<?php
/**
 * Governed Site Lifecycle REST v1
 *
 * Reuses the native Site Profile lifecycle; no alternate option writer,
 * credential generator, OAuth enrollment, release approval, or proxy mutation.
 * The only write action is one explicitly requested, browser-session-bound
 * re-enrollment of a foreign/cloned profile as isolated non-production Staging.
 */
defined('ABSPATH') || exit;

final class MAD4B_SCP_Site_Lifecycle_REST {
    const NS = 'mad4b/v1';
    const ROOT = '/site-lifecycle';
    const CONTRACT = 'mad4b.site-lifecycle-rest.v1';
    const CONFIRM = 'REENROLL THIS VPS STAGING SITE PROFILE';
    private static $booted = false;

    public static function boot() {
        if (self::$booted) return;
        self::$booted = true;
        add_action('rest_api_init', array(__CLASS__, 'routes'), 35);
        add_action('admin_menu', array(__CLASS__, 'register_enrollment_admin'), 99);
    }

    public static function register_enrollment_admin() {
        if (wp_get_environment_type() !== 'staging') return;
        add_management_page('Complete Staging Enrollment','Complete Staging Enrollment',
            'manage_options','mad4b-complete-staging-enrollment',array(__CLASS__,'render_enrollment_admin'));
    }

    public static function render_enrollment_admin() {
        if (!current_user_can('manage_options')) wp_die('Administrator required');
        $status=self::snapshot();
        $profile=$status['profile'];
        $enabled=current_user_can('activate_plugins');
        $nonce=wp_create_nonce('wp_rest');
        $base=rest_url('mad4b/v1/site-lifecycle');
        echo '<div class="wrap"><h1>Complete Staging Enrollment</h1>';
        echo '<p>This action re-enrolls this exact VPS Staging Site Profile. It does not enable governed Write, OAuth, Skills, payments or Production access.</p>';
        echo '<table class="widefat striped" style="max-width:780px"><tbody>';
        foreach(array(
            'WordPress environment'=>$status['wordpress_environment'],
            'Current Site Profile environment'=>$profile['configured_environment'],
            'Origin'=>$status['canonical_origin'],
            'Host binding configured'=>!empty($profile['deployment_binding_configured'])?'Yes':'No',
            'Authority ready'=>!empty($profile['authority_ready'])?'Yes':'No',
            'Profile blockers'=>implode(', ',(array)($profile['blockers']??array()))
        ) as $key=>$value)
            echo '<tr><th>'.esc_html($key).'</th><td>'.esc_html((string)$value).'</td></tr>';
        echo '</tbody></table>';
        if (!$enabled) {
            echo '<p>Site owner permission activate_plugins required. No enrollment action available.</p></div>';
            return;
        }
        if (!empty($profile['authority_ready']) && !empty($profile['same_origin_clone_protection'])) {
            echo '<p><strong>Staging Site Profile is already bound and ready.</strong></p></div>';
            return;
        }
        echo '<p><button type="button" class="button button-primary" id="mad4b-stage-enroll-confirm">Re-enroll this Staging Site Profile</button></p>';
        echo '<pre id="mad4b-stage-enroll-output" style="max-width:780px;white-space:pre-wrap"></pre>';
        $script='(function(){const b=document.getElementById("mad4b-stage-enroll-confirm");';
        $script.='if(!b)return;const o=document.getElementById("mad4b-stage-enroll-output");';
        $script.='const base='.wp_json_encode($base).';const nonce='.wp_json_encode($nonce).';';
        $script.='const cfg={credentials:"same-origin",headers:{"X-WP-Nonce":nonce,"Accept":"application/json"}};';
        $script.='b.addEventListener("click",async()=>{b.disabled=true;try{';
        $script.='const p=await fetch(base+"/plan",cfg);const plan=await p.json();';
        $script.='if(!p.ok||!plan.eligible){o.textContent="Enrollment blocked: "+JSON.stringify(plan.blockers||plan);return;}';
        $script.='if(!confirm("Re-enroll this exact Staging Site Profile? Write and OAuth permissions will remain disabled."))return;';
        $script.='const payload={expected_revision:plan.expected_revision,expected_site_uuid:plan.expected_site_uuid,';
        $script.='expected_profile_digest:plan.expected_profile_digest,expected_plan_sha256:plan.plan_sha256,';
        $script.='expected_origin:plan.expected_origin,confirmation:plan.confirmation};';
        $script.='const r=await fetch(base+"/reenroll-staging",{method:"POST",credentials:"same-origin",';
        $script.='headers:{"Content-Type":"application/json","X-WP-Nonce":nonce},body:JSON.stringify(payload)});';
        $script.='const data=await r.json();o.textContent=JSON.stringify({http:r.status,response:data},null,2);';
        $script.='if(r.ok)setTimeout(()=>location.reload(),1500);';
        $script.='}catch(e){o.textContent="Request failed: "+e.message;}finally{b.disabled=false;}});})();';
        echo '<script>'. $script .'</script></div>';
    }

