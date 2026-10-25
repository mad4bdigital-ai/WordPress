<?php
if (!defined('ABSPATH'))exit;
/** Trusts the original transport's verified identity, never headers supplied by a model. */
final class MAD4B_SCP_Background_Authorization {
 const CONTRACT='mad4b.background-auth-resolver.v1';
 const SUPPORTED=['oauth2_bearer','wp_application_password','mad4b_passwordless_bearer'];
 public static function current() {
  if(!class_exists('MAD4B_SCP_Identity_Context'))
   return new WP_Error('mad4b_background_auth_runtime_missing','Original identity bridge unavailable.');
  $identity=MAD4B_SCP_Identity_Context::current();
  if(is_wp_error($identity)||!is_array($identity)||empty($identity['authenticated']))
   return new WP_Error('mad4b_background_unauthenticated','A verified upstream identity is required.');
  $method=(string)($identity['auth_method']??'');
  if(!in_array($method,self::SUPPORTED,true))
   return new WP_Error('mad4b_background_unsupported_method','No reviewed verifier registered for this auth method.');
  if(!is_string($identity['subject_fingerprint']??null)
    || !preg_match('/^[a-f0-9]{64}$/D',$identity['subject_fingerprint']))
   return new WP_Error('mad4b_background_subject_missing','Stable SHA-256 subject binding required.');
  if(!class_exists('MAD4B_SCP_Site_Profile'))
   return new WP_Error('mad4b_background_site_profile_missing','Enrolled site authority unavailable.');
  $site_uuid=(string)MAD4B_SCP_Site_Profile::site_uuid();
  if(!preg_match('/^[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}$/Di',$site_uuid))
   return new WP_Error('mad4b_background_site_unbound','Enrolled site UUID is required.');
  if(!class_exists('MAD4B_SCP_Agent_Registry'))
   return new WP_Error('mad4b_background_agent_registry_missing','Agent registry unavailable.');
  $agent=MAD4B_SCP_Agent_Registry::resolve_agent($identity);
  if(is_wp_error($agent)||!is_array($agent)||($agent['status']??'')!=='enabled'
    ||empty($agent['public_id'])||(int)($agent['id']??0)<=0)
   return new WP_Error('mad4b_background_agent_not_enrolled','No enrolled enabled Agent for identity.');
  return ['contract'=>self::CONTRACT,'method'=>$method,
   'agent_public_id'=>$agent['public_id'], 'agent_id'=>(int)$agent['id'],
   'subject_fingerprint'=>$identity['subject_fingerprint'],
   'site_uuid'=>$site_uuid,
   'credential_exposed'=>false,'mutation_authorized'=>false];
 }
 public static function check_qa_grant($domain) {
  $who=self::current();if(is_wp_error($who))return $who;
  if(!is_string($domain)||!class_exists('MAD4B_SCP_Unified_Approval_Workflow')
    ||!in_array($domain,MAD4B_SCP_Unified_Approval_Workflow::DOMAINS,true))
   return new WP_Error('mad4b_background_domain_denied','Domain requires a reviewed original-owner adapter.');
  $ability='mad4b/ai-'.str_replace('_','-',$domain).'-review';
  $grant=MAD4B_SCP_Agent_Registry::exact_grant($who['agent_id'],'mad4b-write',$ability,'core');
  if(!is_array($grant)||($grant['effect']??'')!=='allow'
    ||!in_array(($grant['environment']??''),[MAD4B_SCP_Site_Profile::current_environment()],true))
   return new WP_Error('mad4b_background_exact_grant_missing','No exact environment-scoped QA entitlement.');
  return ['allowed'=>true,'identity'=>$who,'ability'=>$ability,
   'review_only'=>true,'publication_authorized'=>false];
 }
 public static function methods() {
  return ['ready'=>self::SUPPORTED,
   'needs_verified_provider'=>['api_key','service_account','signed_workload','mTLS'],
   'human_step_up_required'=>['interactive_browser_mfa','webauthn','provider_interactive_consent'],
   'secrets_in_model_context'=>false];
 }
}
/** Pending browser authorization is a handoff, never a workaround for MFA. */
final class MAD4B_SCP_Browser_Auth_Broker {
 const CONTRACT='mad4b.browser-authorization-broker.v1';
 const OPTION='mad4b_browser_auth_handoffs_v1';
 public static function plan($input) {
  $provider=(string)($input['provider']??'');
  $operation=(string)($input['operation']??'');
  $origin=(string)($input['origin']??'');
  if(!preg_match('/^[a-z0-9][a-z0-9_-]{2,60}$/D',$provider)
    ||!preg_match('/^[a-z0-9_-]{3,80}$/D',$operation)
    ||!preg_match('#^https://[a-zA-Z0-9.-]{4,170}(?::443)?/?$#D',$origin))
   return new WP_Error('mad4b_browser_auth_invalid_scope','Exact provider, operation, HTTPS origin required.');
  return ['contract'=>self::CONTRACT,'provider'=>$provider,'origin'=>$origin,
   'operation'=>$operation,'state'=>'WAITING_AUTH','human_interaction_required'=>true,
   'verified_browser_provider_available'=>false,
   'session_secrets_stored_in_wordpress'=>false,'agent_may_bypass_mfa'=>false,
   'execution_authorized'=>false,'publication_authorized'=>false];
 }
 // Installable broker provider is intentionally not bundled: security of session
 // storage/ownership and service-specific consent must be independently certified.
 public static function issue_session_from_model($input) {
  return new WP_Error('mad4b_browser_authorization_provider_required',
    'An independently verified browser broker and any required human login are necessary.');
 }
}
