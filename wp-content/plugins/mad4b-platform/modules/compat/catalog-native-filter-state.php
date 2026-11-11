<?php
/** Restore approved native archive controls from their URL, in any admitted runtime. */
defined('ABSPATH')||exit;
if(!defined('MAD4B_COMMERCE_RUNTIME_ENABLED')||!MAD4B_COMMERCE_RUNTIME_ENABLED)return;
add_action('wp_enqueue_scripts',static function(){
    if(is_admin()||!function_exists('shared_presentation_catalog_url_context_v1'))return;
    $context=shared_presentation_catalog_url_context_v1((string)($_SERVER['REQUEST_URI']??''));
    if(!is_array($context)||empty($context['facets']))return;
    $expected=[];
    foreach($context['facets']as$taxonomy=>$slugs)foreach((array)$slugs as$slug)$expected[]=['taxonomy'=>$taxonomy,'slug'=>$slug];
    $contract=shared_presentation_catalog_contract_v1();
    $handle='catalog-native-filter-state';$file=WPMU_PLUGIN_DIR.'/assets/catalog-native-filter-state.js';
    wp_enqueue_script($handle,content_url('/mu-plugins/assets/catalog-native-filter-state.js'),['jquery'],(string)filemtime($file),true);
    wp_add_inline_script($handle,'window.CatalogNativeFilterState='.wp_json_encode(['provider'=>$contract['provider'],'expected'=>$expected]).';','before');
},90);
// Diagnostic preview panels must not become storefront content when moving environments.
add_action('wp_head',static function(){if(!isset($_GET['dfsb_qa']))echo '<style id="catalog-hide-diagnostic-preview">.dfsb-qa-only{display:none!important}</style>';},10);
