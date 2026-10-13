<?php
/** Read-only media capture admission; no network or WordPress writes. */
define( 'ABSPATH', dirname( __DIR__ ) . '/' );
class WP_Error {
    private $code;
    public function __construct( $code, $message = '' ) { $this->code = $code; }
    public function get_error_code() { return $this->code; }
}
function is_wp_error( $v ) { return $v instanceof WP_Error; }
function sanitize_key( $v ) { return strtolower( preg_replace( '/[^a-z0-9_-]/i', '', $v ) ); }
function sanitize_file_name( $v ) { return preg_replace( '/[^A-Za-z0-9_.-]/', '-', $v ); }
function sanitize_text_field( $v ) { return (string) $v; }
function sanitize_textarea_field( $v ) { return (string) $v; }
function wp_kses_post( $v ) { return (string) $v; }
function wp_parse_url( $u, $component = -1 ) { return parse_url( $u, $component ); }
function esc_url_raw( $u, $protocols = array() ) { return $u; }
function wp_json_encode( $v, $flags = 0 ) { return json_encode( $v, $flags ); }
function get_posts( $args ) { return array(); }
function absint( $v ) { return abs( (int) $v ); }
class MAD4B_SCP_Adapter_Base {}
require dirname( __DIR__ ) . '/includes/adapters/class-mad4b-scp-remote-media-adapter.php';
function insist( $condition, $label ) {
    if ( ! $condition ) { fwrite( STDERR, 'FAIL: ' . $label . PHP_EOL ); exit( 1 ); }
}
$adapter = new MAD4B_SCP_Remote_Media_Adapter();
$base = array( 'source_url' => 'https://example.test/photos/image.jpg',
    'rights_basis' => 'unknown', 'expected_content_sha256' => str_repeat( 'a', 64 ),
    'source_page_url' => 'https://example.test/catalog/product',
    'title' => 'Image title', 'alt' => 'Image alt' );
$normal = $adapter->remote_import_plan( $base );
insist( ! is_wp_error( $normal ) &&
    in_array( 'rights_confirmation_required', $normal['blockers'], true ),
    'Ordinary media publication import must preserve its unchanged prior policy.' );
$capture = $base;
$capture['capture_only'] = true;
$allowed = $adapter->remote_import_plan( $capture );
insist( ! is_wp_error( $allowed ) && $allowed['ready'] &&
    $allowed['capture_only'] && $allowed['normalized_input']['capture_only'] &&
    !$allowed['mutation_performed'] &&
    $allowed['normalized_input']['title'] === 'Image title',
    'Capture-only may store scraped image evidence without a rights verdict.' );
$unknown_hash = $capture;
unset( $unknown_hash['expected_content_sha256'] );
$needs_inspection = $adapter->remote_import_plan( $unknown_hash );
insist( ! is_wp_error( $needs_inspection ) &&
    in_array( 'content_inspection_required', $needs_inspection['blockers'], true ),
    'Capture-only cannot bypass image inspection and digest match.' );
$licensed = $base;
$licensed['rights_basis'] = 'licensed';
$licensed['rights_reference'] = 'https://example.test/terms';
$valid = $adapter->remote_import_plan( $licensed );
insist( ! is_wp_error( $valid ) && $valid['ready'] &&
    empty( $valid['capture_only'] ), 'Existing normal licensed path stays operational.' );
echo "PASS: unknown-rights source capture only, normal import policy unchanged, remote image inspection preserved\n";
