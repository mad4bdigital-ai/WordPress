<?php

define( 'ABSPATH', __DIR__ . '/' );
define( 'MAD4B_SCP_DIR', dirname( __DIR__ ) . '/' );
function sanitize_key( $value ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $value ) ); }
function wp_json_encode( $value ) { return json_encode( $value ); }
function add_action( $hook, $callback, $priority = 10, $accepted_args = 1 ) { return true; }
function wp_register_ability( $name, $args ) { return true; }
function wp_has_ability( $name ) { return false; }
function jet_engine() { return true; }
class Jet_Engine {}
require_once __DIR__ . '/fixtures/provider-compatibility-symbols.php';
class WP_Error { public $code; public $message; public $data; public function __construct( $code, $message, $data = array() ) { $this->code=$code; $this->message=$message; $this->data=$data; } }
function is_wp_error( $value ) { return $value instanceof WP_Error; }

final class MAD4B_SCP_Provider_Contracts {
    public static $exact = false;
    public static function get( $provider ) {
        $file = 'bit_pi' === $provider ? 'bit-pi/bit-pi.php' : 'jet-engine/jet-engine.php';
        return array( 'plugin_file'=>$file, 'archive_sha256'=>str_repeat('a',64) );
    }
    public static function runtime_status( $provider, $available = null ) {
        return array(
            'provider'=>$provider,
            'status'=>self::$exact?'certified':'version_drift',
            'runtime_contract_ok'=>self::$exact,
            'installed_version'=> 'bit_pi' === $provider ? '1.9.0' : '3.8.15',
            'certified_versions'=>array( 'bit_pi' === $provider ? '1.9.0' : '3.8.11.2' ),
            'certification_authority'=>'test-fixture',
            'runtime_integrity'=>array('required'=>true,'manifest_present'=>true,'verified'=>array('plugin.php'),'missing'=>array(),'mismatched'=>self::$exact?array():array('plugin.php'=>array('reason'=>'hash_mismatch'))),
        );
    }
}

final class FakeJetEngineAdapter {
    public function id(){ return 'jetengine'; }
    public function provider_key(){ return 'jetengine'; }
    public function is_available(){ return true; }
    public function ability_names(){ return array('read'=>array('jetengine/get-post-meta','jetengine/list-post-meta','jetengine/get-cpt-definition'),'content'=>array('jetengine/update-post-meta'),'admin'=>array()); }
    public function reversible_contracts(){ return array('jetengine/update-post-meta'=>'mad4b.rollback.jetengine-post-meta.v1'); }
}
final class FakeBitFlowsAdapter {
    public function id(){ return 'bitflows'; }
    public function provider_key(){ return 'bit_pi'; }
    public function is_available(){ return true; }
    public function ability_names(){ return array('read'=>array('bitflows/list-flows','bitflows/get-flow','bitflows/get-executions'),'content'=>array(),'admin'=>array('bitflows/run-flow')); }
    public function reversible_contracts(){ return array(); }
}
final class MAD4B_SCP_Adapter_Registry {
    private static $instance;
    private $jetengine;
    private $bitflows;
    public static function instance(){ if(!self::$instance) self::$instance=new self(); return self::$instance; }
    public function __construct(){ $this->jetengine=new FakeJetEngineAdapter(); $this->bitflows=new FakeBitFlowsAdapter(); }
    public function register_defaults(){}
    public function get($id){ if('jetengine'===$id) return $this->jetengine; if('bitflows'===$id) return $this->bitflows; return null; }
    public function register($adapter){ return true; }
}

require_once dirname( __DIR__ ) . '/includes/class-mad4b-scp-provider-compatibility-certification.php';

function expect_true($condition,$message){ if(!$condition){fwrite(STDERR,"FAIL: $message\n");exit(1);} }
function expect_same($expected,$actual,$message){ if($expected!==$actual){fwrite(STDERR,"FAIL: $message expected=".var_export($expected,true)." actual=".var_export($actual,true)."\n");exit(1);} }

