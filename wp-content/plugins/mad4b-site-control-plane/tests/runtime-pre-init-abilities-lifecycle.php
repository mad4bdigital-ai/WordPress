<?php
/**
 * Regression acceptance for WordPress 6.9+ Ability lifecycle discipline.
 *
 * The live Staging failure had three distinct dimensions:
 * 1. the Ability registry must never initialize before init;
 * 2. every MAD4B registration callback must already be wired if another
 *    component legally materializes the registry at the very start of init; and
 * 3. authority/certification truth must recover after that early materialization
 *    instead of leaving a pending authority or returning persisted stale evidence.
 *
 * The MU observer below therefore materializes the public Ability catalog one
 * priority before the full Control Plane init callback. rc.15 failed the catalog
 * shape; rc.18 could still leave authority/certification freshness behind.
 */

$wp_path = getenv( 'MAD4B_TEST_WP_PATH' );
if ( ! is_string( $wp_path ) || '' === trim( $wp_path ) ) {
	fwrite( STDERR, "FAIL pre-init-abilities-lifecycle: MAD4B_TEST_WP_PATH is required\n" );
	exit( 1 );
}
$wp_path = rtrim( $wp_path, '/\\' );
if ( ! is_file( $wp_path . '/wp-load.php' ) ) {
	fwrite( STDERR, "FAIL pre-init-abilities-lifecycle: wp-load.php not found\n" );
	exit( 1 );
}

$mu_dir = $wp_path . '/wp-content/mu-plugins';
if ( ! is_dir( $mu_dir ) && ! mkdir( $mu_dir, 0775, true ) && ! is_dir( $mu_dir ) ) {
	fwrite( STDERR, "FAIL pre-init-abilities-lifecycle: cannot create MU directory\n" );
	exit( 1 );
}
$observer = $mu_dir . '/000-000-mad4b-pre-init-observer.php';
$observer_source = <<<'PHP'
<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
$GLOBALS['mad4b_ci_pre_init_ability_violations'] = array();
$GLOBALS['mad4b_ci_missing_ability_lookups'] = array();
$GLOBALS['mad4b_ci_early_init_catalog_materialized'] = false;

add_action( 'doing_it_wrong_run', static function ( $function_name, $message, $version ) {
	$name = (string) $function_name;
	$text = wp_strip_all_tags( (string) $message );
	$is_pre_init = did_action( 'init' ) < 1;
	$is_pre_init_ability = false !== strpos( $name, 'WP_Abilities_Registry' )
		|| false !== strpos( $text, 'Ability API should not be initialized before the init action has fired' );
	$is_missing_lookup = false !== stripos( $text, 'Ability' ) && false !== stripos( $text, 'not found' );

	if ( ! ( $is_pre_init && $is_pre_init_ability ) && ! $is_missing_lookup ) return;

	$frames = array();
	foreach ( array_slice( debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS ), 0, 24 ) as $frame ) {
		$file = isset( $frame['file'] ) ? wp_normalize_path( (string) $frame['file'] ) : '';
		if ( '' !== $file && defined( 'WP_PLUGIN_DIR' ) ) {
			$plugins = trailingslashit( wp_normalize_path( WP_PLUGIN_DIR ) );
			if ( 0 === strpos( $file, $plugins ) ) $file = 'wp-content/plugins/' . ltrim( substr( $file, strlen( $plugins ) ), '/' );
			elseif ( defined( 'WPMU_PLUGIN_DIR' ) ) {
				$mu = trailingslashit( wp_normalize_path( WPMU_PLUGIN_DIR ) );
				if ( 0 === strpos( $file, $mu ) ) $file = 'wp-content/mu-plugins/' . ltrim( substr( $file, strlen( $mu ) ), '/' );
				else $file = basename( $file );
			} else $file = basename( $file );
		}
		$frames[] = array(
			'file' => $file,
			'line' => isset( $frame['line'] ) ? (int) $frame['line'] : 0,
			'class' => isset( $frame['class'] ) ? (string) $frame['class'] : '',
			'function' => isset( $frame['function'] ) ? (string) $frame['function'] : '',
		);
	}
	$event = array(
		'function' => $name,
		'message' => $text,
		'version' => (string) $version,
		'trace' => $frames,
	);
	if ( $is_pre_init && $is_pre_init_ability ) $GLOBALS['mad4b_ci_pre_init_ability_violations'][] = $event;
	if ( $is_missing_lookup ) $GLOBALS['mad4b_ci_missing_ability_lookups'][] = $event;
}, PHP_INT_MIN, 3 );