    public static function routes() {
        foreach (array(
            '/status'           => 'status',
            '/plan'             => 'plan',
            '/readback'         => 'readback',
            '/binding-status'   => 'binding_status',
            '/cutover-plan'     => 'cutover_plan',
            '/rollback-plan'    => 'rollback_plan',
        ) as $suffix => $handler) {
            register_rest_route(self::NS, self::ROOT . $suffix, array(
                'methods' => WP_REST_Server::READABLE,
                'callback' => array(__CLASS__, $handler),
                'permission_callback' => array(__CLASS__, 'can_read'),
            ));
        }
        register_rest_route(self::NS, self::ROOT . '/reenroll-staging', array(
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => array(__CLASS__, 'reenroll_staging'),
            'permission_callback' => array(__CLASS__, 'can_write'),
            'args' => array(
                'expected_revision' => array('required'=>true,'type'=>'integer','minimum'=>1),
                'expected_site_uuid' => array('required'=>true,'type'=>'string','pattern'=>'^[a-f0-9-]{36}$'),
                'expected_profile_digest' => array('required'=>true,'type'=>'string','pattern'=>'^[a-f0-9]{64}$'),
                'expected_plan_sha256' => array('required'=>true,'type'=>'string','pattern'=>'^[a-f0-9]{64}$'),
                'expected_origin' => array('required'=>true,'type'=>'string','format'=>'uri'),
                'confirmation' => array('required'=>true,'type'=>'string','enum'=>array(self::CONFIRM)),
            ),
        ));
    }

    public static function can_read() {
        return current_user_can('manage_options');
    }

    /**
     * A WP REST application password or arbitrary Bearer token is NOT a
     * substitute for an authenticated native WordPress admin browser session.
     * In addition to standard WP REST auth, require an exact session and nonce.
     */
    public static function can_write() {
        if (!current_user_can('manage_options') || !current_user_can('activate_plugins'))
            return new WP_Error('mad4b_lifecycle_admin_required', 'Administrator privileges are required.', array('status'=>403));
        if (wp_get_environment_type() !== 'staging'
            || !defined('WP_ENVIRONMENT_TYPE')
            || WP_ENVIRONMENT_TYPE !== 'staging')
            return new WP_Error('mad4b_lifecycle_staging_only','Only an explicitly configured Staging environment may be re-enrolled.',array('status'=>403));
        $cookie = defined('LOGGED_IN_COOKIE') ? (string) ($_COOKIE[LOGGED_IN_COOKIE]??'') : '';
        $cookie_user = $cookie !== '' ? wp_validate_auth_cookie($cookie,'logged_in') : false;
        $session = function_exists('wp_get_session_token') ? (string)wp_get_session_token() : '';
        $nonce = isset($_SERVER['HTTP_X_WP_NONCE']) ? (string)$_SERVER['HTTP_X_WP_NONCE'] : '';
        if (!$cookie_user || (int)$cookie_user !== get_current_user_id() ||
            $session === '' || $nonce === '' ||
            !wp_verify_nonce($nonce, 'wp_rest'))
            return new WP_Error('mad4b_lifecycle_browser_session_required',
                'A valid WordPress administrator session and X-WP-Nonce are required; bearer credentials cannot re-enroll.',array('status'=>403));
        return true;
    }