$adapter=new FakeJetEngineAdapter();
MAD4B_SCP_Provider_Contracts::$exact=false;
MAD4B_SCP_Provider_Compatibility_Certification::clear_request_cache();
$drift=MAD4B_SCP_Provider_Compatibility_Certification::assess_provider('jetengine',$adapter);
expect_same('compatible_unattested',$drift['compatibility_state'],'version drift with intact structure becomes compatible_unattested');
expect_same('READ_COMPATIBLE',$drift['capabilities']['post_meta.read']['certification_level'],'read capability survives benign version drift');
expect_same(false,$drift['capabilities']['query_builder.read']['surface_exposed'],'catalog-only query-builder capability remains latent when no adapter ability is mounted');
expect_same('QUARANTINED',$drift['capabilities']['query_builder.read']['certification_level'],'latent structural drift remains visible as capability evidence');
expect_true(in_array('query_builder.read',$drift['structural_scope']['latent_incompatibilities'],true),'latent structural drift is reported separately');
expect_same(0,$drift['structural_scope']['exposed_incompatibility_count'],'latent drift must not poison exposed provider compatibility');
expect_same('DISCOVERED',$drift['capabilities']['post_meta.bounded-write']['certification_level'],'write capability does not self-certify from structure');
expect_true(false===$drift['capabilities']['post_meta.bounded-write']['write_eligible'],'version drift cannot open write');
$drift_projection=MAD4B_SCP_Provider_Compatibility_Certification::adapter_mount_projection('jetengine',$adapter);
expect_same(false,$drift_projection['all_write_abilities_eligible'],'MCP compatibility bridge remains fail closed while a write capability is unattested');
expect_true(in_array('jetengine/update-post-meta',array_column($drift_projection['blocked'],'ability'),true),'drifted write appears in capability-blocked mount evidence');
$guard=MAD4B_SCP_Provider_Compatibility_Certification::mutation_guard('jetengine','jetengine/update-post-meta',true,$adapter);
expect_true(is_wp_error($guard),'drifted write remains fail closed');
$plan=MAD4B_SCP_Provider_Compatibility_Certification::recertification_plan(array('provider_id'=>'jetengine'));
expect_same('OWNER_REVIEW_REQUIRED',$plan['classification'],'write-path drift requires governed behavioral/rollback review');

MAD4B_SCP_Provider_Contracts::$exact=true;
MAD4B_SCP_Provider_Compatibility_Certification::clear_request_cache();
$exact=MAD4B_SCP_Provider_Compatibility_Certification::assess_provider('jetengine',$adapter);
expect_same('certified',$exact['compatibility_state'],'exact certified artifact remains certified');
expect_same('FULLY_CERTIFIED',$exact['capabilities']['post_meta.read']['certification_level'],'exact read capability is fully certified');
expect_same('REVERSIBLE_WRITE_CERTIFIED',$exact['capabilities']['post_meta.bounded-write']['certification_level'],'exact reversible write receives reversible certification');
expect_same('active',$exact['capabilities']['post_meta.bounded-write']['activation_stage'],'exact bounded reversible write can be active');
expect_true(true===$exact['capabilities']['post_meta.bounded-write']['write_eligible'],'exact reversible write is eligible for mount compilation');
$exact_projection=MAD4B_SCP_Provider_Compatibility_Certification::adapter_mount_projection('jetengine',$adapter);
expect_same(true,$exact_projection['all_write_abilities_eligible'],'exact reversible write compiles into the MCP provider gate');
expect_true(in_array('jetengine/update-post-meta',array_column($exact_projection['eligible'],'ability'),true),'exact certified write appears in MCP mount evidence');
expect_same(true,MAD4B_SCP_Provider_Compatibility_Certification::mutation_guard('jetengine','jetengine/update-post-meta',true,$adapter),'exact bounded capability guard passes');

