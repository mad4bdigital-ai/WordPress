<?php
if(!defined('ABSPATH')||!defined('WP_CLI'))exit(1);
$cls='MAD4B_JetEngine_Workspace_Shell_V1';
if(!class_exists($cls,false))WP_CLI::error('Missing workspace shell');
wp_set_current_user(1);
set_current_screen('dashboard');
$_GET['page']='dynamic-pricing-controls';
MAD4B_JetEngine_Field_Modes_V1::enqueue();
$cls::enqueue();
$handle=$cls::SCRIPT;
$js=wp_scripts()->registered[$handle]??null;
$css=wp_styles()->registered[$handle]??null;
if(!$js||!$css||!wp_script_is($handle,'enqueued')||
 !wp_style_is($handle,'enqueued') ||
 !in_array('mad4b-jetengine-functional-modes',$js->deps,true)||
 !in_array('mad4b-jetengine-functional-modes',$css->deps,true))
 WP_CLI::error('Unified shell not deferred to original native Workspace');
$src=WPMU_PLUGIN_DIR.'/assets/'.$handle.'.css';
$code=file_get_contents($src);
foreach([
 '.mad4b-ws-content','>.mad4b-ws-original',
 '.cx-section__info','.mad4b-stay-panel__accordion','.mad4b-ts-accordion',
 '.mad4b-ws-dimension-group','.mad4b-ws-provider-panel',
 '.mad4b-ws-nav-group-label','.mad4b-guide-cost-tab-active',
 '#mad4b-guide-taxonomy-cost-panel','@media(max-width:900px)'
] as $selector)if(!str_contains($code,$selector))
 WP_CLI::error('Missing shared surface styling: '.$selector);
$before= MAD4B_JetEngine_Native_Pricing_CAS_V1::source_revision();
wp_set_current_user(0);
$queued=count(wp_scripts()->queue);
$cls::enqueue();
if($queued!==count(wp_scripts()->queue))
 WP_CLI::error('Guest triggered private shell script');
$after=MAD4B_JetEngine_Native_Pricing_CAS_V1::source_revision();
if(is_wp_error($before)||$before!==$after)
 WP_CLI::error('Styling initialized a native settings writer');
echo 'WORKSPACE_SHELL_UI '.wp_json_encode([
 'site'=>home_url(),'css_and_js_enqueued'=>true,
 'native_workspace_dependencies'=>true,'guest_blocked'=>true,
 'original_jetengine_save_unchanged'=>true,'visual_scope_only'=>true,
 'source_option_unchanged'=>true
 ],JSON_UNESCAPED_SLASHES).PHP_EOL;
WP_CLI::success('UNIFIED_JETENGINE_SHELL_UI_SOURCE_PASS');
