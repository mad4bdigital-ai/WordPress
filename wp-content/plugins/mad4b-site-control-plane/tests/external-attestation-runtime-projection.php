<?php
namespace WP\MCP\Domain\Utils {
	final class McpNameSanitizer {
		public static function sanitize_name( $name ) {
			$name = strtolower( trim( (string) $name ) );
			$name = str_replace( '/', '-', $name );
			$name = preg_replace( '/[^a-z0-9_.-]+/', '-', $name );
			return trim( (string) $name, '-' );
		}
	}
}
namespace {
if ( PHP_VERSION_ID < 70400 ) { fwrite( STDERR, "FAIL: PHP 7.4+ required\n" ); exit( 1 ); }
define( 'ABSPATH', __DIR__ . '/' );

$GLOBALS['mad4b_test_abilities'] = array(
	'mad4b/content-update-post' => true,
	'jetsmartfilters/update-filter-meta' => true,
);

function sanitize_key( $value ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $value ) ); }
function did_action( $name ) { return 0; }
function doing_action( $name ) { return false; }
function wp_has_ability( $name ) { return ! empty( $GLOBALS['mad4b_test_abilities'][ $name ] ); }
function wp_get_ability( $name ) { return wp_has_ability( $name ) ? new MAD4B_Test_Ability() : null; }
function is_wp_error( $value ) { return $value instanceof WP_Error; }
function add_filter() { return true; }
class WP_Error {}
class MAD4B_Test_Ability { public function get_meta() { return array( 'annotations' => array( 'readonly' => false ) ); } }

final class MAD4B_Test_Adapter {
	public function id() { return 'jetsmartfilters'; }
	public function provider_key() { return 'jetsmartfilters'; }
	public function ability_names() { return array( 'read' => array(), 'content' => array(), 'admin' => array(), 'write' => array( 'jetsmartfilters/update-filter-meta' ) ); }
	public function status() { return array( 'mutation_requires_certification' => true, 'provider_certification' => array( 'status' => 'drift', 'installed_version' => '3.8.5', 'certified_version' => '3.8.3.1' ) ); }
}

final class MAD4B_SCP_Adapter_Registry {
	private static $instance;
	private $adapter;
	private function __construct() { $this->adapter = new MAD4B_Test_Adapter(); }
	public static function instance() { if ( ! self::$instance ) self::$instance = new self(); return self::$instance; }
	public function register_defaults() {}
	public function all() { return array( $this->adapter ); }
	public function ability_names( $surface ) {
		$map = $this->adapter->ability_names();
		return isset( $map[ $surface ] ) ? $map[ $surface ] : array();
	}
}

final class MAD4B_SCP_Provider_Compatibility_Certification {
	public static $eligible = false;
	public static function supports_provider( $provider ) { return 'jetsmartfilters' === $provider; }
	public static function adapter_mount_projection( $provider, $adapter ) {
		$entry = array( 'ability' => 'jetsmartfilters/update-filter-meta', 'capability_id' => 'filter_meta.bounded-write', 'certification_level' => self::$eligible ? 'REVERSIBLE_WRITE_CERTIFIED' : 'DISCOVERED' );
		return self::$eligible ? array( 'eligible' => array( $entry ), 'blocked' => array() ) : array( 'eligible' => array(), 'blocked' => array( $entry ) );
	}
}

require dirname( __DIR__ ) . '/includes/class-mad4b-scp-servers.php';

function mad4b_assert( $condition, $message ) { if ( ! $condition ) { fwrite( STDERR, 'FAIL: ' . $message . "\n" ); exit( 1 ); } }
function tool_name( $ability ) { return \WP\MCP\Domain\Utils\McpNameSanitizer::sanitize_name( $ability ); }

$stored = array(
	'contract' => 'mad4b.external-handshake-attestation.v2',
	'external_tool_inventory_fingerprint' => str_repeat( 'a', 64 ),
	'eligible_write_tool_count' => 999,
	'expected_eligible_write_tool_count' => 999,
	'provider_gated_write_tool_count' => 0,
	'provider_gated_write_tools' => array(),
	'provider_execution_mount_leaks' => array( 'stale-leak' ),
	'provider_blocked_tool_leaks' => array( 'stale-leak' ),
);

$gated = MAD4B_SCP_Servers::filter_external_inventory_attestation_runtime( $stored );
mad4b_assert( 1 === (int) $gated['eligible_write_tool_count'], 'Only the core write should be eligible before provider certification.' );
mad4b_assert( 1 === (int) $gated['provider_gated_write_tool_count'], 'Provider write should be dynamically reported as gated.' );
mad4b_assert( array( tool_name( 'jetsmartfilters/update-filter-meta' ) ) === $gated['provider_gated_write_tools'], 'Wrong gated provider tool projection.' );
mad4b_assert( 0 === (int) $gated['governance_gated_write_tool_count'] && empty( $gated['governance_gated_write_tools'] ), 'Fixture should not invent governance-gated writes.' );
mad4b_assert( empty( $gated['provider_execution_mount_leaks'] ), 'Gated visibility must not be reported as an execution mount leak.' );
mad4b_assert( ! empty( $gated['runtime_projection_current'] ), 'Runtime projection marker missing.' );
mad4b_assert( $stored['external_tool_inventory_fingerprint'] === $gated['external_tool_inventory_fingerprint'], 'Read-time projection must not rewrite captured inventory identity.' );

MAD4B_SCP_Provider_Compatibility_Certification::$eligible = true;
$active = MAD4B_SCP_Servers::filter_external_inventory_attestation_runtime( $stored );
mad4b_assert( 2 === (int) $active['eligible_write_tool_count'], 'Provider write should become eligible without rescanning.' );
mad4b_assert( 0 === (int) $active['provider_gated_write_tool_count'], 'Gated list should clear after certification.' );
mad4b_assert( empty( $active['provider_gated_write_tools'] ), 'Gated provider tool should disappear after certification.' );
mad4b_assert( 0 === (int) $active['governance_gated_write_tool_count'] && empty( $active['governance_gated_write_tools'] ), 'Provider certification must not create governance-gated writes.' );
mad4b_assert( empty( $active['provider_execution_mount_leaks'] ), 'Certified provider write must not be treated as a leak.' );
mad4b_assert( $stored['external_tool_inventory_fingerprint'] === $active['external_tool_inventory_fingerprint'], 'Certification transition must not mutate external inventory fingerprint.' );

echo "mad4b.external-attestation-runtime-projection.v1: PASS\n";
}
