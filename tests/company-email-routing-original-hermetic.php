<?php
/** Original company email routing; hermetic, never sends a message. */
define('ABSPATH',__DIR__.'/');
define('MAD4B_SCP_DIR',dirname(__DIR__).'/wp-content/plugins/mad4b-site-control-plane/');
define('ARRAY_A','ARRAY_A');
class WP_Error {
 private $code;
 function __construct($code,$msg='',$data=[]){$this->code=$code;}
 function get_error_code(){return $this->code;}
}
function is_wp_error($x){return $x instanceof WP_Error;}
function sanitize_email($v){return is_string($v)?trim($v):'';}
function sanitize_key($v){return preg_replace('/[^a-z0-9_-]/','',strtolower((string)$v));}
function is_email($s){return filter_var($s,FILTER_VALIDATE_EMAIL)!==false;}
function wp_get_environment_type(){return $GLOBALS['test_env'];}
function wp_json_encode($v,$flags=0){return json_encode($v,$flags);}
function current_user_can($cap){return $cap==='mad4b_manage_company_operations';}
function get_option($key,$default=null){return $GLOBALS['opts'][$key]??$default;}
function add_filter($name,$callback,$prio=10,$argc=1){$GLOBALS['filters'][$name]=$callback;}
function add_action($name,$callback,$prio=10){$GLOBALS['actions'][$name]=$callback;}
function register_rest_route($ns,$path,$args){$GLOBALS['routes'][$ns.$path]=$args;}
function rest_ensure_response($v){return $v;}
class OriginalWpDb {
 public $prefix='wp_';
 public $record='';
 function prepare($sql,...$args){return $sql;}
 function get_row($sql,$mode){return ['form_id'=>6,'value'=>$this->record];}
}
$wpdb=new OriginalWpDb();
class MAD4B_SCP_Company_Communication {
 public static $send=false;
 public static $smtp=true;
 public static $routing='enabled';
 public static function config(){return [
  'company_display_name'=>'Wayfa Tours',
  'transactional_from_email'=>'bookings@wayfatours.com',
  'traveler_reply_to'=>'traveler@wayfatours.com',
  'bookings_inbox'=>'bookings@wayfatours.com',
  'outbound_email_policy'=>'enabled',
  'fluent_forms_routing_policy'=>self::$routing,
  'notify_traveler'=>'enabled',
 ];}
 public static function smtpConfigured(){return self::$smtp;}
 public static function canSend(){return self::$send;}
 public static function recipients($event){return ['ops@wayfatours.com'];}
}
class MAD4B_SCP_Site_Profile {
 public static $matches=true;
 public static $uuid='11111111-1111-4111-8111-111111111111';
 public static function profile(){return ['site_uuid'=>self::$uuid];}
 public static function site_urls_match_enrollment(){return self::$matches;}
}
function email_assert($condition,$reason){
 if(!$condition){fwrite(STDERR,'FAIL: '.$reason.PHP_EOL);exit(1);}
 $GLOBALS['checks']=($GLOBALS['checks']??0)+1;
}
if(!function_exists('str_contains')){function str_contains($s,$n){return strpos($s,$n)!==false;}}
if(!function_exists('str_ends_with')){function str_ends_with($s,$n){return substr($s,-strlen($n))===$n;}}
$GLOBALS['test_env']='production';
$GLOBALS['opts']=[];$GLOBALS['actions']=[];$GLOBALS['filters']=[];$GLOBALS['routes']=[];
require dirname(__DIR__).'/wp-content/plugins/mad4b-site-control-plane/includes/class-mad4b-scp-company-email-routing.php';
$c='MAD4B_SCP_Company_Email_Routing';
$pending=$c::identity();
email_assert($pending['valid']===true &&$pending['verified']===false
 &&$pending['status']==='PENDING_VERIFICATION',
 'Unverified original company sender cannot gain execution readiness');
$verified_digest=$pending['identity_sha256'];
$GLOBALS['opts'][$c::VERIFY_OPTION]=[
 'contract'=>'mad4b.company-email-verification.v1',
 'site_uuid'=>MAD4B_SCP_Site_Profile::$uuid,
 'identity_sha256'=>$verified_digest,
 'status'=>'VERIFIED',
 'evidence_digest'=>str_repeat('a',64),
 'verified_at'=>1780000000,
 'verifier_user_id'=>17
];
$verified=$c::identity();
email_assert($verified['verified']===true,'Original site-bound company sender receipt can be read as configured');
MAD4B_SCP_Company_Communication::$send=new WP_Error('transport_unverified');
$plan=$c::resolve('fluent_forms','new_request');
email_assert($plan['would_send']===false,
 'WP_Error from company send admission must never become a sendable notification preview');
MAD4B_SCP_Company_Communication::$send=true;
MAD4B_SCP_Company_Communication::$smtp=new WP_Error('smtp_unknown');
$plan=$c::resolve('fluent_forms','new_request');
email_assert($plan['would_send']===false,
 'Unknown SMTP readiness cannot be treated as verified mail route');
