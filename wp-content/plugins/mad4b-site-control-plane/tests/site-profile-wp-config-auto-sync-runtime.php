<?php
if ( ! defined( 'ABSPATH' ) ) define( 'ABSPATH', sys_get_temp_dir() . '/mad4b-wp-config-fixture-' . getmypid() . '/' );
class WP_Error { private $code; public function __construct( $code, $message = '' ) { $this->code = $code; } public function get_error_code() { return $this->code; } }
function is_wp_error( $v ) { return $v instanceof WP_Error; }
function current_user_can( $cap ) { return true; }
function wp_get_environment_type() { return 'production'; }
class MAD4B_SCP_Site_Profile { public static function wordpress_environment_explicit() { return false; } }
require_once dirname( __DIR__ ) . '/includes/class-mad4b-scp-wp-config-environment-sync.php';
$sample = "<?php\n// db config\ndefine( 'DB_NAME', 'fictional' );\nrequire_once ABSPATH . 'wp-settings.php';\n";
$expected = MAD4B_SCP_WP_Config_Environment_Sync::insert_staging_bootstrap( $sample );
if ( is_wp_error( $expected ) || 1 !== substr_count( $expected, "define( 'WP_ENVIRONMENT_TYPE', 'staging' );" ) ||
	strpos( $expected, "WP_ENVIRONMENT_TYPE" ) > strpos( $expected, "require_once" ) ) exit( "FAIL: insertion\n" );
foreach ( array(
	"<?php\ndefine( 'WP_ENVIRONMENT_TYPE', 'production' );\nrequire_once ABSPATH . 'wp-settings.php';",
	"<?php\nrequire_once ABSPATH . 'wp-settings.php';\nrequire_once ABSPATH . 'wp-settings.php';",
	"<?php\nrequire_once '/tmp/unknown.php';",
) as $bad ) if ( ! is_wp_error( MAD4B_SCP_WP_Config_Environment_Sync::insert_staging_bootstrap( $bad ) ) ) exit( "FAIL: conflict accepted\n" );
@mkdir( ABSPATH, 0700, true );
$path = ABSPATH . 'wp-config.php';
file_put_contents( $path, $sample );
$stat = stat( $path );
$status = array(
	'configured_environment' => 'staging', 'environment_sync_mode' => 'host_managed',
	'wordpress_environment' => 'production', 'wordpress_environment_explicit' => false,
	'configured' => true, 'origin_match' => true, 'environment_match' => true,
	'profile_environment_authoritative' => true, 'implicit_nonproduction_override_confirmed' => true,
	'mutation_pending_audit' => false, 'site_uuid' => 'fixture-site', 'profile_digest' => str_repeat( 'a', 64 ),
);
$result = MAD4B_SCP_WP_Config_Environment_Sync::apply_from_verified_admin_save( $status );
if ( 'config_written_verified_new_request_required' !== $result['state'] ) exit( 'FAIL: '. $result['state'] . "\n" );
if ( hash( 'sha256', file_get_contents( $path ) ) !== hash( 'sha256', $expected ) ) exit( "FAIL: byte readback\n" );
$conflict = $status; $conflict['wordpress_environment_explicit'] = true;
if ( 'blocked_explicit_or_unknown_host_environment' !== MAD4B_SCP_WP_Config_Environment_Sync::apply_from_verified_admin_save( $conflict )['state'] ) exit( "FAIL: explicit Production\n" );
$blocked = $status; $blocked['implicit_nonproduction_override_confirmed'] = false;
if ( 'blocked_unverified_site_profile' !== MAD4B_SCP_WP_Config_Environment_Sync::apply_from_verified_admin_save( $blocked )['state'] ) exit( "FAIL: missing attestation\n" );
$profile_only = $status; $profile_only['environment_sync_mode'] = 'profile_only';
if ( 'not_requested' !== MAD4B_SCP_WP_Config_Environment_Sync::apply_from_verified_admin_save( $profile_only )['state'] ) exit( "FAIL: opt-out\n" );
unlink( $path ); rmdir( ABSPATH );
echo "PASS: guarded Staging config write, readback, conflicts, and opt-out\n";
