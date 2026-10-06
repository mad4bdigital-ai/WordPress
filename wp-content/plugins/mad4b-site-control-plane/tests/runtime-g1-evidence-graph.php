<?php
define('ABSPATH', __DIR__ . '/');
define('MAD4B_SCP_DIR', dirname(__DIR__) . '/');

$GLOBALS['g1_options']=array();
function add_action(){ return true; }
function sanitize_key($v){ return strtolower(preg_replace('/[^a-z0-9_-]/','',(string)$v)); }
function sanitize_textarea_field($v){ return trim((string)$v); }
function absint($v){ return abs((int)$v); }
function wp_json_encode($v,$flags=0){ return json_encode($v,$flags); }
function current_user_can(){ return true; }
function get_current_user_id(){ return 7; }
function current_time($type='mysql',$gmt=false){ return '2026-10-06 16:00:00'; }
function wp_generate_uuid4(){ return '12345678-1234-4234-8234-123456789abc'; }
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
 $args=func_get_args();
 if('mad4b_scp_runtime_policy_conformance'===$name) {
   return array('result'=>'zero_effect_read_verified','evidence_sha256'=>str_repeat('e',64));
 }
 return $value;
}
function get_plugins(){ return array('unknown/unknown.php'=>array('Name'=>'Unknown Plugin','Version'=>'1.2.3','PluginURI'=>'https://example.invalid','Author'=>'Vendor','License'=>'GPLv2')); }
function did_action($name){ return 'rest_api_init'===$name?0:1; }
function rest_get_server(){ throw new RuntimeException('REST lazy loader must not be invoked when rest_api_init was not observed.'); }
function get_registered_meta_keys($type){ return 'post'===$type?array('public_key'=>array('type'=>'string','single'=>true),'api_secret'=>array('type'=>'string','single'=>true)):array(); }
function _get_cron_array(){ return array(123=>array('safe_hook'=>array('x'=>array('schedule'=>'hourly','args'=>array('token'=>'SHOULD_NOT_LEAK'))))); }

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
     'mcp'=>array('surface'=>'read','public'=>false,'mad4b_reversible_contract'=>'none_required'),
   );
 }
 public function get_input_schema(){ return array('type'=>'object','properties'=>array('id'=>array('type'=>'integer')),'additionalProperties'=>false); }
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
     'category'=>'mad4b-read',
     'input_schema_sha256'=>str_repeat('a',64),
     'classification_sha256'=>str_repeat('b',64),
     'descriptor_sha256'=>str_repeat('c',64),
     'readonly'=>true,'readonly_declared'=>true,
     'execution_lane'=>'read','execution_provider'=>'demo',
     'execution_eligible'=>true,'execution_boundary_verified'=>true,'breakglass'=>false,
     'descriptor_contract'=>'mad4b.capability-descriptor.v2',
     'generation_contract'=>'mad4b.capability-generation-roots.v1',
   );
 }
}
class MAD4B_SCP_Structural_Redaction {
 public static function sensitive_key($key){ return false!==strpos(strtolower((string)$key),'secret') || false!==strpos(strtolower((string)$key),'token'); }
 public static function classify($value,$context='metadata'){ return array('classification'=>'public_bounded','stats'=>array('redacted'=>0)); }
 public static function redact($value,$context='metadata'){ return $value; }
}
class MAD4B_SCP_Servers {
 public static function core_tools($server){ return 'mad4b-read'===$server?array('mad4b/example-read'):array(); }
 public static function chatgpt_full_catalog_candidates(){ return array('mad4b/example-read'); }
}
class MAD4B_SCP_Adapter_Registry { public static function instance(){ return new self(); } public function ability_names($surface){ return array(); } }
class MAD4B_SCP_Provider_Contracts {
 public static function all(){
   return array('demo'=>array(
     'label'=>'Demo Provider',
     'version'=>'1.0.0',
     'contract_mode'=>'exact_fixture',
     'components'=>array('primary'=>array(
       'label'=>'Demo Component','plugin_file'=>'demo/demo.php','version'=>'1.0.0',
       'archive_sha256'=>'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
     )),
   ));
 }
 public static function runtime_status($provider,$available=null){
   return array(
     'provider'=>$provider,'label'=>'Demo Provider','status'=>'certified',
     'runtime_contract_ok'=>true,'certified_version'=>'1.0.0','installed_version'=>'1.0.0',
     'contract_mode'=>'exact_fixture','certification_authority'=>'fixture',
   );
 }
}
class MAD4B_SCP_Operation_Registry {
 public static function status(){
   return array('operations'=>array(array(
     'id'=>'content.inspect',
     'planner'=>'mad4b/example-read',
     'executor'=>'exact_executor_from_plan',
     'pipeline_profile'=>'readonly_inspect',
     'required_runtime'=>true,
     'planner_registered'=>true,
     'executor_registered'=>true,
     'descriptor_binding_ready'=>true,
   )));
 }
 public static function operation($id){
   return array(
     'planner_descriptor_sha256'=>str_repeat('d',64),
     'executor_descriptor_sha256'=>str_repeat('f',64),
     'descriptor_binding_ready'=>true,
   );
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
check($competitive['integrity_verified']===true,'competitive evidence integrity was not verified');
check($competitive['package_count']===4 && $competitive['capability_count']===59,'competitive evidence inventory count drifted');
check($competitive['authorizing']===false && $competitive['authority_created']===false,'competitive evidence became authorizing');
check($competitive['static_evidence_creates_runtime_certification']===false,'static evidence created runtime certification');
check(!empty($competitive['capabilities'][0]['evidence_sources']),'competitive evidence provenance links missing');
check(!empty($competitive['capabilities'][0]['mad4b_foundation_paths']),'MAD4B foundation links missing');
check(array_key_exists('runtime_parity_claimed',$competitive['capabilities'][0]),'runtime parity boundary missing');
foreach($competitive['capabilities'] as $capability){
 foreach($capability['evidence_sources'] as $source) check($source['runtime_verified']===false,'static source claimed runtime verification');
}

$one=MAD4B_SCP_Runtime_Evidence_Graph::snapshot();
$two=MAD4B_SCP_Runtime_Evidence_Graph::snapshot();
check(is_array($one),'graph snapshot unavailable');
check($one['generation_sha256']===$two['generation_sha256'],'graph generation is not deterministic');
check($one['authorizing']===false && $one['mutation_performed']===false,'graph became authorizing');
check($one['discovery']['callbacks_executed']===false,'graph executed callbacks');
check($one['discovery']['unknown_plugin_code_executed']===false,'graph executed unknown plugin code');
check(count($one['nodes']['rest_routes'])===0,'REST lazy route discovery should remain unobserved before rest_api_init');
check($one['nodes']['plugins'][0]['candidate_package']['state']==='descriptive_only','unknown plugin candidate is not descriptive only');
check(count($one['nodes']['providers'])===1,'provider graph node missing');
check(count($one['nodes']['components'])===1,'component graph node missing');
check($one['nodes']['providers'][0]['authority_inferred']===false,'provider identity inferred authority');
check($one['nodes']['components'][0]['code_executed']===false,'component discovery executed code');
check(count($one['nodes']['operations'])===1,'operation registry rows were not captured');
check($one['nodes']['operations'][0]['preconditions']['descriptor_binding_ready']===true,'operation descriptor precondition missing');
check(count($one['nodes']['hooks'])===1 && $one['nodes']['hooks'][0]['callback_count']===2,'bounded hook inventory missing');
check($one['nodes']['hooks'][0]['callbacks_invoked']===false && $one['nodes']['hooks'][0]['callback_identities_exposed']===false,'hook discovery exposed or invoked callbacks');
check(count($one['nodes']['mcp_descriptors'])===1,'MCP descriptor projection missing');
check(count($one['edges'])>=4,'runtime graph provider/component/operation edges missing');
check(in_array('contains_component',array_column($one['edges'],'relation'),true),'provider component edge missing');
check(in_array('bound_to_provider',array_column($one['edges'],'relation'),true),'ability provider edge missing');
check(in_array('precondition',$one['semantic_dimensions'],true) && in_array('reversal',$one['semantic_dimensions'],true),'semantic graph dimensions incomplete');

$meta=json_encode($one['nodes']['meta_keys']);
check(false===strpos($meta,'api_secret'),'sensitive meta key leaked');
check(false===strpos(json_encode($one),'SHOULD_NOT_LEAK'),'cron args leaked');
check(false===strpos(json_encode($one),'SHOULD_NOT_EXPOSE'),'hook callback identity leaked');
check($one['nodes']['database_tables'][0]['schema_read']===false,'database schema was read');

$before=$one;
$GLOBALS['wp_post_types']['book']=(object)array('public'=>true,'show_ui'=>true);
$delta=MAD4B_SCP_Runtime_Evidence_Graph::diff(array('before'=>$before));
check(!is_wp_error($delta),'graph diff failed');
check(count($delta['added'])>=1,'graph diff failed to detect an added runtime node');
check($delta['isolation_policy']==='removed_or_changed_only_fail_closed','graph diff isolation policy changed');

$safe=MAD4B_SCP_Runtime_Policy_Classifier::classify_features(array(
 'namespace'=>'mad4b','action'=>'inspect','schema_sha256'=>str_repeat('a',64),'readonly_annotation'=>true,
 'execution_lane'=>'read','effect_class'=>'declared_read_only','schema_secret_bearing'=>false,
 'actual_conformance_verified'=>true,'execution_eligible'=>true,'execution_provider'=>'core','breakglass'=>false,
));
check($safe['auto_classification_eligible']===true,'verified zero-effect read was not eligible');

$get_only=MAD4B_SCP_Runtime_Policy_Classifier::classify_features(array(
 'namespace'=>'vendor','action'=>'get-admin','schema_sha256'=>str_repeat('b',64),'readonly_annotation'=>true,
 'execution_lane'=>'write','effect_class'=>'mutation_or_unknown','schema_secret_bearing'=>false,
 'actual_conformance_verified'=>false,'execution_eligible'=>true,'http_method'=>'GET','requested_risk'=>'low',
));
check($get_only['auto_classification_eligible']===false,'unsafe GET gained automatic read classification');
check(in_array('http_get_cannot_prove_read_safety',$get_only['contradictory_evidence'],true),'unsafe GET contradiction missing');
check(in_array('risk_downgrade_rejected',$get_only['contradictory_evidence'],true),'risk downgrade was not rejected');

$confidence_only=MAD4B_SCP_Runtime_Policy_Classifier::classify_features(array(
 'namespace'=>'vendor','action'=>'list-things','schema_sha256'=>str_repeat('c',64),'readonly_annotation'=>true,
 'execution_lane'=>'read','effect_class'=>'declared_read_only','schema_secret_bearing'=>false,
 'actual_conformance_verified'=>false,'execution_eligible'=>true,
));
check($confidence_only['confidence']>0 && $confidence_only['auto_classification_eligible']===false,'confidence alone promoted authority');

$secret=MAD4B_SCP_Runtime_Policy_Classifier::classify_features(array(
 'namespace'=>'vendor','action'=>'inspect','schema_sha256'=>str_repeat('d',64),'readonly_annotation'=>true,
 'execution_lane'=>'read','effect_class'=>'declared_read_only','schema_secret_bearing'=>true,
 'actual_conformance_verified'=>true,'execution_eligible'=>true,
));
check($secret['auto_classification_eligible']===false,'secret-bearing schema auto-classified');

$proposals=MAD4B_SCP_Runtime_Policy_Classifier::proposals(array('ability_name'=>'mad4b/example-read'));
check(!is_wp_error($proposals) && count($proposals['proposals'])===1,'runtime policy proposal missing');
$proposal=$proposals['proposals'][0];
check($proposal['auto_classification_eligible']===true,'actual conformance did not admit the zero-effect read candidate');
$status=MAD4B_SCP_Runtime_Policy_Classifier::review_status();
check($status['revision']===0 && $status['review_count']===0,'review ledger did not start empty');

$review=MAD4B_SCP_Runtime_Policy_Classifier::record_review(array(
 'ability_name'=>'mad4b/example-read',
 'graph_generation_sha256'=>$proposals['graph_generation_sha256'],
 'proposal_sha256'=>$proposal['proposal_sha256'],
 'decision'=>'accept_evidence',
 'requested_scope_change'=>false,
 'note'=>'Fixture review only.',
 'expected_revision'=>0,
));
check(!is_wp_error($review),'owner review could not be recorded');
check($review['revision']===1 && $review['authorizing']===false,'owner review became authorizing');
check($review['grants_changed']===false && $review['mounts_changed']===false && $review['scopes_changed']===false && $review['certifications_changed']===false,'owner review widened authority');

$after_review=MAD4B_SCP_Runtime_Policy_Classifier::proposals(array('ability_name'=>'mad4b/example-read'));
$overlay=$after_review['proposals'][0]['reviewed_overlay'];
check($overlay['state']==='reviewed_evidence_only' && $overlay['decision']==='accept_evidence','review overlay projection missing');
check($overlay['creates_grant']===false && $overlay['creates_mount']===false && $overlay['creates_scope']===false && $overlay['creates_certification']===false,'review overlay created authority');

$stale=MAD4B_SCP_Runtime_Policy_Classifier::record_review(array(
 'ability_name'=>'mad4b/example-read',
 'graph_generation_sha256'=>$proposals['graph_generation_sha256'],
 'proposal_sha256'=>$proposal['proposal_sha256'],
 'decision'=>'defer',
 'expected_revision'=>0,
));
check(is_wp_error($stale) && 'mad4b_runtime_policy_review_stale'===$stale->get_error_code(),'stale review revision did not fail closed');

$graph_stale=MAD4B_SCP_Runtime_Policy_Classifier::record_review(array(
 'ability_name'=>'mad4b/example-read',
 'graph_generation_sha256'=>str_repeat('0',64),
 'proposal_sha256'=>$proposal['proposal_sha256'],
 'decision'=>'defer',
 'expected_revision'=>1,
));
check(is_wp_error($graph_stale) && 'mad4b_runtime_policy_review_graph_stale'===$graph_stale->get_error_code(),'stale graph review did not fail closed');

echo "mad4b.g1-runtime-evidence-runtime.v2: PASS\n";
