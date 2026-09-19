<?php

define( 'ABSPATH', '/tmp/mad4b-context-recreate-absence/' );

class WP_Error {
	private $code;
	private $message;
	private $data;
	public function __construct( $code, $message = '', $data = null ) { $this->code = (string) $code; $this->message = (string) $message; $this->data = $data; }
	public function get_error_code() { return $this->code; }
	public function get_error_message() { return $this->message; }
	public function get_error_data() { return $this->data; }
}
function is_wp_error( $value ) { return $value instanceof WP_Error; }

require dirname( __DIR__ ) . '/includes/class-mad4b-scp-google-drive-context.php';

function mad4b_absence_assert( $condition, $message, $context = null ) {
	if ( $condition ) return;
	fwrite( STDERR, "FAIL: {$message}\n" );
	if ( null !== $context ) fwrite( STDERR, json_encode( $context, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n" );
	exit( 1 );
}

$ref = new ReflectionClass( 'MAD4B_SCP_Google_Drive_Context' );
$method = $ref->getMethod( 'provider_absence_from_metadata_result' );
$method->setAccessible( true );

$exists = $method->invoke( null, array( 'id' => 'original-file', 'name' => 'Restored Asset' ) );
mad4b_absence_assert( is_wp_error( $exists ), 'Existing provider metadata must deny recreation.' );
mad4b_absence_assert( 'mad4b_google_drive_recreate_original_restored' === $exists->get_error_code(), 'Existing original must surface original-restored blocker.', $exists->get_error_code() );

$missing = $method->invoke( null, new WP_Error( 'mad4b_google_drive_api_failed', 'Not found', array( 'status' => 404, 'provider_code' => 'not_found' ) ) );
mad4b_absence_assert( true === $missing, 'Only provider-confirmed HTTP 404 may prove original absence.', $missing );

$forbidden = $method->invoke( null, new WP_Error( 'mad4b_google_drive_api_failed', 'Forbidden', array( 'status' => 403, 'provider_code' => 'permission_denied' ) ) );
mad4b_absence_assert( is_wp_error( $forbidden ), 'HTTP 403 must never be interpreted as missing.' );
mad4b_absence_assert( 'mad4b_google_drive_recreate_absence_unverified' === $forbidden->get_error_code(), '403 must surface absence-unverified blocker.', $forbidden->get_error_code() );
$data = $forbidden->get_error_data();
mad4b_absence_assert( is_array( $data ) && 403 === (int) $data['http_status'], 'Absence-unverified diagnostic must preserve bounded HTTP status.', $data );

$server_error = $method->invoke( null, new WP_Error( 'mad4b_google_drive_api_failed', 'Server error', array( 'status' => 500 ) ) );
mad4b_absence_assert( is_wp_error( $server_error ) && 'mad4b_google_drive_recreate_absence_unverified' === $server_error->get_error_code(), 'Provider 5xx must fail closed.', $server_error );

echo "mad4b.site-control-plane.context-recreate-provider-absence.runtime.v1: PASS\n";
