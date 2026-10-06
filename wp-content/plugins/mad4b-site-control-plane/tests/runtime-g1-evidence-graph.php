<?php
define('ABSPATH', __DIR__ . '/');
define('MAD4B_SCP_DIR', dirname(__DIR__) . '/');

$GLOBALS['g1_options']=array();
$GLOBALS['g1_conformance_receipt']=array();
$GLOBALS['g1_provider_version']='1.0.0';
$GLOBALS['g1_audit_ready']=true;
$GLOBALS['g1_audit_records']=array();

function add_action(){ return true; }
function sanitize_key($v){ return strtolower(preg_replace('/[^a-z0-9_-]/','',(string)$v)); }
function sanitize_textarea_field($v){ return trim((string)$v); }
function absint($v){ return abs((int)$v); }
function wp_json_encode($v,$flags=0){ return json_encode($v,$flags); }
function current_user_can(){ return true; }
function get_current_user_id(){ return 7; }
function current_time($type='mysql',$gmt=false){ return '2026-10-06 16:00:00'; }
function wp_generate_uuid4(){ static $i=0; $i++; return sprintf('12345678-1234-4234-8234-%012d',$i); }
function add_option($name,$value,$deprecated='',$autoload=false){
 if(array_key_exists($name,$GLOBALS['g1_options'])) return false;
 $GLOBALS['g1_options'][$name]=$value; return true;
}
function update_option($name,$value,$autoload=false){
 $changed=!array_key_exists($name,$GLOBALS['g1_options']) || $GLOBALS['g1_options'][$name]!==$value;
 $GLOBALS['g1_options'][$name]=$value; return $changed;
}
function delete_option($name){ if(!array_key_exists($name,$GLOBALS['g1_options'])) return false; unset($GLOBALS['g1_options'][$name]); return true; }
function get_option($name,$default=array()){
 if('active_plugins'===$name) return array();
 return array_key_exists($name,$GLOBALS['g1_options'])?$GLOBALS['g1_options'][$name]:$default;
}
function apply_filters($name,$value){
 if('mad4b_scp_runtime_policy_conformance'===$name) return $GLOBALS['g1_conformance_receipt'];
 if('mad4b_scp_runtime_policy_conformance_verifiers'===$name) return array(
   'fixture-conformance'=>array(
     'verifier_id'=>'fixture-conformance',
     'issuer_contract'=>'mad4b.provider-compatibility-certification.v1',
     'issuer_id'=>'fixture-control-plane',
     'signature_scheme'=>'fixture-signature-v1',
     'verify_callback'=>array('G1ConformanceVerifier','verify'),
     'trusted'=>true,
     'read_only_verifier'=>true,
     'authorizing'=>false,
   ),
 );
 return $value;
}
function get_plugins(){ return array('unknown/unknown.php'=>array('Name'=>'Unknown Plugin','Version'=>'1.2.3','PluginURI'=>'https://example.invalid','Author'=>'Vendor','License'=>'GPLv2')); }
function did_action($name){ return 'rest_api_init'===$name?0:1; }
function rest_get_server(){ throw new RuntimeException('REST lazy loader must not be invoked when rest_api_init was not observed.'); }
function get_registered_meta_keys($type){ return 'post'===$type?array('public_key'=>array('type'=>'string','single'=>true),'api_secret'=>array('type'=>'string','single'=>true)):array(); }
function _get_cron_array(){
 return array(123=>array(
   'safe_hook'=>array('x'=>array('schedule'=>'hourly','args'=>array('token'=>'SHOULD_NOT_LEAK'))),
   'api_secret_cron'=>array('y'=>array('schedule'=>'daily','args'=>array('secret'=>'ALSO_NEVER_LEAK'))),
 ));
}

class WP_Error {
 private $code;
 public function __construct($code,$message=''){ $this->code=$code; }
 public function get_error_code(){ return $this->code; }
}
function is_wp_error($v){ return $v instanceof WP_Error; }

