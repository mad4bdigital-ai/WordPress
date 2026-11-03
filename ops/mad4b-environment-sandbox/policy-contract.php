<?php
// Hermetic: no WordPress bootstrap, actual network, email or cron.
define('ABSPATH','/test/');
class WP_Error {public function __construct(public $code,public $message){} public function get_error_code(){return $this->code;}}
$GLOBALS['filters']=[];
function wp_get_environment_type(){return getenv('MOCK_RAW_ENV')?:'production';}
function wp_parse_url($url){return parse_url($url);}
function get_option($key,$default=null){return $GLOBALS['options'][$key]??$default;}
define('MAD4B_LOCAL_CLIENT_PROVISION_ENABLED',true);
function add_filter($hook,$callback,$priority=10,$accepted=1){$GLOBALS['filters'][$hook][]=[$priority,$callback];}
class MAD4B_SCP_Company_Communication {
 public static function canSend(){return getenv('MOCK_MAIL_ALLOWED')==='1';}
 public static function smtpConfigured(){return getenv('MOCK_SMTP_VERIFIED')==='1';}
}
function ensure($v,$msg){if(!$v){fwrite(STDERR,"FAIL $msg\n");exit(2);}}
require __DIR__.'/000-mad4b-environment-sandbox.php';
require __DIR__.'/005-mad4b-staging-mail-admission.php';
$raw=wp_get_environment_type();$host=getenv('WP_ENVIRONMENT_TYPE')?:'';
$mode=($host===$raw && in_array($host,['local','development','staging','production'],true))?$raw:'unverified_host';
$expectedHttp=$mode!=='production';$expectedMail=$mode!=='production';$expectedCron=in_array($mode,['local','development','unverified_host'],true);
$has=function($hook){return !empty($GLOBALS['filters'][$hook]);};
ensure($has('pre_http_request')===$expectedHttp,'network hook mode');
ensure($has('pre_wp_mail')===$expectedMail,'email hook mode');
ensure($has('cron_request')===$expectedCron,'cron hook mode');
if($expectedHttp){
 $cb=$GLOBALS['filters']['pre_http_request'][0][1];
 $args=['method'=>'GET','headers'=>[]];$public='https://8.8.8.8/info';
 $previousDeny=new WP_Error('previous_block','earlier provider rule');
 $previousProxy=['response'=>['code'=>200],'body'=>'intercepted'];
 if($mode==='unverified_host'){
   ensure($cb(false,$args,$public) instanceof WP_Error,'ambiguous host network denied');
   ensure($cb($previousProxy,$args,$public) instanceof WP_Error,'ambiguous host stays denied');
 }else{
   ensure($cb($previousDeny,$args,$public)===$previousDeny,'upstream deny preserved');
   ensure($cb($previousProxy,$args,$public)===$previousProxy,'upstream proxy preserved');
   ensure($cb(false,$args,$public)===false,'public destination follows through');
   ensure($cb(false,$args,'http://127.0.0.1/private') instanceof WP_Error,'private IP denied');
   ensure($cb(false,$args,'https://user:pass@8.8.8.8/') instanceof WP_Error,'userinfo denied');
   ensure($cb(false,$args,'http://internal-peer.local:8080/wp-json/mad4b-local-ai/v1/context-review') instanceof WP_Error,'local review route cannot be called as public GET');
   if($mode==='local'){
     $url='http://internal-peer.local:8080/wp-json/mad4b-local-ai/v1/context-review';
     $args=['method'=>'POST','headers'=>['Host'=>'local.example:8080','Authorization'=>'Basic token']];
     ensure($cb(false,$args,$url) instanceof WP_Error,'unregistered local endpoint denied');
     $GLOBALS['options']['mad4b_local_review_endpoint_registry_v1']=[
       ['url'=>$url,'host_header'=>'local.example:8080','state'=>'draft']];
     ensure($cb(false,$args,$url) instanceof WP_Error,'draft local endpoint denied');
     $GLOBALS['options']['mad4b_local_review_endpoint_registry_v1'][0]['state']='certified';
     ensure($cb(false,$args,$url)===false,'certified local endpoint allowed with governed request');
     $bad=$args;$bad['headers']['Host']='other.example';
     ensure($cb(false,$bad,$url) instanceof WP_Error,'local host spoof denied');
   }
 }
}
if($mode==='staging'){
 $cb=$GLOBALS['filters']['pre_wp_mail'][0][1];
 putenv('MOCK_MAIL_ALLOWED=0');putenv('MOCK_SMTP_VERIFIED=0');
 foreach([null,false,true] as $pre) ensure($cb($pre,[])===false,'denied mail never short-circuits as success');
 putenv('MOCK_MAIL_ALLOWED=1');putenv('MOCK_SMTP_VERIFIED=0');
 ensure($cb(null,[])===false,'uncertified smtp blocks');
 putenv('MOCK_SMTP_VERIFIED=1');
 ensure($cb(null,[])===null,'approved native mail delegates');
 ensure($cb(true,[])===false,'prior true cannot fake success');
 ensure($cb(false,[])===false,'prior false preserved');
}
if($mode==='development'||$mode==='local'||$mode==='unverified_host'){
 $mail=$GLOBALS['filters']['pre_wp_mail'][0][1];
 ensure($mail(null,[])===false,'sandbox mail held');
}
echo json_encode(['status'=>'PASS','native'=>$raw,'host'=>$host,'effective_policy'=>$mode,'http'=>$expectedHttp,'mail'=>$expectedMail,'cron'=>$expectedCron],JSON_UNESCAPED_SLASHES)."\n";
