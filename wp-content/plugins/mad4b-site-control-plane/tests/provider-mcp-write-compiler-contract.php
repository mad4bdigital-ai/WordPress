<?php

define( 'ABSPATH', __DIR__ . '/' );
function sanitize_key( $value ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $value ) ); }
function did_action( $hook ) { return 0; }
function doing_action( $hook ) { return false; }
function add_filter( $hook, $callback, $priority = 10, $accepted_args = 1 ) { return true; }
function wp_has_ability( $name ) { return in_array( $name, array( 'multi/write-a', 'multi/write-b', 'legacy/write', 'mad4b/provider-canary-execute', 'bitflows/run-flow' ), true ); }
final class FakeAbility { public function get_meta() { return array( 'annotations' => array( 'readonly' => false ) ); } }
function wp_get_ability( $name ) { return wp_has_ability( $name ) ? new FakeAbility() : null; }
function is_wp_error( $value ) { return false; }

final class FakeMultiAdapter {
	public function id() { return 'multi'; }
	public function provider_key() { return 'multi'; }
	public function ability_names() { return array( 'read' => array(), 'content' => array( 'multi/write-a' ), 'admin' => array( 'multi/write-b' ), 'write' => array() ); }
	public function status() { return array( 'available' => true, 'version' => '2.0.0', 'mutation_requires_certification' => true, 'provider_certification' => array( 'status' => 'version_drift', 'runtime_contract_ok' => false, 'installed_version' => '2.0.0', 'certified_version' => '1.0.0' ) ); }
}
final class FakeLegacyAdapter {
	public function id() { return 'legacy'; }
	public function provider_key() { return 'legacy'; }
	public function ability_names() { return array( 'read' => array(), 'content' => array(), 'admin' => array( 'legacy/write' ), 'write' => array() ); }
	public function status() { return array( 'available' => true, 'version' => '1.0.0', 'mutation_requires_certification' => true, 'provider_certification' => array( 'status' => 'certified', 'runtime_contract_ok' => true, 'installed_version' => '1.0.0', 'certified_version' => '1.0.0' ) ); }
}

final class FakeCatalogedProviderOkAdapter {
	public function id() { return 'catalog-ok'; }
	public function provider_key() { return 'catalog_ok'; }
	public function ability_names() { return array( 'read' => array(), 'content' => array(), 'admin' => array(), 'write' => array() ); }
	public function status() { return array( 'available' => true, 'version' => '1.0.0', 'mutation_requires_certification' => true, 'provider_certification' => array( 'status' => 'certified', 'runtime_contract_ok' => true, 'installed_version' => '1.0.0', 'certified_version' => '1.0.0' ) ); }
}

final class FakeCanaryWrapperAdapter {
	public function id() { return 'provider-canary'; }
	public function provider_key() { return 'core'; }
	public function ability_names() { return array( 'read' => array(), 'content' => array(), 'admin' => array(), 'write' => array( 'mad4b/provider-canary-execute' ) ); }
	public function status() { return array( 'available' => true, 'mutation_requires_certification' => false ); }
}
final class FakeBitFlowsAdapter {
	public function id() { return 'bitflows'; }
	public function provider_key() { return 'bit_pi'; }
	public function ability_names() { return array( 'read' => array(), 'content' => array(), 'admin' => array( 'bitflows/run-flow' ), 'write' => array() ); }
	public function status() { return array( 'available' => true, 'version' => '1.9.0', 'mutation_requires_certification' => true, 'provider_certification' => array( 'status' => 'certified', 'runtime_contract_ok' => true, 'installed_version' => '1.9.0', 'certified_version' => '1.9.0' ) ); }
}
final class MAD4B_SCP_Adapter_Registry {
	private static $instance;
	public static function instance() { if ( ! self::$instance ) self::$instance = new self(); return self::$instance; }
	public function register_defaults() {}
	public function all() { return array( new FakeMultiAdapter(), new FakeLegacyAdapter(), new FakeCatalogedProviderOkAdapter(), new FakeCanaryWrapperAdapter(), new FakeBitFlowsAdapter() ); }
	public function ability_names( $surface ) {
		$names = array();
		foreach ( $this->all() as $adapter ) {
			$map = $adapter->ability_names();
			if ( isset( $map[ $surface ] ) && is_array( $map[ $surface ] ) ) $names = array_merge( $names, $map[ $surface ] );
		}
		return array_values( array_unique( $names ) );
	}
}
final class MAD4B_SCP_Provider_Compatibility_Certification {
	const ACTIVATION_SHADOW = 'shadow';
	const ACTIVATION_ACTIVE = 'active';

