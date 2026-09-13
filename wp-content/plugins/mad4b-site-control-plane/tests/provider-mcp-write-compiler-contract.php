<?php

define( 'ABSPATH', __DIR__ . '/' );
function sanitize_key( $value ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $value ) ); }
function did_action( $hook ) { return 0; }
function doing_action( $hook ) { return false; }
function wp_has_ability( $name ) { return in_array( $name, array( 'multi/write-a', 'multi/write-b', 'legacy/write' ), true ); }
final class FakeAbility { public function get_meta() { return array( 'annotations' => array( 'readonly' => false ) ); } }
function wp_get_ability( $name ) { return wp_has_ability( $name ) ? new FakeAbility() : null; }
function is_wp_error( $value ) { return false; }

final class FakeMultiAdapter {
	public function id() { return 'multi'; }
	public function provider_key() { return 'multi'; }
	public function ability_names() { return array( 'read' => array(), 'content' => array( 'multi/write-a' ), 'admin' => array( 'multi/write-b' ) ); }
	public function status() { return array( 'mutation_requires_certification' => true, 'provider_certification' => array( 'status' => 'version_drift', 'runtime_contract_ok' => false, 'installed_version' => '2.0.0', 'certified_version' => '1.0.0' ) ); }
}
final class FakeLegacyAdapter {
	public function id() { return 'legacy'; }
	public function provider_key() { return 'legacy'; }
	public function ability_names() { return array( 'read' => array(), 'content' => array(), 'admin' => array( 'legacy/write' ) ); }
	public function status() { return array( 'mutation_requires_certification' => true, 'provider_certification' => array( 'status' => 'certified', 'runtime_contract_ok' => true, 'installed_version' => '1.0.0', 'certified_version' => '1.0.0' ) ); }
}
final class MAD4B_SCP_Adapter_Registry {
	private static $instance;
	public static function instance() { if ( ! self::$instance ) self::$instance = new self(); return self::$instance; }
	public function register_defaults() {}
	public function all() { return array( new FakeMultiAdapter(), new FakeLegacyAdapter() ); }
}
final class MAD4B_SCP_Provider_Compatibility_Certification {
	public static function supports_provider( $provider ) { return 'multi' === $provider; }
	public static function adapter_mount_projection( $provider, $adapter = null ) {
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

echo "MAD4B per-ability MCP write compiler contract passed.\n";