class FakeAbility {
 public function get_meta(){
   return array(
     'annotations'=>array('readonly'=>true,'destructive'=>false,'idempotent'=>true),
     'mcp'=>array(
       'surface'=>'read','public'=>false,'mad4b_reversible_contract'=>'none_required',
       'required_capability'=>'read','data_classification'=>'public',
       'resource_schema_version'=>'v1','resource_constraints'=>array('post_type'=>'post'),
     ),
   );
 }
 public function get_input_schema(){ return array('type'=>'object','properties'=>array('id'=>array('type'=>'integer')),'additionalProperties'=>false); }
 public function get_output_schema(){ return array('type'=>'object','properties'=>array('title'=>array('type'=>'string')),'additionalProperties'=>false); }
}
function wp_has_ability($name){ return 'mad4b/example-read'===$name; }
function wp_get_abilities(){ return array('mad4b/example-read'=>new FakeAbility()); }
function wp_get_ability($name){ return wp_has_ability($name)?new FakeAbility():null; }

class MAD4B_SCP_Ability_Contract_Inspector {
 public static function site_binding(){ return array('site_uuid'=>'fixture','origin'=>'https://example.test','environment'=>'staging','profile_revision'=>7); }
 public static function digest($contract,$value){ return hash('sha256','mad4b:'.$contract."\n".json_encode($value)); }
}
class MAD4B_SCP_Capability_Descriptor_Registry {
 public static function describe($name){
   if(!wp_has_ability($name)) return new WP_Error('missing');
   return array(
     'category'=>'mad4b-read','input_schema_sha256'=>str_repeat('a',64),'classification_sha256'=>str_repeat('b',64),
     'descriptor_sha256'=>str_repeat('c',64),'readonly'=>true,'readonly_declared'=>true,
     'execution_lane'=>'read','execution_provider'=>'demo','execution_eligible'=>true,'execution_boundary_verified'=>true,'breakglass'=>false,
     'descriptor_contract'=>'mad4b.capability-descriptor.v2','generation_contract'=>'mad4b.capability-generation-roots.v1',
   );
 }
}
class MAD4B_SCP_Structural_Redaction {
 public static function sensitive_key($key){ $k=strtolower((string)$key); return false!==strpos($k,'secret') || false!==strpos($k,'token') || false!==strpos($k,'password'); }
 public static function classify($value,$context='metadata'){
   $encoded=strtolower(json_encode($value));
   $redacted=(false!==strpos($encoded,'secret')||false!==strpos($encoded,'token')||false!==strpos($encoded,'password'))?1:0;
   return array('classification'=>$redacted?'sensitive_redacted':'public_bounded','stats'=>array('redacted'=>$redacted));
 }
 public static function redact($value,$context='metadata'){ return $value; }
}
class MAD4B_SCP_Servers {
 public static function core_tools($server){ return 'mad4b-read'===$server?array('mad4b/example-read'):array(); }
 public static function chatgpt_full_catalog_candidates(){ return array('mad4b/example-read'); }
}
class MAD4B_SCP_Adapter_Registry { public static function instance(){ return new self(); } public function ability_names($surface){ return array(); } }
class MAD4B_SCP_Provider_Contracts {
 public static function all(){
   $v=$GLOBALS['g1_provider_version'];
   return array('demo'=>array(
     'label'=>'Demo Provider','version'=>$v,'contract_mode'=>'exact_fixture',
     'components'=>array('primary'=>array(
       'label'=>'Demo Component','plugin_file'=>'demo/demo.php','version'=>$v,
       'archive_sha256'=>'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
     )),
   ));
 }
 public static function runtime_status($provider,$available=null){
   $v=$GLOBALS['g1_provider_version'];
   return array(
     'provider'=>$provider,'label'=>'Demo Provider','status'=>'certified','runtime_contract_ok'=>true,
     'certified_version'=>$v,'installed_version'=>$v,'contract_mode'=>'exact_fixture','certification_authority'=>'fixture',
   );
 }
}
class MAD4B_SCP_Operation_Registry {
 public static function status(){
   return array('operations'=>array(array(
     'id'=>'content.inspect','planner'=>'mad4b/example-read','executor'=>'exact_executor_from_plan',
     'pipeline_profile'=>'readonly_inspect','required_runtime'=>true,'planner_registered'=>true,
     'executor_registered'=>true,'descriptor_binding_ready'=>true,
   )));
 }
 public static function operation($id){
   return array('planner_descriptor_sha256'=>str_repeat('d',64),'executor_descriptor_sha256'=>str_repeat('f',64),'descriptor_binding_ready'=>true);
 }
}
class MAD4B_SCP_Dependency_Impact_Graph {
 public static function inspect($input=null){
   return array(
     'contract'=>'mad4b.dependency-impact-graph.v1',
     'provider_id'=>isset($input['provider_id'])?(string)$input['provider_id']:'',
     'plugin'=>isset($input['plugin'])?(string)$input['plugin']:'',
     'impact_reasons'=>array('fixture_dependency_revalidation'),
     'mutation_performed'=>false,'authority_created'=>false,
   );
 }
}
class G1ConformanceVerifier {
 public static function verify($receipt,$context){
   $ok=is_array($receipt)
     && isset($receipt['signature'])
     && 'fixture-signature'===(string)$receipt['signature']
     && isset($receipt['evidence_sha256'])
     && preg_match('/^[a-f0-9]{64}$/D',(string)$receipt['evidence_sha256']);
   return array(
     'verified'=>(bool)$ok,
     'evidence_sha256'=>$ok?(string)$receipt['evidence_sha256']:'',
   );
 }
}
class MAD4B_SCP_Audit {
 public static function storage_status(){ return array('ready'=>!empty($GLOBALS['g1_audit_ready'])); }
 public static function record($action,$context,$status){
   if(empty($GLOBALS['g1_audit_ready'])) return new WP_Error('audit_not_ready');
   $GLOBALS['g1_audit_records'][]=array($action,$context,$status); return array('recorded'=>true);
 }
}
class MAD4B_SCP_Admin_Route_Registry { public static function routes(){ return array('mad4b-control-plane'=>array('required_capability'=>'manage_options')); } }
class MAD4B_SCP_Schema { public static function tables(){ return array('audit'=>'wp_mad4b_audit'); } }
class FakeWpdb { public function tables($scope='all'){ return array('wp_posts','wp_options'); } }
class FakeHook { public $callbacks=array(); public function __construct(){ $this->callbacks=array(10=>array('a'=>array('function'=>'SHOULD_NOT_EXPOSE')),20=>array('b'=>array('function'=>'ALSO_PRIVATE'))); } }

