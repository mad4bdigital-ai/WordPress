<?php
/** Original Company Reconcile: source-owned, nonfinancial price-preview readiness.
 * This is a pure diagnostic provider. It never mutates Options, Orders or Rates.
 */
defined('ABSPATH') || exit;
final class MAD4B_Company_Price_Preview_Reconcile_Provider_V1 {
    public const CONTRACT='mad4b.company-price-preview-reconcile-provider.v1';
    public static function inspect():array {
        $profile=class_exists('MAD4B_SCP_Site_Profile',false)
            ?MAD4B_SCP_Site_Profile::status():[];
        $identity=!empty($profile['configured'])&&!empty($profile['origin_match'])&&
            !empty($profile['environment_match'])&&!empty($profile['deployment_binding_match']);
        $mode=function_exists('shared_product_mode_policies')?shared_product_mode_policies():null;
        $modeReady=is_array($mode)&&!empty($mode['tour']['enabled'])&&
            !empty($mode['tour']['strategy'])&&
            in_array($mode['tour']['strategy'],['rate_occupancy','group_size_tiered',
                'seasonal_occupancy','accommodation_matrix'],true);
        $runtime=class_exists('MAD4B_Form6_Readonly_Price_Preview_V1',false)
            ?MAD4B_Form6_Readonly_Price_Preview_V1::status():[];
        $commerce=defined('MAD4B_COMMERCE_RUNTIME_ENABLED')&&MAD4B_COMMERCE_RUNTIME_ENABLED===true;
        $present=defined('MAD4B_PRESENTATION_RUNTIME_ENABLED')&&MAD4B_PRESENTATION_RUNTIME_ENABLED===true;
        $preview=$commerce?function_exists('shared_presentation_form6_price_quote'):
            !empty($runtime['enabled'])&&!empty($runtime['all_readonly_dependencies']);
        $route=isset(rest_get_server()->get_routes()['/shared-product/v1/tour-form-price-preview']);
        $asset=is_readable(WPMU_PLUGIN_DIR.'/shared-product-core/assets/presentation-fluent-quote-v1.js')&&
            is_readable(WPMU_PLUGIN_DIR.'/shared-product-core/assets/presentation-selection-state-v1.js');
        $provider=is_readable(WP_PLUGIN_DIR.'/mad4b-commerce/modules/compat/local-presentation-fluent-projection-v1.php');
        $projectionLoaded=in_array(realpath(WP_PLUGIN_DIR.'/mad4b-commerce/modules/compat/local-presentation-fluent-projection-v1.php'),
            array_map('realpath',get_included_files()),true);
        // The executable Shortcode owner and CSS loader are required; an
        // identical PHP file on disk is not proof it is active in WordPress.
        $expected=realpath(WP_PLUGIN_DIR.'/mad4b-commerce/modules/commerce/local-tour-public-price-v1.php');
        $callback=$GLOBALS['shortcode_tags']['etg_dynamic_tour_price']??null;
        $actual=$callback instanceof Closure?(new ReflectionFunction($callback))->getFileName():'';
        $nativePriceOwner=is_string($expected) && $expected!=='' &&
            is_string($actual) && realpath($actual)===$expected &&
            function_exists('shared_lab_tour_price_resolve');
        $allFiles=array_map('realpath',get_included_files());
        $sourceStyle=WP_PLUGIN_DIR.'/mad4b-platform/modules/services/mad4b-responsive-presentation.php';
        $tenantStyle=WP_PLUGIN_DIR.'/mad4b-commerce/modules/presentation/company-responsive-source-visual-v1.php';
        $responsiveLoaded=false;
        foreach([$sourceStyle,$tenantStyle] as $styleFile){
            $real=realpath($styleFile);
            if(is_string($real)&&$real!==''&&in_array($real,$allFiles,true)){
                $responsiveLoaded=true;break;
            }
        }
        $fail=[];
        if(!$nativePriceOwner)$fail[]='public_price_shortcode_source_owner_not_active';
        if(!$responsiveLoaded)$fail[]='responsive_presentation_source_css_not_loaded';
        if(!$identity)$fail[]='site_identity_unverified';
        if(!$modeReady)$fail[]='mode_pricing_policy_missing_or_disabled';
        if(!$preview)$fail[]='price_preview_runtime_missing';
        if(!$route)$fail[]='price_preview_route_missing';
        if(!$asset)$fail[]='presentation_js_assets_missing';
        if(!$provider)$fail[]='form6_projection_provider_missing';
        if(!$projectionLoaded)$fail[]='form6_projection_provider_not_loaded';
        return ['contract'=>self::CONTRACT,'state'=>$fail?'RECONCILE_REQUIRED':'PROVIDER_STRUCTURE_READY_PRICE_DATA_NOT_CERTIFIED',
            'site_uuid'=>$profile['site_uuid']??null,
            'origin'=>untrailingslashit(home_url('/')),
            'environment'=>wp_get_environment_type(),
            'price_preview_nonfinancial'=>$preview,
            'mode_policy_configured'=>$modeReady,'mode_policy_count'=>is_array($mode)?count($mode):0,
            'price_preview_route_registered'=>$route,'form6_projection_provider_present'=>$provider,
            'form6_projection_provider_loaded'=>$projectionLoaded,
            'selection_assets_present'=>$asset,
            'public_price_shortcode_source_owner_native'=>$nativePriceOwner,
            'responsive_design_source_provider_loaded'=>$responsiveLoaded,
            'style_browser_css_readback_required'=>true,
            'runtime'=>$commerce?'commerce':($present?'presentation':'disabled'),
            'price_data_or_date_eligibility_certified'=>false,
            'price_quote_sample_verified'=>false,
            'financial_orders_or_checkout_changed'=>false,'failures'=>$fail];
    }
}
add_filter('mad4b_company_reconcile_component_readiness',static function($providers){
    if(!is_array($providers))$providers=[];
    $providers['nonfinancial_pricing_preview']=MAD4B_Company_Price_Preview_Reconcile_Provider_V1::inspect();
    return $providers;
},30);
add_action('mad4b_company_content_reconciled',static function($context=[]):void {
    if(!is_array($context))return;
    do_action('mad4b_company_price_preview_reconcile_readback',
        MAD4B_Company_Price_Preview_Reconcile_Provider_V1::inspect(),$context);
},40);
