<?php

define( 'ABSPATH', '/srv/wordpress/' );

function wp_unslash( $value ) { return $value; }
function wp_parse_url( $url, $component = -1 ) { return parse_url( $url, $component ); }
function rest_get_url_prefix() { return 'wp-json'; }
function is_admin() { return false; }
function current_user_can( $cap ) { return true; }
function sanitize_key( $value ) { return strtolower( preg_replace( '/[^a-z0-9_\-]/i', '', (string) $value ) ); }

require dirname( __DIR__ ) . '/includes/class-mad4b-scp-provider-diagnostic-policy.php';

function mad4b_zero_touch_assert( $condition, $message ) {
	if ( ! $condition ) {
		fwrite( STDERR, "FAIL: {$message}\n" );
		exit( 1 );
	}
}

$_GET = array();
$_SERVER['REQUEST_URI'] = '/wp-json/wp/v2/types/post?context=edit';
mad4b_zero_touch_assert( MAD4B_SCP_Provider_Diagnostic_Policy::current_request_is_foreign_rest(), 'Core Site Health REST route must be foreign REST.' );
mad4b_zero_touch_assert( MAD4B_SCP_Provider_Diagnostic_Policy::current_request_is_zero_touch_surface(), 'Core Site Health REST route must be zero-touch.' );
mad4b_zero_touch_assert( 'foreign_rest' === MAD4B_SCP_Provider_Diagnostic_Policy::zero_touch_reason(), 'Core REST zero-touch reason mismatch.' );

$_SERVER['REQUEST_URI'] = '/wp-json/wpml/v1/rest/status?test_get_parameter=1';
mad4b_zero_touch_assert( MAD4B_SCP_Provider_Diagnostic_Policy::current_request_is_foreign_rest(), 'WPML REST route must be foreign REST.' );
mad4b_zero_touch_assert( MAD4B_SCP_Provider_Diagnostic_Policy::current_request_is_zero_touch_surface(), 'WPML REST route must be zero-touch.' );

$_SERVER['REQUEST_URI'] = '/wp-cron.php?doing_wp_cron=123.456';
mad4b_zero_touch_assert( MAD4B_SCP_Provider_Diagnostic_Policy::current_request_is_wordpress_cron(), 'wp-cron.php loopback must be classified as WordPress cron.' );
mad4b_zero_touch_assert( MAD4B_SCP_Provider_Diagnostic_Policy::current_request_is_zero_touch_surface(), 'wp-cron.php loopback must be zero-touch.' );
mad4b_zero_touch_assert( 'wordpress_cron' === MAD4B_SCP_Provider_Diagnostic_Policy::zero_touch_reason(), 'Cron zero-touch reason mismatch.' );

$_SERVER['REQUEST_URI'] = '/wp-json/mcp/mad4b-read';
mad4b_zero_touch_assert( ! MAD4B_SCP_Provider_Diagnostic_Policy::current_request_is_foreign_rest(), 'MAD4B MCP route must not be treated as foreign REST.' );
mad4b_zero_touch_assert( ! MAD4B_SCP_Provider_Diagnostic_Policy::current_request_is_zero_touch_surface(), 'MAD4B MCP route must retain its own protocol lifecycle.' );

$_SERVER['REQUEST_URI'] = '/wp-json/mad4b/v1/oauth-protected-resource';
mad4b_zero_touch_assert( ! MAD4B_SCP_Provider_Diagnostic_Policy::current_request_is_foreign_rest(), 'MAD4B OAuth resource route must not be treated as foreign REST.' );

$_SERVER['REQUEST_URI'] = '/about/';
mad4b_zero_touch_assert( ! MAD4B_SCP_Provider_Diagnostic_Policy::current_request_is_zero_touch_surface(), 'Normal frontend traffic must remain eligible for bounded acceptance sampling.' );

echo "mad4b.request-serving-zero-touch.runtime.v1: PASS\n";