$GLOBALS['wpdb']=new FakeWpdb();
$GLOBALS['wp_post_types']=array('post'=>(object)array('public'=>true,'show_ui'=>true));
$GLOBALS['wp_taxonomies']=array('category'=>(object)array('object_type'=>array('post'),'public'=>true,'show_ui'=>true));
$GLOBALS['wp_filter']=array('init'=>new FakeHook());

require_once dirname(__DIR__) . '/includes/class-mad4b-scp-competitive-evidence.php';
require_once dirname(__DIR__) . '/includes/class-mad4b-scp-runtime-evidence-graph.php';
require_once dirname(__DIR__) . '/includes/class-mad4b-scp-runtime-policy-classifier.php';

function check($ok,$message){ if(!$ok){ fwrite(STDERR,$message."\n"); exit(1); } }

$competitive=MAD4B_SCP_Competitive_Evidence::summary();
check(!is_wp_error($competitive),'competitive evidence summary failed packaged integrity verification');
check($competitive['integrity_verified']===true && $competitive['direct_web_resource']===false,'competitive evidence resource boundary invalid');
check($competitive['package_count']===count($competitive['packages']) && $competitive['capability_count']===count($competitive['capabilities']),'competitive evidence inventory count drifted');
check($competitive['authorizing']===false && $competitive['authority_created']===false,'competitive evidence became authorizing');
check($competitive['static_evidence_creates_runtime_certification']===false,'static evidence created runtime certification');
foreach($competitive['capabilities'] as $capability){
 foreach($capability['evidence_sources'] as $source) check($source['runtime_verified']===false,'static source claimed runtime verification');
}

