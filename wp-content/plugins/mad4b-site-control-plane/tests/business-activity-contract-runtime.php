<?php
/** Isolated sector-neutral Business Activity Contract negative/positive cases. */
define('ABSPATH',__DIR__);
class WP_Error { private $code; function __construct($c,$m=''){ $this->code=$c; }
 function get_error_code(){return $this->code;} }
function is_wp_error($v){return $v instanceof WP_Error;}
function wp_json_encode($v,$flags=0){return json_encode($v,$flags);}
function sanitize_key($v){return strtolower(preg_replace('/[^a-z0-9_-]/i','',(string)$v));}
function absint($v){return abs((int)$v);}
function current_user_can($cap,$id=0){return true;}
function get_role($r){return $r==='subscriber'?(object)array('capabilities'=>array('read'=>true)):($r==='administrator'?(object)array('capabilities'=>array('manage_options'=>true)):null);}
function get_object_taxonomies($type){return $type==='vendors'?array('region','class'):array('region');}
function get_taxonomy($type){return in_array($type,array('region','class'),true)?(object)array('cap'=>(object)array('assign_terms'=>'edit_posts')):null;}
function term_exists($id,$tax){return $id===7;}
function get_userdata($id){return $id===21?(object)array('ID'=>21):false;}
function get_post_type($id){return $id===101?'vendors':false;}
function get_post_meta($id,$key,$one){return 0;}
function get_user_meta($id,$key,$one){return 0;}
function is_email($v){return filter_var($v,FILTER_VALIDATE_EMAIL)!==false;}
function sanitize_user($v,$strict=true){return strtolower((string)$v);}
function username_exists($v){return false;}
function email_exists($v){return false;}
class MAD4B_SCP_Site_Profile {
 static function configured(){return true;}
 static function origin_enrolled(){return true;}
 static function site_urls_match_enrollment(){return true;}
 static function site_uuid(){return '11111111-2222-4333-8444-555555555555';}
}
class MAD4B_SCP_Content_Experience_Profiles {
 static $profile;
 static function profile($slug){return $slug==='vendors' ? self::$profile : new WP_Error('missing');}
 static function post_type_object($type){return (object)array('cap'=>(object)array('create_posts'=>'edit_posts'));}
 static function post_type_create_cap($obj){return $obj->cap->create_posts;}
 static function profile_routes($slug,$rev){return array('update_plan'=>'mad4b/experience-'.$slug.'-update-plan',
   'update_apply'=>'mad4b/experience-'.$slug.'-update-apply','verify'=>'mad4b/experience-'.$slug.'-verify');}
}
require __DIR__.'/../includes/class-mad4b-scp-business-activity-contracts.php';
function check($pass,$msg){if(!$pass)throw new RuntimeException($msg);}
$raw=array('enabled'=>true,'user_role'=>'subscriber',
 'post_user_meta_key'=>'_vendor_account','user_post_meta_key'=>'_vendor_listing',
 'attribute_meta_keys'=>array('category_code'),
 'taxonomy_slugs'=>array('region'),
 'create_user'=>true,'create_profile'=>true,
 'sync_targets'=>array('wp_feed'=>array('provider'=>'wordpress','direction'=>'bidirectional',
   'field_keys'=>array('category_code'),'source_ref'=>'local_catalog','conflict_policy'=>'manual_review'),
   'brand_folder'=>array('provider'=>'google_drive','direction'=>'bidirectional',
   'field_keys'=>array('category_code'),'source_ref'=>'brand_docs','conflict_policy'=>'manual_review')));
$raw['field_owners']=array('category_code'=>'wp_feed');
$raw['sync_identity_key']='post_id';
$raw['sync_targets']['brand_folder']['field_keys']=array();
$raw['sync_targets']['brand_folder']['purpose']='editorial_policy';
$raw['sync_targets']['brand_folder']['resource_kind']='drive_document';
$normalized=MAD4B_SCP_Business_Activity_Contracts::normalize($raw,'vendors',array('category_code'),array('region'));
check(!is_wp_error($normalized)&&$normalized['field_owners']['category_code']==='wp_feed'&&
 $normalized['sync_targets']['brand_folder']['purpose']==='editorial_policy',
 'Field owners and editorial context not normalized');
$typed=$raw;$typed['sync_targets']['wp_feed']['field_bindings']=array(
 'category_code'=>array('provider_field'=>'Sheet1!B2','value_type'=>'string','null_policy'=>'manual_review'));
$yes=MAD4B_SCP_Business_Activity_Contracts::normalize($typed,'vendors',array('category_code'),array('region'));
check(!is_wp_error($yes)&&$yes['sync_targets']['wp_feed']['field_bindings']['category_code']['provider_field']==='Sheet1!B2',
 'Typed per-source selector configuration unavailable');
$expression=$typed;$expression['sync_targets']['wp_feed']['field_bindings']['category_code']['provider_field']='eval(PHP)';
$no=MAD4B_SCP_Business_Activity_Contracts::normalize($expression,'vendors',array('category_code'),array('region'));
check(is_wp_error($no)&&$no->get_error_code()==='mad4b_activity_source_binding_value_invalid',
 'Executable expression accepted as field selector');
