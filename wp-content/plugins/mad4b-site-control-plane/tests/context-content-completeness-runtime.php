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
function sanitize_key( $value ) { return strtolower( preg_replace( '/[^a-z0-9_\-]/i', '', (string) $value ) ); }
function sanitize_text_field( $value ) { return trim( strip_tags( (string) $value ) ); }
function wp_check_invalid_utf8( $value, $strip = false ) { return (string) $value; }

require dirname( __DIR__ ) . '/includes/class-mad4b-scp-google-drive-context.php';

function mad4b_content_assert( $condition, $message, $context = null ) {
	if ( $condition ) return;
	fwrite( STDERR, "FAIL: {$message}\n" );
	if ( null !== $context ) fwrite( STDERR, json_encode( $context, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n" );
	exit( 1 );
}

$ref = new ReflectionClass( 'MAD4B_SCP_Google_Drive_Context' );
$pdf_method = $ref->getMethod( 'normalize_pdf' );
$pdf_method->setAccessible( true );
$binary_method = $ref->getMethod( 'normalize_binary_content' );
$binary_method->setAccessible( true );

$text_stream = 'BT /F1 12 Tf (Brand Strategy) Tj ET';
$text_pdf = "%PDF-1.4\n1 0 obj << /Length " . strlen( $text_stream ) . " >>\nstream\n" . $text_stream . "\nendstream\nendobj\n%%EOF";
$pdf = $pdf_method->invoke( null, $text_pdf );
mad4b_content_assert( is_array( $pdf ) && ! empty( $pdf['complete'] ), 'Text PDF must normalize completely without provider mutation.', $pdf );
mad4b_content_assert( false !== strpos( $pdf['content'], 'Brand Strategy' ), 'Text PDF normalization must retain visible copy.', $pdf );

$scanned_pdf = "%PDF-1.4\n1 0 obj << /Filter /DCTDecode /Length 4 >>\nstream\nABCD\nendstream\nendobj\n%%EOF";
$pdf = $pdf_method->invoke( null, $scanned_pdf );
mad4b_content_assert( is_array( $pdf ) && empty( $pdf['complete'] ), 'Scanned PDF must remain incomplete before OCR.', $pdf );
mad4b_content_assert( 'extractor_required' === $pdf['normalization_status'], 'Scanned PDF must explicitly route to governed OCR.', $pdf );
mad4b_content_assert( 'pdf_ocr_required' === $pdf['normalization_reason'], 'Scanned PDF OCR blocker must be deterministic.', $pdf );

$opaque = $binary_method->invoke( null, 'application/octet-stream', "\x00\x01opaque", 'opaque.bin' );
mad4b_content_assert( is_array( $opaque ) && empty( $opaque['complete'] ), 'Opaque binary content must never be falsely marked complete.', $opaque );
mad4b_content_assert( 'extractor_required' === $opaque['normalization_status'], 'Opaque binary content must explicitly require a governed extractor.', $opaque );

$source = file_get_contents( dirname( __DIR__ ) . '/includes/class-mad4b-scp-google-drive-context.php' );
mad4b_content_assert( false !== strpos( $source, "'limit_response_size' => (int) $max_bytes + 1" ), 'All provider reads must retain a bounded sentinel byte.' );
mad4b_content_assert( false !== strpos( $source, '$multimodal' ), 'Incomplete PDF/image/audio/video normalization must route through the governed multimodal fallback.' );
mad4b_content_assert( false !== strpos( $source, 'external_extractor_record( $file, $binary, $local )' ), 'Governed extractor fallback must remain explicit.' );
mad4b_content_assert( false !== strpos( $source, 'mad4b_context_asset_content_incomplete' ), 'Incomplete provider content must fail closed for runtime Context.' );

echo "mad4b.site-control-plane.context-content-completeness.runtime.v4: PASS\n";