$one=MAD4B_SCP_Runtime_Evidence_Graph::snapshot(array('refresh'=>true));
$two=MAD4B_SCP_Runtime_Evidence_Graph::snapshot();
check(is_array($one),'graph snapshot unavailable');
check($one['contract']==='mad4b.runtime-evidence-graph.v2','runtime graph v2 contract missing');
check($one['generation_sha256']===$two['generation_sha256'],'graph generation is not deterministic');
check($two['metrics']['cache_hit']===true,'request-level graph cache was not used');
check($one['authorizing']===false && $one['mutation_performed']===false,'graph became authorizing');
check($one['discovery']['callbacks_executed']===false && $one['discovery']['unknown_plugin_code_executed']===false,'graph executed discovered code');
check($one['observation_phase']['rest_api_initialized']===false,'REST lifecycle observation is wrong');
check($one['collection_status']['rest_routes']['trustworthy_for_absence']===false,'uninitialized REST routes were treated as trustworthy absence');
check(count($one['nodes']['rest_routes'])===0,'REST lazy route discovery should remain unobserved before rest_api_init');
check($one['nodes']['plugins'][0]['candidate_package']['state']==='descriptive_only','unknown plugin candidate is not descriptive only');
check(count($one['nodes']['providers'])===1 && count($one['nodes']['components'])===1,'provider/component graph nodes missing');
check(count($one['nodes']['schemas'])===2,'input/output schema graph nodes missing');
check($one['nodes']['abilities'][0]['required_capability']==='read','declared capability evidence missing');
check($one['nodes']['abilities'][0]['data_classification']==='public','data classification evidence missing');
check(strlen($one['nodes']['abilities'][0]['output_schema_sha256'])===64,'output schema digest missing');
check($one['nodes']['abilities'][0]['output_schema_secret_bearing']===false,'public output schema was marked secret-bearing');
check(strlen($one['nodes']['abilities'][0]['resource_constraints_sha256'])===64,'resource constraints digest missing');
check(count($one['nodes']['operations'])===1 && $one['nodes']['operations'][0]['preconditions']['descriptor_binding_ready']===true,'operation registry projection missing');
check(count($one['nodes']['hooks'])===1 && $one['nodes']['hooks'][0]['callback_count']===2,'bounded hook inventory missing');
check(count($one['nodes']['mcp_descriptors'])===1,'MCP descriptor projection missing');
check(in_array('contains_component',array_column($one['edges'],'relation'),true),'provider component edge missing');
check(in_array('bound_to_provider',array_column($one['edges'],'relation'),true),'ability provider edge missing');
check(in_array('declares_schema',array_column($one['edges'],'relation'),true),'ability schema edge missing');
check(isset($one['metrics']['elapsed_ms'],$one['metrics']['memory_delta_bytes'],$one['metrics']['within_soft_budget']),'graph performance metrics missing');
check($one['edge_status']['observed_count']===$one['edge_status']['emitted_count'] && $one['edge_status']['trustworthy_for_impact']===true,'complete fixture edges were not trusted for impact');

$encoded=json_encode($one);
check(false===strpos($encoded,'api_secret'),'sensitive meta key leaked');
check(false===strpos($encoded,'SHOULD_NOT_LEAK') && false===strpos($encoded,'ALSO_NEVER_LEAK'),'cron args leaked');
check(false===strpos($encoded,'api_secret_cron'),'sensitive cron hook name leaked');
check(false===strpos($encoded,'SHOULD_NOT_EXPOSE'),'hook callback identity leaked');
check($one['nodes']['database_tables'][0]['schema_read']===false,'database schema was read');

$status_tamper=$one; $status_tamper['collection_status']['hooks']['observed_count']=999;
$status_tamper_result=MAD4B_SCP_Runtime_Evidence_Graph::diff(array('before'=>$status_tamper));
check(is_wp_error($status_tamper_result) && 'mad4b_runtime_graph_generation_mismatch'===$status_tamper_result->get_error_code(),'collection completeness metadata was not generation-bound');

$edge_tamper=$one; $edge_tamper['edge_status']['observed_count']=$edge_tamper['edge_status']['observed_count']+1;
$edge_tamper_result=MAD4B_SCP_Runtime_Evidence_Graph::diff(array('before'=>$edge_tamper));
check(is_wp_error($edge_tamper_result) && 'mad4b_runtime_graph_generation_mismatch'===$edge_tamper_result->get_error_code(),'edge completeness metadata was not generation-bound');

$fabricated=$one; $fabricated['generation_sha256']=str_repeat('0',64);
$bad_generation=MAD4B_SCP_Runtime_Evidence_Graph::diff(array('before'=>$fabricated));
check(is_wp_error($bad_generation) && 'mad4b_runtime_graph_generation_mismatch'===$bad_generation->get_error_code(),'fabricated graph generation was accepted');

