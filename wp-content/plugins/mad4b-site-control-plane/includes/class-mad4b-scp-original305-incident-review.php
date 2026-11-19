<?php
/**
 * Original305 signed-readiness incident review — native approval ticket adapter.
 *
 * A real logged-in WP administrator may plan and decide an exact incident
 * review through the existing Approval_Tickets engine. This never generates
 * Master signatures, Host312 grants, Original313 receipts, or removes HOLD.
 */
defined('ABSPATH') || exit;

final class MAD4B_SCP_Original305_Incident_Review {
    const NS = 'mad4b-central/v1';
    const PATH = '/original305-incident-review';
    const ABILITY = 'mad4b/original305-incident-review';
    const PROVIDER = 'mad4b-original305-incident';
    const SERVER = 'mad4b-write';
    const TICKET_CLASS = 'mutation';
    const CONTRACT = 'mad4b.original305-incident-closure-review-request.v2';
    const OPERATION = 'host.source.release';
    const ACTION = 'review_legacy_ambiguous_signed_write';
    const MAX_TTL = 900;
    private static $booted = false;

    public static function boot() {
        if (self::$booted) return;
        self::$booted = true;
        add_action('wp_abilities_api_init', array(__CLASS__, 'register_ability'), 45);
        add_action('rest_api_init', array(__CLASS__, 'register_routes'), 45);
        add_action('admin_menu', array(__CLASS__, 'register_admin_page'), 95);
        // Admin-only status transport; frontend REST gateways may hide private routes.
        add_action('wp_ajax_mad4b_original305_readiness_status', array(__CLASS__, 'ajax_readiness_status'));
        add_filter('site_status_tests', array(__CLASS__, 'site_health_tests'));
        add_action('admin_notices', array(__CLASS__, 'dashboard_notice'), 90);
    }

    /** Native WordPress Ability metadata is mandatory for ticket hashing.
     *  This executor *always* denies; an approved review ticket does not
     *  authorize any Host action or issue a cryptographic Master envelope.
     */
    public static function register_ability() {
        if (!function_exists('wp_register_ability') ||
            (function_exists('wp_has_ability') && wp_has_ability(self::ABILITY))) return;
        wp_register_ability(self::ABILITY, array(
            'label'=>'Original305 incident review',
            'description'=>'Original native WordPress ticket review of an exact historical signed-readiness incident. No host execution.',
            'category'=>'mad4b-write',
            'execute_callback'=>array(__CLASS__, 'deny_direct_execution'),
            'permission_callback'=>array(__CLASS__, 'can_decide'),
            'input_schema'=>array('type'=>'object','properties'=>array(),'additionalProperties'=>false),
            'output_schema'=>array('type'=>'object','additionalProperties'=>true),
            'meta'=>array(
                'public'=>false,'show_in_rest'=>false,
                'mcp'=>array('public'=>false,'type'=>'tool','surface'=>'internal'),
                'annotations'=>array('readonly'=>false,'destructive'=>false,'idempotent'=>false),
            ),
        ));
    }

    public static function deny_direct_execution($input=array()) {
        return new WP_Error('mad4b_original305_direct_execution_denied',
            'Only the original authenticated WordPress approval ticket workflow may review this incident.',
            array('status'=>403));
    }

    public static function register_routes() {
        register_rest_route(self::NS, self::PATH . '/status', array(
            'methods'=>WP_REST_Server::READABLE,
            'permission_callback'=>array(__CLASS__, 'can_read'),
            'callback'=>array(__CLASS__, 'status'),
        ));
        register_rest_route(self::NS, self::PATH . '/tickets', array(
            'methods'=>WP_REST_Server::CREATABLE,
            'permission_callback'=>array(__CLASS__, 'can_create'),
            'callback'=>array(__CLASS__, 'create_pending'),
        ));
        register_rest_route(self::NS, self::PATH . '/tickets/(?P<ticket_id>[a-zA-Z0-9_.:-]{8,90})', array(
            'methods'=>WP_REST_Server::READABLE,
            'permission_callback'=>array(__CLASS__, 'can_read'),
            'callback'=>array(__CLASS__, 'ticket_status'),
        ));
        register_rest_route(self::NS, self::PATH . '/tickets/(?P<ticket_id>[a-zA-Z0-9_.:-]{8,90})/decision', array(
            'methods'=>WP_REST_Server::CREATABLE,
            'permission_callback'=>array(__CLASS__, 'can_decide'),
            'callback'=>array(__CLASS__, 'decide'),
        ));
    }

