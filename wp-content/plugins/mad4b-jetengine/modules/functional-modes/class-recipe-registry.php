<?php
defined('ABSPATH') || exit;
/**
 * MAD4B JetEngine Recipe Registry v1.
 * A presentation registry. Never writes or maps native JetEngine values.
 */
final class MAD4B_JetEngine_Recipe_Registry_V1 {
    public const OPTION = 'mad4b_jetengine_recipe_registry_v1';
    public const MODES = ['inherit', 'cards', 'compact', 'accordion'];
    public const INTERFACES = ['options', 'cpt', 'form', 'jetform'];
    public const MAX_RULES = 400;

    public static function boot(): void {
        add_action('admin_menu', [__CLASS__, 'menu'], 35);
        add_action('admin_enqueue_scripts', [__CLASS__, 'admin_assets']);
        add_action('admin_post_mad4b_jetengine_recipe_save', [__CLASS__, 'save']);
        add_action('admin_post_mad4b_jetengine_recipe_delete', [__CLASS__, 'delete']);
    }
    public static function registry(): array {
        $saved = get_option(self::OPTION, []);
        if (!is_array($saved)) $saved = [];
        return [
            'global' => self::mode($saved['global'] ?? 'cards', 'cards'),
            'interfaces' => is_array($saved['interfaces'] ?? null) ? $saved['interfaces'] : [],
            'targets' => is_array($saved['targets'] ?? null) ? $saved['targets'] : [],
            'groups' => is_array($saved['groups'] ?? null) ? $saved['groups'] : [],
            'fields' => is_array($saved['fields'] ?? null) ? $saved['fields'] : [],
        ];
    }
    private static function mode($mode, string $fallback = 'inherit'): string {
        return is_string($mode) && in_array($mode, self::MODES, true) ? $mode : $fallback;
    }
    private static function identifier($value, bool $allowWildcard = false): string {
        if (!is_string($value)) return '';
        $value = trim($value);
        if ($allowWildcard && $value === '*') return '*';
        if (strlen($value) > 110 || !preg_match('/^[a-zA-Z0-9_-]+$/D', $value)) return '';
        return $value;
    }
    private static function lookup(array $entries, string $key): ?string {
        if (!isset($entries[$key])) return null;
        $mode = self::mode($entries[$key]);
        return $mode === 'inherit' ? null : $mode;
    }
    /**
     * Hierarchy: Global -> Interface -> CPT/Options Page/Form -> native field
     * group -> individual native field. A rule always changes style only.
     */
    public static function resolve(string $interface, string $target = '',
        string $group = '', string $field = '', ?array $data = null): array {
        $data ??= self::registry();
        $source = 'global';
        $selected = self::mode($data['global'] ?? 'cards', 'cards');
        if (!in_array($interface, self::INTERFACES, true)) {
            return ['mode' => $selected, 'source' => $source];
        }
        $target = self::identifier($target);
        $group = self::identifier($group);
        $field = self::identifier($field);
        $keys = [
            'interface' => ['interfaces', $interface],
            'target' => ['targets', $interface . ':' . $target],
            'group' => ['groups', $interface . ':' . $target . ':' . $group],
            'field' => ['fields', $interface . ':' . $target . ':' . $field],
        ];
        foreach ($keys as $layer => [$bucket, $key]) {
            if ($layer === 'target' && $target === '') continue;
            if ($layer === 'group' && ($target === '' || $group === '')) continue;
            if ($layer === 'field' && ($target === '' || $field === '')) continue;
            $over = self::lookup((array)($data[$bucket] ?? []), $key);
            if ($over !== null) {
                $selected = $over;
                $source = $layer;
            }
        }
        return ['mode' => $selected, 'source' => $source];
    }
    public static function context_payload(string $interface, string $target = ''): array {
        return [
            'interface' => in_array($interface, self::INTERFACES, true) ? $interface : '',
            'target' => self::identifier($target),
            'registry' => self::registry(),
        ];
    }
    public static function inline_config(string $handle, string $interface, string $target = ''): void {
        wp_add_inline_script($handle,
            'window.MAD4BJetRecipeConfig=' . wp_json_encode(
                self::context_payload($interface, $target),
                JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
            ) . ';', 'before');
    }
    public static function enqueue(string $interface, string $target = '',
        string $scriptHandle = 'mad4b-jetengine-recipe-registry'): void {
        $dir = __DIR__ . '/assets/';
        $url = plugins_url('mad4b-jetengine/modules/functional-modes/assets/');
        wp_enqueue_style('mad4b-jetengine-field-recipe',
            $url . 'jetengine-field-recipe.css', [],
            (string)filemtime($dir . 'jetengine-field-recipe.css'));
        wp_enqueue_style('mad4b-jetengine-recipe-registry',
            $url . 'recipe-registry.css', ['mad4b-jetengine-field-recipe'],
            (string)filemtime($dir . 'recipe-registry.css'));
        wp_enqueue_script($scriptHandle, $url . 'recipe-registry.js', [],
            (string)filemtime($dir . 'recipe-registry.js'), true);
        self::inline_config($scriptHandle, $interface, $target);
    }
    public static function admin_assets(string $hook): void {
        if ($hook !== 'jetengine_page_mad4b-jetengine-recipe-registry'
            && ($_GET['page'] ?? '') !== 'mad4b-jetengine-recipe-registry') return;
        $url = plugins_url('mad4b-jetengine/modules/functional-modes/assets/');
        $dir = __DIR__ . '/assets/';
        wp_enqueue_style('mad4b-jetengine-recipe-workspace',
            $url . 'recipe-workspace.css', [],
            (string)filemtime($dir . 'recipe-workspace.css'));
        wp_enqueue_script('mad4b-jetengine-recipe-workspace',
            $url . 'recipe-workspace.js', [],
            (string)filemtime($dir . 'recipe-workspace.js'), true);
    }
    public static function menu(): void {
        add_submenu_page('mad4b-jetengine',
            'Recipe Workspace', 'Recipe Workspace', 'manage_options',
            'mad4b-jetengine-recipe-registry', [__CLASS__, 'page']);
    }
    private static function authorize(): void {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Insufficient permissions.', 'mad4b-jetengine'), '', ['response' => 403]);
        }
    }
    private static function redirect(string $status): void {
        wp_safe_redirect(add_query_arg(['page' => 'mad4b-jetengine-recipe-registry',
            'recipe_status' => $status], admin_url('admin.php')));
        exit;
    }
    public static function save(): void {
        self::authorize();
        check_admin_referer('mad4b_jetengine_recipe_save');
        foreach (['layer', 'style', 'interface', 'target', 'group', 'field'] as $name) {
            if (isset($_POST[$name]) && !is_string($_POST[$name])) self::redirect('invalid');
        }
        $layer = sanitize_key(wp_unslash($_POST['layer'] ?? ''));
        $style = self::mode(sanitize_key(wp_unslash($_POST['style'] ?? '')));
        $interface = sanitize_key(wp_unslash($_POST['interface'] ?? ''));
        $target = self::identifier(wp_unslash($_POST['target'] ?? ''));
        $group = self::identifier(wp_unslash($_POST['group'] ?? ''));
        $field = self::identifier(wp_unslash($_POST['field'] ?? ''));
        $data = self::registry();
        if (!in_array($layer, ['global', 'interface', 'target', 'group', 'field'], true)
            || !in_array($style, self::MODES, true)
            || ($layer !== 'global' && !in_array($interface, self::INTERFACES, true))) {
            self::redirect('invalid');
        }
        if ($layer !== 'global') {
            $key = match ($layer) {
                'interface' => $interface,
                'target' => $interface . ':' . $target,
                'group' => $interface . ':' . $target . ':' . $group,
                'field' => $interface . ':' . $target . ':' . $field,
            };
            if (($layer === 'target' && !$target)
                || ($layer === 'group' && (!$target || !$group))
                || ($layer === 'field' && (!$target || !$field))) self::redirect('invalid');
            $bucket = $layer === 'interface' ? 'interfaces' : $layer . 's';
            if ($style === 'inherit') unset($data[$bucket][$key]);
            else $data[$bucket][$key] = $style;
        } else {
            $data['global'] = $style === 'inherit' ? 'cards' : $style;
        }
        $total = count($data['interfaces']) + count($data['targets'])
            + count($data['groups']) + count($data['fields']);
        if ($total > self::MAX_RULES) self::redirect('limit');
        update_option(self::OPTION, $data, false);
        self::redirect('saved');
    }
    public static function delete(): void {
        self::authorize();
        check_admin_referer('mad4b_jetengine_recipe_delete');
        if ((isset($_POST['bucket']) && !is_string($_POST['bucket']))
            || (isset($_POST['rule_key']) && !is_string($_POST['rule_key']))) self::redirect('invalid');
        $bucket = sanitize_key(wp_unslash($_POST['bucket'] ?? ''));
        $key = wp_unslash($_POST['rule_key'] ?? '');
        if (!in_array($bucket, ['interfaces', 'targets', 'groups', 'fields'], true)
            || !is_string($key) || strlen($key) > 350
            || !preg_match('/^[a-zA-Z0-9_:-]+$/D', $key)) self::redirect('invalid');
        $data = self::registry();
        unset($data[$bucket][$key]);
        update_option(self::OPTION, $data, false);
        self::redirect('deleted');
    }
    public static function page(): void {
        self::authorize();
        MAD4B_JetEngine_Recipe_Workspace_V2::render();
        return;
        $data = self::registry();
        $status = sanitize_key($_GET['recipe_status'] ?? '');
        echo '<div class="wrap"><h1>MAD4B · JetEngine Recipe Registry</h1>';
        echo '<p>One presentation authority. Inherit Global → Interface → CPT / Options Page / Form → Field Group → Field. ';
        echo 'Original JetEngine fields, native save and frontend form handlers are never replaced.</p>';
        if (in_array($status, ['saved', 'deleted', 'invalid', 'limit'], true)) {
            echo '<div class="notice notice-' . ($status === 'saved' || $status === 'deleted' ? 'success' : 'error') . '"><p>'
                . esc_html(ucfirst($status)) . '</p></div>';
        }
        echo '<p><strong>Current default:</strong> ' . esc_html($data['global'])
            . ' · <strong>Overrides:</strong> '
            . esc_html((string)(count($data['interfaces']) + count($data['targets'])
                + count($data['groups']) + count($data['fields']))) . '</p>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="background:#fff;padding:18px;max-width:1100px;border:1px solid #d6e3d9;border-radius:10px">';
        echo '<input type="hidden" name="action" value="mad4b_jetengine_recipe_save">';
        wp_nonce_field('mad4b_jetengine_recipe_save');
        echo '<h2>Assign Recipe</h2><p><label>Inheritance level <select name="layer">';
        foreach (['global' => 'Global', 'interface' => 'Interface', 'target' => 'CPT / Options Page / Form',
            'group' => 'Field Group', 'field' => 'Individual Field'] as $key => $label) {
            echo '<option value="' . esc_attr($key) . '">' . esc_html($label) . '</option>';
        }
        echo '</select></label> <label>Interface <select name="interface">';
        foreach (self::INTERFACES as $key) echo '<option value="' . esc_attr($key) . '">' . esc_html(ucfirst($key)) . '</option>';
        echo '</select></label> <label>Recipe <select name="style">';
        foreach (self::MODES as $mode) echo '<option value="' . esc_attr($mode) . '" '
            . selected($mode, 'cards', false) . '>' . esc_html(ucfirst($mode)) . '</option>';
        echo '</select></label></p>';
        echo '<p><label>Target (CPT slug, Options Page slug, or JetEngine Form ID) ';
        echo '<input type="text" name="target" maxlength="110" placeholder="properties"></label> ';
        echo '<label>Group ID <input type="text" name="group" maxlength="110" placeholder="rates_group"></label> ';
        echo '<label>Native Field Name <input type="text" name="field" maxlength="110" placeholder="occupancy"></label></p>';
        echo '<p class="description">Only fill the identifiers required by the selected inheritance level. ';
        echo 'Use the exact native JetEngine slug, group ID or field name. Inherit removes an override.</p>';
        submit_button('Save Recipe', 'primary', 'submit', false);
        echo '</form><h2>Active Overrides</h2>';
        echo '<table class="widefat striped" style="max-width:1100px"><thead><tr><th>Level</th><th>Target</th><th>Recipe</th><th>Action</th></tr></thead><tbody>';
        foreach (['interfaces', 'targets', 'groups', 'fields'] as $bucket) {
            foreach ($data[$bucket] as $key => $mode) {
                echo '<tr><td>' . esc_html($bucket) . '</td><td><code>' . esc_html($key)
                    . '</code></td><td>' . esc_html($mode) . '</td><td><form method="post" action="'
                    . esc_url(admin_url('admin-post.php')) . '">';
                echo '<input type="hidden" name="action" value="mad4b_jetengine_recipe_delete">';
                echo '<input type="hidden" name="bucket" value="' . esc_attr($bucket) . '">';
                echo '<input type="hidden" name="rule_key" value="' . esc_attr($key) . '">';
                wp_nonce_field('mad4b_jetengine_recipe_delete');
                submit_button('Remove override', 'secondary small', 'submit', false);
                echo '</form></td></tr>';
            }
        }
        echo '</tbody></table><p><a href="' . esc_url(admin_url('admin.php?page=mad4b-jetengine-field-modes')) . '">Functional Modes</a></p></div>';
    }
}
MAD4B_JetEngine_Recipe_Registry_V1::boot();
