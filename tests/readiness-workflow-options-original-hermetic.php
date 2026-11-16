<?php
/** Company Readiness routing is a local setting; no board writes, email or task dispatch. */
define('ABSPATH',__DIR__.'/');
class WP_Error{private $code;function __construct($code,$msg='',$data=[]){$this->code=$code;}function get_error_code(){return $this->code;}}
function is_wp_error($v){return $v instanceof WP_Error;}
if(!function_exists('str_starts_with')){function str_starts_with($haystack,$needle){return substr($haystack,0,strlen($needle))===$needle;}}
function sanitize_key($v){return strtolower(preg_replace('/[^a-z0-9_-]/','',(string)$v));}
function absint($v){return is_numeric($v)?abs((int)$v):0;}
function get_option($n,$default=[]){return $GLOBALS['settings'];}
function get_user_by($type,$id){return $id?(object)['ID'=>$id]:null;}
function user_can($u,$cap){return $GLOBALS['actor_cap'];}
function get_users($args){return $GLOBALS['candidates'];}
function current_user_can($cap){return $GLOBALS['actor_cap'];}
function wp_die($message,$status=400){throw new RuntimeException('denied:'.$message);}
function check_admin_referer($n){$GLOBALS['nonce_checked']++;}
function workflow_check($yes,$reason){if(!$yes){fwrite(STDERR,'FAIL '.$reason.PHP_EOL);exit(1);} $GLOBALS['n']=($GLOBALS['n']??0)+1;}
require dirname(__DIR__).'/wp-content/plugins/mad4b-site-control-plane/includes/class-mad4b-scp-readiness-workflow-options.php';
$c='MAD4B_SCP_Readiness_Workflow_Options';
$GLOBALS['settings']=['owners'=>['media'=>17],'owner_roles'=>[]];
foreach([new WP_Error('admin_unknown'),1,'true',null,false] as $bad){
 $GLOBALS['actor_cap']=$bad;
 workflow_check($c::assigned_user(['code'=>'media_license_review'])===0,
  'No owner is assigned on an unverified WordPress user capability');
 $GLOBALS['nonce_checked']=0;
 try{$c::save();$denied=false;}catch(RuntimeException $ex){$denied=true;}
 workflow_check($denied&&$GLOBALS['nonce_checked']===0,
  'Unknown admin capability cannot enter workflow settings write before nonce');
 try{$c::render();$denied=false;}catch(RuntimeException $ex){$denied=true;}
 workflow_check($denied,'Unknown admin capability cannot render settings dashboard');
}
$GLOBALS['actor_cap']=true;
workflow_check($c::assigned_user(['code'=>'media_license_review'])===17,
 'Explicit eligible verified owner routing is preserved');
$GLOBALS['settings']=['owners'=>[],'owner_roles'=>['media'=>'editor']];
$GLOBALS['candidates']=new WP_Error('users_unavailable');
workflow_check($c::assigned_user(['code'=>'media_license_review'])===0,
 'User lookup errors cannot assign an unintended review owner');
$GLOBALS['candidates']=[(object)['ID'=>34]];
workflow_check($c::assigned_user(['code'=>'media_license_review'])===34,
 'Unique verified owner role resolves consistently');
echo 'PASS: READINESS_WORKFLOW_OPTIONS_ORIGINAL '.($GLOBALS['n']??0).' nonmutating owner/admin admission cases'.PHP_EOL;
