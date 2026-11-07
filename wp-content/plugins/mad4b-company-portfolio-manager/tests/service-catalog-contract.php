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
echo wp_json_encode(['pass'=>!in_array(false,$checks,true),
    'checks'=>$checks,'companies'=>$rows],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE),PHP_EOL;
if (in_array(false,$checks,true)) exit(8);