$bitflows=new FakeBitFlowsAdapter();
MAD4B_SCP_Provider_Compatibility_Certification::clear_request_cache();
$high=MAD4B_SCP_Provider_Compatibility_Certification::assess_provider('bit_pi',$bitflows);
expect_same('certified',$high['compatibility_state'],'exact Bit Flows artifact may be artifact-certified');
expect_same('FULLY_CERTIFIED',$high['capabilities']['flows.read']['certification_level'],'Bit Flows reads may use exact certification');
expect_same('DISCOVERED',$high['capabilities']['flow.execute']['certification_level'],'high-risk execution cannot inherit full certification from package identity');
expect_same('shadow',$high['capabilities']['flow.execute']['activation_stage'],'high-risk execution defaults to shadow');
expect_same(true,$high['capabilities']['flow.execute']['activation_required'],'high-risk execution requires a separate activation decision');
expect_same(true,$high['capabilities']['flow.execute']['behavioral_probe_required'],'high-risk execution requires trusted behavioral evidence even on an exact artifact');
expect_same(false,$high['capabilities']['flow.execute']['write_eligible'],'high-risk execution cannot auto-mount from exact artifact certification');
$high_projection=MAD4B_SCP_Provider_Compatibility_Certification::adapter_mount_projection('bit_pi',$bitflows);
expect_same(false,$high_projection['all_write_abilities_eligible'],'high-risk adapter remains write-blocked before governed activation');
expect_true(in_array('bitflows/run-flow',array_column($high_projection['blocked'],'ability'),true),'high-risk mutation appears in blocked mount evidence');
$blocked_high=$high_projection['blocked'][0];
expect_same('high_risk_activation_required',$blocked_high['reason'],'high-risk block reason is explicit');
expect_same('shadow',$blocked_high['activation_stage'],'blocked mount evidence preserves activation stage');
$high_guard=MAD4B_SCP_Provider_Compatibility_Certification::mutation_guard('bit_pi','bitflows/run-flow',true,$bitflows);
expect_true(is_wp_error($high_guard),'high-risk execution guard remains fail closed');
expect_true(in_array('high_risk_activation_required',$high_guard->data['violations'],true),'high-risk execution guard explains activation blocker');
$high_plan=MAD4B_SCP_Provider_Compatibility_Certification::recertification_plan(array('provider_id'=>'bit_pi'));
expect_same('OWNER_REVIEW_REQUIRED',$high_plan['classification'],'high-risk activation requires owner review after behavioral evidence');

$mount=MAD4B_SCP_Provider_Compatibility_Certification::mcp_mount_plan();
$eligible=array_column($mount['eligible'],'ability');
$blocked=array_column($mount['blocked'],'ability');
expect_true(in_array('jetengine/update-post-meta',$eligible,true),'MCP mount plan includes exact certified reversible write');
expect_true(in_array('jetengine/get-post-meta',$eligible,true),'MCP mount plan includes compatible read ability');
expect_true(!in_array('bitflows/run-flow',$eligible,true),'MCP mount plan never exposes shadow high-risk execution');
expect_true(in_array('bitflows/run-flow',$blocked,true),'MCP mount plan reports shadow high-risk execution as blocked');
expect_same(false,$mount['authorizing'],'mount plan is evidence, not authority');
expect_true(isset($mount['latent']) && is_array($mount['latent']),'mount plan must separate latent catalog abilities from mounted eligibility');

$registry_source=file_get_contents(dirname(__DIR__).'/includes/class-mad4b-scp-adapter-registry.php');
foreach(array('provider_contract_advisories','provider_not_active_in_current_runtime','provider_inactive_drift_is_blocking') as $marker){
    expect_true(false!==strpos($registry_source,$marker),'runtime self-test must preserve inactive provider drift as advisory evidence: '.$marker);
}
$bitflows_source=file_get_contents(dirname(__DIR__).'/includes/adapters/class-mad4b-scp-bitflows-adapter.php');
foreach(array('mad4b.bitflows-runtime-contract-diagnostic.v1','declared_class_suffix_candidates','autoload_or_bootstrap_mutation_attempted','filesystem_scan_performed') as $marker){
    expect_true(false!==strpos($bitflows_source,$marker),'Bit Flows must expose bounded non-mutating runtime contract diagnostics: '.$marker);
}

$adapter_base_source=file_get_contents(dirname(__DIR__).'/includes/adapters/class-mad4b-scp-adapter-base.php');
expect_true(false!==strpos($adapter_base_source,"capability_mount_projection"),'Adapter Base must expose capability mount projection to MCP compiler');
expect_true(false!==strpos($adapter_base_source,"per_ability_separate_from_artifact_truth"),'Adapter Base must declare capability truth separate from artifact truth');
expect_true(false===strpos($adapter_base_source,"legacy_runtime_contract_ok"),'Adapter Base must not rewrite artifact certification through a legacy capability bridge');
expect_true(false===strpos($adapter_base_source,"capability_compiled_fail_closed"),'obsolete provider-wide capability bridge must be removed');

echo "MAD4B provider compatibility engine contract passed.\n";
