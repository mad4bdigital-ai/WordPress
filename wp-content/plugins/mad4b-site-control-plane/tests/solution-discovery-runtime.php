<?php
/* Native hermetic runtime fixture: no WordPress, file mutations or provider execution. */
if ( ! defined( 'ABSPATH' ) ) define( 'ABSPATH', __DIR__ );
class WP_Error { private $code; public function __construct( $c, $m = '', $d = array() ) { $this->code = $c; } public function get_error_code() { return $this->code; } }
function is_wp_error( $x ) { return $x instanceof WP_Error; }
$GLOBALS['plugin_version'] = '2.1.0';
$GLOBALS['fail_ability_registry'] = false;
$GLOBALS['fail_plugin_registry'] = false;
$GLOBALS['large_inventory'] = false;
$GLOBALS['include_malformed'] = true;
function is_multisite() { return true; }
function is_plugin_active_for_network( $file ) { return $file === 'unknown-file-ops/alternate.php'; }
$GLOBALS['is_admin'] = true;
function current_user_can( $cap ) { return $cap === 'manage_options' && $GLOBALS['is_admin']; }
$GLOBALS['tests'] = 0; $GLOBALS['hooks'] = array(); $GLOBALS['abilities'] = array();
function ok( $yes, $reason ) { ++$GLOBALS['tests']; if ( ! $yes ) { fwrite( STDERR, 'FAIL: ' . $reason . PHP_EOL ); exit(1); } }
function add_action( $hook, $callback, $priority=10 ) { $GLOBALS['hooks'][$hook] = $callback; }
function wp_has_ability( $name ) { return isset( $GLOBALS['abilities'][$name] ); }
function wp_register_ability( $name, $conf ) { $GLOBALS['abilities'][$name] = $conf; }
function get_option( $key, $default=array() ) { return $key === 'active_plugins' ? array( 'unknown-file-ops/tool.php', 'plain-cache/cache.php' ) : $default; }
function get_plugins() {
    if ( $GLOBALS['fail_plugin_registry'] ) throw new RuntimeException('plugin registry');
    if ( $GLOBALS['large_inventory'] ) {
        $out=array();
        for($i=0;$i<240;++$i) $out['module'.$i.'/main.php']=array('Name'=>'Module '.$i);
        return $out;
    }
    $out=array(
    'unknown-file-ops/tool.php' => array( 'Name' => 'Generic File Workspace' ),
    'plain-cache/cache.php' => array( 'Name' => 'Caching Layers' ),
    'unknown-file-ops/alternate.php' => array( 'Name' => 'Alternate File Panel', 'Version' => $GLOBALS['plugin_version'] ),
    'malformed-path' => array( 'Name' => 'Untrusted' ),
);
if(!$GLOBALS['include_malformed']) unset($out['malformed-path']);
return $out; }
function get_mu_plugins() { return array(
    'mandatory-loader.php'=>array('Name'=>'Mandatory Network Overrides','Version'=>'1.1')
); }
function get_dropins() { return array(
    'object-cache.php'=>array('Name'=>'Object Caching Handler','Version'=>'4.2')
); }
class FixtureAbility {
    public function get_label() { return 'Inspect File Metadata'; }
    public function get_description() { return 'Read file details without editing'; }
    public function get_meta() { return array('show_in_rest'=>true); }
}
class PrivateFixtureAbility extends FixtureAbility {
    public function get_label() { return 'Rotate Private Keys'; }
    public function get_meta() { return array('show_in_rest'=>false); }
}
function wp_get_abilities() {
    if ( $GLOBALS['fail_ability_registry'] ) throw new RuntimeException('ability registry');
    if ( $GLOBALS['large_inventory'] ) {
        $out=array();
        for($i=0;$i<240;++$i) $out['fixture/module'.$i] = new FixtureAbility();
        return $out;
    }
    return array(
    'demo/file-inspect' => new FixtureAbility(),
    'private/rotate-keys' => new PrivateFixtureAbility(),
); }
class MAD4B_SCP_Adapter_Base {}
class Registry { public $adapters=array(); public function register( $v ) { $this->adapters[$v->id()]=$v; } }
class MAD4B_SCP_Adaptive_Operations_Context {
    public static $binding; public static $flip = false; public static $calls = 0;
    public static function current() {
        ++self::$calls;
        if ( self::$flip && self::$calls === 2 ) {
            $changed=self::$binding; $changed['runtime_generation']=str_repeat('f',64);
            return $changed;
        }
        return self::$binding;
    }
}
require_once dirname( __DIR__ ) . '/includes/class-mad4b-scp-solution-discovery.php';
$b = array( 'profile_digest'=>str_repeat('a',64), 'runtime_generation'=>str_repeat('b',64),
    'site_uuid'=>'site-uuid', 'restore_epoch'=>1, 'artifact_sha256'=>str_repeat('c',64) );
