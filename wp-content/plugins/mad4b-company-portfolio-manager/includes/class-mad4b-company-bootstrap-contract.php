<?php
/**
 * Plugin Name: MAD4B Company Bootstrap Contract
 * Description: Company owner email is mandatory before provisioning; no default owner, password or automatic tenant activation.
 * Version: 1.0.0
 */
defined('ABSPATH') || exit;
final class MAD4B_Company_Bootstrap_Contract {
 const STORE='mad4b_company_bootstrap_registry_v1';
 const NS='mad4b/v1';
 const ROUTE='/company-provisioning/companies/(?P<company>[a-z][a-z0-9-]{2,47})/bootstrap';
 public static function boot(){
  add_action('rest_api_init',array(__CLASS__,'routes'),91);
  add_action('wp_abilities_api_init',array(__CLASS__,'abilities'),91);
 }
 public static function guard($req){
  return class_exists('MAD4B_Company_Readiness_Governance')
   ? MAD4B_Company_Readiness_Governance::guard($req)
   : new WP_Error('mad4b_bootstrap_guard_unavailable','Company guard unavailable',['status'=>503]);
 }
 public static function record($company){
  $registry=get_option('mad4b_tourism_company_site_registry_v1',[]);
  $bound=is_array($registry)?($registry[$company]??null):null;
  if(!is_array($bound)||empty($bound['host_binding_verified']))
   return new WP_Error('mad4b_bootstrap_company_unbound','Site Profile missing',['status'=>409]);
  $site_uuid=(string)($bound['company_site_uuid']??'');
  if(!wp_is_uuid($site_uuid))
   return new WP_Error('mad4b_bootstrap_site_uuid_missing','Site UUID missing',['status'=>409]);
  $unique=MAD4B_Company_Portfolio_Manager_V1::unique_company_site_identity((string)$company,$site_uuid,(string)($bound['native_site_profile_uuid']??''),is_array($registry)?$registry:[]);
  if(is_wp_error($unique))return $unique;
  $all=get_option(self::STORE,[]);
  $row=is_array($all)?($all[$company]??null):null;
  $owner=is_array($row)?sanitize_email((string)($row['owner_email']??'')):'';
  $valid=$owner!=='' && is_email($owner) && ($row['site_uuid']??'')===$site_uuid;
  return ['contract'=>'mad4b.company-bootstrap.v1','company'=>$company,'site_uuid'=>$site_uuid,
    'revision'=>(int)($row['revision']??0),'owner_email_set'=>(bool)$valid,
    'owner_email'=>$valid?$owner:null,
    'administrator_source'=>'source_site_admin_email','administrator_import_state'=>(string)($row['administrator_import_state']??'pending'),
    'owner_creation_state'=>(string)($row['owner_creation_state']??'pending'),
    'owner_password_setup_state'=>(string)($row['owner_password_setup_state']??'pending'),
    'missing_required_fields'=>$valid?[]:['owner_email'],
    'provisioning_start_allowed'=>(bool)$valid,
    'company_ready'=>false, 'commercial_distribution_allowed'=>false];
 }
 /** Preserve an existing company's owner email across a signed site cutover.
  * Site identity changes; owner/passwords/permissions do not.
  */
 public static function rebind_existing_owner_after_site_replacement($company){
  $company=sanitize_key((string)$company);
  if(!defined('WP_CLI')||!WP_CLI||!current_user_can('manage_options'))
   return new WP_Error('bootstrap_original_operator_required');
  $site=self::record($company);
  if(is_wp_error($site))return $site;
  $new=$site['site_uuid'];
  $all=get_option(self::STORE,[]);
  $old=is_array($all)?($all[$company]??null):null;
  if(!is_array($old)||!is_email((string)($old['owner_email']??'')))
   return new WP_Error('bootstrap_existing_owner_email_required');
  if(($old['site_uuid']??null)===$new)return [
   'state'=>'OWNER_BOOTSTRAP_CURRENT','site_uuid'=>$new,
   'revision'=>(int)$old['revision'],'owner_email_set'=>true];
  $rep=class_exists('MAD4B_Company_Site_Replacement_V1',false)
   ?(MAD4B_Company_Site_Replacement_V1::records()[$company]??null):null;
  $history=is_array($rep)?($rep['history']??[]):[];
  $oldUuid=(string)($old['site_uuid']??'');$historyVerified=false;
  foreach((array)$history as $past){
   if(is_array($past)&&($past['site_uuid']??null)===$oldUuid
      &&($past['authority_inherited']??true)===false){$historyVerified=true;break;}
  }
  $native=class_exists('MAD4B_Company_Native_WPCLI_Enrollment_V1',false)
   ?MAD4B_Company_Native_WPCLI_Enrollment_V1::readback($company,'production'):[];
  $host=class_exists('MAD4B_Company_Native_Host_Proof_V1',false)
   ?MAD4B_Company_Native_Host_Proof_V1::status($company,'production'):[];
  if(!wp_is_uuid($oldUuid)||!$historyVerified
   ||!is_array($rep)||($rep['company_id']??null)!==$company
   ||($rep['environment']??null)!=='production'
   ||($rep['site_uuid']??null)!==$new
   ||($rep['native_site_profile_uuid']??null)!==$new
   ||!in_array($rep['state']??'', ['PENDING_NATIVE_305','PENDING_RECONCILE'],true)
   ||($native['state']??null)!=='ORIGINAL_305_PERSISTENT_PAIR_ENROLLED'
   ||($native['site_uuid']??null)!==$new
   ||($host['state']??null)!=='HOST_PROOF_VERIFIED_NATIVE_EXECUTOR_PENDING'
   ||($host['verified']??false)!==true)
    return new WP_Error('bootstrap_verified_replacement_required');
  global $wpdb;$lock='mad4b:bootstrap:'.$company;
  if((int)$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s,3)',$lock))!==1)
   return new WP_Error('bootstrap_lock_busy');
  try{
   $current=get_option(self::STORE,[]);
   if(!is_array($current)||($current[$company]??null)!==$old)
    return new WP_Error('bootstrap_revision_conflict');
   $updated=$old;
   $updated['site_uuid']=$new;
   $updated['revision']=(int)($old['revision']??0)+1;
   $updated['rebound_from_site_uuid']=$oldUuid;
   $updated['identity_rebound_at']=gmdate('c');
   $updated['authority_inherited']=false;
   $updated['updated_utc']=gmdate('c');
   $current[$company]=$updated;
   if(!update_option(self::STORE,$current,false))
    return new WP_Error('bootstrap_update_failed');
   $read=self::record($company);
   if(is_wp_error($read)||!$read['owner_email_set']||$read['revision']!==$updated['revision'])
    return new WP_Error('bootstrap_readback_failed');
   return ['state'=>'OWNER_BOOTSTRAP_REBOUND_VERIFIED',
    'site_uuid'=>$new,'old_site_uuid'=>$oldUuid,
    'revision'=>$read['revision'],'owner_email_set'=>true,
    'owner_password_changed'=>false,'authority_inherited'=>false];
  }finally{$wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)',$lock));}
 }
 public static function status($req){
  $guard=self::guard($req);if(is_wp_error($guard))return $guard;
  $code=sanitize_key((string)$req->get_param('company'));
  $row=self::record($code);if(is_wp_error($row))return $row;
  $resp=rest_ensure_response($row);$resp->header('Cache-Control','private, no-store');return $resp;
 }
 public static function set_owner($req){
  $guard=self::guard($req);if(is_wp_error($guard))return $guard;
  if(!current_user_can('manage_options')||!current_user_can('activate_plugins'))
   return new WP_Error('mad4b_bootstrap_admin_required','Platform admin required',['status'=>403]);
  $code=sanitize_key((string)$req->get_param('company'));
  $before=self::record($code);if(is_wp_error($before))return $before;
  $body=$req->get_json_params();
  if(!is_array($body))return new WP_Error('mad4b_bootstrap_payload_required','JSON object required',['status'=>422]);
  $mail=strtolower(trim((string)($body['owner_email']??'')));
  if(!is_email($mail) || preg_match('/@(example\\.com|example\\.invalid|invalid\\.example|placeholder\\.test)$/i',$mail))
   return new WP_Error('mad4b_bootstrap_owner_email_required','Valid company owner email is mandatory',['status'=>422]);
  if(strcasecmp($mail,(string)get_option('admin_email'))===0)
   return new WP_Error('mad4b_bootstrap_identity_collision','Company owner must be distinct from the source site Administrator',['status'=>422]);
  $expected=$body['expected_revision']??null;
  if(!is_int($expected)||$expected!==$before['revision'])
   return new WP_Error('mad4b_bootstrap_revision_conflict','Exact expected revision required',['status'=>409]);
  global $wpdb;
  $lock='mad4b:bootstrap:'.$code;
  if((int)$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s,3)',$lock))!==1)
   return new WP_Error('mad4b_bootstrap_busy','Company bootstrap busy',['status'=>423]);
  try {
   $latest=self::record($code);
   if(is_wp_error($latest)||$latest['revision']!==$expected)
    return new WP_Error('mad4b_bootstrap_revision_conflict','Bootstrap was updated',['status'=>409]);
   $all=get_option(self::STORE,[]);
   if(!is_array($all))return new WP_Error('mad4b_bootstrap_store_invalid','Bootstrap store invalid',['status'=>503]);
   $all[$code]=['contract'=>'mad4b.company-bootstrap.v1','site_uuid'=>$before['site_uuid'],
     'owner_email'=>$mail,'revision'=>$expected+1,
     'administrator_import_state'=>'pending','owner_creation_state'=>'pending',
     'owner_password_setup_state'=>'pending',
     'updated_utc'=>gmdate('c'),'operator_id'=>get_current_user_id()];
   if(!update_option(self::STORE,$all,false))
    return new WP_Error('mad4b_bootstrap_store_failure','Cannot save owner email',['status'=>503]);
   $check=self::record($code);
   if(is_wp_error($check)||$check['revision']!==$expected+1||!$check['owner_email_set'])
    return new WP_Error('mad4b_bootstrap_readback_failed','Bootstrap not confirmed',['status'=>503]);
   return rest_ensure_response(['state'=>'OWNER_EMAIL_RECORDED_BOOTSTRAP_PENDING','company'=>$code,'site_uuid'=>$before['site_uuid'],
     'revision'=>$check['revision'],'provisioning_start_allowed'=>true,'company_ready'=>false]);
  } finally { $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)',$lock)); }
 }
 public static function routes(){
  register_rest_route(self::NS,self::ROUTE,[
   ['methods'=>'GET','callback'=>[__CLASS__,'status'],'permission_callback'=>[__CLASS__,'guard']],
   ['methods'=>'POST','callback'=>[__CLASS__,'set_owner'],'permission_callback'=>[__CLASS__,'guard']]
  ]);
 }
 public static function abilities(){
  if(!function_exists('wp_register_ability')||!class_exists('MAD4B_Company_Readiness_Governance'))return;
  $name='mad4b/company-bootstrap-status';
  if(function_exists('wp_has_ability') && wp_has_ability($name))return;
  wp_register_ability($name,[
   'label'=>'Company Bootstrap Readiness','description'=>'Read mandatory company owner email and two-user bootstrap readiness.',
   'category'=>'mad4b-read','input_schema'=>['type'=>'object','properties'=>['company'=>['type'=>'string']],'required'=>['company'],'additionalProperties'=>false],
   'output_schema'=>['type'=>'object','additionalProperties'=>true],
   'permission_callback'=>static function($input){$r=new WP_REST_Request('GET','/');$r->set_param('company',$input['company']??'');return self::guard($r);},
   'execute_callback'=>static function($input){$r=new WP_REST_Request('GET','/');$r->set_param('company',$input['company']??'');$out=self::status($r);return $out instanceof WP_REST_Response?$out->get_data():$out;},
   'meta'=>['show_in_rest'=>false,'annotations'=>['readonly'=>true],'mcp'=>['public'=>false,'type'=>'tool','surface'=>'read']]
  ]);
 }
}
MAD4B_Company_Bootstrap_Contract::boot();
