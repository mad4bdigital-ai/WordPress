<?php
/**
 * Plugin Name: MAD4B Universal VPS Capability Registry Administration
 * Description: Staging-scoped, revision-fenced management of data-driven VPS contracts.
 * Version: 0.2.0
 */
defined('ABSPATH') || exit;

final class MAD4B_Universal_VPS_Registry_Admin_V2 {
    const CONTRACT = 'mad4b.universal-vps-registry-admin.v2';
    const OPTION = 'mad4b_hub_vps_capability_contracts_v2';
    const PAGE = 'mad4b-vps-capability-registry';

    public static function scoped(): bool {
        return function_exists('wp_get_environment_type')
            && wp_get_environment_type() === 'staging'
            && strtolower((string) wp_parse_url(home_url('/'), PHP_URL_HOST)) === 'tours.dev.mad4b.com'
            && class_exists('MAD4B_Hub_Universal_VPS_V1')
            && class_exists('MAD4B_Hub_Bulk_Jobs_Queue');
    }
    public static function can_read(): bool {
        return self::scoped() && current_user_can('manage_options');
    }
    public static function can_write(): bool {
        return self::can_read() && current_user_can('activate_plugins')
            && MAD4B_Hub_Bulk_Jobs_Queue::writable();
    }
    private static function err(string $code, int $http = 400): WP_Error {
        return new WP_Error('mad4b_vps_registry_' . $code, $code,
            ['status' => $http, 'contract' => self::CONTRACT]);
    }
    private static function snapshot(): array {
        $v = get_option(self::OPTION, []);
        return is_array($v) ? $v : [];
    }
    public static function status(array $input = []) {
        if (!self::can_read()) return self::err('read_denied', 403);
        $v = self::snapshot();
        $live = MAD4B_Hub_Universal_VPS_V1::contracts();
        return [
            'contract' => self::CONTRACT,
            'revision' => (int)($v['revision'] ?? 0),
            'declared_contracts' => count((array)($v['items'] ?? [])),
            'effective_contracts' => count($live),
            'disabled_abilities' => array_values((array)($v['disabled_abilities'] ?? [])),
            'effective' => array_map(static fn($c) => [
                'id' => $c['id'], 'ability' => $c['ability'],
                'stages' => $c['stages'], 'readonly' => $c['readonly'],
                'source' => $c['registration_source'] ?? 'unknown'
            ], array_values($live)),
            'default_executor' => 'mcp_client',
            'metadata_auto_registration_supported' => true,
            'mutation_performed' => false
        ];
    }
    /**
     * Row-level CAS under a short database transaction. No approval ceremony;
     * original WordPress capability & nonce checks remain in effect.
     */
    private static function commit(int $expectedRevision, callable $mutate) {
        global $wpdb;
        if ($wpdb->query('START TRANSACTION') === false) return self::err('transaction_unavailable', 503);
        $row = $wpdb->get_row($wpdb->prepare(
            'SELECT option_value FROM ' . $wpdb->options . ' WHERE option_name=%s FOR UPDATE',
            self::OPTION), ARRAY_A);
        if (!$row) {
            $wpdb->query('ROLLBACK');
            return self::err('registry_option_missing', 503);
        }
        $old = maybe_unserialize($row['option_value']);
        if (!is_array($old)) {
            $wpdb->query('ROLLBACK');
            return self::err('registry_corrupt', 503);
        }
        if ((int)($old['revision'] ?? 0) !== $expectedRevision) {
            $wpdb->query('ROLLBACK');
            return self::err('revision_conflict', 409);
        }
        $new = $mutate($old);
        if (is_wp_error($new)) {
            $wpdb->query('ROLLBACK');
            return $new;
        }
        if (!is_array($new) || $new === $old) {
            $wpdb->query('ROLLBACK');
            return self::err('no_configuration_change', 409);
        }
        $new['revision'] = $expectedRevision + 1;
        $new['updated_utc'] = gmdate('c');
        $new['updated_by'] = get_current_user_id();
        $updated = $wpdb->update($wpdb->options,
            ['option_value' => maybe_serialize($new)],
            ['option_name' => self::OPTION, 'option_value' => $row['option_value']],
            ['%s'], ['%s', '%s']);
        if ($updated !== 1) {
            $wpdb->query('ROLLBACK');
            return self::err('concurrent_update', 409);
        }
        if ($wpdb->query('COMMIT') === false) return self::err('commit_unknown', 500);
        wp_cache_delete(self::OPTION, 'options');
        wp_cache_delete('alloptions', 'options');
        return $new;
    }
    public static function configure(array $input = []) {
        if (!self::can_write()) return self::err('write_denied', 403);
        $permittedKeys = ['action','ability','stages','requires_job','expected_revision'];
        if (array_diff(array_keys($input), $permittedKeys)) return self::err('unexpected_parameters');
        $action = (string)($input['action'] ?? '');
        $abilityName = (string)($input['ability'] ?? '');
        $revision = $input['expected_revision'] ?? null;
        if (!in_array($action, ['register','disable','enable','unregister'], true)
            || !preg_match('~^[a-z][a-z0-9._-]{1,79}/[a-z][a-z0-9._-]{1,99}$~D', $abilityName)
            || !is_int($revision) || $revision < 1) return self::err('invalid_parameters');
        $ability = function_exists('wp_get_ability') ? wp_get_ability($abilityName) : null;
        if ($action === 'register') {
            if (!$ability) return self::err('ability_missing', 404);
            $meta = (array) $ability->get_meta();
            $flags = (array)($meta['annotations'] ?? []);
            if (($flags['readonly'] ?? null) !== true
                || ($flags['idempotent'] ?? null) !== true
                || ($flags['destructive'] ?? null) !== false)
                return self::err('provider_verifier_required_for_writes', 403);
            $schema = $ability->get_input_schema();
            if (($schema['type'] ?? null) !== 'object') return self::err('invalid_input_schema');
            $stages = $input['stages'] ?? null;
            if (!is_array($stages) || !$stages || count($stages) > 20
                || array_values($stages) !== $stages
                || count(array_unique($stages)) !== count($stages)
                || array_diff($stages, MAD4B_Hub_Bulk_Jobs_Queue::stages()))
                return self::err('invalid_stage_assignment');
        }
        $result = self::commit($revision, static function(array $registry) use ($action, $abilityName, $input) {
            $items = array_values(array_filter((array)($registry['items'] ?? []), 'is_array'));
            $disabled = array_fill_keys(array_filter((array)($registry['disabled_abilities'] ?? []), 'is_string'), true);
            if ($action === 'register') {
                $id = MAD4B_Hub_Universal_VPS_V1::generated_id($abilityName);
                foreach ($items as $item) if (($item['ability'] ?? null) === $abilityName) {
                    $id = (string)$item['id']; break;
                }
                $items = array_values(array_filter($items,
                    static fn($item) => ($item['ability'] ?? '') !== $abilityName));
                $items[] = [
                    'id' => $id, 'ability' => $abilityName,
                    'stages' => $input['stages'], 'executor' => 'vps_runner',
                    'transport' => 'wp_ability', 'read_only' => true,
                    'requires_job' => ($input['requires_job'] ?? true) === true,
                    'independent_verifier' => 'repeat_readonly_ability',
                    'enabled' => true, 'priority' => 35,
                    'owner' => explode('/', $abilityName, 2)[0]
                ];
                unset($disabled[$abilityName]);
            } elseif ($action === 'disable') {
                $disabled[$abilityName] = true;
            } elseif ($action === 'enable') {
                unset($disabled[$abilityName]);
            } else {
                $items = array_values(array_filter($items,
                    static fn($item) => ($item['ability'] ?? '') !== $abilityName));
                // Suppress self-declared metadata after unregister until explicitly enabled.
                $disabled[$abilityName] = true;
            }
            $registry['items'] = $items;
            $registry['disabled_abilities'] = array_keys($disabled);
            return $registry;
        });
        if (is_wp_error($result)) return $result;
        // The Registry caches only for this request. Re-entrance to the same process
        // may still expose a cached snapshot; requests and workers re-bootstrap.
        return [
            'contract' => self::CONTRACT, 'state' => 'REGISTRY_UPDATED',
            'revision' => (int)$result['revision'], 'action' => $action,
            'ability' => $abilityName, 'refetch_required' => true,
            'tasks_executed' => 0, 'production_mutated' => false
        ];
    }
    public static function routes(): void {
        if (!self::scoped()) return;
        foreach (['/universal-vps/registry/status' => 'status',
                  '/universal-vps/registry/configure' => 'configure'] as $path => $method) {
            register_rest_route('mad4b-hub/v1', $path, [
                'methods' => $method === 'status' ? 'GET' : 'POST',
                'permission_callback' => [__CLASS__, $method === 'status' ? 'can_read' : 'can_write'],
                'callback' => static function($request) use ($method) {
                    $args = $request->get_json_params();
                    $output = self::$method(is_array($args) ? $args : []);
                    return is_wp_error($output) ? $output : rest_ensure_response($output);
                }
            ]);
        }
    }
    public static function page(): void {
        if (!self::can_read()) wp_die('Forbidden');
        $notice = '';
        if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['mad4b_vps_registry_action'])) {
            check_admin_referer('mad4b_vps_registry_manage');
            if (!self::can_write()) wp_die('Forbidden');
            $stagesText = sanitize_text_field(wp_unslash($_POST['stages'] ?? ''));
            $request = [
                'action' => sanitize_key(wp_unslash($_POST['mad4b_vps_registry_action'])),
                'ability' => sanitize_text_field(wp_unslash($_POST['ability'] ?? '')),
                'expected_revision' => (int)($_POST['expected_revision'] ?? 0)
            ];
            if ($request['action'] === 'register') {
                $request['stages'] = array_values(array_filter(array_map('trim', explode(',', $stagesText))));
                $request['requires_job'] = isset($_POST['requires_job']);
            }
            $res = self::configure($request);
            $notice = is_wp_error($res) ? $res->get_error_code()
                : ('Saved registry revision ' . $res['revision']);
        }
        $status = self::status();
        echo '<div class="wrap"><h1>MAD4B Universal VPS Capability Registry</h1>';
        echo '<p>Live Ability discovery and metadata registration are automatic. Only bounded read-only abilities can be enabled here. Mutations require provider-owned verifiers.</p>';
        if ($notice) echo '<div class="notice notice-info"><p>' . esc_html($notice) . '</p></div>';
        if (is_wp_error($status)) { echo '<p>Registry unavailable.</p></div>'; return; }
        echo '<p><strong>Revision:</strong> ' . esc_html((string)$status['revision'])
           . ' — <strong>Effective contracts:</strong> ' . esc_html((string)$status['effective_contracts']) . '</p>';
        echo '<form method="post">';
        wp_nonce_field('mad4b_vps_registry_manage');
        echo '<input type="hidden" name="expected_revision" value="' . esc_attr((string)$status['revision']) . '">';
        echo '<p><label>Ability <input name="ability" size="42" required placeholder="provider-name/ability-name"></label></p>';
        echo '<p><label>Workflow stages <input name="stages" size="42" placeholder="INTEGRATION_QA,CONTEXT_ASSEMBLY"></label></p>';
        echo '<p><label><input type="checkbox" name="requires_job" checked> Require Content Job</label></p>';
        echo '<p><label>Action <select name="mad4b_vps_registry_action">';
        foreach (['register','disable','enable','unregister'] as $a)
            echo '<option value="' . esc_attr($a) . '">' . esc_html(ucfirst($a)) . '</option>';
        echo '</select></label></p><p><button class="button button-primary">Apply</button></p></form>';
        echo '<h2>Effective contracts</h2><table class="widefat striped"><thead><tr>';
        foreach (['Ability','Contract ID','Stages','Source'] as $th) echo '<th>' . esc_html($th) . '</th>';
        echo '</tr></thead><tbody>';
        foreach ($status['effective'] as $r) {
            echo '<tr><td>' . esc_html($r['ability']) . '</td><td><code>' . esc_html($r['id'])
                . '</code></td><td>' . esc_html(implode(', ', $r['stages']))
                . '</td><td>' . esc_html($r['source']) . '</td></tr>';
        }
        echo '</tbody></table></div>';
    }
    public static function menu(): void {
        if (!self::can_read()) return;
        add_submenu_page('mad4b-shared-content-hub', 'VPS Capability Registry',
            'VPS Capability Registry', 'manage_options', self::PAGE, [__CLASS__, 'page']);
    }
    public static function boot(): void {
        add_action('rest_api_init', [__CLASS__, 'routes'], 70);
        add_action('admin_menu', [__CLASS__, 'menu'], 45);
    }
}
MAD4B_Universal_VPS_Registry_Admin_V2::boot();
