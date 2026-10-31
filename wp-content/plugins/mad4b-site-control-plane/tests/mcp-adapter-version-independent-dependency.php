<?php
/**
 * Pure dependency admission contract regression.
 * Does not install or update any WordPress plugin.
 */
namespace WP\MCP\Core {
    class McpAdapter {
        public static function instance() { return new self(); }
        public function create_server() { return true; }
    }
    class McpTransportFactory {}
}
namespace WP\MCP\Servers { class DefaultServerFactory {} }
namespace {
    define( 'ABSPATH', __DIR__ . '/' );
    define( 'WP_PLUGIN_DIR', __DIR__ . '/no-plugin-install/' );
    define( 'MAD4B_SCP_DIR', dirname( __DIR__ ) . '/' );
    $GLOBALS['adapter_test_version'] = '0.7.1';
    function sanitize_key( $value ) { return strtolower( preg_replace( '/[^a-z0-9_\-]/i', '', $value ) ); }
    function get_plugins() {
        return array( 'mcp-adapter/mcp-adapter.php' => array( 'Version' => $GLOBALS['adapter_test_version'] ) );
    }
    function wp_register_ability() {}
    function wp_has_ability() { return true; }
    require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-provider-contracts.php';
    function check( $yes, $description ) {
        if ( ! $yes ) { fwrite( STDERR, 'FAIL ' . $description . "\n" ); exit( 1 ); }
        echo 'PASS ' . $description . "\n";
    }
    $cls = 'MAD4B_SCP_Provider_Contracts';
    foreach ( array( '0.6.1', '0.7.0', '0.7.1', '99.99.99-unknown' ) as $version ) {
        $GLOBALS['adapter_test_version'] = $version;
        $status = $cls::runtime_status( 'mcp_adapter', true );
        check( 'dependency_compatible' === $status['status'], 'capability admission version=' . $version );
        check( true === $status['runtime_contract_ok'], 'runtime compatible version=' . $version );
        check( false === $status['version_gate_enabled'], 'version gate disabled' );
        check( empty( $cls::violations_for_status( $status ) ), 'no version-based violation' );
        check( ! $cls::mutation_allowed( 'mcp_adapter', true ), 'dependency does not grant mutation' );
    }
    $blocked = $cls::runtime_status( 'mcp_adapter', false );
    check( ! $blocked['runtime_contract_ok'] && ! empty( $blocked['missing_capabilities'] ), 'unavailable runtime fails closed' );
    echo "MCP_ADAPTER_VERSION_INDEPENDENT_DEPENDENCY: PASS\n";
}
