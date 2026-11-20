<?php
if(!defined('ABSPATH')||!defined('WP_CLI'))exit(1);
wp_set_current_user(1);
$cls='MAD4B_Pricing_Rules_Visibility_V1';
$_GET['page']='dynamic-pricing-controls';
wp_register_script('mad4b-jetengine-functional-modes','https://example.invalid/functional-modes.js',[],null,true);
$cls::assets();
global $wp_scripts;
$script=$wp_scripts->registered['mad4b-pricing-rules-visibility-v1']??null;
if(!$script||!in_array('mad4b-jetengine-functional-modes',$script->deps,true))
 WP_CLI::error('Readback script missing dependent native Pricing Controls workspace');
if(!has_action('wp_ajax_'.$cls::AJAX))WP_CLI::error('Readback AJAX not registered');
echo "PASS readback dependency and AJAX registered\n";
wp_set_current_user(0);
$before=count((array)wp_scripts()->queue);
$cls::assets();
if(count((array)wp_scripts()->queue)!==$before)
 WP_CLI::error('Guest mutated enqueue state');
echo "PASS readback UI is capability-scoped\n";
wp_set_current_user(1);
$_GET['page']='another-page';
$cls::assets();
if(count((array)wp_scripts()->queue)!==$before)
 WP_CLI::error('Readback leaked into another page');
echo "PASS readback only on Pricing Controls\n";
WP_CLI::success('RULE_VISIBILITY_ASSET_SCOPE_VERIFIED');
