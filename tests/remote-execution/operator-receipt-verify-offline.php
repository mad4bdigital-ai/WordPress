<?php
/**
 * Offline test only: $argv = signed-job.json receipt.json site-pub.pem worker-pub.pem.
 * All evidence synthetic, no WordPress bootstrap or persistent state changed.
 */
define( 'ABSPATH', __DIR__ . '/../../' );
define( 'MAD4B_OPERATOR_SIGNER_PUBLIC_KEY_PATH', $argv[3] );
define( 'MAD4B_OPERATOR_WORKER_PUBLIC_KEY_PATH', $argv[4] );
class WP_Error {
    private $code;
    public function __construct($code, $message='') { $this->code=$code; }
    public function get_error_code() { return $this->code; }
}
function is_wp_error($x) { return $x instanceof WP_Error; }
function wp_get_environment_type() { return 'staging'; }
function home_url() { return 'https://fixture-site.example.test'; }
class MAD4B_SCP_Site_Profile {
    public static function configured() { return true; }
    public static function origin_enrolled() { return true; }
    public static function site_urls_match_enrollment() { return true; }
}
function wp_json_encode($v,$flags=0) { return json_encode($v,$flags); }
require_once __DIR__ . '/../../wp-content/plugins/mad4b-site-control-plane/includes/class-mad4b-scp-operator-receipt-verifier.php';
$job=json_decode(file_get_contents($argv[1]), true);
$receipt=json_decode(file_get_contents($argv[2]), true);
if (!is_array($job) || !is_array($receipt)) { fwrite(STDERR,"fixture_invalid\n"); exit(2); }
$id=$job['submission']['job_id'];
$target=array('target_fingerprint'=>str_repeat('a',64));
$check=function($row,$seal,$allow,$label) use($id,$target) {
    $out=MAD4B_SCP_Operator_Receipt_Verifier::verify($row,$seal,$id,$target);
    $ok=!is_wp_error($out) && !empty($out['verified']);
    if ($ok!==$allow) { fwrite(STDERR,"FAIL $label\n"); exit(3); }
    echo "PASS $label ",($ok?'verified':'denied'),PHP_EOL;
};
$check($receipt,$job['seal'],true,'independent_dual_rsa_signatures');
$changed=$receipt;
$changed['body']['verdict']='FAIL';
$check($changed,$job['seal'],false,'modified_worker_result_denied');
$changed=$job['seal'];
$changed['body']['target_fingerprint']=str_repeat('b',64);
$check($receipt,$changed,false,'modified_original_job_denied');
