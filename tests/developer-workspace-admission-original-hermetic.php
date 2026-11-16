<?php
/** Actual developer workspace native source/environment gates; no build, grant, PHP executor. */
define('ABSPATH',__DIR__.'/');
class WP_Error{private $c;function __construct($c,$m='',$d=[]){$this->c=$c;}function get_error_code(){return $this->c;}}
function add_action($name,$callback,$priority=10){}
function is_wp_error($v){return $v instanceof WP_Error;}
class MAD4B_SCP_Environment{static function effective(){return 'staging';}}
class MAD4B_SCP_Site_Profile{
 static $ready=true,$managed=true,$write=true;
 static function status(){return ['authority_ready'=>self::$ready];}
 static function managed_runtime_enabled(){return self::$managed;}
 static function write_enabled(){return self::$write;}
}
class MAD4B_SCP_Developer_Runtime{
 static $flag=true,$direct=true,$kill=false;
 static function developer_flag_enabled(){return self::$flag;}
 static function direct_execution_enabled(){return self::$direct;}
 static function kill_switch_enabled(){return self::$kill;}
}
function dev_check($ok,$why){if(!$ok){fwrite(STDERR,'FAIL '.$why.PHP_EOL);exit(1);}$GLOBALS['n']=($GLOBALS['n']??0)+1;}
require dirname(__DIR__).'/wp-content/plugins/mad4b-site-control-plane/includes/class-mad4b-scp-developer-workspace.php';
$c='MAD4B_SCP_Developer_Workspace';
$gate=new ReflectionMethod($c,'mutation_gate');if(PHP_VERSION_ID<80100)$gate->setAccessible(true);
foreach(['ready','managed','write'] as $field){
 foreach([new WP_Error('site_unverified'),1,'true',null,false] as $bad){
  MAD4B_SCP_Site_Profile::$$field=$bad;
  $r=$gate->invoke(null,[]);
  dev_check(is_wp_error($r)&&$r->get_error_code()==='mad4b_developer_workspace_site_authority_required',
   'Original Developer workspace requires strict managed and write-enabled site authority');
 }
 MAD4B_SCP_Site_Profile::$$field=true;
}
foreach(['flag','direct','kill'] as $field){
 foreach(($field==='kill'? [new WP_Error('runtime_unverified'),1,'true',null] : [new WP_Error('runtime_unverified'),1,'true',null,false]) as $bad){
  MAD4B_SCP_Developer_Runtime::$$field=$bad;
  $r=$gate->invoke(null,[]);
  dev_check(is_wp_error($r)&&$r->get_error_code()==='mad4b_developer_workspace_runtime_not_ready',
   'Original Developer workspace requires certified runtime mode and disabled kill-switch');
 }
 MAD4B_SCP_Developer_Runtime::$$field=$field==='kill'?false:true;
}
$r=$gate->invoke(null,['expected_source_commit_sha'=>'not-released']);
dev_check(is_wp_error($r)&&$r->get_error_code()==='mad4b_developer_workspace_source_binding_mismatch',
 'Original source SHA verification blocks untrusted package before executing anything');
echo 'PASS: DEVELOPER_WORKSPACE_ADMISSION_ORIGINAL '.($GLOBALS['n']??0).
 ' original site and developer runtime source gate checks'.PHP_EOL;
