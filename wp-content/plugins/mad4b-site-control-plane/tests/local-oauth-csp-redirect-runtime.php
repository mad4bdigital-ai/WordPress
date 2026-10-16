<?php
/** Cookie OAuth consent CSP: an exact registered redirect origin only. */
if ( ! defined( 'ABSPATH' ) ) define( 'ABSPATH', __DIR__ . '/' );
function wp_parse_url( $u ) { return parse_url( $u ); }
require dirname(__DIR__) . '/includes/class-mad4b-scp-local-oauth-server.php';
function csp_verify( $ok, $why ) { if ( ! $ok ) { fwrite( STDERR, "FAIL: " . $why . PHP_EOL ); exit(1); } }
$m = new ReflectionMethod( 'MAD4B_SCP_Local_OAuth_Server', 'consent_form_action_sources' );
$m->setAccessible( true );
$trusted = array( 'client' => array(
    'redirect_uris' => array( 'https://chatgpt.com/connector_platform_oauth_redirect' ) ),
    'redirect_uri' => 'https://chatgpt.com/connector_platform_oauth_redirect' );
csp_verify( "'self' https://chatgpt.com" === $m->invoke( null, $trusted ),
    'registered ChatGPT 302 callback blocked by CSP' );
csp_verify( "'self'" === $m->invoke( null, array( 'redirect_uri' => $trusted['redirect_uri'] ) ),
    'missing verified client was accepted' );
$changed = $trusted;
$changed['redirect_uri'] = 'https://untrusted.example/redirect';
csp_verify( "'self'" === $m->invoke( null, $changed ), 'unregistered origin allowed' );
$changed['redirect_uri'] = 'https://chatgpt.com/other-path';
csp_verify( "'self'" === $m->invoke( null, $changed ), 'redirect path was not exact' );
$registered_second = array( 'client' => array( 'redirect_uris' => array( 'https://app.example.net/callback' ) ),
    'redirect_uri' => 'https://app.example.net/callback' );
csp_verify( "'self' https://app.example.net" === $m->invoke( null, $registered_second ),
    'other exact registered HTTPS client cannot complete OAuth' );
$unsafe = array( 'client' => array( 'redirect_uris' => array( "https://good.example\r\nform-action *" ) ),
    'redirect_uri' => "https://good.example\r\nform-action *" );
csp_verify( "'self'" === $m->invoke( null, $unsafe ), 'CSP header injection accepted' );
$port = array( 'client' => array( 'redirect_uris' => array( 'https://registered.example:8443/callback' ) ),
    'redirect_uri' => 'https://registered.example:8443/callback' );
csp_verify( "'self' https://registered.example:8443" === $m->invoke( null, $port ), 'registered HTTPS port omitted' );
$local = array( 'client' => array( 'redirect_uris' => array( 'http://127.0.0.1:43859/oauth/callback' ) ),
    'redirect_uri' => 'http://127.0.0.1:43859/oauth/callback' );
csp_verify( "'self' http://127.0.0.1:43859" === $m->invoke( null, $local ), 'pre-registered loopback denied' );
$insecure = array( 'client' => array( 'redirect_uris' => array( 'http://public.example/callback' ) ),
    'redirect_uri' => 'http://public.example/callback' );
csp_verify( "'self'" === $m->invoke( null, $insecure ), 'non-loopback HTTP allowed' );
$source = file_get_contents( dirname(__DIR__) . '/includes/class-mad4b-scp-local-oauth-server.php' );
csp_verify( false !== strpos( $source, 'form-action " . $consent_form_action' ),
    'OAuth consent does not use registered redirect-origin CSP' );
echo "LOCAL_OAUTH_CSP_REDIRECT_RUNTIME_PASS" . PHP_EOL;