	public static function supports_provider( $provider ) { return in_array( $provider, array( 'multi', 'bit_pi', 'catalog_ok' ), true ); }

	public static function ability_status( $provider, $ability_name, $adapter = null ) {
		if ( 'catalog_ok' === $provider && 'catalog/write-blocked' === $ability_name ) {
			return array(
				'provider' => 'catalog_ok',
				'capability_id' => 'bounded.blocked',
				'certification_level' => 'DISCOVERED',
				'structural_compatible' => true,
				'write_eligible' => false,
				'activation_stage' => self::ACTIVATION_SHADOW,
				'behavioral_verified' => false,
				'rollback_verified' => false,
			);
		}
		if ( 'multi' !== $provider ) return array();
		if ( 'multi/write-a' === $ability_name ) {
			return array(
				'provider' => 'multi',
				'capability_id' => 'bounded.a',
				'certification_level' => 'REVERSIBLE_WRITE_CERTIFIED',
				'structural_compatible' => true,
				'write_eligible' => true,
				'activation_stage' => self::ACTIVATION_ACTIVE,
				'behavioral_verified' => true,
				'rollback_verified' => true,
			);
		}
		if ( 'multi/write-b' === $ability_name ) {
			return array(
				'provider' => 'multi',
				'capability_id' => 'bounded.b',
				'certification_level' => 'DISCOVERED',
				'structural_compatible' => true,
				'write_eligible' => false,
				'activation_stage' => self::ACTIVATION_SHADOW,
				'behavioral_verified' => false,
				'rollback_verified' => false,
			);
		}
		return array();
	}

	public static function adapter_mount_projection( $provider, $adapter = null ) {
		if ( 'bit_pi' === $provider ) {
			return array(
				'provider_id' => 'bit_pi',
				'cataloged' => true,
				'eligible' => array(),
				'blocked' => array( array( 'ability' => 'bitflows/run-flow', 'capability_id' => 'flow.execute', 'certification_level' => 'DISCOVERED' ) ),
			);
		}
		return array(
			'provider_id' => 'multi',
			'cataloged' => true,
			'eligible' => array( array( 'ability' => 'multi/write-a', 'capability_id' => 'bounded.a', 'certification_level' => 'REVERSIBLE_WRITE_CERTIFIED' ) ),
			'blocked' => array( array( 'ability' => 'multi/write-b', 'capability_id' => 'bounded.b', 'certification_level' => 'DISCOVERED' ) ),
		);
	}
}
final class MAD4B_SCP_Provider_Contracts {
	public static function violations_for_status( array $status ) { return empty( $status['runtime_contract_ok'] ) ? array( 'version_drift' ) : array(); }
}

require_once dirname( __DIR__ ) . '/includes/class-mad4b-scp-servers.php';
require_once dirname( __DIR__ ) . '/includes/class-mad4b-scp-governance-abilities.php';

function expect_true( $condition, $message ) { if ( ! $condition ) { fwrite( STDERR, "FAIL: {$message}\n" ); exit( 1 ); } }
$method = new ReflectionMethod( 'MAD4B_SCP_Servers', 'adapter_write_projection' );
$method->setAccessible( true );
$projection = $method->invoke( null );

expect_true( in_array( 'multi/write-a', $projection['eligible'], true ), 'eligible capability mutation must mount independently' );
expect_true( ! in_array( 'multi/write-b', $projection['eligible'], true ), 'blocked capability mutation must not inherit sibling certification' );
expect_true( isset( $projection['blocked']['multi/write-b'] ), 'blocked mutation must be visible in mount evidence' );
expect_true( 'provider_capability_not_write_eligible' === $projection['blocked']['multi/write-b']['reason'], 'blocked mutation must carry capability-specific reason' );
expect_true( 'bounded.b' === $projection['blocked']['multi/write-b']['capability_id'], 'blocked mutation must retain capability identity' );
expect_true( 'DISCOVERED' === $projection['blocked']['multi/write-b']['certification_level'], 'blocked mutation must retain certification level' );
expect_true( in_array( 'legacy/write', $projection['eligible'], true ), 'non-cataloged provider must retain legacy exact certification fallback' );
expect_true( in_array( 'mad4b/provider-canary-execute', $projection['eligible'], true ), 'core canary wrapper must mount through the canonical write-only adapter compiler without provider certification' );
expect_true( ! in_array( 'bitflows/run-flow', $projection['eligible'], true ), 'high-risk provider target must remain absent from normal write projection' );
expect_true( isset( $projection['blocked']['bitflows/run-flow'] ), 'high-risk provider target remains explicit blocked evidence' );
expect_true( 'flow.execute' === $projection['blocked']['bitflows/run-flow']['capability_id'], 'blocked high-risk target retains exact capability identity' );

