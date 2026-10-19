<?php
/** Exact staging pre-plugin fatal capture: no raw PHP details persisted. */
define( 'ABSPATH', __DIR__ );
define( 'DAY_IN_SECONDS', 86400 );
$GLOBALS['native_mu_options'] = array();
function wp_salt( $k ) { return 'test-only-nonpublic-secret'; }
function get_option( $k, $fallback = false ) { return $GLOBALS['native_mu_options'][ $k ] ?? $fallback; }
function update_option( $k, $v, $autoload = null ) { $GLOBALS['native_mu_options'][ $k ] = $v; return true; }
function is_admin() { return false; }
require __DIR__ . '/../bootstrap/mad4b-native-incident-mu.php';
$check = static function( $ok, $reason ) { if ( ! $ok ) throw new RuntimeException( $reason ); };
$check( false === MAD4B_Native_Incident_MU::boot( false ), 'unsafe auto-boot before exact Staging enrollment' );
$check( false === MAD4B_Native_Incident_MU::record( array( 'type' => E_ERROR ) ), 'non-enrolled write' );
$check( true === MAD4B_Native_Incident_MU::boot( true ), 'exact staging boot should register once' );
$check( false === MAD4B_Native_Incident_MU::boot( true ), 'duplicate shutdown hooks' );
$check( false === MAD4B_Native_Incident_MU::record( array( 'type' => E_WARNING, 'file' => '/var/www/wp-content/plugins/foo/a.php' ) ), 'warning must not persist' );
$message = 'secret=REDACT_ME token=LEAK_NEVER';
$file = '/private/root/wp-content/plugins/plugin-name/inc/secret-file.php';
$err = array( 'type' => E_ERROR, 'message' => $message, 'file' => $file );
$check( true === MAD4B_Native_Incident_MU::record( $err ), 'fatal metadata not recorded' );
$check( false === MAD4B_Native_Incident_MU::record( $err ), 'duplicate event persisted in same minute' );
$stored = get_option( MAD4B_Native_Incident_MU::OPTION );
$text = json_encode( $stored );
$check( strpos( $text, 'LEAK_NEVER' ) === false && strpos( $text, $file ) === false, 'raw PHP error detail leaked' );
$check( 'plugin' === $stored['events'][0]['component_family'] && 'plugin-name' === $stored['events'][0]['suspected_component_slug'] && count( $stored['events'] ) === 1, 'component classification or retention wrong' );
$events = MAD4B_Native_Incident_MU::status();
$check( 'CAPTURED' === $events['state'] && 1 === $events['count'] && empty( $events['events'][0]['message'] ), 'sanitized status wrong' );
// Only public first-party plugin source structure may be correlated with a
// Staging fatal. Never expose server absolute paths or foreign plugin files.
$owned_file = '/private/root/wp-content/plugins/mad4b-site-control-plane/includes/class-mad4b-scp-enrollment-dispatch.php';
$owned_error = array( 'type' => E_ERROR, 'message' => 'SECRET_FIRST_PARTY_FATAL', 'file' => $owned_file, 'line' => 621 );
$check( MAD4B_Native_Incident_MU::record( $owned_error ), 'First-party fatal not captured' );
$owned_status = MAD4B_Native_Incident_MU::status();
$hint = $owned_status['events'][count( $owned_status['events'] ) - 1];
$check( 'includes/class-mad4b-scp-enrollment-dispatch.php' === $hint['first_party_file']
    && 621 === $hint['first_party_line']
    && false === strpos( json_encode( $owned_status ), '/private/root/' )
    && false === strpos( json_encode( $owned_status ), 'SECRET_FIRST_PARTY_FATAL' ),
    'First-party fatal location was missing, absolute, or leaked secrets' );
$foreign = MAD4B_Native_Incident_MU::classify( array(
    'type' => E_ERROR, 'file' => $file, 'line' => 13, 'message' => 'foreign private error' ) );
$check( empty( $foreign['first_party_file'] ) && 0 === $foreign['first_party_line'],
    'Foreign plugin source location leaked to the first-party diagnostic' );
$traversal = MAD4B_Native_Incident_MU::classify( array(
    'type' => E_ERROR,
    'file' => '/private/root/wp-content/plugins/mad4b-site-control-plane/includes/../wp-config.php',
    'line' => 2, 'message' => 'invalid traversal' ) );
$check( empty( $traversal['first_party_file'] ) && 0 === $traversal['first_party_line'],
    'Directory traversal was accepted as a first-party source hint' );
// A forged stored event must be revalidated at read time too.
$original_store = get_option( MAD4B_Native_Incident_MU::OPTION );
$bad_store = $original_store;
$bad_store['events'][] = array(
    'type' => E_ERROR, 'component_family' => 'plugin',
    'suspected_component_slug' => 'mad4b-site-control-plane',
    'first_party_file' => '../../wp-config.php', 'first_party_line' => 8,
    'request_family' => 'frontend', 'last_at' => time(),
    'incident_fingerprint' => str_repeat( 'a', 64 ) );
$GLOBALS['native_mu_options'][ MAD4B_Native_Incident_MU::OPTION ] = $bad_store;
$bad_events = MAD4B_Native_Incident_MU::status()['events'];
$last_bad = $bad_events[count( $bad_events ) - 1];
$check( empty( $last_bad['first_party_file'] ) && 0 === $last_bad['first_party_line'],
    'Forged stored source hint bypassed readback sanitization' );
$GLOBALS['native_mu_options'][ MAD4B_Native_Incident_MU::OPTION ] = $original_store;
$check( empty( MAD4B_Native_Incident_MU::classify( array( 'type' => E_NOTICE ) ) ), 'nonfatal class accepted' );
for ( $i = 0; $i < 22; ++$i ) {
    $e = array( 'type' => E_USER_ERROR, 'file' => $file, 'message' => $message . ':' . $i );
    MAD4B_Native_Incident_MU::record( $e );
}
$check( 12 === MAD4B_Native_Incident_MU::status()['count'], 'bounded event ring not enforced' );
echo "PASS native Staging MU incident safety: staging gate, fatal-only, HMAC/redaction, dedupe, bounded retention, no authorization\n";
