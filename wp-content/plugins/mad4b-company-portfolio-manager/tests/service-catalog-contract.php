<?php
/**
 * wp eval-file wp-content/plugins/mad4b-company-portfolio-manager/tests/service-catalog-contract.php
 * Read-only integration assertions. Never activates a service or writes an option.
 */
if (!defined('WP_CLI') || !WP_CLI) {
    fwrite(STDERR, "Requires WP-CLI\n");
    exit(2);
}
$cls='MAD4B_Company_Service_Catalog_V1';
$checks=[];
$checks['loaded']=class_exists($cls,false);
$checks['staging']=MAD4B_Company_Service_Catalog_V1::staging();
$checks['invalid_mode_fail_closed']=MAD4B_Company_Service_Catalog_V1::matrix('external')===[];
$checks['tour_only_matrix']=MAD4B_Company_Service_Catalog_V1::matrix('tour_only')===[
    'tours'=>'standalone_and_tour','properties'=>'tour_only',
    'transportations'=>'tour_only','visas'=>'tour_only'
];
$checks['full_catalog_plan_matrix']=MAD4B_Company_Service_Catalog_V1::matrix('full_catalog')===
    array_fill_keys(['tours','properties','transportations','visas'],'standalone_and_tour');
$routes=rest_get_server()->get_routes();
$route='/mad4b/v1/company-management/companies/(?P<company_code>[a-z][a-z0-9-]{2,47})/service-catalog';
$checks['rest_registered']=isset($routes[$route]);
$observation_route='/mad4b/v1/company-management/companies/(?P<company_code>[a-z][a-z0-9-]{2,47})/service-catalog/public-observation';
$checks['public_observation_route_registered']=isset($routes[$observation_route]);
$native_route='/mad4b/v1/company-management/companies/(?P<company_code>[a-z][a-z0-9-]{2,47})/service-catalog/native-observation';
$checks['protected_native_route_registered']=isset($routes[$native_route]);
$codes=MAD4B_Company_Service_Catalog_V1::companies();
$rows=[];
foreach ($codes as $code) {
    $item=MAD4B_Company_Service_Catalog_V1::current($code);
    $rows[$code]=is_wp_error($item)?['error'=>$item->get_error_code()]:[
        'mode'=>$item['mode'],'revision'=>$item['revision'],
        'uuid_bound'=>wp_is_uuid($item['company_site_uuid']),
        'applied'=>$item['site_activation_applied'],
        'write_authorized'=>$item['tenant_write_authorized']
    ];
    $checks['bound_'.$code]=!is_wp_error($item)
        &&wp_is_uuid($item['company_site_uuid'])
        &&in_array($item['mode'],['tour_only','full_catalog'],true)
        &&($item['site_activation_applied']??true)===false
        &&($item['tenant_write_authorized']??true)===false;
}
$checks['companies_present']=count($codes)>0;
$checks['wayfa_observation_pilot_enabled']=
    MAD4B_Company_Service_Catalog_V1::observation_enabled('wayfatours');
$checks['other_company_observation_not_implicitly_enabled']=
    !MAD4B_Company_Service_Catalog_V1::observation_enabled('all-royal-egypt')
    && !MAD4B_Company_Service_Catalog_V1::observation_enabled('tours-central');

