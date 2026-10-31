<?php
/**
 * Component: MAD4B Company Readiness — Governed Workflow
 * Description: Read-only readiness receipts, per-company access boundary and closure gates.
 * Version: 1.0.0
 */
defined('ABSPATH') || exit;

final class MAD4B_Company_Readiness_Governance {
    const STORE = 'mad4b_company_readiness_workflow_status_v1';
    const PREFIX = '/mad4b/v1/company-brand-readiness/';
    public static function init() {
        add_filter('rest_pre_dispatch', array(__CLASS__,'pre_dispatch'), 8, 3);
        add_filter('rest_post_dispatch', array(__CLASS__,'limit_companies_response'), 8, 3);
        add_action('rest_api_init', array(__CLASS__,'routes'), 85);
        add_action('admin_notices', array(__CLASS__,'admin_summary'), 90);
        add_action('admin_init', array(__CLASS__,'guard_admin_screen'), 20);
        add_action('wp_abilities_api_init', array(__CLASS__,'abilities'), 80);
    }
    private static function central() {
        // This is transport readiness, not permission to execute. Company tenancy,
        // actor privileges and operation-specific approvals are checked separately.
        $url = function_exists('home_url') ? home_url() : '';
        $host = is_string($url) ? wp_parse_url($url, PHP_URL_HOST) : false;
        $scheme = is_string($url) ? wp_parse_url($url, PHP_URL_SCHEME) : false;
        return $scheme === 'https' && is_string($host) && $host !== ''
            && $host !== 'localhost' && !filter_var($host, FILTER_VALIDATE_IP)
            && filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME);
    }
    private static function registry() {
        $r = get_option('mad4b_tourism_company_site_registry_v1', array());
        return is_array($r) ? $r : array();
    }
    private static function is_platform_admin() {
        return current_user_can('manage_options') && current_user_can('activate_plugins');
    }
    private static function user_companies() {
        $registered = self::registry();
        $assigned = get_user_meta(get_current_user_id(), 'mad4b_allowed_company_codes', true);
        if (is_array($assigned)) return array_values(array_intersect(array_keys($registered), $assigned));
        // Existing single-company master_admin installation; no cross-tenant read possible.
        if (count($registered) === 1) return array_keys($registered);
        return array();
    }
    public static function guard($request = null) {
        if (!self::central()) return new WP_Error('mad4b_company_wrong_host','Control Plane only',array('status'=>403));
        if (self::is_platform_admin()) return true;
        if (!current_user_can('mad4b_manage_company_operations') ||
            !current_user_can('mad4b_propose_company_content'))
            return new WP_Error('mad4b_company_operator_required','Company operator permission required',array('status'=>403));
        $allowed = self::user_companies();
        if (!$allowed) return new WP_Error('mad4b_company_assignment_required','Explicit company assignment required',array('status'=>403));
        $company = $request instanceof WP_REST_Request ? sanitize_key((string)$request->get_param('company')) : '';
        if ($company !== '' && !in_array($company, $allowed, true))
            return new WP_Error('mad4b_company_cross_tenant_denied','Company is not assigned to this operator',array('status'=>403));
        return true;
    }
    public static function pre_dispatch($result, $server, $request) {
        if (is_wp_error($result) || !($request instanceof WP_REST_Request)) return $result;
        if (strpos($request->get_route(),self::PREFIX) !== 0) return $result;
        // rest_pre_dispatch runs before WordPress sets route URL parameters.
        // Resolve the company directly from the verified route string.
        $scoped = new WP_REST_Request($request->get_method(), $request->get_route());
        if (preg_match('~^/mad4b/v1/company-brand-readiness/companies/([a-z][a-z0-9-]{2,47})(?:/|$)~D',
            $request->get_route(), $matches))
            $scoped->set_param('company', $matches[1]);
        $allowed = self::guard($scoped);
        return is_wp_error($allowed) ? $allowed : $result;
    }
    public static function limit_companies_response($response, $server, $request) {
        if (!($request instanceof WP_REST_Request) ||
            $request->get_route() !== self::PREFIX . 'companies' ||
            self::is_platform_admin() || is_wp_error($response) ||
            !($response instanceof WP_REST_Response)) return $response;
        $data = $response->get_data();
        if (!is_array($data) || !is_array($data['companies'] ?? null)) return $response;
        $allowed = self::user_companies();
        $data['companies'] = array_values(array_filter($data['companies'], static function($item)use($allowed){
            return in_array($item['company_code'] ?? '', $allowed, true);
        }));
        $response->set_data($data);
        return $response;
    }
    public static function guard_admin_screen() {
        if (($_GET['page'] ?? '') !== 'mad4b-company-brand-readiness') return;
        $allowed = self::guard();
        if (is_wp_error($allowed)) wp_die(esc_html($allowed->get_error_message()), 403);
        if (self::is_platform_admin()) return;
        $registered = self::registry();
        $selected = sanitize_key((string)($_GET['company'] ?? ''));
        $assigned = self::user_companies();
        // The original company selector lists all tenants. Deny the unsafe multi-tenant
        // admin screen until it is replaced with an assignment-filtered selector.
        if (count($registered)>1 || ($selected !== '' && !in_array($selected,$assigned,true)))
            wp_die('Company-scoped selection requires the assignment-filtered screen.',403);
    }
    public static function routes() {
        register_rest_route('mad4b/v1','/company-brand-readiness/companies/(?P<company>[a-z][a-z0-9-]{2,47})/workflow',
            array('methods'=>'GET','callback'=>array(__CLASS__,'workflow'),
                  'permission_callback'=>array(__CLASS__,'guard')));
    }
    public static function workflow($request) {
        $permit=self::guard($request);
        if(is_wp_error($permit))return $permit;
        $company = sanitize_key((string)$request->get_param('company'));
        $registry = self::registry();
        if (empty($registry[$company]['host_binding_verified']))
            return new WP_Error('mad4b_company_unbound','Unbound company',array('status'=>404));
        $all = get_option(self::STORE, array());
        $report = is_array($all) ? ($all[$company] ?? null) : null;
        if (!is_array($report) ||
            ($report['site_uuid'] ?? '') !== ($registry[$company]['company_site_uuid'] ?? ''))
            return new WP_Error('mad4b_workflow_evidence_pending','Readiness evidence not collected',array('status'=>503));
        $response = rest_ensure_response($report);
        $response->header('Cache-Control','private, no-store');
        return $response;
    }
    public static function admin_summary() {
        if (!self::central() || ($_GET['page'] ?? '') !== 'mad4b-company-brand-readiness') return;
        if (is_wp_error(self::guard())) return;
        $registry = self::registry();
        $company = sanitize_key((string)($_GET['company'] ?? ''));
        if ($company === '') $company = (string)array_key_first($registry);
        if (!self::is_platform_admin() && !in_array($company,self::user_companies(),true)) return;
        $all = get_option(self::STORE,array());
        $report = is_array($all) ? ($all[$company] ?? null) : null;
        echo '<div class="notice notice-info" style="padding:12px;max-width:1020px">';
        echo '<h2>Governed Company Readiness Workflow — '.esc_html($company).'</h2>';
        if (!is_array($report)) {
            echo '<p>No current audit receipt. Run the governed VPS readiness collector.</p></div>';
            return;
        }
        echo '<p><strong>'.esc_html($report['result'] ?? 'UNKNOWN').'</strong> | '.
            esc_html($report['generated_utc'] ?? '').' | Audit SHA-256: <code>'.
            esc_html(substr((string)($report['sha256'] ?? ''),0,20)).'…</code></p>';
        echo '<table class="widefat striped"><thead><tr><th>Stage</th><th>State</th><th>Required action</th></tr></thead><tbody>';
        foreach ((array)($report['stages'] ?? array()) as $stage=>$v) {
            echo '<tr><td>'.esc_html(str_replace('_',' ',$stage)).'</td><td><strong>'.
                esc_html($v['state'] ?? '').'</strong></td><td>'.
                esc_html($v['next_action'] ?? '').'</td></tr>';
        }
        echo '</tbody></table><p>Source media references are never rewritten or copied without asset existence and usage-rights checks. Only published and approved canonical Content Hub assets can enter commercial distribution.</p></div>';
    }
    public static function abilities() {
        if (!self::central() || !function_exists('wp_register_ability')) return;
        if (function_exists('wp_has_ability') && wp_has_ability('mad4b/company-readiness-workflow')) return;
        wp_register_ability('mad4b/company-readiness-workflow',array(
            'label'=>'Read governed company readiness workflow',
            'description'=>'Read company-bound readiness stages, blockers and evidence.',
            'category'=>'mad4b-read',
            'input_schema'=>array('type'=>'object','properties'=>array('company'=>array('type'=>'string')),
                'required'=>array('company'),'additionalProperties'=>false),
            'output_schema'=>array('type'=>'object'),
            'execute_callback'=>array(__CLASS__,'ability_workflow'),
            'permission_callback'=>static function($input) {
                $r = new WP_REST_Request('GET','/');
                $r->set_param('company', is_array($input) ? ($input['company'] ?? '') : '');
                return self::guard($r);
            },
            'meta'=>array('show_in_rest'=>true,'readonly'=>true)
        ));
    }
    public static function ability_workflow($input) {
        $r = new WP_REST_Request('GET','/');
        $r->set_param('company',(string)($input['company'] ?? ''));
        $out=self::workflow($r);
        return $out instanceof WP_REST_Response ? $out->get_data() : $out;
    }
}
MAD4B_Company_Readiness_Governance::init();