// Materialize the public catalog legally at the first edge of init, before the
// full MAD4B Plugin::boot() callback at -1000000. Every MAD4B registration hook
// must therefore have been wired during plugin bootstrap, not from full boot.
add_action( 'init', static function () {
	if ( ! function_exists( 'wp_get_abilities' ) ) return;
	$GLOBALS['mad4b_ci_early_init_catalog_materialized'] = true;
	wp_get_abilities();
}, -1000001 );
PHP;
if ( false === file_put_contents( $observer, $observer_source ) ) {
	fwrite( STDERR, "FAIL pre-init-abilities-lifecycle: cannot write MU observer\n" );
	exit( 1 );
}

$_SERVER['HTTP_HOST'] = 'staging.egypttourgates.com';
$_SERVER['HTTPS'] = 'on';
$_SERVER['SERVER_PORT'] = '443';
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['REQUEST_URI'] = '/wp-json/wpml/v1/rest/status?test_get_parameter=1&cachebuster=ci';
$_GET['test_get_parameter'] = '1';
$_GET['cachebuster'] = 'ci';

require $wp_path . '/wp-load.php';
@unlink( $observer );

$violations = isset( $GLOBALS['mad4b_ci_pre_init_ability_violations'] ) && is_array( $GLOBALS['mad4b_ci_pre_init_ability_violations'] )
	? $GLOBALS['mad4b_ci_pre_init_ability_violations']
	: array();
if ( ! empty( $violations ) ) {
	fwrite( STDERR, 'FAIL pre-init-abilities-lifecycle: Ability API initialized before init: ' . json_encode( $violations, JSON_UNESCAPED_SLASHES ) . PHP_EOL );
	exit( 1 );
}
if ( did_action( 'init' ) < 1 ) {
	fwrite( STDERR, "FAIL pre-init-abilities-lifecycle: WordPress init did not complete\n" );
	exit( 1 );
}
if ( empty( $GLOBALS['mad4b_ci_early_init_catalog_materialized'] ) ) {
	fwrite( STDERR, "FAIL pre-init-abilities-lifecycle: early init Ability catalog was not materialized\n" );
	exit( 1 );
}
if ( did_action( 'wp_abilities_api_init' ) < 1 ) {
	fwrite( STDERR, "FAIL pre-init-abilities-lifecycle: wp_abilities_api_init did not fire\n" );
	exit( 1 );
}
if ( ! function_exists( 'wp_has_ability' ) ) {
	fwrite( STDERR, "FAIL pre-init-abilities-lifecycle: wp_has_ability is unavailable\n" );
	exit( 1 );
}

$expected = array(
	'mad4b/site-info',
	'mad4b/content-update-post',
	'mad4b/mutation-get',
	'mad4b/mutation-undo',
	'mad4b/agent-list',
	'mad4b/agent-effective-access',
	'mad4b/approval-plan',
	'mad4b/connection-status',
	'mad4b/write-authority-status',
	'mad4b/write-runtime-certification',
	'mad4b/rest-compatibility-status',
	'mad4b/skills-list',
	'mad4b/skills-runtime-certification',
);
$missing = array();
foreach ( $expected as $ability ) {
	if ( ! wp_has_ability( $ability ) ) $missing[] = $ability;
}
if ( ! empty( $missing ) ) {
	fwrite( STDERR, 'FAIL pre-init-abilities-lifecycle: early-materialized catalog is incomplete: ' . json_encode( $missing, JSON_UNESCAPED_SLASHES ) . PHP_EOL );
	exit( 1 );
}

