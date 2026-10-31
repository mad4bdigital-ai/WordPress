<?php
define('ABSPATH','/tmp/');
class WP_Error {public function __construct(public $code='',public $message='',public $data=[]){}}
function is_wp_error($v){return $v instanceof WP_Error;}
function absint($x){return abs((int)$x);}
function sanitize_email($email){return filter_var($email,FILTER_SANITIZE_EMAIL);}
function is_email($email){return filter_var($email,FILTER_VALIDATE_EMAIL)!==false;}
function add_filter(...$args){}
function add_action(...$args){}
function get_option($key,$default=''){return $GLOBALS['options'][$key]??$default;}
function wp_json_encode($v,$flags=0){return json_encode($v,$flags);}
function wp_get_environment_type(){return 'staging';}
function get_post($id){return $GLOBALS['posts'][$id]??null;}
function check($v,$label){if(!$v)throw new Exception($label);}
class MAD4B_SCP_Company_Communication {
 public static function config(){return ['transactional_from_email'=>'bookings@example.test','company_display_name'=>'Example','traveler_reply_to'=>'info@example.test','bookings_inbox'=>'office@example.test'];}
 public static function smtpConfigured(){return false;}
}
class MAD4B_SCP_Site_Profile {
 public static function profile(){return ['site_uuid'=>'site-123'];}
 public static function site_urls_match_enrollment(){return true;}
}
require __DIR__.'/../wp-content/plugins/mad4b-site-control-plane/includes/class-mad4b-scp-company-email-routing.php';
require __DIR__.'/../wp-content/plugins/mad4b-site-control-plane/includes/class-mad4b-scp-migration-decision-catalog.php';
$email='MAD4B_SCP_Company_Email_Routing';
$status=$email::identity();
check($status['status']==='PENDING_VERIFICATION'&&!$status['verified'],'unverified sender treated verified');
check($status['email_domain']==='example.test','company domain not retained');
$GLOBALS['options'][$email::BINDINGS_OPTION]=['contract'=>'mad4b.company-notification-bindings.v1','site_uuid'=>'foreign-site','identity_sha256'=>$status['identity_sha256'],'entries'=>[['recipient'=>'other@example.test']]];
check($email::bindings()===[],'foreign tenant bound notification');
$GLOBALS['options'][$email::VERIFY_OPTION]=['contract'=>'mad4b.company-email-verification.v1','site_uuid'=>'site-123','identity_sha256'=>str_repeat('a',64),'status'=>'VERIFIED','evidence_digest'=>'digest','verified_at'=>'now','verifier_user_id'=>1];
check($email::identity()['verified']===false,'stale identity signature accepted');
$catalog='MAD4B_SCP_Migration_Decision_Catalog';
check(count($catalog::definitions())>=12,'migration asset categories missing');
check(count($catalog::choices())>=6,'migration decisions missing');
check($catalog::asset_supported('brand_kit')===true,'known asset rejected');
check($catalog::asset_supported('unexpected')===false,'unknown asset accepted');
check($catalog::asset_supported('page_9999')===false,'unknown page accepted');
check($catalog::valid_page(9999)===false,'missing legal page accepted');
check($catalog::page_key(7)==='page_7','page key not stable');
echo "COMPANY_EMAIL_MIGRATION_RUNTIME PASS tenant identity, proof binding, catalog scope\n";
