<?php
/**
 * Isolated read-only regression for the ARE-verified passive WP-CLI gate.
 * Does not bootstrap WordPress or connect to a database.
 */
define( 'ABSPATH', __DIR__ . '/' );
define( 'WP_CLI', true );
class WP_CLI {
    public static $arguments = array();
    public static function get_runner() {
        return (object) array( 'arguments' => self::$arguments );
    }
}
require dirname( __DIR__ ) . '/includes/class-mad4b-scp-wpcli-passive-gate.php';

function wpcli_gate_assert( $name, $args, $raw, $expected ) {
    $actual = MAD4B_SCP_WPCLI_Passive_Gate::matches( $args, $raw );
    if ( $actual !== $expected ) {
        fwrite( STDERR, 'FAIL ' . $name . PHP_EOL );
        exit( 1 );
    }
    WP_CLI::$arguments = $args;
    $_SERVER['argv'] = $raw;
    $runtime = MAD4B_SCP_WPCLI_Passive_Gate::allowed_request();
    if ( $runtime !== $expected ) {
        fwrite( STDERR, 'FAIL actual CLI classification: ' . $name . PHP_EOL );
        exit( 1 );
    }
}
$raw = array( 'wp', '--quiet', '--skip-themes' );
wpcli_gate_assert( 'option_get', array( 'option', 'get', 'home' ), array_merge( $raw, array( 'option', 'get', 'home' ) ), true );
wpcli_gate_assert( 'plugin_list', array( 'plugin', 'list', '--field=name' ), array_merge( $raw, array( 'plugin', 'list', '--field=name' ) ), true );
wpcli_gate_assert( 'theme_status', array( 'theme', 'status' ), array_merge( $raw, array( 'theme', 'status' ) ), true );
wpcli_gate_assert( 'core_version', array( 'core', 'version' ), array_merge( $raw, array( 'core', 'version' ) ), true );
wpcli_gate_assert( 'eval_is_full', array( 'eval', 'echo 1;' ), array( 'wp', 'eval', 'echo 1;' ), false );
wpcli_gate_assert( 'eval_file_is_full', array( 'eval-file', 'script.php' ), array( 'wp', 'eval-file', 'script.php' ), false );
wpcli_gate_assert( 'option_update_is_full', array( 'option', 'update', 'home', 'x' ), array( 'wp', 'option', 'update', 'home', 'x' ), false );
wpcli_gate_assert( 'plugin_activate_is_full', array( 'plugin', 'activate', 'x' ), array( 'wp', 'plugin', 'activate', 'x' ), false );
wpcli_gate_assert( 'plugin_install_is_full', array( 'plugin', 'install', 'x' ), array( 'wp', 'plugin', 'install', 'x' ), false );
wpcli_gate_assert( 'mad4b_command_is_full', array( 'mad4b', 'status' ), array( 'wp', 'mad4b', 'status' ), false );
wpcli_gate_assert( 'post_list_is_full', array( 'post', 'list' ), array( 'wp', 'post', 'list' ), false );
wpcli_gate_assert( 'require_flag_is_full', array( 'option', 'get', 'home' ), array( 'wp', '--require=custom.php', 'option', 'get', 'home' ), false );
wpcli_gate_assert( 'exec_flag_is_full', array( 'option', 'get', 'home' ), array( 'wp', '--exec=custom.php', 'option', 'get', 'home' ), false );
wpcli_gate_assert( 'context_flag_is_full', array( 'option', 'get', 'home' ), array( 'wp', '--context=admin', 'option', 'get', 'home' ), false );
wpcli_gate_assert( 'url_flag_is_full', array( 'option', 'get', 'home' ), array( 'wp', '--url=https://example.com', 'option', 'get', 'home' ), false );
wpcli_gate_assert( 'user_flag_is_full', array( 'option', 'get', 'home' ), array( 'wp', '--user=1', 'option', 'get', 'home' ), false );
wpcli_gate_assert( 'invalid_arguments_are_full', null, array( 'wp' ), false );

$bootstrap = file_get_contents( dirname( __DIR__ ) . '/mad4b-site-control-plane.php' );
$deactivation = strpos( $bootstrap, "register_deactivation_hook( __FILE__" );
$guard = strpos( $bootstrap, "MAD4B_SCP_WPCLI_Passive_Gate::allowed_request()" );
$native = strpos( $bootstrap, 'Plain GET/HEAD views of WordPress' );
if ( false === $deactivation || false === $guard || false === $native
    || ! ( $deactivation < $guard && $guard < $native ) ) {
    fwrite( STDERR, "FAIL bootstrap order / lifecycle invariants\n" );
    exit( 1 );
}
echo "mad4b.wpcli-passive-gate.v1: PASS\n";
