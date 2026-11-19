<?php
/**
 * MAD4B read-only WordPress native admin CSS/Components inventory.
 * wp eval-file .../qa/wp-native-ui-inventory.php --skip-themes --user=1
 * Only WP core asset files and public selectors, never settings/credentials.
 * Not an activation of a global admin theme.
 */
if(!defined('ABSPATH'))return;
$assetDefs=[
 'foundations'=>[
  'file'=>'wp-admin/css/common.min.css',
  'selectors'=>['.wrap','.notice','.card','.nav-tab-wrapper','.button-primary','.postbox']],
 'native_forms'=>[
  'file'=>'wp-admin/css/forms.min.css',
  'selectors'=>['.form-table','.regular-text','.large-text','.description','.wp-picker-container','input[type=checkbox]']],
 'tables'=>[
  'file'=>'wp-admin/css/list-tables.min.css',
  'selectors'=>['.wp-list-table','.tablenav','.row-actions','.column-title','.check-column']],
 'admin_navigation'=>[
  'file'=>'wp-admin/css/admin-menu.min.css',
  'selectors'=>['#adminmenu','#adminmenuback','#adminmenuwrap','.wp-submenu']],
 'media_modal'=>[
  'file'=>'wp-admin/css/media.min.css',
  'selectors'=>['.media-modal','.media-frame','.attachments-browser','.attachment','.media-toolbar']],
 'color_picker'=>[
  'file'=>'wp-admin/css/color-picker.min.css',
  'selectors'=>['.wp-color-picker','.wp-picker-container','.iris-picker']],
 'post_editor_legacy'=>[
  'file'=>'wp-admin/css/edit.min.css',
  'selectors'=>['#poststuff','#post-body','.postbox','.inside']],
 'navigation_menus'=>[
  'file'=>'wp-admin/css/nav-menus.min.css',
  'selectors'=>['#menu-management','.menu-item-handle','.menu-item-settings']],
 'widgets_legacy'=>[
  'file'=>'wp-admin/css/widgets.min.css',
  'selectors'=>['#widgets-right','.widget','.widget-inside']],
 'gutenberg_components'=>[
  'file'=>'wp-includes/css/dist/components/style.min.css',
  'selectors'=>['.components-button','.components-text-control__input','.components-panel','.components-modal__frame','.components-notice']],
 'gutenberg_block_editor'=>[
  'file'=>'wp-includes/css/dist/block-editor/style.min.css',
  'selectors'=>['.block-editor-block-list__layout','.block-editor-block-inspector','.block-editor-inserter']],
 'gutenberg_editor'=>[
  'file'=>'wp-includes/css/dist/editor/style.min.css',
  'selectors'=>['.editor-post-title','.editor-styles-wrapper','.interface-interface-skeleton']],
];
$families=[];
foreach($assetDefs as $name=>$def){
 $path=ABSPATH.$def['file'];
 $available=is_readable($path);
 $css=$available?file_get_contents($path):'';
 $present=[];
 foreach($def['selectors'] as $selector){
  // Audit exact public CSS string occurrences only, not a CSS parser.
  $present[$selector]=strpos($css,$selector)!==false;
 }
 $families[$name]=[
  'path'=>$def['file'],'available'=>$available,
  'bytes'=>$available?strlen($css):null,
  'sha256'=>$available?hash('sha256',$css):null,
  'selector_matches'=>$present,
  'matched'=>count(array_filter($present)),
  'requested'=>count($present),
  'rtl_asset'=>is_readable(preg_replace('/\.min\.css$/','-rtl.min.css',$path)),
 ];
}
global $wp_styles;
wp_styles();
$handles=['common','forms','buttons','admin-menu','list-tables','media-views',
 'wp-color-picker','edit','nav-menus','widgets','wp-components','wp-block-editor',
 'wp-editor','wp-edit-post','wp-edit-site','wp-notices','wp-base-styles'];
$registered=[];
foreach($handles as $handle){
 $obj=$wp_styles->registered[$handle]??null;
 $registered[$handle]=['registered'=>(bool)$obj,
   'deps'=>$obj?array_values((array)$obj->deps):[],
   'src'=>$obj?str_replace(ABSPATH,'',(string)$obj->src):null];
}
echo wp_json_encode([
 'wp_version'=>get_bloginfo('version'),
 'scope'=>'WordPress core CSS only, read-only. Selector match != operational compatibility.',
 'families'=>$families,
 'registered_handles'=>$registered,
 'stats'=>[
   'families'=>count($families),
   'core_files_present'=>count(array_filter($families,static fn($v)=>$v['available'])),
   'known_selectors'=>array_sum(array_column($families,'requested')),
   'matched_selectors'=>array_sum(array_column($families,'matched')),
   'known_handles'=>count($registered),
   'registered_handles'=>count(array_filter($registered,static fn($v)=>$v['registered'])),
 ]],JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE).PHP_EOL;