$cross_site=$one; $cross_site['site_binding']['origin']='https://other.example';
$cross_site_result=MAD4B_SCP_Runtime_Evidence_Graph::diff(array('before'=>$cross_site));
check(is_wp_error($cross_site_result) && 'mad4b_runtime_graph_cross_site_rejected'===$cross_site_result->get_error_code(),'cross-site graph comparison was accepted');

$oversized=$one; $oversized['junk']=str_repeat('x',2100000);
$oversized_result=MAD4B_SCP_Runtime_Evidence_Graph::diff(array('before'=>$oversized));
check(is_wp_error($oversized_result) && 'mad4b_runtime_graph_before_oversized'===$oversized_result->get_error_code(),'oversized graph comparison input was accepted');

$before=$one;
$GLOBALS['g1_provider_version']='2.0.0';
$provider_delta=MAD4B_SCP_Runtime_Evidence_Graph::diff(array('before'=>$before));
check(!is_wp_error($provider_delta),'provider drift diff failed');
check(in_array('mad4b/example-read',$provider_delta['affected_abilities'],true),'provider drift did not propagate to dependent ability');
check(in_array('content.inspect',$provider_delta['affected_operations'],true),'provider drift did not propagate to dependent operation');
check(in_array('pipeline:readonly_inspect',$provider_delta['affected_workflows'],true),'provider drift did not propagate to dependent workflow');
check(!empty($provider_delta['dependency_impacts']),'dependency impact foundation was not reused');
$GLOBALS['g1_provider_version']='1.0.0';
MAD4B_SCP_Runtime_Evidence_Graph::clear_request_cache();

$many_hooks=array();
for($i=0;$i<300;$i++) $many_hooks['fixture_hook_'.$i]=new FakeHook();
$GLOBALS['wp_filter']=$many_hooks;
$truncated=MAD4B_SCP_Runtime_Evidence_Graph::snapshot(array('refresh'=>true));
check($truncated['collection_status']['hooks']['observed_count']===300,'hook observed count was not preserved before truncation');
check($truncated['collection_status']['hooks']['emitted_count']===256,'hook emitted count did not respect the bounded collector limit');
check($truncated['collection_status']['hooks']['truncated']===true,'bounded hook truncation was not declared');
check($truncated['collection_status']['hooks']['trustworthy_for_absence']===false,'truncated hook collection claimed trustworthy absence');
$GLOBALS['wp_filter']=array('init'=>new FakeHook());
MAD4B_SCP_Runtime_Evidence_Graph::clear_request_cache();
$one=MAD4B_SCP_Runtime_Evidence_Graph::snapshot(array('refresh'=>true));

$node=$one['nodes']['abilities'][0];
$provider_sha=$one['nodes']['providers'][0]['contract_sha256'];
$receipt=array(
 'contract'=>MAD4B_SCP_Runtime_Policy_Classifier::CONFORMANCE_CONTRACT,
 'ability_name'=>'mad4b/example-read',
 'graph_generation_sha256'=>$one['generation_sha256'],
 'descriptor_generation_sha256'=>$node['descriptor_generation_sha256'],
 'input_schema_sha256'=>$node['input_schema_sha256'],
 'output_schema_sha256'=>$node['output_schema_sha256'],
 'provider_contract_sha256'=>$provider_sha,
 'result'=>'zero_effect_read_verified',
 'observed_writes'=>0,
 'observed_external_effects'=>0,
 'output_classification'=>'public_bounded',
 'issuer_contract'=>'mad4b.provider-compatibility-certification.v1',
 'issuer_id'=>'fixture-control-plane',
 'verifier_id'=>'fixture-conformance',
 'signature_scheme'=>'fixture-signature-v1',
 'signature'=>'fixture-signature',
 'evidence_sha256'=>str_repeat('e',64),
);
$receipt['receipt_sha256']=MAD4B_SCP_Ability_Contract_Inspector::digest(MAD4B_SCP_Runtime_Policy_Classifier::CONFORMANCE_CONTRACT,$receipt);
$GLOBALS['g1_conformance_receipt']=$receipt;

