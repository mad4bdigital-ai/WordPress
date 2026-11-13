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
// Hermetic native WordPress option/filter stubs. Source policy, not a
// hard-coded environment label, determines the advisory/enforced mode.
function get_option( $key, $fallback = false ) {
    return isset( $GLOBALS['ci_options'][$key] ) ? $GLOBALS['ci_options'][$key] : $fallback;
}
function apply_filters( $hook, $value ) {
    return isset( $GLOBALS['ci_filters'][$hook] ) ? $GLOBALS['ci_filters'][$hook] : $value;
}

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
verify( ! is_wp_error( $valid ) && 'advisory' === $valid['license_mode']
    && 0 === $valid['license_warning_count'], 'Production neutral default was not advisory' );

// Policy is explicit, scoped to the site, and must not be changed implicitly
// just because the WordPress environment is labeled Production or Staging.
$GLOBALS['ci_options']['mad4b_remote_media_rights_mode'] = 'enforced';
$enforced = MAD4B_SCP_Remote_Media_Rights::publish_guard( $id, array(), array(), $today );
verify( ! is_wp_error( $enforced ) && 'enforced' === $enforced['license_mode']
    && 0 === $enforced['license_warning_count'], 'Enforced licensed image rejected' );
$GLOBALS['ci_environment'] = 'staging';
$enforced_stage = MAD4B_SCP_Remote_Media_Rights::publish_guard( $id, array(), array(), $today );
verify( ! is_wp_error( $enforced_stage ) && 'enforced' === $enforced_stage['license_mode'],
    'Enforced mode was incorrectly limited to Production' );

// An explicit filter can configure stricter policy without opening an
// environment-specific backdoor or bypassing content/provenance identity.
unset( $GLOBALS['ci_options']['mad4b_remote_media_rights_mode'] );
$GLOBALS['ci_filters']['mad4b_remote_media_rights_mode'] = 'enforced';
$filtered = MAD4B_SCP_Remote_Media_Rights::publish_guard( $id, array(), array(), $today );
verify( ! is_wp_error( $filtered ) && 'enforced' === $filtered['license_mode'],
    'Explicit native rights filter not respected' );
unset( $GLOBALS['ci_filters']['mad4b_remote_media_rights_mode'] );
$GLOBALS['ci_options']['mad4b_remote_media_rights_mode'] = 'invalid';
$invalid_policy = MAD4B_SCP_Remote_Media_Rights::publish_guard( $id, array(), array(), $today );
verify( is_wp_error( $invalid_policy )
    && 'mad4b_remote_media_rights_policy_invalid' === $invalid_policy->get_error_code(),
    'Unreviewed rights mode admitted' );
unset( $GLOBALS['ci_options']['mad4b_remote_media_rights_mode'] );

$GLOBALS['ci_media_meta'][$id]['_mad4b_remote_media_provenance'][0]['license_expires_on'] = '2020-01-01';
foreach ( array( 'production', 'staging' ) as $env ) {
    $GLOBALS['ci_environment'] = $env;
    $expired = MAD4B_SCP_Remote_Media_Rights::publish_guard( $id, array(), array(), $today );
    verify( is_wp_error( $expired )
        && 'mad4b_remote_media_publish_rights_expired' === $expired->get_error_code(),
        'An explicitly expired media license escaped in ' . $env );
}
$GLOBALS['ci_media_meta'][$id]['_mad4b_remote_media_provenance'][0]['license_expires_on'] = '2099-12-31';
$GLOBALS['ci_media_meta'][$id]['_mad4b_remote_media_provenance'][0]['rights_note'] = '';
$advisory = MAD4B_SCP_Remote_Media_Rights::publish_guard( $id, array(), array(), $today );
verify( ! is_wp_error( $advisory ) && 'advisory' === $advisory['license_mode']
    && in_array( 'rights_evidence_missing', array_column( $advisory['license_warnings'], 'code' ), true ),
    'Missing permission evidence was not flagged in advisory mode' );
$GLOBALS['ci_options']['mad4b_remote_media_rights_mode'] = 'enforced';
$missing = MAD4B_SCP_Remote_Media_Rights::publish_guard( $id, array(), array(), $today );
verify( is_wp_error( $missing ) && 'mad4b_remote_media_publish_rights_evidence_missing' === $missing->get_error_code(),
    'Enforced mode accepted missing permission evidence' );
unset( $GLOBALS['ci_options']['mad4b_remote_media_rights_mode'] );

$GLOBALS['ci_media_meta'][$id]['_mad4b_remote_media_source_sha256'] = array( str_repeat( 'b', 64 ) );
foreach ( array( 'staging', 'production' ) as $env ) {
    $GLOBALS['ci_environment'] = $env;
    $drift = MAD4B_SCP_Remote_Media_Rights::publish_guard( $id, array(), array(), $today );
    verify( is_wp_error( $drift )
        && 'mad4b_remote_media_publish_source_identity_drift' === $drift->get_error_code(),
        'Source provenance drift escaped in ' . $env );
}
echo "REMOTE_MEDIA_RIGHTS_ENVIRONMENT: PASS (site-neutral advisory/enforced, expired rights and provenance fail-closed)\n";
