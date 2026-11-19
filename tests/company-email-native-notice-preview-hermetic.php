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
function current_user_can($cap){return $cap==='mad4b_manage_company_operations' && $GLOBALS['preview_admin'];}
function get_option($key,$default=null){return $GLOBALS['opts'][$key]??$default;}
function update_option(){throw new Exception('NO_NATIVE_NOTICE_OPTION_WRITE');}
function wp_mail(){throw new Exception('NO_NATIVE_NOTICE_EMAIL_SEND');}
function wp_schedule_event(){throw new Exception('NO_NATIVE_NOTICE_SCHEDULER');}
function add_filter($name,$callback,$prio=10,$argc=1){$GLOBALS['filters'][$name]=$callback;}
function add_action($name,$callback,$prio=10){$GLOBALS['actions'][$name]=$callback;}
function register_rest_route($ns,$path,$args){$GLOBALS['routes'][$ns.$path]=$args;}
function rest_ensure_response($v){return $v;}
class OriginalWpDb {
 public $prefix='wp_';
 public $record='';
 public $feeds=[];
 public $formStatus=[6=>'published',9=>'published'];
 public $reads=0;
 function prepare($sql,...$args){
  foreach($args as $arg)$sql=preg_replace('/%[ds]/',is_int($arg)?(string)$arg:("'".$arg."'"),$sql,1);
  return $sql;
 }
 function get_row($sql,$mode){++$this->reads;
  if(strpos($sql,'fluentform_forms')!==false){
   preg_match('/id=([0-9]+)/',$sql,$m);$id=(int)($m[1]??0);
   return isset($this->formStatus[$id])?['id'=>$id,'status'=>$this->formStatus[$id]]:null;
  }
  return ['form_id'=>6,'value'=>$this->record];
 }
 function get_results($sql,$mode){++$this->reads;
  preg_match('/form_id=([0-9]+)/',$sql,$m);
  return $this->feeds[(int)($m[1]??0)]??[];
 }
}
$wpdb=new OriginalWpDb();
class MAD4B_SCP_Company_Communication {
 public static $send=false;
 public static $smtp=true;
 public static function config(){return [
  'company_display_name'=>'Wayfa Tours',
  'transactional_from_email'=>'bookings@wayfatours.com',
  'traveler_reply_to'=>'traveler@wayfatours.com',
  'bookings_inbox'=>'bookings@wayfatours.com',
  'outbound_email_policy'=>'enabled',
  'fluent_forms_routing_policy'=>'enabled',
  'notify_traveler'=>'enabled',
 ];}
 public static function smtpConfigured(){return self::$smtp;}
 public static function canSend(){return self::$send;}
 public static function recipients($event){return ['ops@wayfatours.com'];}
}
class MAD4B_SCP_Site_Profile {
 public static $matches=true;
 public static function status(){return ['authority_ready'=>self::$matches,'origin_match'=>self::$matches,'environment_match'=>self::$matches,'deployment_binding_match'=>self::$matches,'site_uuid'=>self::$uuid];}
 public static $uuid='11111111-1111-4111-8111-111111111111';
 public static function profile(){return ['site_uuid'=>self::$uuid];}
 public static function site_urls_match_enrollment(){return self::$matches;}
}
class DummyBookingRouter {
 public static $enabled=true;
 public static function config(){return ['enabled'=>self::$enabled?'yes':'no','form_id'=>6];}
}
class_alias('DummyBookingRouter','Commerce\\Composition\\SingleFluentPaymentRouter');
class MAD4B_Company_Contact_Delivery {
 public static function configured_form(){return 9;}
}
function email_assert($condition,$reason){
 if(!$condition){fwrite(STDERR,'FAIL: '.$reason.PHP_EOL);exit(1);}
 $GLOBALS['checks']=($GLOBALS['checks']??0)+1;
}
if(!function_exists('str_contains')){function str_contains($s,$n){return strpos($s,$n)!==false;}}
if(!function_exists('str_ends_with')){function str_ends_with($s,$n){return substr($s,-strlen($n))===$n;}}
$GLOBALS['test_env']='production';
$GLOBALS['preview_admin']=true;
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
// Native Fluent Forms readback: current company policy versus exactly two
// existing site-owned feeds. Synthetic settings only, no mail transport.
$GLOBALS['opts']['mad4b_company_contact_form_public_enabled_v1']='yes';
$bookingName='MAD4B — Reservations — New travel request';
$contactName='MAD4B Company Contact Inbox (managed)';
$make=function($name,$owner){return [
 'name'=>$name,'mad4b_native_owner'=>$owner,'enabled'=>true,
 'sendTo'=>['type'=>'email','email'=>'ops@wayfatours.com'],
 'fromName'=>'Wayfa Tours','fromEmail'=>'bookings@wayfatours.com',
 'replyTo'=>'traveler@wayfatours.com',
 'subject'=>'Original heading','message'=>'Original private traveler entry'
];};
$booking=$make($bookingName,'reservation_company_v1');
$contact=$make($contactName,'company_contact_v1');
$bookingRaw=json_encode($booking);$contactRaw=json_encode($contact);
$wpdb->feeds=[6=>[['id'=>42,'value'=>$bookingRaw]],9=>[['id'=>45,'value'=>$contactRaw]]];
$GLOBALS['opts'][$c::BINDINGS_OPTION]=[
 'contract'=>'mad4b.company-notification-bindings.v1',
 'site_uuid'=>MAD4B_SCP_Site_Profile::$uuid,'identity_sha256'=>$verified_digest,
 'entries'=>[
  ['consumer'=>'fluent_forms','form_id'=>6,'feed_id'=>42,
   'notification_name'=>$bookingName,'event'=>'new_request',
   'source_sha256'=>hash('sha256',$bookingRaw)],
  ['consumer'=>'fluent_forms','form_id'=>9,'feed_id'=>45,
   'notification_name'=>$contactName,'event'=>'traveler_support',
   'source_sha256'=>hash('sha256',$contactRaw)]
 ]];