$missing_lookups = isset( $GLOBALS['mad4b_ci_missing_ability_lookups'] ) && is_array( $GLOBALS['mad4b_ci_missing_ability_lookups'] )
	? $GLOBALS['mad4b_ci_missing_ability_lookups']
	: array();
if ( ! empty( $missing_lookups ) ) {
	fwrite( STDERR, 'FAIL pre-init-abilities-lifecycle: Ability not-found lookup warning emitted: ' . json_encode( $missing_lookups, JSON_UNESCAPED_SLASHES ) . PHP_EOL );
	exit( 1 );
}

// rc.19 regression: the one-shot Ability lifecycle may already be over, but
// full boot must still reconcile runtime authority and all read abilities must
// expose fresh, non-mutating truth rather than bootstrap/persisted snapshots.
if ( ! class_exists( 'MAD4B_SCP_Live_Truth' ) ) {
	fwrite( STDERR, "FAIL pre-init-abilities-lifecycle: live truth bridge is unavailable\n" );
	exit( 1 );
}
$admin_ids = get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ) );
if ( empty( $admin_ids ) ) {
	fwrite( STDERR, "FAIL pre-init-abilities-lifecycle: administrator fixture is unavailable\n" );
	exit( 1 );
}
wp_set_current_user( (int) $admin_ids[0] );

$live_write_count = count( MAD4B_SCP_Servers::write_tools() );
$authority_ability = wp_get_ability( 'mad4b/write-authority-status' );
$authority_truth = is_object( $authority_ability ) && method_exists( $authority_ability, 'execute' ) ? $authority_ability->execute() : new WP_Error( 'mad4b_ci_ability_unexecutable', 'Authority ability is not executable.' );
if ( is_wp_error( $authority_truth ) ) {
	fwrite( STDERR, 'FAIL pre-init-abilities-lifecycle: authority truth execution failed: ' . $authority_truth->get_error_code() . PHP_EOL );
	exit( 1 );
}
if ( ! is_array( $authority_truth ) || 'live_read_only' !== ( isset( $authority_truth['inspection_source'] ) ? $authority_truth['inspection_source'] : '' ) ) {
	fwrite( STDERR, "FAIL pre-init-abilities-lifecycle: authority ability did not use live read-only truth\n" );
	exit( 1 );
}
if ( 'pending' === ( isset( $authority_truth['state'] ) ? $authority_truth['state'] : '' ) ) {
	fwrite( STDERR, 'FAIL pre-init-abilities-lifecycle: authority remained pending after full boot: ' . json_encode( $authority_truth, JSON_UNESCAPED_SLASHES ) . PHP_EOL );
	exit( 1 );
}
if ( ! isset( $authority_truth['write_tool_count'] ) || (int) $authority_truth['write_tool_count'] !== $live_write_count ) {
	fwrite( STDERR, "FAIL pre-init-abilities-lifecycle: authority truth write inventory is stale\n" );
	exit( 1 );
}

// Seed a deliberately stale certificate that mimics the live rc.18 defect: the
// stored snapshot claims nine more tools than the current provider-aware plane.
$stale_cert = array(
	'contract' => MAD4B_SCP_Write_Runtime_Certification::CONTRACT,
	'ready' => true,
	'state' => 'ready',
	'blockers' => array(),
	'write_tool_count' => $live_write_count + 9,
	'evidence_digest' => str_repeat( 'a', 64 ),
	'observed_at' => '2000-01-01T00:00:00+00:00',
);
update_option( MAD4B_SCP_Write_Runtime_Certification::OPTION, $stale_cert, false );
$legacy_readback = MAD4B_SCP_Write_Runtime_Certification::status();
if ( ! is_array( $legacy_readback ) || empty( $legacy_readback['stale'] ) || ! isset( $legacy_readback['state'] ) || 'stale' !== $legacy_readback['state'] || ! empty( $legacy_readback['ready'] ) ) {
	fwrite( STDERR, 'FAIL pre-init-abilities-lifecycle: stale persisted certification was exposed as current: ' . json_encode( $legacy_readback, JSON_UNESCAPED_SLASHES ) . PHP_EOL );
	exit( 1 );
}

