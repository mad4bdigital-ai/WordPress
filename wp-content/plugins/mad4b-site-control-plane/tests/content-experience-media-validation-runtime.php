<?php
// Exercise the public normalizer with WordPress' real URL sanitizer: it admits
// relative URLs, so the contextual media contract must enforce absoluteness.
$repo = dirname( __DIR__, 4 );
define( 'ABSPATH', $repo . '/' );
function apply_filters( $name, $value ) { return $value; }
function did_action( $name ) { return 1; }
function absint( $value ) { return abs( (int) $value ); }
function get_post_type( $id ) { return 1 === $id ? 'attachment' : false; }
function wp_attachment_is_image( $id ) { return 1 === $id; }
function current_user_can( $cap, $id ) { return 1 === $id; }
class WP_Error {
 private $code;
 function __construct( $code, $message, $data = null ) { $this->code = $code; }
 function get_error_code() { return $this->code; }
}
function is_wp_error( $value ) { return $value instanceof WP_Error; }
require $repo . '/wp-includes/formatting.php';
require $repo . '/wp-includes/kses.php';
require dirname( __DIR__ ) . '/includes/class-mad4b-scp-content-experience-profiles.php';
require dirname( __DIR__ ) . '/includes/class-mad4b-scp-content-experience-media.php';
function media_check( $condition, $message ) { if ( ! $condition ) throw new RuntimeException( $message ); }
$spec = array( 'kind' => 'image_gallery_usage', 'storage' => 'items', 'max_items' => 2, 'usage_fields' => array( 'source_url', 'link_url', 'link_target', 'focal_point' ) );
function normalize_media_usage( $item ) {
 return MAD4B_SCP_Content_Experience_Media::normalize_meta_value( 'gallery_usage', array( array( 'attachment_id' => 1 ) + $item ), $GLOBALS['spec'] );
}
media_check( '/relative' === esc_url_raw( '/relative', array( 'http', 'https' ) ), 'Fixture does not exercise real WordPress relative URL behavior' );
foreach ( array( 'source_url', 'link_url' ) as $field ) {
 foreach ( array( '/relative', '//example.invalid/path', '#fragment', 'example.invalid/path', 'https://user:password@example.invalid/path', 'javascript:alert(1)' ) as $url ) {
  $result = normalize_media_usage( array( $field => $url ) );
  media_check( is_wp_error( $result ) && 'mad4b_content_experience_media_usage_url_invalid' === $result->get_error_code(), 'Contextual URL admitted relative, inferred-scheme, credential-bearing or unsafe input: ' . $field );
 }
 $result = normalize_media_usage( array( $field => 'https://example.invalid/path?item=1#details' ) );
 media_check( ! is_wp_error( $result ) && 'https://example.invalid/path?item=1#details' === $result[0][$field], 'Explicit absolute HTTP(S) media URL was damaged' );
 media_check( ! is_wp_error( normalize_media_usage( array( $field => '' ) ) ), 'Empty optional URL cannot be cleared' );
 foreach ( array( array( 'url' => 'https://example.invalid/' ), null, 'https://example.invalid/' . str_repeat( 'x', 8192 ) ) as $invalid_value ) {
  media_check( is_wp_error( normalize_media_usage( array( $field => $invalid_value ) ) ), 'Contextual URL admitted an untyped or oversized value' );
 }
}
$empty_target = normalize_media_usage( array( 'link_url' => '', 'link_target' => '_blank' ) );
media_check( is_wp_error( $empty_target ) && 'mad4b_content_experience_media_usage_link_target_without_url' === $empty_target->get_error_code(), 'Link target admitted without a usable URL' );
foreach ( array( NAN, INF, -INF, -0.1, 1.1 ) as $coordinate ) {
 media_check( is_wp_error( normalize_media_usage( array( 'focal_point' => array( 'x' => $coordinate, 'y' => 0.5 ) ) ) ), 'Focal point admitted an invalid numeric coordinate' );
}
media_check( ! is_wp_error( normalize_media_usage( array( 'focal_point' => array( 'x' => 0, 'y' => 1 ) ) ) ), 'Valid boundary focal point rejected' );
echo "mad4b.content-experience-media-validation.runtime.v1: PASS\n";
