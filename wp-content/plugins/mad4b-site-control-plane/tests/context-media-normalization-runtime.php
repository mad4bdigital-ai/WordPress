<?php
/** Pure PHP regression; does not mutate WordPress, registries or media records. */
define( 'ABSPATH', __DIR__ . '/' );
class WP_Error {
    private $code;
    public function __construct( $code, $message = '' ) { $this->code = $code; }
    public function get_error_code() { return $this->code; }
}
function is_wp_error( $value ) { return $value instanceof WP_Error; }
function add_action( $name, $callback ) { return true; }
function sanitize_key( $value ) { return strtolower( preg_replace( '/[^a-z0-9_\\-]/', '', (string) $value ) ); }
function wp_check_invalid_utf8( $value, $strip = false ) {
    if ( preg_match( '//u', (string) $value ) ) return (string) $value;
    return $strip ? iconv( 'UTF-8', 'UTF-8//IGNORE', (string) $value ) : '';
}
require_once __DIR__ . '/../includes/class-mad4b-scp-google-drive-context.php';
require_once __DIR__ . '/../includes/class-mad4b-scp-wordpress-media-context.php';
$passed = 0;
$assert = static function ( $name, $good ) use ( &$passed ) {
    echo $name . ': ' . ( $good ? 'PASS' : 'FAIL' ) . "\n";
    if ( ! $good ) exit( 1 );
    ++$passed;
};
$txt = MAD4B_SCP_WordPress_Media_Context::normalize_bytes( 'text/plain', "Brand strategy\nTone" );
$assert( 'new_txt_normalized', is_array( $txt ) && true === $txt['content_complete'] && $txt['normalized_text'] === "Brand strategy\nTone" );
$md = MAD4B_SCP_WordPress_Media_Context::normalize_bytes( 'text/markdown', "# Editorial guidelines" );
$assert( 'new_markdown_normalized', is_array( $md ) && true === $md['content_complete'] );
$assert( 'empty_text_fail_closed', is_wp_error( MAD4B_SCP_WordPress_Media_Context::normalize_bytes( 'text/plain', '' ) ) );
$assert( 'binary_as_text_denied', is_wp_error( MAD4B_SCP_WordPress_Media_Context::normalize_bytes( 'text/plain', "a\x00b" ) ) );
$assert( 'invalid_utf8_denied', is_wp_error( MAD4B_SCP_WordPress_Media_Context::normalize_bytes( 'text/plain', "\xff" ) ) );
$assert( 'oversize_text_denied', is_wp_error( MAD4B_SCP_WordPress_Media_Context::normalize_bytes( 'text/plain', str_repeat( 'a', 262145 ) ) ) );
$pdf = "%PDF-1.4\n1 0 obj << /Length 34 >>\nstream\nBT (Brand strategy PDF) Tj ET\nendstream\nendobj\n%%EOF";
$normalized = MAD4B_SCP_WordPress_Media_Context::normalize_bytes( 'application/pdf', $pdf );
$assert( 'text_pdf_normalized', is_array( $normalized ) && true === $normalized['content_complete'] && strpos( $normalized['normalized_text'], 'Brand strategy PDF' ) !== false );
$assert( 'invalid_pdf_denied', is_wp_error( MAD4B_SCP_WordPress_Media_Context::normalize_bytes( 'application/pdf', 'not a PDF' ) ) );
$assert( 'ocr_pdf_not_auto_approved', is_wp_error( MAD4B_SCP_WordPress_Media_Context::normalize_bytes( 'application/pdf', "%PDF-1.4\n%%EOF" ) ) );
$assert( 'unsupported_mime_denied', is_wp_error( MAD4B_SCP_WordPress_Media_Context::normalize_bytes( 'image/png', 'fake-image' ) ) );
echo "SUMMARY pass=$passed fail=0\n";
