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
function current_user_can($cap){return ($GLOBALS['allowCompanyOps']??true) && $cap==='mad4b_manage_company_operations';}
function get_option($key,$default=null){return $GLOBALS['opts'][$key]??$default;}
function add_filter($name,$callback,$prio=10,$argc=1){$GLOBALS['filters'][$name]=$callback;}
function add_action($name,$callback,$prio=10){$GLOBALS['actions'][$name]=$callback;}
function register_rest_route($ns,$path,$args){$GLOBALS['routes'][$ns.$path]=$args;}
function rest_ensure_response($v){return $v;}
class OriginalWpDb {
 public $prefix='wp_';
 public $record='';
 public $form_id=6;
 public $form_status='published';
 public $managed_rows=[];
 public $read_fail=false;
 function prepare($sql,...$args){return $sql;}
 function get_row($sql,$mode){
  if($this->read_fail) throw new RuntimeException('synthetic database error');
  if(strpos($sql,'fluentform_forms')!==false)
   return ['id'=>$this->form_id,'status'=>$this->form_status];
  return ['form_id'=>6,'value'=>$this->record];
 }
 function get_results($sql,$mode){return $this->managed_rows;}
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

// Native notification planning must derive each company's current form ID
// from the correct provider, never assume a global Fluent Forms numeric ID.
class NativeBookingRouterFixture {
 public static $formId=19;
 public static $enabled='yes';
 public static $fail=false;
 public static function config(){
  if(self::$fail)throw new RuntimeException('synthetic booking provider failure');
  return ['enabled'=>self::$enabled,'form_id'=>self::$formId];
 }
}
class_alias('NativeBookingRouterFixture','Commerce\Composition\SingleFluentPaymentRouter');
// Clear the earlier fixture's exact form 6 binding before testing that a
// historical number alone never establishes company ownership.
$GLOBALS['opts'][$c::BINDINGS_OPTION]=[];
class MAD4B_Company_Contact_Delivery {
 public static $formId=29;
 public static $fail=false;
 public static function configured_form(){
  if(self::$fail)throw new RuntimeException('synthetic contact provider failure');
  return self::$formId;
 }
}
$email=$c::fluent_form_recipient('unrelated@outside.test',
 ['name'=>'Unrelated legacy form','sendTo'=>['type'=>'email']],[],(object)['id'=>6]);
email_assert($email==='unrelated@outside.test',
 'Legacy form ID 6 is not governed unless configured by this company');
$email=$c::fluent_form_recipient('persisted@outside.test',
 ['name'=>'Not yet bound','sendTo'=>['type'=>'email']],[],(object)['id'=>19]);
email_assert($email==='','Dynamically configured booking form blocks unknown recipient until exact binding');
$preflight=$c::native_notice_preflight('unknown');
email_assert($preflight['status']==='BLOCKED' && in_array('native_kind_unknown',$preflight['blockers'],true)
 && $preflight['mutation_performed']===false,'Unknown notice source remains blocked and read-only');
$GLOBALS['allowCompanyOps']=false;
$preflight=$c::native_notice_preflight('reservation');
email_assert(in_array('company_operations_permission_required',$preflight['blockers'],true),
 'Native preflight requires company operations authority');
$GLOBALS['allowCompanyOps']=true;
$wpdb->form_id=19;
$wpdb->form_status='published';
$reservationRecord=[
 'name'=>'MAD4B — Reservations — New travel request',
 'enabled'=>true,'mad4b_native_owner'=>'reservation_company_v1',
 'sendTo'=>['type'=>'email','email'=>'ops@wayfatours.com'],
 'fromEmail'=>'bookings@wayfatours.com'
];
$raw=json_encode($reservationRecord);
$wpdb->managed_rows=[['id'=>78,'value'=>$raw]];
$GLOBALS['opts'][$c::BINDINGS_OPTION]=[
 'contract'=>'mad4b.company-notification-bindings.v1',
 'site_uuid'=>MAD4B_SCP_Site_Profile::$uuid,
 'identity_sha256'=>$verified_digest,
 'entries'=>[[
  'consumer'=>'fluent_forms','form_id'=>19,'event'=>'new_request',
  'notification_name'=>$reservationRecord['name'],
  'feed_id'=>78,'source_sha256'=>hash('sha256',$raw)
 ]]
];
$preflight=$c::native_notice_preflight('reservation');
email_assert($preflight['status']==='READY_READ_ONLY' && $preflight['form_id']===19
 && $preflight['bound_feed_verified']===true && $preflight['recipient_count']===1
 && $preflight['mutation_performed']===false && $preflight['messages_sent']===0
 && $preflight['authorizing']===false,
 'Exact registered booking, verified sender, current feed hash and site binding give read-only ready');
$wpdb->form_status='draft';
$preflight=$c::native_notice_preflight('reservation');
email_assert(in_array('native_form_not_published',$preflight['blockers'],true),
 'Draft booking form can never be preflight ready');
$wpdb->form_status='published';
$wpdb->read_fail=true;
$preflight=$c::native_notice_preflight('reservation');
email_assert(in_array('native_form_read_failed',$preflight['blockers'],true),
 'Native database exception returns a bounded blocker without a write');
$wpdb->read_fail=false;
NativeBookingRouterFixture::$fail=true;
$preflight=$c::native_notice_preflight('reservation');
email_assert(in_array('native_form_not_configured',$preflight['blockers'],true),
 'Booking source exception cannot authorize a guessed numeric form');
NativeBookingRouterFixture::$fail=false;
$wpdb->managed_rows[]=['id'=>79,'value'=>$raw];
$preflight=$c::native_notice_preflight('reservation');
email_assert(in_array('multiple_company_owned_feeds',$preflight['blockers'],true),
 'Duplicate managed booking notifications fail closed');
$wpdb->managed_rows=[['id'=>78,'value'=>$raw]];
$wpdb->managed_rows=array_fill(0,120,['id'=>78,'value'=>$raw]);
$preflight=$c::native_notice_preflight('reservation');
email_assert(in_array('native_feed_inventory_invalid',$preflight['blockers'],true),
 'Limit-saturated notification readback must never prove unique company ownership');
$wpdb->managed_rows=[['id'=>78,'value'=>$raw]];
$reservationRecord['sendTo']['type']='field';
$wpdb->managed_rows=[['id'=>78,'value'=>json_encode($reservationRecord)]];
$preflight=$c::native_notice_preflight('reservation');
email_assert(in_array('managed_feed_disabled_or_invalid_type',$preflight['blockers'],true),
 'Managed company native email cannot be converted to a traveler field');
email_assert($c::fluent_form_recipient('persisted@outside.test',
 $reservationRecord,[],(object)['id'=>19])==='',
 'Managed recipient filter rejects non-email transport bypass');
$reservationRecord['sendTo']['type']='email';
$reservationRecord['sendTo']['email']='legacy@outside.test';
$wpdb->managed_rows=[['id'=>78,'value'=>json_encode($reservationRecord)]];
$preflight=$c::native_notice_preflight('reservation');
email_assert(in_array('managed_feed_sender_or_recipient_drift',$preflight['blockers'],true),
 'Persisted old recipient cannot pass read-only native reconciliation preflight');
$reservationRecord['sendTo']['email']='ops@wayfatours.com';
$reservationRecord['enabled']=false;
$wpdb->managed_rows=[['id'=>78,'value'=>json_encode($reservationRecord)]];
$preflight=$c::native_notice_preflight('reservation');
email_assert(in_array('managed_feed_disabled_or_invalid_type',$preflight['blockers'],true),
 'Previously disabled native editor feed must remain disabled');
$reservationRecord['enabled']=true;
$reservationRecord['subject']='Revised booking notification'; // change SHA only.
$wpdb->managed_rows=[['id'=>78,'value'=>json_encode($reservationRecord)]];
$rawUpdated=json_encode($reservationRecord);
$recordBeforeBinding=$c::native_notice_preflight('reservation');
email_assert(in_array('managed_feed_exact_binding_missing_or_stale',$recordBeforeBinding['blockers'],true),
 'Changing a managed feed requires its matching current SHA receipt');
MAD4B_SCP_Company_Communication::$send=new WP_Error('send_gate_disabled');
$preflight=$c::native_notice_preflight('reservation');
email_assert(in_array('company_mail_transport_unverified',$preflight['blockers'],true),
 'WP_Error cannot assert native email send readiness');
MAD4B_SCP_Company_Communication::$send=true;
MAD4B_SCP_Site_Profile::$matches=new WP_Error('origin_unverified');
$preflight=$c::native_notice_preflight('reservation');
email_assert(in_array('company_email_identity_unverified',$preflight['blockers'],true),
 'Wrong site origin cannot reuse company email receipt');
MAD4B_SCP_Site_Profile::$matches=true;
$wpdb->form_id=29;
$GLOBALS['opts']['mad4b_company_contact_form_public_enabled_v1']='yes';
$contactRecord=[
 'name'=>'MAD4B Company Contact Inbox (managed)',
 'enabled'=>true,'mad4b_native_owner'=>'company_contact_v1',
 'sendTo'=>['type'=>'email','email'=>'ops@wayfatours.com'],
 'fromEmail'=>'bookings@wayfatours.com'
];
$contactRaw=json_encode($contactRecord);
$wpdb->managed_rows=[['id'=>80,'value'=>$contactRaw]];
$GLOBALS['opts'][$c::BINDINGS_OPTION]['entries']=[[
 'consumer'=>'fluent_forms','form_id'=>29,'event'=>'traveler_support',
 'notification_name'=>$contactRecord['name'],'feed_id'=>80,
 'source_sha256'=>hash('sha256',$contactRaw)
]];
$preflight=$c::native_notice_preflight('contact');
email_assert($preflight['status']==='READY_READ_ONLY' && $preflight['form_id']===29
 && $preflight['bound_feed_verified']===true,
 'Current native contact form uses its own provider and exact current feed binding');
MAD4B_Company_Contact_Delivery::$fail=true;
$preflight=$c::native_notice_preflight('contact');
email_assert(in_array('native_contact_provider_error',$preflight['blockers'],true),
 'Contact source exception cannot authorize an arbitrary form');
MAD4B_Company_Contact_Delivery::$fail=false;
$GLOBALS['opts']['mad4b_company_contact_form_public_enabled_v1']='no';
$preflight=$c::native_notice_preflight('contact');
email_assert(in_array('native_contact_public_form_disabled',$preflight['blockers'],true),
 'Disabled public contact feed cannot be treated as a ready notification');
$GLOBALS['actions']['rest_api_init']();
$rest=$GLOBALS['routes']['mad4b/v1/admin/company-notification-routing/native-preflight'];
email_assert($rest['methods']==='GET' && is_callable($rest['permission_callback']),
 'Scoped admin preflight is GET-only and has separate company permission callback');
email_assert($rest['permission_callback']()===true,'Company operations editor can read preflight');
$GLOBALS['allowCompanyOps']=false;
email_assert($rest['permission_callback']()===false,'Unauthorized operator cannot access preflight');
$GLOBALS['allowCompanyOps']=true;

echo 'PASS: COMPANY_EMAIL_ROUTING '.($GLOBALS['checks']??0).
 ' original cross-company and no-send preview checks'.PHP_EOL;