$edge_method=new ReflectionMethod('MAD4B_SCP_Runtime_Evidence_Graph','edges');
$edge_method->setAccessible(true);
$edge_abilities=array();
for($i=0;$i<5;$i++) $edge_abilities[]=array('id'=>'fixture/read-'.$i,'kind'=>'ability');
$edge_operations=array();
for($i=0;$i<256;$i++) $edge_operations[]=array(
 'id'=>'fixture/op-'.$i,'kind'=>'operation',
 'ability_refs'=>array('fixture/read-0','fixture/read-1','fixture/read-2','fixture/read-3','fixture/read-4'),
);
$edge_rows=$edge_method->invoke(null,array(
 'abilities'=>$edge_abilities,'providers'=>array(),'components'=>array(),'schemas'=>array(),
 'operations'=>$edge_operations,'mcp_descriptors'=>array(),
));
$edge_observed=new ReflectionProperty('MAD4B_SCP_Runtime_Evidence_Graph','last_edge_observed_count');
$edge_observed->setAccessible(true);
check(count($edge_rows)===1024 && $edge_observed->getValue()===1280,'edge collector did not preserve pre-truncation count');

$safe=MAD4B_SCP_Runtime_Policy_Classifier::classify_features(array(
 'namespace'=>'mad4b','action'=>'inspect','schema_sha256'=>str_repeat('a',64),'output_schema_sha256'=>str_repeat('b',64),
 'readonly_annotation'=>true,'execution_lane'=>'read','effect_class'=>'declared_read_only','schema_secret_bearing'=>false,
 'output_schema_secret_bearing'=>false,'actual_conformance_verified'=>true,'actual_output_classification'=>'public_bounded',
 'execution_eligible'=>true,'execution_boundary_verified'=>true,'execution_provider'=>'demo','breakglass'=>false,'declared_capability'=>'read',
));
check($safe['auto_classification_eligible']===true,'verified public zero-effect read was not eligible');

$output_secret=$safe['features']; // public_features payload is suitable evidence input except booleans added below.
$output_secret['namespace']='vendor'; $output_secret['action']='inspect'; $output_secret['readonly_annotation']=true;
$output_secret['execution_lane']='read'; $output_secret['effect_class']='declared_read_only'; $output_secret['schema_secret_bearing']=false;
$output_secret['output_schema_secret_bearing']=true; $output_secret['actual_conformance_verified']=true; $output_secret['actual_output_classification']='public_bounded';
$output_secret['execution_eligible']=true; $output_secret['execution_boundary_verified']=true; $output_secret['declared_capability']='read';
$output_secret_result=MAD4B_SCP_Runtime_Policy_Classifier::classify_features($output_secret);
check($output_secret_result['auto_classification_eligible']===false && in_array('secret_output_schema_blocks_auto_classification',$output_secret_result['contradictory_evidence'],true),'secret output auto-classified');

$privileged=$output_secret; $privileged['output_schema_secret_bearing']=false; $privileged['declared_capability']='manage_options';
$privileged_result=MAD4B_SCP_Runtime_Policy_Classifier::classify_features($privileged);
check($privileged_result['auto_classification_eligible']===false && in_array('privileged_capability_blocks_auto_classification',$privileged_result['contradictory_evidence'],true),'privileged read auto-classified');

$fake_receipt=$receipt; $fake_receipt['receipt_sha256']=str_repeat('f',64); $GLOBALS['g1_conformance_receipt']=$fake_receipt;
$fake_proposals=MAD4B_SCP_Runtime_Policy_Classifier::proposals(array('ability_name'=>'mad4b/example-read'));
check($fake_proposals['proposals'][0]['auto_classification_eligible']===false,'forged conformance receipt enabled auto classification');
check($fake_proposals['proposals'][0]['conformance']['verified']===false,'forged conformance receipt was marked verified');
$GLOBALS['g1_conformance_receipt']=$receipt;

$untrusted_verifier=$receipt;
$untrusted_verifier['verifier_id']='forged-verifier';
unset($untrusted_verifier['receipt_sha256']);
$untrusted_verifier['receipt_sha256']=MAD4B_SCP_Ability_Contract_Inspector::digest(MAD4B_SCP_Runtime_Policy_Classifier::CONFORMANCE_CONTRACT,$untrusted_verifier);
$GLOBALS['g1_conformance_receipt']=$untrusted_verifier;
$untrusted_proposals=MAD4B_SCP_Runtime_Policy_Classifier::proposals(array('ability_name'=>'mad4b/example-read'));
check($untrusted_proposals['proposals'][0]['auto_classification_eligible']===false,'untrusted verifier enabled auto classification');
check($untrusted_proposals['proposals'][0]['conformance']['reason']==='conformance_verifier_untrusted','untrusted verifier denial reason missing');
$GLOBALS['g1_conformance_receipt']=$receipt;

