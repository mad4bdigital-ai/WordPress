<?php
define( 'ABSPATH', __DIR__ . '/' );
$tmp = sys_get_temp_dir() . '/mad4b-impact-routing-' . getmypid();
@mkdir( $tmp . '/config', 0777, true );
define( 'MAD4B_SCP_DIR', $tmp . '/' );

$GLOBALS['mad4b_resource_version'] = 'r1';
$GLOBALS['mad4b_descriptor_version'] = 'd1';
$GLOBALS['mad4b_provider_version'] = 'p1';
$GLOBALS['mad4b_impact_version'] = 'i1';
$GLOBALS['mad4b_dependency_version'] = 'g1';
$GLOBALS['mad4b_route_authority_allowed'] = true;

function add_action($hook,$callback,$priority=10,$accepted_args=1){return true;}
function wp_register_ability($name,$args){return true;}
function wp_has_ability($name){return false;}
function sanitize_key($value){return strtolower(preg_replace('/[^a-z0-9_\-]/i','',(string)$value));}
function wp_json_encode($value,$flags=0){return json_encode($value,$flags);}
function is_wp_error($value){return $value instanceof WP_Error;}

class WP_Error {
	private $code; private $message; private $data;
	public function __construct($code='',$message='',$data=array()){$this->code=(string)$code;$this->message=(string)$message;$this->data=$data;}
	public function get_error_code(){return $this->code;}
	public function get_error_message(){return $this->message;}
	public function get_error_data(){return $this->data;}
	public function add_data($data){$this->data=$data;}
}

final class MAD4B_SCP_Resource_Constraint_Set {
	public static function compile($ability,$provider,$input){return array('resource_set_sha256'=>hash('sha256','resource|'.$GLOBALS['mad4b_resource_version'].'|'.json_encode($input)));}
}
final class MAD4B_SCP_Impact_Policy {
	public static function classify($ability,$provider,$input){return array(
		'impact'=>'high','risk_tier'=>'high','side_effect_scope'=>'governed','mutation_kind'=>'mutate',
		'approval_required'=>true,'rollback_evidence_required'=>true,
		'classification_sha256'=>hash('sha256','impact|'.$GLOBALS['mad4b_impact_version'])
	);}
}
final class MAD4B_SCP_Capability_Descriptor_Registry {
	public static function describe($ability){return array(
		'descriptor_sha256'=>hash('sha256','descriptor|'.$GLOBALS['mad4b_descriptor_version']),
		'generation_roots'=>array('runtime'=>hash('sha256','generation|'.$GLOBALS['mad4b_descriptor_version']))
	);}
}
final class MAD4B_SCP_Provider_Contracts {
	public static function runtime_status($provider){return array(
		'status'=>'certified','certified_version'=>$GLOBALS['mad4b_provider_version'],'installed_version'=>$GLOBALS['mad4b_provider_version'],
		'certification_authority'=>'ci','contract_mode'=>'exact','runtime_contract_ok'=>true
	);}
}
final class MAD4B_SCP_Dependency_Impact_Graph {
	public static function inspect($input){return array(
		'wordpress_dependents'=>array('dep-'.$GLOBALS['mad4b_dependency_version']),
		'affected_addons'=>array('addon-'.$GLOBALS['mad4b_dependency_version']),
		'certification_revalidation_required'=>true,
		'impact_reasons'=>array('dependency_'.$GLOBALS['mad4b_dependency_version'])
	);}
}
final class MAD4B_SCP_Operation_Registry {
	public static function status(){return array('operations'=>array(array(
		'id'=>'content.publish','target_kind'=>'post','risk'=>'high','planner'=>'fixture/plan','executor'=>'fixture/do-write','supports'=>array('publish')
	)));}
}
final class MAD4B_SCP_Capability_Traits {
	public static function resolve($input=array()){return array('eligible'=>array(array(
		'provider_id'=>'fixture','profile_fingerprint'=>str_repeat('1',64),'certification_fingerprint'=>str_repeat('2',64),'descriptor_generation_sha256'=>str_repeat('3',64)
	)));}
}
final class MAD4B_SCP_Servers {
	public static function provider_for_ability($server,$ability){return ('mad4b-write'===$server&&'fixture/do-write'===$ability)?'fixture':null;}
}
final class MAD4B_SCP_Authorization {
	public static function probe_mutation($ability,$server,$provider='core',$input=null){
		if(empty($input))return new WP_Error('mad4b_semantic_test_input_missing','Input missing.');
		if(empty($GLOBALS['mad4b_route_authority_allowed'])){
			$error=new WP_Error('mad4b_nhi_scope_denied','Denied.',array('target_fingerprint'=>'fixture-secret-target'));
			return MAD4B_SCP_Authorization_Decision_Graph::decorate($error,$ability,$server,$provider);
		}
		$result=array(
			'allowed'=>true,'reason_code'=>'preflight_allowed','resource_set_sha256'=>str_repeat('4',64),
			'policy_decision_sha256'=>str_repeat('5',64),'target_fingerprint'=>'fixture-target-123','approval_required'=>false
		);
		return MAD4B_SCP_Authorization_Decision_Graph::decorate($result,$ability,$server,$provider);
	}
}