MAD4B_SCP_Adaptive_Operations_Context::$binding = $b;
$in = array( 'expected_profile_digest'=>$b['profile_digest'],
    'expected_runtime_generation'=>$b['runtime_generation'],
    'intent'=>'Edit site files', 'related_terms'=>array('file manager'), 'mode'=>'match' );
$inventory = MAD4B_SCP_Solution_Discovery::site_inventory();
ok( count($inventory['rows']) === 6, 'regular MU drop-ins and visible abilities enumerated' );
ok( count(array_unique(array_column($inventory['rows'],'id'))) === 6, 'unique identities across plugin families' );
ok( !in_array('ability:private/rotate-keys',array_column($inventory['rows'],'id'),true), 'private ability hidden from read discovery' );
ok( !$inventory['plugin_inventory_complete'] && $inventory['ability_inventory_complete']
    && $inventory['extension_inventory_complete'], 'malformed plugin marks partial coverage' );
$mu=array_values(array_filter($inventory['rows'],function($r){return $r['id']==='must_use_plugin:mandatory-loader.php';}));
$drop=array_values(array_filter($inventory['rows'],function($r){return $r['id']==='dropin:object-cache.php';}));
ok(count($mu)===1 && $mu[0]['observed_state']==='active','MU extension active');
ok(count($drop)===1 && $drop[0]['observed_state']==='registered','drop-in presence cannot assert activation');
$network=array_values(array_filter($inventory['rows'],function($x){return $x['id']==='plugin:unknown-file-ops/alternate.php';}));
ok(count($network)===1 && $network[0]['observed_state']==='active','network plugin active');
$r = MAD4B_SCP_Solution_Discovery::read_discover($in);
ok( is_array($r) && count($r['candidates']) === 3, 'unmapped plugin and ability discovered dynamically' );
ok( $r['candidates'][0]['lexical_score'] >= $r['candidates'][1]['lexical_score'], 'ranked' );
ok( $r['candidates'][0]['execution_allowed'] === false && !$r['mutation_performed'], 'never auto executes' );
ok( $r['mapping_required_to_discover'] === false && !$r['auto_install_allowed'], 'no adapter requirement' );
ok( $r['coverage']['external_inventory_complete'] === false, 'external coverage not falsely complete' );
ok( $r['coverage']['ability_visibility_scope'] === 'show_in_rest_only', 'WordPress private abilities filtered' );
ok( count(array_filter($r['candidates'], function($c) { return !empty($c['metadata_digest']); })) === 2, 'plugin version/state digest recorded' );
$again = MAD4B_SCP_Solution_Discovery::read_discover($in);
ok( $r['snapshot_sha256'] === $again['snapshot_sha256'] && $r['candidates'] === $again['candidates'], 'deterministic' );
$GLOBALS['plugin_version']='2.2.0';
$changed=MAD4B_SCP_Solution_Discovery::read_discover($in);
ok(is_array($changed) && $changed['snapshot_sha256']!==$r['snapshot_sha256'],'version change invalidates candidate snapshot');
$GLOBALS['plugin_version']='2.1.0';
$in['mode']='inventory'; $in['limit']=1; $all=MAD4B_SCP_Solution_Discovery::read_discover($in);
ok( $all['total_matches']===6 && $all['next_offset']===1, 'inventory pagination' );
$in['offset']=1; $next=MAD4B_SCP_Solution_Discovery::read_discover($in);
ok( $next['candidates'][0]['id']!==$all['candidates'][0]['id'], 'pagination unique' );
$in['mode']='match'; $in['offset']=0; $in['related_terms']=array();
$in['intent']='Unrelated analysis'; $empty=MAD4B_SCP_Solution_Discovery::read_discover($in);
ok( $empty['total_matches']===0 && $empty['decision']==='INVENTORY_INCOMPLETE_RETRY',
    'unknown cannot be treated as absence when inventory is incomplete' );
$GLOBALS['include_malformed']=false;
$complete=MAD4B_SCP_Solution_Discovery::read_discover($in);
ok(is_array($complete) && $complete['decision']==='EXPAND_INVENTORY_OR_EXTERNAL_DISCOVERY',
    'complete inventory can truthfully declare no lexical matches');
