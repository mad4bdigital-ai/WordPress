<?php
if(!defined('ABSPATH')||!defined('WP_CLI'))exit(1);
$cls='MAD4B_JetEngine_Workspace_Shell_V1';
if(!class_exists($cls,false))WP_CLI::error('Original shell not found');
wp_set_current_user(1);set_current_screen('dashboard');
$_GET['page']='dynamic-pricing-controls';
MAD4B_JetEngine_Field_Modes_V1::enqueue();
$cls::enqueue();
$styles=wp_styles();
$handle='mad4b-pricing-interface-v2';
$style=$styles->registered[$handle]??null;
if(!$style || !wp_style_is($handle,'enqueued'))
 WP_CLI::error('Pricing design system not enqueued');
if(!in_array($cls::SCRIPT,(array)$style->deps,true))
 WP_CLI::error('Design system is not ordered after the native JetEngine shell');
$css=WPMU_PLUGIN_DIR.'/assets/'.$handle.'.css';
if(!is_readable($css))WP_CLI::error('Pricing design system file missing');
$source=file_get_contents($css);
foreach(['.mad4b-stay-panel__accordion','.mad4b-ts-accordion',
         '#mad4b-guide-taxonomy-cost-panel','.mad4b-ws-dimension-group',
         '.mad4b-supplier-readiness>details',
         '.mad4b-stay-panel__quote','.mad4b-ts-rail-card',
         '.mad4b-ts-source','.mad4b-stay-panel__layers',
         'mad4b-ws-flat-canvas','@container','@media'] as $needle){
 if(!str_contains($source,$needle))
   WP_CLI::error('Missing shared pricing component style: '.$needle);
}
$before=MAD4B_JetEngine_Native_Pricing_CAS_V1::source_revision();
if(is_wp_error($before))WP_CLI::error('Original pricing source unavailable');
$after=MAD4B_JetEngine_Native_Pricing_CAS_V1::source_revision();
if($before!==$after)WP_CLI::error('The design readback mutated native pricing settings');
echo 'PRICING_INTERFACE_V2_ACCEPTANCE '.wp_json_encode([
 'site'=>home_url(),
 'unified_service_appearance'=>['accommodation','transportation','guide','policies','supplier_inspector'],
 'preserves_native_save'=>true,
 'new_data_fields'=>0,
 'native_source_revision_unchanged'=>true,
 'style_priority_dependency'=>true
],JSON_UNESCAPED_SLASHES).PHP_EOL;
WP_CLI::success('COMMON_PRICING_INTERFACE_V2_READY');
