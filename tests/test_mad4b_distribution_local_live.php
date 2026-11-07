<?php
/** Post-deployment acceptance, strictly read-only: no account or secret creation. */
if (!defined('ABSPATH') || !defined('WP_CLI')) exit(3);
$check=static function($ok,$reason){if(!$ok)WP_CLI::error('LOCAL_CONNECTION_TEST_'.$reason);};
$cls='MAD4B_Distribution_Local_Credentials_V1';
$reg='MAD4B_Distribution_Channel_Registry_V1';
$check(class_exists($cls),'BACKEND_LOADED');
$check(class_exists($reg),'REGISTRY_LOADED');
$info=$cls::status();
$check($info['external_vault_required']===false,'NO_EXTERNAL_WALLET');
$check($info['encryption_available']===true,'SODIUM_AND_SALT_READY');
$check($info['storage']==='company_local_encrypted_wp_option','ENCRYPTED_OPTIONS');
$check($info['domain_and_blog_bound']===true,'COMPANY_ISOLATION');
$rows=$reg::rows();
$check(count($rows)>=11,'CHANNEL_REGISTRY_AVAILABLE');
$check(array_sum(array_map(static fn($r)=>(int)($r['external_delivery_enabled']??false),$rows))===0,'NO_AUTOMATIC_EXTERNAL_SEND');
$check(count($reg::connection_health())===count($rows),'HEALTH_ALL_CHANNELS');
$check(($reg::connection_health()['meta_catalog']['external_vault_required']??true)===false,'HEALTH_NO_EXTERNAL_WALLET');
$check(($reg::connection_health()['google_things_to_do']['credential_required']??false)===true,'GTTD_CREDENTIALS_IF_CONNECTING');
$check(($reg::connection_health()['meta_catalog']['credential_required']??true)===false,'INTERNAL_FEED_NO_SECRET_REQUIRED');
$check(!is_user_logged_in(),'UNAUTHENTICATED_CONTEXT');
$check($cls::for_adapter('google_things_to_do','provider_verify')===false,'DENY_READ_BY_DEFAULT');
$check($cls::put('google_things_to_do','DEMO_LOCAL_SECRET_NOT_REAL') instanceof WP_Error,'ANONYMOUS_SECRET_PUT_DENIED');
$check($cls::delete('google_things_to_do') instanceof WP_Error,'ANONYMOUS_SECRET_DELETE_DENIED');
$routes=rest_get_server()->get_routes();
$check(isset($routes['/mad4b-distribution/v1/channels/(?P<id>[a-z0-9_]+)/credential']),'SECRET_ROUTE_EXISTS');
foreach ([['POST','/mad4b-distribution/v1/channels/google_things_to_do/credential'],
        ['DELETE','/mad4b-distribution/v1/channels/google_things_to_do/credential'],
        ['GET','/mad4b-distribution/v1/channels']] as $pair) {
    $req=new WP_REST_Request($pair[0],$pair[1]);
    $req->set_body_params(['credential'=>'DEMO_LOCAL_SECRET_NOT_REAL']);
    $res=rest_do_request($req);
    $check($res->get_status()>=400,'ANONYMOUS_REST_DENIED_'.$pair[0]);
}
$check(!array_key_exists('credential_reference',$reg::public_status()['meta_catalog']),'NO_RAW_REF_LEAK');
$check(!array_key_exists('secret',$reg::public_status()['meta_catalog']),'NO_RAW_SECRET_LEAK');
$raw=get_option($cls::STORE,[]);
$check(is_array($raw),'LOCAL_SECRET_STORE_VALID');
foreach($raw as $id=>$cipher){
    $check(is_string($cipher) && str_starts_with($cipher,'MAD4B$DL1$'),'NO_PLAINTEXT_SECRETS');
}
$check(get_plugin_data(WP_PLUGIN_DIR.'/mad4b-distribution-connectors/mad4b-distribution-connectors.php')['Version']==='1.2.0','DEPLOYED_VERSION');
$check(function_exists('mcfm_get_feeds') && count(mcfm_get_feeds())>=2,'FEEDS_PRESERVED');
$check(count(MAD4B_Meta_Catalog_Adapter_V1::profiles())>=2,'CATALOG_PROFILES_PRESERVED');
$check(count(array_filter($reg::connection_health(),static fn($x)=>$x['connection_verified']))===0,'NO_FALSE_CONNECTED_STATE');
echo wp_json_encode(['test'=>'mad4b.distribution-local-live.v1','tests_passed'=>24,
    'site'=>home_url(), 'registered_channels'=>count($rows),
    'encrypted_storage_available'=>$info['encryption_available'],
    'external_vault_required'=>false,'stored_credentials'=>count($raw),
    'managed_feeds'=>count(mcfm_get_feeds()),'provider_connections_verified'=>0,
    'external_sends'=>0,'mutations'=>false],JSON_UNESCAPED_SLASHES).PHP_EOL;
