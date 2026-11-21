<?php
/**
 * MAD4B Original305 v3 — one WordPress Admin / REST review interface.
 *
 * Source-only until selective, preimage-backed Staging installation.
 * No signer lives here. A WordPress session can REQUEST an independent Owner
 * step-up but cannot create a signed Original305/312/313 authority document.
 */
defined('ABSPATH') || exit;

if (!class_exists('MAD4B_Host_V3_Owner_Console', false)) {
final class MAD4B_Host_V3_Owner_Console {
    const API = 'mad4b-host-v3/v1';
    const PAGE = 'mad4b-host-v3-owner-review';
    const SITE = 'e5e64bca-ed62-48d2-b2b3-dff693cd702a';
    const ORIGIN = 'https://tours.dev.mad4b.com';
    const DEPLOYMENT_BINDING = '32749c43cd77b0b070d6987815a627e9221df74ea859ff4580b23f175ccd05d0';
    const TRUST_SHA = '325b954c9c1059ad37371b170c2cc82c1f64f3bce4bf851c45e5d9bea610a538';
    const LOADER_SHA = '3698c03ec6f6ec45b2849cc9a59377f0edc70f2f3a9b8303d19e2cf7d56ac339';
    const READER_SHA = 'fc865e27306cf5c40a184e847b7bd863fd93beb25a5602c41cb721f64bc024e6';
    const SOURCE_HEAD = '0f25e3337c8fc921aaa59b9696475dc01d98220d';
    const HISTORY_EPOCH = 1795264472;
    const HISTORY_ARCHIVE_SHA = 'aa31a841078675a918914d7f237f2530c01cb268d0ee90940f3551bfbefe1780';
    const HISTORY_INDEX_SHA = 'b5a35ba0f2879cd1c002f8b7eaf260d8a34a4cd541ae8aae1c7199e0092c5f71';
    const CONFIRM = 'REQUEST EXACT V3 OWNER STEP-UP';
    const REQUEST_CONTRACT = 'mad4b.original305.owner-v3-browser-stepup-request.v1';

    public static function boot() {
        add_action('rest_api_init', array(__CLASS__, 'register_rest'));
        add_action('admin_menu', array(__CLASS__, 'register_admin'), 99);
    }
    private static function deny($code, $message, $http = 403) {
        return new WP_Error($code, $message, array('status'=>$http));
    }
    public static function site_binding_valid() {
        if (!function_exists('home_url') ||
            rtrim((string)home_url('/'), '/') !== self::ORIGIN ||
            !function_exists('get_option') ||
            !function_exists('wp_get_environment_type') ||
            wp_get_environment_type() !== 'staging') return false;
        $profile = get_option('mad4b_scp_site_profile_v2', false);
        if (!is_array($profile) ||
            ($profile['contract'] ?? '') !== 'mad4b.site-profile.v2' ||
            ($profile['version'] ?? null) !== 2 ||
            ($profile['environment'] ?? '') !== 'staging' ||
            ($profile['site_uuid'] ?? '') !== self::SITE ||
            ($profile['canonical_origin'] ?? '') !== self::ORIGIN ||
            ($profile['deployment_binding_digest'] ?? '') !== self::DEPLOYMENT_BINDING) return false;
        if (!defined('MAD4B_HOST_V3_PINNED_BINDINGS') ||
            !defined('MAD4B_HOST_V3_ORIGINAL312_PUBLIC_B64') ||
            !defined('MAD4B_HOST_V3_ORIGINAL313_PUBLIC_B64') ||
            (defined('MAD4B_HOST_V3_ROOT_TRUST_COLLISION') &&
             constant('MAD4B_HOST_V3_ROOT_TRUST_COLLISION') === true)) return false;
        $pins = MAD4B_HOST_V3_PINNED_BINDINGS;
        $exact = is_array($pins) && isset($pins['mad4b-central:staging'])
                 ? $pins['mad4b-central:staging'] : null;
        if (!is_array($exact) ||
            ($exact['company'] ?? '') !== 'mad4b-central' ||
            ($exact['environment'] ?? '') !== 'staging' ||
            ($exact['site_uuid'] ?? '') !== self::SITE ||
            ($exact['origin'] ?? '') !== self::ORIGIN ||
            ($exact['target_host_id'] ?? '') !== 'hostinger-primary' ||
            !defined('MAD4B_HOST_V3_CENTRAL_DEPLOYMENT_BINDING_SHA256') ||
            MAD4B_HOST_V3_CENTRAL_DEPLOYMENT_BINDING_SHA256 !== self::DEPLOYMENT_BINDING
        ) return false;
        foreach (array(
            '000-mad4b-host-v3-root-trust.php' => self::TRUST_SHA,
            '410-mad4b-original318-v3-readback.php' => self::LOADER_SHA,
            'class-mad4b-original318-host-v3-reader.php' => self::READER_SHA,
        ) as $relative=>$sha) {
            $path = WPMU_PLUGIN_DIR . '/' . $relative;
            if (is_link($path) || !is_file($path) || !is_readable($path) ||
                !hash_equals($sha, (string)hash_file('sha256', $path))) return false;
        }
        return true;
    }
    private static function can_view() {
        return current_user_can('manage_options') ||
               current_user_can('mad4b_manage_company_operations');
    }
    public static function view_permission() {
        if (!is_user_logged_in() || !self::can_view())
            return self::deny('mad4b_v3_admin_required','Host Authority review permission required.',403);
        if (!self::site_binding_valid())
            return self::deny('mad4b_v3_site_not_enrolled','This exact Staging site is not verified.',409);
        return true;
    }
    private static function browser_owner_session() {
        if (!current_user_can('manage_options') ||
            !current_user_can('activate_plugins') ||
            !is_user_logged_in()) return false;
        // A bearer token or application password is not a human Owner step-up.
        $cookie = defined('LOGGED_IN_COOKIE') && isset($_COOKIE[LOGGED_IN_COOKIE])
                && is_string($_COOKIE[LOGGED_IN_COOKIE])
                ? $_COOKIE[LOGGED_IN_COOKIE] : '';
        $session = function_exists('wp_get_session_token') ?
                   (string)wp_get_session_token() : '';
        $nonce = isset($_SERVER['HTTP_X_WP_NONCE']) &&
                 is_string($_SERVER['HTTP_X_WP_NONCE']) ?
                 $_SERVER['HTTP_X_WP_NONCE'] : '';
        return $cookie !== '' && $session !== '' && $nonce !== '' &&
               (int)wp_validate_auth_cookie($cookie, 'logged_in') ===
               (int)get_current_user_id() &&
               (bool)wp_verify_nonce($nonce, 'wp_rest');
    }
    public static function register_rest() {
        register_rest_route(self::API, '/status', array(
            'methods'=>WP_REST_Server::READABLE,
            'permission_callback'=>array(__CLASS__,'view_permission'),
            'callback'=>array(__CLASS__,'status_endpoint')
        ));
        register_rest_route(self::API, '/history', array(
            'methods'=>WP_REST_Server::READABLE,
            'permission_callback'=>array(__CLASS__,'view_permission'),
            'callback'=>array(__CLASS__,'history_endpoint')
        ));
        register_rest_route(self::API, '/review-plan', array(
            'methods'=>WP_REST_Server::READABLE,
            'permission_callback'=>array(__CLASS__,'view_permission'),
            'callback'=>array(__CLASS__,'plan_endpoint')
        ));
        register_rest_route(self::API, '/review-requests', array(
            'methods'=>WP_REST_Server::CREATABLE,
            'permission_callback'=>array(__CLASS__,'view_permission'),
            'callback'=>array(__CLASS__,'request_endpoint'),
            'args'=>array(
                'request_id'=>array('type'=>'string','required'=>true),
                'expected_plan_sha256'=>array('type'=>'string','required'=>true),
                'confirmation'=>array('type'=>'string','required'=>true),
            ),
        ));
    }
    private static function reader() {
        if (!class_exists('MAD4B_Original318_Host_V3_Readback',false))
            return array('state'=>'UNAVAILABLE','write_authorized'=>false,
                'readback_verified'=>false,'signature_verified'=>false);
        $r = MAD4B_Original318_Host_V3_Readback::current('mad4b-central','staging');
        if (!is_array($r)) return array('state'=>'INVALID','write_authorized'=>false);
        return array(
            'state'=>substr((string)($r['state'] ?? 'INVALID'),0,60),
            'reason'=>substr((string)($r['reason'] ?? ''),0,100),
            'readback_verified'=>($r['readback_verified'] ?? false) === true,
            'signature_verified'=>($r['signature_verified'] ?? false) === true,
            'write_authorized'=>($r['write_authorized'] ?? false) === true
        );
    }
    private static function record_key($digest) {
        return 'mad4b_host_v3_review_' . substr($digest,0,40);
    }
    public static function status_data() {
        $r=self::reader();
        return array(
            'contract'=>'mad4b.original305.owner-v3-console-status.v1',
            'company'=>'mad4b-central','environment'=>'staging',
            'site_uuid'=>self::SITE,'origin'=>self::ORIGIN,
            'site_identity_verified'=>self::site_binding_valid(),
            'current'=>$r,
            'original305_public_role'=>'ROOT_PREFLIGHT_REQUIRED',
            'original312_public_role'=>'host_resource_312_pinned',
            'original313_public_role'=>'independent_313_pinned',
            'original313_complete_predecessor'=>'UNVERIFIED_IN_WORDPRESS',
            'owner_stepup_signer_enrollment'=>'NOT_VERIFIED_IN_WORDPRESS',
            'owner_stepup_signed'=>false,
            'signed_v3_enrollment_issued'=>$r['signature_verified'],
            'standing_write_enabled'=>$r['write_authorized'],
            'review_action'=>'REQUEST_INDEPENDENT_OWNER_STEPUP',
            'write_authorization_from_this_console'=>false,
            'production_allowed'=>false,
        );
    }
    public static function plan_data() {
        $status=self::status_data();
        $core=array(
          'contract'=>'mad4b.original305.owner-v3-review-plan.v1',
          'company'=>'mad4b-central',
          'environment'=>'staging',
          'site_uuid'=>self::SITE,'origin'=>self::ORIGIN,
          'target_host_id'=>'hostinger-primary',
          'site_deployment_digest'=>self::DEPLOYMENT_BINDING,
          'root_public_verifier_sha256'=>self::TRUST_SHA,
          'original318_php_sha256'=>self::LOADER_SHA,
          'source_head'=>self::SOURCE_HEAD,
          'original318_state'=>$status['current']['state'],
          'original313_predecessor'=>'UNVERIFIED',
          'owner_stepup_signer_enrollment'=>'NOT_VERIFIED_IN_WORDPRESS',
          'action'=>'REQUEST_INDEPENDENT_OWNER_STEPUP',
          'write_authorized'=>false,
        );
        $plan_sha=hash('sha256',wp_json_encode($core,JSON_UNESCAPED_SLASHES));
        $existing=get_option(self::record_key($plan_sha),false);
        return array(
          'contract'=>'mad4b.original305.owner-v3-review-plan-result.v1',
          'plan'=>$core,
          'plan_sha256'=>$plan_sha,
          'can_request_review'=>self::site_binding_valid() && self::browser_owner_session() && !$status['current']['write_authorized'],
          'can_issue_signed_approval'=>false,
          'can_enable_persistent_write'=>false,
          'exact_confirmation'=>self::CONFIRM,
          'expires_review_request_after_seconds'=>900,
          'existing_request'=>is_array($existing) ? array(
             'request_id'=>(string)($existing['request_id'] ?? ''),
             'state'=>(string)($existing['state'] ?? ''),
             'created_epoch'=>(int)($existing['created_epoch'] ?? 0),
          ) : null,
          'required_before_issuance'=>array(
             'OWNER_STEPUP_REGISTERED_FIRST_PARTY_SIGNER',
             'FRESH_SIGNED_OWNER_STEPUP',
             'ORIGINAL313_COMPLETE_MONOTONIC_PREDECESSOR',
             'ORIGINAL305_SIGNED_APPROVAL',
             'ORIGINAL312_SIGNED_V3_ENROLLMENT',
             'ORIGINAL318_AND_ORIGINAL313_INDEPENDENT_READBACK',
          ),
        );
    }
    private static function response($body) {
        $r=rest_ensure_response($body);
        $r->header('Cache-Control','private, no-store');
        return $r;
    }
    public static function status_endpoint($request) { return self::response(self::status_data()); }
    public static function history_data() {
        return array(
          'contract'=>'mad4b.original313.host-v134-history-rest-read.v1',
          'scope'=>'mad4b-central:staging',
          'snapshot_epoch'=>self::HISTORY_EPOCH,
          'snapshot_age_seconds'=>max(0,time()-self::HISTORY_EPOCH),
          'snapshot_is_live'=>false,
          'archive_sha256'=>self::HISTORY_ARCHIVE_SHA,
          'firstparty_root_index_sha256'=>self::HISTORY_INDEX_SHA,
          'root_archival_subdirectories'=>11,
          'root_archive_documents'=>13,
          'historical_verified_signed_chains'=>1,
          'historical_verified_scope'=>'all-royal-egypt:staging',
          'toursdev_record_in_scanned_archive'=>false,
          'root_predecessor_ledger_across_generations_complete'=>false,
          'current_original313_v3_predecessor'=>'NOT_ESTABLISHED',
          'signed_predecessor_generated'=>false,
          'write_authorized'=>false,
          'next_action'=>'INDEPENDENT_COMPLETE_ROOT_PREDECESSOR_PROOF',
        );
    }
    public static function history_endpoint($request) { return self::response(self::history_data()); }
    public static function plan_endpoint($request) { return self::response(self::plan_data()); }

    public static function validate_request_for_test($payload, $plan, $browser, $now) {
        if (!$browser)
            return self::deny('mad4b_v3_browser_stepup_required',
                'Real administrator browser cookie, session and REST nonce required.',403);
        if (!is_array($payload))
            return self::deny('mad4b_v3_review_request_shape','Exact request fields required.',422);
        $fields=array_keys($payload);
        sort($fields,SORT_STRING);
        if ($fields!==array('confirmation','expected_plan_sha256','request_id'))
            return self::deny('mad4b_v3_review_request_shape','Exact request fields required.',422);
        $id=$payload['request_id'];
        $expected=$payload['expected_plan_sha256'];
        $confirmation=$payload['confirmation'];
        if (!is_string($id) || preg_match('/^[a-f0-9]{32}$/D',$id)!==1 ||
            !is_string($expected) || preg_match('/^[a-f0-9]{64}$/D',$expected)!==1 ||
            !hash_equals((string)$plan['plan_sha256'],$expected) ||
            !is_string($confirmation) || !hash_equals(self::CONFIRM,$confirmation))
            return self::deny('mad4b_v3_review_precondition_changed',
                'The exact request digest, ID or confirmation does not match.',409);
        if (empty($plan['can_request_review']) || !empty($plan['can_issue_signed_approval']) ||
            !empty($plan['can_enable_persistent_write']))
            return self::deny('mad4b_v3_review_not_eligible',
                'Original v3 prerequisite evidence is unavailable.',409);
        return array(
            'contract'=>self::REQUEST_CONTRACT,
            'state'=>'PENDING_INDEPENDENT_OWNER_STEPUP',
            'request_id'=>$id,
            'plan_sha256'=>$expected,
            'company'=>'mad4b-central','environment'=>'staging',
            'site_uuid'=>self::SITE,'source_head'=>self::SOURCE_HEAD,
            'requested_by_user_id'=>(int)get_current_user_id(),
            'created_epoch'=>(int)$now,
            'expires_epoch'=>(int)$now+900,
            'original305_approval_signed'=>false,
            'original312_enrollment_signed'=>false,
            'original313_witness_signed'=>false,
            'host_write_authorized'=>false,
        );
    }
    public static function request_endpoint($request) {
        if (!self::browser_owner_session())
            return self::deny('mad4b_v3_browser_stepup_required',
                'Verified browser administrator session required.',403);
        $payload=$request->get_json_params();
        $plan=self::plan_data();
        $record=self::validate_request_for_test($payload,$plan,true,time());
        if (is_wp_error($record)) return $record;
        $name=self::record_key($plan['plan_sha256']);
        // Atomic unique option INSERT; re-read immediately, no retry of
        // ambiguous non-idempotent writes and no signature inference.
        if (!add_option($name,$record,'','no')) {
            $saved=get_option($name,false);
            if (is_array($saved) && ($saved['request_id'] ?? '')===$record['request_id'] &&
                ($saved['plan_sha256'] ?? '')===$record['plan_sha256'] &&
                ($saved['requested_by_user_id'] ?? null)===$record['requested_by_user_id'])
                return self::response(array('result'=>'ALREADY_RECORDED','review'=>$saved));
            return self::deny('mad4b_v3_review_exists',
                'Exact plan has a different review request; reconcile before retry.',409);
        }
        $readback=get_option($name,false);
        if (!is_array($readback) || $readback!==$record)
            return self::deny('mad4b_v3_review_readback_unverified',
                'Review write outcome requires independent operator reconciliation.',503);
        return self::response(array('result'=>'RECORDED_REQUEST_ONLY','review'=>$record,
                                   'owner_stepup_signed'=>false,'write_authorized'=>false));
    }
    public static function register_admin() {
        if (!self::can_view() || !self::site_binding_valid()) return;
        // Tools is a reliable core menu even when Control Plane uses a
        // separate early-load optimization on the current site.
        $parent = isset($GLOBALS['admin_page_hooks']) &&
                  isset($GLOBALS['admin_page_hooks']['mad4b-control-plane'])
                  ? 'mad4b-control-plane' : 'tools.php';
        add_submenu_page($parent,'Original Host Authority v3',
            'Host Authority v3','read',self::PAGE,array(__CLASS__,'render_admin'));
    }
    public static function render_admin() {
        if (!self::can_view() || !self::site_binding_valid())
            wp_die(esc_html__('You do not have permission to view this page.'));
        $nonce=wp_create_nonce('wp_rest');
        $base=rest_url(self::API);
        echo '<div class="wrap mad4b-host-v3"><h1>Original Host Authority v3</h1>';
        echo '<p>Independent Owner review, Original313 history and persistent Write readiness. '
            .'The administrator interface and REST API use the same verified read model.</p>';
        echo '<style>.mad4b-host-v3{max-width:1060px}.mad4b-host-v3 .v3-layout{display:grid;grid-template-columns:minmax(0,1.45fr) minmax(290px,1fr);gap:16px}.mad4b-host-v3 .v3-card{background:#fff;border:1px solid #c3c4c7;padding:20px;margin:12px 0}.mad4b-host-v3 .v3-row{display:flex;justify-content:space-between;gap:14px;padding:10px 0;border-bottom:1px solid #eee}.mad4b-host-v3 .v3-row:last-child{border:0}.mad4b-host-v3 .v3-state{font-weight:600}.mad4b-host-v3 .v3-help{color:#50575e;font-size:13px}.mad4b-host-v3 code{overflow-wrap:anywhere}.mad4b-host-v3 .v3-busy{opacity:.65}.mad4b-host-v3 .notice{margin:8px 0}.mad4b-host-v3 .v3-card h2{margin-top:0}@media(max-width:782px){.mad4b-host-v3 .v3-layout{grid-template-columns:1fr}}</style>';
        echo '<div class="v3-layout"><div>';
        echo '<section class="v3-card" aria-labelledby="v3-identity"><h2 id="v3-identity">Current host identity</h2>';
        echo '<p><strong>Tours.dev — Staging</strong><br><code>'.esc_html(self::SITE).'</code></p>';
        echo '<div id="v3-status" role="status" aria-live="polite">Reading independently verified site state…</div></section>';
        echo '<section class="v3-card" aria-labelledby="v3-review"><h2 id="v3-review">Owner step-up request</h2>';
        echo '<p>A browser request records intent only. Approval must be independently signed by the registered Owner step-up signer and checked against Original313.</p>';
        echo '<label for="v3-confirm"><strong>Exact confirmation</strong></label>';
        echo '<input type="text" class="regular-text" id="v3-confirm" autocomplete="off" spellcheck="false" placeholder="'.esc_attr(self::CONFIRM).'" aria-describedby="v3-confirm-help">';
        echo '<p id="v3-confirm-help" class="v3-help">This does not grant Write or sign an Owner approval.</p>';
        echo '<p><button class="button button-primary" id="v3-submit" disabled>Request independent Owner review</button></p>';
        echo '<div id="v3-result" role="status" aria-live="polite"></div></section>';
        echo '</div><div>';
        echo '<section class="v3-card" aria-labelledby="v3-steps"><h2 id="v3-steps">Activation gates</h2><ol>';
        echo '<li>Owner identity and independent step-up</li><li>Original313 complete predecessor ledger</li><li>Original305 signed approval</li><li>Original312 v3 enrollment</li><li>Original318 and Original313 readback</li><li>Persistent Write standing entitlement with exact-operation grant</li>';
        echo '</ol><details><summary>Why is the final Write step separate?</summary><p>A standing entitlement is revocable. Every deployment still requires an exact signed operation, backup, and verified rollback. Production requires separate site authority.</p></details></section>';
        echo '<section class="v3-card" aria-labelledby="v3-history-title"><h2 id="v3-history-title">Original313 history</h2><div id="v3-history" aria-live="polite">Loading archival audit…</div><details><summary>Evidence and responsibility</summary><p>Historical Root signature verification shows recorded documents, not a signed proof of the latest predecessor. The independent Root authority must certify complete revision history.</p></details></section>';
        echo '<section class="v3-card"><h2>REST contract</h2><p class="v3-help">Authenticated GET status, history and plan. Browser-only POST request; no direct signing operation.</p>';
        echo '<code>'.esc_html(self::API).'</code></section></div></div></div>';
        $js = <<<'JS'
(function(){
"use strict";
const base=__BASE__, nonce=__NONCE__;
const status=document.getElementById("v3-status"), submit=document.getElementById("v3-submit"),
      confirm=document.getElementById("v3-confirm"), result=document.getElementById("v3-result"),
      history=document.getElementById("v3-history");
let plan=null;
function out(node,txt){node.textContent=txt;}
async function call(path,options={}){
  const res=await fetch(base+path,{credentials:"same-origin",cache:"no-store",
    headers:{"X-WP-Nonce":nonce,"Accept":"application/json",...(options.body?{"Content-Type":"application/json"}:{})},
    ...options});
  let data;try{data=await res.json();}catch(e){throw Error("Invalid JSON response");}
  if(!res.ok)throw Error((data&&data.message)||"Request failed ("+res.status+")");
  return data;
}
function row(label,value){
 const r=document.createElement("div");r.className="v3-row";
 const a=document.createElement("span");a.textContent=label;
 const b=document.createElement("span");b.className="v3-state";b.textContent=value;
 r.append(a,b);status.append(r);
}
async function init(){
 try{
   const [s,p,h]=await Promise.all([call("/status"),call("/review-plan"),call("/history")]);
   out(history,"Verified historical chains: "+h.historical_verified_signed_chains+
      " · Documents: "+h.root_archive_documents+
      " · Tours.dev predecessor: "+h.current_original313_v3_predecessor+
      " (archival snapshot, not current signed authority).");
   plan=p;
   status.textContent="";
   row("Original318 v3",s.current.state);
   row("Original312 public key","Host Resource 312");
   row("Owner step-up",s.owner_stepup_signer_enrollment);
   row("Original313 predecessor",s.original313_complete_predecessor);
   row("Persistent Write",s.standing_write_enabled?"AUTHORIZED":"NOT AUTHORIZED");
   if(p.existing_request)out(result,"Previous review request: "+p.existing_request.state);
   submit.disabled=!p.can_request_review;
 }catch(e){out(status,e.message);submit.disabled=true;}
}
function newId(){
 const bytes=new Uint8Array(16);crypto.getRandomValues(bytes);
 return Array.from(bytes,x=>x.toString(16).padStart(2,"0")).join("");
}
submit.addEventListener("click",async function(){
 if(!plan)return;
 if(confirm.value!==plan.exact_confirmation){out(result,"Exact confirmation does not match.");return;}
 submit.disabled=true;out(result,"Recording a review request…");
 try{
   const record=await call("/review-requests",{method:"POST",body:JSON.stringify({
      request_id:newId(),expected_plan_sha256:plan.plan_sha256,
      confirmation:confirm.value
   })});
   out(result,record.result+": "+record.review.request_id+". No signed approval or Host Write issued.");
   await init();
 }catch(e){out(result,e.message);} finally{submit.disabled=!plan||!plan.can_request_review;}
});
init();
})();
JS;
        $js=str_replace(array('__BASE__','__NONCE__'),
            array(wp_json_encode($base,JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT),wp_json_encode($nonce,JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT)),$js);
        echo '<script>'. $js .'</script>'; // Exact static source + JSON-encoded WP values.
    }
}
MAD4B_Host_V3_Owner_Console::boot();
}
