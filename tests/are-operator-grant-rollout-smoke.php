<?php
/** Standalone side-effect-free Developer authority inventory regression. */
define( 'ABSPATH', __DIR__ . '/../' );
if ( isset( $argv[1] ) && 'operator' === $argv[1] ) {
    define( 'MAD4B_MCP_OPERATOR_GRANT_ROLLOUT', true );
}
function add_action( ...$args ) {}
require_once __DIR__ . '/../wp-content/plugins/mad4b-site-control-plane/includes/class-mad4b-scp-developer-runtime.php';
$tools = MAD4B_SCP_Developer_Runtime::tool_names( false );
$legacy = array(
    'mad4b/developer-runtime-status',
    'mad4b/developer-wp-cli',
    'mad4b/developer-filesystem',
    'mad4b/developer-package-install',
);
foreach ( $legacy as $name ) {
    if ( ! in_array( $name, $tools, true ) ) {
        fwrite( STDERR, "FAIL: Legacy Developer capability missing: $name\n" );
        exit( 2 );
    }
}
$new = array(
    'mad4b/operator-session-open',
    'mad4b/operator-session-status',
    'mad4b/operator-session-close',
    'mad4b/operator-operation-plan',
    'mad4b/operator-operation-submit',
);
$enabled = defined( 'MAD4B_MCP_OPERATOR_GRANT_ROLLOUT' )
    && true === constant( 'MAD4B_MCP_OPERATOR_GRANT_ROLLOUT' );
foreach ( $new as $name ) {
    if ( in_array( $name, $tools, true ) !== $enabled ) {
        fwrite( STDERR, "FAIL: Operator capability rollout mismatch: $name\n" );
        exit( 3 );
    }
}
if ( count( MAD4B_SCP_Developer_Runtime::tool_names( true ) ) !== 2 ) {
    fwrite( STDERR, "FAIL: Breakglass inventory changed\n" );
    exit( 4 );
}
echo 'PASS Developer tool inventory operator rollout=' . ( $enabled ? 'ON' : 'OFF' )
    . ', legacy intact and breakglass unchanged' . PHP_EOL;