    public static function can_read() {
        return get_current_user_id() > 0 && current_user_can('manage_options');
    }

    /** Require the authenticated browser cookie and fresh native REST nonce. */
    private static function browser_session() {
        if (!self::can_read()) return new WP_Error('mad4b_original305_admin_required', 'A WordPress administrator is required.', array('status'=>403));
        $cookie = defined('LOGGED_IN_COOKIE') ? (string) ($_COOKIE[LOGGED_IN_COOKIE] ?? '') : '';
        $cookie_id = $cookie !== '' ? wp_validate_auth_cookie($cookie, 'logged_in') : false;
        $session = function_exists('wp_get_session_token') ? (string) wp_get_session_token() : '';
        $nonce = isset($_SERVER['HTTP_X_WP_NONCE']) && is_string($_SERVER['HTTP_X_WP_NONCE']) ? $_SERVER['HTTP_X_WP_NONCE'] : '';
        if (!$cookie_id || (int) $cookie_id !== get_current_user_id() || $session === '' ||
            $nonce === '' || !wp_verify_nonce($nonce, 'wp_rest')) {
            return new WP_Error('mad4b_original305_browser_session_required',
                'An authenticated WordPress administrator browser session and fresh X-WP-Nonce are required.',
                array('status'=>403));
        }
        return true;
    }

    public static function can_create() {
        $auth = self::browser_session();
        if (is_wp_error($auth)) return $auth;
        if (!class_exists('MAD4B_SCP_Policy', false) ||
            !method_exists('MAD4B_SCP_Policy', 'can_plan_mutations') ||
            !MAD4B_SCP_Policy::can_plan_mutations()) {
            return new WP_Error('mad4b_original305_planner_required',
                'Original native mutation planner permission is required.', array('status'=>403));
        }
        return true;
    }

    public static function can_decide() {
        $auth = self::browser_session();
        if (is_wp_error($auth)) return $auth;
        if (!class_exists('MAD4B_SCP_Policy', false) ||
            !method_exists('MAD4B_SCP_Policy', 'can_approve_mutations') ||
            !MAD4B_SCP_Policy::can_approve_mutations()) {
            return new WP_Error('mad4b_original305_native_approver_required',
                'Original native approval authority is required.', array('status'=>403));
        }
        return true;
    }

