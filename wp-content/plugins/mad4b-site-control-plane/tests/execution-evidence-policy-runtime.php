<?php
define( 'ABSPATH', __DIR__ );
class WP_Error {
	private $code; private $data;
	public function __construct( $code, $message = '', $data = array() ) { $this->code=$code; $this->data=$data; }
	public function get_error_code(){ return $this->code; }
	public function get_error_data(){ return $this->data; }
}
function is_wp_error($v){return $v instanceof WP_Error;}
function sanitize_key($v){return strtolower(preg_replace('/[^a-z0-9_\-]/i','',(string)$v));}
function wp_json_encode($v,$flags=0){return json_encode($v,$flags);}

require dirname(__DIR__).'/includes/class-mad4b-scp-execution-evidence-policy.php';

$fail=static function($m){fwrite(STDERR,"FAIL execution-evidence-policy-runtime: {$m}\n");exit(1);};
$check=static function($c,$m)use($fail){if(!$c)$fail($m);};
$code=static function($v){return is_wp_error($v)?$v->get_error_code():'';};

$points=MAD4B_SCP_Execution_Evidence_Policy::crash_points();
$check(isset($points['intent_persisted'],$points['approval_claimed'],$points['provider_entry_possible'],$points['provider_returned'],$points['readback_verified'],$points['audit_committed'],$points['durable_receipt_committed']),'crash-point table incomplete');
foreach($points as $name=>$row){
	$check(empty($row['blind_retry_allowed'])||!isset($row['blind_retry_allowed']),'crash point enabled blind retry: '.$name);
}
$provider=MAD4B_SCP_Execution_Evidence_Policy::crash_point('provider_entry_possible');
$check(is_array($provider)&&'RECONCILING'===$provider['state']&&!empty($provider['reconciliation_required'])&&empty($provider['terminal']),'possible provider side effect was terminalized');
$done=MAD4B_SCP_Execution_Evidence_Policy::crash_point('durable_receipt_committed');
$check(is_array($done)&&'COMMITTED'===$done['state']&&!empty($done['terminal'])&&empty($done['blind_retry_allowed']),'durable receipt point is not terminal committed');
$unknown=MAD4B_SCP_Execution_Evidence_Policy::crash_point('future_unknown_point');
$check('mad4b_execution_crash_point_unknown'===$code($unknown),'unknown crash point failed open');

$huge=array(
	'reason_code'=>'provider_postcondition_unknown',
	'operation_id'=>'11111111-1111-4111-8111-111111111111',
	'target_fingerprint'=>str_repeat('a',64),
	'nested'=>array(
		'reconciliation_ref'=>'reconcile:provider:fixture',
		'result_sha256'=>str_repeat('b',64),
		'authorization_binding_digest'=>str_repeat('c',64),
	),
	'noise'=>str_repeat('x',90000),
);
$compact=MAD4B_SCP_Execution_Evidence_Policy::compact_audit_summary($huge,65536);
$check(is_array($compact)&&!empty($compact['truncated']),'oversized evidence was not compacted');
$check(strlen($compact['json'])<=65536,'compacted evidence exceeded bound');
$summary=$compact['summary'];
$check('provider_postcondition_unknown'===($summary['reason_code']??''),'top-level mandatory reason_code was lost');
$mandatory=$summary['_mandatory_paths']??array();
$check('reconcile:provider:fixture'===($mandatory['nested.reconciliation_ref']??''),'nested reconciliation_ref was lost');
$check(str_repeat('b',64)===($mandatory['nested.result_sha256']??''),'nested result digest was lost');
$check(str_repeat('c',64)===($mandatory['nested.authorization_binding_digest']??''),'nested authorization digest was lost');
$check(hash('sha256',wp_json_encode($huge,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE))===($summary['_sha256']??''),'original evidence digest mismatch');

$tooSmall=MAD4B_SCP_Execution_Evidence_Policy::compact_audit_summary(array(
	'reason_code'=>str_repeat('r',500),
	'target_fingerprint'=>str_repeat('d',500),
	'reconciliation_ref'=>str_repeat('e',500),
	'result_sha256'=>str_repeat('f',500),
	'noise'=>str_repeat('z',5000),
),512);
$check('mad4b_execution_evidence_mandatory_exceeds_bound'===$code($tooSmall),'mandatory evidence overflow was silently truncated');

echo "mad4b.execution-evidence-policy.runtime.v1: PASS\n";
