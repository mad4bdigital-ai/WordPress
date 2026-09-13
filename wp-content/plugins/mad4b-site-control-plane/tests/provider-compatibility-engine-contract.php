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
eval( 'namespace Jet_Engine\\Query_Builder; class Manager {}' );
class WP_Error { public $code; public $message; public $data; public function __construct( $code, $message, $data = array() ) { $this->code=$code; $this->message=$message; $this->data=$data; } }
function is_wp_error( $value ) { return $value instanceof WP_Error; }

final class MAD4B_SCP_Provider_Contracts {
    public static $exact = false;
    public static function get( $provider ) { return array( 'plugin_file'=>'jet-engine/jet-engine.php', 'archive_sha256'=>str_repeat('a',64) ); }
    public static function runtime_status( $provider, $available = null ) {
        return array(
            'provider'=>$provider,
            'status'=>self::$exact?'certified':'version_drift',
            'runtime_contract_ok'=>self::$exact,
            'installed_version'=>'3.8.15',
            'certified_versions'=>array('3.8.11.2'),
            'certification_authority'=>'test-fixture',
            'runtime_integrity'=>array('required'=>true,'manifest_present'=>true,'verified'=>array('jet-engine.php'),'missing'=>array(),'mismatched'=>self::$exact?array():array('jet-engine.php'=>array('reason'=>'hash_mismatch'))),
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
final class MAD4B_SCP_Adapter_Registry {
    private static $instance;
    private $adapter;
    public static function instance(){ if(!self::$instance) self::$instance=new self(); return self::$instance; }
    public function __construct(){ $this->adapter=new FakeJetEngineAdapter(); }
    public function register_defaults(){}
    public function get($id){ return 'jetengine'===$id?$this->adapter:null; }
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
expect_true(true===$exact['capabilities']['post_meta.bounded-write']['write_eligible'],'exact reversible write is eligible for mount compilation');
$exact_projection=MAD4B_SCP_Provider_Compatibility_Certification::adapter_mount_projection('jetengine',$adapter);
expect_same(true,$exact_projection['all_write_abilities_eligible'],'exact reversible write compiles into the legacy MCP provider gate');
expect_true(in_array('jetengine/update-post-meta',array_column($exact_projection['eligible'],'ability'),true),'exact certified write appears in MCP mount evidence');
expect_same(true,MAD4B_SCP_Provider_Compatibility_Certification::mutation_guard('jetengine','jetengine/update-post-meta',true,$adapter),'exact capability guard passes');

$mount=MAD4B_SCP_Provider_Compatibility_Certification::mcp_mount_plan();
$eligible=array_column($mount['eligible'],'ability');
expect_true(in_array('jetengine/update-post-meta',$eligible,true),'MCP mount plan includes exact certified reversible write');
expect_true(in_array('jetengine/get-post-meta',$eligible,true),'MCP mount plan includes compatible read ability');
expect_same(false,$mount['authorizing'],'mount plan is evidence, not authority');

$adapter_base_source=file_get_contents(dirname(__DIR__).'/includes/adapters/class-mad4b-scp-adapter-base.php');
expect_true(false!==strpos($adapter_base_source,"capability_mount_projection"),'Adapter Base must expose capability mount projection to MCP compiler');
expect_true(false!==strpos($adapter_base_source,"legacy_runtime_contract_ok"),'Adapter Base must preserve legacy provider truth while compiling capability eligibility');
expect_true(false!==strpos($adapter_base_source,"capability_compiled_fail_closed"),'MCP compatibility bridge must be explicitly fail closed');

echo "MAD4B provider compatibility engine contract passed.\n";
