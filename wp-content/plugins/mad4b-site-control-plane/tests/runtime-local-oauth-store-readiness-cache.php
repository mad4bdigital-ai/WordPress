<?php
define( 'ABSPATH', '/tmp/' );

class MAD4B_OAuth_Store_WPDB {
	public $prefix = 'wp_';
	public $get_var_calls = 0;
	public $missing = array();

	public function prepare( $sql, $value ) {
		return $sql . '|' . (string) $value;
	}
	public function get_var( $prepared ) {
		++$this->get_var_calls;
		$parts = explode( '|', (string) $prepared, 2 );
		$table = isset( $parts[1] ) ? $parts[1] : '';
		return in_array( $table, $this->missing, true ) ? null : $table;
	}
}
$GLOBALS['wpdb'] = new MAD4B_OAuth_Store_WPDB();

function get_option( $key, $default = false ) { return $default; }

require dirname( __DIR__ ) . '/includes/class-mad4b-scp-local-oauth-store.php';

function mad4b_store_assert( $condition, $message ) {
	if ( ! $condition ) { fwrite( STDERR, "FAIL: {$message}\n" ); exit( 1 ); }
}

for ( $i = 0; $i < 16; ++$i ) {
	mad4b_store_assert( MAD4B_SCP_Local_OAuth_Store::is_ready(), 'ready store unexpectedly reported unavailable' );
}
mad4b_store_assert( 2 === $GLOBALS['wpdb']->get_var_calls, '16 is_ready calls must perform exactly one two-table readiness probe per request' );

MAD4B_SCP_Local_OAuth_Store::reset_readiness_cache();
$GLOBALS['wpdb']->missing = array( 'wp_mad4b_scp_oauth_refresh_tokens' );
mad4b_store_assert( ! MAD4B_SCP_Local_OAuth_Store::is_ready(), 'missing refresh-token table must fail readiness' );
$after_missing = $GLOBALS['wpdb']->get_var_calls;
for ( $i = 0; $i < 8; ++$i ) {
	mad4b_store_assert( ! MAD4B_SCP_Local_OAuth_Store::is_ready(), 'cached false readiness changed unexpectedly' );
}
mad4b_store_assert( $after_missing === $GLOBALS['wpdb']->get_var_calls, 'negative readiness must also be request-local memoized' );

MAD4B_SCP_Local_OAuth_Store::reset_readiness_cache();
$GLOBALS['wpdb']->missing = array();
mad4b_store_assert( MAD4B_SCP_Local_OAuth_Store::is_ready(), 'readiness did not recover after explicit cache reset' );
mad4b_store_assert( $GLOBALS['wpdb']->get_var_calls > $after_missing, 'cache reset did not force a fresh schema probe' );

echo "mad4b.local-oauth-store-readiness-cache.runtime.v1: PASS\n";
