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

// Capability-first, provider-neutral matrix: hermetic observations only.
// These are real provider identifiers from provider-capability-contracts.json,
// NOT claims that their live installations are currently certified.
$provider_ids = array(
	'jetengine', 'jetsmartfilters', 'bit_pi', 'rank_math', 'elementor',
	'google_drive_context', 'litespeed', 'polylang', 'woocommerce', 'wp-import-export'
);
foreach ( $provider_ids as $pid ) {
	$runtime_drift = array( 'provider' => $pid, 'status' => 'version_drift', 'runtime_contract_ok' => false );
	$fixture = array(
		'contract' => 'mad4b.provider-capability-certification-result.v1',
		'provider_id' => $pid,
		'capabilities' => array(
			'read.sample' => array( 'surface_exposed' => true, 'structural_compatible' => true, 'read_eligible' => true, 'write_eligible' => false ),
			'write.uncertified' => array( 'surface_exposed' => true, 'structural_compatible' => true, 'read_eligible' => false, 'write_eligible' => false ),
			'latent.absent' => array( 'surface_exposed' => false, 'structural_compatible' => false, 'read_eligible' => false ),
		),
	);
	$projection = MAD4B_SCP_Provider_Health_View::normalize( $runtime_drift, array( 'version_drift' ), array(), $fixture );
	$capability = $projection['capability_first'];
	$check( 'mad4b.capability-first-provider-health.v1' === $capability['contract']
		&& $pid === $capability['provider_id'], 'Wrong provider binding on capability-first projection.', $capability );
	$check( 'observed' === $capability['evidence_state']
		&& 'PARTIAL' === $capability['read_state']
		&& array( 'read.sample' ) === $capability['compatible_read_capabilities'],
		'Version drift blocked safe read-only capability observation or hid partial inventory.', $capability );
	$check( 'READ_COMPATIBLE' === $capability['capabilities']['read.sample']['state']
		&& 'UNKNOWN' === $capability['capabilities']['write.uncertified']['state']
		&& false === $capability['capabilities']['write.uncertified']['mutation_authorized'],
		'Capabilities not isolated by their actual observed behavior.', $capability );
	$check( false === $capability['mutation_authorized']
		&& false === $capability['owner_gate_bypassed']
		&& false === $capability['write_grants_created']
		&& false === $projection['runtime_contract_mutation_eligible'],
		'Read compatibility or version drift widened the mutation boundary.', $projection );
	$check( true === $capability['runtime_version_drift']
		&& false === $capability['provider_version_pin_required_for_read_observation'],
		'Exact provider version pin still used as read gate.', $capability );
}
$fixture = array(
	'contract' => 'mad4b.provider-capability-certification-result.v1',
	'provider_id' => 'jetengine',
	'capabilities' => array(
		'read.sample' => array( 'surface_exposed' => true, 'structural_compatible' => true, 'read_eligible' => true ),
	),
);
$runtime = array( 'provider' => 'jetengine', 'status' => 'unavailable', 'runtime_contract_ok' => false );
$gone = MAD4B_SCP_Provider_Health_View::capability_first( 'jetengine', $runtime, $fixture );
$check( 'UNKNOWN' === $gone['read_state']
	&& empty( $gone['compatible_read_capabilities'] ),
	'Absent provider was declared read-compatible.', $gone );
$unknown_runtime = MAD4B_SCP_Provider_Health_View::capability_first( 'jetengine',
	array( 'status' => '', 'runtime_contract_ok' => false ), $fixture );
$check( 'UNKNOWN' === $unknown_runtime['read_state']
	&& empty( $unknown_runtime['compatible_read_capabilities'] ),
	'Unknown provider runtime was promoted to compatible read.', $unknown_runtime );
$fixture['provider_id'] = 'rank_math';
$foreign = MAD4B_SCP_Provider_Health_View::capability_first( 'jetengine',
	array( 'status' => 'version_drift' ), $fixture );
$check( 'invalid_or_foreign' === $foreign['evidence_state']
	&& 'UNKNOWN' === $foreign['read_state'],
	'Cross-provider certification evidence was accepted.', $foreign );
