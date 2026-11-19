<?php
/** Original plugin bootstrap classifiers. No plugin activation or WordPress hooks. */
define('ABSPATH',__DIR__.'/');
class WP_Error {function __construct($code,$msg='',$data=[]){}}
function is_admin(){return $GLOBALS['on_admin'];}
function sanitize_key($v){return preg_replace('/[^a-z0-9_-]/','',strtolower((string)$v));}
function wp_unslash($v){return stripslashes($v);}
function plugin_test($yes,$why){if(!$yes){fwrite(STDERR,'FAIL: '.$why.PHP_EOL);exit(1);}$GLOBALS['checks']=($GLOBALS['checks']??0)+1;}
require dirname(__DIR__).'/wp-content/plugins/mad4b-site-control-plane/includes/class-mad4b-scp-plugin.php';
$c='MAD4B_SCP_Plugin';
$life=new ReflectionMethod($c,'request_is_wordpress_plugin_lifecycle');
$schema=new ReflectionMethod($c,'request_requires_schema_reconciliation');
$skill=new ReflectionMethod($c,'request_requires_skill_reconciliation');
if(PHP_VERSION_ID<80100){$life->setAccessible(true);$schema->setAccessible(true);$skill->setAccessible(true);}
$GLOBALS['on_admin']=false;
plugin_test($life->invoke(null)===false &&$schema->invoke(null)===false &&$skill->invoke(null)===false,
 'Public WordPress request is not a plugin lifecycle or automatic schema/skill reconciliation');
$GLOBALS['on_admin']=true;
foreach(['index.php','post.php','admin.php','edit.php'] as $page){
 $GLOBALS['pagenow']=$page;$_GET['page']='mad4b-control-plane-connection';$_REQUEST=[];
 plugin_test($life->invoke(null)===false
  &&$schema->invoke(null)===false &&$skill->invoke(null)===false,
  'Passive first-party admin page never repairs Schema or reconciles provider skills');
}
foreach(['update.php','update-core.php','plugin-install.php','plugins.php'] as $page){
 $GLOBALS['pagenow']=$page;$_REQUEST=[];
 plugin_test($life->invoke(null)===true
  &&$schema->invoke(null)===false &&$skill->invoke(null)===false,
  'Native plugin lifecycle screens are classified but not delegated background reconciliation');
}
$GLOBALS['pagenow']='admin.php';
foreach(['upload-plugin','install-plugin','update-plugin','activate','deactivate','delete-selected'] as $operation){
 $_REQUEST=['action'=>$operation];
 plugin_test($life->invoke(null)===true
  &&$schema->invoke(null)===false &&$skill->invoke(null)===false,
  'Explicit plugin operation is recognized without mutating unrelated runtime');
}
$_REQUEST=['action'=>new WP_Error('unsafe_input')];
plugin_test($life->invoke(null)===false
 &&$schema->invoke(null)===false &&$skill->invoke(null)===false,
 'Error-shaped user request is never classified as a valid plugin lifecycle');
echo 'PASS: PLUGIN_BOOTSTRAP_ORIGINAL '.($GLOBALS['checks']??0).' native no-op lifecycle classification cases'.PHP_EOL;