$target='wayfatours';
if(in_array($target,$codes,true)){
    $observeRequest=new WP_REST_Request('GET',
        '/mad4b/v1/company-management/companies/'.$target.'/service-catalog/public-observation');
    $allow=rest_do_request($observeRequest);
    $detail=$allow->get_data();
    $checks['live_observation_admin_allowed']=$allow->get_status()===200;
    $checks['live_observation_never_grants_rights']=
        ($detail['company_plan_applied']??true)===false
        &&($detail['tenant_mutated']??true)===false
        &&($detail['booking_verified']??true)===false
        &&($detail['payment_verified']??true)===false;
    $checks['live_observation_bound_host']=($detail['site_host']??null)==='wayfatours.com'
        &&($detail['registered_site_uuid']??null)===($rows[$target]['uuid_bound']
            ?MAD4B_Company_Portfolio_Manager_V1::company_binding($target)['company_site_uuid']:null);
    $checks['live_observation_rates_guest_denied']=
        ($detail['checks']['internal_rates']['http_status']??0)===404;
    $checks['live_observation_plan_matches_guest']=
        ($detail['plan_mode']??null)==='tour_only'
        && ($detail['plan_to_public_disagreements']??null)===[]
        && ($detail['plan_vs_public']['properties']['expected_guest_http_if_deployed']??null)===404
        && ($detail['plan_vs_public']['properties']['observed_guest_http']??null)===404;

    $original_user=get_current_user_id();
    wp_set_current_user(0);
    $anonymous=rest_do_request($observeRequest);
    $checks['live_observation_anonymous_denied']=in_array($anonymous->get_status(),[401,403],true);
    wp_set_current_user($original_user);
    $nativeRequest=new WP_REST_Request('GET',
        '/mad4b/v1/company-management/companies/'.$target.'/service-catalog/native-observation');
    $nativeResponse=rest_do_request($nativeRequest);
    $native=$nativeResponse->get_data();
    $checks['native_readback_admin_200']=$nativeResponse->get_status()===200;
    $checks['native_readback_original_site_uuid']=
        ($native['site_uuid']??null)==='a3a7cc36-db7e-4a14-8456-b41857def05c';
    $checks['native_readback_fresh_wpcli_unsigned']=
        ($native['state']??null)==='fresh_local_wordpress_wpcli_unsigned'
        &&($native['original313_signed_receipt']??true)===false
        &&($native['cryptographically_signed']??true)===false;
    $checks['native_readback_runtime_schema_verified']=
        ($native['snapshot']['schema']['declared_count']??0)===31
        &&($native['snapshot']['schema']['runtime_schema_gaps']??null)==[];
    $options=$native['snapshot']['related_options_live_status']??[];
    $checks['native_options_contract_verified']=
        ($options['pricing_configuration']['schema_fields']??null)===37
        && ($options['pricing_configuration']['financial_policy_complete']??null)===true
        && ($options['pricing_configuration']['expected_booking_field_present']??null)===true;
    $checks['native_destination_activation_verified']=
        ($options['destinations']['hierarchy_and_public_flags_consistent']??null)===true
        && ($options['destinations']['groups']['active_continents']['selected']??0)===2
        && ($options['destinations']['groups']['active_countries']['selected']??0)===4
        && ($options['destinations']['groups']['active_governorates']['selected']??0)===17;
    $checks['native_currency_authoring_scope_verified']=
        count((array)($options['currencies']['new_draft_authoring_currencies']??[]))===4
        && count((array)($options['selling_options']['allowed_units']??[]))===5;
    $checks['native_readback_deployment_not_inferred']=
        ($native['company_plan_applied']??true)===false
        &&($native['booking_verified']??true)===false
        &&($native['payment_verified']??true)===false;
    $checks['native_readback_other_company_denied']=
        (MAD4B_Company_Service_Catalog_V1::native_observation('all-royal-egypt')['reason']??'')
        ==='company_pending_reconciliation_or_full_clone';
    wp_set_current_user(0);
    $publicNative=rest_do_request($nativeRequest);
    $checks['native_readback_anonymous_forbidden']=
        in_array($publicNative->get_status(),[401,403],true);
    wp_set_current_user($original_user);
    // In-process mock of a corrupted central option: no production DB mutation.
    $key=MAD4B_Company_Service_Catalog_V1::NATIVE_OBSERVATION_PREFIX.$target;
    $corrupt=get_option($key);
    if(is_array($corrupt)){
        $corrupt['snapshot_sha256']=str_repeat('0',64);
        $override=static fn()=>$corrupt;
        add_filter('pre_option_'.$key,$override);
        $check=MAD4B_Company_Service_Catalog_V1::native_observation($target);
        remove_filter('pre_option_'.$key,$override);
        $checks['native_readback_corrupt_hash_denied']=
            ($check['state']??null)==='unverified'
            &&($check['reason']??null)==='receipt_binding_or_digest_invalid';
    }else $checks['native_readback_corrupt_hash_denied']=false;

    $original=get_option($key);
    if(is_array($original)){
        $stale=$original;
        $stale['observed_epoch']=time()-400;
        $stale['expires_epoch']=time()-40;
        $override=static fn()=>$stale;
        add_filter('pre_option_'.$key,$override);
        $result=MAD4B_Company_Service_Catalog_V1::native_observation($target);
        remove_filter('pre_option_'.$key,$override);
        $checks['native_readback_expired_denied']=($result['state']??null)==='unverified'
            &&($result['reason']??null)==='host_wpcli_readback_stale';

        $badModule=$original;
        $untrusted=json_decode($original['snapshot_json'],true);
        $untrusted['readback_module_sha256']=str_repeat('0',64);
        $badModule['snapshot_json']=wp_json_encode($untrusted,
            JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
        $badModule['snapshot_sha256']=hash('sha256',$badModule['snapshot_json']);
        $override=static fn()=>$badModule;
        add_filter('pre_option_'.$key,$override);
        $result=MAD4B_Company_Service_Catalog_V1::native_observation($target);
        remove_filter('pre_option_'.$key,$override);
        $checks['native_readback_wrong_runtime_code_denied']=
            ($result['state']??null)==='unverified'
            &&($result['reason']??null)==='runtime_snapshot_identity_or_schema_mismatch';

        $withoutOptions=$original;
        $payload=json_decode($original['snapshot_json'],true);
        unset($payload['snapshot']['related_options_live_status']);
        $withoutOptions['snapshot_json']=wp_json_encode($payload,
            JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
        $withoutOptions['snapshot_sha256']=hash('sha256',$withoutOptions['snapshot_json']);
        $override=static fn()=>$withoutOptions;
        add_filter('pre_option_'.$key,$override);
        $result=MAD4B_Company_Service_Catalog_V1::native_observation($target);
        remove_filter('pre_option_'.$key,$override);
        $checks['native_options_missing_fail_closed']=
            ($result['state']??null)==='unverified'
            &&($result['reason']??null)==='live_options_snapshot_missing_or_invalid';
    }else{
        $checks['native_readback_expired_denied']=false;
        $checks['native_readback_wrong_runtime_code_denied']=false;
        $checks['native_options_missing_fail_closed']=false;
    }
}

echo wp_json_encode(['pass'=>!in_array(false,$checks,true),
    'checks'=>$checks,'companies'=>$rows],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE),PHP_EOL;
if (in_array(false,$checks,true)) exit(8);
