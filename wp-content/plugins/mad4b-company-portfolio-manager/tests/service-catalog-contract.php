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
}

echo wp_json_encode(['pass'=>!in_array(false,$checks,true),
    'checks'=>$checks,'companies'=>$rows],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE),PHP_EOL;
if (in_array(false,$checks,true)) exit(8);
