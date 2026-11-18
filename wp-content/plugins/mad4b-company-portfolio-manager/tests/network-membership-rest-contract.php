<?php
/**
 * Read + invalid-write REST acceptance; DOES NOT mutate any membership.
 * Run with WP-CLI in the enrolled Staging container.
 */
defined('WP_CLI') && WP_CLI || exit(2);
$path='/mad4b/v1/company-management/companies/tours-central/network-membership';
$result=['checks'=>[]];
$route=rest_get_server()->get_routes();
$result['checks']['route_registered']=count($route['/mad4b/v1/company-management/companies/(?P<company_code>[a-z][a-z0-9-]{2,47})/network-membership']??[])===2;
$anon=rest_do_request(new WP_REST_Request('GET',$path));
$result['checks']['anonymous_denied']=$anon->get_status()===403;
$admins=get_users(['role'=>'administrator','fields'=>'ids','number'=>1]);
if(!$admins)exit(3);
wp_set_current_user((int)$admins[0]);
$checks=[
    ['case'=>'unsupported_action','payload'=>['action'=>'disconnect_now','expected_revision'=>0],'status'=>422],
    ['case'=>'stale_revision','payload'=>['action'=>'prepare_detach','expected_revision'=>1],'status'=>409],
    ['case'=>'unexpected_field','payload'=>['action'=>'prepare_detach','expected_revision'=>0,'production_write'=>true],'status'=>422],
    ['case'=>'invalid_authority','payload'=>['action'=>'set_authority','expected_revision'=>0,'content_authority'=>'super_admin'],'status'=>422]
];
foreach(['tours-central','wayfatours'] as $code) {
    $request=new WP_REST_Request('GET',
        '/mad4b/v1/company-management/companies/'.$code.'/network-membership');
    $res=rest_do_request($request);
    $data=$res->get_data();
    $result['checks']['read_'.$code]=$res->get_status()===200
        &&($data['company_code']??null)===$code
        &&($data['readiness']['can_detach_now']??true)===false
        &&($data['runtime_changed']??true)===false;
}
foreach($checks as $check) {
    $request=new WP_REST_Request('POST',$path);
    $request->set_header('X-WP-Nonce',wp_create_nonce('wp_rest'));
    $request->set_header('Content-Type','application/json');
    $request->set_body(wp_json_encode($check['payload']));
    $res=rest_do_request($request);
    $result['checks'][$check['case']]=$res->get_status()===$check['status'];
}
$result['checks']['read_only_staging_option']=get_option(
    MAD4B_Company_Network_Membership_V1::STORE,null)===null;
$result['pass']=!in_array(false,$result['checks'],true);
echo wp_json_encode($result,JSON_UNESCAPED_SLASHES),PHP_EOL;
if(!$result['pass'])exit(8);
