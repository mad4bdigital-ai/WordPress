<?php
if ( ! defined( 'ABSPATH' ) ) define( 'ABSPATH', __DIR__ . '/' );
if ( ! defined( 'MAD4B_SCP_DIR' ) ) define( 'MAD4B_SCP_DIR', dirname( __DIR__ ) . '/' );

class WP_Error {
	private $code; private $data;
	function __construct( $code = '', $message = '', $data = array() ) { $this->code=$code; $this->data=$data; }
	function get_error_code(){ return $this->code; }
	function get_error_data(){ return $this->data; }
}
function is_wp_error( $v ){ return $v instanceof WP_Error; }
function sanitize_key( $v ){ return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $v ) ); }
function wp_json_encode( $v, $flags = 0 ){ return json_encode( $v, $flags ); }
$GLOBALS['mad4b_registered_abilities']=array(); $GLOBALS['mad4b_actions']=array();
function add_action( $hook, $callback, $priority = 10, $args = 1 ){ $GLOBALS['mad4b_actions'][]=array($hook,$callback,$priority,$args); return true; }
function wp_register_ability( $name, $args ){ $GLOBALS['mad4b_registered_abilities'][$name]=$args; return true; }
function wp_has_ability( $name ){ return false; }

class MAD4B_SCP_Policy { static function can_read(){ return true; } }
class MAD4B_SCP_Environment { static function effective(){ return 'staging'; } }
class MAD4B_SCP_Time_Policy {
	static $now=1800000000;
	static function now_epoch(){ return self::$now; }
	static function bounded_ttl($purpose,$default){ return $purpose==='production_evidence' ? 1800 : $default; }
	static function assert_timestamp($purpose,$value,$ttl=null){ return ($value>0 && $value<=self::$now+60) ? true : new WP_Error('time_invalid'); }
}
class MAD4B_SCP_Crypto_Profile {
	static function sign_digest_for_purpose($purpose,$sha){
		if($purpose!=='production_evidence') return new WP_Error('purpose_invalid');
		return array('contract'=>'mad4b.detached-signature.v1','profile_id'=>'production-evidence-rs256-v1','kid'=>'test-kid','signed_sha256'=>$sha,'signature_b64url'=>'test','authorizing'=>false);
	}
	static function verify_digest_for_purpose($sig,$sha,$purpose){
		return $purpose==='production_evidence' && isset($sig['signed_sha256']) && hash_equals($sha,(string)$sig['signed_sha256'])
			? array('valid'=>true) : new WP_Error('signature_invalid');
	}
}
class MAD4B_SCP_Execution_Receipt {
	static function verify(array $receipt){
		return isset($receipt['contract'],$receipt['receipt_signature_valid']) && $receipt['contract']==='mad4b.execution-receipt.v1' && $receipt['receipt_signature_valid']===true
			? array('valid'=>true) : new WP_Error('execution_receipt_invalid');
	}
}
class MAD4B_SCP_Live_Acceptance_Observer {
	static function build_provenance_identity_status(){
		return array('identity_ready'=>true,'source_commit_sha'=>str_repeat('a',40),'build_fingerprint'=>str_repeat('b',64),'package_manifest_digest'=>str_repeat('c',64));
	}
}
class MAD4B_SCP_Production_Certification {
	static $rows=array();
	static function execute($input=array()){
		$id=isset($input['stage_id'])?(string)$input['stage_id']:'';
		return isset(self::$rows[$id]) ? self::$rows[$id] : new WP_Error('stage_missing');
	}
}

require MAD4B_SCP_DIR . 'includes/class-mad4b-scp-production-readiness-evaluator.php';
MAD4B_SCP_Production_Readiness_Evaluator::boot();
MAD4B_SCP_Production_Readiness_Evaluator::register_ability();

function check_ready($ok,$message){ if(!$ok){ fwrite(STDERR,"PRODUCTION_READINESS_RUNTIME_FAILED:$message\n"); exit(1); } }
function expect_error($value,$code,$message){ check_ready(is_wp_error($value) && $value->get_error_code()===$code,$message); }

$ability=$GLOBALS['mad4b_registered_abilities'][MAD4B_SCP_Production_Readiness_Evaluator::ABILITY] ?? null;
check_ready(is_array($ability),'ability_not_registered');
check_ready(!empty($ability['meta']['annotations']['readonly']) && empty($ability['meta']['annotations']['destructive']),'ability_not_read_only');

