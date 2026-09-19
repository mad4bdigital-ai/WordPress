<?php

define( 'ABSPATH', '/tmp/mad4b-context-content-completeness/' );

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

function mad4b_content_assert( $condition, $message, $context = null ) {
	if ( $condition ) return;
	fwrite( STDERR, "FAIL: {$message}\n" );
	if ( null !== $context ) fwrite( STDERR, json_encode( $context, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n" );
	exit( 1 );
}

$ref = new ReflectionClass( 'MAD4B_SCP_Google_Drive_Context' );
$record = $ref->getMethod( 'fetch_text_content_record' );
$record->setAccessible( true );

$pdf = $record->invoke(
	null,
	array(
		'id' => 'pdf-fixture',
		'mimeType' => 'application/pdf',
	)
);
mad4b_content_assert( is_array( $pdf ), 'Unsupported binary document must return bounded normalization metadata.' );
mad4b_content_assert( empty( $pdf['complete'] ), 'Unsupported binary document must never be marked complete.' );
mad4b_content_assert( 'unsupported' === $pdf['normalization_status'], 'Unsupported MIME must be explicit.', $pdf );
mad4b_content_assert( 'unsupported_mime_type' === $pdf['normalization_reason'], 'Unsupported MIME reason must be deterministic.', $pdf );

$source = file_get_contents( dirname( __DIR__ ) . '/includes/class-mad4b-scp-google-drive-context.php' );
mad4b_content_assert( false !== strpos( $source, "'limit_response_size' => self::MAX_TEXT_BYTES + 1" ), 'Provider text read must request one sentinel byte past the certified limit.' );
mad4b_content_assert( false !== strpos( $source, "'content_complete' => false" ) || false !== strpos( $source, "'complete' => false" ), 'Incomplete normalization must remain explicit.' );
mad4b_content_assert( false !== strpos( $source, 'mad4b_context_asset_content_incomplete' ), 'Incomplete provider content must fail closed for runtime Context.' );

echo "mad4b.site-control-plane.context-content-completeness.runtime.v1: PASS\n";