$config=array('providers'=>array('fixture'=>array('capabilities'=>array('publish.v1'=>array('abilities'=>array('fixture/do-write'))))));
file_put_contents($tmp.'/config/provider-capability-contracts.json',json_encode($config));

require dirname(__DIR__).'/includes/class-mad4b-scp-approval-impact-binding.php';
require dirname(__DIR__).'/includes/class-mad4b-scp-authorization-decision-graph.php';
require dirname(__DIR__).'/includes/class-mad4b-scp-execution-receipt.php';
require dirname(__DIR__).'/includes/class-mad4b-scp-semantic-intent-router.php';

$fail=static function($m,$v=null){fwrite(STDERR,'FAIL impact-approval-receipt-routing-runtime: '.$m.(null===$v?'':' '.json_encode($v)).PHP_EOL);exit(1);};
$check=static function($c,$m,$v=null)use($fail){if(!$c)$fail($m,$v);};
$code=static function($v){return is_wp_error($v)?$v->get_error_code():'';};

$input=array('post_id'=>123,'post_title'=>'Hello');
$base=MAD4B_SCP_Approval_Impact_Binding::build('fixture/do-write','fixture','target-1',$input);
$check(is_array($base)&&64===strlen($base['exact_input_sha256'])&&64===strlen($base['resource_set_sha256'])&&64===strlen($base['dependency_generation_sha256'])&&64===strlen($base['impact_sha256'])&&!$base['authorizing'],'approval impact binding is incomplete',$base);

$input_changed=MAD4B_SCP_Approval_Impact_Binding::build('fixture/do-write','fixture','target-1',array('post_id'=>123,'post_title'=>'Changed'));
$check($base['exact_input_sha256']!==$input_changed['exact_input_sha256']&&$base['binding_sha256']!==$input_changed['binding_sha256'],'exact input drift did not invalidate approval binding');

$GLOBALS['mad4b_resource_version']='r2';
$resource_changed=MAD4B_SCP_Approval_Impact_Binding::build('fixture/do-write','fixture','target-1',$input);
$check($base['resource_set_sha256']!==$resource_changed['resource_set_sha256']&&$base['binding_sha256']!==$resource_changed['binding_sha256'],'resource-set drift did not invalidate approval binding');
$GLOBALS['mad4b_resource_version']='r1';

$GLOBALS['mad4b_provider_version']='p2';
$generation_changed=MAD4B_SCP_Approval_Impact_Binding::build('fixture/do-write','fixture','target-1',$input);
$check($base['dependency_generation_sha256']!==$generation_changed['dependency_generation_sha256']&&$base['binding_sha256']!==$generation_changed['binding_sha256'],'dependency generation drift did not invalidate approval binding');
$GLOBALS['mad4b_provider_version']='p1';

$GLOBALS['mad4b_impact_version']='i2';
$impact_changed=MAD4B_SCP_Approval_Impact_Binding::build('fixture/do-write','fixture','target-1',$input);
$check($base['impact_sha256']!==$impact_changed['impact_sha256']&&$base['binding_sha256']!==$impact_changed['binding_sha256'],'impact drift did not invalidate approval binding');
$GLOBALS['mad4b_impact_version']='i1';

$target_changed=MAD4B_SCP_Approval_Impact_Binding::build('fixture/do-write','fixture','target-2',$input);
$check($base['impact_sha256']!==$target_changed['impact_sha256']&&$base['binding_sha256']!==$target_changed['binding_sha256'],'target fingerprint drift did not invalidate approval binding');

$success=MAD4B_SCP_Authorization_Decision_Graph::decorate(array(
	'allowed'=>true,'reason_code'=>'preflight_allowed','request_id'=>'req-1','subject_fingerprint'=>'subject-secret',
	'grant_id'=>9,'capability_descriptor_sha256'=>str_repeat('a',64),'resource_set_sha256'=>str_repeat('b',64),
	'target_fingerprint'=>'target-secret','approval_ticket_id'=>'11111111-1111-4111-8111-111111111111',
	'approval_impact_binding_sha256'=>str_repeat('c',64),'policy_decision_sha256'=>str_repeat('d',64)
),'fixture/do-write','mad4b-write','fixture');
$graph=$success['authorization_decision_graph'];
$check('PASS'===$graph['decision']&&8===count($graph['steps'])&&!$graph['authorizing'],'success decision graph is invalid',$graph);
foreach($graph['steps'] as $step)foreach($step['evidence_refs'] as $ref)$check(!isset($ref['value'])&&64===strlen($ref['sha256'])&&!empty($ref['redacted']),'decision graph leaked raw evidence',$ref);

