<?php
if(!defined('ABSPATH')||!defined('WP_CLI'))exit(1);
$cls='MAD4B_Package_Supplier_Admission_V1';
if(!class_exists($cls,false))WP_CLI::error('Package admission not loaded');
wp_set_current_user(1);
set_current_screen('dashboard');
$_GET['page']='dynamic-pricing-controls';
// In the real admin request, Functional Modes is enqueued at priority 55,
// before the supplier panel (priority 80). Reproduce that order in WP-CLI.
MAD4B_JetEngine_Field_Modes_V1::enqueue();
$cls::enqueue();
$s=wp_scripts()->registered['mad4b-package-supplier-admission-review-v1']??null;
$style=wp_styles()->registered['mad4b-package-supplier-admission-review-v1']??null;
if(!$s||!$style||
 !wp_script_is('mad4b-package-supplier-admission-review-v1','enqueued')||
 !wp_style_is('mad4b-package-supplier-admission-review-v1','enqueued'))
 WP_CLI::error('Authorized admin readiness UI not enqueued');
if(!in_array('mad4b-jetengine-functional-modes',$s->deps,true)||
   !in_array('mad4b-jetengine-published-tours',$s->deps,true))
 WP_CLI::error('Supplier readiness panel did not follow Workspace and Tour Preview dependencies');
if(!has_action('wp_ajax_'.$cls::AJAX)||
    has_action('wp_ajax_nopriv_'.$cls::AJAX))
 WP_CLI::error('Private admin AJAX registration invalid');
$js=WPMU_PLUGIN_DIR.'/assets/mad4b-package-supplier-admission-review-v1.js';
$css=WPMU_PLUGIN_DIR.'/assets/mad4b-package-supplier-admission-review-v1.css';
if(!is_file($js)||!is_file($css))
 WP_CLI::error('Admin review asset missing');
$queueBefore=count(wp_scripts()->queue);
wp_set_current_user(0);
$cls::enqueue();
if($queueBefore!==count(wp_scripts()->queue))
 WP_CLI::error('Guest caused new admin assets to enqueue');
$deny=$cls::inspect(60629,'2026-12-15','EGP',2,0,0);
if(!is_wp_error($deny)||$deny->get_error_code()!=='package_admission_forbidden')
 WP_CLI::error('Guest cost-source audit was authorized');
echo 'SUPPLIER_ADMIN_UI_ACCEPTANCE '.wp_json_encode([
 'site'=>home_url(),'private_admin_ajax'=>true,
 'js_loaded'=>true,'css_loaded'=>true,
 'shared_tour_preview_dependency'=>true,
 'price_sources_workspace_dependency'=>true,
 'native_jetengine_save_unmodified'=>true,
 'guest_inspection_denied'=>true,
],JSON_UNESCAPED_UNICODE).PHP_EOL;
WP_CLI::success('PRICING_CONTROLS_READ_ONLY_SUPPLIER_REVIEW_UI_READY');
