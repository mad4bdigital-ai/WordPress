<?php

$tmp = sys_get_temp_dir() . '/mad4b-host-bridge-' . bin2hex(random_bytes(4));
@mkdir($tmp . '/wp-content', 0777, true);
file_put_contents($tmp . '/wp-config.php', "<?php // bridge fixture\n");

define('ABSPATH', $tmp . '/');
define('WP_CONTENT_DIR', $tmp . '/wp-content');

class WP_Error {
	private $code; private $message;
	public function __construct($code,$message=''){ $this->code=$code; $this->message=$message; }
	public function get_error_code(){ return $this->code; }
	public function get_error_message(){ return $this->message; }
}
function is_wp_error($v){ return $v instanceof WP_Error; }
function sanitize_key($v){ return strtolower(preg_replace('/[^a-z0-9_\-]/','',(string)$v)); }
function wp_json_encode($v,$flags=0){ return json_encode($v,$flags); }
function wp_generate_uuid4(){
	$d=random_bytes(16); $d[6]=chr((ord($d[6])&0x0f)|0x40); $d[8]=chr((ord($d[8])&0x3f)|0x80);
	return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($d),4));
}
function wp_mkdir_p($dir){ return is_dir($dir) || mkdir($dir,0777,true); }
function wp_get_environment_type(){ return 'staging'; }
function add_action($a,$b,$c=null){}
function wp_register_ability($a,$b){}
function wp_has_ability($a){ return false; }

final class MAD4B_SCP_Site_Profile {
	public static function site_uuid(){ return '11111111-2222-4333-8444-555555555555'; }
}
final class MAD4B_SCP_Policy {
	public static function can_read(){ return true; }
	public static function can_mutate(){ return true; }
}
final class MAD4B_SCP_Authorization {
	public static $calls = array();
	public static function claim_mutation($ability,$server,$provider,$input){
		self::$calls[] = compact('ability','server','provider','input');
		return array(
			'allowed'=>true,
			'policy_decision_sha256'=>str_repeat('a',64),
			'agent_public_id'=>'agent-ci',
			'approval_ticket_id'=>'aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee',
		);
	}
}

require dirname(__DIR__) . '/includes/class-mad4b-scp-host-bridge.php';

$fail = static function($m) use ($tmp){
	fwrite(STDERR, "FAIL host-bridge-contract: $m\n");
	exec('rm -rf ' . escapeshellarg($tmp));
	exit(1);
};
$check = static function($c,$m) use ($fail){ if(!$c)$fail($m); };

$cap = MAD4B_SCP_Host_Bridge::capabilities();
$check(false === $cap['generic_shell_available'], 'generic shell exposed');
$check(false === $cap['raw_sql_available'], 'raw SQL exposed');
$check(false === $cap['caller_command_strings_allowed'], 'command strings exposed');
$check(false === $cap['production_authorized'], 'Production authority widened');

$plan = MAD4B_SCP_Host_Bridge::plan(array(
	'operation_id'=>'runtime.status.read',
	'runner_profile_id'=>'ci-runner',
	'arguments'=>array(),
));
$check(is_array($plan) && 64===strlen($plan['plan_sha256']), 'read plan failed');
$check('wordpress_request'===$plan['submission_location'], 'submission location false');
$check('host_runner'===$plan['execution_location'], 'execution location false');
$check(false===$plan['mutation_performed'], 'planning mutated');

$job='aaaaaaaa-1111-4222-8333-bbbbbbbbbbbb';
$apply = MAD4B_SCP_Host_Bridge::apply(array(
	'plan'=>$plan,
	'job_id'=>$job,
	'idempotency_key'=>'idem-read-1',
));
$check(is_array($apply) && true===$apply['queued'], 'read apply did not queue');
$check(false===$apply['production_authorized'], 'queued submission widened Production');
$check('host_runner'===$apply['execution_location'], 'queued execution location false');

$replay = MAD4B_SCP_Host_Bridge::apply(array(
	'plan'=>$plan,
	'job_id'=>$job,
	'idempotency_key'=>'idem-read-1',
));
$check(true===$replay['replayed'], 'exact replay was not idempotent');
$check($replay['submission_sha256']===$apply['submission_sha256'], 'exact replay digest drifted');