foreach(['booking','contact'] as $kind){
 $plan=$c::native_notice_preview($kind);
 email_assert($plan['status']==='READY_FOR_REVIEW'&&$plan['read_only']===true
  &&$plan['messages_sent']===0&&$plan['native_feeds_mutated']===0
  &&$plan['options_updated']===0&&$plan['apply_available']===false,
  'Site-bound '.$kind.' preview must not send, save or authorize');
 email_assert($plan['recipient_policy_match']&&$plan['sender_policy_match']
  &&$plan['exact_binding_verified']&&$plan['native_owner_verified'],
  'Existing native '.$kind.' feed matches exact company/Fluent binding');
 email_assert($plan['expected_recipient_domains']===['wayfatours.com']
  &&$plan['expected_recipient_count']===1,'Only recipient domain/count emitted');
 $out=json_encode($plan);
 email_assert(strpos($out,'ops@')===false&&strpos($out,'Original private traveler')===false,
  'Native preview must not reveal private addresses or message bodies');
}
email_assert($c::native_notice_preview('other')['reasons']===['unknown_notice_kind'],
 'Invalid consumer kind denied');
$GLOBALS['preview_admin']=false;$reads=$wpdb->reads;
$emailBlocked=$c::native_notice_preview('booking');
email_assert($emailBlocked['reasons']===['company_operations_permission_required']
 &&$reads===$wpdb->reads,'Nonadmin blocked before database access');
$GLOBALS['preview_admin']=true;
MAD4B_SCP_Site_Profile::$matches=false;$reads=$wpdb->reads;
$emailBlocked=$c::native_notice_preview('booking');
email_assert($emailBlocked['reasons']===['site_or_email_identity_not_verified']
 &&$reads===$wpdb->reads,'Foreign Site Profile blocked before native DB read');
MAD4B_SCP_Site_Profile::$matches=true;
$foreign=$booking;$foreign['sendTo']['email']='foreign@outside.example';
$wpdb->feeds[6][0]['value']=json_encode($foreign);
$drift=$c::native_notice_preview('booking');
email_assert(in_array('recipient_policy_drift',$drift['reasons'],true)
 &&in_array('native_binding_missing_or_stale',$drift['reasons'],true),
 'Foreign destination must be flagged and never silently rewritten');
$wpdb->feeds[6][0]['value']=$bookingRaw;
$wpdb->feeds[6][]=['id'=>46,'value'=>$bookingRaw];
email_assert($c::native_notice_preview('booking')['reasons']===['native_feed_ambiguous_or_unowned'],
 'Duplicate managed native feeds denied');
$wpdb->feeds[6]=[];
$missing=$c::native_notice_preview('booking');
email_assert($missing['status']==='MISSING' &&$missing['reasons']===['native_feed_missing'],
 'Missing feed is not created during read-only preview');
$wpdb->feeds[6]=[['id'=>42,'value'=>$bookingRaw]];
$noOwner=$contact;unset($noOwner['mad4b_native_owner']);
$wpdb->feeds[9]=[['id'=>45,'value'=>json_encode($noOwner)]];
email_assert(in_array('native_owner_binding_missing',$c::native_notice_preview('contact')['reasons'],true),
 'Unowned contact feed requires explicit source review');
$wpdb->feeds[9]=[['id'=>45,'value'=>$contactRaw]];
$wpdb->formStatus[9]='draft';
email_assert($c::native_notice_preview('contact')['reasons']===['native_form_not_published'],
 'Unpublished contact form cannot be certified');
$wpdb->formStatus[9]='published';
call_user_func($GLOBALS['actions']['rest_api_init']);
$route=$GLOBALS['routes']['mad4b/v1/admin/company-native-notice/preview']??[];
email_assert(($route['methods']??'')==='GET'
 &&is_callable($route['permission_callback']??null),
 'Company native preview exposes only guarded GET readback');
MAD4B_SCP_Site_Profile::$uuid='22222222-2222-4222-8222-222222222222';
email_assert($c::bindings()===[],'Wrong company Site UUID cannot inherit existing notification binding');
echo 'PASS: COMPANY_NATIVE_NOTICE_READONLY '.($GLOBALS['checks']??0).
 ' scoped native notification no-write/no-send assertions'.PHP_EOL;
