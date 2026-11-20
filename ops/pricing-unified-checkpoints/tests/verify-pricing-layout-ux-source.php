<?php
if(!defined('ABSPATH') || !defined('WP_CLI')) exit(1);
wp_set_current_user(1);
set_current_screen('dashboard');
$_GET['page']='dynamic-pricing-controls';
$root=WPMU_PLUGIN_DIR.'/assets/';
$plugin=WP_PLUGIN_DIR.'/mad4b-jetengine/modules/functional-modes/assets/functional-modes.js';
$files=[
 'shared_design'=>$root.'mad4b-pricing-interface-v2.css',
 'shell'=>$root.'mad4b-jetengine-workspace-shell-v1.js',
 'rule_registry'=>$root.'mad4b-pricing-rules-visibility-v1.js',
 'dimensions'=>$plugin
];
foreach($files as $name=>$file)if(!is_readable($file)) WP_CLI::error('Missing UX source '.$name);
$css=file_get_contents($files['shared_design']);
$shell=file_get_contents($files['shell']);
$rules=file_get_contents($files['rule_registry']);
$dim=file_get_contents($files['dimensions']);
foreach([
 'cx-control.mad4b-ws-control.cx-control-repeater',
 'grid-template-columns:215px minmax(0,1fr)',
 'mad4b-ws-dimension-choice-help',
 'mad4b-ws-find-settings',
 'mad4b-pricing-composed[data-mad4b-pricing-pane]'
] as $token)
 if(!str_contains($css,$token))WP_CLI::error('Pricing layout marker missing: '.$token);
if(!str_contains($shell,"find.append(fieldSearch)") ||
   !str_contains($shell,'const fieldSearch=cfg.pricing?'))
 WP_CLI::error('Original company search must be moved intact');
if(!str_contains($dim,'guidance.append(scopePanel,helper,core)') ||
   !str_contains($dim,'choiceHelp.append(caption)') ||
   !str_contains($dim,'dimPanel.append(toolbar,actionFeedback,container,pending,'))
 WP_CLI::error('Repeater-first progressive descriptions contract missing');
if(substr_count($rules,"const table=make('table','widefat striped mad4b-pricing-rule-table')")!==1 ||
   !str_contains($rules,"'.mad4b-ws-nav-btn[data-section=\"policies\"]'") ||
   !str_contains($rules,"policyPanel.append(brief,detail)") ||
   !str_contains($rules,"const detail=make('details','mad4b-pricing-policy-registry-details')") ||
   str_contains($rules,'details.append(wrapper)'))
 WP_CLI::error('Unified policy registry duplicated in overview');
$before=MAD4B_JetEngine_Native_Pricing_CAS_V1::source_revision();
if(is_wp_error($before))WP_CLI::error('Native source revision unavailable');
$factory=null;
foreach((array)jet_engine()->options_pages->registered_pages as $page)
 if($page instanceof Jet_Engine_Options_Page_Factory &&
    $page->slug==='etg-dynamic-pricing-controls'){$factory=$page;break;}
if(!$factory) WP_CLI::error('Native JetEngine source missing');
$factory->init_builder();
ob_start();$factory->render_page();$html=ob_get_clean();
if(substr_count($html,'<form')!==1 ||
 !str_contains($html,'shared_mode_policy_rows') ||
 !str_contains($html,'shared_package_pricing_rows') ||
 !str_contains($html,'cx-ui-repeater-item-control'))
 WP_CLI::error('Native Repeater or form changed');
$after=MAD4B_JetEngine_Native_Pricing_CAS_V1::source_revision();
if($before!==$after) WP_CLI::error('Unexpected native settings revision change');
echo 'CP27_PRICING_LAYOUT_VERIFIED '.wp_json_encode([
 'site'=>home_url(),
 'native_fields'=>count($factory->get_prepared_fields()),
 'native_form_count'=>1,
 'read_only'=>true,
 'single_registry'=>true,
 'full_width_repeater_shell'=>true,
 'progressive_description_sections'=>true,
 'company_and_product_grid_shared'=>true,
 'original_revision_unchanged'=>true
],JSON_UNESCAPED_SLASHES).PHP_EOL;
WP_CLI::success('PRICING_UI_CP27_SOURCE_AND_FORM_PASS');