    private static function snapshot() {
        $s = MAD4B_SCP_Site_Profile::status();
        $profile = MAD4B_SCP_Site_Profile::profile();
        $audit = class_exists('MAD4B_SCP_Audit') ? MAD4B_SCP_Audit::storage_status() : array();
        $env = wp_get_environment_type();
        $origin = MAD4B_SCP_Site_Profile::current_origin();
        $keys = array('configured','site_uuid','revision','profile_digest','environment',
            'configured_environment','environment_match','origin_match',
            'deployment_binding_configured','deployment_binding_bound',
            'deployment_binding_match','same_origin_clone_protection',
            'authority_ready','write_enabled','oauth_enabled','skills_enabled',
            'blockers','mutation_pending_audit','binding_state','reenrollment_required');
        $status = array();
        foreach ($keys as $key) $status[$key] = $s[$key] ?? null;
        return array(
            'contract'=>self::CONTRACT,
            'wordpress_environment'=>$env,
            'canonical_origin'=>$origin,
            'db_isolation'=>array(
                'database_name'=>defined('DB_NAME') ? DB_NAME : '',
                'is_staging_named'=>defined('DB_NAME') && DB_NAME !== '',
                'note'=>'Database name is informational; credential and topology isolation require host checks.',
            ),
            'profile'=>$status,
            'audit_ready'=>!empty($audit['ready']) && !empty($audit['append_only']),
            'audit_event_count'=>isset($audit['event_count'])?(int)$audit['event_count']:null,
            'commercial_runtime_enabled'=>defined('MAD4B_COMMERCE_RUNTIME_ENABLED') && MAD4B_COMMERCE_RUNTIME_ENABLED,
            'host_secret_exposed'=>false,
            'no_production_mutation'=>true,
        );
    }

    private static function rest_response($body, $http=200) {
        $response = new WP_REST_Response($body,$http);
        $response->header('Cache-Control','private, no-store, max-age=0');
        return $response;
    }

    public static function status() {
        return self::rest_response(self::snapshot());
    }

    private static function rebind_plan() {
        $snapshot=self::snapshot();
        $s=$snapshot['profile'];
        $target='staging';
        $origin=(string)$snapshot['canonical_origin'];
        $is_https=stripos($origin,'https://')===0;
        $preconditions=array(
            'wordpress_explicit_staging'=>$snapshot['wordpress_environment']===$target
                &&defined('WP_ENVIRONMENT_TYPE')&&WP_ENVIRONMENT_TYPE==='staging',
            'source_profile_configured'=>!empty($s['configured']),
            'source_not_pending_audit'=>empty($s['mutation_pending_audit']),
            'valid_current_identity'=>(int)$s['revision']>=1
                &&is_string($s['site_uuid'])&&preg_match('/^[a-f0-9-]{36}$/',$s['site_uuid'])===1,
            'current_origin_https'=>$is_https,
            'current_origin_matches_existing'=>!empty($s['origin_match']),
            'append_only_audit_ready'=>!empty($snapshot['audit_ready']),
            'requires_reenrollment'=>empty($s['authority_ready'])
                &&$s['configured_environment']!=='staging',
        );
        $identity=array(
            'site_uuid'=>(string)($s['site_uuid']??''),
            'revision'=>(int)($s['revision']??0),
            'profile_digest'=>(string)($s['profile_digest']??''),
            'target_environment'=>$target,
            'origin'=>$origin,
            'database_name'=>$snapshot['db_isolation']['database_name'],
        );
        $digest=hash('sha256',wp_json_encode(array('contract'=>self::CONTRACT,'identity'=>$identity,
            'preconditions'=>$preconditions,
            'host_binding_present'=>!empty($s['deployment_binding_configured'])),
            JSON_UNESCAPED_SLASHES));
        return array(
            'contract'=>self::CONTRACT.'.reenroll-plan',
            'plan_sha256'=>$digest,
            'expected_site_uuid'=>$identity['site_uuid'],
            'expected_revision'=>$identity['revision'],
            'expected_profile_digest'=>$identity['profile_digest'],
            'expected_origin'=>$origin,
            'target_environment'=>$target,
            'checks'=>$preconditions,
            'eligible'=>!in_array(false,$preconditions,true),
            'blockers'=>array_keys(array_filter($preconditions,static function($ok){return !$ok;})),
            'write_features_after_reenroll'=>array(
                'oauth'=>false,'skills'=>false,'write'=>false,'acceptance'=>false,
                'managed_runtime'=>false,'provider_isolation'=>false,
            ),
            'confirmation'=>self::CONFIRM,
            'host_binding_present'=>!empty($s['deployment_binding_configured']),
            'host_binding_required_before_cutover'=>true,
            'no_automatic_binding_creation'=>true,
            'no_automatic_governed_write_enablement'=>true,
        );
    }

