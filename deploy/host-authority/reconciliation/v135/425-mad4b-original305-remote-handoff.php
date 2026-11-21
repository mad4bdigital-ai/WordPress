<?php
/**
 * MAD4B Original305 Remote Owner Handoff v135.
 *
 * Additive to verified v134 Admin + REST. Browser owner review can be
 * initiated remotely. Independently signed consent can later be received
 * ONLY against an Original313-signed exact preapproval offer and an enrolled
 * separate Owner step-up public key. Nothing here signs on behalf of Owner,
 * issues Original305/312/313 enrollment or grants Host Write.
 */
defined('ABSPATH') || exit;
if (!class_exists('MAD4B_Host_V3_Remote_Handoff', false)) {
final class MAD4B_Host_V3_Remote_Handoff {
    const API = 'mad4b-host-v3/v1';
    const CONTRACT = 'mad4b.original305.remote-review-request.v1';
    const HANDOFF = 'mad4b.original313.owner-remote-handoff.v1';
    const HANDOFF_DOMAIN = "MAD4B_ORIGINAL313_OWNER_REMOTE_HANDOFF_V1\n";
    const INTENT = 'mad4b.original305.owner-v3-exact-stepup-consent.v1';
    const INTENT_DOMAIN = "MAD4B_ORIGINAL305_OWNER_V3_EXACT_STEPUP\n";
    const PHASE = 'AWAITING_INDEPENDENT_ORIGINAL313_PREDECESSOR';
    const OFFER_OPTION = 'mad4b_original313_v3_signed_owner_offer_v1';
    const CONFIRM = 'REQUEST REMOTE V3 OWNER REVIEW';
    const TTL = 1800;
    const HANDOFF_FIELDS = array(
        'contract','company','environment','site_uuid','target_host_id',
        'request_id','plan_sha256','predecessor_sha256','enrollment_sha256',
        'source_commit_sha','owner_stepup_public_b64','owner_stepup_enrollment_sha256',
        'original305_issuer_public_b64','original305_issuer_enrollment_sha256',
        'identity_authority_confirmed','monotonic_predecessor_verified',
        'issued_epoch','expires_epoch','state'
    );
    const INTENT_FIELDS = array(
        'contract','company','environment','site_uuid','target_host_id',
        'enrollment_sha256','predecessor_sha256','source_commit_sha',
        'operation_id','request_id','actor_digest_sha256','intent_nonce',
        'decision','issued_epoch','expires_epoch'
    );

    private static function error($code,$msg,$status=409) {
        return new WP_Error($code,$msg,array('status'=>$status));
    }
    public static function boot() {
        add_action('rest_api_init',array(__CLASS__,'routes'));
        add_action('admin_footer',array(__CLASS__,'admin_panel'));
    }
    public static function permission() {
        if (!class_exists('MAD4B_Host_V3_Owner_Console',false))
            return self::error('mad4b_remote_base_missing','Original318 v3 review module is missing.',503);
        return MAD4B_Host_V3_Owner_Console::view_permission();
    }
    private static function owner_browser() {
        if (!is_user_logged_in() || !current_user_can('manage_options') ||
            !current_user_can('activate_plugins') || !defined('LOGGED_IN_COOKIE') ||
            !isset($_COOKIE[LOGGED_IN_COOKIE]) ||
            !is_string($_COOKIE[LOGGED_IN_COOKIE]) ||
            !function_exists('wp_get_session_token') || wp_get_session_token()==='') return false;
        $cookie=$_COOKIE[LOGGED_IN_COOKIE];
        $nonce=$_SERVER['HTTP_X_WP_NONCE'] ?? '';
        return is_string($nonce) && $nonce!=='' &&
            (int)wp_validate_auth_cookie($cookie,'logged_in')===(int)get_current_user_id() &&
            (bool)wp_verify_nonce($nonce,'wp_rest');
    }
    public static function routes() {
        foreach (array(
            '/remote-status'=>array('GET','remote_status'),
            '/remote-request'=>array('GET','remote_request'),
            '/remote-requests'=>array('POST','create_request'),
            '/remote-signed-proof'=>array('POST','receive_signed_proof')
        ) as $path=>$method) {
            register_rest_route(self::API,$path,array(
                'methods'=>$method[0]==='GET'?WP_REST_Server::READABLE:WP_REST_Server::CREATABLE,
                'permission_callback'=>array(__CLASS__,'permission'),
                'callback'=>array(__CLASS__,$method[1])
            ));
        }
    }
    private static function plan() {
        return MAD4B_Host_V3_Owner_Console::plan_data();
    }
    private static function req_key($id) {return 'mad4b_v3_remote_req_'.$id;}
    private static function proof_key($id) {return 'mad4b_v3_remote_proof_'.$id;}
    private static function response($obj) {
        $r=rest_ensure_response($obj);
        $r->header('Cache-Control','private, no-store, max-age=0');
        return $r;
    }
    private static function exact_keys($data,$expected) {
        if (!is_array($data)) return false;
        $actual=array_keys($data);sort($actual,SORT_STRING);
        $exp=$expected;sort($exp,SORT_STRING);
        return $actual===$exp;
    }
    private static function hex($x,$n) {
        return is_string($x) && preg_match('/^[a-f0-9]{'.$n.'}$/D',$x)===1;
    }
    private static function request_info($id) {
        if (!self::hex($id,32)) return self::error('mad4b_remote_invalid_id','Invalid remote request ID.',422);
        $record=get_option(self::req_key($id),false);
        if (!is_array($record) || ($record['contract']??'')!==self::CONTRACT ||
            ($record['request_id']??'')!==$id ||
            ($record['site_uuid']??'')!==MAD4B_Host_V3_Owner_Console::SITE)
            return self::error('mad4b_remote_no_request','Review request is not registered for this site.',404);
        // Administrative readers see the same immutable request state.
        return $record;
    }
    public static function create_record_for_test($payload,$plan,$browser,$uid,$now,$id) {
        if (!$browser) return self::error('mad4b_remote_owner_browser_required',
            'A real Owner browser session with REST nonce is required.',403);
        if (!self::exact_keys($payload,array('expected_plan_sha256','confirmation')))
            return self::error('mad4b_remote_exact_request_required','Unexpected remote request fields.',422);
        if (!self::hex($id,32) ||
            !self::hex($payload['expected_plan_sha256'],64) ||
            !hash_equals((string)($plan['plan_sha256']??''),$payload['expected_plan_sha256']) ||
            !is_string($payload['confirmation']) ||
            !hash_equals(self::CONFIRM,$payload['confirmation']) ||
            !isset($plan['plan']) ||
            empty($plan['can_request_review']) ||
            ($plan['plan']['site_uuid'] ?? '')!==MAD4B_Host_V3_Owner_Console::SITE ||
            ($plan['plan']['environment'] ?? '')!=='staging' ||
            ($plan['plan']['action'] ?? '')!=='REQUEST_INDEPENDENT_OWNER_STEPUP' ||
            !empty($plan['can_issue_signed_approval']) ||
            !empty($plan['can_enable_persistent_write']) ||
            !empty($plan['plan']['write_authorized']))
            return self::error('mad4b_remote_exact_plan_changed',
                'The verified plan has changed; no signed approval is implied.',409);
        return array(
            'contract'=>self::CONTRACT,
            'state'=>self::PHASE,
            'site_uuid'=>MAD4B_Host_V3_Owner_Console::SITE,
            'company'=>'mad4b-central','environment'=>'staging',
            'target_host_id'=>'hostinger-primary',
            'request_id'=>$id,
            'plan_sha256'=>$payload['expected_plan_sha256'],
            'requested_by_user_id'=>(int)$uid,
            'created_epoch'=>(int)$now,
            'expires_epoch'=>(int)$now+self::TTL,
            'owner_signature_verified'=>false,
            'predecessor_signature_verified'=>false,
            'original305_approval_signed'=>false,
            'original312_enrollment_signed'=>false,
            'standing_host_write_authorized'=>false,
        );
    }
    public static function create_request($request) {
        if (!self::owner_browser())
            return self::error('mad4b_remote_owner_browser_required',
                'A real Owner browser session and a valid REST nonce are required.',403);
        $data=$request->get_json_params();
        $plan=self::plan();
        $id=bin2hex(random_bytes(16));
        $record=self::create_record_for_test($data,$plan,true,get_current_user_id(),time(),$id);
        if (is_wp_error($record)) return $record;
        // Safe request creation is bounded per actual browser session, and
        // no prior record can be deleted or overwritten to reset history.
        $rate_key='mad4b_v3_remote_rate_'.get_current_user_id();
        if (get_transient($rate_key))
            return self::error('mad4b_remote_request_rate_limited',
                'A recent request is already recorded; use its request ID.',429);
        if (!add_option(self::req_key($id),$record,'','no'))
            return self::error('mad4b_remote_create_outcome_ambiguous',
                'Review request registration requires reconciliation.',503);
        if (get_option(self::req_key($id),false)!==$record)
            return self::error('mad4b_remote_create_readback_unverified',
                'Review request write outcome cannot be verified.',503);
        set_transient($rate_key,$id,60);
        return self::response(array('result'=>'REMOTE_REQUEST_REGISTERED_NOT_SIGNED',
            'review'=>$record,'authority_issued'=>false,'write_authorized'=>false,
            'remote_handoff_url'=>rest_url(self::API.'/remote-request?request_id='.$id)));
    }
    private static function offer() {
        if (!extension_loaded('sodium') ||
            !defined('MAD4B_HOST_V3_ORIGINAL313_PUBLIC_B64'))
            return self::error('mad4b_remote_crypto_unavailable','Independent Original313 verifier unavailable.',503);
        // WordPress is only an untrusted transport of a Root-signed offer.
        // No file in the webroot contains a publishable signing identity,
        // and WordPress option mutations cannot forge Original313 signatures.
        $data=get_option(self::OFFER_OPTION,false);
        if (!is_array($data)) return self::error(
            'mad4b_remote_independent_offer_absent',
            'Original313 has not published a signed exact predecessor and enrolled Owner identity.',409);
        $encoded=wp_json_encode($data,JSON_UNESCAPED_SLASHES);
        if (!is_string($encoded) || strlen($encoded)>8192)
            return self::error('mad4b_remote_offer_untrusted','Root offer has unsafe structure or size.',409);
        if (!self::exact_keys($data,array_merge(self::HANDOFF_FIELDS,array('signature'))))
            return self::error('mad4b_remote_offer_shape_invalid','Root offer fields are invalid.',409);
        $pub=base64_decode(MAD4B_HOST_V3_ORIGINAL313_PUBLIC_B64,true);
        if (!is_string($pub) || strlen($pub)!==SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES ||
            !self::signed_valid($data,self::HANDOFF_FIELDS,self::HANDOFF_DOMAIN,$pub))
            return self::error('mad4b_remote_offer_signature_invalid','Original313 signature is invalid.',409);
        // Match the current first-party WP review candidate too; the Root
        // cannot silently switch source while an owner sees another plan.
        $plan=self::plan();
        if (($plan['plan']['source_head'] ?? '')!==$data['source_commit_sha'] ||
            ($plan['plan_sha256'] ?? '')!==$data['plan_sha256'])
            return self::error('mad4b_remote_offer_plan_drift',
                'Signed offer is not for the active exact review plan.',409);
        $owner=base64_decode($data['owner_stepup_public_b64'],true);
        $issuer305=base64_decode($data['original305_issuer_public_b64'],true);
        if (!is_string($owner) || strlen($owner)!==32 ||
            !is_string($issuer305) || strlen($issuer305)!==32 ||
            hash_equals($owner,$issuer305) ||
            hash_equals($issuer305,$pub) ||
            hash_equals($issuer305,(string)base64_decode(MAD4B_HOST_V3_ORIGINAL312_PUBLIC_B64,true)) ||
            hash_equals($owner,$pub) ||
            !defined('MAD4B_HOST_V3_ORIGINAL312_PUBLIC_B64') ||
            hash_equals($owner,(string)base64_decode(MAD4B_HOST_V3_ORIGINAL312_PUBLIC_B64,true)) ||
            $data['contract']!==self::HANDOFF ||
            $data['company']!=='mad4b-central' ||
            $data['environment']!=='staging' ||
            $data['site_uuid']!==MAD4B_Host_V3_Owner_Console::SITE ||
            $data['target_host_id']!=='hostinger-primary' ||
            $data['identity_authority_confirmed']!==true ||
            $data['monotonic_predecessor_verified']!==true ||
            $data['state']!=='READY_FOR_OWNER_STEPUP' ||
            !self::hex($data['request_id'],32) ||
            !self::hex($data['plan_sha256'],64) ||
            !self::hex($data['predecessor_sha256'],64) ||
            !self::hex($data['enrollment_sha256'],64) ||
            !self::hex($data['owner_stepup_enrollment_sha256'],64) ||
            !self::hex($data['original305_issuer_enrollment_sha256'],64) ||
            !self::hex($data['source_commit_sha'],40) ||
            !is_int($data['issued_epoch']) || !is_int($data['expires_epoch']) ||
            $data['expires_epoch']<time() ||
            $data['issued_epoch']>time()+5 ||
            $data['expires_epoch']-$data['issued_epoch']>600) return self::error(
                'mad4b_remote_offer_not_current',
                'The independent Root offer is not current, enrolled or bound to this exact site.',409);
        return $data;
    }
    private static function signed_valid($doc,$fields,$domain,$pub) {
        if (!self::exact_keys($doc,array_merge($fields,array('signature'))) ||
            !is_string($doc['signature'])) return false;
        $sig=base64_decode($doc['signature'],true);
        if (!is_string($sig) || strlen($sig)!==SODIUM_CRYPTO_SIGN_BYTES) return false;
        $unsigned=$doc;unset($unsigned['signature']);
        ksort($unsigned,SORT_STRING);
        return sodium_crypto_sign_verify_detached(
            $sig,$domain.wp_json_encode($unsigned,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE),
            $pub);
    }
    private static function handoff_for($record) {
        $offer=self::offer();
        $ready=!is_wp_error($offer) &&
            hash_equals($offer['request_id'],$record['request_id']) &&
            hash_equals($offer['plan_sha256'],$record['plan_sha256']);
        $stored=get_option(self::proof_key($record['request_id']),false);
        $quarantined=is_array($stored) &&
            ($stored['contract'] ?? '')==='mad4b.original305.owner-v3-signed-evidence-quarantine.v1' &&
            ($stored['request_id'] ?? '')===$record['request_id'] &&
            !empty($stored['root_independent_reverification_required']);
        return array(
            'contract'=>'mad4b.original305.remote-handoff-status.v1',
            'review'=>$record,
            'signed_proof_quarantined_in_wordpress'=>$quarantined,
            'independent_signed_offer_ready'=>$ready,
            'signed_intent_allowed'=>$ready,
            'offer_state'=>$ready?'READY_FOR_OWNER_STEPUP':
                  (is_wp_error($offer)?$offer->get_error_code():'mad4b_remote_offer_scope_mismatch'),
            'root_predecessor_complete'=>$ready,
            'source_owner_signature_present'=>$quarantined,
            'owner_stepup_verified_in_root'=>false,
            'owner_approval_issued'=>false,
            'persistent_host_write_authorized'=>false,
            'signed_offer'=>$ready?$offer:null,
        );
    }
    public static function remote_status($request) {
        $plan=self::plan();
        return self::response(array(
            'contract'=>'mad4b.original305.remote-owner-status.v1',
            'site_uuid'=>MAD4B_Host_V3_Owner_Console::SITE,
            'plan_sha256'=>$plan['plan_sha256'],
            'can_start_from_browser'=>self::owner_browser(),
            'remote_supported'=>true,
            'signed_root_offer_present'=>!is_wp_error(self::offer()),
            'signed_owner_proof_submission'=>'AVAILABLE_ONLY_AFTER_ROOT_OFFER',
            'owner_stepup_signed'=>false,
            'original313_current_predecessor'=>'NOT_ESTABLISHED',
            'host_write_authorized'=>false,
        ));
    }
    public static function remote_request($req) {
        $id=$req->get_param('request_id');
        $record=self::request_info($id);
        if (is_wp_error($record)) return $record;
        return self::response(self::handoff_for($record));
    }
    public static function validate_proof_for_test($record,$offer,$signed,$now) {
        if (!is_array($record) || !is_array($offer) || !is_array($signed) ||
            $now>$record['expires_epoch'] || $now>$offer['expires_epoch'])
            return self::error('mad4b_remote_proof_expired','The exact review and signed offer must be current.',409);
        if (!self::exact_keys($signed,array_merge(self::INTENT_FIELDS,array('signature'))) ||
            !is_string($signed['contract']) || $signed['contract']!==self::INTENT)
            return self::error('mad4b_remote_signed_intent_shape','Signed Owner intent shape is invalid.',422);
        foreach (array('request_id','plan_sha256') as $key) {
            $lhs=$key==='request_id'?$record['request_id']:$record['plan_sha256'];
            if (!hash_equals($lhs,$offer[$key]))
                return self::error('mad4b_remote_offer_request_mismatch','Root offer does not match this review.',409);
        }
        foreach (array('company','environment','site_uuid','target_host_id',
                       'enrollment_sha256','predecessor_sha256','source_commit_sha','request_id') as $key) {
            if (!is_string($signed[$key]) || !hash_equals((string)$offer[$key],$signed[$key]))
                return self::error('mad4b_remote_intent_scope_mismatch','Owner intent does not match the signed Root offer.',409);
        }
        if ($signed['operation_id']!=='company.host-authority.enrollment.v3.issue' ||
            $signed['decision']!=='APPROVE' ||
            !self::hex($signed['actor_digest_sha256'],64) ||
            !self::hex($signed['intent_nonce'],32) ||
            !is_int($signed['issued_epoch']) || !is_int($signed['expires_epoch']) ||
            $signed['issued_epoch']>$now+5 ||
            $signed['issued_epoch']<$now-180 ||
            $signed['expires_epoch']<=$now ||
            $signed['expires_epoch']>$signed['issued_epoch']+180)
            return self::error('mad4b_remote_owner_decision_invalid','Owner decision or validity window is invalid.',409);
        $owner=base64_decode($offer['owner_stepup_public_b64'],true);
        if (!is_string($owner) || strlen($owner)!==32 ||
            !self::signed_valid($signed,self::INTENT_FIELDS,self::INTENT_DOMAIN,$owner))
            return self::error('mad4b_remote_owner_signature_invalid','Independent Owner Step-up signature is invalid.',403);
        return array(
            'contract'=>'mad4b.original305.owner-v3-signed-evidence-quarantine.v1',
            'state'=>'SIGNED_OWNER_STEPUP_VERIFIED_IN_WORDPRESS_PENDING_ROOT',
            'request_id'=>$record['request_id'],
            'plan_sha256'=>$record['plan_sha256'],
            'signed_intent'=>$signed,
            'original313_signed_offer_sha256'=>hash('sha256',wp_json_encode($offer,JSON_UNESCAPED_SLASHES)),
            'verified_epoch'=>$now,
            'root_independent_reverification_required'=>true,
            'original305_approval_signed'=>false,
            'original312_enrollment_signed'=>false,
            'host_write_authorized'=>false
        );
    }
    public static function receive_signed_proof($request) {
        $data=$request->get_json_params();
        if (!self::exact_keys($data,array('request_id','signed_intent')))
            return self::error('mad4b_remote_signed_proof_shape','Only request_id and signed_intent are accepted.',422);
        $record=self::request_info($data['request_id']);
        if (is_wp_error($record)) return $record;
        $offer=self::offer();
        if (is_wp_error($offer)) return $offer;
        $proof=self::validate_proof_for_test($record,$offer,$data['signed_intent'],time());
        if (is_wp_error($proof)) return $proof;
        $key=self::proof_key($record['request_id']);
        if (!add_option($key,$proof,'','no')) {
            $saved=get_option($key,false);
            if (is_array($saved) && ($saved['contract']??'')===$proof['contract'] &&
                ($saved['request_id']??'')===$proof['request_id'] &&
                ($saved['signed_intent']??array())===$proof['signed_intent']) return self::response(
                    array('result'=>'EXACT_SIGNED_PROOF_ALREADY_IN_QUARANTINE',
                          'root_validation_pending'=>true,'write_authorized'=>false));
            return self::error('mad4b_remote_proof_conflict','Another proof is recorded for this request.',409);
        }
        if (get_option($key,false)!==$proof)
            return self::error('mad4b_remote_proof_readback_unverified','Signed proof receipt readback not verified.',503);
        return self::response(array('result'=>'SIGNED_PROOF_QUARANTINED_NOT_AN_APPROVAL',
            'request_id'=>$record['request_id'],
            'root_validation_pending'=>true,'write_authorized'=>false));
    }
    public static function admin_panel() {
        if (!is_admin() || !isset($_GET['page']) ||
            $_GET['page']!==MAD4B_Host_V3_Owner_Console::PAGE ||
            !current_user_can('manage_options') ||
            !MAD4B_Host_V3_Owner_Console::site_binding_valid()) return;
        $base=rest_url(self::API);
        $nonce=wp_create_nonce('wp_rest');
        echo '<script>(function(){"use strict";';
        echo 'const base='.wp_json_encode($base,JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT).', nonce='.wp_json_encode($nonce,JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT).';';
        ?>
document.addEventListener("DOMContentLoaded",function(){
 const host=document.querySelector(".mad4b-host-v3 .v3-layout>div:first-child");
 if(!host)return;
 const card=document.createElement("section");card.className="v3-card";
 const heading=document.createElement("h2");heading.textContent="Remote Owner Step-up";card.append(heading);
 const desc=document.createElement("p");desc.textContent="Start a remote review and copy the evidence handoff. This is not a signature, grant, or authorization. Independent Original313 and Owner signing are required.";card.append(desc);
 const start=document.createElement("button");start.type="button";start.className="button button-secondary";start.textContent="Create remote review request";card.append(start);
 const copy=document.createElement("button");copy.type="button";copy.className="button";copy.textContent="Copy handoff";copy.disabled=true;copy.style.marginInlineStart="8px";card.append(copy);
 const status=document.createElement("p");status.setAttribute("role","status");status.setAttribute("aria-live","polite");card.append(status);
 const label=document.createElement("label");label.textContent="Independent signed Owner intent (JSON)";label.style.display="block";label.style.marginTop="15px";card.append(label);
 const signed=document.createElement("textarea");signed.rows=4;signed.className="large-text code";signed.placeholder="Paste an independently signed Owner intent after Original313's verified offer is available";signed.setAttribute("aria-label","Independently signed Owner intent JSON");card.append(signed);
 const submit=document.createElement("button");submit.type="button";submit.className="button button-secondary";submit.textContent="Verify signed proof remotely";submit.disabled=true;card.append(submit);
 const help=document.createElement("details");const summary=document.createElement("summary");summary.textContent="What does the external signer need?";help.append(summary);
 const note=document.createElement("p");note.textContent="A registered independent Owner identity and fresh Original313-signed predecessor and offer. WordPress only transports review evidence; Root verifies it independently.";help.append(note);card.append(help);
 host.append(card);let handoff=null;
 async function api(path,body){
  const r=await fetch(base+path,{method:body?"POST":"GET",
    headers:{"Accept":"application/json","X-WP-Nonce":nonce,...(body?{"Content-Type":"application/json"}:{})},
    credentials:"same-origin",cache:"no-store",...(body?{body:JSON.stringify(body)}:{})});
  const d=await r.json();if(!r.ok)throw Error(d.message||"Request rejected");return d;
 }
 start.addEventListener("click",async function(){
  start.disabled=true;status.textContent="Creating an unsigned remote review...";
  try{
    const plan=await api("/review-plan");
    if(!plan.plan_sha256)throw Error("No exact plan is available.");
    const r=await api("/remote-requests",{expected_plan_sha256:plan.plan_sha256,
       confirmation:"REQUEST REMOTE V3 OWNER REVIEW"});
    handoff=await api("/remote-request?request_id="+encodeURIComponent(r.review.request_id));
    copy.disabled=false;submit.disabled=!handoff.independent_signed_offer_ready;status.textContent="Request "+r.review.request_id+
       " recorded. "+handoff.offer_state+". No Host Write or owner signature issued.";
  }catch(e){status.textContent=e.message;}finally{start.disabled=false;}
 });
 copy.addEventListener("click",async function(){
   if(!handoff)return;
   try{await navigator.clipboard.writeText(JSON.stringify(handoff,null,2));status.textContent="Remote handoff copied; independent signing is still required.";}
   catch(e){status.textContent="Browser clipboard permission denied; use GET /remote-request with your request ID.";}
 });
 submit.addEventListener("click",async function(){
   if(!handoff||!handoff.independent_signed_offer_ready)return;
   submit.disabled=true;status.textContent="Checking the independent signature…";
   try{
     const proof=JSON.parse(signed.value);
     if(!proof || Array.isArray(proof)||typeof proof!=="object")throw Error("Signed intent must be a JSON object.");
     const r=await api("/remote-signed-proof",{request_id:handoff.review.request_id,signed_intent:proof});
     status.textContent=r.result+". Root must independently reverify this proof; Host Write remains disabled.";
   }catch(e){status.textContent=e.message;}finally{submit.disabled=false;}
 });
});
})();
<?php
        echo '</script>';
    }
}
MAD4B_Host_V3_Remote_Handoff::boot();
}
