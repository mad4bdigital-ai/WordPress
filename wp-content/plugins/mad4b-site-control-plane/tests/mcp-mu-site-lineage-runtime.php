<?php
/** Dynamic, site-bound historical MU ownership regression (no WP/filesystem writes). */
if ( ! defined( 'ABSPATH' ) ) define( 'ABSPATH', __DIR__ . '/' );
function mu_lineage_check( $ok, $message ) {
	if ( ! $ok ) { fwrite( STDERR, 'FAIL: ' . $message . "\n" ); exit( 1 ); }
}
class WP_Error {
	private $code;
	public function __construct( $code, $message = '' ) { $this->code = $code; }
	public function get_error_code() { return $this->code; }
}
function is_wp_error( $value ) { return $value instanceof WP_Error; }
function sanitize_key( $value ) { return strtolower( preg_replace( '/[^a-z0-9_-]/i', '', (string) $value ) ); }
$GLOBALS['mu_lineage_options'] = array();
$GLOBALS['mu_lineage_active'] = false;
$GLOBALS['mu_lineage_site'] = 'd745d81f-6fc4-5c6a-99dd-d953c92137bf';
$GLOBALS['mu_lineage_origin'] = 'https://staging.egypttourgates.com';
function get_option( $key, $default = null ) {
	return array_key_exists( $key, $GLOBALS['mu_lineage_options'] ) ? $GLOBALS['mu_lineage_options'][ $key ] : $default;
}
function update_option( $key, $value, $autoload = false ) {
	$GLOBALS['mu_lineage_options'][ $key ] = $value;
	return true;
}
function wp_cache_delete( $key, $group ) { return true; }
class MAD4B_SCP_MCP_Runtime_Recovery {
	public static function active() { return $GLOBALS['mu_lineage_active']; }
}
class MAD4B_SCP_Site_Profile {
	public static function status() {
		return array(
			'site_uuid' => $GLOBALS['mu_lineage_site'],
			'environment' => 'staging',
			'canonical_origin' => $GLOBALS['mu_lineage_origin'],
			'revision' => 5,
		);
	}
}
require_once dirname( __DIR__ ) . '/includes/class-mad4b-scp-mcp-mu-bootstrap-refresh.php';
$c = 'MAD4B_SCP_MCP_MU_Bootstrap_Refresh';
$a = str_repeat( 'a', 64 );
$b = str_repeat( 'b', 64 );
$c_hash = str_repeat( 'c', 64 );
mu_lineage_check( ! $c::historical_site_owned_sha256( $a ), 'Unknown initial hash cannot bootstrap trust' );
mu_lineage_check( is_wp_error( $c::record_verified_lineage( array( $a ) ) ), 'No ledger write outside governed recovery' );
$GLOBALS['mu_lineage_active'] = true;
mu_lineage_check( ! is_wp_error( $c::record_verified_lineage( array( $a, $b ) ) ), 'Authorized exact lineage checkpoint' );
mu_lineage_check( $c::historical_site_owned_sha256( $a ) && $c::historical_site_owned_sha256( $b ), 'Historical and successor hashes trusted' );
mu_lineage_check( ! $c::historical_site_owned_sha256( $c_hash ), 'Unknown hash still rejected' );
mu_lineage_check( ! is_wp_error( $c::record_verified_lineage( array( $c_hash ) ) ), 'Future generation extends lineage without code allowlist' );
mu_lineage_check( $c::historical_site_owned_sha256( $a ) && $c::historical_site_owned_sha256( $c_hash ), 'Multiple generations retained' );
$GLOBALS['mu_lineage_site'] = 'aabbccdd-0000-4444-9999-000000000000';
mu_lineage_check( ! $c::historical_site_owned_sha256( $a ), 'Foreign site UUID never inherits lineage' );
mu_lineage_check( is_wp_error( $c::record_verified_lineage( array( $a ) ) ), 'Foreign lineage cannot be overwritten on clone' );
$GLOBALS['mu_lineage_site'] = 'd745d81f-6fc4-5c6a-99dd-d953c92137bf';
$GLOBALS['mu_lineage_origin'] = 'https://different-staging.example';
mu_lineage_check( ! $c::historical_site_owned_sha256( $a ), 'Foreign origin never inherits lineage' );
$GLOBALS['mu_lineage_origin'] = 'https://staging.egypttourgates.com';
mu_lineage_check( $c::historical_site_owned_sha256( $b ), 'Restored exact site still owns lineage' );
mu_lineage_check( is_wp_error( $c::record_verified_lineage( array( 'bad' ) ) ), 'Invalid hash rejected' );
for ( $i = 0; $i < 36; $i++ ) {
	$hash = hash( 'sha256', 'new-trusted-generation-' . $i );
	mu_lineage_check( ! is_wp_error( $c::record_verified_lineage( array( $hash ) ) ), 'Bounded dynamic generation write ' . $i );
}
mu_lineage_check( ! $c::historical_site_owned_sha256( $a ), 'Oldest entries evicted at retention cap' );
mu_lineage_check( $c::historical_site_owned_sha256( hash( 'sha256', 'new-trusted-generation-35' ) ), 'Newest generation remains trusted' );
$GLOBALS['mu_lineage_options'][ $c::HISTORY_OPTION ] = array( 'contract' => $c::HISTORY_CONTRACT, 'hashes' => array( $a ) );
mu_lineage_check( ! $c::historical_site_owned_sha256( $a ), 'Malformed stored history fails closed' );
mu_lineage_check( is_wp_error( $c::record_verified_lineage( array( $b ) ) ), 'Malformed lineage cannot be silently overwritten' );
echo "PASS: dynamic MU lineage, old/new generations, site isolation, lifecycle guard and corruption denial\n";
