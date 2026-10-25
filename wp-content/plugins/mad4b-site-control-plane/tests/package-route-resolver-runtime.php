<?php
define( 'ABSPATH', __DIR__ . '/' );
require_once __DIR__ . '/../includes/class-mad4b-scp-package-route-resolver.php';
$providers = array( 'mcp_adapter' => array(), 'elementor' => array() );
$cases = array(
    array( 'mad4b', 'staging', 'control_plane_exact_head', true ),
    array( 'mad4b-site-control-plane', 'staging', 'control_plane_exact_head', true ),
    array( 'mcp_adapter', 'staging', 'certified_provider_package', true ),
    array( 'elementor', 'staging', 'certified_provider_package', true ),
    array( 'unknown', 'staging', 'none', false ),
    array( 'mad4b', 'production', 'control_plane_exact_head', false ),
    array( 'mcp_adapter', 'production', 'certified_provider_package', false ),
    array( '', 'staging', 'none', false ),
);
foreach ( $cases as $case ) {
    $result = MAD4B_SCP_Package_Route_Resolver::resolve( $case[0], $case[1], $providers );
    if ( $result['route'] !== $case[2] || $result['eligible_to_plan'] !== $case[3]
        || $result['auto_apply_allowed'] !== false
        || $result['caller_supplied_package_allowed'] !== false
        || $result['mutation_performed'] !== false ) {
        fwrite( STDERR, 'route resolution failed: ' . json_encode( $case ) . PHP_EOL );
        exit( 1 );
    }
}
echo "package-route-resolver-runtime: PASS\n";
