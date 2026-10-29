<?php
/**
 * Company Performance Intelligence — Staging-only, read-only MAD4B Ability.
 * Exact site/source observations and deterministic remediation plans only.
 * No options, products, site content, server config, or presets are modified.
 */
defined('ABSPATH') || exit;
final class MAD4B_Company_Performance_Intelligence {
    const ABILITY = 'mad4b/company-performance-scan-plan';
    const CONTRACT = 'mad4b.company-performance-intelligence.v1';
    const MAX_DOCS = 16;
    const MAX_ACTIVE_PLUGINS = 160;

    public static function boot() {
        add_action('wp_abilities_api_init', [__CLASS__, 'register_ability'], 45);
        add_action('admin_menu', [__CLASS__, 'register_admin_page'], 50);
    }

    public static function register_admin_page() {
        if (wp_get_environment_type() !== 'staging') return;
        add_management_page('Company Performance Intelligence',
            'Company Performance Scan', 'manage_options',
            'mad4b-company-performance-scan', [__CLASS__, 'render_admin_page']);
    }

    public static function render_admin_page() {
        if (wp_get_environment_type() !== 'staging' || !current_user_can('manage_options')) {
            wp_die(esc_html__('You are not allowed to view this report.'));
        }
        $report = self::scan_plan([]);
        echo '<div class="wrap"><h1>Company Performance Intelligence</h1>';
        echo '<p>Read-only Staging scan. No template, cache setting, database, or Production change is performed.</p>';
        if (is_wp_error($report)) {
            echo '<p class="notice notice-error">'.esc_html($report->get_error_message()).'</p></div>';
            return;
        }
        echo '<p><strong>Company:</strong> '.esc_html($report['inventory']['host']).'</p>';
        echo '<p><strong>Critical CSS:</strong> '.esc_html($report['summary']['critical_css_state']);
        echo ' &nbsp; <strong>Fragments:</strong> '.esc_html($report['summary']['fragment_state']);
        echo ' &nbsp; <strong>Critical issues:</strong> '.(int)$report['summary']['p0_count'].'</p>';
        echo '<table class="widefat striped"><thead><tr><th>Priority</th><th>Finding</th><th>State</th><th>Remediation plan</th></tr></thead><tbody>';
        foreach ($report['remediation_plan'] as $step) {
            echo '<tr><td>'.esc_html($step['priority']).'</td><td>'.
                esc_html($step['id']).'</td><td>'.esc_html($step['state']).
                '</td><td>'.esc_html($step['recommendation']).'</td></tr>';
        }
        echo '</tbody></table>';
        echo '<p><strong>Snapshot fingerprint:</strong> <code>'.esc_html($report['scan_snapshot_sha256']).'</code></p>';
        echo '<p><em>Browser evidence and change history are collected separately by the private VPS runner; this page does not claim real-time Lighthouse or apply remediation.</em></p></div>';
    }

    public static function can_read($input = null) {
        return wp_get_environment_type() === 'staging'
            && class_exists('MAD4B_SCP_Policy')
            && is_callable(['MAD4B_SCP_Policy', 'can_read'])
            && (bool) MAD4B_SCP_Policy::can_read();
    }