MAD4B_SCP_Company_Communication::$smtp=true;
$plan=$c::resolve('fluent_forms','new_request');
email_assert($plan['would_send']===true &&$plan['messages_sent']===0 &&$plan['dry_run']===true,
 'Verified original receipt and explicit SMTP/send policy only produce a dry-run route');
$foreign=$c::resolve('unknown_provider','new_request');
email_assert($foreign['would_send']===false &&$foreign['status']==='BLOCKED',
 'Unregistered consumer cannot resolve company mail');
$foreign=$c::resolve('fluent_forms','operations_external');
email_assert($foreign['would_send']===false,
 'External operations require a separate dispatch approval');
MAD4B_SCP_Site_Profile::$matches=new WP_Error('origin_unverified');
$forged=$c::identity();
email_assert($forged['valid']===false &&$forged['verified']===false,
 'WP_Error from site origin enrollment must not satisfy company sender identity');
MAD4B_SCP_Site_Profile::$matches=true;
$record=json_encode(['enabled'=>true,'name'=>'Booking Request','sendTo'=>['type'=>'email','email'=>'someone@outside.com']]);
$wpdb->record=$record;
$GLOBALS['opts'][$c::BINDINGS_OPTION]=[
 'contract'=>'mad4b.company-notification-bindings.v1',
 'site_uuid'=>MAD4B_SCP_Site_Profile::$uuid,
 'identity_sha256'=>$verified_digest,
 'entries'=>[[
  'consumer'=>'fluent_forms','form_id'=>6,
  'notification_name'=>'Booking Request','event'=>'new_request',
  'feed_id'=>42,'source_sha256'=>hash('sha256',$record)
 ]]
];
$form=(object)['id'=>6];
MAD4B_SCP_Company_Communication::$send=new WP_Error('deny');
$email=$c::fluent_form_recipient('outside@example.com',[
 'name'=>'Booking Request','sendTo'=>['type'=>'email']],[],$form);
email_assert($email==='','Form routing cannot be enabled by unknown company send authority');
MAD4B_SCP_Company_Communication::$send=true;
$email=$c::fluent_form_recipient('outside@example.com',[
 'name'=>'Booking Request','sendTo'=>['type'=>'email']],[],$form);
email_assert($email==='ops@wayfatours.com','Exact verified current Fluent Forms feed uses company-scoped recipients');
$wpdb->record=str_replace('Booking Request','Old Notification',$record);
$email=$c::fluent_form_recipient('outside@example.com',[
 'name'=>'Booking Request','sendTo'=>['type'=>'email']],[],$form);
email_assert($email==='','Changed feed hash cannot reuse historical binding');
MAD4B_SCP_Site_Profile::$uuid='22222222-2222-4222-8222-222222222222';
email_assert($c::bindings()===[],'Wrong company Site UUID cannot inherit existing notification binding');

// Prior Wayfatours native owner stamps are never permission to reuse the
// notification's stale stored address, even if routing was later disabled.
MAD4B_SCP_Company_Communication::$routing='disabled';
foreach(['reservation_company_v1','company_contact_v1'] as $owner) {
 $managed=['mad4b_native_owner'=>$owner,'name'=>'Booking Request',
          'sendTo'=>['type'=>'email','email'=>'old@outside.test']];
 email_assert($c::fluent_form_recipient('old@outside.test',$managed,[],$form)==='',
  'Disabled company routing must never fall through to persisted '.$owner.' recipient');
 email_assert($c::fluent_form_recipient('old@outside.test',$managed,[],null)==='',
  'Malformed native owner form must fail closed '.$owner);
}
email_assert($c::fluent_form_recipient('unrelated@outside.test',
 ['name'=>'Other','sendTo'=>['type'=>'email']],[],(object)['id'=>18])==='unrelated@outside.test',
 'Noncompany Fluent Forms notifications must retain original address when routing disabled');
MAD4B_SCP_Company_Communication::$routing='enabled';
email_assert($c::fluent_form_recipient('unrelated@outside.test',
 ['name'=>'Other','sendTo'=>['type'=>'email']],[],(object)['id'=>18])==='unrelated@outside.test',
 'Enabled company routing must not hijack unrelated native forms');
email_assert($c::fluent_form_recipient('unrelated@outside.test',
 ['name'=>'Other','sendTo'=>['type'=>'email']],[],null)==='unrelated@outside.test',
 'Invalid unrelated form must remain native when not company owned');
$managed=['mad4b_native_owner'=>'reservation_company_v1',
  'name'=>'Booking Request','sendTo'=>['type'=>'email']];
email_assert($c::fluent_form_recipient('old@outside.test',$managed,[],$form)==='',
 'A managed feed on cloned/changed Site UUID must fail closed despite matching old form');
MAD4B_SCP_Site_Profile::$uuid='11111111-1111-4111-8111-111111111111';
$wpdb->record=$record;
MAD4B_SCP_Company_Communication::$send=new WP_Error('denied');
email_assert($c::fluent_form_recipient('old@outside.test',$managed,[],$form)==='',
 'Managed native mail cannot send when canSend returns WP_Error');
MAD4B_SCP_Company_Communication::$send=true;
echo 'PASS: COMPANY_EMAIL_ROUTING '.($GLOBALS['checks']??0).
 ' original cross-company and no-send preview checks'.PHP_EOL;