$write_cert_ability = wp_get_ability( 'mad4b/write-runtime-certification' );
$write_truth = is_object( $write_cert_ability ) && method_exists( $write_cert_ability, 'execute' ) ? $write_cert_ability->execute() : new WP_Error( 'mad4b_ci_ability_unexecutable', 'Write certification ability is not executable.' );
if ( is_wp_error( $write_truth ) ) {
	fwrite( STDERR, 'FAIL pre-init-abilities-lifecycle: write truth execution failed: ' . $write_truth->get_error_code() . PHP_EOL );
	exit( 1 );
}
if ( empty( $write_truth['current_truth'] ) || ! isset( $write_truth['write_tool_count'] ) || (int) $write_truth['write_tool_count'] !== $live_write_count ) {
	fwrite( STDERR, 'FAIL pre-init-abilities-lifecycle: write certification ability returned stale inventory: ' . json_encode( $write_truth, JSON_UNESCAPED_SLASHES ) . PHP_EOL );
	exit( 1 );
}
if ( in_array( 'wpml_route_missing', isset( $write_truth['blockers'] ) && is_array( $write_truth['blockers'] ) ? $write_truth['blockers'] : array(), true ) ) {
	fwrite( STDERR, "FAIL pre-init-abilities-lifecycle: lazy WPML route leaked into local write certification blockers\n" );
	exit( 1 );
}
if ( ! array_key_exists( 'wpml_internal_probe_blocks_local_certification', $write_truth ) || false !== $write_truth['wpml_internal_probe_blocks_local_certification'] ) {
	fwrite( STDERR, "FAIL pre-init-abilities-lifecycle: WPML local/external certification boundary is not explicit\n" );
	exit( 1 );
}

$rest_ability = wp_get_ability( 'mad4b/rest-compatibility-status' );
$rest_truth = is_object( $rest_ability ) && method_exists( $rest_ability, 'execute' ) ? $rest_ability->execute() : new WP_Error( 'mad4b_ci_ability_unexecutable', 'REST compatibility ability is not executable.' );
if ( is_wp_error( $rest_truth ) || empty( $rest_truth['local_rest_isolation_ready'] ) ) {
	fwrite( STDERR, 'FAIL pre-init-abilities-lifecycle: local REST isolation truth is not ready: ' . ( is_wp_error( $rest_truth ) ? $rest_truth->get_error_code() : json_encode( $rest_truth, JSON_UNESCAPED_SLASHES ) ) . PHP_EOL );
	exit( 1 );
}
if ( ! isset( $rest_truth['wpml_internal_probe_role'] ) || 'diagnostic_only' !== $rest_truth['wpml_internal_probe_role'] || ! array_key_exists( 'wpml_internal_probe_blocks_local_certification', $rest_truth ) || false !== $rest_truth['wpml_internal_probe_blocks_local_certification'] ) {
	fwrite( STDERR, "FAIL pre-init-abilities-lifecycle: REST truth still couples WPML internal route to local certification\n" );
	exit( 1 );
}

// The explicit observer may persist current evidence; freshness metadata must
// then make the normal status() readback current again rather than stale.
MAD4B_SCP_Write_Runtime_Certification::observe();
$fresh_readback = MAD4B_SCP_Write_Runtime_Certification::status();
if ( ! is_array( $fresh_readback ) || ! empty( $fresh_readback['stale'] ) || ! isset( $fresh_readback['write_tool_count'] ) || (int) $fresh_readback['write_tool_count'] !== $live_write_count ) {
	fwrite( STDERR, 'FAIL pre-init-abilities-lifecycle: persisted certification did not refresh to current inventory: ' . json_encode( $fresh_readback, JSON_UNESCAPED_SLASHES ) . PHP_EOL );
	exit( 1 );
}

echo 'mad4b.site-control-plane.pre-init-abilities-lifecycle.v3: PASS' . PHP_EOL;