$identity=array('source_commit_sha'=>str_repeat('a',40),'build_fingerprint'=>str_repeat('b',64),'package_manifest_digest'=>str_repeat('c',64));
$plan=json_decode(file_get_contents(MAD4B_SCP_DIR.'config/production-certification-plan.json'),true);
$policy=json_decode(file_get_contents(MAD4B_SCP_DIR.'config/production-readiness-policy.json'),true);
$rows=array();

foreach($plan['stages'] as $stage){
	$reversible='reversible_staging_mutation'===$stage['mutation_class'];
	$contract=$stage['producer_contract']; $method=$stage['attestation_method'];
	$producer_evidence=array('contract'=>$contract,'production_mutation'=>false,'authorizing'=>false);
	if($method==='exact_deployment_verifier'){
		$producer_evidence+=array('ready'=>true,'runtime_identity_match'=>true,'mutation_performed'=>false,'live_source_sha'=>$identity['source_commit_sha'],'expected_sha'=>$identity['source_commit_sha']);
	}elseif($method==='external_release_root_verifier'){
		$producer_evidence+=array('verified'=>true,'attestation_verified'=>true,'runtime_self_attestation_authoritative'=>false,'verification_boundary'=>'external_release_verifier')+$identity;
	}elseif($method==='rollback_retention_verifier'){
		$producer_evidence+=array('verification_source'=>'github_actions_artifact_api','expired'=>false,'artifact_expires_at'=>gmdate('c',MAD4B_SCP_Time_Policy::$now+86400));
	}elseif($method==='repository_workflow_verifier'){
		$producer_evidence+=array('conclusion'=>'success','head_sha'=>$identity['source_commit_sha']);
	}elseif($method==='durable_host_runner_receipt'){
		$producer_evidence+=array('mutation_performed'=>true,'readback_verdict'=>'PASS','approval_ref'=>'approval:test','authority_ref'=>str_repeat('d',64));
	}elseif($method==='recovery_plane_receipt'){
		$producer_evidence+=array('evidence_state'=>'DURABLE_VERIFIED_RECEIPT','readback_verified'=>true,'mutation_performed'=>true);
	}elseif($method==='governed_execution_receipt'){
		$producer_evidence+=array('receipt_signature_valid'=>true,'mutation_performed'=>true);
	}else{
		$producer_evidence+=array('candidate_identity'=>$identity,'ready'=>true,'mutation_performed'=>false);
	}
	$row=array(
		'contract'=>'mad4b.production-live-gate-evidence.v1','stage_id'=>$stage['id'],'gate'=>$stage['gate'],
		'environment'=>'staging','candidate_identity'=>$identity,'producer'=>$stage['producer'],
		'producer_contract'=>$contract,'producer_evidence'=>$producer_evidence,
		'producer_evidence_sha256'=>MAD4B_SCP_Production_Readiness_Evaluator::canonical_digest($producer_evidence),
		'ready'=>true,'mutation_performed'=>$reversible,'production_mutation'=>false,'authorizing'=>false,
	);
	if($reversible){ $row['rollback_verified']=true; $row['postcondition_verified']=true; }
	if($stage['trust_mode']==='runtime_recompute'){
		$producer_evidence['candidate_identity']=$identity; $producer_evidence['ready']=true; $producer_evidence['mutation_performed']=false;
		$row['producer_evidence']=$producer_evidence;
		$row['producer_evidence_sha256']=MAD4B_SCP_Production_Readiness_Evaluator::canonical_digest($producer_evidence);
		MAD4B_SCP_Production_Certification::$rows[$stage['id']]=$row;
	}else{
		$att=MAD4B_SCP_Production_Readiness_Evaluator::seal_verified_evidence($row,$stage,$identity);
		check_ready(!is_wp_error($att),'seal_failed_'.$stage['id']);
		$row['evidence_attestation']=$att;
	}
	$rows[]=$row;
}
$optional=array_map('strval',$policy['profiles']['control_plane_core']['optional_workstream_ids']); sort($optional,SORT_STRING);
$bundle=array('contract'=>'mad4b.production-live-evidence-bundle.v1','profile'=>'control_plane_core','environment'=>'staging','candidate_identity'=>$identity,'optional_capabilities_disabled'=>$optional,'evidence'=>$rows,'production_authorized'=>false,'authorizing'=>false);

