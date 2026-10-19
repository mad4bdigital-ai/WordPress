<?php
/**
 * Hermetic, fail-closed memory regression for bounded MCP diagnostics.
 * Runs on PHP 7.4+ with no database, WordPress boot, or site mutations.
 */
error_reporting( E_ALL );
if ( ! defined( 'ABSPATH' ) ) define( 'ABSPATH', '/tmp/' );
function sanitize_key( $value ) { return strtolower( preg_replace( '/[^a-z0-9_-]/i', '', (string) $value ) ); }
function absint( $value ) { return abs( (int) $value ); }
function is_wp_error( $value ) { return $value instanceof WP_Error; }
function wp_json_encode( $value, $flags = 0 ) { return json_encode( $value, $flags ); }
class WP_Error {}
function mad4b_bound_assert( $result, $why ) { if ( ! $result ) throw new RuntimeException( $why ); }
require dirname( __DIR__ ) . '/includes/class-mad4b-scp-connector-resilience.php';
require dirname( __DIR__ ) . '/includes/class-mad4b-scp-read-consistency.php';

$reflection = new ReflectionClass( 'MAD4B_SCP_Read_Consistency' );
$compact_status = $reflection->getMethod( 'compact_status_data' );
$compact_status->setAccessible( true );
$compact_bundle = $reflection->getMethod( 'compact_bundle_result' );
$compact_bundle->setAccessible( true );
$huge = array(
  'state' => 'blocked_current_drift', 'ready' => false,
  'source_commit_sha' => str_repeat( 'a', 40 ),
  'blockers' => array_fill( 0, 80000, 'bounded-only' ),
  'private_untrusted_payload' => array_fill( 0, 80000, str_repeat( 'SECRET-DO-NOT-EXPORT', 4 ) ),
);
$checks = MAD4B_SCP_Connector_Resilience::run_checks(
  array(
    'oversized_provider' => static function () use ( &$huge ) { return $huge; },
    'sibling' => static function () { return array( 'state' => 'ready', 'ready' => true ); },
  ),
  array(
    'result_projector' => static function ( $name, $data ) use ( $compact_status ) {
      return $compact_status->invoke( null, $name, $data );
    },
    'budget_ms' => 5000,
  )
);
mad4b_bound_assert( ! empty( $checks['checks']['oversized_provider']['ok'] ) && ! empty( $checks['checks']['sibling']['ok'] ), 'Expected two projected reads' );
$checks_json = json_encode( $checks );
mad4b_bound_assert( false !== $checks_json && strlen( $checks_json ) < 5000, 'Raw diagnostic retained before output bound' );
mad4b_bound_assert( false === strpos( $checks_json, 'SECRET-DO-NOT-EXPORT' ), 'Raw data leaked from check projector' );

$bundle = $compact_bundle->invoke( null, 'identity', $checks );
$json = json_encode( $bundle );
mad4b_bound_assert( false !== $json && strlen( $json ) < 6000, 'Bundle retained oversized data' );
mad4b_bound_assert( false === strpos( $json, 'SECRET-DO-NOT-EXPORT' ), 'Raw data leaked from bundle digest' );
mad4b_bound_assert( 'bounded_bundle_summary' === $bundle['evidence_digest_scope'], 'Digest scope ambiguous' );
mad4b_bound_assert( 'bounded_status_summary' === $bundle['checks']['oversized_provider']['evidence_digest_scope'], 'Check digest scope ambiguous' );
mad4b_bound_assert( false === $bundle['checks']['oversized_provider']['summary']['ready'], 'Blocked authority was promoted to ready' );
mad4b_bound_assert( 12 === count( $bundle['checks']['oversized_provider']['summary']['blockers'] ), 'Blocker list cap lost' );

$failed = MAD4B_SCP_Connector_Resilience::run_checks(
  array( 'projection' => static function () { return array( 'ready' => true ); } ),
  array( 'result_projector' => static function () { throw new RuntimeException( 'SECRET-DO-NOT-EXPORT' ); }, 'retry_transient' => false )
);
mad4b_bound_assert( ! empty( $failed['partial'] ) && empty( $failed['checks']['projection']['ok'] ), 'Projection failure must fail closed' );
mad4b_bound_assert( false === strpos( json_encode( $failed ), 'SECRET-DO-NOT-EXPORT' ), 'Projection exception leaked' );
mad4b_bound_assert( empty( $failed['checks']['projection']['automatic_retry_performed'] ), 'Projection failure must not replay automatically' );

// Allowlisted scalar fields must also be bounded BEFORE bundle hashing.
// A field name allowlist alone does not constrain a provider's string size.
$large_scalar = str_repeat( 'x', 8 * 1024 * 1024 );
$scalar_summary = $compact_status->invoke( null, 'provider', array(
  'state' => $large_scalar, 'ready' => false,
  'current' => array( 'version' => $large_scalar, 'ready' => null ),
) );
mad4b_bound_assert( strlen( json_encode( $scalar_summary ) ) < 2000, 'Allowlisted scalar data bypassed projection bound' );
mad4b_bound_assert( false === $scalar_summary['ready'] && null === $scalar_summary['current']['ready'], 'Scalar bounding changed readiness truth' );
mad4b_bound_assert( strlen( (string) $compact_status->invoke( null, 'provider', $large_scalar ) ) <= 512, 'Scalar-only provider bypassed projection bound' );
$unicode_summary = $compact_status->invoke( null, 'provider', array( 'state' => str_repeat( "\xe2\x82\xac", 512 ) ) );
mad4b_bound_assert( false !== json_encode( $unicode_summary ) && strlen( $unicode_summary['state'] ) <= 512, 'Scalar bound split UTF-8 and invalidated JSON' );

echo "mad4b.session-safe-memory-regression: PASS\n";
