<?php
/** Target site exact WordPress resource read-only evidence, with double-read and fail-closed encoding. */
define('ABSPATH','/');
class WP_Post{
 public int $ID=61152;
 public string $post_type='tour-rates',$post_status='publish',$post_name='sample-rate';
 public int $post_parent=0;
 public string $post_title='Sample Rate',$post_content='Body',$post_excerpt='Excerpt';
 public string $post_modified_gmt='2026-11-12 00:00:00';
}
function add_action(...$args){}
function current_user_can($cap){return ($GLOBALS['TEST_ADMIN']??true) && $cap==='manage_options';}
function wp_is_uuid($id){return preg_match('/^[a-f0-9]{8}-(?:[a-f0-9]{4}-){3}[a-f0-9]{12}$/D',$id)===1;}
function home_url($path='/'){return 'https://wayfatours.com/';}
function untrailingslashit($s){return rtrim($s,'/');}
function wp_get_environment_type(){return 'production';}
function get_option($key,$fallback=null){return 'configured';}
function wp_json_encode($value,$flags=0){
 return ($GLOBALS['FAIL_ENCODING']??false)?false:json_encode($value,$flags);
}
function is_wp_error($v){return false;}
function get_post($id){return (int)$id===61152?new WP_Post():null;}
function get_post_type_object($type){return (object)['public'=>$type==='tour-rates'];}
function get_post_meta($id){
 $GLOBALS['READ_COUNT']=($GLOBALS['READ_COUNT']??0)+1;
 if(($GLOBALS['META_UNSERIALIZABLE']??false))return ['bad-value'=>[INF]];
 if(($GLOBALS['MUTATE_DURING_READ']??false))
  return ['price-test'=>[(string)$GLOBALS['READ_COUNT']]];
 return ['price-test'=>['100 USD'],'note'=>['same']];
}
function get_object_taxonomies($type,$output='names'){return [];}
class MAD4B_SCP_Site_Profile{
 public static function profile(){return [
  'site_uuid'=>'a3a7cc36-db7e-4a14-8456-b41857def05c',
  'canonical_origin'=>'https://wayfatours.com','environment'=>'production'
 ];}
 public static function status(){return [
  'configured'=>true,'origin_match'=>true,'environment_match'=>true,
  'deployment_binding_match'=>!($GLOBALS['SITE_DRIFT']??false),
  'site_uuid'=>'a3a7cc36-db7e-4a14-8456-b41857def05c'
 ];}
}
require dirname(__DIR__).'/wp-content/mu-plugins/mad4b-company-exact-resource-evidence-v1.php';
$class='MAD4B_Company_Exact_Resource_Evidence_V1';
$tests=[];$test=static function($label,$ok)use(&$tests){$tests[$label]=(bool)$ok;};
$good=$class::inspect('wordpress.post','61152');
$test('two_stable_readbacks_accepted',($good['state']??'')==='EXACT_RESOURCE_READ_ONLY_OBSERVED');
$test('resource_scope_exact',($good['resource_key']??'')==='wordpress.post:61152');
$test('both_reads_executed',($GLOBALS['READ_COUNT']??0)===2);
$test('not_a_signing_or_execution_capability',
 ($good['original_313_signed']??true)===false
 &&($good['original_312_signed']??true)===false
 &&($good['mutation_performed']??true)===false);
$test('sensitive_values_not_emitted',
 !str_contains(json_encode($good),'100 USD')
 &&($good['raw_sensitive_metadata_emitted']??true)===false);
$test('company_preservation_and_before_present',
 strlen($good['company_preservation_sha256']??'')===64
 &&strlen($good['before_sha256']??'')===64);
$again=$class::inspect('wordpress.post','61152');
$test('stable_content_semantic_digests_repeat',
 ($again['before_sha256']??null)===$good['before_sha256']);
$GLOBALS['MUTATE_DURING_READ']=true;$GLOBALS['READ_COUNT']=0;
$drift=$class::inspect('wordpress.post','61152');
$test('two_pass_resource_change_holds',
 ($drift['reason']??null)==='RESOURCE_OR_COMPANY_CHANGED_DURING_READBACK');
unset($GLOBALS['MUTATE_DURING_READ']);
$GLOBALS['META_UNSERIALIZABLE']=true;
$bad=$class::inspect('wordpress.post','61152');
$test('json_encoding_failure_holds_instead_of_hashing_empty',
 ($bad['reason']??null)==='RESOURCE_READBACK_ENCODING_OR_INTERNAL_FAILURE');
unset($GLOBALS['META_UNSERIALIZABLE']);
$GLOBALS['FAIL_ENCODING']=true;
$bad=$class::inspect('wordpress.post','61152');
$test('global_option_digest_encode_failure_holds',
 ($bad['reason']??null)==='RESOURCE_READBACK_ENCODING_OR_INTERNAL_FAILURE');
unset($GLOBALS['FAIL_ENCODING']);
$GLOBALS['SITE_DRIFT']=true;
$bad=$class::inspect('wordpress.post','61152');
$test('site_deployment_binding_drift_holds',
 ($bad['reason']??null)==='EXACT_SITE_IDENTITY_NOT_VERIFIED');
unset($GLOBALS['SITE_DRIFT']);
$GLOBALS['TEST_ADMIN']=false;
$bad=$class::inspect('wordpress.post','61152');
$test('non_admin_read_holds',($bad['reason']??null)==='ADMINISTRATOR_REQUIRED');
$GLOBALS['TEST_ADMIN']=true;
foreach([
 ['wordpress.post','0'],['wordpress.post','12/../../etc'],
 ['wordpress.plugin','../outside'],['wordpress.customer','61152']
] as $i=>[$type,$id]){
 $bad=$class::inspect($type,$id);
 $test('unsupported_resource_selector_holds_'.$i,
   ($bad['state']??null)==='EXACT_RESOURCE_READBACK_HOLD');
}
echo json_encode(['pass'=>!in_array(false,$tests,true),'assertions'=>count($tests),
 'failed'=>array_keys(array_filter($tests,fn($v)=>!$v)),
 'read_only'=>true],JSON_UNESCAPED_SLASHES).PHP_EOL;
if(in_array(false,$tests,true))exit(1);
