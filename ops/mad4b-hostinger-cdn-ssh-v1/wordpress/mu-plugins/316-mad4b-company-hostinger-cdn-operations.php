<?php
/**
 * Plugin Name: MAD4B Company — Hostinger CDN Operation
 * Description: Read-only registered company CDN operation and knowledge visibility.
 * Version: 1.0.0
 */
defined('ABSPATH') || exit;
if (!class_exists('MAD4B_Company_Hostinger_CDN_Operation_V1', false)) {
final class MAD4B_Company_Hostinger_CDN_Operation_V1 {
    const RECEIPT = 'mad4b_company_hostinger_cdn_operation_v1';
    const KNOWLEDGE = 'mad4b_company_hostinger_cdn_knowledge_v1';
    public static function boot() {
        add_action('admin_menu', array(__CLASS__, 'menu'), 99);
        add_action('wp_abilities_api_categories_init', function () {
            if (function_exists('wp_register_ability_category')) {
                wp_register_ability_category('mad4b-hostinger-cdn',
                    array('label'=>'MAD4B Hostinger CDN','description'=>'Registered company cache and provider evidence'));
            }
        });
        add_action('wp_abilities_api_init', array(__CLASS__, 'abilities'), 30);
        add_filter('mad4b_scp_dynamic_provider_manifests', array(__CLASS__, 'provider_manifest'), 30);
        add_action('rest_api_init', function () {
            register_rest_route('mad4b/v1', '/company-hostinger-cdn-operation', array(
                'methods' => 'GET',
                'permission_callback' => function () { return current_user_can('manage_options'); },
                'callback' => function () {
                    $r = new WP_REST_Response(self::status());
                    $r->header('Cache-Control', 'private, no-store');
                    return $r;
                },
            ));
        });
        if (defined('WP_CLI') && WP_CLI && class_exists('WP_CLI')) {
            WP_CLI::add_command('mad4b company-cache-hostinger status', function () {
                WP_CLI::line(wp_json_encode(self::status(), JSON_UNESCAPED_SLASHES));
            });
        }
    }
    public static function abilities() {
        if (!function_exists('wp_register_ability')) return;
        foreach (array(
            'mad4b/hostinger-cdn-operation-status' => array(__CLASS__, 'status'),
            'mad4b/hostinger-cdn-knowledge' => array(__CLASS__, 'knowledge'),
        ) as $name=>$callback) {
            if (function_exists('wp_has_ability') && wp_has_ability($name)) continue;
            wp_register_ability($name, array(
                'label'=>str_replace('-', ' ', substr($name, 6)),
                'description'=>'Read-only company operation or knowledge entry. Does not issue a provider cache purge.',
                'category'=>'mad4b-hostinger-cdn',
                'permission_callback'=>static function(){return current_user_can('manage_options');},
                'execute_callback'=>static function($input=array())use($callback){return call_user_func($callback);},
                'input_schema'=>array('type'=>'object','properties'=>new stdClass(),'additionalProperties'=>false),
                'output_schema'=>array('type'=>'object','additionalProperties'=>true),
                'meta'=>array('show_in_rest'=>false,
                    'mcp'=>array('public'=>false,'surface'=>'read','type'=>'tool'),
                    'annotations'=>array('readonly'=>true,'destructive'=>false,'idempotent'=>true)),
            ));
        }
    }
    public static function knowledge() {
        $value=get_option(self::KNOWLEDGE,array());
        return is_array($value) ? $value : array();
    }
    public static function provider_manifest($items) {
        if (!is_array($items)) $items=array();
        $items[]=array(
            'provider_id'=>'hostinger_cdn_operation',
            'existing_registry_identity'=>'hostinger_cdn_operation',
            'implementation_version'=>'1.0.0',
            'supports'=>array('hostinger_cdn_readiness','ssh_origin_cache_status',
                'cdn_edge_context_verification','cache_provider_knowledge'),
            'side_effects'=>array(),
            'soft_elapsed_budget_ms'=>30000,
            'enforceable_deadline_supported'=>false,
        );
        return $items;
    }
    public static function status() {
        $data = get_option(self::RECEIPT, array());
        $knowledge = get_option(self::KNOWLEDGE, array());
        if (!is_array($data)) $data = array();
        if (!is_array($knowledge)) $knowledge = array();
        $time = strtotime((string)($data['utc'] ?? ''));
        $stale = !$time || (time() - $time) > 3600;
        return array(
            'contract' => 'mad4b.company-hostinger-cdn-ui.v1',
            'operation_id' => 'company.cache.hostinger.refresh',
            'state' => $stale ? 'STALE_EVIDENCE' : ($data['state'] ?? 'NOT_ENROLLED'),
            'stale' => $stale,
            'run_id' => (string)($data['run_id'] ?? ''),
            'observed_utc' => (string)($data['utc'] ?? ''),
            'companies' => $data['companies'] ?? array(),
            'provider_edge_purge_called' => $data['provider_edge_purge_called'] ?? false,
            'native_313_receipt' => false,
            'knowledge_id' => (string)($knowledge['knowledge_id'] ?? ''),
            'operation_summary' => (string)($knowledge['problem'] ?? ''),
            'next_step' => (string)($knowledge['remediation']['provider_action'] ?? ''),
        );
    }
    public static function menu() {
        $parent = class_exists('MAD4B_Company_Portfolio_Manager_V1') ? 'mad4b-company-brand-readiness' : 'tools.php';
        add_submenu_page($parent, 'Hostinger CDN Operations', 'Hostinger CDN', 'manage_options',
            'mad4b-company-hostinger-cdn-operations', array(__CLASS__, 'page'));
    }
    public static function page() {
        if (!current_user_can('manage_options')) return;
        $status = self::status();
        echo '<div class="wrap"><h1>Hostinger CDN — Company Operations</h1>';
        echo '<p>MAD4B Central operation: <code>company.cache.hostinger.refresh</code>. Every site is read independently over registered SSH and the Hostinger edge. Origin purge acknowledgement is not a CDN purge receipt.</p>';
        echo '<p><strong>Fleet state:</strong> ' . esc_html($status['state']) . ' — <strong>Last run:</strong> ' . esc_html($status['observed_utc']) . '</p>';
        echo '<table class="widefat striped"><thead><tr><th>Company site</th><th>SSH</th><th>Edge state</th><th>Contexts requiring intervention</th></tr></thead><tbody>';
        foreach ($status['companies'] as $domain => $item) {
            if (!is_array($item)) continue;
            echo '<tr><td>' . esc_html((string)$domain) . '</td><td>' .
                esc_html((string)($item['ssh_readback'] ?? 'UNKNOWN')) . '</td><td>' .
                esc_html((string)($item['edge_state'] ?? 'UNKNOWN')) . '</td><td>' .
                esc_html(implode(', ', array_map('sanitize_key', (array)($item['critical_contexts'] ?? array())))) .
                '</td></tr>';
        }
        echo '</tbody></table>';
        echo '<h2>Operational Knowledge</h2><p><strong>' .
             esc_html($status['knowledge_id']) . '</strong>: ' .
             esc_html($status['operation_summary']) . '</p><p>' .
             esc_html($status['next_step']) . '</p>';
        echo '<p><strong>Safety:</strong> Hostinger CDN purge requires independent provider authorization; the panel is read-only. No shared Redis or all-site cache purge is issued from this screen.</p></div>';
    }
}
MAD4B_Company_Hostinger_CDN_Operation_V1::boot();
}
