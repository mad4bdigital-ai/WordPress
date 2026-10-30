<?php
/**
 * Plugin Name: MAD4B Company Plugin Sync — Central Operation Bridge
 * Description: Company-scoped plan and durable queue/readback using existing Control Plane operation journal. Never copies files or executes tenant mutations.
 * Version: 0.1.0
 */
defined('ABSPATH') || exit;
final class MAD4B_Company_Plugin_Sync_Operation {
 const PLAN_STORE='mad4b_company_plugin_sync_plan_v1';
 const JOB_MAP='mad4b_company_plugin_sync_jobs_v1';
 const CONTRACT='mad4b.company-plugin-sync-operation.v1';
 const NS='mad4b/v1';
 const ROOT='/company-provisioning/companies';
 public static function boot(){
  add_action('rest_api_init',array(__CLASS__,'routes'),90);
  add_action('wp_abilities_api_init',array(__CLASS__,'abilities'),85);
 }
 private static function central(){
  return wp_get_environment_type()==='staging' && defined('DB_NAME')
   && DB_NAME==='etg_commerce_staging'
   && wp_parse_url(home_url(),PHP_URL_HOST)==='tours.dev.mad4b.com';
 }
 public static function guard($request){
  if(!self::central())return new WP_Error('mad4b_company_sync_wrong_host','Central staging only',array('status'=>403));
  if(!class_exists('MAD4B_Company_Readiness_Governance'))
   return new WP_Error('mad4b_company_scope_missing','Company scope guard unavailable',array('status'=>503));
  return MAD4B_Company_Readiness_Governance::guard($request);
 }
 private static function company($request){
  $permit=self::guard($request);
  if(is_wp_error($permit))return $permit;
  $code=sanitize_key((string)$request->get_param('company'));
  if(!preg_match('/^[a-z][a-z0-9-]{2,47}$/D',$code))return new WP_Error('mad4b_company_code_invalid','Invalid company',array('status'=>422));
  $registry=get_option('mad4b_tourism_company_site_registry_v1',array());
  $row=is_array($registry)?($registry[$code]??null):null;
  if(!is_array($row)||empty($row['host_binding_verified'])||!wp_is_uuid((string)($row['company_site_uuid']??'')))
   return new WP_Error('mad4b_company_unbound','Missing company Site Profile binding',array('status'=>409));
  return array('code'=>$code,'site_uuid'=>$row['company_site_uuid']);
 }
 private static function plan_record($code,$site_uuid){
  $all=get_option(self::PLAN_STORE,array());
  $rec=is_array($all)?($all[$code]??null):null;
  if(!is_array($rec) || ($rec['contract']??'')!=='mad4b.company-plugin-sync-plan.v1'
     || ($rec['company']??'')!==$code || ($rec['site_uuid']??'')!==$site_uuid
     || !preg_match('/^[a-f0-9]{64}$/D',(string)($rec['plan_hash']??'')))
   return new WP_Error('mad4b_company_sync_plan_missing','Fresh scoped VPS plan has not been imported',array('status'=>503));
  $age=time()-strtotime((string)($rec['generated_at']??''));
  if($age<0||$age>3600)return new WP_Error('mad4b_company_sync_plan_stale','Plugin plan requires fresh inventory',array('status'=>409));
  return $rec;
 }
 private static function reply($data,$status=200){
  $r=new WP_REST_Response($data,$status);
  $r->header('Cache-Control','private, no-store');
  return $r;
 }
 public static function routes(){
  $pattern=self::ROOT.'/(?P<company>[a-z][a-z0-9-]{2,47})';
  register_rest_route(self::NS,$pattern.'/plan',array('methods'=>'GET','callback'=>array(__CLASS__,'plan'),'permission_callback'=>array(__CLASS__,'guard')));
  register_rest_route(self::NS,$pattern.'/jobs',array('methods'=>'POST','callback'=>array(__CLASS__,'request_job'),'permission_callback'=>array(__CLASS__,'guard')));
  register_rest_route(self::NS,$pattern.'/jobs/(?P<operation_id>[0-9a-f-]{36})',array('methods'=>'GET','callback'=>array(__CLASS__,'job'),'permission_callback'=>array(__CLASS__,'guard')));
 }
 public static function plan($request){
  $company=self::company($request);if(is_wp_error($company))return $company;
  $rec=self::plan_record($company['code'],$company['site_uuid']);
  if(is_wp_error($rec))return $rec;
  return self::reply(array('contract'=>self::CONTRACT,'company'=>$company['code'],'site_uuid'=>$company['site_uuid'],
   'plan_hash'=>$rec['plan_hash'],'source_plugin_count'=>(int)($rec['source_plugin_count']??0),
   'copy_missing'=>(int)($rec['copy_missing']??0),'activation_candidates'=>(int)($rec['activation_candidates']??0),
   'generated_at'=>$rec['generated_at'],'isolated_test_pass'=>false,'production_mutation_authorized'=>false));
 }
 public static function request_job($request){
  $company=self::company($request);if(is_wp_error($company))return $company;
  $rec=self::plan_record($company['code'],$company['site_uuid']);if(is_wp_error($rec))return $rec;
  // Company creation requires a real owner email, never a placeholder.
  if(!class_exists('MAD4B_Company_Bootstrap_Contract'))
   return new WP_Error('mad4b_company_bootstrap_unavailable','Bootstrap contract unavailable',['status'=>503]);
  $bootstrap=MAD4B_Company_Bootstrap_Contract::record($company['code']);
  if(is_wp_error($bootstrap))return $bootstrap;
  if(empty($bootstrap['owner_email_set']))
   return new WP_Error('mad4b_company_owner_email_required','Company owner email is mandatory before provisioning begins',['status'=>422]);
  $input=$request->get_json_params();
  if(!is_array($input))$input=$request->get_params();
  if(!is_array($input) || !is_string($input['plan_hash']??null) || !hash_equals($rec['plan_hash'],$input['plan_hash']))
   return new WP_Error('mad4b_company_plan_hash_mismatch','Exact current plan hash required',array('status'=>409));
  if(!class_exists('MAD4B_SCP_Operation_Journal')||!class_exists('MAD4B_SCP_Schema'))
   return new WP_Error('mad4b_central_operation_journal_missing','Central Journal unavailable',array('status'=>503));
  global $wpdb;
  $lock='mad4b:company-plugin-sync:'.$company['code'];
  if((int)$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s,3)',$lock))!==1)
   return new WP_Error('mad4b_company_sync_job_busy','Job queue busy',array('status'=>423));
  try {
   $all=get_option(self::JOB_MAP,array());
   if(!is_array($all))return new WP_Error('mad4b_company_sync_job_store_invalid','Job map invalid',array('status'=>503));
   $existing=$all[$company['code']][$rec['plan_hash']]??null;
   if(is_string($existing) && wp_is_uuid($existing)){
    $read=MAD4B_SCP_Operation_Journal::status($existing);
    if(!is_wp_error($read))return self::reply(array('state'=>'ALREADY_QUEUED','operation_id'=>$existing,'journal'=>$read,'production_mutation_authorized'=>false));
    return new WP_Error('mad4b_company_sync_reconciliation_required','Existing job requires journal reconciliation',array('status'=>409));
   }
   $id=wp_generate_uuid4();
   $ctx=array('operation_id'=>$id,'operation_key'=>'company.plugin-sync:'.$company['code'].':'.substr($rec['plan_hash'],0,32),
    'operation_binding_sha256'=>hash('sha256',self::CONTRACT.'|'.$company['site_uuid'].'|'.$rec['plan_hash']),
    'hard_deadline_at'=>gmdate('c',time()+1800));
   $started=MAD4B_SCP_Operation_Journal::begin($ctx,'planned',array('company'=>$company['code'],
    'site_uuid'=>$company['site_uuid'],'plan_hash'=>$rec['plan_hash'],'operator_id'=>get_current_user_id(),
    'isolated_test_pass'=>false,'company_production_mutated'=>false));
   if(is_wp_error($started))return $started;
   $all[$company['code']][$rec['plan_hash']]=$id;
   if(!update_option(self::JOB_MAP,$all,false)){
    return new WP_Error('mad4b_company_sync_job_map_unconfirmed','Journal job created but mapping readback uncertain; reconcile',array('status'=>503));
   }
   $confirmed=get_option(self::JOB_MAP,array());
   if(($confirmed[$company['code']][$rec['plan_hash']]??'')!==$id)
    return new WP_Error('mad4b_company_sync_job_readback_failed','Job mapping readback failed',array('status'=>503));
   return self::reply(array('contract'=>self::CONTRACT,'state'=>'PLANNED_NOT_EXECUTED','operation_id'=>$id,
    'journal'=>$started,'company'=>$company['code'],'plan_hash'=>$rec['plan_hash'],
    'production_mutation_authorized'=>false),201);
  } finally {
   $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)',$lock));
  }
 }
 public static function job($request){
  $company=self::company($request);if(is_wp_error($company))return $company;
  $id=(string)$request->get_param('operation_id');
  $jobs=get_option(self::JOB_MAP,array());
  $mine=is_array($jobs)?($jobs[$company['code']]??array()):array();
  if(!is_array($mine)||!in_array($id,$mine,true))
   return new WP_Error('mad4b_company_sync_job_not_found','Job not assigned to company',array('status'=>404));
  if(!class_exists('MAD4B_SCP_Operation_Journal'))
   return new WP_Error('mad4b_operation_journal_missing','Journal missing',array('status'=>503));
  $status=MAD4B_SCP_Operation_Journal::status($id);
  if(is_wp_error($status))return $status;
  return self::reply(array('contract'=>self::CONTRACT,'company'=>$company['code'],'journal'=>$status,
    'production_mutation_authorized'=>false));
 }
 public static function ability_guard($input){
  $r=new WP_REST_Request('GET','/');
  if(is_array($input)&&isset($input['company']))$r->set_param('company',$input['company']);
  return self::guard($r);
 }
 public static function abilities(){
  if(!self::central()||!function_exists('wp_register_ability'))return;
  foreach(array(
   'mad4b/company-plugin-sync-plan'=>array('method'=>'plan','read'=>true),
   'mad4b/company-plugin-sync-request'=>array('method'=>'request_job','read'=>false),
   'mad4b/company-plugin-sync-job'=>array('method'=>'job','read'=>true)
  ) as $name=>$def){
   if(function_exists('wp_has_ability')&&wp_has_ability($name))continue;
   $props=array('company'=>array('type'=>'string','minLength'=>3,'maxLength'=>48));
   if('request_job'===$def['method'])$props['plan_hash']=array('type'=>'string','pattern'=>'^[a-f0-9]{64}$');
   if('job'===$def['method'])$props['operation_id']=array('type'=>'string','format'=>'uuid');
   $required=array_keys($props);
   wp_register_ability($name,array('label'=>'Company Plugin Sync '.str_replace('_',' ',$def['method']),
    'description'=>'Company-scoped central plan or operation journal; no tenant execution authority',
    'category'=>$def['read']?'mad4b-read':'mad4b-write',
    'input_schema'=>array('type'=>'object','properties'=>$props,'required'=>$required,'additionalProperties'=>false),
    'output_schema'=>array('type'=>'object','additionalProperties'=>true),
    'permission_callback'=>array(__CLASS__,'ability_guard'),
    'execute_callback'=>static function($input)use($def){
     $method=$def['read']?'GET':'POST';$req=new WP_REST_Request($method,'/');
     foreach((array)$input as $key=>$value)$req->set_param($key,$value);
     if('POST'===$method){$req->set_header('Content-Type','application/json');$req->set_body(wp_json_encode($input));}
     $out=call_user_func(array(__CLASS__,$def['method']),$req);
     return $out instanceof WP_REST_Response ? $out->get_data() : $out;
    },
    'meta'=>array('show_in_rest'=>false,'public'=>false,
     'annotations'=>array('readonly'=>$def['read'],'destructive'=>false,'idempotent'=>true),
     'mcp'=>array('public'=>false,'type'=>'tool','surface'=>$def['read']?'read':'write'))
   ));
  }
 }
}
MAD4B_Company_Plugin_Sync_Operation::boot();
