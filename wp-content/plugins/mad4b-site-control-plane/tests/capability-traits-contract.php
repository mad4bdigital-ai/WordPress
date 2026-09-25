<?php

define( 'ABSPATH', __DIR__ . '/' );
define( 'MAD4B_SCP_DIR', dirname( __DIR__ ) . '/' );

function add_action( $hook, $callback, $priority = 10 ) {}
function sanitize_key( $value ) {
	$value = strtolower( (string) $value );
	return preg_replace( '/[^a-z0-9_\-]/', '', str_replace( '.', '_', $value ) );
}
function sanitize_text_field( $value ) { return trim( (string) $value ); }
function wp_json_encode( $value, $flags = 0 ) { return json_encode( $value, $flags ); }

require dirname( __DIR__ ) . '/includes/class-mad4b-scp-capability-traits.php';

$fail = static function ( $message ) {
	fwrite( STDERR, 'FAIL capability-traits-contract: ' . $message . PHP_EOL );
	exit( 1 );
};
$check = static function ( $condition, $message ) use ( $fail ) { if ( ! $condition ) $fail( $message ); };

$profile = MAD4B_SCP_Capability_Traits::profile( 'bit_pi', 'flow.execute' );
$check( 'mad4b.capability-profile.v1' === $profile['contract'], 'profile contract mismatch' );
$check( 'bit_pi' === $profile['provider_id'], 'provider mismatch' );
$check( 'flow.execute' === $profile['capability_id'], 'capability mismatch' );
$check( false === $profile['authorizing'], 'profile became authorizing' );
$check( false === $profile['mutation_performed'], 'profile reported mutation' );
$check( false === $profile['traits']['durable_wait'], 'Bit Flows durable_wait declaration mismatch' );
$check( 'none' === $profile['traits']['cancellation'], 'Bit Flows cancellation declaration mismatch' );
$check( 'mad4b_guarded' === $profile['traits']['idempotency_model'], 'Bit Flows idempotency declaration mismatch' );
$check( 'flow_history_readback' === $profile['traits']['callback_model'], 'Bit Flows callback declaration mismatch' );
$check( 1 === preg_match( '/^[a-f0-9]{64}$/', $profile['profile_fingerprint'] ), 'profile fingerprint invalid' );

$profile2 = MAD4B_SCP_Capability_Traits::profile( 'bit_pi', 'flow.execute' );
$check( hash_equals( $profile['profile_fingerprint'], $profile2['profile_fingerprint'] ), 'profile fingerprint is non-deterministic' );

$eligible = MAD4B_SCP_Capability_Traits::resolve( array(
	'capability_id' => 'flow.execute',
	'required_traits' => array(
		'durable_wait' => false,
		'cancellation' => 'none',
		'local_or_remote' => 'local_wordpress',
	),
) );
$check( 'mad4b.capability-trait-resolution.v1' === $eligible['contract'], 'resolution contract mismatch' );
$check( true === $eligible['non_authorizing'], 'resolution became authorizing' );
$check( false === $eligible['mutation_performed'], 'resolution reported mutation' );
$eligible_ids = array_map( static function( $row ) { return $row['provider_id']; }, $eligible['eligible'] );
$check( in_array( 'bit_pi', $eligible_ids, true ), 'matching Bit Flows capability was not eligible' );

$mismatch = MAD4B_SCP_Capability_Traits::resolve( array(
	'capability_id' => 'flow.execute',
	'required_traits' => array( 'durable_wait' => true ),
) );
$bit_rejected = array_values( array_filter( $mismatch['rejected'], static function( $row ) {
	return 'bit_pi' === $row['provider_id'];
} ) );
$check( 1 === count( $bit_rejected ), 'Bit Flows mismatch was not rejected' );
$check( 'trait_mismatch' === $bit_rejected[0]['violations'][0]['reason_code'], 'trait mismatch reason code missing' );

$unknown = MAD4B_SCP_Capability_Traits::resolve( array(
	'capability_id' => 'flow.execute',
	'required_traits' => array( 'max_runtime_seconds' => array( 'gte' => 1 ) ),
) );
$bit_unknown = array_values( array_filter( $unknown['rejected'], static function( $row ) {
	return 'bit_pi' === $row['provider_id'];
} ) );
$check( 1 === count( $bit_unknown ), 'unknown Bit Flows trait was not rejected' );
$check( 'trait_unknown' === $bit_unknown[0]['violations'][0]['reason_code'], 'unknown trait did not fail closed' );

$missing = MAD4B_SCP_Capability_Traits::resolve( array(
	'capability_id' => 'does.not.exist',
	'required_traits' => array( 'durable_wait' => true ),
) );
$check( 0 === count( $missing['eligible'] ), 'unknown capability produced an eligible provider' );
$check( false === $missing['ambiguous'], 'unknown capability became ambiguous' );

$read = MAD4B_SCP_Capability_Traits::profile( 'bit_pi', 'flows.read' );
$check( 'read_repeatable' === $read['traits']['idempotency_model'], 'Bit Flows read idempotency declaration mismatch' );
$check( false === $read['traits']['durable_wait'], 'Bit Flows read durable_wait declaration mismatch' );

$main_source = file_get_contents( dirname( __DIR__ ) . '/mad4b-site-control-plane.php' );
$servers_source = file_get_contents( dirname( __DIR__ ) . '/includes/class-mad4b-scp-servers.php' );
$check( false !== strpos( $main_source, 'class-mad4b-scp-capability-traits.php' ), 'capability trait service is not loaded by plugin runtime' );
foreach ( array( 'mad4b/capability-trait-profile', 'mad4b/capability-trait-resolve' ) as $ability ) {
	$check( false !== strpos( $servers_source, "'" . $ability . "'" ), 'capability trait ability is not mounted on read plane: ' . $ability );
}
$check( false === strpos( $servers_source, "array( 'mad4b/capability-trait-profile', 'mad4b/capability-trait-resolve' )," ), 'capability trait mount guard ambiguity' );
$source = file_get_contents( dirname( __DIR__ ) . '/includes/class-mad4b-scp-capability-traits.php' );
foreach ( array( 'wp_insert_post(', 'wp_update_post(', '$wpdb->insert', '$wpdb->update', 'shell_exec(', 'exec(', 'proc_open(' ) as $forbidden ) {
	$check( false === strpos( $source, $forbidden ), 'capability trait resolver contains mutation primitive: ' . $forbidden );
}

echo "mad4b.capability-traits.v1: PASS\n";
