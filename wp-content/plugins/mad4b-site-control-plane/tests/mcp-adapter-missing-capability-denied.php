<?php
/** A vendor version is never a substitute for an executable API. */
namespace WP\MCP\Core {
    class McpAdapter {
        public static function instance() { return new self(); }
        // create_server is deliberately missing.
    }
    class McpTransportFactory {}
}
namespace WP\MCP\Servers { class DefaultServerFactory {} }
namespace {
    define( 'ABSPATH', __DIR__ . '/' );
    define( 'WP_PLUGIN_DIR', __DIR__ . '/nonexistent-plugins/' );
    define( 'MAD4B_SCP_DIR', dirname( __DIR__ ) . '/' );
    function trailingslashit( $p ) { return rtrim( $p, '/' ) . '/'; }
    function sanitize_key( $p ) { return strtolower( preg_replace( '/[^a-z0-9_-]/i', '', $p ) ); }
    function get_plugins() { return array( 'mcp-adapter/mcp-adapter.php' => array( 'Version' => '99.99' ) ); }
    function wp_register_ability() {}
    function wp_has_ability() { return true; }
    require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-provider-contracts.php';
    $s = MAD4B_SCP_Provider_Contracts::runtime_status( 'mcp_adapter', true );
    if ( 'dependency_incompatible' !== $s['status']
        || ! in_array( 'create_server', $s['missing_capabilities'], true )
        || ! empty( $s['runtime_contract_ok'] )
        || empty( MAD4B_SCP_Provider_Contracts::violations_for_status( $s ) )
        || MAD4B_SCP_Provider_Contracts::mutation_allowed( 'mcp_adapter', true ) ) {
        fwrite( STDERR, 'FAIL missing capability was incorrectly admitted' . "\n" );
        exit( 1 );
    }
    echo "MCP_ADAPTER_MISSING_CAPABILITY_FAILS_CLOSED: PASS\n";
}
