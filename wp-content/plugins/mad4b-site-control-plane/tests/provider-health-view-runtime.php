<?php
define( 'ABSPATH', __DIR__ );

class WP_Error {
	private $code;
	public function __construct( $code, $message = '' ) { $this->code = (string) $code; }
	public function get_error_code() { return $this->code; }
}
function is_wp_error( $value ) { return $value instanceof WP_Error; }
function sanitize_key( $value ) { return strtolower( preg_replace( '/[^a-z0-9_\-]/i', '', (string) $value ) ); }

final class MAD4B_SCP_Connector_Resilience {
	const CONTRACT = 'mad4b.connector-resilience.v2';
}
final class MAD4B_SCP_Provider_Contracts {
	public static $status = array();
	public static $violations = array();
	public static function runtime_status( $provider, $available = null ) {
		$status = self::$status;
		$status['provider'] = $provider;
		return $status;
	}
	public static function violations_for_status( array $status ) { return self::$violations; }
}

require dirname( __DIR__ ) . '/includes/class-mad4b-scp-provider-health-view.php';

$check = static function ( $condition, $message, $context = null ) {
	if ( $condition ) return;
	fwrite( STDERR, 'FAIL provider-health-view: ' . $message . PHP_EOL );
	if ( null !== $context ) fwrite( STDERR, json_encode( $context, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . PHP_EOL );
	exit( 1 );
};

$certified = array(
	'provider' => 'fixture',
	'status' => 'certified',
	'runtime_contract_ok' => true,
	'runtime_integrity' => array( 'required' => true, 'manifest_present' => true, 'missing' => array(), 'mismatched' => array() ),
);
$healthy = MAD4B_SCP_Provider_Health_View::normalize( $certified );
$check( 'HEALTHY' === $healthy['health_state'], 'Certified provider did not normalize HEALTHY.', $healthy );
$check( true === $healthy['runtime_contract_mutation_eligible'], 'Certified runtime contract was not reported eligible.', $healthy );
$check( false === $healthy['mutation_authorized'] && 'none' === $healthy['authority_effect'] && false === $healthy['blind_retry_allowed'], 'Health projection widened authority or retry.', $healthy );

$partial = array(
	'contract' => MAD4B_SCP_Connector_Resilience::CONTRACT,
	'state' => 'partial',
	'partial' => true,
	'session_breaker_open' => false,
);
$degraded = MAD4B_SCP_Provider_Health_View::normalize( $certified, array(), $partial );
$check( 'DEGRADED' === $degraded['health_state'] && 'request_local_provider_diagnostics_partial' === $degraded['reason'], 'Partial request diagnostics did not degrade healthy provider.', $degraded );
$check( false === $degraded['persistent_circuit_state_created'] && false === $degraded['persistent_circuit_enforced'], 'Request diagnostics created persistent circuit state.', $degraded );

$breaker = $partial;
$breaker['session_breaker_open'] = true;
$breaker_degraded = MAD4B_SCP_Provider_Health_View::normalize( $certified, array(), $breaker );
$check( 'DEGRADED' === $breaker_degraded['health_state'] && 'request_local_session_breaker_open' === $breaker_degraded['reason'], 'Request-local breaker was not represented as degraded evidence.', $breaker_degraded );

$drift = array(
	'provider' => 'fixture',
	'status' => 'version_drift',
	'runtime_contract_ok' => false,
);
$blocked = MAD4B_SCP_Provider_Health_View::normalize( $drift, array( 'version_drift' ), array(
	'contract' => MAD4B_SCP_Connector_Resilience::CONTRACT,
	'state' => 'ready',
	'partial' => false,
	'session_breaker_open' => false,
) );
$check( 'UNKNOWN' === $blocked['health_state'], 'Healthy request diagnostics overrode provider contract drift.', $blocked );
$check( false === $blocked['runtime_contract_mutation_eligible'] && false === $blocked['mutation_authorized'], 'Blocked provider was reported mutation-authorized.', $blocked );

$unavailable = MAD4B_SCP_Provider_Health_View::normalize( array(
	'provider' => 'fixture',
	'status' => 'unavailable',
	'runtime_contract_ok' => false,
), array( 'unavailable' ) );
$check( 'BLOCKED' === $unavailable['health_state'], 'Unavailable provider was not blocked.', $unavailable );

$unknown = MAD4B_SCP_Provider_Health_View::normalize( array(
	'provider' => 'fixture',
	'status' => '',
	'runtime_contract_ok' => false,
) );
$check( 'UNKNOWN' === $unknown['health_state'] && false === $unknown['runtime_contract_mutation_eligible'] && false === $unknown['blind_retry_allowed'], 'Unknown provider evidence was promoted or made retryable.', $unknown );

$foreign_diag = MAD4B_SCP_Provider_Health_View::normalize( $certified, array(), array( 'contract' => 'foreign' ) );
$check( is_wp_error( $foreign_diag ) && 'mad4b_provider_health_diagnostic_contract_invalid' === $foreign_diag->get_error_code(), 'Foreign diagnostic contract was accepted.', $foreign_diag );

MAD4B_SCP_Provider_Contracts::$status = $certified;
MAD4B_SCP_Provider_Contracts::$violations = array();
$delegated = MAD4B_SCP_Provider_Health_View::provider( 'fixture' );
$check( 'HEALTHY' === $delegated['health_state'] && 'fixture' === $delegated['provider'], 'Provider Contracts delegation failed.', $delegated );

MAD4B_SCP_Provider_Contracts::$status = $drift;
MAD4B_SCP_Provider_Contracts::$violations = array( 'version_drift' );
$delegated_blocked = MAD4B_SCP_Provider_Health_View::provider( 'fixture' );
$check( 'UNKNOWN' === $delegated_blocked['health_state'], 'Provider drift delegation was not blocked.', $delegated_blocked );

echo "mad4b.provider-health-view.v1: PASS\n";

$capability_evidence = array( 'contract' => 'mad4b.provider-capability-certification-result.v1', 'provider_id' => 'fixture', 'capabilities' => array(
 'read.current' => array( 'surface_exposed' => true, 'structural_compatible' => true, 'read_eligible' => true ),
 'write.unknown' => array( 'surface_exposed' => true, 'structural_compatible' => true, 'write_eligible' => false ),
 'latent.broken' => array( 'surface_exposed' => false, 'structural_compatible' => false ),
) );
$local = MAD4B_SCP_Provider_Health_View::normalize( $drift, array( 'version_drift' ), array(), $capability_evidence );
$check( 'HEALTHY' === $local['health_state'] && array( 'write.unknown' ) === $local['isolated_capabilities'], 'Drift or latent contract failure poisoned compatible reads.', $local );
$check( ! $local['runtime_contract_mutation_eligible'] && ! $local['mutation_authorized'], 'Compatible read evidence authorized writes.', $local );
$capability_evidence['provider_id'] = 'another-provider';
$foreign = MAD4B_SCP_Provider_Health_View::normalize( $drift, array( 'version_drift' ), array(), $capability_evidence );
$check( ! $foreign['capability_scope'] && 'UNKNOWN' === $foreign['health_state'], 'Foreign provider evidence admitted.', $foreign );
echo "Capability-local provider health: PASS\n";
