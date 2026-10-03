<?php
define( 'ABSPATH', __DIR__ . '/' );
define( 'MAD4B_SCP_DIR', dirname(__DIR__) . '/' );
class WP_Error { private $code; public function __construct($c,$m='',$d=null){$this->code=(string)$c;} public function get_error_code(){return $this->code;} }
function is_wp_error($v){return $v instanceof WP_Error;}
function get_bloginfo($key){return 'version'===$key?'7.1.2':'';}
require dirname(__DIR__).'/includes/class-mad4b-scp-wordpress-lifecycle-migration-profile.php';

$fail=static function($m,$v=null){fwrite(STDERR,'FAIL wordpress-lifecycle-migration-profile-runtime: '.$m.(null===$v?'':' '.json_encode($v)).PHP_EOL);exit(1);};
$check=static function($c,$m,$v=null)use($fail){if(!$c)$fail($m,$v);};

$status=MAD4B_SCP_WordPress_Lifecycle_Migration_Profile::status();
$check(is_array($status)&&!empty($status['native_hooks_available'])&&!empty($status['legacy_provenance_authoritative']),'Native hook availability/legacy authority status is invalid.',$status);
$check(empty($status['cutover_eligible'])&&empty($status['automatic_cutover'])&&empty($status['replacement_performed']),'Lifecycle migration performed an implicit cutover.',$status);

$sha=hash('sha256','parity');
$incomplete=MAD4B_SCP_WordPress_Lifecycle_Migration_Profile::evaluate_parity(array(
 'legacy_behavior_sha256'=>$sha,'native_shadow_behavior_sha256'=>$sha,
 'legacy_provenance_sha256'=>$sha,'native_registration_provenance_sha256'=>$sha,
));
$check(is_array($incomplete)&&empty($incomplete['parity_certified'])&&!empty($incomplete['missing_requirements']),'Incomplete lifecycle evidence certified parity.',$incomplete);

$complete=array(
 'invocation_identity'=>true,'pre_execute_observation'=>true,'permission_result'=>true,'execute_result'=>true,
 'registration_provenance_equivalent'=>true,'final_execution_boundary_equivalent'=>true,
 'fixed_dispatch_semantics_equivalent'=>true,'no_authority_widening'=>true,
 'legacy_behavior_sha256'=>$sha,'native_shadow_behavior_sha256'=>$sha,
 'legacy_provenance_sha256'=>$sha,'native_registration_provenance_sha256'=>$sha,
);
$cert=MAD4B_SCP_WordPress_Lifecycle_Migration_Profile::evaluate_parity($complete);
$check(is_array($cert)&&!empty($cert['parity_certified'])&&!empty($cert['cutover_eligible']),'Complete exact parity evidence did not certify.',$cert);
$check(!empty($cert['legacy_provenance_authoritative'])&&empty($cert['replacement_performed'])&&empty($cert['authority_effect']),'Parity certification replaced or authorized provenance unexpectedly.',$cert);

$tampered=$complete; $tampered['native_shadow_behavior_sha256']=hash('sha256','different');
$cert=MAD4B_SCP_WordPress_Lifecycle_Migration_Profile::evaluate_parity($tampered);
$check(is_array($cert)&&empty($cert['parity_certified'])&&in_array('behavior_digest_parity',$cert['missing_requirements'],true),'Behavior drift was not caught.',$cert);

echo "mad4b.wordpress-lifecycle-migration-profile.runtime.v1: PASS\n";
