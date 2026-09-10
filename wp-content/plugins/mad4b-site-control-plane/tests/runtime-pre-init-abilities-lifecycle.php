<?php
/**
 * Regression acceptance for WordPress 6.9+ Ability lifecycle discipline.
 *
 * The live Staging failure had two distinct dimensions:
 * 1. the Ability registry must never initialize before init; and
 * 2. every MAD4B registration callback must already be wired if another
 *    component legally materializes the registry at the very start of init.
 *
 * The MU observer below therefore materializes the public Ability catalog one
 * priority before the full Control Plane init callback. rc.15 failed this shape:
 * core bridge abilities registered, while governance/connection/write/Skill
 * callbacks were attached too late and missed the one-shot
 * wp_abilities_api_init action.
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

echo 'mad4b.site-control-plane.pre-init-abilities-lifecycle.v2: PASS' . PHP_EOL;
