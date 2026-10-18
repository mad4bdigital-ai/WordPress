<?php
/**
 * Source coexistence gate for the separately governed Dynamic Adapter PR #415
 * and Optional Policy PR #416. Neither PR can be considered fully integrated
 * until BOTH sets of bootstrap and discovery hooks survive the final merge.
 *
 * This is a source-contract guard; live MCP transport, WordPress registration
 * and owner-authorized Staging acceptance remain independent release gates.
 */
$root = dirname( __DIR__ );
$plugin = file_get_contents( $root . '/mad4b-site-control-plane.php' );
$servers = file_get_contents( $root . '/includes/class-mad4b-scp-servers.php' );
$workspace = file_get_contents( $root . '/includes/class-mad4b-scp-operator-workspace.php' );
$nav = file_get_contents( $root . '/includes/class-mad4b-scp-admin-navigation-manifest.php' );
function assert_contract( $condition, $reason ) {
    if ( ! $condition ) throw new RuntimeException( $reason );
}
foreach ( array(
    'class-mad4b-scp-optional-governance.php',
    'class-mad4b-scp-optional-governance-remote.php',
) as $name ) assert_contract(
    false !== strpos( $plugin, $name ), 'Optional policy runtime boot missing: ' . $name
);
foreach ( array( 'mad4b/optional-governance-status',
    'mad4b/optional-governance-plan', 'mad4b/optional-governance-verify' ) as $name )
    assert_contract( false !== strpos( $servers, $name ), 'MCP read catalog lost: ' . $name );
assert_contract( false !== strpos( $nav, 'mad4b-optional-governance-options' ),
    'Unified Options Control navigation lost' );
assert_contract( false !== strpos( $workspace, 'Optional governance and advisories' ),
    'Operator Action Center optional diagnostics lost' );
$hybrid_path = $root . '/includes/class-mad4b-scp-mcp-hybrid.php';
if ( ! file_exists( $hybrid_path ) ) {
    // PR #415 has NOT landed in this branch. Do NOT misreport that
    // cross-PR integration passed: the integration check is deferred.
    echo "OPTIONAL_ADAPTER_COMPATIBILITY: DEFERRED (PR415 Native MCP hybrid absent; source-only baseline checks PASS)\n";
    exit(0);
}
assert_contract( false !== strpos( $plugin, 'class-mad4b-scp-mcp-hybrid.php' ),
    'PR415 Native Hybrid entrypoint missing plugin bootstrap require' );
$hybrid_source = file_get_contents( $hybrid_path );
foreach ( array(
    'class-mad4b-scp-mcp-hybrid-authority.php',
    'class-mad4b-scp-mcp-local-ability-trust.php',
) as $name ) assert_contract( false !== strpos( $hybrid_source, $name ),
    'PR415 Native Hybrid missing trusted nested component: ' . $name );
assert_contract( false !== strpos( $servers, 'mcp' ),
    'Dynamic MCP catalog surface missing after hybrid integration' );
assert_contract( file_exists( $root . '/includes/class-mad4b-scp-admin-native-mcp-view.php' ),
    'Native MCP admin view missing after integration' );
echo "OPTIONAL_ADAPTER_COMPATIBILITY: PASS (optional policies and MCP hybrid source hooks coexist; LIVE transport still unverified)\n";