    public static function exact_review_request($request) {
        $keys = array('action','approval_ability','contract','host_release_authorized','legacy_hold_sha256',
            'operation_id','registry_sha256','request_sha256','service_unit_sha256',
            'state','supervisor_sha256');
        if (!is_array($request) || count($request) !== count($keys) ||
            array_diff(array_keys($request), $keys) || array_diff($keys, array_keys($request))) {
            return new WP_Error('mad4b_original305_review_schema_invalid', 'An exact V2 incident request is required.');
        }
        if ($request['contract'] !== self::CONTRACT || $request['operation_id'] !== self::OPERATION ||
            $request['action'] !== self::ACTION || $request['approval_ability'] !== self::ABILITY ||
            $request['state'] !== 'REQUIRES_FIRSTPARTY_SIGNED_MASTER_REVIEW' ||
            $request['host_release_authorized'] !== false) {
            return new WP_Error('mad4b_original305_review_scope_invalid', 'Incident request scope is not permitted.');
        }
        foreach (array('legacy_hold_sha256','registry_sha256','supervisor_sha256','service_unit_sha256','request_sha256') as $key) {
            if (!is_string($request[$key]) || !preg_match('/^[a-f0-9]{64}$/D', $request[$key])) {
                return new WP_Error('mad4b_original305_review_digest_invalid', 'Incident request digests are invalid.');
            }
        }
        $provided = (string) $request['request_sha256'];
        $copy = $request;
        unset($copy['request_sha256']);
        ksort($copy, SORT_STRING);
        $canon = wp_json_encode($copy, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
        if (!is_string($canon) || !hash_equals($provided, hash('sha256', $canon))) {
            return new WP_Error('mad4b_original305_review_hash_changed', 'Incident request digest does not match its contents.');
        }
        return $request;
    }

    private static function input($plan) { return array('original305_incident_request'=>$plan); }
    private static function target($plan) { return (string) $plan['request_sha256']; }
    private static function ticket_engine_available() {
        return class_exists('MAD4B_SCP_Approval_Tickets', false) &&
               class_exists('MAD4B_SCP_Agent_Registry', false) &&
               class_exists('MAD4B_SCP_Approval_Impact_Binding', false) &&
               function_exists('wp_has_ability') && wp_has_ability(self::ABILITY);
    }
    private static function safe_ticket($t) {
        if (!is_array($t)) return array();
        return array_intersect_key($t, array_fill_keys(array(
            'ticket_id','status','payload_sha256','ability_name','provider',
            'target_fingerprint','expires_at','approved_by','ticket_class'), true));
    }

    /** Host observations are display-only telemetry, never an approval source. */
    private static function runtime_observation() {
        $o=get_option('mad4b_original305_runtime_observation_v1', false);
        $empty=array('state'=>'AWAITING_AUTOMATED_OBSERVATION', 'fresh'=>false,
            'legacy_hold_present'=>null, 'timer_enabled'=>null,
            'original_v8_registry_exact'=>null, 'signed_readiness_admitted'=>false,
            'wordpress_readback_available'=>null, 'wordpress_readback_state'=>null,
            'hold_evidence_consistency_verified'=>null,
            'observation_epoch'=>null, 'next_authorized_step'=>'AUTOMATED_DIAGNOSTICS_PENDING');
        if (!is_array($o) || ($o['contract'] ?? null)!=='mad4b.original305-diagnostic-snapshot.v1') return $empty;
        $observed=$o['observation_epoch'] ?? null;
        $expiry=$o['expires_epoch'] ?? null;
        if (!is_int($observed) || !is_int($expiry) || $expiry<$observed ||
            $expiry-$observed>1200 || $observed>time()+30 || $expiry<=time()) {
            $empty['state']='AUTOMATED_OBSERVATION_EXPIRED';
            return $empty;
        }
        foreach (array('legacy_hold_present','timer_enabled','original_v8_registry_exact','signed_readiness_admitted') as $key) {
            if (!isset($o[$key]) || !is_bool($o[$key])) return $empty;
        }
        $wp_states=array('SIGNED_LEASE_CURRENT','SIGNED_LEASE_EXPIRED_OR_UNADMITTED',
            'SIGNED_LEASE_ABSENT','WPCLI_READ_FAILED','WPCLI_OUTPUT_INVALID');
        $wp_state=$o['wordpress_readback_state'] ?? null;
        $wp_available=$o['wordpress_readback_available'] ?? null;
        if (!is_string($wp_state) || !in_array($wp_state,$wp_states,true) ||
            !is_bool($wp_available) ||
            $wp_available !== in_array($wp_state, array('SIGNED_LEASE_CURRENT',
                'SIGNED_LEASE_EXPIRED_OR_UNADMITTED','SIGNED_LEASE_ABSENT'), true) ||
            ($o['hold_evidence_consistency_verified'] ?? null) !== true ||
            (!$wp_available && $o['signed_readiness_admitted']===true)) {
            $empty['state']='AUTOMATED_OBSERVATION_EVIDENCE_INCOMPLETE';
            return $empty;
        }
        $states=array('LEGACY_AMBIGUOUS_WRITE_HOLD_NEEDS_GOVERNED_RECONCILIATION',
            'SIGNED_HOST_READINESS_ACCEPTED','NO_CURRENT_SIGNED_READINESS',
            'ORIGINAL_REGISTRY_CHANGED_HOLD','INCIDENT_EVIDENCE_INVALID_OR_UNAVAILABLE',
            'ORIGINAL305_WORDPRESS_READBACK_UNAVAILABLE');
        if (!in_array($o['state'] ?? '', $states, true)) return $empty;
        return array('state'=>$o['state'],'fresh'=>true,
            'legacy_hold_present'=>$o['legacy_hold_present'],
            'timer_enabled'=>$o['timer_enabled'],
            'original_v8_registry_exact'=>$o['original_v8_registry_exact'],
            'signed_readiness_admitted'=>$o['signed_readiness_admitted'],
            'wordpress_readback_available'=>$wp_available,
            'wordpress_readback_state'=>$wp_state,
            'hold_evidence_consistency_verified'=>true,
            'observation_epoch'=>$observed,
            'next_authorized_step'=>($o['legacy_hold_present'] ?
                'FIRSTPARTY_ORIGINAL_MASTER_INCIDENT_REVIEW_REQUIRED' :
                'NORMAL_FIRSTPARTY_AUTOMATIC_RENEWAL_POLICY'));
    }

    public static function status() {
        $obs=self::runtime_observation();
        $signed=false;
        if (class_exists('MAD4B_Original_Native_Operation_Providers_V1', false) &&
            method_exists('MAD4B_Original_Native_Operation_Providers_V1', 'legacy_admitted')) {
            $signed=MAD4B_Original_Native_Operation_Providers_V1::legacy_admitted(
                'host.source.release','host','host.source.ref.v1') === true;
        }
        return array('contract'=>'mad4b.original305-incident-review-rest.v2',
            'ability'=>self::ABILITY,'ticket_engine_ready'=>self::ticket_engine_available(),
            'observation'=>$obs,'wordpress_original_signed_readiness_admitted'=>$signed,
            'review_workflow'=>'AUTOMATIC_MONITORING_WITH_GOVERNED_EXCEPTION',
            'original_master_signed_approval_issued'=>false,
            'host_source_request_independently_verified_here'=>false,
            'incident_closed'=>false,'timer_enabled_by_this_route'=>false,
            'host_release_authorized'=>false);
    }

    /** Site Health is deliberately based on source-backed observations only.
     *  A stale or missing report never becomes a green readiness claim.
     */
    public static function operational_health() {
        $status=self::status();
        $obs=$status['observation'];
        if (($obs['fresh'] ?? false)!==true) {
            return array('status'=>'critical','code'=>'ORIGINAL305_OBSERVATION_STALE',
                'description'=>'The first-party Host diagnostic observation is missing or expired. The signed-readiness state cannot be trusted until automatic monitoring recovers.');
        }
        if (($obs['legacy_hold_present'] ?? null)===true) {
            return array('status'=>'critical','code'=>'ORIGINAL305_HISTORICAL_HOLD',
                'description'=>'A historical ambiguous signed write remains held. Automatic monitoring continues, but the original signing timer is disabled until the original incident authority approves reconciliation.');
        }
        if (($obs['wordpress_readback_available'] ?? null)!==true) {
            return array('status'=>'critical','code'=>'ORIGINAL305_WORDPRESS_READBACK_UNAVAILABLE',
                'description'=>'Original305 Host diagnostics are current, but independent WordPress signature readback was unavailable. The signed readiness state is not proven.');
        }
        if (($obs['original_v8_registry_exact'] ?? null)!==true) {
            return array('status'=>'critical','code'=>'ORIGINAL305_REGISTRY_MISMATCH',
                'description'=>'The enrolled original Host Provider Registry does not match the independently observed first-party source.');
        }
        if (($obs['timer_enabled'] ?? null)!==true ||
            ($status['wordpress_original_signed_readiness_admitted'] ?? null)!==true ||
            ($obs['signed_readiness_admitted'] ?? null)!==true ||
            ($obs['state'] ?? '')!=='SIGNED_HOST_READINESS_ACCEPTED') {
            return array('status'=>'critical','code'=>'ORIGINAL305_SIGNED_READINESS_NOT_ACTIVE',
                'description'=>'Signed readiness is not currently admitted by WordPress and independently observed as active with the original signing timer enabled.');
        }
        return array('status'=>'good','code'=>'ORIGINAL305_SIGNED_READINESS_HEALTHY',
            'description'=>'Original305 signed readiness is currently admitted, independently observed, and the original renewal timer is enabled. No Host release permission is implied.');
    }

    public static function site_health_tests($tests) {
        if (!is_array($tests)) return $tests;
        if (!isset($tests['direct']) || !is_array($tests['direct'])) $tests['direct']=array();
        $tests['direct']['mad4b_original305_readiness']=array(
            'label'=>'MAD4B Original305 signed readiness',
            'test'=>array(__CLASS__, 'site_health_result'));
        return $tests;
    }

    public static function site_health_result() {
        $health=self::operational_health();
        $url=admin_url('tools.php?page=mad4b-original305-incident-review');
        return array('label'=>'MAD4B Original305 signed readiness',
            'status'=>$health['status'],
            'badge'=>array('label'=>'Security','color'=>$health['status']==='good'?'blue':'red'),
            'description'=>'<p>'.esc_html($health['description']).'</p>',
            'actions'=>'<p><a href="'.esc_url($url).'">View automatic Original305 diagnostics</a></p>',
            'test'=>'mad4b_original305_readiness');
    }

    /** Show one bounded dashboard notice, not a site-wide alert on every page. */
    public static function dashboard_notice() {
        if (!self::can_read()) return;
        global $pagenow;
        if ($pagenow!=='index.php') return;
        $health=self::operational_health();
        if ($health['status']==='good') return;
        echo '<div class="notice notice-error"><p><strong>Original305: '
            .esc_html($health['code']).'</strong> — '.esc_html($health['description'])
            .' <a href="'.esc_url(admin_url('tools.php?page=mad4b-original305-incident-review'))
            .'">Automatic diagnostics</a></p></div>';
    }

    /** Authenticated same-origin admin status; never creates an approval or Host operation. */
    public static function ajax_readiness_status() {
        if (!self::can_read()) {
            wp_send_json_error(array('code'=>'mad4b_original305_admin_required',
                'message'=>'A WordPress administrator is required.'),403);
            return;
        }
        if (!check_ajax_referer('mad4b_original305_readiness_status','_ajax_nonce',false)) {
            wp_send_json_error(array('code'=>'mad4b_original305_nonce_invalid',
                'message'=>'Refresh the WordPress administration page.'),403);
            return;
        }
        nocache_headers();
        wp_send_json_success(self::status(),200);
    }

    public static function create_pending($request) {
        if (!self::ticket_engine_available()) return new WP_Error('mad4b_original305_ticket_engine_unavailable', 'Original WordPress approval engine unavailable.', array('status'=>503));
        $json = $request->get_json_params();
        if (!is_array($json) || !isset($json['incident_request']) || !isset($json['agent_public_id']) ||
            !is_string($json['agent_public_id']) || strlen($json['agent_public_id']) > 190) {
            return new WP_Error('mad4b_original305_review_input_invalid', 'Review request and enabled agent public ID are required.', array('status'=>400));
        }
        $plan = self::exact_review_request($json['incident_request']);
        if (is_wp_error($plan)) return $plan;
        $reason = isset($json['reason']) && is_string($json['reason']) ? trim($json['reason']) : 'Original305 historical signed-readiness incident review';
        $ttl = isset($json['ttl']) && is_int($json['ttl']) ? min(self::MAX_TTL, $json['ttl']) : 600;
        if ($ttl < 60) return new WP_Error('mad4b_original305_review_ttl_invalid', 'Review ticket TTL must be 60–900 seconds.', array('status'=>400));
        $agent = MAD4B_SCP_Agent_Registry::get_agent_by_public_id($json['agent_public_id']);
        if (!is_array($agent) || (string)($agent['status'] ?? '') !== 'enabled') {
            return new WP_Error('mad4b_original305_agent_not_enrolled', 'Only an enabled native MAD4B agent may request a review.', array('status'=>403));
        }
        $ticket = MAD4B_SCP_Approval_Tickets::create_pending(
            $json['agent_public_id'], self::SERVER, self::ABILITY, self::PROVIDER,
            self::target($plan), self::input($plan), self::TICKET_CLASS, $reason, $ttl);
        if (is_wp_error($ticket)) return $ticket;
        if (!is_array($ticket) || empty($ticket['ticket_id'])) return new WP_Error('mad4b_original305_ticket_not_confirmed', 'Original pending ticket readback missing.', array('status'=>503));
        $bind = MAD4B_SCP_Approval_Tickets::bind_ticket_to_current_candidate($ticket['ticket_id']);
        if (is_wp_error($bind)) return $bind;
        $fresh = MAD4B_SCP_Approval_Tickets::get($ticket['ticket_id']);
        if (!is_array($fresh) || $fresh['status'] !== 'pending' ||
            $fresh['ability_name'] !== self::ABILITY || $fresh['target_fingerprint'] !== self::target($plan)) {
            return new WP_Error('mad4b_original305_ticket_readback_changed', 'Exact original pending ticket readback failed.', array('status'=>409));
        }
        return array('state'=>'ORIGINAL305_PENDING_REVIEW_NOT_APPROVED',
            'request_sha256'=>$plan['request_sha256'],
            'ticket'=>self::safe_ticket($fresh),
            'master_approval_issued'=>false,'incident_closed'=>false,'host_release_authorized'=>false,
            'host_source_request_independently_verified_here'=>false);
    }

    public static function ticket_status($request) {
        if (!self::ticket_engine_available()) return new WP_Error('mad4b_original305_ticket_engine_unavailable', 'Original WordPress approval engine unavailable.', array('status'=>503));
        $ticket = MAD4B_SCP_Approval_Tickets::get((string) $request['ticket_id']);
        if (!is_array($ticket) || ($ticket['ability_name'] ?? '') !== self::ABILITY ||
            ($ticket['provider'] ?? '') !== self::PROVIDER) {
            return new WP_Error('mad4b_original305_ticket_not_found', 'Original305 incident ticket not found.', array('status'=>404));
        }
        return array('ticket'=>self::safe_ticket($ticket),
            'master_signed_envelope_issued'=>false,'incident_closed'=>false,
            'host_release_authorized'=>false);
    }

    public static function decide($request) {
        if (!self::ticket_engine_available()) return new WP_Error('mad4b_original305_ticket_engine_unavailable', 'Original WordPress approval engine unavailable.', array('status'=>503));
        $json=$request->get_json_params();
        if (!is_array($json) || !isset($json['incident_request'], $json['expected_payload_sha256'], $json['decision'])) {
            return new WP_Error('mad4b_original305_review_input_invalid', 'Exact incident plan, expected ticket digest and decision are required.', array('status'=>400));
        }
        $plan=self::exact_review_request($json['incident_request']);
        if (is_wp_error($plan)) return $plan;
        $decision=$json['decision'];
        if ($decision !== 'approve' && $decision !== 'reject') {
            return new WP_Error('mad4b_original305_decision_invalid', 'Decision must be approve or reject.', array('status'=>400));
        }
        $ticket=MAD4B_SCP_Approval_Tickets::get((string)$request['ticket_id']);
        if (!is_array($ticket) || $ticket['status'] !== 'pending' ||
            $ticket['ability_name'] !== self::ABILITY || $ticket['provider'] !== self::PROVIDER ||
            $ticket['server_id'] !== self::SERVER || $ticket['ticket_class'] !== self::TICKET_CLASS ||
            $ticket['target_fingerprint'] !== self::target($plan)) {
            return new WP_Error('mad4b_original305_ticket_binding_changed', 'Original review ticket does not match the incident request.', array('status'=>409));
        }
        $agent=MAD4B_SCP_Agent_Registry::get_agent_by_id((int)$ticket['agent_id']);
        if (!is_array($agent) || $agent['status'] !== 'enabled') {
            return new WP_Error('mad4b_original305_agent_not_enrolled', 'Review agent is no longer enabled.', array('status'=>403));
        }
        $fresh_hash=MAD4B_SCP_Approval_Tickets::canonical_payload_hash($agent['public_id'],
            self::SERVER,self::ABILITY,self::PROVIDER,self::target($plan),self::input($plan),self::TICKET_CLASS);
        if (is_wp_error($fresh_hash)) return $fresh_hash;
        $expected=$json['expected_payload_sha256'];
        if (!is_string($expected) || !preg_match('/^[a-f0-9]{64}$/D',$expected) ||
            !hash_equals($expected,(string)$ticket['payload_sha256']) ||
            !hash_equals((string)$fresh_hash,$expected)) {
            return new WP_Error('mad4b_original305_ticket_digest_changed', 'Exact original approval ticket digest changed.', array('status'=>409));
        }
        $decided=MAD4B_SCP_Approval_Tickets::decide_pending((string)$request['ticket_id'],
            $decision,$expected,array('contract'=>'mad4b.original305-native-incident-review.v1'));
        if (is_wp_error($decided)) return $decided;
        if (!is_array($decided) || $decided['status'] !== ($decision === 'approve' ? 'approved' : 'revoked')) {
            return new WP_Error('mad4b_original305_decision_not_confirmed', 'Original approval decision readback missing.', array('status'=>503));
        }
        return array('state'=>'ORIGINAL305_NATIVE_TICKET_DECIDED_NO_MASTER_SIGNATURE',
            'request_sha256'=>$plan['request_sha256'],
            'ticket'=>self::safe_ticket($decided),
            'signed_master_envelope_issued'=>false,'incident_closed'=>false,
            'host_release_authorized'=>false,
            'host_source_request_independently_verified_here'=>false);
    }

    public static function register_admin_page() {
        add_management_page('Original305 automatic readiness', 'Original305 readiness',
            'manage_options', 'mad4b-original305-incident-review', array(__CLASS__, 'render_admin'));
    }
    public static function render_admin() {
        if (!self::can_read()) wp_die('Administrator required');
        $nonce=wp_create_nonce('mad4b_original305_readiness_status');
        $url=add_query_arg(array(
            'action'=>'mad4b_original305_readiness_status',
            '_ajax_nonce'=>$nonce,
        ),admin_url('admin-ajax.php'));
        echo '<div class="wrap"><h1>Original305 — Automatic Readiness</h1>';
        echo '<p>Read-only automatic monitoring. No JSON, Agent ID, ticket digest, or manual operational input is required.</p>';
        echo '<table class="widefat striped" style="max-width:850px"><tbody>';
        foreach (array('state'=>'Readiness status','signed'=>'Verified WordPress signed lease',
            'registry'=>'Original Registry V8','hold'=>'Historical signed-write HOLD',
            'holdproof'=>'Incident HOLD file proof','wpread'=>'WordPress independent readback',
            'timer'=>'Original renewal timer','observed'=>'Last Host observation') as $id=>$label) {
            echo '<tr><th style="width:270px">'.esc_html($label).'</th><td id="mad4b-o305-'.esc_attr($id).'">Checking…</td></tr>';
        }
        echo '</tbody></table><p id="mad4b-o305-message" role="status" aria-live="polite">Automatic status check in progress.</p>';
        echo '<p><strong>Authority boundary:</strong> confirmed transient delivery errors recover through the original signed-readiness supervisor. An unverified historical HOLD cannot be automatically approved, signed or removed by this dashboard. Independent original Master authority is required for that exception.</p></div>';
        $script=<<<'JS'
(function(){'use strict';
const endpoint=__URL__;
const put=(field,value)=>{const el=document.getElementById('mad4b-o305-'+field);if(el)el.textContent=String(value);};
const note=document.getElementById('mad4b-o305-message');
const booleanText=(v,yes,no)=>v===true?yes:v===false?no:'Not yet verified';
let busy=false;
async function refresh(){if(busy || document.hidden)return;busy=true;
try{const response=await fetch(endpoint,{method:'GET',credentials:'same-origin',cache:'no-store',headers:{'Accept':'application/json'}});
if(!response.ok)throw new Error('Authenticated status unavailable ('+response.status+')');
const envelope=await response.json();
if(!envelope||envelope.success!==true||!envelope.data)throw new Error('Invalid authenticated status response');
const data=envelope.data,state=data.observation||{};
put('state',state.fresh?state.state:'Waiting for fresh Host diagnostics');
put('signed',booleanText(data.wordpress_original_signed_readiness_admitted,'Accepted','Not accepted'));
put('registry',booleanText(state.original_v8_registry_exact,'Exact first-party V8 verified','Mismatch — hold'));
put('hold',booleanText(state.legacy_hold_present,'Active — requires original incident authority','Not present'));
put('holdproof',booleanText(state.hold_evidence_consistency_verified,'Source bytes verified','Evidence missing or inconsistent'));
put('wpread',state.fresh&&state.wordpress_readback_available===true?state.wordpress_readback_state:
    state.fresh?'Independent WordPress readback unavailable':'Waiting for fresh Host observation');
put('timer',booleanText(state.timer_enabled,'Enabled','Disabled'));
put('observed',state.fresh && Number.isInteger(state.observation_epoch)?new Date(state.observation_epoch*1000).toLocaleString():'No fresh Host observation');
if(note)note.textContent=state.legacy_hold_present===true?'Historical safety hold remains in effect. Automatic diagnostics continue; no approval is forged.':
(state.fresh?'Automatic observation is current. Signed renewals depend on the original provider policy.':'Awaiting automatic Host observation; no user action needed for diagnostics.');
}catch(e){if(note)note.textContent='Automatic status check unavailable: '+e.message;}
finally{busy=false;}}
refresh();window.setInterval(refresh,60000);
})();
JS;
        $script=str_replace('__URL__',wp_json_encode($url),$script);
        wp_enqueue_script('wp-api-fetch');
        wp_add_inline_script('wp-api-fetch',$script,'after');
    }

}
