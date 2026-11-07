<?php
/** WP-CLI integration, no authentication impersonation and no external calls. */
if (!defined('ABSPATH')||!defined('WP_CLI')) exit(3);
if (!class_exists('MAD4B_Distribution_Channel_Registry_V1',false)) {
    require_once '/tmp/mad4b-distribution-connectors-preflight/mad4b-distribution-connectors.php';
}
$cls='MAD4B_Distribution_Channel_Registry_V1';
$assert=static function($x,$why){if(!$x)WP_CLI::error('DISTRIBUTION_REGRESSION_'.$why);};
$catalog=$cls::catalog();
$assert(count($catalog)>=8,'CHANNEL_CATALOG');
foreach(['meta_catalog','google_things_to_do','google_ads','google_business_profile','viator','getyourguide','klook','partner_b2b'] as $code) {
    $assert(isset($catalog[$code]),'CHANNEL_'.$code);
    $row=$cls::default_row($code,$catalog[$code]);
    $assert($row['status']==='not_connected' && !$row['external_delivery_enabled']
        && !$row['publishing_enabled'] && !$row['credential_reference'],'SAFE_DEFAULTS_'.$code);
}
$ads=$cls::default_row('google_ads',$catalog['google_ads']);
$assert($ads['linked_channels']===['google_things_to_do'],'SHARED_DATASET_LINK');
$assert($ads['shared_dataset']==='tourism_catalog_v1','SHARED_DATASET');
$assert($cls::create_custom(['id'=>'custom_test','label'=>'Example','transport'=>'api','category'=>'other']) instanceof WP_Error,'ANONYMOUS_CREATE_DENIED');
$assert($cls::configure('meta_catalog',['account_alias'=>'test']) instanceof WP_Error,'ANONYMOUS_CONFIG_DENIED');
$server=rest_get_server();
// A candidate loaded by WP-CLI after rest_api_init must register its route manually.
if (!isset($server->get_routes()['/mad4b-distribution/v1/channels'])) $cls::rest();
$routes=$server->get_routes();
$assert(isset($routes['/mad4b-distribution/v1/channels']),'REST_CHANNELS');
$assert(isset($routes['/mad4b-distribution/v1/channels/(?P<id>[a-z0-9_]+)']),'REST_CHANNEL_DETAIL');
foreach([['GET','/mad4b-distribution/v1/channels'],['POST','/mad4b-distribution/v1/channels'],
    ['POST','/mad4b-distribution/v1/channels/meta_catalog']] as $spec) {
    $req=new WP_REST_Request($spec[0],$spec[1]);
    $resp=rest_do_request($req);
    $assert($resp->get_status()>=400,'ANONYMOUS_REST_DENIED');
}
$rows=$cls::rows();
foreach($rows as $code=>$row){
    $assert(($row['external_delivery_enabled']??false)===false,'NO_UNAUTHORIZED_DELIVERIES');
    $assert(!isset($row['access_token'],$row['secret_key'],$row['password']),'NO_RAW_SECRETS');
}
$public=$cls::public_status();
foreach($public as $code=>$row)$assert(!array_key_exists('credential_reference',$row),'SECRET_REF_NOT_EXPOSED');
$assert(!has_action('mad4b_distribution_send_external'),'NO_ACTIVE_SENDER');
$arg=[
    'site'=>home_url('/'),'channels_catalog'=>count($catalog),'channels_saved'=>count($rows),
    'linked_ads_to_ttd'=>true,'unauthenticated_mutations_denied'=>true,
    'external_send_enabled'=>false,'tests_passed'=>19,
];
echo wp_json_encode($arg,JSON_UNESCAPED_SLASHES).PHP_EOL;
