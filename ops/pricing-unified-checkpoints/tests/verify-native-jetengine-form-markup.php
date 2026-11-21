<?php
if(!defined('ABSPATH')||!defined('WP_CLI'))exit(1);
wp_set_current_user(1);
set_current_screen('dashboard');
$factory=null;
foreach((array)jet_engine()->options_pages->registered_pages as $page)
 if($page instanceof Jet_Engine_Options_Page_Factory &&
    $page->slug==='etg-dynamic-pricing-controls'){$factory=$page;break;}
if(!$factory || $factory->storage_type!=='separate')
 WP_CLI::error('Actual JetEngine pricing Options Page missing');
$before=MAD4B_JetEngine_Native_Pricing_CAS_V1::source_revision();
try{
 $factory->init_builder();
 ob_start();$factory->render_page();$html=ob_get_clean();
}catch(Throwable $e){
 if(ob_get_level()>0)ob_end_clean();
 WP_CLI::error('Native JetEngine form rendering failed: '.$e->getMessage());
}
$need=[
 'one_original_form'=>substr_count($html,'<form')===1,
 'native_save_button'=>(bool)preg_match('/<button[^>]*type="submit"/',$html),
 'settings_top'=>str_contains($html,'settings_top'),
 'pricing_repeater'=>str_contains($html,'shared_mode_policy_rows[item-0][mode]'),
 'pricing_package_selection'=>str_contains($html,'shared_package_pricing_rows[item-0][package_term_id]') ||
   str_contains($html,'name="shared_package_pricing_rows"'),
 'original_input_name'=>str_contains($html,'shared_package_pricing_rows'),
 'native_repeater_row'=>str_contains($html,'cx-ui-repeater-item-control'),
 'original_content_box'=>str_contains($html,'cheryr-ui-repeater-content-box'),
];
preg_match_all('/name=["\x27]([^"\x27]*shared_package_pricing_rows[^"\x27]*)["\x27]/',$html,$allPackageNames);
WP_CLI::log('PACKAGE_FORM_NAMES '.wp_json_encode(array_slice((array)($allPackageNames[1]??[]),0,18)));
if(in_array(false,$need,true))
 WP_CLI::error('Native UI markup mismatch '.wp_json_encode($need));
$after=MAD4B_JetEngine_Native_Pricing_CAS_V1::source_revision();
if(is_wp_error($before)||$before!==$after)
 WP_CLI::error('Rendered HTML mutated original JetEngine options');
echo 'JETENGINE_REAL_FORM '.wp_json_encode(['site'=>home_url(),'bytes'=>strlen($html),
 'native_fields'=>count($factory->get_prepared_fields()),
 'markers'=>$need,'reads_only'=>true],JSON_UNESCAPED_UNICODE).PHP_EOL;
WP_CLI::success('REAL_JETENGINE_FORM_NATIVE_CAS_AND_PICKER_MARKUP_ACCEPTED');
