<?php
define( 'ABSPATH', __DIR__ . '/' );
function sanitize_key($v){return strtolower(preg_replace('/[^a-z0-9_\-]/i','',(string)$v));}
function wp_json_encode($v,$flags=0){return json_encode($v,$flags);}
class WP_Error{private $c;function __construct($c,$m='',$d=array()){$this->c=$c;}function get_error_code(){return $this->c;}}
function is_wp_error($v){return $v instanceof WP_Error;}
require dirname(__DIR__).'/includes/class-mad4b-scp-provider-postcondition-profile.php';

$fail=static function($m,$v=null){fwrite(STDERR,'FAIL provider-postcondition-profile: '.$m.(null===$v?'':' '.json_encode($v)).PHP_EOL);exit(1);};
$check=static function($c,$m,$v=null)use($fail){if(!$c)$fail($m,$v);};
$profile=array(
 'contract'=>MAD4B_SCP_Provider_Postcondition_Profile::PROFILE_CONTRACT,
 'ability_name'=>'fixture/update',
 'reader_certified'=>true,
 'freshness_seconds'=>30,
 'profile_sha256'=>str_repeat('a',64),
 'authorizing'=>false,
);
$before=str_repeat('b',64);$after=str_repeat('c',64);$other=str_repeat('d',64);$now=time();

$committed=MAD4B_SCP_Provider_Postcondition_Profile::decision_from_hashes($profile,$before,$after,$after,$now);
$check('committed'===$committed['postcondition_state']&&!$committed['retry_reclaim_eligible']&&!$committed['blind_retry_allowed'],'timeout-after-side-effect was retryable',$committed);

$noeffect=MAD4B_SCP_Provider_Postcondition_Profile::decision_from_hashes($profile,$before,$after,$before,$now);
$check('no_effect'===$noeffect['postcondition_state']&&$noeffect['retry_reclaim_eligible']&&!$noeffect['blind_retry_allowed'],'lost-response no-effect did not require fresh planned retry',$noeffect);
$decision=MAD4B_SCP_Provider_Postcondition_Profile::recovery_decision($noeffect);
$check($decision['retry_reclaim_eligible']&&'fresh_plan_and_authorization_required_before_retry'===$decision['client_action'],'no-effect retry decision missing fresh-plan requirement',$decision);

$duplicate=MAD4B_SCP_Provider_Postcondition_Profile::decision_from_hashes($profile,$before,$after,$after,$now);
$check('committed'===$duplicate['postcondition_state']&&!$duplicate['retry_reclaim_eligible'],'duplicate callback replay was admitted',$duplicate);

$delay=MAD4B_SCP_Provider_Postcondition_Profile::decision_from_hashes($profile,$before,$after,$other,$now);
$check('unknown'===$delay['postcondition_state']&&$delay['reconciliation_required']&&!$delay['retry_reclaim_eligible'],'commit/readback-delay unknown state became retryable',$delay);

$stale=MAD4B_SCP_Provider_Postcondition_Profile::decision_from_hashes($profile,$before,$after,$before,$now-120);
$check('unknown'===$stale['postcondition_state']&&!$stale['fresh']&&!$stale['retry_reclaim_eligible'],'stale no-effect observation became retryable',$stale);

$unsupported=array('contract'=>MAD4B_SCP_Provider_Postcondition_Profile::PROFILE_CONTRACT,'ability_name'=>'unsupported','reader_certified'=>false,'freshness_seconds'=>0,'profile_sha256'=>str_repeat('e',64),'authorizing'=>false);
$u=MAD4B_SCP_Provider_Postcondition_Profile::decision_from_hashes($unsupported,$before,$after,$before,$now);
$check('unknown'===$u['postcondition_state']&&$u['reconciliation_required']&&!$u['retry_reclaim_eligible']&&!$u['blind_retry_allowed'],'unsupported reader did not fail closed',$u);

echo "mad4b.provider-postcondition-profile.runtime.v1: PASS\n";
