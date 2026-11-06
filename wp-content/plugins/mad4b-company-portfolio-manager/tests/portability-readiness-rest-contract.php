<?php
/** Feature009 P1D read-only REST/admin contract, exact company scoping. */
if(!defined('WP_CLI')||!WP_CLI)exit(2);
$class='MAD4B_Company_Portability_Readiness_V1';
$checks=[];
$routes=rest_get_server()->get_routes();
$pattern='/mad4b/v1/company-management/companies/(?P<company_code>[a-z][a-z0-9-]{2,47})/portability-readiness';
$registered=$routes[$pattern]??[];
$checks['get_only_route']=count($registered)===1
 &&isset($registered[0]['methods']['GET'])
 &&!isset($registered[0]['methods']['POST']);
$anon=rest_do_request(new WP_REST_Request('GET',
  '/mad4b/v1/company-management/companies/tours-central/portability-readiness'));
$checks['anonymous_denied']=$anon->get_status()===403;
$before=[
 'memberships'=>get_option('mad4b_company_network_memberships_v1',null),
 'catalog'=>get_option('mad4b_company_service_catalog_modes_v1',null),
 'markets'=>get_option('mad4b_central_company_market_portfolio_plans_v1',null)
];
$ids=get_users(['role'=>'administrator','fields'=>'ids','number'=>1]);
if(!$ids){echo 'no_admin_available',PHP_EOL;exit(8);}
wp_set_current_user((int)$ids[0]);
foreach(['tours-central','wayfatours'] as $code){
 $request=new WP_REST_Request('GET',
  '/mad4b/v1/company-management/companies/'.$code.'/portability-readiness');
 $res=rest_do_request($request);$body=$res->get_data();
 $checks['get_200_'.$code]=$res->get_status()===200
  &&($body['company_code']??null)===$code
  &&($body['hub']['company_publication_target_count']??null)===0
  &&($body['hub']['exact_company_site_brand_records']??null)===0
  &&($body['can_detach_now']??true)===false
  &&($body['can_rejoin_now']??true)===false
  &&($body['actual_company_export_created']??true)===false
  &&count($body['blocking_gates']??[])===9
  &&($res->get_headers()['Cache-Control']??null)==='private, no-store';
}
$post=rest_do_request(new WP_REST_Request('POST',
 '/mad4b/v1/company-management/companies/tours-central/portability-readiness'));
$checks['post_not_accepted']=in_array($post->get_status(),[404,405],true);
ob_start();
MAD4B_Company_Portability_Readiness_V1::screen();
$html=ob_get_clean();
$checks['admin_screen_rendered']=is_string($html)
 &&str_contains($html,'Company Portability Readiness')
 &&str_contains($html,'tours-central')
 &&str_contains($html,'wayfatours')
 &&str_contains($html,'hub_company_publication_lineage_missing');
$checks['admin_menu_registered']=has_action('admin_menu',[$class,'admin_menu'])!==false;
$checks['no_option_writes']=
 get_option('mad4b_company_network_memberships_v1',null)===$before['memberships']
 &&get_option('mad4b_company_service_catalog_modes_v1',null)===$before['catalog']
 &&get_option('mad4b_central_company_market_portfolio_plans_v1',null)===$before['markets'];
echo wp_json_encode(['pass'=>!in_array(false,$checks,true),'checks'=>$checks],JSON_UNESCAPED_SLASHES),PHP_EOL;
if(in_array(false,$checks,true))exit(8);
