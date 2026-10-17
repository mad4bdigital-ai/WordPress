<?php
/* Hermetic: neither WordPress, HTTP, filesystem installation nor real host access. */
$dir = sys_get_temp_dir() . '/mad4b-security-provider-fixture-' . getmypid();
mkdir( $dir . '/wp-admin/includes', 0700, true );
file_put_contents( $dir . '/wp-admin/includes/plugin.php', "<?php\n" );
file_put_contents( $dir . '/wp-admin/includes/file.php', "<?php\n" );
file_put_contents( $dir . '/wp-admin/includes/class-wp-upgrader.php', "<?php\n" );
define( 'ABSPATH', $dir . '/' );
$GLOBALS['official'] = (object) array(
    'slug' => 'wordfence', 'version' => '8.0.1',
    'download_link' => 'https://downloads.wordpress.org/plugin/wordfence.8.0.1.zip',
    'requires_php' => '7.4', 'requires' => '6.0',
);
$GLOBALS['installed'] = array();
$GLOBALS['activate_allowed'] = false;
$GLOBALS['permission'] = false;
$GLOBALS['download_calls'] = 0;
$GLOBALS['download_ok'] = false;
$GLOBALS['activated'] = false;
$GLOBALS['audit_calls'] = 0;
class WP_Error {
    private $code;
    public function __construct( $code, $message = '', $data = array() ) { $this->code = $code; }
    public function get_error_code() { return $this->code; }
}
function is_wp_error( $x ) { return $x instanceof WP_Error; }
function sanitize_key( $s ) { return strtolower( preg_replace( '/[^a-z0-9_-]/', '', (string) $s ) ); }
function wp_parse_url( $url ) { return parse_url( $url ); }
function wp_json_encode( $v, $flags = 0 ) { return json_encode( $v, $flags ); }
function get_bloginfo( $key ) { return '7.1'; }
function current_user_can( $key ) { return true; }
function get_plugins() { return $GLOBALS['installed']; }
function is_plugin_active( $file ) { return ! empty( $GLOBALS['activated'] ); }
function plugins_api( $action, $args ) { return $GLOBALS['official']; }
function download_url( $url, $timeout = 0 ) {
    ++$GLOBALS['download_calls'];
    if ( empty( $GLOBALS['download_ok'] ) ) return new WP_Error( 'offline_by_fixture' );
    $path = tempnam( sys_get_temp_dir(), 'mad4b-provider-archive-' );
    file_put_contents( $path, 'a harmless test archive, not a real plugin' );
    return $path;
}
function wp_clean_plugins_cache( $clear_update_cache = true ) {}
function activate_plugin( $file, $redirect = '', $network_wide = false, $silent = false ) {
    $GLOBALS['activated'] = true;
    return null;
}
class Automatic_Upgrader_Skin {}
class Plugin_Upgrader {
    public function __construct( $skin ) {}
    public function install( $path, $options = array() ) {
        if ( ! empty( $options['overwrite_package'] ) ) throw new RuntimeException( 'unsafe overwrite requested' );
        $GLOBALS['installed']['wordfence/wordfence.php'] = array( 'Version' => '8.0.1' );
        return true;
    }
}
class MAD4B_SCP_Site_Profile {
    public static function environment_allowed( $envs, $cap ) { return true; }
}
class MAD4B_SCP_Policy {
    public static function plugin_lifecycle_allowed( $file, $verb ) {
        return $GLOBALS['activate_allowed'];
    }
}
class MAD4B_SCP_Plugin_Package {
    public static function can_apply( $input ) { return $GLOBALS['permission']; }
}
class MAD4B_SCP_Audit {
    public static function record( $type, $data, $status = 'ok' ) { ++$GLOBALS['audit_calls']; return true; }
}
require dirname( __DIR__ ) . '/includes/class-mad4b-scp-official-security-packages.php';
$checks = 0;
$assert = static function( $ok, $why ) use ( &$checks ) {
    ++$checks;
    if ( ! $ok ) throw new RuntimeException( 'Check ' . $checks . ': ' . $why );
};
try {
    $provider = MAD4B_SCP_Official_Security_Packages::provider( 'security-wordfence' );
    $assert( $provider['slug'] === 'wordfence', 'reviewed catalog lookup' );
    $assert( ! MAD4B_SCP_Official_Security_Packages::provider( 'custom-php' ), 'no arbitrary slug' );
    $input = array( 'provider_id' => 'security-wordfence', 'source' => 'wordpress_org',
        'reason' => 'Install reviewed security provider' );
    $plan = MAD4B_SCP_Official_Security_Packages::plan( $input );
    $assert( ! is_wp_error( $plan ) && $plan['eligible'], 'official staging plan' );
    $assert( $plan['target_version'] === '8.0.1' && strlen( $plan['plan_sha256'] ) === 64, 'version-bound exact digest' );
    $assert( $plan['plan_sha256'] === MAD4B_SCP_Official_Security_Packages::plan( $input )['plan_sha256'], 'stable digest' );
    $assert( $plan['production_allowed'] === false && $plan['package_sha256_verified_before_download'] === false, 'no false security guarantees' );
    $assert( is_wp_error( MAD4B_SCP_Official_Security_Packages::plan( array_merge( $input, array( 'source' => 'certified_local_archive' ) ) ) ), 'no alternate source' );
    $GLOBALS['official']->download_link = 'https://evil.example/plugin/wordfence.8.0.1.zip';
    $assert( is_wp_error( MAD4B_SCP_Official_Security_Packages::plan( $input ) ), 'reject redirected package authority' );
    $GLOBALS['official']->download_link = 'https://downloads.wordpress.org/plugin/wordfence.8.0.1.zip';
    $GLOBALS['official']->slug = 'another-plugin';
    $assert( is_wp_error( MAD4B_SCP_Official_Security_Packages::plan( $input ) ), 'reject wrong slug' );
    $GLOBALS['official']->slug = 'wordfence';
    $GLOBALS['installed']['wordfence/wordfence.php'] = array( 'Version' => '8.0.1' );
    $assert( ! MAD4B_SCP_Official_Security_Packages::plan( $input )['eligible'], 'do not overwrite existing plugins' );
    $GLOBALS['installed'] = array();
    $activeInput = array_merge( $input, array( 'activate_after_install' => true ) );
    $assert( in_array( 'governed_plugin_activation_not_allowed', MAD4B_SCP_Official_Security_Packages::plan( $activeInput )['blockers'], true ), 'activation requires existing allowlist' );
    $GLOBALS['activate_allowed'] = true;
    $assert( MAD4B_SCP_Official_Security_Packages::plan( $activeInput )['eligible'], 'allowlisted activation preflight' );
    $assert( is_wp_error( MAD4B_SCP_Official_Security_Packages::apply( array_merge( $input, array( 'expected_plan_sha256' => $plan['plan_sha256'] ) ) ) ), 'mutations denied without original grant' );
    $assert( $GLOBALS['audit_calls'] === 0 && $GLOBALS['download_calls'] === 0, 'no side effects without grant' );
    $GLOBALS['permission'] = array( 'allowed' => true, 'reason_code' => 'preflight_allowed' );
    $assert( is_wp_error( MAD4B_SCP_Official_Security_Packages::apply( array_merge( $input, array( 'expected_plan_sha256' => str_repeat( 'a', 64 ) ) ) ) ), 'stale plan rejected before effects' );
    $assert( $GLOBALS['audit_calls'] === 0 && $GLOBALS['download_calls'] === 0, 'stale plan did not create intent' );
    $result = MAD4B_SCP_Official_Security_Packages::apply( array_merge( $input, array( 'expected_plan_sha256' => $plan['plan_sha256'] ) ) );
    $assert( is_wp_error( $result ) && $result->get_error_code() === 'offline_by_fixture', 'official download through core when exactly authorized' );
    $assert( $GLOBALS['audit_calls'] === 1 && $GLOBALS['download_calls'] === 1, 'one audit intent and one native download attempt' );
    $GLOBALS['download_ok'] = true;
    $result = MAD4B_SCP_Official_Security_Packages::apply( array_merge( $input, array( 'expected_plan_sha256' => $plan['plan_sha256'] ) ) );
    $assert( is_array( $result ) && $result['installed'] && ! $result['active'], 'native WordPress install without implicit activation' );
    $assert( $result['security_enforcement_certified'] === false && $result['operational_verified'] === false, 'no fake protection certification' );
    $assert( strlen( $result['download_sha256'] ) === 64 && $GLOBALS['audit_calls'] === 3, 'downloaded artifact hash and final audit' );
    $blocked = MAD4B_SCP_Official_Security_Packages::plan( $input );
    $assert( ! $blocked['eligible'], 'subsequent install blocked for already installed plugin' );
    $GLOBALS['installed'] = array();
    $GLOBALS['activated'] = false;
    $activePlan = MAD4B_SCP_Official_Security_Packages::plan( $activeInput );
    $activated = MAD4B_SCP_Official_Security_Packages::apply( array_merge( $activeInput, array( 'expected_plan_sha256' => $activePlan['plan_sha256'] ) ) );
    $assert( is_array( $activated ) && $activated['active'], 'opt-in allowlisted site activation after install' );
    $assert( $GLOBALS['audit_calls'] === 5 && $GLOBALS['download_calls'] === 3, 'one governed audit pair per successful install' );
    echo "SECURITY_OPERATIONAL_PROVIDERS_RUNTIME: PASS ($checks checks)\n";
} finally {
    @unlink( $dir . '/wp-admin/includes/plugin.php' );
    @unlink( $dir . '/wp-admin/includes/file.php' );
    @unlink( $dir . '/wp-admin/includes/class-wp-upgrader.php' );
    @rmdir( $dir . '/wp-admin/includes' ); @rmdir( $dir . '/wp-admin' ); @rmdir( $dir );
}
