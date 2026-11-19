<?php
/** Original sealed Source Lifecycle and Hub consumer; never authorize operations. */
error_reporting(E_ALL);
define('ABSPATH',__DIR__.'/');
class WP_Error {
    private $code;
    public function __construct($code,$message='',$data=null){$this->code=$code;}
    public function get_error_code(){return $this->code;}
}
function ensure_safe($state,$message){if(!$state){fwrite(STDERR,'FAIL: '.$message.PHP_EOL);exit(1);}}
$GLOBALS['recorded_hooks']=array();
$GLOBALS['attempted_writes']=0;
function add_action($hook,$callback,$priority=10,$args=1){
    $GLOBALS['recorded_hooks'][]=$hook;
}
function current_user_can(){return false;}
function update_option(){++$GLOBALS['attempted_writes'];throw new RuntimeException('Storage denied');}
function wp_remote_post(){++$GLOBALS['attempted_writes'];throw new RuntimeException('Network denied');}
$plugin=dirname(__DIR__);
$main=file_get_contents($plugin.'/mad4b-site-control-plane.php');
$files=array(
 'class-mad4b-scp-hub-jetengine-consumer-governance.php'=>'857ccb61407c483c90e6874c9e3c3ff7d7008ebe8ae4d552dc8c04cb5ac729be',
 'class-mad4b-scp-source-lifecycle.php'=>'ac95b12345f26c355bbdf45df20a3740a3878eeb2cb08a2365a1ccd7ec4183e4',
);
ensure_safe(is_string($main) && count($files)===2,'Current candidate Bootstrap and exact module list');
foreach($files as $name=>$sha){
 $file=$plugin.'/includes/'.$name;
 ensure_safe(is_file($file)&&hash_equals($sha,hash_file('sha256',$file)),'Original module SHA256 drift: '.$name);
 ensure_safe(false===strpos($main,$name),'Original source-only module must not load from main Bootstrap: '.$name);
}
require_once $plugin.'/includes/class-mad4b-scp-hub-jetengine-consumer-governance.php';
ensure_safe($GLOBALS['recorded_hooks']===array(),'No Hub hooks at source load');
$invalid=MAD4B_SCP_Hub_JetEngine_Consumer_Governance::validate_plan(array());
ensure_safe($invalid instanceof WP_Error&&$invalid->get_error_code()==='mad4b_hub_jetengine_contract_invalid',
 'Unsigned/invalid JetEngine consumer plan rejected');
$incomplete=MAD4B_SCP_Hub_JetEngine_Consumer_Governance::validate_plan(array(
 'contract'=>MAD4B_SCP_Hub_JetEngine_Consumer_Governance::CONTRACT
));
ensure_safe($incomplete instanceof WP_Error && $incomplete->get_error_code()==='mad4b_hub_jetengine_required_field_data_owner',
 'Contract label alone grants no Hub consumer admission');
// The original source lifecycle has a deliberate inline boot at load time.
// Only an isolated hook-spy is present: no actual WordPress is connected.
require_once $plugin.'/includes/class-mad4b-scp-source-lifecycle.php';
ensure_safe($GLOBALS['recorded_hooks']===array(
 'admin_post_mad4b_context_archive_source',
 'admin_post_mad4b_context_restore_source'),
 'Original inline Source Lifecycle hook registration is exactly bounded');
$denied=MAD4B_SCP_Source_Lifecycle::archive_plan('any-source');
ensure_safe($denied instanceof WP_Error && $denied->get_error_code()==='mad4b_source_archive_denied',
 'Unenrolled / unapproved source archive cannot run');
ensure_safe($GLOBALS['attempted_writes']===0,'No WordPress write or network call');
echo "PASS: 2 frozen originals, signed Hub denial, source archive denial, isolated inline boot".PHP_EOL;
