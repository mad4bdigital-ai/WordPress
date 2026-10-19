<?php
namespace ETG\DynamicFilterSEOBridge\Config {
    class Configuration { public function enabled() { return $GLOBALS['globalOn']; } }
}
namespace {
    define('ABSPATH', __DIR__);
    $env = 'staging'; $admin = true; $globalOn = false; $certified = false; $context = array(); $options = array();
    function add_action($name, $callback) {}
    function wp_get_environment_type() { return $GLOBALS['env']; }
    function current_user_can($cap) { return $GLOBALS['admin']; }
    function home_url($path) { return 'https://example.test' . $path; }
    function get_option($key, $default = false) {
        if (!empty($GLOBALS['staleRouteCache']) && 'etg_indexflow_routes' === $key) return $default;
        return array_key_exists($key,$GLOBALS['options']) ? $GLOBALS['options'][$key] : $default;
    }
    function update_option($key,$value,$autoload = null) { $GLOBALS['options'][$key]=$value; return true; }
    function delete_option($key) { unset($GLOBALS['options'][$key]);return true; }
    function add_option($key,$value,$deprecated='',$autoload=null) { if(array_key_exists($key,$GLOBALS['options']))return false;$GLOBALS['options'][$key]=$value;return true; }
    function wp_generate_uuid4() { return bin2hex(random_bytes(16)); }
    function wp_cache_delete($key,$group) {}
    function is_serialized($raw) { return is_string($raw) && 0 === strpos($raw,'a:'); }
    function is_wp_error($value) { return $value instanceof WP_Error; }
    class WP_Error { private $code; public function __construct($code,$message='') { $this->code=$code; } public function get_error_code() { return $this->code; } }
    class MAD4B_SCP_Policy { public static function can_read() { return true; } public static function can_mutate() { return $GLOBALS['admin']; } }
    class MAD4B_SCP_Provider_Contracts { public static function mutation_guard($provider,$available=null) { return $GLOBALS['certified'] ? true : new WP_Error('mad4b_provider_mutation_not_certified'); } }
    class MAD4B_SCP_Reversible_Adapter_Mutations { public static function current_mutation_context() { return $GLOBALS['context']; } }
    abstract class MAD4B_SCP_Adapter_Base {
        protected function schema(array $p,array $r=array()) { return array('properties'=>$p,'required'=>$r); }
        protected function add_ability(...$args) {}
    }
    $wpdb = new class {
        public $options = 'wp_options';
        public $last_error = '';
        public function prepare($sql,...$args) { return $sql; }
        public function get_var($sql) {
            $key=\ETG\DynamicFilterSEOBridge\IndexFlow\RouteDocumentStore::OPTION;
            return array_key_exists($key,$GLOBALS['options']) ? serialize($GLOBALS['options'][$key]) : null;
        }
        public function delete($table,$where,$format) {
            if (get_option($where['option_name']) !== $where['option_value']) return 0;
            delete_option($where['option_name']);return 1;
        }
    };
    spl_autoload_register(static function ($class) {
        $prefix='ETG\\DynamicFilterSEOBridge\\';
        if(0===strpos($class,$prefix))require_once dirname(__DIR__,2).'/etg-dynamic-filter-seo-bridge/includes/'.str_replace('\\','/',substr($class,strlen($prefix))).'.php';
    });
    require dirname(__DIR__).'/includes/adapters/class-mad4b-scp-indexflow-routes-adapter.php';
    $n = 0;
    function check($condition,$message) { $GLOBALS['n']++;if(!$condition)throw new \RuntimeException($message); }
    $adapter = new MAD4B_SCP_IndexFlow_Routes_Adapter();
    $doc = array('version'=>1,'profiles'=>array(),'routes'=>array());
    $plan = $adapter->routes_plan(array('document'=>$doc));
    check(is_array($plan) && !$options,'Read plan has no persistent effects');
    $input = $plan + array('document'=>$doc,'confirmed'=>true);
    check(is_wp_error($adapter->routes_apply($input)),'Uncertified provider denied');
    $certified = true;
    check(is_wp_error($adapter->routes_apply($input)),'Direct unjournaled invocation denied');
    $lease = $adapter->acquire_reversible_scope($adapter::APPLY,$input);
    check(is_array($lease),'Shared option lock acquired');
    $other = new MAD4B_SCP_IndexFlow_Routes_Adapter();
    check(is_wp_error($other->acquire_reversible_scope($adapter::APPLY,$input)),'Concurrent adapter cannot snapshot same option');
    $before = $adapter->capture_reversible_state($adapter::APPLY,$input);
    check(is_array($before) && !$before['state']['exists'],'Captured absent option in journal scope');
    $context = array('mutation_id'=>'fixture','adapter_id'=>'etg-dfsb-write');
    check(is_wp_error($adapter->routes_apply($input)),'Unrelated ETG writer context cannot apply routes');
    $context['adapter_id'] = $adapter->id();
    $result = $adapter->routes_apply($input);
    check(!is_wp_error($result) && $result['verified'] && !$result['runtime_enabled'],'Journaled apply verifies inactive registry');
    $staleRouteCache = true;
    check(is_wp_error($adapter->routes_apply($input)),'Stale retry rejected after prior write');
    check($adapter->routes_verify(array('expected_sha256'=>$result['state_sha256']))['expected_matches'],'Independent readback matches');
    check(get_option('etg_indexflow_routes',null) === null, 'Snapshot/readback ignore deliberately stale option cache');
    $staleRouteCache = false;
    $undo = $adapter->restore_reversible_state($adapter::APPLY,$before['target'],$before['state'],array('after_sha256'=>$result['state_sha256']));
    check(!is_wp_error($undo) && $undo['restored'],'Undo restores absence with expected after-state');
    $adapter->release_reversible_scope($lease);
    check(!$options,'Route option and lock absent after undo/release');
    $env='production';check(!$adapter->is_available() && is_wp_error($adapter->acquire_reversible_scope($adapter::APPLY,$input)),'Production denied');$env='staging';
    $admin=false;check(is_wp_error($adapter->routes_plan(array('document'=>$doc))),'Non-administrator denied');$admin=true;
    $globalOn=true;check(is_wp_error($adapter->acquire_reversible_scope($adapter::APPLY,$input)),'Global ON denies writes');$globalOn=false;
    echo "IndexFlow governed adapter: $n checks PASS (fixture authority only; live certification absent)\n";
}