$duplicateRef=$raw;$duplicateRef['sync_targets']['another']=array('provider'=>'google_drive',
 'source_ref'=>'brand_docs','resource_kind'=>'drive_document','purpose'=>'reference',
 'field_keys'=>array(),'direction'=>'import');
$no=MAD4B_SCP_Business_Activity_Contracts::normalize($duplicateRef,'vendors',array('category_code'),array('region'));
check(is_wp_error($no)&&$no->get_error_code()==='mad4b_activity_duplicate_resource_binding',
 'Duplicate Drive resource alias accepted');
$misowner=$raw;$misowner['field_owners']['category_code']='brand_folder';
$no=MAD4B_SCP_Business_Activity_Contracts::normalize($misowner,'vendors',array('category_code'),array('region'));
check(is_wp_error($no)&&$no->get_error_code()==='mad4b_activity_field_owner_invalid','Editorial policy promoted to record field owner');
$wrongDirection=$raw;$wrongDirection['sync_targets']['wp_feed']['direction']='export';
$no=MAD4B_SCP_Business_Activity_Contracts::normalize($wrongDirection,'vendors',array('category_code'),array('region'));
check(is_wp_error($no)&&$no->get_error_code()==='mad4b_activity_field_owner_direction_invalid','Outbound-only source promoted to field owner');
$colliding=$raw;$colliding['sync_targets']['wordpress']=$raw['sync_targets']['wp_feed'];
$no=MAD4B_SCP_Business_Activity_Contracts::normalize($colliding,'vendors',array('category_code'),array('region'));
check(is_wp_error($no),'Reserved WordPress source ID hijacked');

check(!is_wp_error($normalized)&&$normalized['enabled']&&count($normalized['sync_targets'])===2,'Generic facet config failed');
$privileged=$raw;$privileged['user_role']='administrator';
$bad=MAD4B_SCP_Business_Activity_Contracts::normalize($privileged,'vendors',array('category_code'),array('region'));
check(is_wp_error($bad)&&$bad->get_error_code()==='mad4b_activity_contract_identity_invalid','Admin role escalation admitted');
$unknown=$raw;$unknown['attribute_meta_keys']=array('unknown_key');
$bad=MAD4B_SCP_Business_Activity_Contracts::normalize($unknown,'vendors',array('category_code'),array('region'));
check(is_wp_error($bad),'Unknown site Meta key admitted');
$nonallowed=$raw;$nonallowed['taxonomy_slugs']=array('class');
$bad=MAD4B_SCP_Business_Activity_Contracts::normalize($nonallowed,'vendors',array('category_code'),array('region'));
check(is_wp_error($bad),'Parent taxonomy scope bypass admitted');
$inject=$raw;$inject['sync_targets']['brand_folder']['source_ref']='https://evil.example/token';
$bad=MAD4B_SCP_Business_Activity_Contracts::normalize($inject,'vendors',array('category_code'),array('region'));
check(is_wp_error($bad),'Unbounded provider URL admitted into source-ref');
MAD4B_SCP_Content_Experience_Profiles::$profile=array(
 'enabled'=>true,'slug'=>'vendors','post_type'=>'vendors','revision'=>2,
 'authority_sha256'=>str_repeat('a',64),'activity_contract'=>$normalized);
$req=array('profile_slug'=>'vendors','post_id'=>101,'user_id'=>21,
 'attributes'=>array('category_code'=>'verified'),'classifications'=>array('region'=>array(7)));
$plan=MAD4B_SCP_Business_Activity_Contracts::plan($req);
check(!is_wp_error($plan)&&preg_match('/^[a-f0-9]{64}$/',$plan['plan_sha256'])&&
 !$plan['mutation_performed'],'Existing user/profile link plan invalid');
$fail=$req;$fail['attributes']=array('unapproved'=>'x');
$planbad=MAD4B_SCP_Business_Activity_Contracts::plan($fail);
check(is_wp_error($planbad),'Unmapped metadata accepted by dynamic link plan');
$sync=MAD4B_SCP_Business_Activity_Contracts::sync_plan(array(
 'profile_slug'=>'vendors','target_id'=>'brand_folder','operation'=>'update'));
check(!is_wp_error($sync)&&$sync['provider']==='google_drive'&&
 !$sync['provider_write_certified']&&!$sync['mutation_performed']&&
 $sync['requires_source_and_destination_revision_readback'],'Drive operation was implicitly authorized');
$badSync=MAD4B_SCP_Business_Activity_Contracts::sync_plan(array(
 'profile_slug'=>'vendors','target_id'=>'missing','operation'=>'update'));
check(is_wp_error($badSync),'Nonconfigured provider sync target accepted');
echo "PASS data-only Business Activity facet for arbitrary industry CPT, user roles, taxonomy, WordPress/Drive sync\n";
