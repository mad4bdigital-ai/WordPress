<?php
define( 'ABSPATH', __DIR__ . '/' );
define( 'MAD4B_SCP_VERSION', '0.4.0-rc.102' );
$wp_version = '7.1.2';
function site_url() { return 'https://staging.example.test'; }
function home_url() { return 'https://staging.example.test'; }
function is_multisite() { return false; }
function wp_get_environment_type() { return 'staging'; }
class MAD4B_SCP_Policy {
    public static function can_breakglass() { return false; }
}
class ThrowingWPDB {
    public function db_server_info() { throw new RuntimeException( 'no permission to inspect DB' ); }
    public function db_version() { return 'fallback-db'; }
}
class VersionWPDB {
    public function db_version() { return 'MariaDB-Version'; }
}
require dirname( __DIR__ ) . '/includes/class-mad4b-scp-abilities.php';
function must( $yes, $why ) {
    if ( ! $yes ) { fwrite( STDERR, 'FAIL ' . $why . PHP_EOL ); exit( 1 ); }
}
$abilities = new MAD4B_SCP_Abilities();
$wpdb = new ThrowingWPDB();
$r = $abilities->site_info();
must( $r['site']['db_server_info'] === 'unavailable', 'safe failure on DB introspection exception' );
must( $r['site']['wordpress_version'] === '7.1.2'
    && $r['site']['environment_type'] === 'staging', 'informational site contract maintained' );
$wpdb = new VersionWPDB();
$r = $abilities->site_info();
must( $r['site']['db_server_info'] === 'MariaDB-Version', 'fallback DB method used' );
$wpdb = null;
$r = $abilities->site_info();
must( $r['site']['db_server_info'] === 'unavailable', 'unavailable DB never crashes info' );
echo "SITE_INFO_RESILIENCE_RUNTIME_PASS\n";
