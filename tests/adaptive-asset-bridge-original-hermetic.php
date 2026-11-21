<?php
/** Original MAD4B adaptive asset shim, no real WordPress, no site writing. */
define('ABSPATH',__DIR__.'/');
define('WP_PLUGIN_DIR',sys_get_temp_dir().'/mad4b-adaptive-asset-test-'.getmypid());
$GLOBALS['active']=['mad4b-adaptive-asset-intelligence/mad4b-adaptive-asset-intelligence.php'];
function get_option($name,$fallback=[]){return $name==='active_plugins'?$GLOBALS['active']:$fallback;}
function asset_check($yes,$why){
 if(!$yes){fwrite(STDERR,'FAIL: '.$why.PHP_EOL);exit(1);}
 $GLOBALS['checks']=($GLOBALS['checks']??0)+1;
}
$directory=WP_PLUGIN_DIR.'/mad4b-adaptive-asset-intelligence/includes';
mkdir($directory,0700,true);
$source=dirname(__DIR__).'/wp-content/plugins/mad4b-site-control-plane/includes/class-mad4b-scp-adaptive-asset-intelligence.php';
$canonical=$directory.'/class-mad4b-scp-adaptive-asset-intelligence.php';
$outside=sys_get_temp_dir().'/mad4b-untrusted-asset-code-'.getmypid().'.php';
file_put_contents($outside,'<?php $GLOBALS["untrusted_include_executed"]=true;');
symlink($outside,$canonical);
include $source;
asset_check(!isset($GLOBALS['untrusted_include_executed']),
 'A WordPress active plugin option cannot cause canonical shim to require a symlink outside plugin root');
unlink($canonical);unlink($outside);
$GLOBALS['active']=[];
file_put_contents($canonical,
 '<?php final class MAD4B_SCP_Adaptive_Asset_Intelligence { const ORIGINAL_OWNER=true; }');
include $source;
asset_check(!class_exists('MAD4B_SCP_Adaptive_Asset_Intelligence',false),
 'Inactive first-party plugin cannot be loaded by legacy shim');
$GLOBALS['active']=['mad4b-adaptive-asset-intelligence/mad4b-adaptive-asset-intelligence.php'];
include $source;
asset_check(class_exists('MAD4B_SCP_Adaptive_Asset_Intelligence',false)
 &&MAD4B_SCP_Adaptive_Asset_Intelligence::ORIGINAL_OWNER===true,
 'Only installed active canonical original class is reused, not reimplemented');
include $source;
asset_check(class_exists('MAD4B_SCP_Adaptive_Asset_Intelligence',false),
 'Original plugin owner is not double included');
unlink($canonical);rmdir($directory);rmdir(dirname($directory));rmdir(WP_PLUGIN_DIR);
echo 'PASS: ADAPTIVE_ASSET_BRIDGE '.($GLOBALS['checks']??0).
 ' original owner, symlink and active-plugin assertions'.PHP_EOL;