    public static function plan() {
        return self::rest_response(self::rebind_plan());
    }

    public static function binding_status() {
        $s=self::snapshot();
        $p=$s['profile'];
        return self::rest_response(array(
            'contract'=>self::CONTRACT.'.host-binding',
            'configured'=>!empty($p['deployment_binding_configured']),
            'bound'=>!empty($p['deployment_binding_bound']),
            'matches'=>!empty($p['deployment_binding_match']),
            'clone_protected'=>!empty($p['same_origin_clone_protection']),
            'site_profile_authority_ready'=>!empty($p['authority_ready']),
            'creation_via_rest_allowed'=>false,
            'native_owner_flow'=>'MAD4B Site Profile admin save, then approved Native Host Binding lifecycle',
            'credential_value_exposed'=>false,
            'production_secret_mutation_allowed'=>false,
        ));
    }

    public static function readback() {
        $s=self::snapshot();
        $plan=self::rebind_plan();
        return self::rest_response(array(
            'contract'=>self::CONTRACT.'.readback',
            'effective_environment'=>$s['wordpress_environment'],
            'configured_environment'=>$s['profile']['configured_environment'],
            'current_origin'=>$s['canonical_origin'],
            'revision'=>$s['profile']['revision'],
            'profile_digest'=>$s['profile']['profile_digest'],
            'site_uuid'=>$s['profile']['site_uuid'],
            'profile_ready'=>!empty($s['profile']['authority_ready']),
            'binding_verified'=>!empty($s['profile']['same_origin_clone_protection']),
            'audit_ready'=>$s['audit_ready'],
            'pending'=>$s['profile']['mutation_pending_audit'],
            'blockers'=>$s['profile']['blockers'],
            'next_reenrollment_plan_sha256'=>$plan['plan_sha256'],
            'write_enabled'=>!empty($s['profile']['write_enabled']),
            'confirmation_is_not_authority'=>true,
        ));
    }

    public static function cutover_plan() {
        $s=self::snapshot();
        $p=$s['profile'];
        $checks=array(
            'exact_staging_environment'=>$s['wordpress_environment']==='staging',
            'governed_profile_ready'=>!empty($p['authority_ready']),
            'distinct_host_binding_verified'=>!empty($p['same_origin_clone_protection']),
            'audit_ready'=>$s['audit_ready'],
            'commercial_suite_loaded'=>class_exists('WooCommerce')
                &&function_exists('shared_product_tour_rate_search'),
            'fluent_form6_loaded'=>class_exists('FluentForm\App\Models\Form'),
        );
        return self::rest_response(array(
            'contract'=>self::CONTRACT.'.cutover-plan',
            'checks'=>$checks,
            'wordpress_preflight_ready'=>!in_array(false,$checks,true),
            'host_route_cutover_authorized'=>false,
            'host_route_is_live_staging'=>'unverified_external_reverse_proxy',
            'requires_external_vps_evidence'=>array(
                'source_database_freeze_or_final_delta',
                'proxy_rollback_snapshot',
                'target_https_and_browser_acceptance',
                'host_owner_cutover_approval',
            ),
            'automatically_stops_old_lab'=>false,
        ));
    }

    public static function rollback_plan() {
        return self::rest_response(array(
            'contract'=>self::CONTRACT.'.rollback-plan',
            'site_scope'=>'current_wordpress_only',
            'rest_rollback_authorized'=>false,
            'preflight_required'=>array('owned_snapshot','exact_expected_revision','audit_evidence',
                'external_proxy_target','separate_database_restore_authorization'),
            'native_safe_continuation'=>'Use VPS rollback snapshot and governed Site Profile lifecycle, not an arbitrary REST SQL/proxy write.',
            'production_mutation_authorized'=>false,
        ));
    }

