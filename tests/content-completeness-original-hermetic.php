<?php
/** Actual WordPress Content Completeness source normalization and read-only permission. */
define('ABSPATH',__DIR__.'/');
class WP_Error{private $code;function __construct($code,$msg='',$data=[]){$this->code=$code;}function get_error_code(){return $this->code;}}
function is_wp_error($x){return $x instanceof WP_Error;}
function current_user_can($cap){return $GLOBALS['wp_permission'];}
function get_option($name,$default=[]){return $GLOBALS['stored']??$default;}
function sanitize_key($v){return strtolower(preg_replace('/[^a-z0-9_-]/','',(string)$v));}
function sanitize_text_field($v){return trim((string)$v);}
function post_type_exists($type){return $type==='post';}
function taxonomy_exists($tax){return $tax==='destinations';}
function ccr_check($ok,$why){if(!$ok){fwrite(STDERR,'FAIL '.$why.PHP_EOL);exit(1);}$GLOBALS['n']=($GLOBALS['n']??0)+1;}
require dirname(__DIR__).'/wp-content/plugins/mad4b-site-control-plane/includes/class-mad4b-scp-content-completeness.php';
$c='MAD4B_SCP_Content_Completeness';
$read=new ReflectionMethod($c,'read_permission');
if(PHP_VERSION_ID<80100)$read->setAccessible(true);
foreach([new WP_Error('user_unverified'),1,'true',null,false] as $bad){
 $GLOBALS['wp_permission']=$bad;
 ccr_check($c::admin_permission()===false &&$read->invoke(null)===false,
  'Unverified WordPress Content Completeness permissions cannot read or administer');
}
$GLOBALS['wp_permission']=true;
ccr_check($c::admin_permission()===true &&$read->invoke(null)===true,
 'Verified native WordPress permissions remain readable');
$valid=['enabled'=>true,'exclude_qa'=>false,'rules'=>[
 ['id'=>'test_rule','target'=>'post','field'=>'post_title',
  'post_type'=>'post','language'=>'en','severity'=>'high','min_chars'=>5]
]];
$r=$c::normalize($valid);
ccr_check(is_array($r)&&$r['enabled']===true&&count($r['rules'])===1,
 'Valid exact post field completeness policy normalizes without a write');
foreach(['id','target','field','post_type','taxonomy','meta_key','language',
 'severity','inherit_from_meta','inherit_post_type','conditional_meta'] as $field){
 $input=$valid;$input['rules'][0][$field]=new WP_Error('source_unverified');
 $r=$c::normalize($input);
 ccr_check(is_wp_error($r),
  'An error-shaped rule descriptor cannot cast to a trusted WordPress meta key or source field');
}
$GLOBALS['stored']=new WP_Error('configuration_unavailable');
$s=$c::settings();
ccr_check(is_array($s)&&$s['enabled']===false&&$s['rules']===[],
 'Missing source settings cannot become a discovered completeness rule');
echo 'PASS: CONTENT_COMPLETENESS_ORIGINAL '.($GLOBALS['n']??0).
 ' first-party site field/permission/read-policy cases'.PHP_EOL;
