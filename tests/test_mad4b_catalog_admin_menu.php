<?php
/** Isolated admin-menu registration test, without an authenticated live session. */
define('ABSPATH','/unit');
$GLOBALS['hooks']=[];$GLOBALS['menus']=[];
function add_action($name,$cb,$priority=10){$GLOBALS['hooks'][$name][$priority][]= $cb;}
function remove_action($name,$cb,$priority=10){
 if(!isset($GLOBALS['hooks'][$name][$priority]))return false;
 foreach($GLOBALS['hooks'][$name][$priority] as $i=>$existing)
  if($existing===$cb){unset($GLOBALS['hooks'][$name][$priority][$i]);return true;}
 return false;
}
function has_hook($name,$cb){foreach(($GLOBALS['hooks'][$name]??[]) as $entries)foreach($entries as $entry)if($entry===$cb)return true;return false;}
function remove_submenu_page($parent,$slug){$GLOBALS['menus'][]=['removed',$parent,$slug];return true;}
function add_options_page($title,$text,$cap,$slug,$fn){
 if($cap!=='manage_options')throw new RuntimeException('UNSAFE_CAPABILITY');
 add_action('settings_page_'.$slug,$fn);
 $GLOBALS['menus'][]=['added',$slug];return 'settings_page_'.$slug;
}
function mcfm_admin_page(){}
add_action('settings_page_mcfm_feeds_v2','mcfm_admin_page');
require_once __DIR__.'/../wp-content/plugins/mad4b-distribution-connectors/includes/class-mad4b-catalog-studio-admin.php';
$cls='MAD4B_Catalog_Studio_Admin_V1';
if(!has_hook('admin_menu',[$cls,'menus']))throw new RuntimeException('MENU_PRIORITY_MISSING');
$cls::menus();
if(has_hook('settings_page_mcfm_feeds_v2','mcfm_admin_page'))throw new RuntimeException('DOUBLE_RENDER_REGRESSION');
if(!has_hook('settings_page_mcfm_feeds_v2',[$cls,'render']))throw new RuntimeException('STUDIO_UI_NOT_REGISTERED');
if(!has_hook('settings_page_mad4b-catalog-advanced','mcfm_admin_page'))throw new RuntimeException('LEGACY_EDITOR_MISSING');
echo json_encode(['suite'=>'mad4b.catalog-studio.admin-menu','checks_passed'=>4,
    'old_callback_removed'=>true,'new_callback_added'=>true,'advanced_editor_preserved'=>true,
    'executed_in_isolated_authority_stub'=>true,'live_user_impersonation'=>false]).PHP_EOL;