$conflict = MAD4B_SCP_Host_Bridge::apply(array(
	'plan'=>$plan,
	'job_id'=>$job,
	'idempotency_key'=>'different-idempotency',
));
$check(is_wp_error($conflict) && 'mad4b_host_job_replay_conflict'===$conflict->get_error_code(), 'job replay conflict not denied');

$status = MAD4B_SCP_Host_Bridge::status(array('job_id'=>$job));
$check(is_array($status) && 'queued'===$status['state'], 'queued status unavailable');

$doctor = MAD4B_SCP_Host_Bridge::doctor();
$check(1===$doctor['counts']['queued'], 'doctor queue count mismatch');
$check(false===$doctor['operator_review_required'], 'doctor demanded review without incident');

$cancel = MAD4B_SCP_Host_Bridge::cancel(array('job_id'=>$job));
$check(is_array($cancel) && true===$cancel['cooperative_only'], 'cancel did not remain cooperative');
$check(false===$cancel['external_side_effect_stopped'], 'cancel falsely claimed external stop');
$status = MAD4B_SCP_Host_Bridge::status(array('job_id'=>$job));
$check('cancelled'===$status['state'], 'cancelled status unavailable');

// Write submissions require approval + normal Authorization claim.
$nested = array(
	'contract'=>'mad4b.host-runner-workspace-replace-plan.v1',
	'operation_id'=>'workspace.file.replace',
	'plan_sha256'=>str_repeat('b',64),
);
$wplan = MAD4B_SCP_Host_Bridge::plan(array(
	'operation_id'=>'workspace.file.replace',
	'runner_profile_id'=>'ci-runner',
	'arguments'=>array('plan'=>$nested,'new_content_b64'=>'YQ=='),
));
$denied = MAD4B_SCP_Host_Bridge::apply(array(
	'plan'=>$wplan,
	'job_id'=>'cccccccc-1111-4222-8333-dddddddddddd',
	'idempotency_key'=>'idem-write-no-approval',
	'server_id'=>'mad4b-primary',
));
$check(is_wp_error($denied) && 'mad4b_host_operation_approval_required'===$denied->get_error_code(), 'write without approval was accepted');

$write = MAD4B_SCP_Host_Bridge::apply(array(
	'plan'=>$wplan,
	'job_id'=>'eeeeeeee-1111-4222-8333-ffffffffffff',
	'idempotency_key'=>'idem-write-1',
	'approval_ref'=>'approval:ci',
	'server_id'=>'mad4b-primary',
	'_mad4b_approval_ticket_id'=>'aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee',
));
$check(is_array($write) && true===$write['queued'], 'authorized write did not queue');
$check(1===count(MAD4B_SCP_Authorization::$calls), 'write did not use Authorization claim');
$check('mad4b/host-operation-apply'===MAD4B_SCP_Authorization::$calls[0]['ability'], 'wrong authorization ability');
$check(str_repeat('a',64)===$write['authority']['policy_decision_sha256'], 'policy decision evidence missing');

// Stale target plan must fail after root identity changes.
file_put_contents($tmp . '/wp-config.php', "<?php // changed target\n");
$stale = MAD4B_SCP_Host_Bridge::apply(array(
	'plan'=>$plan,
	'job_id'=>'99999999-1111-4222-8333-999999999999',
	'idempotency_key'=>'idem-stale',
));
$check(is_wp_error($stale) && 'mad4b_host_plan_target_stale'===$stale->get_error_code(), 'stale target plan accepted');

$source = file_get_contents(dirname(__DIR__) . '/includes/class-mad4b-scp-host-bridge.php');
foreach(array('shell_exec(','exec(','proc_open(','passthru(','system(','eval(','WP_CLI::runcommand') as $forbidden){
	$check(false===strpos($source,$forbidden), 'forbidden execution primitive present: '.$forbidden);
}

exec('rm -rf ' . escapeshellarg($tmp));
echo "mad4b.host-bridge.v1: PASS\n";
