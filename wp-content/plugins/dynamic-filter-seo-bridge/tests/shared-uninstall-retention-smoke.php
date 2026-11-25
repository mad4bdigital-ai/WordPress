<?php
// Isolated filesystem + option fixtures: never loads WordPress or its data.
$root = sys_get_temp_dir() . '/dfsb-retention-' . bin2hex(random_bytes(8));
define('WP_PLUGIN_DIR', $root);
$GLOBALS['settings'] = array('schema_version'=>1,'migration_state'=>'current','data_retention'=>'delete_on_uninstall');
$GLOBALS['effects'] = array();
$GLOBALS['multisite'] = false;
function is_multisite(){return $GLOBALS['multisite'];}
function get_option($name,$default=false){return $name==='etg_dfsb_settings'?$GLOBALS['settings']:$default;}
function delete_option($name){$GLOBALS['effects'][]=$name;}
function delete_transient($name){$GLOBALS['effects'][]=$name;}
require dirname(__DIR__).'/includes/Lifecycle/UninstallPolicy.php';
use DynamicFilterSEOBridge\Lifecycle\UninstallPolicy;
function check($value,$label){if(!$value){throw new RuntimeException($label);}}
function preserve($reason){
    check(!UninstallPolicy::shouldDeleteData(),'must preserve: '.$reason);
    $result=UninstallPolicy::cleanup();
    check($result['deleted']===false && $result['reason']===$reason,'reason: '.$reason);
    check($GLOBALS['effects']===array(),'no cache or option deletions: '.$reason);
}
try{
    preserve('plugin_inventory_unavailable');
    mkdir($root,0700);
    $legacy=$root.'/etg-dynamic-filter-seo-bridge';
    mkdir($legacy);
    preserve('shared_legacy_storage_preserve');
    file_put_contents($legacy.'/etg-dynamic-filter-seo-bridge.php','<?php // fixture');
    preserve('shared_legacy_storage_preserve');
    unlink($legacy.'/etg-dynamic-filter-seo-bridge.php');
    rmdir($legacy);
    if(function_exists('symlink') && @symlink($root.'/missing-target',$legacy)){
        preserve('shared_legacy_storage_preserve');
        unlink($legacy);
    }
    $GLOBALS['multisite']=true;
    preserve('multisite_preserve_policy');
    $GLOBALS['multisite']=false;
    $GLOBALS['settings']['data_retention']='preserve';
    preserve('retention_preserve');
    $GLOBALS['settings']['data_retention']='delete_on_uninstall';
    check(UninstallPolicy::shouldDeleteData(),'last package with explicit current-schema deletion intent');
    $result=UninstallPolicy::cleanup();
    check($result['deleted']===true && in_array('etg_dfsb_settings',$GLOBALS['effects'],true) && in_array('dfsb_settings',$GLOBALS['effects'],true),'existing final-package cleanup contract');
    echo "PASS: shared storage preserved for legacy package, incomplete copy, missing inventory and multisite; final package opt-in unchanged\n";
}finally{
    if(isset($legacy) && is_link($legacy)){unlink($legacy);}
    if(isset($legacy) && is_file($legacy.'/etg-dynamic-filter-seo-bridge.php')){unlink($legacy.'/etg-dynamic-filter-seo-bridge.php');}
    if(isset($legacy) && is_dir($legacy)){rmdir($legacy);}
    if(is_dir($root)){rmdir($root);}
}