    public static function register_ability() {
        if (!function_exists('wp_register_ability') ||
            (function_exists('wp_has_ability') && wp_has_ability(self::ABILITY))) return;
        wp_register_ability(self::ABILITY, [
            'label' => 'Scan Company Performance and Plan Remediation',
            'description' => 'Read-only, bounded company-scoped inventory, Elementor template and optimization drift detection, and ordered remediation plan. Does not apply any optimization.',
            'category' => 'mad4b-read',
            'execute_callback' => [__CLASS__, 'scan_plan'],
            'permission_callback' => [__CLASS__, 'can_read'],
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'previous_snapshot_sha256' => [
                        'type' => 'string', 'pattern' => '^[a-f0-9]{64}$'
                    ],
                ],
                'additionalProperties' => false
            ],
            'output_schema' => ['type'=>'object','additionalProperties'=>true],
            'meta' => [
                'public'=>false,'show_in_rest'=>false,
                'mcp'=>['public'=>false,'type'=>'tool','surface'=>'read'],
                'annotations'=>['readonly'=>true,'destructive'=>false,'idempotent'=>true],
            ]
        ]);
    }

    private static function load_config($file) {
        // Fixed in-repository configuration files only; no user-defined paths.
        $path = __DIR__.'/'.$file;
        if (!is_readable($path) || is_link($path) || filesize($path)>32768) return null;
        $obj=json_decode((string)file_get_contents($path),true);
        return is_array($obj) ? $obj : null;
    }

    private static function add_issue(&$issues,$id,$priority,$state,$evidence,$action,$auto=false) {
        if (count($issues)>=32) return;
        $issues[]=['id'=>$id,'priority'=>$priority,'state'=>$state,
            'evidence'=>$evidence,'recommendation'=>$action,
            'auto_apply_allowed'=>$auto,'change_performed'=>false];
    }

    private static function pinned_docs($conf,$name,&$issues) {
        $out=[];
        if (!is_array($conf) || !is_array($conf['document_guard'] ?? null)) return $out;
        $guard=$conf['document_guard'];
        if (count($guard)>self::MAX_DOCS) {
            self::add_issue($issues,$name.'_guard_overflow','P0','blocked',
                ['count'=>count($guard)],'Reject this preset until its document bounds are reviewed.');
            return $out;
        }
        foreach ($guard as $k=>$sha) {
            if (!ctype_digit((string)$k) || (int)$k<=0 ||
                !is_string($sha) || !preg_match('/^[a-f0-9]{64}$/D',$sha)) continue;
            $id=(int)$k;
            $p=get_post($id);
            if (!$p || $p->post_status!=='publish') {
                $out[(string)$id]=['state'=>'missing_or_unpublished',
                    'expected_sha256'=>$sha,'observed_sha256'=>null];
                self::add_issue($issues,$name.'_document_'.$id,'P0','needs_review',
                    ['document_id'=>$id,'reason'=>'document_missing_or_unpublished'],
                    'Keep the affected asset optimization disabled. Restore or certify the published Elementor document and rerun browser QA.');
                continue;
            }
            $raw=(string)get_post_meta($id,'_elementor_data',true);
            $observed=hash('sha256',$raw);
            $match=hash_equals($sha,$observed);
            $out[(string)$id]=['state'=>$match?'match':'changed',
                'expected_sha256'=>$sha,'observed_sha256'=>$observed,
                'post_modified_gmt'=>(string)$p->post_modified_gmt];
            if (!$match) self::add_issue($issues,$name.'_document_'.$id,'P0','drift',
                ['document_id'=>$id,'reason'=>'elementor_source_hash_changed'],
                'Preserve fail-closed original CSS/JS. Audit the changed widget and above-fold position on Staging for mobile, desktop and WPML before issuing new pinned hashes.');
        }
        return $out;
    }

    public static function scan_plan($input=[]) {
        if (wp_get_environment_type() !== 'staging') {
            return new WP_Error('company_performance_environment_denied',
                'Performance Intelligence is restricted to Staging.');
        }
        if (!is_array($input) || array_diff(array_keys($input), ['previous_snapshot_sha256']) ||
            (isset($input['previous_snapshot_sha256']) &&
             (!is_string($input['previous_snapshot_sha256']) ||
              !preg_match('/^[a-f0-9]{64}$/D',$input['previous_snapshot_sha256'])))) {
            return new WP_Error('company_performance_invalid_input','Only an optional exact prior digest is accepted.');
        }
        $issues=[];
        $host=(string)wp_parse_url(home_url('/'),PHP_URL_HOST);
        if (!preg_match('/^[a-z0-9.-]{4,150}$/D',$host)) {
            return new WP_Error('company_performance_host_invalid','Site host is not valid.');
        }
        $critical=self::load_config('company-critical-css-preset.json');
        $fragments=self::load_config('company-performance-fragment-preset.json');

        $active=get_option('active_plugins',[]);
        if (!is_array($active) || count($active)>self::MAX_ACTIVE_PLUGINS) {
            $active=[];
            self::add_issue($issues,'plugin_inventory_bound','P1','unknown',
                ['reason'=>'inventory_unavailable_or_overflow'],'Review active plugin inventory before certifying performance changes.');
        }
        $tracked=[
            'elementor'=>'elementor/elementor.php',
            'elementor_pro'=>'elementor-pro/elementor-pro.php',
            'jetengine'=>'jet-engine/jet-engine.php',
            'jetsmartfilters'=>'jet-smart-filters/jet-smart-filters.php',
            'woocommerce'=>'woocommerce/woocommerce.php',
            'wpml'=>'sitepress-multilingual-cms/sitepress.php',
            'litespeed'=>'litespeed-cache/litespeed-cache.php',
            'uael'=>'ultimate-elementor/ultimate-elementor.php',
            'control_plane'=>'mad4b-site-control-plane/mad4b-site-control-plane.php',
        ];
        $plugins=[];
        foreach($tracked as $key=>$slug) {
            $plugins[$key]=['active'=>in_array($slug,$active,true)];
        }
        $versions=[
            'elementor'=>defined('ELEMENTOR_VERSION')?(string)ELEMENTOR_VERSION:'',
            'jetengine'=>defined('JET_ENGINE_VERSION')?(string)JET_ENGINE_VERSION:'',
            'woo'=>defined('WC_VERSION')?(string)WC_VERSION:'',
            'uael'=>defined('UAEL_VER')?(string)UAEL_VER:'',
            'litespeed'=>defined('LSCWP_V')?(string)LSCWP_V:'',
        ];

        $critical_docs=self::pinned_docs($critical,'critical_css',$issues);
        $critical_state='unconfigured';
        if ($critical!==null) {
            $critical_state=($critical['enabled']??false)?'enabled':'disabled';
            if (($critical['contract']??'')!=='mad4b.company-critical-css-preset.v1' ||
                strtolower((string)($critical['site_host']??''))!==strtolower($host) ||
                ($critical['environment']??'')!=='staging') {
                $critical_state='invalid_contract';
                self::add_issue($issues,'critical_css_contract','P0','blocked',
                    ['reason'=>'invalid_tenant_scope_or_contract'],
                    'Keep the Critical CSS optimization disabled until company host and contract are reconciled.');
            }
            if (($critical['enabled']??false) &&
                ($critical['uael_version']??'')!==$versions['uael']) {
                $critical_state='fail_closed_version_drift';
                self::add_issue($issues,'critical_css_uael_version','P0','drift',
                    ['configured_version'=>$critical['uael_version']??'','observed_version'=>$versions['uael']],
                    'Keep normal CSS; recertify UAEL upgrade and device layouts before repinning.');
            }
            foreach($critical_docs as $row) {
                if ($row['state']!=='match') $critical_state='fail_closed_document_drift';
            }
        } else {
            self::add_issue($issues,'critical_css_config_missing','P1','missing',
                ['reason'=>'critical_preset_unavailable'],
                'Locate and validate the company-scoped Critical CSS config before any optimization.');
        }

        $fragment_state='unconfigured';
        $fragment_targets=[];
        if ($fragments!==null) {
            $fragment_state=($fragments['enabled']??false)?'enabled':'disabled';
            if (($fragments['contract']??'')!=='mad4b.company-performance-fragments.v1' ||
                ($fragments['environment']??'')!=='staging' ||
                strtolower((string)($fragments['site_host']??''))!==strtolower($host)) {
                $fragment_state='invalid_contract';
                self::add_issue($issues,'fragment_contract','P0','blocked',
                    ['reason'=>'invalid_tenant_scope_or_contract'],
                    'Keep fragment caches disabled until tenant contract is restored.');
            }
            foreach (['menu_document_id','attractions_document_id','attractions_listing_id'] as $name) {
                $id=(int)($fragments['targets'][$name]??0);
                if ($id<1) continue;
                $p=get_post($id);
                $fragment_targets[$name]=[
                    'document_id'=>$id,
                    'status'=>$p?(string)$p->post_status:'missing',
                    'modified_gmt'=>$p?(string)$p->post_modified_gmt:'',
                    'elementor_sha256'=>$p?hash('sha256',(string)get_post_meta($id,'_elementor_data',true)):null
                ];
            }
            // No content hashes are pinned for cards in v2: a 20s TTL
            // does NOT prove prices, visitor personalization, or term count parity.
            if ($fragment_state==='enabled') self::add_issue($issues,'fragment_visual_parity','P2','verification_required',
                ['ttl_seconds'=>$fragments['fragment_ttl_seconds']??null,
                 'risk'=>'dynamic_content_or_count_can_change_between_scans'],
                'Recheck anonymous English page cards, IDs, counts, menu images and carousel during browser QA; keep auth/cookie/geo-market bypass.');
        }

        $settings=[
            'woocommerce_default_customer_address'=>(string)get_option('woocommerce_default_customer_address',''),
            'woocommerce_calc_taxes'=>(string)get_option('woocommerce_calc_taxes',''),
            'currency_base'=>(string)get_option('woocommerce_currency',''),
            'wp_cron_disabled'=>defined('DISABLE_WP_CRON') && (bool)DISABLE_WP_CRON,
        ];
        if ($fragment_state==='enabled' && ($settings['woocommerce_default_customer_address']!=='base' ||
                $settings['woocommerce_calc_taxes']!=='no')) {
            self::add_issue($issues,'market_cache_guard','P0','unsafe_market_context',
                ['default_customer_address'=>$settings['woocommerce_default_customer_address'],
                 'calc_taxes'=>$settings['woocommerce_calc_taxes']],
                'Disable any affected fragment caching and audit GeoIP/currency/tax variance before considering activation.');
        }
        $settings['js_defer_policy']='not_scanned_by_browser';
        $settings['css_combine_policy']='not_scanned_by_browser';
        $readable=['critical_config'=>$critical!==null,'fragment_config'=>$fragments!==null];
        $source=[
            'host'=>$host,'environment'=>wp_get_environment_type(),
            'tracked_plugins'=>$plugins,'runtime_versions'=>$versions,
            'critical_css'=>['state'=>$critical_state,'enabled'=>(bool)($critical['enabled']??false),
                'configured_route_count'=>is_array($critical['routes']??null)?count($critical['routes']):0,
                'documents'=>$critical_docs,'defer_webfontloader'=>(bool)($critical['defer_webfontloader']??false)],
            'fragments'=>['state'=>$fragment_state,'enabled'=>(bool)($fragments['enabled']??false),
                'targets'=>$fragment_targets,'geo_policy'=>(string)($fragments['geo_policy']??'missing')],
            'settings'=>$settings,'readable'=>$readable,
        ];
        // Deterministic hash; avoid dynamic timestamps in fingerprint.
        $digest=hash('sha256',wp_json_encode($source,JSON_UNESCAPED_SLASHES));
        if (isset($input['previous_snapshot_sha256']) && !hash_equals($input['previous_snapshot_sha256'],$digest)) {
            self::add_issue($issues,'scan_snapshot_changed','P1','changed_since_last_scan',
                ['previous_digest'=>$input['previous_snapshot_sha256'],'current_digest'=>$digest],
                'Compare signed prior and current snapshots; rerun targeted browser QA before any performance-presets promotion.');
        }

        if (empty($issues)) self::add_issue($issues,'browser_baseline_pending','P2','verification_required',
            ['reason'=>'runtime_wp_does_not_measure_browser_lcp'],
            'Run Playwright/Lighthouse per locale and device; compare FCP/LCP/CLS, menu, gallery, JetSmartFilters and booking CTA before declaring a speed win.');
        else self::add_issue($issues,'browser_baseline_pending','P2','verification_required',
            ['reason'=>'runtime_wp_does_not_measure_browser_lcp'],
            'Run approved browser audit and attach Core Web Vitals; no estimated performance savings are release proof.');

        $weight=['P0'=>0,'P1'=>1,'P2'=>2];
        usort($issues,static function($a,$b)use($weight) {
            $p=$weight[$a['priority']]<=>$weight[$b['priority']];
            return $p ?: strcmp($a['id'],$b['id']);
        });

        return [
            'contract'=>self::CONTRACT,'scope'=>'current_staging_company_only',
            'readonly'=>true,'mutation_performed'=>false,'auto_apply_allowed'=>false,
            'scan_snapshot_sha256'=>$digest,
            'previous_snapshot_state'=>!isset($input['previous_snapshot_sha256'])?'not_supplied':
                (hash_equals($input['previous_snapshot_sha256'],$digest)?'unchanged':'changed'),
            'inventory'=>$source,
            'summary'=>[
                'critical_css_state'=>$critical_state,
                'fragment_state'=>$fragment_state,
                'issue_count'=>count($issues),
                'p0_count'=>count(array_filter($issues,static fn($x)=>$x['priority']==='P0')),
                'plan_state'=>'review_required',
            ],
            'remediation_plan'=>$issues,
            'limits'=>[
                'browser_lcp_cls_not_measured'=>true,
                'geoip_provider_behavior_unverified'=>true,
                'snapshot_persistence_external_operator_only'=>true,
                'plans_are_not_owner_approval'=>true,
            ]
        ];
    }
}
MAD4B_Company_Performance_Intelligence::boot();
