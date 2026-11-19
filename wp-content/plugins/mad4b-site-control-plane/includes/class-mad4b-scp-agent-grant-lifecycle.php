<?php
if (!defined('ABSPATH')) exit;

/**
 * Exact owner-governed NHI grant issuance, phase 1: bounded inference read.
 * This is a WordPress-native, authenticated REST surface, NOT a SQL/SSH API.
 * Privileged and domain-mutating grants retain their original owner workflows.
 */
final class MAD4B_SCP_Agent_Grant_Lifecycle {
 const CONTRACT = 'mad4b.agent-grant-lifecycle.v1';
 const ABILITY = 'mad4b/ollama-inference';
 const SERVER = 'mad4b-read';
 const PROVIDER = 'core';
 const LOCK = 'mad4b_scp_agent_grant_lifecycle_lock_v1';
 const CONFIRM = 'APPROVE_EXACT_AGENT_GRANT';
 const APPLY_CONFIRM = 'APPLY_APPROVED_EXACT_GRANT';
 public static function boot() {
  add_action('wp_abilities_api_init', array(__CLASS__,'register_abilities'), 82);
  add_action('rest_api_init', array(__CLASS__,'register_rest'), 65);
 }
 public static function owner() {
  if (PHP_SAPI==='cli' || (defined('WP_CLI') && WP_CLI)
    || true!==current_user_can('manage_options')) return false;
  $uid=get_current_user_id();
  if (!is_int($uid)||$uid<1 || !class_exists('MAD4B_SCP_Site_Profile')
    || true!==MAD4B_SCP_Site_Profile::configured()
    || true!==MAD4B_SCP_Site_Profile::origin_enrolled()
    || true!==MAD4B_SCP_Site_Profile::site_urls_match_enrollment()
    || true!==MAD4B_SCP_Site_Profile::user_is_enrolled($uid)) return false;
  if (true!==MAD4B_SCP_Site_Profile::managed_runtime_enabled()) return false;
  // OAuth clients need the platform-verified bearer AND existing authority
  // step-up scope. Browser admin sessions need a genuine WP REST nonce.
  if (class_exists('MAD4B_SCP_OAuth_Resource_Bridge')) {
   $bearer=MAD4B_SCP_OAuth_Resource_Bridge::verified_bearer_active();
   if (is_wp_error($bearer)) return false;
   if (true===$bearer) {
    return true===MAD4B_SCP_OAuth_Resource_Bridge::verified_bearer_has_scope(
      MAD4B_SCP_OAuth_Resource_Bridge::AUTHORITY_STEP_UP_SCOPE)
     && class_exists('MAD4B_SCP_Local_OAuth_Server')
     && true===MAD4B_SCP_OAuth_Resource_Bridge::verified_bearer_client_is(
      MAD4B_SCP_Local_OAuth_Server::CHATGPT_CIMD_CLIENT_ID);
   }
  }
  $nonce=is_string($_SERVER['HTTP_X_WP_NONCE']??null)?$_SERVER['HTTP_X_WP_NONCE']:'';
  if (''===$nonce) return false;
  $nonce_valid=wp_verify_nonce($nonce,'wp_rest');
  return 1===$nonce_valid || 2===$nonce_valid;
 }
 public static function register_abilities() {
  if (!function_exists('wp_register_ability')) return;
  $methods=array('status'=>true,'plan'=>true,'request'=>false,'decision'=>false,'apply'=>false,'revoke-plan'=>true,'revoke-apply'=>false);
  $properties=array(
   'agent_public_id'=>array('type'=>'string','minLength'=>36,'maxLength'=>64),
   'scope'=>array('type'=>'string','enum'=>array('all','local','development','staging','production')),
   'reason'=>array('type'=>'string','minLength'=>3,'maxLength'=>500),
   'plan_sha256'=>array('type'=>'string','minLength'=>64,'maxLength'=>64),
   'ticket_id'=>array('type'=>'string','minLength'=>36,'maxLength'=>36),
   'expected_payload_sha256'=>array('type'=>'string','minLength'=>64,'maxLength'=>64),
   'confirmation'=>array('type'=>'string','maxLength'=>64),
   'expected_revision'=>array('type'=>'integer','minimum'=>0),
   'grant_id'=>array('type'=>'integer','minimum'=>1),
  );
  foreach($methods as $method=>$readonly) {
   $name='mad4b/agent-grant-'.$method;
   if (function_exists('wp_has_ability') && true===wp_has_ability($name)) continue;
   wp_register_ability($name,array(
    'label'=>'Governed Exact Agent Grant '.ucfirst($method),
    'description'=>'Owner-only plan/ticket workflow for an exact nonprivileged Ollama inference grant.',
    'category'=>'mad4b-admin',
    'execute_callback'=>array(__CLASS__,str_replace('-','_',$method)),
    'permission_callback'=>array(__CLASS__,'owner'),
    'input_schema'=>array('type'=>'object','properties'=>$properties,'additionalProperties'=>false),
    'output_schema'=>array('type'=>'object','additionalProperties'=>true),
    'meta'=>array('public'=>false,'show_in_rest'=>false,
     'mcp'=>array('public'=>false,'type'=>'tool','surface'=>'admin'),
     'annotations'=>array('readonly'=>$readonly,'destructive'=>!$readonly,'idempotent'=>$readonly))
   ));
  }
 }
 public static function register_rest() {
  foreach(array('status','plan','request','decision','apply','revoke-plan','revoke-apply') as $op) {
   register_rest_route('mad4b/v1','/agent-grants/'.$op,array(
    'methods'=>'POST',
    'permission_callback'=>array(__CLASS__,'owner'),
    'callback'=>static function($req)use($op) {
     $input=$req->get_json_params();
     if(!is_array($input)) return new WP_Error('mad4b_agent_grant_input_required','A JSON object is required.',array('status'=>400));
     $a=wp_get_ability('mad4b/agent-grant-'.$op);
     if(!is_object($a) || !method_exists($a,'execute')) return new WP_Error('mad4b_agent_grant_ability_missing','Original Ability unavailable.',array('status'=>503));
     return $a->execute($input);
    }
   ));
  }
 }
 private static function canonical($value) {
  $out=wp_json_encode($value,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
  return is_string($out)?hash('sha256',$out):'';
 }
 // WordPress $wpdb ARRAY_A returns decimal DB IDs as strings.
 // Keep exact integer meaning without coercing arrays, WP_Error or fragments.
 private static function exact_db_number($value,$allow_zero=false) {
  if(is_int($value))return $value>=($allow_zero?0:1)?$value:null;
  if(!is_string($value)||!preg_match('/^(?:0|[1-9][0-9]{0,15})$/D',$value))
   return null;
  $number=(int)$value;
  return (string)$number===$value&&$number>=($allow_zero?0:1)?$number:null;
 }
 private static function build($input) {
  if (!is_array($input) || !is_string($input['agent_public_id']??null)
    || !is_string($input['scope']??null) || !is_string($input['reason']??null))
   return new WP_Error('mad4b_agent_grant_invalid_input');
  $id=trim($input['agent_public_id']);
  $scope=sanitize_key($input['scope']);
  $reason=trim($input['reason']);
  $env=class_exists('MAD4B_SCP_Environment')?MAD4B_SCP_Environment::effective():wp_get_environment_type();
  if (!is_string($env)||!in_array($env,array('local','development','staging','production'),true))
   return new WP_Error('mad4b_agent_grant_environment_unverified');
  if (!preg_match('/^[a-f0-9-]{36}$/D',$id) || strlen($reason)<3 || strlen($reason)>500
   || !in_array($scope,array('all',$env),true))
   return new WP_Error('mad4b_agent_grant_input_invalid','An exact agent, reason and all/matching scope are required.');
  if (!class_exists('MAD4B_SCP_Agent_Registry') || !class_exists('MAD4B_SCP_Servers')
   || !class_exists('MAD4B_SCP_Audit') || !class_exists('MAD4B_SCP_Approval_Tickets')
   || true!==MAD4B_SCP_Site_Profile::configured()
   || true!==MAD4B_SCP_Site_Profile::origin_enrolled()
   || true!==MAD4B_SCP_Site_Profile::site_urls_match_enrollment()
   || !function_exists('wp_has_ability') || true!==wp_has_ability(self::ABILITY)
   || MAD4B_SCP_Servers::provider_for_ability(self::SERVER,self::ABILITY)!==self::PROVIDER)
   return new WP_Error('mad4b_agent_grant_runtime_unavailable');
  $audit=MAD4B_SCP_Audit::storage_status();
  if(!is_array($audit) || ($audit['ready']??null)!==true)
   return new WP_Error('mad4b_agent_grant_audit_unavailable');
  $agent=MAD4B_SCP_Agent_Registry::get_agent_by_public_id($id);
  if(!is_array($agent) || ($agent['status']??'')!=='enabled'
   || ($agent['slug']??'')!==MAD4B_SCP_Site_Profile::agent_slug())
   return new WP_Error('mad4b_agent_grant_agent_invalid');
  $agent_id=self::exact_db_number($agent['id']??null);
  $agent_revision=self::exact_db_number($agent['revision']??null,true);
  if(null===$agent_id||null===$agent_revision)
   return new WP_Error('mad4b_agent_grant_agent_identity_invalid');
  $subjects=MAD4B_SCP_Agent_Registry::subjects_for_agent($agent_id);
  if (!is_array($subjects)||!$subjects)
   return new WP_Error('mad4b_agent_grant_subject_required');
  $has_subject=false;$subject_evidence=array();
  foreach($subjects as $subject){
   $subject_id=is_array($subject)?self::exact_db_number($subject['id']??null):null;
   if (null===$subject_id
    || !is_string($subject['status']??null)
    || !is_string($subject['subject_fingerprint']??null)
    || !preg_match('/^[a-f0-9]{64}$/D',$subject['subject_fingerprint']))
    return new WP_Error('mad4b_agent_grant_subject_identity_unverified');
   if($subject['status']==='enabled')$has_subject=true;
   $subject_evidence[]=array(
    'id'=>$subject_id,'status'=>$subject['status'],
    'fingerprint'=>$subject['subject_fingerprint']);
  }
  if(!$has_subject)return new WP_Error('mad4b_agent_grant_subject_required');
  $ability_object=function_exists('wp_get_ability')?wp_get_ability(self::ABILITY):null;
  if(!is_object($ability_object)||!method_exists($ability_object,'get_input_schema'))
   return new WP_Error('mad4b_agent_grant_ability_schema_missing');
  try { $ability_schema=$ability_object->get_input_schema(); }
  catch (\Throwable $error) { $ability_schema=null; }
  if (!is_array($ability_schema)||!$ability_schema)
   return new WP_Error('mad4b_agent_grant_ability_schema_unverified');
  $ability_schema_sha=self::canonical($ability_schema);
  $existing=MAD4B_SCP_Agent_Registry::exact_grant($agent_id,self::SERVER,self::ABILITY,self::PROVIDER);
  if(is_array($existing))return new WP_Error('mad4b_agent_grant_already_allowed');
  if(!is_wp_error($existing) || $existing->get_error_code()!=='mad4b_nhi_grant_missing')
   return new WP_Error('mad4b_agent_grant_explicit_deny_or_error');
  $rows=MAD4B_SCP_Agent_Registry::grants_for_agent($agent_id,self::SERVER);
  if (!is_array($rows)) return new WP_Error('mad4b_agent_grant_inventory_unverified');
  $fingerprints=array();
  foreach($rows as $r){
   if (!is_array($r)) return new WP_Error('mad4b_agent_grant_inventory_unverified');
   if(($r['ability_name']??'')===self::ABILITY && ($r['provider']??'')===self::PROVIDER) {
    $grant_id=self::exact_db_number($r['id']??null);
    $grant_env=$r['environment']??null;
    $effect=$r['effect']??null;
    if (null===$grant_id||!is_string($grant_env)||!is_string($effect)
      ||!in_array($effect,array('allow','deny'),true))
     return new WP_Error('mad4b_agent_grant_inventory_unverified');
    $fingerprints[]=array('id'=>$grant_id,'environment'=>$grant_env,'effect'=>$effect);
    if($effect==='allow' && $grant_env===$scope)
     return new WP_Error('mad4b_agent_grant_duplicate_scope');
   }
  }
  // The original site identity and profile revision are immutable inputs
  // to the grant plan, not untrusted objects that JSON can stringify.
  $site_uuid=MAD4B_SCP_Site_Profile::site_uuid();
  $site_revision=self::exact_db_number(MAD4B_SCP_Site_Profile::revision(),true);
  $site_digest=MAD4B_SCP_Site_Profile::profile_digest();
  if (!is_string($site_uuid)
    || !preg_match('/^[a-f0-9-]{36}$/D',$site_uuid)
    || null===$site_revision
    || !is_string($site_digest)
    || !preg_match('/^[a-f0-9]{64}$/D',$site_digest))
   return new WP_Error('mad4b_agent_grant_site_identity_unverified');
  $basis=array('contract'=>self::CONTRACT,'site_uuid'=>$site_uuid,
   'site_profile_revision'=>$site_revision,
   'site_profile_digest'=>$site_digest,
   'effective_environment'=>$env,
   'agent_public_id'=>$id,'agent_revision'=>$agent_revision,
   'enabled_subjects_snapshot_sha256'=>self::canonical($subject_evidence),
   'ability_input_schema_sha256'=>$ability_schema_sha,
   'server'=>self::SERVER,'ability'=>self::ABILITY,'provider'=>self::PROVIDER,
   'scope'=>$scope,'reason_sha256'=>hash('sha256',$reason),
   'grant_rows_sha256'=>self::canonical($fingerprints));
  $sha=self::canonical($basis);
  if(!preg_match('/^[a-f0-9]{64}$/',$sha))return new WP_Error('mad4b_agent_grant_plan_digest_failed');
  return array('plan_sha256'=>$sha,'basis'=>$basis,'agent'=>$agent,
   'ticket_input'=>array('contract'=>self::CONTRACT,'plan_sha256'=>$sha,
    'agent_public_id'=>$id,'scope'=>$scope,
    'agent_revision'=>$agent_revision,'site_uuid'=>$site_uuid),
   'reason'=>$reason);
 }
 public static function status($input=array()) {
  if(!self::owner())return new WP_Error('mad4b_agent_grant_owner_required');
  $id=trim((string)($input['agent_public_id']??''));
  if(!preg_match('/^[a-f0-9-]{36}$/D',$id))
   return new WP_Error('mad4b_agent_grant_agent_id_required');
  $agent=MAD4B_SCP_Agent_Registry::get_agent_by_public_id($id);
  if(!is_array($agent))return new WP_Error('mad4b_agent_grant_agent_missing');
  $agent_id=self::exact_db_number($agent['id']??null);
  $agent_revision=self::exact_db_number($agent['revision']??null,true);
  if(null===$agent_id||null===$agent_revision)
   return new WP_Error('mad4b_agent_grant_agent_identity_invalid');
  $grant=MAD4B_SCP_Agent_Registry::exact_grant(
   $agent_id,self::SERVER,self::ABILITY,self::PROVIDER);
  return array('contract'=>self::CONTRACT,'agent_public_id'=>$id,
   'agent_status'=>(string)$agent['status'],
   'agent_revision'=>$agent_revision,
   'ability_registered'=>function_exists('wp_has_ability')&&wp_has_ability(self::ABILITY),
   'mapped_provider'=>MAD4B_SCP_Servers::provider_for_ability(self::SERVER,self::ABILITY),
   'effective_grant'=>is_wp_error($grant)?$grant->get_error_code()
     : (is_array($grant)?(string)$grant['effect']:'unknown'),
   'effective_scope'=>is_array($grant)?(string)$grant['environment']:'',
   'effective_grant_id'=>is_array($grant)?(int)$grant['id']:0,
   'environment'=>MAD4B_SCP_Environment::effective(),
   'mutation_performed'=>false);
 }
 public static function plan($input=array()) {
  if(!self::owner())return new WP_Error('mad4b_agent_grant_owner_required');
  $plan=self::build($input);
  if(is_wp_error($plan))return $plan;
  return array('contract'=>self::CONTRACT,'eligible'=>true,
   'target'=>$plan['basis'],'plan_sha256'=>$plan['plan_sha256'],
   'owner_approval_required'=>true,'mutation_performed'=>false);
 }
 private static function exact_plan($input) {
  $p=self::build($input);
  if(is_wp_error($p))return $p;
  $hash=strtolower(trim((string)($input['plan_sha256']??'')));
  if(!preg_match('/^[a-f0-9]{64}$/',$hash) || !hash_equals($p['plan_sha256'],$hash))
   return new WP_Error('mad4b_agent_grant_plan_stale');
  return $p;
 }
 public static function request($input=array()) {
  if(!self::owner())return new WP_Error('mad4b_agent_grant_owner_required');
  $p=self::exact_plan($input);if(is_wp_error($p))return $p;
  $audit=MAD4B_SCP_Audit::record('mad4b/agent-grant-request',
   array('plan_sha256'=>$p['plan_sha256'],'ability'=>self::ABILITY,'authority_expansion'=>true),'ok');
  if(is_wp_error($audit)||!is_array($audit))return new WP_Error('mad4b_agent_grant_audit_required');
  $ticket=MAD4B_SCP_Approval_Tickets::create_pending($p['agent']['public_id'],self::SERVER,
   self::ABILITY,self::PROVIDER,$p['plan_sha256'],$p['ticket_input'],'mutation',$p['reason']);
  if(is_wp_error($ticket))return $ticket;
  return array('contract'=>self::CONTRACT,'ticket_id'=>$ticket['ticket_id']??'',
   'payload_sha256'=>$ticket['payload_sha256']??'','status'=>'pending',
   'owner_decision_required'=>true,'mutation_performed'=>false);
 }
 public static function decision($input=array()) {
  if(!self::owner())return new WP_Error('mad4b_agent_grant_owner_required');
  $p=self::exact_plan($input);if(is_wp_error($p))return $p;
  if(($input['confirmation']??'')!==self::CONFIRM)
   return new WP_Error('mad4b_agent_grant_confirmation_required');
  $ticket=MAD4B_SCP_Approval_Tickets::get((string)($input['ticket_id']??''));
  if(!is_array($ticket)||($ticket['status']??'')!=='pending'
    ||(int)($ticket['agent_id']??0)!==(int)$p['agent']['id']
    ||($ticket['ability_name']??'')!==self::ABILITY
    ||($ticket['server_id']??'')!==self::SERVER
    ||($ticket['target_fingerprint']??'')!==$p['plan_sha256'])
   return new WP_Error('mad4b_agent_grant_ticket_mismatch');
  $expected=MAD4B_SCP_Approval_Tickets::canonical_payload_hash(
   $p['agent']['public_id'],self::SERVER,self::ABILITY,self::PROVIDER,
   $p['plan_sha256'],$p['ticket_input'],'mutation');
  if(is_wp_error($expected))return $expected;
  if(!hash_equals((string)($ticket['payload_sha256']??''),(string)$expected))
   return new WP_Error('mad4b_agent_grant_ticket_payload_drift');
  $result=MAD4B_SCP_Approval_Tickets::decide_pending($ticket['ticket_id'],'approve',$expected,
   array('contract'=>self::CONTRACT,'plan_sha256'=>$p['plan_sha256']));
  if(is_wp_error($result))return $result;
  return array('contract'=>self::CONTRACT,'ticket_id'=>$ticket['ticket_id'],
   'status'=>$result['status']??'unknown','approved_by_enrolled_owner'=>true,
   'grant_created'=>false);
 }
 public static function revoke_plan($input=array()) {
  return self::revoke_common($input,false);
 }
 public static function revoke_apply($input=array()) {
  return self::revoke_common($input,true);
 }
 private static function revoke_common($input,$apply) {
  if(!self::owner())return new WP_Error('mad4b_agent_grant_owner_required');
  if(!class_exists('MAD4B_SCP_G2_Permission_Changes'))
   return new WP_Error('mad4b_agent_grant_revoke_provider_missing');
  $id=trim((string)($input['agent_public_id']??''));
  $gid=absint($input['grant_id']??0);
  $rev=isset($input['expected_revision'])?(int)$input['expected_revision']:-1;
  $reason=trim((string)($input['reason']??''));
  if(!preg_match('/^[a-f0-9-]{36}$/D',$id)||$gid<1||$rev<0
   ||strlen($reason)<3||strlen($reason)>500)
   return new WP_Error('mad4b_agent_grant_revoke_target_invalid');
  $agent=MAD4B_SCP_Agent_Registry::get_agent_by_public_id($id);
  if(!is_array($agent)||$rev!==(int)$agent['revision'])
   return new WP_Error('mad4b_agent_grant_revoke_agent_stale');
  $found=false;
  foreach(MAD4B_SCP_Agent_Registry::grants_for_agent((int)$agent['id'],self::SERVER) as $row)
   if((int)$row['id']===$gid && ($row['effect']??'')==='allow'
    &&($row['ability_name']??'')===self::ABILITY
    &&($row['provider']??'')===self::PROVIDER)$found=true;
  if(!$found)return new WP_Error('mad4b_agent_grant_revoke_mismatch');
  $params=array('agent_public_id'=>$id,'operation'=>'revoke_allow_grant',
   'expected_revision'=>$rev,'server_id'=>self::SERVER,'grant_id'=>$gid,
   'reason'=>$reason);
  $plan=MAD4B_SCP_G2_Permission_Changes::plan($params);
  if(is_wp_error($plan))return $plan;
  if(empty($plan['eligible']))
   return new WP_Error('mad4b_agent_grant_revoke_blocked');
  if(!$apply)return $plan;
  if(($input['confirmation']??'')!==MAD4B_SCP_G2_Permission_Changes::CONFIRMATION)
   return new WP_Error('mad4b_agent_grant_revoke_confirmation_required');
  $expected=strtolower(trim((string)($input['plan_sha256']??'')));
  if(!preg_match('/^[a-f0-9]{64}$/',$expected)
   ||!hash_equals((string)$plan['plan_sha256'],$expected))
   return new WP_Error('mad4b_agent_grant_revoke_plan_stale');
  return MAD4B_SCP_G2_Permission_Changes::apply($params+array(
   'plan_sha256'=>$expected,
   'confirmation'=>MAD4B_SCP_G2_Permission_Changes::CONFIRMATION));
 }
 public static function apply($input=array()) {
  global $wpdb;
  if(!self::owner())return new WP_Error('mad4b_agent_grant_owner_required');
  $p=self::exact_plan($input);if(is_wp_error($p))return $p;
  if(($input['confirmation']??'')!==self::APPLY_CONFIRM)
   return new WP_Error('mad4b_agent_grant_apply_confirmation_required');
  $ticket_id=(string)($input['ticket_id']??'');
  $ticket=MAD4B_SCP_Approval_Tickets::get($ticket_id);
  if(!is_array($ticket)||($ticket['status']??'')!=='approved'
    ||(int)($ticket['approved_by']??0)<1
    ||!MAD4B_SCP_Site_Profile::user_is_enrolled((int)$ticket['approved_by']))
   return new WP_Error('mad4b_agent_grant_owner_ticket_required');
  $verified=MAD4B_SCP_Approval_Tickets::validate_exact($ticket_id,$p['agent'],self::SERVER,
   self::ABILITY,self::PROVIDER,$p['plan_sha256'],$p['ticket_input'],'mutation');
  if(is_wp_error($verified))return $verified;
  if(!add_option(self::LOCK,array('at'=>time(),'actor'=>get_current_user_id()),'',false))
   return new WP_Error('mad4b_agent_grant_concurrent_apply');
  $claimed=false;$created_id=0;$grant_inserted=false;$error='';
  try {
   $fresh=self::exact_plan($input);
   if(is_wp_error($fresh))return $fresh;
   $pre=MAD4B_SCP_Audit::record('mad4b/agent-grant-apply-intent',
    array('contract'=>self::CONTRACT,'ticket_id'=>$ticket_id,
     'plan_sha256'=>$p['plan_sha256'],'authority_expansion'=>true),'ok');
   if(is_wp_error($pre)||!is_array($pre))return new WP_Error('mad4b_agent_grant_audit_required');
   $claim=MAD4B_SCP_Approval_Tickets::claim_exact($ticket_id,$p['agent'],self::SERVER,
    self::ABILITY,self::PROVIDER,$p['plan_sha256'],$p['ticket_input'],'mutation');
   if(is_wp_error($claim))return $claim;
   $claimed=true;
   $before=MAD4B_SCP_Agent_Registry::grants_for_agent((int)$p['agent']['id'],self::SERVER);
   $known=array();foreach($before as $row)$known[(int)$row['id']]=true;
   // Re-check deny-first authority immediately before insertion. The ticket
   // claim is single-use, but other controllers could have modified a grant.
   $late=MAD4B_SCP_Agent_Registry::exact_grant((int)$p['agent']['id'],
    self::SERVER,self::ABILITY,self::PROVIDER);
   if(!is_wp_error($late)||$late->get_error_code()!=='mad4b_nhi_grant_missing') {
    $error='mad4b_agent_grant_concurrent_authority_change';
    return new WP_Error($error);
   }
   $res=MAD4B_SCP_Agent_Registry::grant_ability($p['agent']['public_id'],self::SERVER,
    self::ABILITY,self::PROVIDER,array(),'allow',$p['basis']['scope']);
   if(is_wp_error($res)){$error=$res->get_error_code();return $res;}
   $grant_inserted=true;
   // Capture the same-writer insert identity immediately, before any other DB
   // operation. It is needed for safe compensation even if readback fails.
   $created_id=(int)$wpdb->insert_id;
   $after=MAD4B_SCP_Agent_Registry::grants_for_agent((int)$p['agent']['id'],self::SERVER);
   $created_verified=false;
   foreach($after as $row)if($created_id>0 && (int)$row['id']===$created_id
    && !isset($known[$created_id])
    &&($row['ability_name']??'')===self::ABILITY&&($row['provider']??'')===self::PROVIDER
    &&($row['effect']??'')==='allow'&&($row['environment']??'')===$p['basis']['scope'])
     $created_verified=true;
   $effective=MAD4B_SCP_Agent_Registry::exact_grant((int)$p['agent']['id'],self::SERVER,
    self::ABILITY,self::PROVIDER);
   if(!$created_verified || !is_array($effective)||($effective['effect']??'')!=='allow') {
    $error='mad4b_agent_grant_readback_failed';
    return new WP_Error($error);
   }
   $receipt=MAD4B_SCP_Audit::record('mad4b/agent-grant-applied',
    array('contract'=>self::CONTRACT,'ticket_id'=>$ticket_id,
     'grant_id'=>$created_id,'plan_sha256'=>$p['plan_sha256'],
     'scope'=>$p['basis']['scope'],'authority_expansion'=>true),'ok');
   if(is_wp_error($receipt)||!is_array($receipt)){
    $error='mad4b_agent_grant_receipt_failed';return new WP_Error($error);
   }
   $final=MAD4B_SCP_Approval_Tickets::finalize_claim($ticket_id,'used');
   if(is_wp_error($final)){$error='mad4b_agent_grant_finalize_failed';return new WP_Error($error);}
   $claimed=false;
   return array('contract'=>self::CONTRACT,'grant_id'=>$created_id,
    'ticket_id'=>$ticket_id,'scope'=>$p['basis']['scope'],'verified'=>true,
    'audit_recorded'=>true,'mutation_performed'=>true);
  } finally {
   $uncertain=false;
   if($claimed){
    if($grant_inserted && !$created_id)$uncertain=true;
    if($created_id){
     $revoked=MAD4B_SCP_Agent_Registry::revoke_allow_grant_by_id(
      $p['agent']['public_id'],$created_id,self::SERVER);
     $still=false;
     $wpdb->last_error='';
     $readback=MAD4B_SCP_Agent_Registry::grants_for_agent((int)$p['agent']['id'],self::SERVER);
     $readback_failed=(string)$wpdb->last_error!=='';
     foreach($readback as $row)if((int)$row['id']===$created_id)$still=true;
     if(is_wp_error($revoked) || $still || $readback_failed)$uncertain=true;
     $comp_audit=MAD4B_SCP_Audit::record('mad4b/agent-grant-compensation',
      array('grant_id'=>$created_id,'ticket_id'=>$ticket_id,
       'revoke_readback_verified'=>!$uncertain),$uncertain?'error':'ok');
     if(is_wp_error($comp_audit)||!is_array($comp_audit))$uncertain=true;
    }
    $closed=MAD4B_SCP_Approval_Tickets::finalize_claim(
     $ticket_id,'failed',$error?:'mad4b_agent_grant_apply_interrupted');
    if(is_wp_error($closed))$uncertain=true;
   }
   delete_option(self::LOCK);
   if($uncertain)return new WP_Error('mad4b_agent_grant_compensation_uncertain',
    'Grant or approval ticket requires owner reconciliation; do not retry blindly.',
    array('reconciliation_required'=>true,'grant_id'=>$created_id,
     'ticket_id'=>$ticket_id,'blind_retry_allowed'=>false));
  }
 }
}
MAD4B_SCP_Agent_Grant_Lifecycle::boot();
