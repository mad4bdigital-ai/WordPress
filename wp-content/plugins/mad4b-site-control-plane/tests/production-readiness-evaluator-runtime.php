<?php
if ( ! defined( 'ABSPATH' ) ) define( 'ABSPATH', __DIR__ . '/' );
if ( ! defined( 'MAD4B_SCP_DIR' ) ) define( 'MAD4B_SCP_DIR', dirname( __DIR__ ) . '/' );

class WP_Error {
	private $code; private $message; private $data;
	function __construct( $code = '', $message = '', $data = array() ) { $this->code=$code; $this->message=$message; $this->data=$data; }
	function get_error_code(){ return $this->code; }
	function get_error_data(){ return $this->data; }
}
function is_wp_error( $v ){ return $v instanceof WP_Error; }
function sanitize_key( $v ){ return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $v ) ); }
function wp_json_encode( $v, $flags = 0 ){ return json_encode( $v, $flags ); }
$GLOBALS['mad4b_registered_abilities']=array();
$GLOBALS['mad4b_actions']=array();
function add_action( $hook, $callback, $priority = 10, $args = 1 ){ $GLOBALS['mad4b_actions'][]=array($hook,$callback,$priority,$args); return true; }
function wp_register_ability( $name, $args ){ $GLOBALS['mad4b_registered_abilities'][$name]=$args; return true; }
function wp_has_ability( $name ){ return false; }
class MAD4B_SCP_Policy { static function can_read(){ return true; } }
class MAD4B_SCP_Environment { static function effective(){ return 'staging'; } }
class MAD4B_SCP_Live_Acceptance_Observer {
	static function build_provenance_identity_status(){
		return array(
			'identity_ready'=>true,
			'source_commit_sha'=>str_repeat('a',40),
			'build_fingerprint'=>str_repeat('b',64),
			'package_manifest_digest'=>str_repeat('c',64),
		);
	}
}

require MAD4B_SCP_DIR . 'includes/class-mad4b-scp-production-readiness-evaluator.php';
MAD4B_SCP_Production_Readiness_Evaluator::boot();
MAD4B_SCP_Production_Readiness_Evaluator::register_ability();

function check_ready( $ok, $message ){ if ( ! $ok ) { fwrite(STDERR,"PRODUCTION_READINESS_RUNTIME_FAILED:$message\n"); exit(1); } }
$ability=$GLOBALS['mad4b_registered_abilities'][MAD4B_SCP_Production_Readiness_Evaluator::ABILITY] ?? null;
check_ready( is_array($ability), 'ability_not_registered' );
check_ready( !empty($ability['meta']['annotations']['readonly']) && empty($ability['meta']['annotations']['destructive']), 'ability_not_read_only' );

$identity=array('source_commit_sha'=>str_repeat('a',40),'build_fingerprint'=>str_repeat('b',64),'package_manifest_digest'=>str_repeat('c',64));
$plan=json_decode(file_get_contents(MAD4B_SCP_DIR.'config/production-certification-plan.json'),true);
$policy=json_decode(file_get_contents(MAD4B_SCP_DIR.'config/production-readiness-policy.json'),true);
$rows=array();
foreach($plan['stages'] as $stage){
	$reversible='reversible_staging_mutation'===$stage['mutation_class'];
	$producer_contract='mad4b.production-live-gate-evidence.v1'===$stage['evidence_contract'] ? 'mad4b.synthetic-runtime-test.v1' : $stage['evidence_contract'];
	$producer_evidence=array(
		'contract'=>$producer_contract,
		'candidate_identity'=>$identity,
		'ready'=>true,
		'mutation_performed'=>$reversible,
		'production_mutation'=>false,
		'authorizing'=>false,
	);
	if($reversible){ $producer_evidence['rollback_verified']=true; $producer_evidence['postcondition_verified']=true; }
	$row=array(
		'contract'=>'mad4b.production-live-gate-evidence.v1',
		'gate'=>$stage['gate'],
		'environment'=>'staging',
		'candidate_identity'=>$identity,
		'producer'=>$stage['producer'],
		'producer_contract'=>$producer_contract,
		'producer_evidence'=>$producer_evidence,
		'producer_evidence_sha256'=>MAD4B_SCP_Production_Readiness_Evaluator::canonical_digest($producer_evidence),
		'ready'=>true,
		'mutation_performed'=>$reversible,
		'production_mutation'=>false,
		'authorizing'=>false,
	);
	if($reversible){ $row['rollback_verified']=true; $row['postcondition_verified']=true; }
	$rows[]=$row;
}
$optional=array_map('strval',$policy['profiles']['control_plane_core']['optional_workstream_ids']);
sort($optional,SORT_STRING);
$bundle=array(
	'contract'=>'mad4b.production-live-evidence-bundle.v1',
	'profile'=>'control_plane_core',
	'environment'=>'staging',
	'candidate_identity'=>$identity,
	'optional_capabilities_disabled'=>$optional,
	'evidence'=>$rows,
	'production_authorized'=>false,
	'authorizing'=>false,
);
$good=MAD4B_SCP_Production_Readiness_Evaluator::evaluate_against_identity($bundle,$identity,'staging');
check_ready( !is_wp_error($good) && !empty($good['production_ready']) && false===$good['production_authorized'], 'good_bundle_not_ready' );
check_ready( count($rows)===$good['evidence_gate_count'], 'gate_count_drift' );

$bad=$bundle; $bad['candidate_identity']['source_commit_sha']=str_repeat('d',40);
$result=MAD4B_SCP_Production_Readiness_Evaluator::evaluate_against_identity($bad,$identity,'staging');
check_ready( is_wp_error($result) && 'mad4b_production_readiness_runtime_identity_mismatch'===$result->get_error_code(), 'identity_drift_survived' );

$bad=$bundle; array_pop($bad['evidence']);
$result=MAD4B_SCP_Production_Readiness_Evaluator::evaluate_against_identity($bad,$identity,'staging');
check_ready( is_wp_error($result) && 'mad4b_production_readiness_gate_missing'===$result->get_error_code(), 'missing_gate_survived' );

$bad=$bundle; $bad['evidence'][0]['producer_evidence']['ready']=false;
$result=MAD4B_SCP_Production_Readiness_Evaluator::evaluate_against_identity($bad,$identity,'staging');
check_ready( is_wp_error($result) && 'mad4b_production_readiness_producer_evidence_digest_mismatch'===$result->get_error_code(), 'tampered_evidence_survived' );

$bad=$bundle; $bad['production_authorized']=true;
$result=MAD4B_SCP_Production_Readiness_Evaluator::evaluate_against_identity($bad,$identity,'staging');
check_ready( is_wp_error($result) && 'mad4b_production_readiness_bundle_authority_widened'===$result->get_error_code(), 'authority_widening_survived' );

$bad=$bundle; array_pop($bad['optional_capabilities_disabled']);
$result=MAD4B_SCP_Production_Readiness_Evaluator::evaluate_against_identity($bad,$identity,'staging');
check_ready( is_wp_error($result) && 'mad4b_production_readiness_optional_fail_closed_set_invalid'===$result->get_error_code(), 'optional_capability_scope_drift_survived' );

echo "mad4b.production-readiness-evaluator.v1: PASS\n";