expect_true( in_array( 'mad4b/provider-canary-execute', MAD4B_SCP_Servers::write_tools(), true ), 'registered canary wrapper must be present on mad4b-write' );
expect_true( 'core' === MAD4B_SCP_Servers::provider_for_ability( 'mad4b-write', 'mad4b/provider-canary-execute' ), 'canary wrapper provider must resolve to core on mad4b-write' );
expect_true( null === MAD4B_SCP_Servers::provider_for_ability( 'mad4b-admin', 'mad4b/provider-canary-execute' ), 'canary wrapper must not leak onto mad4b-admin' );
expect_true( null === MAD4B_SCP_Servers::provider_for_ability( 'mad4b-content', 'mad4b/provider-canary-execute' ), 'canary wrapper must not leak onto mad4b-content' );
expect_true( null === MAD4B_SCP_Servers::provider_for_ability( 'mad4b-write', 'bitflows/run-flow' ), 'blocked provider target must not resolve on mad4b-write' );

$runtime = new ReflectionMethod( 'MAD4B_SCP_Governance_Abilities', 'provider_runtime_state' );
$runtime->setAccessible( true );

$exact = $runtime->invoke( null, 'multi', 'multi/write-a' );
expect_true( 'certified' === $exact['state'], 'exact behaviorally recertified capability must be accepted by governance runtime planning' );
expect_true( true === $exact['runtime_contract_ok'], 'exact capability certification must satisfy runtime gate for that ability only' );
expect_true( false === $exact['provider_runtime_contract_ok'], 'exact capability certification must not rewrite provider-level version-drift truth' );
expect_true( true === $exact['exact_runtime_certified'], 'exact capability certification evidence must be explicit' );
expect_true( 'ability' === $exact['certification_scope'], 'exact capability override must be scoped to one ability' );
expect_true( 'bounded.a' === $exact['capability_id'], 'governance runtime evidence must retain exact capability identity' );

$sibling = $runtime->invoke( null, 'multi', 'multi/write-b' );
expect_true( 'blocked' === $sibling['state'], 'uncertified sibling ability must remain blocked under the same provider' );
expect_true( false === $sibling['runtime_contract_ok'], 'uncertified sibling must not inherit exact capability certification' );
expect_true( false === $sibling['exact_runtime_certified'], 'uncertified sibling must not be marked exact-runtime-certified' );

$provider_wide = $runtime->invoke( null, 'multi', '' );
expect_true( 'blocked' === $provider_wide['state'], 'provider version drift must remain blocked without an exact ability selector' );
expect_true( false === $provider_wide['provider_runtime_contract_ok'], 'provider-wide runtime contract must remain false under version drift' );
expect_true( false === $provider_wide['exact_runtime_certified'], 'capability evidence must never promote provider-wide certification' );

$cataloged_provider_ok = $runtime->invoke( null, 'catalog_ok', 'catalog/write-blocked' );
expect_true( 'blocked' === $cataloged_provider_ok['state'], 'cataloged ability must remain blocked when its exact capability is ineligible even if legacy provider runtime is certified' );
expect_true( true === $cataloged_provider_ok['provider_runtime_contract_ok'], 'cataloged test fixture must retain provider-level certified truth' );
expect_true( false === $cataloged_provider_ok['exact_runtime_certified'], 'cataloged ineligible ability must not synthesize exact certification from provider-level truth' );
expect_true( 'none' === $cataloged_provider_ok['certification_scope'], 'cataloged provider-level truth must not bypass exact per-ability certification' );

$legacy = $runtime->invoke( null, 'legacy', 'legacy/write' );
expect_true( 'certified' === $legacy['state'], 'non-cataloged provider must retain legacy provider-level certification behavior' );
expect_true( true === $legacy['provider_runtime_contract_ok'], 'legacy exact provider runtime contract remains authoritative when no capability catalog exists' );
expect_true( false === $legacy['exact_runtime_certified'], 'non-cataloged provider must not synthesize capability certification' );
expect_true( 'provider' === $legacy['certification_scope'], 'legacy fallback remains provider-scoped' );

echo "MAD4B per-ability MCP write compiler and approval runtime contract passed.\n";
