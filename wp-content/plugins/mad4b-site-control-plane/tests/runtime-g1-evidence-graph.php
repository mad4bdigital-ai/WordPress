<?php
define('ABSPATH', __DIR__ . '/');
define('MAD4B_SCP_DIR', dirname(__DIR__) . '/');
function add_action(){ return true; }
function sanitize_key($v){ return strtolower(preg_replace('/[^a-z0-9_-]/','',(string)$v)); }
function wp_json_encode($v,$flags=0){ return json_encode($v,$flags); }
function wp_has_ability($name){ return false; }
function wp_get_abilities(){ return array(); }
function current_user_can(){ return true; }
function get_plugins(){ return array('unknown/unknown.php'=>array('Name'=>'Unknown Plugin','Version'=>'1.2.3','PluginURI'=>'https://example.invalid','Author'=>'Vendor','License'=>'GPLv2')); }
function get_option($name,$default=array()){ return 'active_plugins'===$name?array():$default; }
function did_action($name){ return 'rest_api_init'===$name?0:1; }
function rest_get_server(){ throw new RuntimeException('REST lazy loader must not be invoked when rest_api_init was not observed.'); }
function get_registered_meta_keys($type){ return 'post'===$type?array('public_key'=>array('type'=>'string','single'=>true),'api_secret'=>array('type'=>'string','single'=>true)):array(); }
function _get_cron_array(){ return array(123=>array('safe_hook'=>array('x'=>array('schedule'=>'hourly','args'=>array('token'=>'SHOULD_NOT_LEAK'))))); }
class WP_Error { private $code; public function __construct($code,$message=''){ $this->code=$code; } public function get_error_code(){ return $this->code; } }
function is_wp_error($v){ return $v instanceof WP_Error; }
class MAD4B_SCP_Ability_Contract_Inspector {
 public static function site_binding(){ return array('site_uuid'=>'fixture','origin'=>'https://example.test','environment'=>'staging','profile_revision'=>7); }
 public static function digest($contract,$value){ return hash('sha256','mad4b:'.$contract."\n".json_encode($value)); }
}
class MAD4B_SCP_Structural_Redaction {
 public static function sensitive_key($key){ return false!==strpos(strtolower((string)$key),'secret') || false!==strpos(strtolower((string)$key),'token'); }
 public static function classify($value,$context='metadata'){ return array('classification'=>'public_bounded','stats'=>array('redacted'=>0)); }
 public static function redact($value,$context='metadata'){ return $value; }
}
class MAD4B_SCP_Servers { public static function core_tools($server){ return array(); } public static function chatgpt_full_catalog_candidates(){ return array(); } }
class MAD4B_SCP_Adapter_Registry { public static function instance(){ return new self(); } public function ability_names($surface){ return array(); } }
class MAD4B_SCP_Operation_Registry { public static function read_projection($surface='catalog'){ return array(array('id'=>'content.inspect','planner_ability'=>'mad4b/example-read')); } }
class MAD4B_SCP_Admin_Route_Registry { public static function routes(){ return array('mad4b-control-plane'=>array('required_capability'=>'manage_options')); } }
class MAD4B_SCP_Schema { public static function tables(){ return array('audit'=>'wp_mad4b_audit'); } }
class FakeWpdb { public function tables($scope='all'){ return array('wp_posts','wp_options'); } }
$GLOBALS['wpdb']=new FakeWpdb();
$GLOBALS['wp_post_types']=array('post'=>(object)array('public'=>true,'show_ui'=>true));
$GLOBALS['wp_taxonomies']=array('category'=>(object)array('object_type'=>array('post'),'public'=>true,'show_ui'=>true));
require_once dirname(__DIR__) . '/includes/class-mad4b-scp-runtime-evidence-graph.php';
require_once dirname(__DIR__) . '/includes/class-mad4b-scp-runtime-policy-classifier.php';
function check($ok,$message){ if(!$ok){ fwrite(STDERR,$message."\n"); exit(1); } }
$one=MAD4B_SCP_Runtime_Evidence_Graph::snapshot();
$two=MAD4B_SCP_Runtime_Evidence_Graph::snapshot();
check(is_array($one),'graph snapshot unavailable');
check($one['generation_sha256']===$two['generation_sha256'],'graph generation is not deterministic');
check($one['authorizing']===false && $one['mutation_performed']===false,'graph became authorizing');
check($one['discovery']['callbacks_executed']===false,'graph executed callbacks');
check($one['discovery']['unknown_plugin_code_executed']===false,'graph executed unknown plugin code');
check(count($one['nodes']['rest_routes'])===0,'REST lazy route discovery should remain unobserved before rest_api_init');
check($one['nodes']['plugins'][0]['candidate_package']['state']==='descriptive_only','unknown plugin candidate is not descriptive only');
$meta=json_encode($one['nodes']['meta_keys']);
check(false===strpos($meta,'api_secret'),'sensitive meta key leaked');
check(false===strpos(json_encode($one),'SHOULD_NOT_LEAK'),'cron args leaked');
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
echo "mad4b.g1-runtime-evidence-runtime.v1: PASS\n";
