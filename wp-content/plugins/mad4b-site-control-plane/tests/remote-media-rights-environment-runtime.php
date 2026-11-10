<?php
/** Hermetic, no-WordPress-mutation proof of remote rights policy in both environments. */
define( 'ABSPATH', '/' );
class WP_Error {
    private $code;
    public function __construct( $code, $message = '', $data = null ) { $this->code = $code; }
    public function get_error_code() { return $this->code; }
}
function is_wp_error( $value ) { return $value instanceof WP_Error; }
function wp_get_environment_type() { return $GLOBALS['ci_environment']; }
function absint( $n ) { return abs( (int) $n ); }
function sanitize_key( $text ) { return strtolower( preg_replace( '/[^a-z0-9_\-]/i', '', (string) $text ) ); }
function wp_json_encode( $value, $flags = 0 ) { return json_encode( $value, $flags ); }
function get_post_meta( $id, $key, $single = false ) {
    $values = $GLOBALS['ci_media_meta'][$id][$key] ?? array();
    return $single ? ( $values[0] ?? '' ) : $values;
}
function verify( $ok, $message ) {
    if ( ! $ok ) { fwrite( STDERR, 'FAIL: ' . $message . PHP_EOL ); exit( 1 ); }
}
require dirname( __DIR__ ) . '/includes/class-mad4b-scp-remote-media-rights.php';
$id = 29;
$source_hash = hash( 'sha256', 'https://images.example.invalid/fixture.jpg' );
$content_hash = str_repeat( 'a', 64 );
$event = array(
    'contract' => 'mad4b.remote-media-provenance.v1',
    'source_url_sha256' => $source_hash,
    'content_sha256' => $content_hash,
    'rights_basis' => 'permission',
    'rights_note' => 'CI permission evidence',
    'rights_reference' => '',
    'license_expires_on' => '2099-12-31',
);
$GLOBALS['ci_media_meta'][$id] = array(
    '_mad4b_remote_media_source_sha256' => array( $source_hash ),
    '_mad4b_remote_media_content_sha256' => array( $content_hash ),
    '_mad4b_remote_media_provenance' => array( $event ),
);
$today = '2026-10-06';
$GLOBALS['ci_environment'] = 'production';
$valid = MAD4B_SCP_Remote_Media_Rights::publish_guard( $id, array(), array(), $today );
verify( ! is_wp_error( $valid ) && 'enforced' === $valid['license_mode'] && 0 === $valid['license_warning_count'],
    'Production valid licensed image not accepted' );
$GLOBALS['ci_media_meta'][$id]['_mad4b_remote_media_provenance'][0]['license_expires_on'] = '2020-01-01';
$expired = MAD4B_SCP_Remote_Media_Rights::publish_guard( $id, array(), array(), $today );
verify( is_wp_error( $expired ) && 'mad4b_remote_media_publish_rights_expired' === $expired->get_error_code(),
    'Production accepted expired image rights' );
$GLOBALS['ci_environment'] = 'staging';
$advisory = MAD4B_SCP_Remote_Media_Rights::publish_guard( $id, array(), array(), $today );
verify( ! is_wp_error( $advisory ) && 'advisory' === $advisory['license_mode']
    && in_array( 'license_expired_or_invalid', array_column( $advisory['license_warnings'], 'code' ), true ),
    'Staging failed to report expired license warning' );
$GLOBALS['ci_media_meta'][$id]['_mad4b_remote_media_source_sha256'] = array( str_repeat( 'b', 64 ) );
$drift = MAD4B_SCP_Remote_Media_Rights::publish_guard( $id, array(), array(), $today );
verify( is_wp_error( $drift ) && 'mad4b_remote_media_publish_source_identity_drift' === $drift->get_error_code(),
    'Staging accepted remote-image source provenance drift' );
$GLOBALS['ci_environment'] = 'production';
$drift = MAD4B_SCP_Remote_Media_Rights::publish_guard( $id, array(), array(), $today );
verify( is_wp_error( $drift ) && 'mad4b_remote_media_publish_source_identity_drift' === $drift->get_error_code(),
    'Production accepted remote-image source provenance drift' );
$GLOBALS['ci_media_meta'][$id]['_mad4b_remote_media_source_sha256'] = array( $source_hash );
$GLOBALS['ci_media_meta'][$id]['_mad4b_remote_media_provenance'][0]['rights_note'] = '';
$missing = MAD4B_SCP_Remote_Media_Rights::publish_guard( $id, array(), array(), $today );
verify( is_wp_error( $missing ) && 'mad4b_remote_media_publish_rights_evidence_missing' === $missing->get_error_code(),
    'Production accepted missing permission evidence' );
echo "REMOTE_MEDIA_RIGHTS_ENVIRONMENT: PASS (Production enforced, Staging advisory, both provenance fail-closed)\n";