$good=MAD4B_SCP_Production_Readiness_Evaluator::evaluate_against_identity($bundle,$identity,'staging');
check_ready(!is_wp_error($good) && !empty($good['production_ready']) && false===$good['production_authorized'],'good_bundle_not_ready');
check_ready(count($rows)===$good['trusted_evidence_gate_count'],'trusted_gate_count_drift');
check_ready($good['evidence_trust_contract']==='mad4b.production-evidence-trust.v1','trust_contract_missing');

$bad=$bundle;
foreach($bad['evidence'] as &$candidate){ if(isset($candidate['evidence_attestation'])){ unset($candidate['evidence_attestation']); break; } } unset($candidate);
expect_error(MAD4B_SCP_Production_Readiness_Evaluator::evaluate_against_identity($bad,$identity,'staging'),'mad4b_production_readiness_evidence_attestation_missing','unsigned_external_evidence_survived');

$bad=$bundle;
foreach($bad['evidence'] as &$candidate){ if(isset($candidate['evidence_attestation'])){ $candidate['producer_evidence']['tampered']=true; $candidate['producer_evidence_sha256']=MAD4B_SCP_Production_Readiness_Evaluator::canonical_digest($candidate['producer_evidence']); break; } } unset($candidate);
expect_error(MAD4B_SCP_Production_Readiness_Evaluator::evaluate_against_identity($bad,$identity,'staging'),'mad4b_production_readiness_evidence_attestation_claim_mismatch','tampered_attested_evidence_survived');

$bad=$bundle;
foreach($bad['evidence'] as &$candidate){ if(isset($candidate['evidence_attestation'])){ $candidate['evidence_attestation']['claim']['issued_at']-=3600; $candidate['evidence_attestation']['claim']['expires_at']-=3600; break; } } unset($candidate);
expect_error(MAD4B_SCP_Production_Readiness_Evaluator::evaluate_against_identity($bad,$identity,'staging'),'mad4b_production_readiness_evidence_attestation_stale','stale_attestation_survived');

$bad=$bundle; $bad['evidence'][0]['producer_contract']='mad4b.arbitrary.v1';
expect_error(MAD4B_SCP_Production_Readiness_Evaluator::evaluate_against_identity($bad,$identity,'staging'),'mad4b_production_readiness_producer_evidence_contract_invalid','arbitrary_producer_contract_survived');

$bad=$bundle;
foreach($bad['evidence'] as &$candidate){ if(!isset($candidate['evidence_attestation'])){ $candidate['producer_evidence']['ready']=false; $candidate['producer_evidence_sha256']=MAD4B_SCP_Production_Readiness_Evaluator::canonical_digest($candidate['producer_evidence']); break; } } unset($candidate);
check_ready(is_wp_error(MAD4B_SCP_Production_Readiness_Evaluator::evaluate_against_identity($bad,$identity,'staging')),'runtime_recompute_tamper_survived');

$bad=$bundle;
foreach($bad['evidence'] as &$candidate){ if(isset($candidate['evidence_attestation']) && !empty($candidate['rollback_verified'])){ $candidate['rollback_verified']=false; break; } } unset($candidate);
check_ready(is_wp_error(MAD4B_SCP_Production_Readiness_Evaluator::evaluate_against_identity($bad,$identity,'staging')),'outer_flag_tamper_survived');

$bad=$bundle; $bad['candidate_identity']['source_commit_sha']=str_repeat('d',40);
expect_error(MAD4B_SCP_Production_Readiness_Evaluator::evaluate_against_identity($bad,$identity,'staging'),'mad4b_production_readiness_runtime_identity_mismatch','identity_drift_survived');

$bad=$bundle; array_pop($bad['evidence']);
expect_error(MAD4B_SCP_Production_Readiness_Evaluator::evaluate_against_identity($bad,$identity,'staging'),'mad4b_production_readiness_gate_missing','missing_gate_survived');

$bad=$bundle; $bad['production_authorized']=true;
expect_error(MAD4B_SCP_Production_Readiness_Evaluator::evaluate_against_identity($bad,$identity,'staging'),'mad4b_production_readiness_bundle_authority_widened','authority_widening_survived');

$bad=$bundle; array_pop($bad['optional_capabilities_disabled']);
expect_error(MAD4B_SCP_Production_Readiness_Evaluator::evaluate_against_identity($bad,$identity,'staging'),'mad4b_production_readiness_optional_fail_closed_set_invalid','optional_capability_scope_drift_survived');

echo "mad4b.production-readiness-evaluator.v1: PASS\n";
