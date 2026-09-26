<?php

define( 'ABSPATH', __DIR__ . '/' );
define( 'MAD4B_SCP_DIR', dirname( __DIR__ ) . '/' );

$GLOBALS['workflow_plan_profile_fp'] = str_repeat( 'a', 64 );
$GLOBALS['workflow_plan_activation_stage'] = 'canary';
$GLOBALS['workflow_plan_write_eligible'] = true;

class WP_Error {
	private $code; private $message;
	public function __construct($code,$message=''){ $this->code=$code; $this->message=$message; }
	public function get_error_code(){ return $this->code; }
	public function get_error_message(){ return $this->message; }
}
function is_wp_error($v){ return $v instanceof WP_Error; }
function add_action($h,$c,$p=10){}
function wp_register_ability_category($n,$a){}
function wp_register_ability($n,$a){}
function wp_has_ability($n){ return in_array($n,array('bitflows/list-flows','bitflows/get-flow','bitflows/get-executions','bitflows/run-flow'),true); }
function sanitize_key($v){ return strtolower(preg_replace('/[^a-z0-9_\-]/','',str_replace('.','_',trim((string)$v)))); }
function sanitize_text_field($v){ return trim((string)$v); }
function wp_json_encode($v,$flags=0){ return json_encode($v,$flags); }
function absint($v){ return abs((int)$v); }

final class FakeWorkflowPlanAdapter {
	public function is_available(){ return true; }
	public function status(){
		return array(
			'provider_certification'=>array('runtime_contract_ok'=>true,'artifact'=>'fixture'),
			'capability_certification'=>array(
				'capabilities'=>array(
					'flows.read'=>array(
						'read_eligible'=>true,
						'write_eligible'=>false,
						'activation_stage'=>'active',
						'certification_level'=>'READ_COMPATIBLE',
					),
					'flow.execute'=>array(
						'read_eligible'=>false,
						'write_eligible'=>!empty($GLOBALS['workflow_plan_write_eligible']),
						'canary_eligible'=>true,
						'activation_stage'=>$GLOBALS['workflow_plan_activation_stage'],
						'certification_level'=>'FULLY_CERTIFIED',
						'capability_contract_digest'=>str_repeat('c',64),
					),
				),
			),
		);
	}
}
final class MAD4B_SCP_Adapter_Registry {
	private static $instance;
	private $adapter;
	public static function instance(){ if(!self::$instance) self::$instance=new self(); return self::$instance; }
	public function __construct(){ $this->adapter=new FakeWorkflowPlanAdapter(); }
	public function register_defaults(){}
	public function get($id){ return 'bitflows'===$id ? $this->adapter : null; }
}
final class MAD4B_SCP_Capability_Traits {
	public static function profile($provider,$capability){
		return array(
			'provider_id'=>$provider,
			'capability_id'=>$capability,
			'profile_fingerprint'=>$GLOBALS['workflow_plan_profile_fp'],
			'authorizing'=>false,
		);
	}
}

require dirname(__DIR__) . '/includes/class-mad4b-scp-workflow-providers.php';

$fail=static function($m){fwrite(STDERR,"FAIL workflow-provider-plan-fingerprint-contract: $m\n");exit(1);};
$check=static function($c,$m) use($fail){if(!$c)$fail($m);};

$input=array(
	'provider'=>'bitflows',
	'operation'=>'execute',
	'workflow_ref'=>'41',
	'expected_workflow_sha256'=>str_repeat('f',64),
	'reason'=>'bind exact provider profile and certification',
);
$plan1=MAD4B_SCP_Workflow_Providers::plan($input);
$check(is_array($plan1),'baseline workflow plan failed');
$check(true===$plan1['execution_ready'],'baseline certified plan not execution-ready');
$check(str_repeat('a',64)===$plan1['provider_profile_fingerprint'],'profile fingerprint not bound');
$check('canary'===$plan1['provider_release_ring'],'release ring not bound');
$check(1===preg_match('/^[a-f0-9]{64}$/',$plan1['capability_certification_fingerprint']),'certification fingerprint invalid');
$check($plan1['provider_profile_fingerprint']===$plan1['execution_binding']['provider_profile_fingerprint'],'execution binding lost profile fingerprint');
$check($plan1['capability_certification_fingerprint']===$plan1['execution_binding']['capability_certification_fingerprint'],'execution binding lost certification fingerprint');
$check($plan1['provider_release_ring']===$plan1['execution_binding']['provider_release_ring'],'execution binding lost release ring');

$GLOBALS['workflow_plan_activation_stage']='active';
$plan2=MAD4B_SCP_Workflow_Providers::plan($input);
$check(is_array($plan2),'plan after certification change failed');
$check($plan1['capability_certification_fingerprint']!==$plan2['capability_certification_fingerprint'],'certification change did not change fingerprint');
$check($plan1['plan_sha256']!==$plan2['plan_sha256'],'certification change did not invalidate plan digest');

$GLOBALS['workflow_plan_profile_fp']=str_repeat('b',64);
$plan3=MAD4B_SCP_Workflow_Providers::plan($input);
$check($plan2['provider_profile_fingerprint']!==$plan3['provider_profile_fingerprint'],'profile change not observed');
$check($plan2['plan_sha256']!==$plan3['plan_sha256'],'profile change did not invalidate plan digest');

$GLOBALS['workflow_plan_write_eligible']=false;
$blocked=MAD4B_SCP_Workflow_Providers::plan($input);
$check(is_array($blocked) && false===$blocked['execution_ready'],'lost write certification did not block execution plan');
$check('provider_capability_not_certified'===$blocked['blocker'],'lost certification blocker mismatch');

echo "mad4b.workflow-provider-plan-fingerprint.v1: PASS\n";