    public static function reenroll_staging(WP_REST_Request $req) {
        $gate=self::can_write();
        if (is_wp_error($gate)) return $gate;
        $expected=array('expected_revision','expected_site_uuid',
            'expected_profile_digest','expected_plan_sha256','expected_origin','confirmation');
        $body=$req->get_json_params();
        if (!is_array($body))
            return new WP_Error('mad4b_lifecycle_exact_body_required',
                'Submit only the six exact plan-bound JSON properties.',array('status'=>400));
        $submitted=array_keys($body);
        sort($submitted);
        sort($expected);
        if ($submitted !== $expected)
            return new WP_Error('mad4b_lifecycle_exact_body_required',
                'Submit only the six exact plan-bound JSON properties.',array('status'=>400));
        $plan=self::rebind_plan();
        foreach (array(
            'expected_revision'=>'expected_revision',
            'expected_site_uuid'=>'expected_site_uuid',
            'expected_profile_digest'=>'expected_profile_digest',
            'expected_plan_sha256'=>'plan_sha256',
            'expected_origin'=>'expected_origin',
            'confirmation'=>'confirmation',
        ) as $request=>$from) {
            if (!isset($body[$request]) || (string)$body[$request] !== (string)$plan[$from])
                return new WP_Error('mad4b_lifecycle_stale_or_unapproved_plan',
                    'The request differs from the current Site Profile plan.',array('status'=>409));
        }
        if (empty($plan['eligible']))
            return new WP_Error('mad4b_lifecycle_host_preflight_blocked',
                'The existing deployment binding, audit or other exact-site preconditions are not satisfied.',
                array('status'=>409,'blockers'=>$plan['blockers']));
        $origin=(string)MAD4B_SCP_Site_Profile::current_origin();
        if (!hash_equals((string)$plan['expected_origin'],$origin))
            return new WP_Error('mad4b_lifecycle_origin_changed','Site origin changed during enrollment.',array('status'=>409));
        // Deliberate narrow, safe re-enrollment; no write or remote feature
        // copied from cloned source. Subject must opt in separately later.
        $input=array(
            'environment'=>'staging',
            'environment_sync_mode'=>MAD4B_SCP_Site_Profile::ENV_SYNC_PROFILE_ONLY,
            'display_name'=>'ETG Commerce Staging',
            'chatgpt_app_id'=>'',
            'oauth_user_ids'=>(string)get_current_user_id(),
            'staging_origin'=>$origin,
            'oauth_enabled'=>false,
            'skills_enabled'=>false,
            'write_enabled'=>false,
            'provider_isolation_enabled'=>false,
            'managed_runtime_enabled'=>false,
            'acceptance_enabled'=>false,
            'expected_revision'=>(int)$plan['expected_revision'],
            'expected_profile_digest'=>(string)$plan['expected_profile_digest'],
        );
        $result=MAD4B_SCP_Site_Profile::save_current_site($input);
        if (is_wp_error($result))
            return new WP_Error($result->get_error_code(), $result->get_error_message(), array('status'=>409));
        MAD4B_SCP_Site_Profile::reset_cache();
        $after=MAD4B_SCP_Site_Profile::status();
        $fresh=MAD4B_SCP_Site_Profile::profile();
        $readback=class_exists('MAD4B_SCP_Site_Profile_Admin')
            &&MAD4B_SCP_Site_Profile_Admin::persisted_readback_matches($input,$result,$after,$fresh);
        $verified=$readback
            &&$after['configured_environment']==='staging'
            &&$after['environment']==='staging'
            &&!empty($after['authority_ready'])
            &&empty($after['write_enabled'])
            &&empty($after['mutation_pending_audit']);
        $host_bound=$verified && !empty($after['same_origin_clone_protection']);
        return self::rest_response(array(
            'contract'=>self::CONTRACT.'.reenroll-result',
            'state'=>!$verified?'PERSISTED_BUT_RECONCILIATION_REQUIRED':
                ($host_bound?'REENROLLED_AND_HOST_BOUND':'REENROLLED_HOST_BINDING_REQUIRED'),
            'readback_verified'=>(bool)$verified,
            'host_binding_verified'=>(bool)$host_bound,
            'new_site_uuid'=>(string)($after['site_uuid']??''),
            'new_revision'=>(int)($after['revision']??0),
            'new_profile_digest'=>(string)($after['profile_digest']??''),
            'authority_ready'=>(bool)($after['authority_ready']??false),
            'write_enabled'=>(bool)($after['write_enabled']??false),
            'blockers'=>$after['blockers']??array(),
        ),!$verified?409:($host_bound?200:202));
    }
}