$denied=new WP_Error('mad4b_approval_required','Approval required.',array('approval_ticket_id'=>'secret-ticket','approval_impact_binding_sha256'=>str_repeat('e',64)));
$denied=MAD4B_SCP_Authorization_Decision_Graph::decorate($denied,'fixture/do-write','mad4b-write','fixture');
$data=$denied->get_error_data();$dgraph=$data['authorization_decision_graph'];
$impact_step=null;$policy_step=null;
foreach($dgraph['steps'] as $step){if('impact_approval'===$step['stage'])$impact_step=$step;if('policy_resolution'===$step['stage'])$policy_step=$step;}
$check('FAIL'===$impact_step['status']&&'NOT_EVALUATED'===$policy_step['status']&&!empty($impact_step['evidence_refs']),'failure decision graph did not preserve redacted impact evidence',$dgraph);
$check('secret-ticket'!==$impact_step['evidence_refs'][0]['sha256'],'failure graph exposed raw ticket identity');

$claim=array(
	'ability'=>'fixture/do-write','provider'=>'fixture','request_id'=>'req-1','target_fingerprint'=>'target-1',
	'resource_set_sha256'=>str_repeat('1',64),'approval_required'=>true,'approval_ticket_id'=>'11111111-1111-4111-8111-111111111111',
	'approval_impact_binding_sha256'=>str_repeat('2',64),'context_receipt_sha256'=>str_repeat('3',64),
	'capability_descriptor_sha256'=>str_repeat('4',64),'policy_decision_sha256'=>str_repeat('5',64),
	'idempotency_key'=>'idem-1','operation_id'=>'22222222-2222-4222-8222-222222222222'
);
$terminal=array('receipt_id'=>'receipt:v1:'.str_repeat('6',64),'receipt_sha256'=>str_repeat('6',64),'terminal_material_sha256'=>str_repeat('7',64));
$receipt=MAD4B_SCP_Execution_Receipt::build($claim,array('readback'=>array('ok'=>true)),$terminal);
$check(is_array($receipt)&&0===strpos($receipt['receipt_id'],'execution-receipt:v1:')&&'PASS'===$receipt['stages']['approval']['status'],'execution receipt build failed',$receipt);
$verification=MAD4B_SCP_Execution_Receipt::verify($receipt);
$check(is_array($verification)&&!empty($verification['valid'])&&false===$verification['cryptographic_signature_verified'],'execution receipt independent integrity verification failed',$verification);
$export=MAD4B_SCP_Execution_Receipt::export($receipt);
$check(is_array($export)&&'mad4b.execution-receipt-export.v1'===$export['contract'],'execution receipt export failed',$export);

$missing=$claim;unset($missing['context_receipt_sha256']);
$missing_receipt=MAD4B_SCP_Execution_Receipt::build($missing,array(),$terminal);
$check('mad4b_execution_receipt_required_stage_missing'===$code($missing_receipt),'missing preparation stage reached terminal success',$missing_receipt);
$tampered=$receipt;$tampered['ability']='fixture/tampered';
$check('mad4b_execution_receipt_integrity_invalid'===$code(MAD4B_SCP_Execution_Receipt::verify($tampered)),'tampered receipt passed integrity verification');
$bad_id=$receipt;$bad_id['receipt_id']='execution-receipt:v1:'.str_repeat('f',64);
$check('mad4b_execution_receipt_identity_invalid'===$code(MAD4B_SCP_Execution_Receipt::verify($bad_id)),'receipt id detached from digest');

$route=MAD4B_SCP_Semantic_Intent_Router::route(array('intent'=>'publish','target_kind'=>'post','execution_input'=>array('post_id'=>123)));
$check(is_array($route)&&1===$route['eligible_count']&&!empty($route['selected'])&&!$route['authorizing']&&!$route['authority_created']&&!$route['mutation_performed'],'authorized semantic route did not select exact candidate',$route);
$check(!empty($route['selected']['authority_eligible'])&&'mad4b-write'===$route['selected']['server_id'],'semantic route selection lacks current authority proof',$route['selected']);

$GLOBALS['mad4b_route_authority_allowed']=false;
$denied_route=MAD4B_SCP_Semantic_Intent_Router::route(array('intent'=>'publish','target_kind'=>'post','execution_input'=>array('post_id'=>123)));
$check(0===$denied_route['eligible_count']&&empty($denied_route['selected'])&&!empty($denied_route['human_review_required']),'semantic router selected a currently unauthorized capability',$denied_route);
$check('mad4b_nhi_scope_denied'===$denied_route['candidates'][0]['reason_code'],'semantic router hid current authority denial reason',$denied_route['candidates'][0]);

$GLOBALS['mad4b_route_authority_allowed']=true;
$missing_input_route=MAD4B_SCP_Semantic_Intent_Router::route(array('intent'=>'publish','target_kind'=>'post'));
$check(0===$missing_input_route['eligible_count']&&'semantic_exact_execution_input_required'===$missing_input_route['candidates'][0]['reason_code'],'semantic router selected without exact execution/resource input',$missing_input_route);

@unlink($tmp.'/config/provider-capability-contracts.json');@rmdir($tmp.'/config');@rmdir($tmp);
echo "mad4b.impact-approval-receipt-routing.runtime.v1: PASS\n";
