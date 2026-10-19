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
$check( strpos( $text, 'LEAK_NEVER' ) === false && strpos( $text, $file ) === false && strpos( $text, 'plugin-name' ) === false, 'raw PHP error detail leaked' );
$check( 'plugin' === $stored['events'][0]['component_family'] && count( $stored['events'] ) === 1, 'component classification or retention wrong' );
$events = MAD4B_Native_Incident_MU::status();
$check( 'CAPTURED' === $events['state'] && 1 === $events['count'] && empty( $events['events'][0]['message'] ), 'sanitized status wrong' );
$check( empty( MAD4B_Native_Incident_MU::classify( array( 'type' => E_NOTICE ) ) ), 'nonfatal class accepted' );
for ( $i = 0; $i < 22; ++$i ) {
    $e = array( 'type' => E_USER_ERROR, 'file' => $file, 'message' => $message . ':' . $i );
    MAD4B_Native_Incident_MU::record( $e );
}
$check( 12 === MAD4B_Native_Incident_MU::status()['count'], 'bounded event ring not enforced' );
echo "PASS native Staging MU incident safety: staging gate, fatal-only, HMAC/redaction, dedupe, bounded retention, no authorization\n";
