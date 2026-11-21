<?php
if ( ! defined( 'ABSPATH' ) ) exit;
/**
 * Dynamic Search Provider Provisioning (all current and future SERP adapters).
 * A VERIFIED account is an enrollment event, not invented spend/rights evidence.
 * Auto-provisions an exact site/brand/language Search Profile; unfreezing
 * commercial spend requires independently verified authoritative provider data.
 */
final class MAD4B_SCP_Search_Provider_Autoprovision {
 const CONTRACT='mad4b.search-provider-autoprovision.v1';
 const KIND='provider_provision';
 private static $running=false;
 public static function boot(){
  add_action('mad4b_scp_search_provider_verified',array(__CLASS__,'on_verified'),15,2);
  add_action('mad4b_hub_bulk_as_tick',array(__CLASS__,'tick'),25,0);
  add_action('wp_abilities_api_init',array(__CLASS__,'abilities'),40);
  add_action('rest_api_init',array(__CLASS__,'routes'),40);
 }
 private static function error($code){return MAD4B_SCP_Search_Contracts::error('autoprovision_'.$code);}
 private static function write_allowed(){
  return MAD4B_SCP_Search_Runtime::can_configure() &&
   class_exists('MAD4B_SCP_Policy')&&MAD4B_SCP_Policy::can_mutate();
 }
 private static function id_for($id){
  return 'autop-'.substr(hash('sha256',MAD4B_SCP_Site_Profile::site_uuid().'|'.$id),0,24);
 }
 private static function provider($id){
  if(!MAD4B_SCP_Search_Contracts::id($id))return self::error('invalid_provider');
  $adapters=MAD4B_SCP_Search_Providers::adapters();
  return $adapters[$id]??self::error('provider_not_registered');
 }
 private static function known_account($id,$adapter){
  if($adapter instanceof MAD4B_SCP_Search_SERP_Enrollment){
   $status=MAD4B_SCP_Search_Provider_Connections::status($id);
   return is_wp_error($status)?$status:$status;
  }
  // External/custom adapters are dynamically discovered but a descriptor
  // alone does not produce an account-verified receipt.
  return array('connection_state'=>'EXTERNALLY_MANAGED',
   'revision'=>0,'configured'=>false,'authorizing'=>false);
 }
 private static function certified($d){
  $check=MAD4B_SCP_Search_Providers::descriptor_guard($d,time());
  return !is_wp_error($check);
 }
 private static function context_languages(){
  $langs=MAD4B_SCP_Search_Surfaces::languages();
  $active=array();
  if(is_array($langs))foreach($langs as $lang=>$info)
   if(is_array($info)&&!empty($info['active'])&&empty($info['conflicting'])
     &&preg_match('/^[a-z]{2,3}(?:-[a-z0-9]{2,8})*$/D',(string)$lang))
    $active[]=$lang;
  sort($active,SORT_STRING);
  return array_slice(array_values(array_unique($active)),0,64);
 }
 private static function profile_config($id,$d){
  $policy=MAD4B_SCP_Search_Context::policy();
  if(is_wp_error($policy))return $policy;
  $scope=MAD4B_SCP_Operational_Integrity::capture();
  if(is_wp_error($scope))return $scope;
  $brand=(string)($scope['scope']['brand_ref']??'');
  if(!MAD4B_SCP_Search_Contracts::id($brand))$brand='';
  $engines=array_values(array_filter((array)($d['capabilities']['engines']??array()),
    static fn($v)=>is_string($v)&&MAD4B_SCP_Search_Contracts::id($v)));
  $devices=array_values(array_filter((array)($d['capabilities']['devices']??array()),
    static fn($v)=>is_string($v)&&MAD4B_SCP_Search_Contracts::id($v)));
  if(!$devices)$devices=$policy['defaults']['provider_policy']['devices'];
  $depth=is_int($d['capabilities']['max_depth']??null)?
   min(100,max(1,$d['capabilities']['max_depth'])):$policy['defaults']['provider_policy']['depth'];
  return array(
   'profile_id'=>self::id_for($id),'brand_id'=>$brand,
   'enabled'=>false,'markets'=>array(),
   'language_policy'=>array('desired'=>self::context_languages()),
   'provider_policy'=>array('allowed'=>array($id),'disabled'=>array(),
      'engines'=>$engines,'devices'=>$devices,'depth'=>$depth,'freeze_spend'=>true),
   'objective'=>'coverage');
 }
 private static function exact_record($id){
  return MAD4B_SCP_Search_Store::read(self::KIND,$id);
 }
 private static function commit_report($id,$account,$profileId,$report){
  $prior=self::exact_record($id);
  if(is_wp_error($prior))return $prior;
  $data=array(
   'contract'=>self::CONTRACT,'provider_id'=>$id,
   'site_uuid'=>MAD4B_SCP_Site_Profile::site_uuid(),
   'profile_id'=>$profileId,'account_revision'=>(int)($account['revision']??0),
   'status'=>$report['status'],'capture_ready'=>(bool)$report['capture_ready'],
   'non_paid_operational'=>(bool)$report['non_paid_operational'],
   'pending'=>array_values(array_unique($report['pending'])),
   'observed_at'=>time(),'paid_search_invoked'=>false,
   'credentials_exposed'=>false,'source'=>'registered_search_provider_adapter',
   'local_discovery_sha256'=>(string)($report['local_discovery']['plan_sha256']??($prior['local_discovery_sha256']??'')),
   'local_discovery_count'=>(int)($report['local_discovery']['source_count']??($prior['local_discovery_count']??0)));
  // Refresh on a new state/revision; avoid periodic no-op audit churn.
  if(is_array($prior)){
   $unchanged=true;
   foreach($data as $key=>$value)
    if($key!=='observed_at' && ($prior[$key]??null)!==$value){$unchanged=false;break;}
   if($unchanged)return $prior;
  }
  return MAD4B_SCP_Search_Store::cas(self::KIND,$id,$prior,$data,'PROVIDER_AUTO_PROVISION_RECONCILED');
 }
 public static function inspect($input=array()){
  if(!current_user_can('manage_options')||!MAD4B_SCP_Search_Runtime::can_configure())
   return self::error('not_authorized');
  $id=(string)($input['provider_id']??'');
  $adapter=self::provider($id);if(is_wp_error($adapter))return $adapter;
  $account=self::known_account($id,$adapter);
  if(is_wp_error($account))return $account;
  $d=$adapter->descriptor();
  $profileId=self::id_for($id);
  $profile=MAD4B_SCP_Search_Context::profile($profileId);
  $profileExists=!is_wp_error($profile);
  $isVerified=($account['connection_state']??'')==='VERIFIED';
  $nativeCertified=self::certified($d);
  $budget=!empty($d['account_id'])?MAD4B_SCP_Search_Budgets::status($d['account_id']):null;
  $budgetValid=is_array($budget)
   &&isset($budget['reset_at'],$budget['remaining'])
   &&(int)$budget['reset_at']>time()
   &&(int)$budget['remaining']>0
   &&empty($budget['overrun_requires_reconciliation'])
   &&(!empty($budget['hard_global_enforcement'])||empty($d['shared_account'])
      ||!empty($d['local_allocation_certified']));
  $pending=array();
  if(!$isVerified){
   $pending[]=$adapter instanceof MAD4B_SCP_Search_SERP_Enrollment?
     'account_connection_requires_verified_receipt':'external_account_verification_required';
  }
  if(!$nativeCertified)$pending[]='native_provider_certification_and_rights_evidence_required';
  if(!$budgetValid)$pending[]='verified_account_budget_authority_required';
  if(!$profileExists)$pending[]='site_search_profile_bootstrap_pending';
  $languages=self::context_languages();
  if(!$languages)$pending[]='active_languages_unknown';
  // A market is a commercial targeting decision. Do not infer from site address.
  $mappedMarkets=false;
  if($profileExists)foreach((array)($profile['markets']??array()) as $market)
    if(is_array($market)&&preg_match('/^[A-Z]{2}$/D',(string)($market['country']??''))
       &&isset($market['provider_locations'][$id]['id'],$market['provider_locations'][$id]['precision'])){
       $mappedMarkets=true;break;
    }
  if(!$mappedMarkets)$pending[]='source_verified_market_and_provider_location_required';
  $budgetPolicyReady=$profileExists&&!empty($profile['budget_policy']['nodes']);
  if(!$budgetPolicyReady)$pending[]='configured_profile_budget_nodes_required';
  if(!$profileExists||empty($profile['enabled']))$pending[]='local_search_discovery_not_resumed';
  $commercialEvidenceReady=$isVerified&&$nativeCertified&&$budgetValid
   &&$profileExists&&$mappedMarkets&&$budgetPolicyReady;
  $canCapture=$commercialEvidenceReady&&!empty($profile['enabled'])
   &&empty($profile['provider_policy']['freeze_spend']);
  return array('contract'=>self::CONTRACT,'provider_id'=>$id,
   'registered_adapter'=>get_class($adapter),
   'site_uuid'=>MAD4B_SCP_Site_Profile::site_uuid(),
   'account_connection_state'=>$account['connection_state'],
   'account_revision'=>(int)($account['revision']??0),
   'native_certification_verified'=>$nativeCertified,
   'budget_authority_verified'=>$budgetValid,
   'commercial_evidence_ready'=>$commercialEvidenceReady,
   'provider_profile_id'=>$profileId,'profile_created'=>$profileExists,
   'non_paid_operational'=>$profileExists&&!empty($profile['enabled'])
    &&!empty($profile['provider_policy']['freeze_spend']),
   'capture_ready'=>$canCapture,'pending'=>array_values(array_unique($pending)),
   'paid_search_invoked'=>false,'needs_explicit_model_api_key'=>false);
 }
 public static function reconcile($input=array()){
  if(!self::write_allowed())return self::error('write_denied');
  if(self::$running)return self::error('reentrant');
  $id=(string)($input['provider_id']??'');
  $adapter=self::provider($id);if(is_wp_error($adapter))return $adapter;
  self::$running=true;
  try{
   $initial=self::inspect(array('provider_id'=>$id));
   if(is_wp_error($initial))return $initial;
   if($initial['account_connection_state']!=='VERIFIED'){
    // A configured adapter with stale receipt remains tracked without
    // creating a provider claim or enabling new spending.
    return self::report($id,$initial,false);
   }
   $d=$adapter->descriptor();
   $pid=self::id_for($id);
   if(!$initial['profile_created']){
    $raw=self::profile_config($id,$d);
    if(is_wp_error($raw))return $raw;
    $plan=MAD4B_SCP_Search_Context::plan(array('profile'=>$raw,'expected_revision'=>0));
    if(is_wp_error($plan))return $plan;
    $created=MAD4B_SCP_Search_Context::apply(array('profile'=>$raw,
       'expected_revision'=>0,'plan_sha256'=>$plan['plan_sha256']));
    if(is_wp_error($created)){
     $existing=MAD4B_SCP_Search_Context::profile($pid);
     if(is_wp_error($existing))return $created;
    }
   }
   $profile=MAD4B_SCP_Search_Context::profile($pid);
   if(is_wp_error($profile))return $profile;
   // Observing owned site surfaces without provider spend is safe.
   if(empty($profile['enabled'])&&!empty($profile['provider_policy']['freeze_spend'])){
     $res=MAD4B_SCP_Search_Context::control_transition(array(
        'profile_id'=>$pid,'control'=>'resume',
        'expected_revision'=>(int)$profile['revision']));
     if(is_wp_error($res)){
      $current=MAD4B_SCP_Search_Context::profile($pid);
      if(is_wp_error($current)||empty($current['enabled']))return $res;
     }
   }
   $latest=self::inspect(array('provider_id'=>$id));
   if(is_wp_error($latest))return $latest;
   // Once the native provider contract, rights, mapped target, and approved
   // budget all exist, switch to paid mode through the normal typed control.
   // No certification, market or budget is ever synthesized from VERIFIED.
   if(!empty($latest['commercial_evidence_ready'])
      &&!empty($profile['provider_policy']['freeze_spend'])){
     $admitted=MAD4B_SCP_Search_Context::control_transition(array(
       'profile_id'=>$pid,'control'=>'unfreeze_spend',
       'expected_revision'=>(int)$profile['revision']));
     if(is_wp_error($admitted))
      $latest['pending'][]='commercial_activation_control_retry_required';
     else
      $latest=self::inspect(array('provider_id'=>$id));
   }
   $local=self::owned_discovery_once($pid,$id);
   if(is_wp_error($local)){
    $latest['pending'][]='local_site_discovery_retry_required';
    $latest['discovery_error_code']=$local->get_error_code();
    $local=array();
   }
   $reported=self::report($id,$latest,true,$local);
   return $reported;
  }finally{self::$running=false;}
 }
 private static function report($id,$status,$changed,$local=array()){
  $report=array(
   'status'=>$status['capture_ready']?'READY':
     ($status['non_paid_operational']?'NON_PAID_ACTIVE':'VERIFYING'),
   'capture_ready'=>$status['capture_ready'],
   'non_paid_operational'=>$status['non_paid_operational'],
   'pending'=>$status['pending'],'local_discovery'=>$local);
  $stored=self::commit_report($id,$status,$status['provider_profile_id'],$report);
  if(is_wp_error($stored))return $stored;
  return array_merge($status,array(
   'lifecycle_state'=>$report['status'],
   'auto_reconciled'=>true,'profile_was_updated'=>$changed,
   'record_revision'=>$stored['_revision']??null,
   'owned_surfaces_indexed'=>(int)($stored['local_discovery_count']??0),
   'discovery_replayed'=>(bool)($local['replayed']??false),
   'paid_search_invoked'=>false));
 }
 private static function recheck_expired_account($id,$adapter){
  // Only the existing Staging WP-CLI service user may renew an *account*
  // observation. Never a paid SERP request or credential rotation.
  if(!defined('WP_CLI')||!WP_CLI||PHP_SAPI!=='cli'
   ||!self::write_allowed()
   ||!$adapter instanceof MAD4B_SCP_Search_SERP_Enrollment)
   return array('attempted'=>false);
  $state=self::known_account($id,$adapter);
  if(is_wp_error($state)||empty($state['configured'])
    ||!in_array($state['connection_state'],
        array('CHECK_EXPIRED','CHECK_FAILED','NOT_CHECKED'),true))
   return array('attempted'=>false);
  $key='mad4b_asi_account_recheck_'.substr(hash('sha256',
    MAD4B_SCP_Site_Profile::site_uuid().'|'.$id),0,28);
  if(get_transient($key))return array('attempted'=>false,'retry_throttled'=>true);
  set_transient($key,1,600);
  $updated=MAD4B_SCP_Search_Provider_Connections::apply(array(
    'provider_id'=>$id,'operation'=>'test',
    'expected_revision'=>(int)$state['revision']));
  return is_wp_error($updated)?array(
    'attempted'=>true,'verified'=>false,'error'=>$updated->get_error_code()):
    array('attempted'=>true,
      'verified'=>($updated['connection_state']??'')==='VERIFIED');
 }
 public static function on_verified($id,$revision=0){
  if(!is_string($id)||!self::write_allowed()||self::$running)return;
  $out=self::reconcile(array('provider_id'=>$id));
  if(is_wp_error($out))error_log('[MAD4B Provider AutoProvision] '.$id.' '.$out->get_error_code());
 }
 public static function tick(){
  if(!defined('WP_CLI')||!WP_CLI||PHP_SAPI!=='cli'||!self::write_allowed())
   return;
  self::reconcile_all();
 }
 /** Dynamic cross-family inventory. Native OAuth/custom adapters keep their
  * own consent and capability-specific execution authority. */
 private static function other_registered(){
  $out=array();
  if(!class_exists('MAD4B_SCP_Provider_Connection_Center'))return $out;
  $discovered=MAD4B_SCP_Provider_Connection_Center::discover();
  if(is_wp_error($discovered))return $out;
  foreach((array)($discovered['providers']??array()) as $p){
   if(!is_array($p)||empty($p['provider']))continue;
   $id=(string)$p['provider'];
   $plan=MAD4B_SCP_Provider_Connection_Center::plan(array('provider'=>$id));
   $out[$id]=array(
     'provider_id'=>$id,'type'=>$p['kind']??'other',
     'connected'=>!empty($p['connected']),
     'health_state'=>$p['health_state']??'unknown',
     'next_action'=>is_wp_error($plan)?'adapter_plan_unavailable':($plan['action']??'none'),
     'automatic_readiness'=>!empty($p['connected'])&&empty($p['revocation_pending'])
       &&empty($p['reconnect_required'])&&empty($p['refresh_required']),
     'native_adapter_authority_preserved'=>true,
     'oauth_consent_forged'=>false,'mutation_performed'=>false);
  }
  return $out;
 }
 private static function owned_discovery_once($pid,$id){
  $prior=self::exact_record($id);
  if(is_wp_error($prior))return $prior;
  $plan=MAD4B_SCP_Search_Runtime::compile_plan(array('profile_id'=>$pid,'candidates'=>array()));
  if(is_wp_error($plan))return $plan;
  $hash=(string)($plan['plan_sha256']??'');
  if(!MAD4B_SCP_Search_Contracts::sha($hash))return self::error('discovery_plan_invalid');
  if(is_array($prior)&&($prior['local_discovery_sha256']??'')===$hash)
   return array('source_count'=>(int)($prior['local_discovery_count']??0),
     'replayed'=>true,'plan_sha256'=>$hash);
  $result=MAD4B_SCP_Search_Runtime::compile_apply(array(
    'profile_id'=>$pid,'candidates'=>array(),'plan_sha256'=>$hash));
  if(is_wp_error($result))return $result;
  $count=count($plan['surfaces']??array());
  return array('source_count'=>$count,'replayed'=>false,'plan_sha256'=>$hash);
 }
 public static function reconcile_all($input=array()){
  if(!self::write_allowed())return self::error('write_denied');
  $rows=array();$adapters=MAD4B_SCP_Search_Providers::adapters();
  foreach(array_slice($adapters,0,30,true) as $id=>$adapter){
   try{
    $renew=self::recheck_expired_account($id,$adapter);
    $x=self::reconcile(array('provider_id'=>$id));
    $rows[$id]=is_wp_error($x)?array('state'=>'ERROR','error'=>$x->get_error_code()):
        array('state'=>$x['lifecycle_state'],'pending'=>$x['pending'],
           'profile_id'=>$x['provider_profile_id'],
           'capture_ready'=>$x['capture_ready'],
           'owned_surfaces_indexed'=>(int)($x['owned_surfaces_indexed']??0),
           'account_auto_recheck'=>$renew);
   }catch(Throwable $e){$rows[$id]=array('state'=>'ERROR',
      'error'=>'adapter_reconciliation_exception');}
  }
  $native=self::other_registered();
  return array('contract'=>self::CONTRACT,'providers'=>$rows,
    'registered_count'=>count($adapters),'native_providers'=>$native,
    'native_registered_count'=>count($native),
    'all_registered_count'=>count($adapters)+count($native),
    'spent_searches'=>0);
 }
 public static function inventory($input=array()){
  if(!current_user_can('manage_options'))return self::error('read_denied');
  $rows=array();
  foreach(MAD4B_SCP_Search_Providers::adapters() as $id=>$adapter){
   $s=self::inspect(array('provider_id'=>$id));
   $rows[$id]=is_wp_error($s)?array('error'=>$s->get_error_code()):$s;
  }
  $native=self::other_registered();
  $plugins=class_exists('MAD4B_SCP_Plugin_Discovery')
    ? MAD4B_SCP_Plugin_Discovery::provider_candidate_matrix():array();
  return array('contract'=>self::CONTRACT,'providers'=>$rows,
   'native_providers'=>$native,'native_registered_count'=>count($native),
   'discovered_plugin_candidates'=>is_array($plugins)?(int)($plugins['count']??0):0,
   'plugin_candidates_discovery_only'=>true,
   'count'=>count($rows)+count($native),'paid_search_invoked'=>false);
 }
 public static function abilities(){
  if(!function_exists('wp_register_ability'))return;
  foreach(array('inventory'=>false,'reconcile'=>true,'reconcile_all'=>true) as $name=>$write){
   $ability='mad4b/search-provider-autoprovision-'.str_replace('_','-',$name);
   if(wp_has_ability($ability))continue;
   wp_register_ability($ability,array(
    'label'=>'Dynamic Search Provider AutoProvision '.ucwords(str_replace('_',' ',$name)),
    'description'=>'Dynamically reconcile registered provider adapters from verified account evidence; never authorize paid searches without source-backed certification.',
    'category'=>$write?'mad4b-write':'mad4b-read',
    'execute_callback'=>array(__CLASS__,$name),
    'permission_callback'=>static fn()=>$write?self::write_allowed():current_user_can('manage_options'),
    'input_schema'=>array('type'=>'object','properties'=>array(
     'provider_id'=>array('type'=>'string')),'additionalProperties'=>false),
    'output_schema'=>array('type'=>'object','additionalProperties'=>true),
    'meta'=>array('public'=>false,'show_in_rest'=>false,
      'mcp'=>array('public'=>false,'type'=>'tool','surface'=>$write?'write':'read'),
      'annotations'=>array('readonly'=>!$write,'destructive'=>false,'idempotent'=>true))
   ));
  }
 }
 public static function routes(){
  register_rest_route('mad4b-search/v1','/provider-autoprovision',array(
   'methods'=>'GET',
   'permission_callback'=>static fn()=>current_user_can('manage_options'),
   'callback'=>static fn($request)=>self::inventory($request->get_params())));
 }
}
