<?php
/**
 * REV114 — Commerce Suite Release Certification (local, read-only).
 * Joins existing subsystem evidence; never duplicates source pricing or
 * claims production authority on the basis of a plugin being installed.
 */
defined('ABSPATH') || exit;
if ((!defined('MAD4B_COMMERCE_RUNTIME_ENABLED') || !MAD4B_COMMERCE_RUNTIME_ENABLED) || !defined('MAD4B_COMMERCE_RUNTIME_ENABLED')
    || !MAD4B_COMMERCE_RUNTIME_ENABLED) return;

function shared_commerce_suite_certification_v1(int $tourId=0):array {
    global $wpdb;
    $check=static function(array $items):array {
        $gates=[];
        foreach($items as $k=>$v)$gates[$k]=(bool)$v;
        return ['mounted'=>count(array_filter($gates))===count($gates),
                'checks'=>$gates,'missing'=>array_keys(array_filter($gates,static fn($ok)=>!$ok))];
    };
    $formTable=$wpdb->prefix.'fluentform_forms';
    $form6=(int)$wpdb->get_var($wpdb->prepare(
       "SELECT COUNT(*) FROM {$formTable} WHERE id=%d",6));
    $marketSource=class_exists('MAD4B_SCP_Market_Profiles');
    $approved=$marketSource?MAD4B_SCP_Market_Profiles::approved_list():[];
    $marketCount=is_array($approved)?count($approved):0;
    $offerCpt=post_type_exists('offer-campaign');
    $offers=$offerCpt?wp_count_posts('offer-campaign'):null;
    $published=$offers?(int)($offers->publish??0):0;
    $offerConfig=get_option('global-offers',[]);
    $offersEnabled=is_array($offerConfig) && !empty($offerConfig['offers_enabled']);
    $core=$check([
        'woocommerce'=>class_exists('WooCommerce')&&function_exists('wc_get_product'),
        'tour_rate_eligibility'=>function_exists('shared_product_eligible_tour_rate_ids'),
        'canonical_quote'=>function_exists('shared_product_pricing_quote_contract_v2'),
        'rate_generator'=>class_exists('Shared_Product_Rate_Generation'),
        'program_source'=>function_exists('shared_product_tour_itinerary_contract_v1'),
        'duration_source'=>function_exists('shared_product_rate_duration_source_v1'),
        'date_service'=>class_exists('Shared_Product_Date_Service'),
        'financial_policy'=>function_exists('shared_product_verify_global_offer_quote')
    ]);
    $presentation=$check([
        'elementor'=>did_action('elementor/loaded')>0 || class_exists('Elementor\Plugin'),
        'jetengine'=>function_exists('jet_engine'),
        'jetsmartfilters'=>class_exists('Jet_Smart_Filters') || function_exists('jet_smart_filters'),
        'native_provider_registry'=>function_exists('shared_presentation_registry_v2'),
        'query_context'=>function_exists('shared_presentation_primary_tour_post_v1'),
        'jetengine_dynamic_tags'=>function_exists('company_presentation_text'),
        'selection_source'=>function_exists('native_component_selection_plan'),
        'native_rate_facets'=>function_exists('shared_product_tour_facets'),
        'wpml'=>defined('ICL_SITEPRESS_VERSION') || class_exists('SitePress')
    ]);
    $forms=$check([
        'fluent_forms'=>class_exists('FluentForm\App\Models\Form'),
        'form6_schema'=>$form6===1,
        'booking_intent'=>function_exists('shared_product_form_booking_intent'),
        'quote_preview_route'=>function_exists('shared_presentation_form6_quote_preview_v1')
           || isset(rest_get_server()->get_routes()['/shared-product/v1/tour-form-price-preview']),
        'submission_guard'=>has_filter('fluentform/filter_insert_data')!==false,
        'selection_projection'=>is_readable(WPMU_PLUGIN_DIR.'/local-presentation-fluent-projection-v1.php')
             &&is_readable(WPMU_PLUGIN_DIR.'/shared-product-core/assets/presentation-fluent-projection-v1.js')
    ]);
    $markets=$check([
        'control_plane_profile_provider'=>$marketSource,
        'market_context_provider'=>class_exists('MAD4B_SCP_Market_Context'),
        'approval_bridge'=>function_exists('shared_offer_verified_market'),
        'market_rule_guard'=>function_exists('shared_woo_market_rule_match'),
        'woocommerce_market_bridge'=>function_exists('shared_offer_current_visible_language')
    ]);
    $offersCheck=$check([
        'offer_campaign_cpt'=>$offerCpt,
        'market_targeting'=>function_exists('shared_woo_campaign_market_eligible'),
        'offer_resolver'=>function_exists('global_offer_resolved'),
        'discount_verifier'=>function_exists('shared_product_verify_global_offer_quote'),
        'coupon_bridge'=>function_exists('shared_product_global_offer_coupon_context_v1')
             ||is_readable(WPMU_PLUGIN_DIR.'/shared-product-core/pricing/global-offer-coupon.php')
    ]);
    $company=$check([
        'commerce_composition'=>class_exists('Commerce\Composition\CommerceEngineInspector'),
        'company_commerce_policy'=>class_exists('Commerce\Composition\CompanyCommercePolicy'),
        'provider_tenant_policy'=>class_exists('MAD4B_SCP_Market_Profiles'),
        'site_control_plane'=>class_exists('MAD4B_SCP_Market_Context'),
        'suite_lab_scope'=>(defined('MAD4B_COMMERCE_RUNTIME_ENABLED') && MAD4B_COMMERCE_RUNTIME_ENABLED)
    ]);
    $components=[
        'commerce_core'=>$core,'presentation_context'=>$presentation,
        'fluent_forms'=>$forms,'market_targeting'=>$markets,
        'offer_engine'=>$offersCheck,'company_runtime'=>$company
    ];
    $mounted=0;
    foreach($components as $component)if($component['mounted'])$mounted++;
    $commercialBlockers=[];
    if(!$core['mounted'])$commercialBlockers[]='commerce_core_missing_dependency';
    if(!$presentation['mounted'])$commercialBlockers[]='presentation_missing_dependency';
    if(!$forms['mounted'])$commercialBlockers[]='fluent_form6_contract_incomplete';
    if(!$markets['mounted'])$commercialBlockers[]='market_provider_not_mounted';
    if($marketCount===0)$commercialBlockers[]='no_approved_market_profiles';
    if(!$offersCheck['mounted'])$commercialBlockers[]='offer_engine_dependency_incomplete';
    if(!$offersEnabled)$commercialBlockers[]='global_offers_not_enabled';
    if($published===0)$commercialBlockers[]='no_published_offer_campaign';
    if(!$company['mounted'])$commercialBlockers[]='company_policy_provider_incomplete';
    // A complete customer order/booking and a certified artifact are separate
    // signed release gates, never inferred from smoke/CLI read assertions.
    $commercialBlockers[]='full_booking_payment_supplier_e2e_not_certified';
    $commercialBlockers[]='canonical_repository_release_candidate_not_certified';
    $commercialBlockers[]='tenant_staging_dependency_parity_not_certified';
    $tourPreview=null;
    if($tourId>0){
        if(get_post_type($tourId)!=='tours-and-activities'){
            $tourPreview=['state'=>'unknown_tour'];
        } else {
            $source=shared_product_tour_itinerary_contract_v1($tourId);
            $rateIds=shared_product_eligible_tour_rate_ids($tourId);
            $tourPreview=[
                'tour_id'=>$tourId,
                'program_source'=>$source['authoritative_source']??'unknown',
                'program_state'=>$source['state']??'unknown',
                'program_choice'=>$source['customer_program_choice']??false,
                'duration_source'=>shared_product_tour_duration_source_v1($tourId),
                'eligible_rate_ids'=>array_slice(array_map('intval',$rateIds),0,50),
                'eligible_rate_count'=>count($rateIds),
                'quotation_status'=>'preview_not_approved'
            ];
        }
    }
    return [
        'contract'=>'commerce-suite.release-certification.v1',
        'environment'=>wp_get_environment_type(),
        'site_scope'=>'site_bound_runtime',
        'schema'=>[
            'core'=>'source_owned_existing_engines',
            'presentation'=>'elementor_jetengine_jsf_wpml_dynamic_sources',
            'form'=>'fluent_forms_native_intent_and_quote',
            'market'=>'mad4b_control_plane_approved_profiles',
            'offer'=>'woocommerce_native_offer_campaign_and_verified_quote',
            'tenant'=>'company_owned_configuration_not_global_mutation'
        ],
        'components'=>$components,'mounted_component_count'=>$mounted,
        'component_count'=>count($components),
        'data_readiness'=>[
            'form6_schema_available'=>$form6===1,
            'approved_market_profiles'=>$marketCount,
            'published_offer_campaigns'=>$published,
            'global_offers_enabled'=>$offersEnabled
        ],
        'product_preview'=>$tourPreview,
        'commercial_ready'=>false,'commercial_blockers'=>$commercialBlockers,
        'package_certified'=>false,'staging_deployment_authorized'=>false,
        'production_mutation_authorized'=>false,
        'quote_approved'=>false,'booking_confirmed'=>false,'writes_performed'=>false
    ];
}
add_action('rest_api_init',static function():void {
    register_rest_route('shared-product/v1','/commerce-suite/certification',[
       'methods'=>WP_REST_Server::READABLE,
       'permission_callback'=>static fn()=>current_user_can('manage_options')
           &&(defined('MAD4B_COMMERCE_RUNTIME_ENABLED') && MAD4B_COMMERCE_RUNTIME_ENABLED),
       'args'=>['tour_id'=>['type'=>'integer','minimum'=>0,'default'=>0]],
       'callback'=>static function(WP_REST_Request $req){
           $res=new WP_REST_Response(shared_commerce_suite_certification_v1((int)$req['tour_id']));
           $res->header('Cache-Control','private,no-store,max-age=0');
           return $res;
       }
    ]);
},30);
add_action('admin_menu',static function():void {
    add_management_page(
        'Commerce Suite Release Certification','Commerce Suite Release',
        'manage_options','shared-commerce-suite-release',
        static function(){
            if(!current_user_can('manage_options'))wp_die('Forbidden');
            $status=shared_commerce_suite_certification_v1();
            echo '<div class="wrap"><h1>Commerce Suite Release Certification</h1>';
            echo '<p>Read-only dependency and commercial readiness assessment. Does not authorize checkout, quote or deployment.</p>';
            echo '<table class="widefat striped"><thead><tr><th>Subsystem</th><th>Mounted</th><th>Missing prerequisites</th></tr></thead><tbody>';
            foreach($status['components'] as $name=>$part)
                echo '<tr><td>'.esc_html($name).'</td><td>'.($part['mounted']?'Yes':'No').
                 '</td><td>'.esc_html(implode(', ',$part['missing'])).'</td></tr>';
            echo '</tbody></table><h2>Data readiness</h2><pre>'.
                esc_html(wp_json_encode($status['data_readiness'],JSON_PRETTY_PRINT)).
                '</pre><h2>Unclosed release gates</h2><ul>';
            foreach($status['commercial_blockers'] as $why)echo '<li>'.esc_html($why).'</li>';
            echo '</ul><p>REST: <code>'.esc_html(rest_url('shared-product/v1/commerce-suite/certification')).'</code></p></div>';
        }
    );
},95);
