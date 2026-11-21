<?php
/**
 * Hermetic, no WordPress DB or network, test source-owned public request router.
 * Run: php tests/public-document-kernel-hermetic.php
 */
define('ABSPATH','/tmp/');
require_once __DIR__.'/../includes/class-mad4b-scp-public-document-kernel.php';

$host = 'example-tour-company.invalid';
$base = array(
    'REQUEST_METHOD' => 'GET',
    'HTTP_HOST' => $host,
    'HTTPS' => 'on',
    'REQUEST_URI' => '/journeys/verified-tour-123/',
    'QUERY_STRING' => '',
);
function check_public_case( $case, $server, $get, $post, $cookies, $env, $mode,
    $prefixes, $host, $admin, $cli, $ajax, $cron, $expected ) {
    $r = MAD4B_SCP_Public_Document_Kernel::classify(
        $server,$get,$post,$cookies,$env,$mode,$prefixes,$host,$admin,$cli,$ajax,$cron);
    if ((bool)$r['eligible'] !== $expected) {
        fwrite(STDERR, 'FAIL '.$case.' '.json_encode($r)."\n");
        exit(1);
    }
}
$count=0;
$cases = array(
    'accept_trusted_public_tour' => array($base,array(),array(),array(),'production','on',array('journeys'),$host,false,false,false,false,true),
    'accept_spanish' => array(array_merge($base,array('REQUEST_URI'=>'/es/journeys/circuito-piramides/')),array(),array(),array(),'production','on',array('journeys'),$host,false,false,false,false,true),
    'accept_staging_canary' => array(array_merge($base,array('HTTP_X_MAD4B_SCP_PUBLIC_CANARY'=>'v1')),array(),array(),array(),'staging','canary',array('journeys'),$host,false,false,false,false,true),
    'reject_default_off' => array($base,array(),array(),array(),'production','off',array('journeys'),$host,false,false,false,false,false),
    'reject_canary_production' => array($base,array(),array(),array(),'production','canary',array('journeys'),$host,false,false,false,false,false),
    'reject_unset_canary_header' => array($base,array(),array(),array(),'staging','canary',array('journeys'),$host,false,false,false,false,false),
    'reject_unknown_route_prefix' => array($base,array(),array(),array(),'production','on',array('experiences'),$host,false,false,false,false,false),
    'reject_empty_prefix_config' => array($base,array(),array(),array(),'production','on',array(),$host,false,false,false,false,false),
    'reject_malformed_prefix_config' => array($base,array(),array(),array(),'production','on',array('Journeys'),$host,false,false,false,false,false),
    'reject_malformed_nested_prefix' => array($base,array(),array(),array(),'production','on',array('journeys','a/b'),$host,false,false,false,false,false),
    'reject_wrong_host' => array($base,array(),array(),array(),'production','on',array('journeys'),'other.invalid',false,false,false,false,false),
    'reject_host_suffix_spoof' => array(array_merge($base,array('HTTP_HOST'=>'example-tour-company.invalid.evil.test')),array(),array(),array(),'production','on',array('journeys'),$host,false,false,false,false,false),
    'reject_query_string' => array(array_merge($base,array('QUERY_STRING'=>'price=1','REQUEST_URI'=>'/journeys/verified-tour-123/?price=1')),array('price'=>'1'),array(),array(),'production','on',array('journeys'),$host,false,false,false,false,false),
    'reject_query_without_parse' => array(array_merge($base,array('QUERY_STRING'=>'x=1')),array(),array(),array(),'production','on',array('journeys'),$host,false,false,false,false,false),
    'reject_cookie_raw' => array(array_merge($base,array('HTTP_COOKIE'=>'wordpress_logged_in_x=secret')),array(),array(),array(),'production','on',array('journeys'),$host,false,false,false,false,false),
    'reject_cookie_decoded' => array($base,array(),array(),array('woocommerce_cart_hash'=>'test'),'production','on',array('journeys'),$host,false,false,false,false,false),
    'reject_user_auth' => array(array_merge($base,array('HTTP_AUTHORIZATION'=>'Bearer example')),array(),array(),array(),'production','on',array('journeys'),$host,false,false,false,false,false),
    'reject_login_basic' => array(array_merge($base,array('PHP_AUTH_USER'=>'admin')),array(),array(),array(),'production','on',array('journeys'),$host,false,false,false,false,false),
    'reject_nonce' => array(array_merge($base,array('HTTP_X_WP_NONCE'=>'123')),array(),array(),array(),'production','on',array('journeys'),$host,false,false,false,false,false),
    'reject_country_header' => array(array_merge($base,array('HTTP_X_COUNTRY_CODE'=>'US')),array(),array(),array(),'production','on',array('journeys'),$host,false,false,false,false,false),
    'reject_untrusted_geo_header' => array(array_merge($base,array('HTTP_X_GEOIP_COUNTRY'=>'US')),array(),array(),array(),'production','on',array('journeys'),$host,false,false,false,false,false),
    'reject_currency_header' => array(array_merge($base,array('HTTP_X_CURRENCY'=>'EGP')),array(),array(),array(),'production','on',array('journeys'),$host,false,false,false,false,false),
    'reject_speculative_prefetch' => array(array_merge($base,array('HTTP_SEC_PURPOSE'=>'prefetch')),array(),array(),array(),'production','on',array('journeys'),$host,false,false,false,false,false),
    'reject_mad4b_header' => array(array_merge($base,array('HTTP_X_MAD4B_SIGNED_QUERY_CANARY'=>'v1')),array(),array(),array(),'production','on',array('journeys'),$host,false,false,false,false,false),
    'reject_post' => array(array_merge($base,array('REQUEST_METHOD'=>'POST')),array(),array(),array(),'production','on',array('journeys'),$host,false,false,false,false,false),
    'reject_post_payload' => array($base,array(),array('payment'=>'1'),array(),'production','on',array('journeys'),$host,false,false,false,false,false),
    'reject_head' => array(array_merge($base,array('REQUEST_METHOD'=>'HEAD')),array(),array(),array(),'production','on',array('journeys'),$host,false,false,false,false,false),
    'reject_admin' => array($base,array(),array(),array(),'production','on',array('journeys'),$host,true,false,false,false,false),
    'reject_cli' => array($base,array(),array(),array(),'production','on',array('journeys'),$host,false,true,false,false,false),
    'reject_ajax' => array($base,array(),array(),array(),'production','on',array('journeys'),$host,false,false,true,false,false),
    'reject_cron' => array($base,array(),array(),array(),'production','on',array('journeys'),$host,false,false,false,true,false),
    'reject_rest' => array(array_merge($base,array('REQUEST_URI'=>'/wp-json/mad4b/v1/ability/')),array(),array(),array(),'production','on',array('journeys'),$host,false,false,false,false,false),
    'reject_admin_php' => array(array_merge($base,array('REQUEST_URI'=>'/wp-admin/plugins.php')),array(),array(),array(),'production','on',array('journeys'),$host,false,false,false,false,false),
    'reject_checkout' => array(array_merge($base,array('REQUEST_URI'=>'/checkout/')),array(),array(),array(),'production','on',array('journeys'),$host,false,false,false,false,false),
    'reject_preview' => array(array_merge($base,array('REQUEST_URI'=>'/journeys/verified-tour-123/?preview=true','QUERY_STRING'=>'preview=true')),array('preview'=>'true'),array(),array(),'production','on',array('journeys'),$host,false,false,false,false,false),
    'reject_encoded_path' => array(array_merge($base,array('REQUEST_URI'=>'/journeys/%2Fwp-admin/')),array(),array(),array(),'production','on',array('journeys'),$host,false,false,false,false,false),
    'reject_slashes' => array(array_merge($base,array('REQUEST_URI'=>'//journeys/verified-tour-123/')),array(),array(),array(),'production','on',array('journeys'),$host,false,false,false,false,false),
    'reject_fake_locale' => array(array_merge($base,array('REQUEST_URI'=>'/es-ES/journeys/verified-tour-123/')),array(),array(),array(),'production','on',array('journeys'),$host,false,false,false,false,false),
    'reject_non_tour_foreign_request' => array(array_merge($base,array('REQUEST_URI'=>'/journeys/verified-tour-123/checkout/')),array(),array(),array(),'production','on',array('journeys'),$host,false,false,false,false,false),
    'reject_disabled_environment' => array($base,array(),array(),array(),'local','on',array('journeys'),$host,false,false,false,false,false)
);
foreach ($cases as $name=>$args) {
    check_public_case($name,...$args);
    $count++;
}
echo "public document kernel admission hermetic: PASS ".$count." cases\n";
