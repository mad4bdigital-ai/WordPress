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
    const ROUTES = 'mad4b_company_hostinger_two_route_bindings_v1';
    const PROPOSALS = 'mad4b_company_hostinger_connection_proposals_v1';
    const HOST_ACCOUNTS = array(
        'hosting-1'=>'cloud-startup',
        'hosting-2'=>'allroyalegypt',
        'hosting-3'=>'egypttourgates',
    );
    public static function boot() {
        add_action('admin_menu', array(__CLASS__, 'menu'), 99);
        add_action('admin_post_mad4b_hostinger_propose_connection', array(__CLASS__, 'save_connection_proposal'));
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

    private static function enrolled_company_sites() {
        if (!class_exists('MAD4B_Company_Environment_Registry_V2', false)) return array();
        $registry=MAD4B_Company_Environment_Registry_V2::discover();
        $out=array();
        foreach ((array)$registry as $company=>$row) {
            foreach ((array)($row['environments']??array()) as $env=>$site) {
                if (!is_array($site) || !in_array($env,array('production','staging'),true)) continue;
                $origin=(string)($site['canonical_origin']??'');
                $parts=wp_parse_url($origin);
                if (!is_array($parts) || ($parts['scheme']??'')!=='https' ||
                    empty($parts['host']) || isset($parts['user']) || isset($parts['pass']) ||
                    isset($parts['port']) || isset($parts['query']) || isset($parts['fragment']) ||
                    !in_array($parts['path']??'',array('','/'),true)) continue;
                $out[$company.':'.$env]=array('company_id'=>$company,
                    'environment'=>$env,'origin'=>$origin,
                    'site_uuid'=>$site['site_uuid']??null);
            }
        }
        return $out;
    }
    public static function save_connection_proposal() {
        if (!class_exists('MAD4B_Company_Portfolio_Manager_V1',false) ||
            !MAD4B_Company_Portfolio_Manager_V1::staging() ||
            !current_user_can('manage_options') || !current_user_can('activate_plugins')) {
            wp_die('Company Manager administrator required', '', array('response'=>403));
        }
        check_admin_referer('mad4b_hostinger_binding_proposal_v1');
        $scope=sanitize_text_field(wp_unslash((string)($_POST['company_scope']??'')));
        $target=sanitize_key(wp_unslash((string)($_POST['ssh_target']??'')));
        $candidate=strtolower(trim(sanitize_text_field(wp_unslash((string)($_POST['api_website_domain']??'')))));
        $sites=self::enrolled_company_sites();
        if (!isset($sites[$scope]) || !array_key_exists($target,self::HOST_ACCOUNTS) ||
            !is_string($candidate) || strlen($candidate)>253 ||
            !filter_var($candidate,FILTER_VALIDATE_DOMAIN,FILTER_FLAG_HOSTNAME) ||
            preg_match('/[\/:@?#]/', $candidate)) {
            wp_die('Invalid company or provider binding candidate', '', array('response'=>422));
        }
        $site=$sites[$scope];
        $rows=get_option(self::PROPOSALS,array());
        if (!is_array($rows))$rows=array();
        $rows[$scope]=array(
            'company_id'=>$site['company_id'],
            'environment'=>$site['environment'],
            'origin'=>$site['origin'],
            'company_site_uuid'=>$site['site_uuid'],
            'ssh_target'=>$target,
            'api_account_alias'=>self::HOST_ACCOUNTS[$target],
            'api_website_domain_candidate'=>$candidate,
            'status'=>'PROPOSED_PENDING_EXTERNAL_SSH_AND_API_WEBSITE_VERIFICATION',
            'proposed_utc'=>gmdate('c')
        );
        update_option(self::PROPOSALS,$rows,false);
        wp_safe_redirect(add_query_arg(array(
            'page'=>'mad4b-company-hostinger-cdn-operations',
            'binding_proposed'=>1
        ),admin_url('admin.php')));
        exit;
    }
    public static function connections() {
        $routing=get_option(self::ROUTES,array());
        $proposals=get_option(self::PROPOSALS,array());
        if (!is_array($routing))$routing=array();
        if (!is_array($proposals))$proposals=array();
        return array(
            'contract'=>'mad4b.company-hostinger-connections.v1',
            'routing_state'=>$routing['contract']??'NOT_PROJECTED',
            'observed_utc'=>$routing['utc']??null,
            'routes'=>$routing['sites']??array(),
            'proposals'=>$proposals,
            'proposals_are_authorizing'=>false,
        );
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
            'connection_routing' => self::connections(),
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
        $connections=self::connections();
        echo '<h2>Internal Company Identity &amp; External Host Routing</h2>';
        echo '<p>Internal: Company Environment Registry. External: pinned Registry Infra SSH and Hostinger API website binding. A proposed account reference is not a credential or authorization grant.</p>';
        echo '<table class="widefat striped"><thead><tr><th>Company / Environment</th><th>Canonical origin</th><th>SSH</th><th>Hostinger API</th><th>Composite routing</th></tr></thead><tbody>';
        foreach ((array)$connections['routes'] as $scope=>$record) {
            if (!is_array($record))continue;
            $route=$record['routes']['company.cache.hostinger.refresh']??array();
            echo '<tr><td>'.esc_html((string)$scope).'</td><td>'.
                esc_html((string)($record['canonical_origin']??'')).'</td><td>'.
                esc_html((string)($record['ssh_target']??'')).': '.
                esc_html(!empty($record['ssh_verified'])?'VERIFIED':'NOT_VERIFIED').'</td><td>'.
                esc_html((string)($record['api_account_alias']??'')).': '.
                esc_html((string)($record['api_state']??'UNKNOWN')).'</td><td>'.
                esc_html((string)($route['state']??'UNKNOWN')).'</td></tr>';
        }
        echo '</tbody></table>';
        if(class_exists('MAD4B_Company_Portfolio_Manager_V1',false) &&
           MAD4B_Company_Portfolio_Manager_V1::staging() &&
           current_user_can('activate_plugins')) {
            echo '<h3>Propose a company-site Hostinger/SSH binding</h3>';
            echo '<p>Saved proposals are verified by the external Registry Infra worker. They never contain an API token or SSH private key, and do not enable writes.</p>';
            if (isset($_GET['binding_proposed']))echo '<div class="notice notice-success"><p>Binding proposal recorded; awaiting independent external verification.</p></div>';
            echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';
            echo '<input type="hidden" name="action" value="mad4b_hostinger_propose_connection">';
            wp_nonce_field('mad4b_hostinger_binding_proposal_v1');
            echo '<p><label>Company site <select name="company_scope" required>';
            foreach (self::enrolled_company_sites() as $scope=>$site) {
                echo '<option value="'.esc_attr((string)$scope).'">'.esc_html((string)$scope).' — '.esc_html((string)$site['origin']).'</option>';
            }
            echo '</select></label></p>';
            echo '<p><label>Registered SSH/Hostinger account <select name="ssh_target" required>';
            foreach (self::HOST_ACCOUNTS as $target=>$alias) {
                echo '<option value="'.esc_attr($target).'">'.esc_html($target.' / '.$alias).'</option>';
            }
            echo '</select></label></p>';
            echo '<p><label>Hostinger website domain (metadata only) <input name="api_website_domain" type="text" maxlength="253" required placeholder="example.com"></label></p>';
            submit_button('Save binding proposal — external verification required');
            echo '</form>';
        }
        echo '<h2>Operational Knowledge</h2><p><strong>' .
             esc_html($status['knowledge_id']) . '</strong>: ' .
             esc_html($status['operation_summary']) . '</p><p>' .
             esc_html($status['next_step']) . '</p>';
        echo '<p><strong>Safety:</strong> Hostinger CDN purge requires independent provider authorization; the panel is read-only. No shared Redis or all-site cache purge is issued from this screen.</p></div>';
    }
}
MAD4B_Company_Hostinger_CDN_Operation_V1::boot();
}
