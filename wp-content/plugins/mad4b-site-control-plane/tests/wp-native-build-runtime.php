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
function get_option( $name, $default = false ) {
    // Intentionally return the request-local cache: it might be stale.
    return $GLOBALS['native_opts'][ $name ] ?? $default;
}
function maybe_unserialize( $value ) { return unserialize( $value ); }
class MAD4B_Native_Test_DB {
    public $options = 'wp_options';
    public function prepare( $sql, ...$args ) { return array_merge( array( $sql ), $args ); }
    public function query( $q ) {
        $sql = $q[0]; $key = $q[1];
        if ( strpos( $sql, 'INSERT IGNORE INTO' ) !== false ) {
            if ( isset( $GLOBALS['native_db_opts'][ $key ] ) ) return 0;
            $GLOBALS['native_db_opts'][ $key ] = $q[2]; return 1;
        }
        if ( strpos( $sql, 'DELETE FROM' ) !== false ) {
            if ( ! isset( $GLOBALS['native_db_opts'][ $key ] ) ||
                $GLOBALS['native_db_opts'][ $key ] !== $q[2] ) return 0;
            unset( $GLOBALS['native_db_opts'][ $key ] ); return 1;
        }
        return false;
    }
    public function get_var( $query ) {
        $GLOBALS['native_uncached_reads'] = (int) ( $GLOBALS['native_uncached_reads'] ?? 0 ) + 1;
        $name = $query[1];
        $record = $GLOBALS['native_db_opts'][ $name ] ?? null;
        if ( ! empty( $GLOBALS['native_switch_job_after_lock'] ) &&
            $name === MAD4B_SCP_WP_Native_Build::OPTION ) {
            $record['state'] = 'BUILT_UNVERIFIED';
            $GLOBALS['native_db_opts'][ $name ] = $record;
            // Simulate the in-process get_option cache staying stale.
        }
        return is_array( $record ) ? serialize( $record ) : ( is_string( $record ) ? $record : null );
    }
}
$GLOBALS['wpdb'] = new MAD4B_Native_Test_DB();
function update_option( $name, $value, $autoload = null ) {
    $GLOBALS['native_opts'][$name] = $value;
    $GLOBALS['native_db_opts'][$name] = $value;
    return true;
}
function current_user_can( $cap ) { return ! empty( $GLOBALS['native_owner_enrolled'] ); }
function get_current_user_id() { return ! empty( $GLOBALS['native_owner_enrolled'] ) ? 123 : 0; }
function wp_safe_remote_get( $url, $args = array() ) {
    throw new RuntimeException( 'Offline test must not use network' );
}
function wp_schedule_single_event( $when, $hook, $args = array(), $wp_error = false ) {
    return $GLOBALS['native_schedule_ok'] ?? true;
}
// Minimal trusted adapter registry facade: no Server core-list edits.
abstract class MAD4B_SCP_Adapter_Base {
    public function provider_key() { return $this->certified_provider_key(); }
}
final class MAD4B_SCP_Adapter_Registry {
    private static $instance;
    private $adapters = array();
    public static function instance() {
        if ( ! self::$instance ) self::$instance = new self();
        return self::$instance;
    }
    public function register( $adapter ) {
        if ( ! $adapter instanceof MAD4B_SCP_Adapter_Base ) return false;
        $this->adapters[ $adapter->id() ] = $adapter;
        return true;
    }
    public function ability_names( $surface ) {
        $names = array();
        foreach ( $this->adapters as $adapter ) {
            $map = $adapter->ability_names();
            foreach ( $map[ $surface ] ?? array() as $ability ) $names[] = $ability;
        }
        return $names;
    }
}
class MAD4B_SCP_Site_Profile {
    public static $row = array( 'site_uuid' => 'test-site',
        'profile_digest' => 'profile-a', 'environment' => 'staging',
        'configured_environment' => 'staging', 'authority_ready' => true,
        'origin_match' => true, 'canonical_origin' => 'https://staging.example.test' );
    public static function status() { return self::$row; }
    public static function user_is_enrolled( $id ) { return ! empty( $GLOBALS['native_owner_enrolled'] ) && $id === 123; }
    public static function site_uuid() { return 'test-site'; }
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
assert_native( MAD4B_SCP_Adapter_Registry::instance()->ability_names( 'admin' )
    === array( $c::PREFIX . 'start' ),
    'Boot automatically self-enrolls the native build adapter in the governed registry' );
$c::register_abilities();
$c::register_abilities();
assert_native( count( $GLOBALS['native_abilities'] ) === 6,
    'Six idempotently registered governed abilities' );
foreach ( array( 'capabilities', 'plan', 'status', 'simulate', 'export-chunk' ) as $name ) {
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
// Real dynamic registration contract: no central Server ability allowlist.
$registry = new MAD4B_SCP_Adapter_Registry();
$c::register_adapter( $registry );
$c::register_adapter( $registry );
$ability = $c::PREFIX . 'start';
assert_native( $registry->ability_names( 'admin' ) === array( $ability ) &&
    $registry->ability_names( 'write' ) === array() &&
    $registry->ability_names( 'content' ) === array(),
    'Source Worker enrolls one Admin Ability without duplicate/manual core entries' );
class MAD4B_SCP_Future_Module_Fixture extends MAD4B_SCP_Adapter_Base {
    public function id() { return 'future-module'; }
    public function ability_names() {
        return array( 'admin' => array( 'future/governed-action' ),
            'read' => array(), 'write' => array(), 'content' => array() );
    }
}
assert_native( true === $registry->register( new MAD4B_SCP_Future_Module_Fixture() ) &&
    $registry->ability_names( 'admin' ) === array(
        $ability, 'future/governed-action' ),
    'A second future module enrolls without any Server core allowlist edit' );
$adapter = new MAD4B_SCP_WP_Native_Build_Adapter();
assert_native( $adapter->provider_key() === 'core' &&
    $adapter->mutation_ability_runtime_eligibility( 'mad4b/unknown' ) instanceof WP_Error,
    'Only native builder supports its typed governed ability' );
assert_native( $c::capabilities()['eligible'] &&
    true === $adapter->mutation_ability_runtime_eligibility( $ability ),
    'Native build dynamic mount is eligible only on enrolled Staging with host support' );
$server_source = file_get_contents(
    dirname( __DIR__ ) . '/includes/class-mad4b-scp-servers.php' );
assert_native( is_string( $server_source ) &&
    strpos( $server_source, "'mad4b/wp-native-build-start'" ) === false &&
    strpos( $server_source, 'registered_adapter_write_candidates' ) !== false,
    'Do not add fixed build ability to central Servers catalog' );
$GLOBALS['native_env'] = 'production';
assert_native( $adapter->mutation_ability_runtime_eligibility( $ability ) instanceof WP_Error,
    'Dynamic adapter cannot mount native build on Production' );
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
    array( array( 'path' => 'test.php', 'bytes' => 13,
        'sha' => '74e80946d0da8372c10f17874a564f2ece67fa67' ) ), '',
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
// The in-WordPress evidence simulator performs independent local file reads
// but MUST never mint native PHP version-matrix passes or signatures.
$simulation_sha = str_repeat( 'b', 40 );
$simulation_dir = sys_get_temp_dir() . '/mad4b-native-' .
    substr( hash( 'sha256', 'test-site:' . $simulation_sha ), 0, 28 );
assert_native( mkdir( $simulation_dir, 0700 ) === true,
    'Create private local simulation workspace' );
assert_native( copy( $zip, $simulation_dir . '/candidate.zip' ),
    'Copy canonical fixture ZIP into simulated build workspace' );
$simulation_zip_sha = hash_file( 'sha256', $simulation_dir . '/candidate.zip' );
$simulation_zip_bytes = filesize( $simulation_dir . '/candidate.zip' );
$receipt_data = array(
    'source_commit_sha' => $simulation_sha,
    'archive_sha256' => $simulation_zip_sha,
    'archive_bytes' => $simulation_zip_bytes,
    'build_fingerprint' => str_repeat( 'c', 64 ),
    'package_manifest_digest' => str_repeat( 'd', 64 ),
);
$receipt_text = json_encode( $receipt_data );
file_put_contents( $simulation_dir . '/CANONICAL-PACKAGE-RECEIPT.json', $receipt_text );
$simulation_job = array(
    'head' => $simulation_sha, 'state' => 'BUILT_UNVERIFIED',
    'site_uuid' => 'test-site', 'profile_digest' => 'profile-b',
    'origin' => 'https://staging.example.test',
    'archive_sha256' => $simulation_zip_sha,
    'archive_bytes' => $simulation_zip_bytes,
    'build_fingerprint' => str_repeat( 'c', 64 ),
    'package_manifest_digest' => str_repeat( 'd', 64 ),
    'canonical_receipt_sha256' => hash( 'sha256', $receipt_text ),
    'php_files_token_parsed' => 1,
    'entries' => array( array( 'path' => 'test.php' ) ),
);
$GLOBALS['native_opts'][ $c::OPTION ] = $simulation_job;
$GLOBALS['native_db_opts'][ $c::OPTION ] = $simulation_job;
$GLOBALS['native_env'] = 'staging';
assert_native( is_wp_error( $c::simulate( array( 'expected_head' => 'main' ) ) ),
    'Simulation accepts no mutable Git refs or unknown inputs' );
$sim = $c::simulate( array( 'expected_head' => $simulation_sha ) );
assert_native( $sim['state'] === 'LOCAL_SELF_CHECKS_PASSED_UNTRUSTED'
    && !in_array( 'BLOCKED', $sim['local_checks'], true )
    && $sim['external_gates']['php74_tree_syntax'] === 'NOT_EXECUTED_BY_SIMULATOR'
    && $sim['external_gates']['php83_tree_syntax'] === 'NOT_EXECUTED_BY_SIMULATOR'
    && $sim['independent_signature_present'] === false
    && $sim['release_certified'] === false
    && $sim['mutation_performed'] === false,
    'Locally valid ZIP must never become a certified release' );
file_put_contents( $simulation_dir . '/candidate.zip', 'tampered' );
$bad_sim = $c::simulate( array( 'expected_head' => $simulation_sha ) );
assert_native( $bad_sim['state'] === 'LOCAL_SELF_CHECKS_BLOCKED'
    && $bad_sim['local_checks']['archive_sha256_bytes'] === 'BLOCKED'
    && $bad_sim['local_checks']['canonical_receipt_sha256_binding'] === 'BLOCKED',
    'Tampered local ZIP must fail both checksum and signed-boundary approximation' );
$GLOBALS['native_env'] = 'production';
assert_native( is_wp_error( $c::simulate( array( 'expected_head' => $simulation_sha ) ) ),
    'Simulator is unavailable on Production even for read-only package inspection' );
unset( $GLOBALS['native_opts'][ $c::OPTION ],
    $GLOBALS['native_db_opts'][ $c::OPTION ] );
@unlink( $simulation_dir . '/candidate.zip' );
@unlink( $simulation_dir . '/CANONICAL-PACKAGE-RECEIPT.json' );
@rmdir( $simulation_dir );
// The simulator never changes the test's original Production refusal.
// Regression: the ZIP writer rejects content whose Git blob ID is not bound
// to the byte stream (in particular, sha1("blob <size>\\0<bytes>")).
file_put_contents( $dir . '/test.php', '<?php echo 2;' );
$altered = $archive->invoke( null, $dir . '/',
    array( array( 'path' => 'test.php', 'bytes' => 13,
        'sha' => '74e80946d0da8372c10f17874a564f2ece67fa67' ) ), '',
    $dir . '/tampered.zip' );
assert_native( is_wp_error( $altered ) &&
    $altered->get_error_code() === 'mad4b_native_zip_failed' &&
    ! is_file( $dir . '/tampered.zip' ),
    'Tampered pinned Git blob never yields an exportable archive' );
@unlink( $zip ); @unlink( $dir . '/test.php' ); @rmdir( $dir );
// Regression: a failed WordPress scheduler is never treated as accepted work.
$schedule = $reflect->getMethod( 'schedule' );
$schedule->setAccessible( true );
$GLOBALS['native_schedule_ok'] = false;
assert_native( $schedule->invoke( null ) === false,
    'Cron schedule failure must be a hard false result' );
$GLOBALS['native_schedule_ok'] = true;
assert_native( $schedule->invoke( null ) === true,
    'Cron scheduling success remains supported' );

// Simulate an active job with changed state between the initial cheap read
// and the locked read. It must never fetch or regress a completed cursor.
$GLOBALS['native_env'] = 'staging';
MAD4B_SCP_Site_Profile::$row['profile_digest'] = 'profile-b';
$src = str_repeat( 'a', 40 );
$workspace = sys_get_temp_dir() . '/mad4b-native-' .
    substr( hash( 'sha256', 'test-site:' . $src ), 0, 28 );
if ( ! is_dir( $workspace ) ) mkdir( $workspace, 0700 );
$job = array( 'state' => 'DISCOVERING', 'head' => $src,
    'site_uuid' => 'test-site', 'profile_digest' => 'profile-b',
    'origin' => 'https://staging.example.test',
    'expires_at' => time() + 600, 'cursor' => 0, 'entries' => array() );
$GLOBALS['native_opts'][ $c::OPTION ] = $job;
$GLOBALS['native_db_opts'][ $c::OPTION ] = $job;
$GLOBALS['native_uncached_reads'] = 0;
$GLOBALS['native_switch_job_after_lock'] = true;
$c::tick();
assert_native( $c::status()['state'] === 'BUILT_UNVERIFIED',
    'After-lock fresh read must not overwrite superseding job state' );
assert_native( $GLOBALS['native_uncached_reads'] === 1,
    'Worker must bypass request-local WP object cache for locked job state' );
$GLOBALS['native_switch_job_after_lock'] = false;
// A thrown network transport exception cannot terminate the cron callback
// or leak exception text. It produces a blocked typed recovery condition.
$GLOBALS['native_opts'][ $c::OPTION ] = $job;
$GLOBALS['native_db_opts'][ $c::OPTION ] = $job;
$GLOBALS['native_uncached_reads'] = 0;
$c::tick();
$blocked = $c::status();
assert_native( $blocked['state'] === 'BLOCKED' &&
    $blocked['blocker'] === 'native_worker_internal_exception',
    'Worker internal exception is caught, bounded and fail closed' );
unset( $GLOBALS['native_opts'][ $c::OPTION ],
    $GLOBALS['native_db_opts'][ $c::OPTION ] );
@unlink( $workspace . '/.lock' ); @rmdir( $workspace );
// A held site-global SQL mutex prevents admission before the old job row
// can be replaced, independently of the selected Git source SHA.
class MAD4B_SCP_Policy {
    public static function can_mutate() { return true; }
}
class MAD4B_SCP_OAuth_Resource_Bridge {
    const AUTHORITY_STEP_UP_SCOPE = 'owner-step-up';
    public static function verified_bearer_active() { return true; }
    public static function verified_bearer_has_scope( $scope ) { return true; }
    public static function verified_bearer_client_is( $client ) { return true; }
}
class MAD4B_SCP_Local_OAuth_Server {
    const CHATGPT_CIMD_CLIENT_ID = 'fixture-chatgpt';
}
class MAD4B_SCP_Authorization {
    public static function authorize_mutation( $name, $category, $provider, $input ) {
        return array( 'allowed' => true );
    }
}
require_once dirname( __DIR__ ) . '/includes/class-mad4b-scp-batch-atomic-mutex.php';
$GLOBALS['native_owner_enrolled'] = true;
$GLOBALS['native_schedule_ok'] = true;
$build_input = array( 'expected_head' => $sha,
    'expected_plan_sha256' => $c::plan( array( 'expected_head' => $sha ) )['plan_sha256'],
    'confirmation' => $c::CONFIRM );
$site_lease = MAD4B_SCP_Batch_Atomic_Mutex::acquire( 'wp-native-build', 'begin' );
assert_native( !is_wp_error( $site_lease ), 'First site-global build mutex claim' );
$held_start = $c::start( $build_input );
assert_native( is_wp_error( $held_start ) &&
    $held_start->get_error_code() === 'mad4b_native_admission_locked',
    'An active build reservation blocks another admitted start' );
assert_native( true === MAD4B_SCP_Batch_Atomic_Mutex::release( $site_lease ),
    'Exact owner releases site-global admission' );
$started = $c::start( $build_input );
assert_native( !is_wp_error( $started ) && $started['state'] === 'DISCOVERING',
    'Owner-authenticated Staging build admitted under the site-global mutex' );
$persisted = $GLOBALS['native_db_opts'][ $c::OPTION ];
assert_native( isset( $persisted['job_id'] ) && strlen( $persisted['job_id'] ) === 32,
    'Build has a durable per-operation identifier' );
$mutex_key = MAD4B_SCP_Batch_Atomic_Mutex::key( 'wp-native-build' );
assert_native( !isset( $GLOBALS['native_db_opts'][ $mutex_key ] ),
    'Admission lease released after verified scheduling' );
$blocked_second = $c::start( $build_input );
assert_native( is_wp_error( $blocked_second ) &&
    $blocked_second->get_error_code() === 'mad4b_native_build_busy' &&
    $GLOBALS['native_db_opts'][ $c::OPTION ] === $persisted,
    'A second serial contender may not replace a live package build' );
@unlink( sys_get_temp_dir() . '/mad4b-native-' .
    substr( hash( 'sha256', 'test-site:' . $sha ), 0, 28 ) . '/.lock' );
@rmdir( sys_get_temp_dir() . '/mad4b-native-' .
    substr( hash( 'sha256', 'test-site:' . $sha ), 0, 28 ) );
echo "PASS: Staging build site-global atomic admission, owner token and busy refusal\\n";
echo "PASS: WordPress-native source worker locked state, cron and fail-closed contracts\n";
