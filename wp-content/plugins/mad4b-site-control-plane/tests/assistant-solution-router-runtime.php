<?php
/** Exact source fixture. No real WordPress, network or file changes. */
if ( ! defined( 'ABSPATH' ) ) define( 'ABSPATH', __DIR__ );
class WP_Error { private $code; public function __construct($x,$m='',$d=null){$this->code=$x;} public function get_error_code(){return $this->code;} }
function is_wp_error($x){return $x instanceof WP_Error;}
$GLOBALS['is_admin']=true; $GLOBALS['abilities']=array(); $GLOBALS['checks']=0;
function current_user_can($x){return $x==='manage_options' && $GLOBALS['is_admin'];}
function add_action($x,$y,$z=10){} function wp_has_ability($x){return isset($GLOBALS['abilities'][$x]);}
function wp_register_ability($x,$y){$GLOBALS['abilities'][$x]=$y;}
function get_option($k,$d=array()){return $k==='active_plugins' ? array('file-ops/one.php') : $d;}
function get_plugins(){return array('file-ops/one.php'=>array('Name'=>'File Workspace'),
    'file-ops/two.php'=>array('Name'=>'File Explorer'));}
function wp_get_abilities(){return array();}
function get_mu_plugins(){return array();}
function get_dropins(){return array();}
class MAD4B_SCP_Adapter_Base {}
class MAD4B_SCP_Adaptive_Operations_Context {
    public static $binding;
    public static function current(){return self::$binding;}
}
function ok($x,$desc){++$GLOBALS['checks'];if(!$x){fwrite(STDERR,'FAIL '.$desc.PHP_EOL);exit(1);}}
require_once dirname(__DIR__).'/includes/class-mad4b-scp-assistant-planning.php';
require_once dirname(__DIR__).'/includes/class-mad4b-scp-solution-discovery.php';
require_once dirname(__DIR__).'/includes/class-mad4b-scp-assistant-solution-router.php';
$b=array('site_uuid'=>'fixture-site','environment'=>'staging',
    'profile_digest'=>str_repeat('a',64),'runtime_generation'=>str_repeat('b',64),
    'artifact_sha256'=>str_repeat('c',64),'origin_sha256'=>str_repeat('d',64),
    'external_record_sha256'=>str_repeat('e',64),'restore_epoch'=>1);
MAD4B_SCP_Adaptive_Operations_Context::$binding=$b;
$p=array('expected_profile_digest'=>$b['profile_digest'],
    'expected_runtime_generation'=>$b['runtime_generation'],
    'desired'=>array('capabilities'=>array(array('capability'=>'file.management','required'=>true))),
    'observed'=>array('capabilities'=>array(array('capability'=>'file.management','state'=>'missing'))));
$arg=array('planning_input'=>$p, 'related_terms'=>array('file'), 'limit_per_gap'=>8);
$r=MAD4B_SCP_Assistant_Solution_Router::read_plan($arg);
ok(is_array($r) && $r['contract']==='mad4b.assistant-solution-discovery.v1','route contract');
ok(count($r['tasks'])===1 && $r['tasks'][0]['planner_decision']==='DISCOVER_ALTERNATIVES','native planner used');
ok($r['tasks'][0]['total_matches']===2 && count($r['tasks'][0]['candidates'])===2,'two unmapped plugin files discovered');
ok($r['tasks'][0]['execution_allowed']===false && $r['authorizing']===false
    && $r['provider_executed']===false && $r['automatic_install_allowed']===false,'inert');
ok($r['registry_coverage']['external_inventory_complete']===false,'external coverage unknown');
$second=MAD4B_SCP_Assistant_Solution_Router::read_plan($arg);
ok($r['tasks'][0]['snapshot_sha256']===$second['tasks'][0]['snapshot_sha256'],'deterministic');
$arg['external_hints']=array(array('id'=>'hosting','label'=>'File Host Configuration','source'=>'connector'));
$external=MAD4B_SCP_Assistant_Solution_Router::read_plan($arg);
ok(is_array($external) && $external['tasks'][0]['total_matches']===3
    && $external['tasks'][0]['execution_allowed']===false, 'external hint observed only');
$arg['external_hints'][0]['source']='privileged_shell';
ok(is_wp_error(MAD4B_SCP_Assistant_Solution_Router::read_plan($arg)),'unsafe external hint rejected');
unset($arg['external_hints']);
$arg['planning_input']['expected_runtime_generation']=str_repeat('f',64);
ok(is_wp_error(MAD4B_SCP_Assistant_Solution_Router::read_plan($arg)),'stale plan denied');
$arg['planning_input']=$p;$arg['override']=true;
ok(is_wp_error(MAD4B_SCP_Assistant_Solution_Router::read_plan($arg)),'unknown inputs denied');
unset($arg['override']);$GLOBALS['is_admin']=false;
ok(is_wp_error(MAD4B_SCP_Assistant_Solution_Router::read_plan($arg)),'permission gate');
$GLOBALS['is_admin']=true;$arg['related_terms']=array('https://evil.invalid');
ok(is_wp_error(MAD4B_SCP_Assistant_Solution_Router::read_plan($arg)),'unsafe hints denied');
$arg['related_terms']=array('unknown');$arg['planning_input']['desired']['capabilities'][0]['capability']='unrelated.analytics';
$arg['planning_input']['observed']['capabilities'][0]['capability']='unrelated.analytics';
$empty=MAD4B_SCP_Assistant_Solution_Router::read_plan($arg);
ok(is_array($empty) && $empty['tasks'][0]['total_matches']===0
    && $empty['tasks'][0]['status']==='EXPAND_DISCOVERY','negative no result');
MAD4B_SCP_Assistant_Solution_Router::register_ability();
ok(isset($GLOBALS['abilities']['mad4b/assistant-solution-discover'])
    && $GLOBALS['abilities']['mad4b/assistant-solution-discover']['meta']['annotations']['readonly']===true,'registered read only');
echo 'MAD4B_ASSISTANT_SOLUTION_ROUTER: PASS '.$GLOBALS['checks'].' assertions'.PHP_EOL;