$fixture['provider_id'] = 'jetengine';
$fixture['capabilities']['read.sample']['read_eligible'] = 1; // never treat untyped truthy data as certificate.
$untyped = MAD4B_SCP_Provider_Health_View::capability_first( 'jetengine',
	array( 'status' => 'version_drift' ), $fixture );
$check( 'UNKNOWN' === $untyped['read_state'],
	'Untyped input was promoted to read compatibility.', $untyped );
$fixture['capabilities']['read.sample']['read_eligible'] = true;
$fixture['capabilities']['incomplete'] = array( 'surface_exposed' => true ); // missing structural evidence
$partial_evidence = MAD4B_SCP_Provider_Health_View::capability_first( 'jetengine',
	array( 'status' => 'version_drift' ), $fixture );
$check( 'partial_invalid' === $partial_evidence['evidence_state']
	&& 'PARTIAL_UNVERIFIED' === $partial_evidence['read_state'],
	'Invalid partial evidence was misrepresented as complete.', $partial_evidence );
$too_many = array();
for ( $n = 0; $n < 65; $n++ ) {
	$too_many[ 'fixture.' . $n ] = array( 'surface_exposed' => true, 'structural_compatible' => true, 'read_eligible' => true );
}
$fixture['capabilities'] = $too_many;
$overflow = MAD4B_SCP_Provider_Health_View::capability_first( 'jetengine',
	array( 'status' => 'version_drift' ), $fixture );
$check( 'invalid_or_foreign' === $overflow['evidence_state'] && empty( $overflow['capabilities'] ),
	'Unbounded capability inventory was accepted.', $overflow );
$fixture['capabilities'] = array(
	'write.sample' => array( 'surface_exposed' => true, 'structural_compatible' => true, 'write_eligible' => true ),
);
$write = MAD4B_SCP_Provider_Health_View::capability_first( 'jetengine',
	array( 'status' => 'certified', 'runtime_contract_ok' => true ), $fixture );
$check( 'WRITE_CANDIDATE_UNAUTHORIZED' === $write['capabilities']['write.sample']['state']
	&& false === $write['mutation_authorized'] && false === $write['capabilities']['write.sample']['mutation_authorized'],
	'Reported write eligibility was silently treated as authorization.', $write );
$partial_health = MAD4B_SCP_Provider_Health_View::normalize(
	array( 'provider' => 'jetengine', 'status' => 'version_drift', 'runtime_contract_ok' => false ),
	array( 'version_drift' ), array(), array(
		'contract' => 'mad4b.provider-capability-certification-result.v1',
		'provider_id' => 'jetengine',
		'capabilities' => array(
			'read.sample' => array( 'surface_exposed' => true, 'structural_compatible' => true, 'read_eligible' => true ),
			'invalid.sample' => array( 'surface_exposed' => true ),
		),
	)
);
$check( 'DEGRADED' === $partial_health['health_state']
	&& 'capability_evidence_partial_invalid' === $partial_health['reason']
	&& array( 'read.sample' ) === $partial_health['read_eligible_capabilities']
	&& false === $partial_health['runtime_contract_mutation_eligible'],
	'Partial capability evidence was made healthy or authorized mutation.', $partial_health );
$foreign_cert_health = MAD4B_SCP_Provider_Health_View::normalize(
	array( 'provider' => 'jetengine', 'status' => 'certified', 'runtime_contract_ok' => true ),
	array(), array(), array(
		'contract' => 'mad4b.provider-capability-certification-result.v1',
		'provider_id' => 'rank_math',
		'capabilities' => array( 'read.sample' => array(
			'surface_exposed' => true, 'structural_compatible' => true, 'read_eligible' => true
		) ),
	)
);
$check( false === $foreign_cert_health['capability_scope']
	&& false === $foreign_cert_health['runtime_contract_mutation_eligible']
	&& 'invalid_or_foreign' === $foreign_cert_health['capability_first']['evidence_state'],
	'Foreign certification entered runtime mutation eligibility.', $foreign_cert_health );

echo "Capability-first provider matrix: PASS (10 providers, version drift, scoped read, owner gate and corrupt evidence)\n";
