<?php
// Pure admission + Staging guard: never treat provider HTTP 400 as success.
if (!defined('ABSPATH')) define('ABSPATH','/tmp/mad4b-manual-google-recovery/');
class WP_Error {
    private $code; public function __construct($code,$message=''){$this->code=$code;}
    public function get_error_code(){return $this->code;}
}
function is_wp_error($x){return $x instanceof WP_Error;}
function current_user_can($permission){return $permission==='manage_options';}
class MAD4B_SCP_Site_Profile {
    public static function status(){return array('environment'=>'production','origin_match'=>true,
        'environment_match'=>true,'authority_ready'=>true,'site_uuid'=>'fixture-site');}
}
require dirname(__DIR__).'/includes/class-mad4b-scp-google-drive-context.php';
$stamp='2026-10-15T16:00:00Z';
$pending=array('connected'=>true,'revocation_pending'=>true,'read_available'=>false,
    'write_available'=>false,'revocation_attempted_at'=>$stamp,
    'revocation_attempts'=>array(
      array('credential_type'=>'refresh','http_status'=>400,'provider_error'=>'invalid_token'),
      array('credential_type'=>'access','http_status'=>400,'provider_error'=>'invalid_token')));
function manual_assert($ok,$description){if(!$ok){fwrite(STDERR,"FAILED: $description\n");exit(1);}}
manual_assert(true===MAD4B_SCP_Google_Drive_Context::manual_revocation_admission($pending,$stamp),'Exact two invalid_token outcomes eligible for independent manual attestation');
foreach(array('revocation_pending','connected') as $key){
  $input=$pending; $input[$key]=false;
  manual_assert(is_wp_error(MAD4B_SCP_Google_Drive_Context::manual_revocation_admission($input,$stamp)),'Disabled or unconnected pending state rejected '.$key);
}
$drift=MAD4B_SCP_Google_Drive_Context::manual_revocation_admission($pending,'2026-10-15T15:00:00Z');
manual_assert(is_wp_error($drift)&&$drift->get_error_code()==='mad4b_google_manual_revoke_snapshot_changed','CAS blocks stale snapshot');
$bad=$pending;$bad['revocation_attempts'][1]['provider_error']='invalid_grant';
manual_assert(is_wp_error(MAD4B_SCP_Google_Drive_Context::manual_revocation_admission($bad,$stamp)),'Unexpected provider reason must fail closed');
$bad=$pending;$bad['revocation_attempts'][1]['http_status']=200;
manual_assert(is_wp_error(MAD4B_SCP_Google_Drive_Context::manual_revocation_admission($bad,$stamp)),'Mixed attempts not eligible for manual proof');
$bad=$pending;unset($bad['revocation_attempts'][1]);
manual_assert(is_wp_error(MAD4B_SCP_Google_Drive_Context::manual_revocation_admission($bad,$stamp)),'One token outcome is insufficient');
$bad=$pending;$bad['read_available']=true;
manual_assert(is_wp_error(MAD4B_SCP_Google_Drive_Context::manual_revocation_admission($bad,$stamp)),'Active transport cannot enter manual cleanup');
$missing=MAD4B_SCP_Google_Drive_Context::complete_manual_revocation_attestation($stamp,'');
manual_assert(is_wp_error($missing)&&$missing->get_error_code()==='mad4b_google_manual_revoke_explicit_attestation_required','Direct invocation without typed attestation must fail');
$deny=MAD4B_SCP_Google_Drive_Context::complete_manual_revocation_attestation($stamp,'I VERIFIED GOOGLE ACCOUNT REMOVAL');
manual_assert(is_wp_error($deny)&&$deny->get_error_code()==='mad4b_google_manual_revoke_staging_required','Production cannot perform manual token deletion');
echo "GOOGLE_MANUAL_REVOCATION_ADMISSION: PASS\n";
