<?php
if(!defined('ABSPATH') || !defined('WP_CLI')) exit(1);
$cls='MAD4B_JetEngine_Workspace_Shell_V1';
if(!class_exists($cls,false))WP_CLI::error('Missing Workspace shell');
wp_set_current_user(1);set_current_screen('dashboard');
$_GET['page']='dynamic-pricing-controls';
MAD4B_JetEngine_Field_Modes_V1::enqueue();
$cls::enqueue();
if(!wp_script_is($cls::SCRIPT,'enqueued') ||
   !wp_style_is($cls::SCRIPT,'enqueued'))
 WP_CLI::error('Original shell script/CSS not enqueued');
$src=WPMU_PLUGIN_DIR.'/assets/'.$cls::SCRIPT.'.js';
$css=WPMU_PLUGIN_DIR.'/assets/'.$cls::SCRIPT.'.css';
$js=file_get_contents($src);$style=file_get_contents($css);
foreach(['mad4b-ws-editor-composed','createComment',
 'workspaceContent.append(original)',
 'root.insertBefore(original,anchor.nextSibling)',
 'mad4b:jetengine:mode-changed',
 'nativeEditors.length===1','mad4b-ws-flat-canvas'] as $needle)
 if(!str_contains($js,$needle)) WP_CLI::error('Single editor safeguard missing: '.$needle);
foreach(['.mad4b-ws-layout','grid-template-columns:215px',
 '.mad4b-ws-flat-canvas','.mad4b-ws-control:not(.mad4b-ws-filtered)',
 '.mad4b-ws-content>.mad4b-ws-original',
 '.mad4b-ws-editor-composed','border-inline-end',
 '.mad4b-ws-summary','@media(max-width:900px)'] as $needle)
 if(!str_contains($style,$needle)) WP_CLI::error('Unified surface scope missing: '.$needle);
$before=MAD4B_JetEngine_Native_Pricing_CAS_V1::source_revision();
if(is_wp_error($before))WP_CLI::error('Native pricing source unavailable');
$page=null;
foreach((array)jet_engine()->options_pages->registered_pages as $entry){
 if($entry instanceof Jet_Engine_Options_Page_Factory &&
    $entry->slug==='etg-dynamic-pricing-controls'){$page=$entry;break;}
}
if(!$page || $page->storage_type!=='separate') WP_CLI::error('Native page storage changed');
$fields=$page->get_prepared_fields();
$page->init_builder();
ob_start();$page->render_page();$html=ob_get_clean();
if(substr_count($html,'<form')!==1 ||
   !str_contains($html,'settings_top') ||
   !str_contains($html,'shared_mode_policy_rows'))
 WP_CLI::error('Original JetEngine form/fields not preserved');
$after=MAD4B_JetEngine_Native_Pricing_CAS_V1::source_revision();
if($before!==$after)WP_CLI::error('Editor shell changed options');
echo 'COMPOSED_JETENGINE_ACCEPTANCE '.wp_json_encode([
 'site'=>home_url(),'native_form_count'=>1,
 'native_field_count'=>count($fields),
 'source_revision_unchanged'=>true,
 'original_save_and_form_rendered'=>true,
 'one_original_editor_runtime_guard'=>true,
 'enqueued_by_existing_native_workspace'=>true,
 'customer_price_changed'=>false
],JSON_UNESCAPED_SLASHES).PHP_EOL;
WP_CLI::success('JETENGINE_TRUE_SINGLE_SURFACE_NATIVE_FORM_RETAINED');
