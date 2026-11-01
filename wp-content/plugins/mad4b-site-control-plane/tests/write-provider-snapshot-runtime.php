<?php
/** Hermetic source-linked Write provider snapshot regression; no WP or grants. */
define( 'ABSPATH', __DIR__ . '/' );
require dirname( __DIR__ ) . '/includes/class-mad4b-scp-server-write-catalog.php';
final class QA31_Provider_Snapshot {
    use MAD4B_SCP_Server_Write_Catalog;
    public static $mounted = array();
    public static $core = array();
    public static $adapters = array();
    public static function write_tools() { return self::$mounted; }
    public static function core_write_candidates() { return self::$core; }
    public static function registered_adapter_write_candidates() { return self::$adapters; }
}
function fail_snapshot( $why ) { fwrite( STDERR, "FAIL WRITE_SNAPSHOT: $why\n" ); exit(1); }
QA31_Provider_Snapshot::$mounted = array(
    'mad4b/hub-source-sync-request', 'mad4b/provider-connection-refresh',
    'provider/certified-write', 'provider/unreviewed-write',
);
QA31_Provider_Snapshot::$core = array(
    'mad4b/hub-source-sync-request', 'mad4b/provider-connection-refresh',
    'provider/certified-write',
);
QA31_Provider_Snapshot::$adapters = array(
    'provider/certified-write' => 'trusted-provider',
    'provider/unreviewed-write' => '',
    'provider/unmounted-write' => 'trusted-provider',
);
$snapshot = QA31_Provider_Snapshot::live_write_provider_snapshot();
if ( count( $snapshot ) !== 3
  || ($snapshot['mad4b/hub-source-sync-request'] ?? '') !== 'core'
  || ($snapshot['mad4b/provider-connection-refresh'] ?? '') !== 'core'
  || ($snapshot['provider/certified-write'] ?? '') !== 'core'
  || array_key_exists( 'provider/unreviewed-write', $snapshot )
  || array_key_exists( 'provider/unmounted-write', $snapshot ) ) fail_snapshot('core priority or unmounted write admitted');
QA31_Provider_Snapshot::$mounted = array('provider/certified-write', 'provider/fresh-write');
QA31_Provider_Snapshot::$core = array();
QA31_Provider_Snapshot::$adapters = array(
    'provider/certified-write' => 'reviewed-provider',
    'provider/fresh-write' => 'fresh-provider'
);
$fresh = QA31_Provider_Snapshot::live_write_provider_snapshot();
if ( count( $fresh ) !== 2
    || ($fresh['provider/certified-write'] ?? '') !== 'reviewed-provider'
    || ($fresh['provider/fresh-write'] ?? '') !== 'fresh-provider'
    || isset( $fresh['mad4b/hub-source-sync-request'] ) ) fail_snapshot('snapshot reused stale provider map');
QA31_Provider_Snapshot::$adapters = array('provider/fresh-write'=>'');
if ( QA31_Provider_Snapshot::live_write_provider_snapshot() ) fail_snapshot('unreviewed write became provider grant');
echo "WRITE_PROVIDER_SNAPSHOT: PASS (core priority, no unmounted/unreviewed, fresh per request)\n";