$proposals=MAD4B_SCP_Runtime_Policy_Classifier::proposals(array('ability_name'=>'mad4b/example-read'));
check(!is_wp_error($proposals) && count($proposals['proposals'])===1,'runtime policy proposal missing');
$proposal=$proposals['proposals'][0];
check($proposal['auto_classification_eligible']===true && $proposal['conformance']['verified']===true,'trusted conformance did not admit public zero-effect read');

$status=MAD4B_SCP_Runtime_Policy_Classifier::review_status();
check($status['revision']===0 && $status['history_count']===0 && $status['integrity_valid']===true,'review ledger did not start clean');

$GLOBALS['g1_audit_ready']=false;
$audit_block=MAD4B_SCP_Runtime_Policy_Classifier::record_review(array(
 'ability_name'=>'mad4b/example-read','graph_generation_sha256'=>$proposals['graph_generation_sha256'],
 'proposal_sha256'=>$proposal['proposal_sha256'],'decision'=>'accept_evidence','expected_revision'=>0,
));
check(is_wp_error($audit_block) && 'mad4b_runtime_policy_review_audit_not_ready'===$audit_block->get_error_code(),'review write did not fail closed when audit was unavailable');
$GLOBALS['g1_audit_ready']=true;

$review=MAD4B_SCP_Runtime_Policy_Classifier::record_review(array(
 'ability_name'=>'mad4b/example-read','graph_generation_sha256'=>$proposals['graph_generation_sha256'],
 'proposal_sha256'=>$proposal['proposal_sha256'],'decision'=>'accept_evidence','requested_scope_change'=>false,
 'note'=>'Fixture review only.','expected_revision'=>0,
));
check(!is_wp_error($review),'owner review could not be recorded');
check($review['revision']===1 && $review['history_count']===1 && $review['authorizing']===false,'append-only review history was not recorded');
check(count($GLOBALS['g1_audit_records'])===1,'mandatory review audit record missing');

$after_review=MAD4B_SCP_Runtime_Policy_Classifier::proposals(array('ability_name'=>'mad4b/example-read'));
$overlay=$after_review['proposals'][0]['reviewed_overlay'];
check($overlay['state']==='reviewed_evidence_only' && $overlay['decision']==='accept_evidence','review overlay projection missing');
check($overlay['creates_grant']===false && $overlay['creates_mount']===false && $overlay['creates_scope']===false && $overlay['creates_certification']===false,'review overlay created authority');

$stale=MAD4B_SCP_Runtime_Policy_Classifier::record_review(array(
 'ability_name'=>'mad4b/example-read','graph_generation_sha256'=>$proposals['graph_generation_sha256'],
 'proposal_sha256'=>$proposal['proposal_sha256'],'decision'=>'defer','expected_revision'=>0,
));
check(is_wp_error($stale) && 'mad4b_runtime_policy_review_stale'===$stale->get_error_code(),'stale review revision did not fail closed');

$store=$GLOBALS['g1_options'][MAD4B_SCP_Runtime_Policy_Classifier::REVIEW_OPTION];
$store['reviews']['mad4b/example-read']['note']='tampered';
$GLOBALS['g1_options'][MAD4B_SCP_Runtime_Policy_Classifier::REVIEW_OPTION]=$store;
$tampered_status=MAD4B_SCP_Runtime_Policy_Classifier::review_status();
check($tampered_status['integrity_valid']===false,'tampered review digest was accepted');
$tampered_write=MAD4B_SCP_Runtime_Policy_Classifier::record_review(array(
 'ability_name'=>'mad4b/example-read','graph_generation_sha256'=>$proposals['graph_generation_sha256'],
 'proposal_sha256'=>$proposal['proposal_sha256'],'decision'=>'defer','expected_revision'=>1,
));
check(is_wp_error($tampered_write) && 'mad4b_runtime_policy_review_store_tampered'===$tampered_write->get_error_code(),'tampered review store accepted a new write');

echo "mad4b.g1-runtime-evidence-runtime.v3: PASS\n";
