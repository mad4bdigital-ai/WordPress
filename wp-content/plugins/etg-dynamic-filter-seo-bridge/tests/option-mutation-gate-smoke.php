<?php
/** Standalone fail-closed, identity-scoped ETG Admin/MCP option lock regression. */
$GLOBALS['etg_gate_options']=array();
$GLOBALS['etg_gate_cache_deletes']=array();
class ETG_Gate_DB {
    public $options='wp_options'; public $fail_delete=false;
    public function delete($table,$where,$format) {
        if($table!==$this->options || $format!==array('%s','%s'))return false;
        if($this->fail_delete)return false;
        $key=$where['option_name']??'';
        if(!isset($GLOBALS['etg_gate_options'][$key])
            ||$GLOBALS['etg_gate_options'][$key]!==($where['option_value']??''))return 0;
        unset($GLOBALS['etg_gate_options'][$key]);return 1;
    }
}
$GLOBALS['wpdb']=new ETG_Gate_DB();
function wp_cache_delete($key,$group){$GLOBALS['etg_gate_cache_deletes'][]=$group.':'.$key;return true;}
function wp_generate_uuid4(){return 'ci-'.bin2hex(random_bytes(8));}
function add_option($key,$value,$unused='',$autoload='no'){
    if(array_key_exists($key,$GLOBALS['etg_gate_options']))return false;
    $GLOBALS['etg_gate_options'][$key]=$value;return true;
}
function get_option($key,$default=''){return $GLOBALS['etg_gate_options'][$key]??$default;}
function delete_option($key){if(!array_key_exists($key,$GLOBALS['etg_gate_options']))return false;unset($GLOBALS['etg_gate_options'][$key]);return true;}
class WP_Error {
    private $code;public function __construct($code,$message=''){$this->code=$code;}
    public function get_error_code(){return $this->code;}
}
function is_wp_error($value){return $value instanceof WP_Error;}
require_once dirname(__DIR__).'/includes/Runtime/OptionMutationGate.php';
use ETG\DynamicFilterSEOBridge\Runtime\OptionMutationGate as Gate;
function ck($ok,$label){if(!$ok)throw new RuntimeException($label);}
ck(is_wp_error(Gate::acquire('wp_users')),'unknown option cannot be locked');
$slot=Gate::acquire('etg_dfsb_dynamic_content_slots');
ck(is_array($slot),'slot resource lock acquired');
ck(is_wp_error(Gate::acquire('etg_dfsb_dynamic_content_slots')),'second same-resource writer denied');
$profile=Gate::acquire('etg_dfsb_settings');
ck(is_array($profile)&&$profile['key']!==$slot['key'],'distinct physical options have distinct locks');
$stale=$slot;$stale['token']='not-the-owner';
ck(!Gate::release($stale),'stale token cannot release a newer owner');
ck(is_wp_error(Gate::acquire('etg_dfsb_dynamic_content_slots')),'non-owner release does not unlock');
ck(Gate::release($slot),'owner can release');
ck(!Gate::release($slot),'double release does not delete subsequent ownership');
$another=Gate::acquire('etg_dfsb_dynamic_content_slots');
ck(is_array($another)&&$another['token']!==$slot['token'],'replacement owner receives distinct token');
ck(!Gate::release($slot),'prior lease must not delete newer owner');
ck(Gate::release($another),'replacement lease released');
$blocked=Gate::acquire('etg_dfsb_dynamic_content_slots');
$GLOBALS['wpdb']->fail_delete=true;
ck(!Gate::release($blocked),'failed atomic release retains lock');
ck(is_wp_error(Gate::acquire('etg_dfsb_dynamic_content_slots')),
    'failed backend release must not admit next holder');
$GLOBALS['wpdb']->fail_delete=false;
ck(Gate::release($blocked),'release succeeds after backend recovery');
ck(in_array('options:alloptions',$GLOBALS['etg_gate_cache_deletes'],true),
    'option caches are invalidated after direct SQL');
ck(Gate::release($profile),'independent profile lease released');
$paths=array(
    dirname(__DIR__).'/includes/Admin/DynamicContentPage.php',
    dirname(__DIR__).'/includes/Admin/InventoryControlPage.php',
    dirname(__DIR__).'/includes/Admin/PublicationPage.php',
    dirname(__DIR__).'/includes/Admin/OperationalPage.php',
);
foreach($paths as $path){
    $source=file_get_contents($path);
    ck(is_string($source)&&strpos($source,'OptionMutationGate::acquire(')!==false,
        'ETG Admin writer does not use common option gate: '.basename($path));
}
echo "PASS ETG shared option lock, stale token, Admin contracts\n";
