<?php
/** Synthetic expiry contract; network-free, no site data, no real credentials. */
$root=sys_get_temp_dir().'/mad4b-review-expire-'.bin2hex(random_bytes(5));
$vault=$root.'/vault';$web=$root.'/web';
mkdir($vault,0700,true);mkdir($web,0700,true);chmod($vault,0700);
define('ABSPATH',$web.'/');
define('MAD4B_AI_REVIEW_VAULT_PATH',$vault.'/ai-only-review.json');
$GLOBALS['env']='production';
$GLOBALS['hooks']=[];
$GLOBALS['options']=[];
$GLOBALS['revocations']=[];
$GLOBALS['revocation_allowed']=false;
function wp_get_environment_type(){return $GLOBALS['env'];}
function add_action($hook,$callback,$priority=10,$args=1){$GLOBALS['hooks'][$hook]=$callback;}
function add_filter($hook,$callback,$priority=10,$args=1){}
function get_option($key,$default=[]){return $GLOBALS['options'][$key]??$default;}
function update_option($key,$value,$autoload=false){$GLOBALS['options'][$key]=$value;return true;}
function is_wp_error($x){return $x instanceof WP_Error;}
class WP_Error{
 private $code;
 function __construct($code,$message=''){$this->code=$code;}
 function get_error_code(){return $this->code;}
}
class MAD4B_SCP_Site_Profile {
 static function site_uuid(){return '11111111-2222-4333-8444-555555555555';}
}
class WP_Application_Passwords {
 static function delete_application_password($user,$uuid){
  $GLOBALS['revocations'][]=[$user,$uuid];
  return $GLOBALS['revocation_allowed'];
 }
}
require dirname(__DIR__).'/includes/class-mad4b-scp-ai-only-local-activation.php';
$pass=0;
function check($label,$valid){global $pass;if(!$valid){fwrite(STDERR,'FAIL '.$label.PHP_EOL);exit(1);} $pass++;}
$path=MAD4B_AI_REVIEW_VAULT_PATH;
check('existing cron compatibility registered',isset($GLOBALS['hooks']['mad4b_ai_only_local_expire']));
function record_expiry($expires){
 global $path;
 file_put_contents($path,json_encode(['user_id'=>13,'uuid'=>'11111111-aaaa-4444-aaaa-aaaaaaaaaaaa','expires_at'=>$expires,'site_uuid'=>MAD4B_SCP_Site_Profile::site_uuid()]));
 chmod($path,0600);
}
record_expiry(time()+120);
check('future credential no revocation',MAD4B_SCP_AI_Only_Local_Activation::expire()===null && count($GLOBALS['revocations'])===0 && is_file($path));
record_expiry(time()-120);
$failed=MAD4B_SCP_AI_Only_Local_Activation::expire();
check('failed original WP revocation denied',is_wp_error($failed) && $failed->get_error_code()==='mad4b_ai_review_revoke_failed');
check('failed revocation keeps private vault for retry',is_file($path) && (fileperms($path)&0077)===0);
check('failure retains exact user and uuid', $GLOBALS['revocations'][0]===[13,'11111111-aaaa-4444-aaaa-aaaaaaaaaaaa']);
$audits=get_option(MAD4B_SCP_AI_Only_Local_Activation::AUDIT_OPTION,[]);
check('failure audit persisted',count($audits)===1 && $audits[0]['event']==='credential_revoke_failed');
$GLOBALS['revocation_allowed']=true;
$recovered=MAD4B_SCP_AI_Only_Local_Activation::expire();
check('successful revoke and vault cleanup', $recovered===true && !is_file($path) && count($GLOBALS['revocations'])===2);
$audits=get_option(MAD4B_SCP_AI_Only_Local_Activation::AUDIT_OPTION,[]);
check('success audit persisted', count($audits)===2 && $audits[1]['event']==='credential_expired');
MAD4B_SCP_AI_Only_Local_Activation::expire();
check('idempotent no record', count($GLOBALS['revocations'])===2);
rmdir($vault);rmdir($web);rmdir($root);
echo 'PASS: '.$pass.' original WordPress cron expiry/revocation checks'.PHP_EOL;
