<?php
/** Original Server facade and transports; no WordPress, network or native job. */
define('ABSPATH',__DIR__.'/');
define('WP_CLI',true);
function add_action($name,$callback,$priority=10,$accepted=1){return true;}
function add_filter($name,$callback,$priority=10,$accepted=1){return true;}
function is_wp_error($value){return $value instanceof WP_Error;}
function sanitize_key($value){return strtolower(preg_replace('/[^a-z0-9_-]/i','',(string)$value));}
function wp_has_ability($name){return isset($GLOBALS['registered_abilities'][$name]);}
function wp_get_ability($name){return wp_has_ability($name)?(object)array('name'=>$name):false;}
function home_url($path='/'){return 'https://tours.dev.mad4b.com/';}
function untrailingslashit($path){return rtrim($path,'/');}
function wp_parse_url($url,$component){return parse_url($url,$component);}
function get_current_user_id(){return 0;}
function get_userdata($id){return $id===128?(object)array('ID'=>128):false;}
function user_can($user,$cap){return $user && $cap==='manage_options' && $user->ID===128;}
class WP_Error {
    private $code;
    function __construct($code,$message='',$data=array()){$this->code=$code;}
    function get_error_code(){return $this->code;}
}
class MAD4B_SCP_Capability_Transport_Routing {
    const VPS='vps';
    public static $mode='registered_operator';
    public static function mode_for_transport($transport){return self::$mode;}
}
class MAD4B_Hub_Universal_VPS_V1 {
    public static $scoped=true;
    public static function scoped(){return self::$scoped;}
}
class MAD4B_SCP_CLI_Execution_Context {
    public static $facts=array(
        'read_context_verified'=>true,'host_site_binding_verified'=>true,
        'blockers'=>array(),'environment'=>'staging',
        'site_uuid'=>'11111111-1111-4111-8111-111111111111',
        'canonical_origin'=>'https://tours.dev.mad4b.com'
    );
    public static function resolve($operation,$write=false){return self::$facts;}
}
class MAD4B_SCP_Site_Profile {
    public static function site_uuid(){return '11111111-1111-4111-8111-111111111111';}
    public static function origin_enrolled(){return true;}
    public static function site_urls_match_enrollment(){return true;}
}
class MAD4B_SCP_Execution_Authority {
    public static $actor=array(
        'authenticated'=>true,'registry_bound'=>true,'transport'=>'host_cli',
        'subject_type'=>'host_operator','environment'=>'staging',
        'subject_fingerprint'=>'ffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffff',
        'site_uuid'=>'11111111-1111-4111-8111-111111111111','wp_user_id'=>128
    );
    public static function resolve($purpose){return self::$actor;}
}
$GLOBALS['registered_abilities']=array_fill_keys(array(
    'mad4b/hub-universal-vps-status',
    'mad4b/hub-universal-vps-discover',
    'mad4b/hub-universal-vps-plan',
    'mad4b/hub-universal-vps-enqueue',
),true);
require dirname(__DIR__).'/wp-content/plugins/mad4b-site-control-plane/includes/class-mad4b-scp-servers.php';
function server_assert($condition,$reason){if(!$condition){fwrite(STDERR,"FAIL: ".$reason."\n");exit(1);}}
$tools=MAD4B_SCP_Servers::core_tools('mad4b-vps-local');
server_assert(count($tools)===4
  && in_array('mad4b/hub-universal-vps-enqueue',$tools,true),
    'Original local server alone has bounded registered enqueue');
foreach(array('mad4b-read','mad4b-chatgpt','mad4b-enrollment','mad4b-write') as $remote){
    server_assert(!in_array('mad4b/hub-universal-vps-enqueue',
        MAD4B_SCP_Servers::core_tools($remote),true),'No remote enqueue leak: '.$remote);
}
$_SERVER['argv']=array('wp','plugin','list');
server_assert(is_wp_error(MAD4B_SCP_Servers::can_vps_local_transport()),
  'Normal WP CLI without explicit MCP server target is denied');
server_assert(MAD4B_SCP_Servers::can_vps_local_write_transport()===false,
  'Bare root CLI cannot issue a registered local operator write');
$_SERVER['argv']=array('wp','mcp-adapter','serve','--server=mad4b-vps-local');
$root=function_exists('posix_geteuid') && posix_geteuid()===0;
if($root) {
    server_assert(MAD4B_SCP_Servers::can_vps_local_transport()===true,
       'Original enrolled Host native CLI scoped route may open transport');
    server_assert(MAD4B_SCP_Servers::provider_for_ability('mad4b-vps-local',
       'mad4b/hub-universal-vps-enqueue')==='core',
       'Only certified native local catalog abilities may resolve on local route');
    $context=MAD4B_SCP_Servers::vps_local_identity_context(array('authenticated'=>false));
    server_assert(!empty($context['authenticated'])
        && $context['subject_type']==='host_operator'
        && $context['origin']==='vps_local_stdio'
        && $context['token_scopes']===array(),
        'Existing Host actor is reused without creating an OAuth bearer');
} else {
    server_assert(is_wp_error(MAD4B_SCP_Servers::can_vps_local_transport()),
       'Non-root CLI never inherits Host transport');
}
$original=MAD4B_SCP_Execution_Authority::$actor;
MAD4B_SCP_Execution_Authority::$actor['subject_fingerprint']='bad';
server_assert(MAD4B_SCP_Servers::can_vps_local_write_transport()===false,
   'Malformed registry subject is denied');
MAD4B_SCP_Execution_Authority::$actor=$original;
MAD4B_SCP_Execution_Authority::$actor['environment']='production';
server_assert(MAD4B_SCP_Servers::can_vps_local_write_transport()===false,
   'Foreign environment Host actor is denied');
MAD4B_SCP_Execution_Authority::$actor=$original;
MAD4B_SCP_Execution_Authority::$actor['site_uuid']='22222222-2222-4222-8222-222222222222';
server_assert(MAD4B_SCP_Servers::can_vps_local_write_transport()===false,
   'Foreign company site Host actor is denied');
MAD4B_SCP_Execution_Authority::$actor=$original;
MAD4B_SCP_Capability_Transport_Routing::$mode='broker';
server_assert(is_wp_error(MAD4B_SCP_Servers::can_vps_local_transport()),
   'No implicit fallback from Broker/remote transport to Host operator');
MAD4B_SCP_Capability_Transport_Routing::$mode='registered_operator';
MAD4B_Hub_Universal_VPS_V1::$scoped=false;
server_assert(is_wp_error(MAD4B_SCP_Servers::can_vps_local_transport()),
   'Unscoped VPS operation cannot use site host');
echo 'PASS: SERVERS_NATIVE_TRANSPORT 14 original-source admission and isolation cases, root-positive='
    .($root?'yes':'non-root-denied')."\n";
