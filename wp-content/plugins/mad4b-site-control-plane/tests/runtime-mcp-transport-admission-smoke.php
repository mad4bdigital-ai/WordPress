<?php
/**
 * Runtime proof that MAD4B preserves transport admission diagnostics before the
 * official MCP Adapter converts permission callback WP_Error values to false.
 */

if ( ! defined( 'ABSPATH' ) ) {
	throw new RuntimeException( 'WordPress is not loaded.' );
}

$fail = static function ( $message, $data = null ) {
	throw new RuntimeException(
		$message . ( null !== $data ? ' ' . wp_json_encode( $data, JSON_UNESCAPED_SLASHES ) : '' )
	);
};

foreach ( array( 'MAD4B_SCP_MCP_Transport_Admission', 'MAD4B_SCP_Transport_Context', 'MAD4B_SCP_Servers' ) as $class ) {
	if ( ! class_exists( $class ) ) $fail( 'Required transport diagnostic class is unavailable.', $class );
}

MAD4B_SCP_MCP_Transport_Admission::boot();
$status = MAD4B_SCP_MCP_Transport_Admission::status();
if ( empty( $status['booted'] ) || empty( $status['preserves_permission_wp_error'] ) || ! empty( $status['creates_authority'] ) ) {
	$fail( 'Transport admission status contract is invalid.', $status );
}

$admin_user_id = get_current_user_id();
if ( $admin_user_id < 1 || ! current_user_can( 'manage_options' ) ) {
	$fail( 'Transport admission smoke requires the fixture administrator.' );
}

// Trailing slash must bind to the same exact governed transport.
$trailing = new WP_REST_Request( 'POST', '/mcp/mad4b-chatgpt/' );
$bound = MAD4B_SCP_Transport_Context::bind( 'mad4b-chatgpt', $trailing );
if ( true !== $bound ) {
	$fail( 'Canonical trailing-slash MCP route did not bind.', is_wp_error( $bound ) ? $bound->get_error_code() : $bound );
}
MAD4B_SCP_Transport_Context::clear();

// Prove the pre-permission bridge preserves a bounded MAD4B denial instead of
// allowing MCP Adapter to collapse it to false/rest_forbidden.
wp_set_current_user( 0 );
$trailing->set_header( 'Authorization', 'Bearer diagnostic-secret-must-not-leak' );
$denied = MAD4B_SCP_MCP_Transport_Admission::preserve_permission_error( null, array(), $trailing );
if ( ! is_wp_error( $denied ) ) $fail( 'Anonymous ChatGPT transport admission did not fail closed.', $denied );
if ( 'mad4b_transport_read_permission_denied' !== $denied->get_error_code() ) {
	$fail( 'Transport admission did not preserve the original MAD4B error code.', $denied->get_error_code() );
}
$data = $denied->get_error_data();
if (
	! is_array( $data )
	|| 'mad4b.mcp-transport-admission.v1' !== ( isset( $data['contract'] ) ? (string) $data['contract'] : '' )
	|| 'read_permission' !== ( isset( $data['stage'] ) ? (string) $data['stage'] : '' )
	|| 'read_capability_not_satisfied' !== ( isset( $data['blocker'] ) ? (string) $data['blocker'] : '' )
	|| 'mad4b-chatgpt' !== ( isset( $data['server_id'] ) ? (string) $data['server_id'] : '' )
	|| empty( $data['diagnostic_safe'] )
) {
	$fail( 'Transport admission denial lost bounded diagnostic evidence.', $data );
}
if ( false !== strpos( wp_json_encode( $data ), 'diagnostic-secret-must-not-leak' ) ) {
	$fail( 'Transport admission diagnostic leaked bearer material.' );
}

$response = rest_convert_error_to_response( $denied );
$response = MAD4B_SCP_MCP_Transport_Admission::annotate_response( $response, rest_get_server(), $trailing );
$headers = $response instanceof WP_REST_Response ? $response->get_headers() : array();
if (
	'denied' !== ( isset( $headers['X-MAD4B-MCP-Admission'] ) ? (string) $headers['X-MAD4B-MCP-Admission'] : '' )
	|| 'read_permission' !== ( isset( $headers['X-MAD4B-MCP-Admission-Stage'] ) ? (string) $headers['X-MAD4B-MCP-Admission-Stage'] : '' )
	|| 'mad4b_transport_read_permission_denied' !== ( isset( $headers['X-MAD4B-MCP-Admission-Code'] ) ? (string) $headers['X-MAD4B-MCP-Admission-Code'] : '' )
) {
	$fail( 'Transport admission response headers do not expose bounded denial stage/code.', $headers );
}

// Prove the catalog fail-closed gate is preserved as its own stage rather than
// becoming a generic adapter permission denial.
wp_set_current_user( $admin_user_id );
$adapter = class_exists( '\\WP\\MCP\\Core\\McpAdapter' ) ? \\WP\\MCP\\Core\\McpAdapter::instance() : null;
if ( ! is_object( $adapter ) || ! is_object( $adapter->get_server( 'mad4b-chatgpt' ) ) ) {
	$fail( 'Official ChatGPT MCP server is unavailable for catalog admission proof.' );
}
$reflect = new ReflectionClass( 'MAD4B_SCP_Servers' );
$property = $reflect->getProperty( 'registrations' );
$property->setAccessible( true );
$registrations = $property->getValue();
if ( empty( $registrations['mad4b-chatgpt'] ) || ! is_array( $registrations['mad4b-chatgpt'] ) ) {
	$fail( 'ChatGPT registration evidence is unavailable.' );
}
$original = $registrations;
$registrations['mad4b-chatgpt']['catalog_evidence'] = array(
	'ready' => false,
	'blocker' => 'mcp_catalog_projection_mismatch',
);
$property->setValue( null, $registrations );

$request = new WP_REST_Request( 'POST', '/mcp/mad4b-chatgpt' );
$catalog_denied = MAD4B_SCP_MCP_Transport_Admission::preserve_permission_error( null, array(), $request );
$property->setValue( null, $original );
MAD4B_SCP_Transport_Context::clear();

if ( ! is_wp_error( $catalog_denied ) || 'mad4b_mcp_catalog_not_ready' !== $catalog_denied->get_error_code() ) {
	$fail( 'Catalog admission failure was not preserved.', is_wp_error( $catalog_denied ) ? $catalog_denied->get_error_code() : $catalog_denied );
}
$catalog_data = $catalog_denied->get_error_data();
if (
	! is_array( $catalog_data )
	|| 'catalog_admission' !== ( isset( $catalog_data['stage'] ) ? (string) $catalog_data['stage'] : '' )
	|| 'mcp_catalog_projection_mismatch' !== ( isset( $catalog_data['blocker'] ) ? (string) $catalog_data['blocker'] : '' )
	|| 503 !== (int) ( isset( $catalog_data['status'] ) ? $catalog_data['status'] : 0 )
) {
	$fail( 'Catalog admission diagnostic evidence is incomplete.', $catalog_data );
}

// Unrelated REST requests must remain untouched.
$foreign = new WP_REST_Request( 'GET', '/wp/v2/types' );
$sentinel = array( 'unchanged' => true );
$foreign_result = MAD4B_SCP_MCP_Transport_Admission::preserve_permission_error( $sentinel, array(), $foreign );
if ( $sentinel !== $foreign_result ) $fail( 'Transport admission bridge touched a foreign REST route.' );

wp_set_current_user( $admin_user_id );
MAD4B_SCP_Transport_Context::clear();

echo "mad4b.site-control-plane.mcp-transport-admission.v1: PASS\n";
