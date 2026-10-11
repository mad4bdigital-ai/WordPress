<?php
/**
 * Offline WordPress-native builder contract and refusal tests.
 * No GitHub network, plugin installation, signature, or WordPress mutation.
 */
if ( ! defined( 'ABSPATH' ) ) define( 'ABSPATH', __DIR__ . '/' );
class WP_Error {
    private $code;
    public function __construct( $code, $message = '' ) { $this->code = $code; }
    public function get_error_code() { return $this->code; }
}
function is_wp_error( $v ) { return $v instanceof WP_Error; }
function add_action( $hook, $cb, $priority = 10 ) { $GLOBALS['native_hooks'][] = $hook; }
function wp_register_ability( $name, $def ) { $GLOBALS['native_abilities'][$name] = $def; }
function wp_has_ability( $name ) { return isset( $GLOBALS['native_abilities'][$name] ); }
function wp_json_encode( $v, $options = 0 ) { return json_encode( $v, $options ); }
function wp_get_environment_type() { return $GLOBALS['native_env'] ?? 'staging'; }
function get_option( $name, $default = false ) { return $GLOBALS['native_opts'][$name] ?? $default; }
function update_option( $name, $value, $autoload = null ) {
    $GLOBALS['native_opts'][$name] = $value; return true;
}
function current_user_can( $cap ) { return false; }
function get_current_user_id() { return 0; }
class MAD4B_SCP_Site_Profile {
    public static $row = array( 'site_uuid' => 'test-site',
        'profile_digest' => 'profile-a', 'environment' => 'staging',
        'configured_environment' => 'staging', 'authority_ready' => true,
        'origin_match' => true, 'canonical_origin' => 'https://staging.example.test' );
    public static function status() { return self::$row; }
    public static function user_is_enrolled( $id ) { return false; }
}
class MAD4B_SCP_WordPress_Native_Opt_In {
    public static function enabled() { return true; }
}
require_once dirname(__DIR__) . '/includes/class-mad4b-scp-wp-native-build.php';
function assert_native( $condition, $why ) {
    if ( ! $condition ) { fwrite( STDERR, "FAIL: " . $why . "\n" ); exit( 1 ); }
}
$c = 'MAD4B_SCP_WP_Native_Build';
$c::boot();
$c::register_abilities();
$c::register_abilities();
assert_native( count( $GLOBALS['native_abilities'] ) === 5,
    'Five idempotently registered governed abilities' );
foreach ( array( 'capabilities', 'plan', 'status', 'export-chunk' ) as $name ) {
    $a = $GLOBALS['native_abilities'][ $c::PREFIX . $name ];
    assert_native( $a['category'] === 'mad4b-read' &&
        $a['meta']['annotations']['readonly'] === true,
        'Read abilities must not gain mutation authority' );
    assert_native( $a['input_schema']['additionalProperties'] === false,
        'Every input schema is closed' );
}
$start = $GLOBALS['native_abilities'][ $c::PREFIX . 'start' ];
assert_native( $start['category'] === 'mad4b-admin' &&
    $start['meta']['annotations']['readonly'] === false &&
    $start['meta']['mcp']['production_mutation_allowed'] === false,
    'Owner-governed Staging-only startup' );
$cap = $c::capabilities();
assert_native( $cap['github_ci_required'] === false &&
    $cap['external_native_tests_required'] === true &&
    $cap['independent_attestation_required'] === true &&
    $cap['production_authorized'] === false,
    'Never mistake in-process packaging for trusted CI or Production' );
$sha = str_repeat( 'a', 40 );
$plan = $c::plan( array( 'expected_head' => $sha ) );
assert_native( ! is_wp_error( $plan ) && $plan['source_commit_sha'] === $sha &&
    $plan['release_certified'] === false, 'Deterministic non-authorizing plan' );
assert_native( is_wp_error( $c::plan( array( 'expected_head' => 'main' ) ) ),
    'No moving branch or arbitrary input accepted' );
assert_native( is_wp_error( $c::plan( array( 'expected_head' => $sha, 'cmd' => 'shell' ) ) ),
    'No command field is allowed' );
MAD4B_SCP_Site_Profile::$row['profile_digest'] = 'profile-b';
$changed = $c::plan( array( 'expected_head' => $sha ) );
assert_native( $changed['plan_sha256'] !== $plan['plan_sha256'],
    'Site Profile drift invalidates plan' );
assert_native( is_wp_error( $c::start( array( 'expected_head' => $sha,
    'expected_plan_sha256' => $changed['plan_sha256'],
    'confirmation' => 'INSTALL INTO PRODUCTION' ) ) ),
    'Unsafe confirmation rejected before mutation' );
assert_native( is_wp_error( $c::can_start( array() ) ),
    'Non-enrolled caller cannot start local worker' );
assert_native( is_wp_error( $c::export_chunk( array(
    'expected_head' => $sha, 'offset' => 0 ) ) ),
    'Only enrolled Staging owner may export staged bytes' );
$c::tick();
assert_native( $c::status()['state'] === 'NOT_RUN',
    'Cron never invents a job or starts itself' );
$GLOBALS['native_env'] = 'production';
assert_native( ! $c::capabilities()['eligible'],
    'Actual WordPress Production environment denied' );
// Byte-for-byte parity with the canonical Python builder's ZipInfo format.
$reflect = new ReflectionClass( $c );
$canonical = $reflect->getMethod( 'canonical_json' );
$sample = array( 'valid' => true, 'contract' => 'a',
    'package_files' => array( array( 'sha256' => 'abc', 'path' => 'test.php',
        'bytes' => 13 ) ) );
$pretty = $canonical->invoke( null, $sample );
assert_native( ! is_wp_error( $pretty ) &&
    hash( 'sha256', $pretty ) ===
        'aaaa6e266484a959b95022eb8b6fb4d58ddbbff69e00f06a16e2fff370fac18f',
    'Canonical JSON must match Python sorted-key two-space output' );
$dir = sys_get_temp_dir() . '/mad4b-native-golden-' . bin2hex( random_bytes( 6 ) );
assert_native( mkdir( $dir, 0700 ) === true, 'Private golden fixture directory' );
file_put_contents( $dir . '/test.php', '<?php echo 1;' );
$archive = $reflect->getMethod( 'canonical_archive' );
$zip = $dir . '/test.zip';
$written = $archive->invoke( null, $dir . '/',
    array( array( 'path' => 'test.php' ) ), '',
    $zip );
// Empty provenance is still a valid archive entry for the reference fixture;
// the golden ZIP below tests just one file, so use a separate local
// deterministic ZIP writer parity vector for the multi-entry contract.
assert_native( ! is_wp_error( $written ) &&
    is_file( $zip ), 'Canonical PHP ZIP writer emits a readable file' );
$bytes = file_get_contents( $zip );
assert_native( is_string( $bytes ) &&
    hash( 'sha256', $bytes ) ===
        '316c14c4a36fa12d97ad4eb17c081e3b3a168c87cafee95aebece1b6626c7ea5',
    'Complete PHP ZIP must match Python canonical ZIP byte-for-byte' );
if ( class_exists( 'ZipArchive' ) ) {
    $reader = new ZipArchive();
    assert_native( $reader->open( $zip ) === true &&
        $reader->numFiles === 2 &&
        $reader->getFromName( 'mad4b-site-control-plane/test.php' ) === '<?php echo 1;',
        'Native ZIP remains readable by ZipArchive' );
    $reader->close();
}
@unlink( $zip ); @unlink( $dir . '/test.php' ); @rmdir( $dir );
echo "PASS: WordPress-native source worker registration and fail-closed contracts\n";