$GLOBALS['include_malformed']=true;
$in['intent']='Object cache'; $dropMatch=MAD4B_SCP_Solution_Discovery::read_discover($in);
ok(is_array($dropMatch) && in_array('dropin:object-cache.php',array_column($dropMatch['candidates'],'id'),true),
    'generic drop-in route match without vendor mapping');
$in['intent']='Unrelated analysis';
$in['intent']='Review file access';
$in['external_hints']=array(array('id'=>'hosting','label'=>'File Hosting Interface','source'=>'connector'));
$hint=MAD4B_SCP_Solution_Discovery::read_discover($in);
ok( $hint['total_matches']===4 && $hint['candidates'][0]['behavior_verified']===false, 'external hints remain unverified' );
$GLOBALS['is_admin']=false;
ok( is_wp_error(MAD4B_SCP_Solution_Discovery::read_discover($in)), 'non-admin rejected before inventory' );
$GLOBALS['is_admin']=true;
$GLOBALS['fail_ability_registry']=true;
$degraded=MAD4B_SCP_Solution_Discovery::read_discover($in);
ok(is_array($degraded) && $degraded['inventory_incomplete']===true
    && $degraded['coverage']['ability_inventory_complete']===false,'ability exception marks incomplete');
$GLOBALS['fail_ability_registry']=false;
$GLOBALS['fail_plugin_registry']=true;
$degraded=MAD4B_SCP_Solution_Discovery::read_discover($in);
ok(is_array($degraded) && $degraded['inventory_incomplete']===true
    && $degraded['coverage']['plugin_inventory_complete']===false,'plugin exception marks incomplete');
$GLOBALS['fail_plugin_registry']=false;
MAD4B_SCP_Adaptive_Operations_Context::$calls=0; MAD4B_SCP_Adaptive_Operations_Context::$flip=true;
ok(is_wp_error(MAD4B_SCP_Solution_Discovery::read_discover($in)),'mid-inventory generation drift denied');
MAD4B_SCP_Adaptive_Operations_Context::$flip=false;
$GLOBALS['large_inventory']=true;
$paginated=$in; $paginated['intent']='Module'; $paginated['mode']='inventory';
$paginated['offset']=400; $paginated['limit']=40; unset($paginated['external_hints']);
$far=MAD4B_SCP_Solution_Discovery::read_discover($paginated);
ok(is_array($far) && count($far['candidates'])===40 && $far['next_offset']===440,
    'deep pagination reachable past legacy 400 ceiling');
$GLOBALS['large_inventory']=false;
$bad=$in; $bad['unknown']=true;
ok( is_wp_error(MAD4B_SCP_Solution_Discovery::read_discover($bad)), 'reject arbitrary input' );
$bad=$in; $bad['external_hints'][0]['source']='admin';
ok( is_wp_error(MAD4B_SCP_Solution_Discovery::read_discover($bad)), 'reject unknown source' );
$bad=$in; $bad['expected_runtime_generation']=str_repeat('d',64);
ok( is_wp_error(MAD4B_SCP_Solution_Discovery::read_discover($bad)), 'deny stale generation' );
$bad=$in; $bad['related_terms']=array('https://evil.example/path');
ok( is_wp_error(MAD4B_SCP_Solution_Discovery::read_discover($bad)), 'deny unsafe term' );
$bad=$in; $bad['intent']=array('file');
ok( is_wp_error(MAD4B_SCP_Solution_Discovery::read_discover($bad)), 'deny type mismatch' );
$bad=$in; $bad['related_terms']=array_fill(0, 13,'files');
ok( is_wp_error(MAD4B_SCP_Solution_Discovery::read_discover($bad)), 'bounded hints' );
$bad=$in; $bad['external_hints'][]=$bad['external_hints'][0];
ok( is_wp_error(MAD4B_SCP_Solution_Discovery::read_discover($bad)), 'duplicate candidate fails closed' );
MAD4B_SCP_Solution_Discovery::boot();
ok( isset($GLOBALS['hooks']['wp_abilities_api_init']), 'boot hooks' );
$registry=new Registry(); MAD4B_SCP_Solution_Discovery::register_adapter($registry);
ok( isset($registry->adapters['solution-discovery']) && $registry->adapters['solution-discovery']->ability_names()['admin']===array(), 'read-only adapter' );
MAD4B_SCP_Solution_Discovery::register_ability();
ok( isset($GLOBALS['abilities']['mad4b/solution-discover']) &&
    $GLOBALS['abilities']['mad4b/solution-discover']['meta']['annotations']['readonly']===true, 'readonly ability' );
echo 'MAD4B_SOLUTION_DISCOVERY: PASS ' . $GLOBALS['tests'] . ' assertions' . PHP_EOL;
