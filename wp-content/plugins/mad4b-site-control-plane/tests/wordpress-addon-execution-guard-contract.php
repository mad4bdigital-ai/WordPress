<?php

$root = sys_get_temp_dir() . '/mad4b-addon-registry-' . getmypid();
@mkdir( $root . '/config', 0777, true );
@mkdir( $root . '/plugins/mad4b-test-addon', 0777, true );

define( 'ABSPATH', $root . '/' );
define( 'MAD4B_SCP_DIR', $root . '/' );
define( 'WP_PLUGIN_DIR', $root . '/plugins' );

class WP_Error {
	private $code; private $message; private $data;
	public function __construct( $code, $message = '', $data = null ) { $this->code=$code; $this->message=$message; $this->data=$data; }
	public function get_error_code(){ return $this->code; }
	public function get_error_message(){ return $this->message; }
	public function get_error_data(){ return $this->data; }
}
function is_wp_error( $v ){ return $v instanceof WP_Error; }
function add_action( $h, $c, $p = 10, $a = 1 ){}
function wp_register_ability( $n, $a ){}
function wp_has_ability( $n ){ return false; }
function wp_normalize_path( $p ){ return str_replace( '\\', '/', $p ); }
function wp_json_encode( $v, $flags = 0 ){ return json_encode( $v, $flags ); }
function sanitize_key( $v ){ return strtolower( preg_replace( '/[^a-z0-9_\-]/', '', str_replace( '.', '_', trim( (string) $v ) ) ) ); }
function get_option( $k, $default = false ){ return isset( $GLOBALS['opts'][ $k ] ) ? $GLOBALS['opts'][ $k ] : $default; }
function update_option( $k, $v, $autoload = null ){ $GLOBALS['opts'][ $k ] = $v; return true; }
function is_multisite(){ return false; }
function is_plugin_active( $file ){
	return array_key_exists( 'plugin_active_override', $GLOBALS )
		? $GLOBALS['plugin_active_override'] : ! empty( $GLOBALS['addon_active'] );
}
function is_plugin_active_for_network( $file ){ return false; }
function get_plugins(){
	if ( array_key_exists( 'installed_plugins_override', $GLOBALS ) )
		return $GLOBALS['installed_plugins_override'];
	return array(
		'mad4b-test-addon/mad4b-test-addon.php' => array( 'Name' => 'MAD4B Test Add-on', 'Version' => $GLOBALS['addon_version'] ),
	);
}

// A changing server environment is never itself an Add-on release grant.
final class MAD4B_SCP_Site_Profile {
    public static $uuid = 'a3a7cc36-db7e-4a14-8456-b41857def05c';
    public static $enrolled = true;
    public static $origin_matches = true;
    public static $environment = 'staging';
    public static $digest = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    public static function site_uuid(){ return self::$uuid; }
    public static function origin_enrolled(){ return self::$enrolled; }
    public static function site_urls_match_enrollment(){ return self::$origin_matches; }
    public static function profile_digest(){ return self::$digest; }
}

final class MAD4B_SCP_Policy { public static function can_read(){ return true; } }
final class MAD4B_SCP_Audit { public static function record( $a, array $s, $status='ok', $join=false ){ return true; } }
final class MAD4B_SCP_Provider_Contracts {
	public static function runtime_status( $provider ) {
		if ( array_key_exists( 'provider_override', $GLOBALS ) )
			return $GLOBALS['provider_override'];
		return array(
			'provider' => $provider,
			'status' => ! empty( $GLOBALS['provider_certified'] ) ? 'certified' : 'version_drift',
			'runtime_contract_ok' => ! empty( $GLOBALS['provider_certified'] ),
			'installed_version' => $GLOBALS['provider_version'],
			'certification_authority' => 'fixture',
		);
	}
}

$GLOBALS['opts'] = array();
$GLOBALS['provider_version'] = '1.0.0';
$GLOBALS['provider_certified'] = true;
$GLOBALS['addon_version'] = '2.0.0';
$GLOBALS['addon_active'] = true;

$addon_file = WP_PLUGIN_DIR . '/mad4b-test-addon/mad4b-test-addon.php';
file_put_contents( $addon_file, "<?php\n/* Plugin Name: MAD4B Test Add-on\nVersion: 2.0.0\n*/\n" );
$addon_sha = hash_file( 'sha256', $addon_file );

$canonicalize = static function ( $value ) use ( &$canonicalize ) {
	if ( ! is_array( $value ) ) return $value;
	$keys = array_keys( $value );
	if ( $keys !== range( 0, count( $value ) - 1 ) ) ksort( $value, SORT_STRING );
	foreach ( $value as $k => $v ) $value[ $k ] = $canonicalize( $v );
	return $value;
};
$fingerprint = static function ( $value ) use ( $canonicalize ) {
	return hash( 'sha256', json_encode( $canonicalize( $value ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
};

$extension_points = array(
	array(
		'type' => 'hook',
		'name' => 'mad4b_test_provider_after_save',
		'required' => true,
		'accepted_args' => 1,
		'lifecycle_phase' => 'post_save',
		'semantics' => 'bounded_write',
	),
);
$extension_fp = $fingerprint( $extension_points );

$manifest = array(
	'addon_id' => 'mad4b-test-addon',
	'plugin_file' => 'mad4b-test-addon/mad4b-test-addon.php',
	'source_root' => 'wp-content/plugins/mad4b-test-addon',
	'base_provider' => array( 'provider_id' => 'fixture_provider', 'identity_source' => 'fixture' ),
	'compatible_versions' => array( 'constraint' => '1.x', 'unknown_version_behavior' => 'FAIL_CLOSED' ),
	'extension_points' => $extension_points,
	'capabilities' => array( 'fixture.write' ),
	'data_ownership' => array( 'provider_owned' => array(), 'addon_owned' => array(), 'schema_owner' => 'addon', 'migration_owner' => 'addon', 'uninstall_behavior' => 'retain' ),
	'authority_impact' => array( 'inherits_production_authority' => false, 'adds_generic_shell' => false, 'adds_raw_sql' => false ),
	'rollback' => array( 'disable_is_safe' => true, 'capability_level_compensation' => array(), 'irreversible_capabilities' => array() ),
	'certification' => array( 'exact_provider_version_required' => true, 'exact_addon_version_required' => true, 'extension_points_verified' => true, 'runtime_behavior_verified' => true ),
	'tests' => array( 'compatibility_contract' => 'required', 'runtime_certification' => 'required', 'negative_space_scan' => 'required' ),
	'portability' => array( 'vendor_files_modified' => false, 'exit_path_documented' => true ),
	'supply_chain' => array( 'source' => 'fixture', 'publisher' => 'MAD4B', 'license' => 'GPL-2.0-or-later', 'distribution_mode' => 'private', 'package_sha256' => str_repeat( 'a', 64 ), 'update_channel' => 'fixture', 'reviewed_version' => '2.0.0', 'security_review_status' => 'PASS' ),
	'network_access' => array( 'allowed_hosts' => array(), 'data_categories' => array(), 'data_governance_required' => true, 'secret_access' => 'none' ),
	'multisite' => array( 'support' => 'unsupported', 'network_activation_allowed' => false ),
	'performance_budget' => array( 'max_added_queries' => 0, 'max_sync_wall_ms' => 50, 'unbounded_autoload_option_allowed' => false ),
	'observability' => array( 'status_required' => true, 'last_failure_required' => true, 'certification_state_required' => true ),
	'failure_policy' => array( 'bounded_retries' => true, 'circuit_breaker_or_quarantine' => true, 'reconcile_before_retry_after_uncertain_write' => true ),
	'release_ring' => 'canary',
	'certified_pairs' => array(
		array(
			'provider_version' => '1.0.0',
			'addon_version' => '2.0.0',
			'extension_points_sha256' => $extension_fp,
			'addon_main_file_sha256' => $addon_sha,
		),
	),
);
file_put_contents(
	MAD4B_SCP_DIR . 'config/wordpress-addon-catalog.json',
	json_encode( array( 'contract' => 'mad4b.wordpress-addon-catalog.v1', 'addons' => array( $manifest ), 'defaults' => array( 'production_authorized' => false ) ), JSON_PRETTY_PRINT )
);

require dirname( __DIR__ ) . '/includes/class-mad4b-scp-addon-registry.php';

$fail = static function ( $message ) {
	fwrite( STDERR, 'FAIL wordpress-addon-execution-guard-contract: ' . $message . PHP_EOL );
	exit( 1 );
};
$check = static function ( $condition, $message ) use ( $fail ) { if ( ! $condition ) $fail( $message ); };

$binding = MAD4B_SCP_Addon_Registry::execution_binding( 'mad4b-test-addon' );
$check( ! is_wp_error( $binding ), 'exact certified pair did not produce execution binding' );
$check( 'mad4b.wordpress-addon-execution-binding.v1' === $binding['contract'], 'execution binding contract mismatch' );
$check( 1 === preg_match( '/^[a-f0-9]{64}$/', $binding['pair_fingerprint'] ), 'pair fingerprint invalid' );
$check( 1 === preg_match( '/^[a-f0-9]{64}$/', $binding['certification_fingerprint'] ), 'certification fingerprint invalid' );
$check( false === $binding['production_authorized'], 'execution binding authorized Production' );

// The original Registry must not turn error-shaped provider observations,
// incompatible raw contract types, or a hashless exact pair into a write binding.
$check_rejected = static function ( $name ) use ( $check ) {
    $out = MAD4B_SCP_Addon_Registry::execution_binding( 'mad4b-test-addon' );
    $check( is_wp_error( $out ), $name . ' must not mint an execution binding' );
};
foreach ( array(
    new WP_Error( 'provider_observation_failed' ),
    null, false, 'provider_online',
    array( 'status' => 'certified', 'runtime_contract_ok' => new WP_Error( 'contract_unknown' ), 'installed_version' => '1.0.0' ),
    array( 'status' => 'certified', 'runtime_contract_ok' => 1, 'installed_version' => '1.0.0' ),
    array( 'status' => 'certified', 'runtime_contract_ok' => 'true', 'installed_version' => '1.0.0' ),
) as $untrusted_provider ) {
    $GLOBALS['provider_override'] = $untrusted_provider;
    $check_rejected( 'Original provider contract with unverified source' );
}
unset( $GLOBALS['provider_override'] );
foreach ( array( new WP_Error( 'plugin_activity_unknown' ), 'true', 1, null ) as $unknown ) {
	$GLOBALS['plugin_active_override'] = $unknown;
	$check_rejected( 'Unverified WordPress is_plugin_active source evidence' );
}
unset( $GLOBALS['plugin_active_override'] );
foreach ( array( new WP_Error( 'plugin_inventory_unavailable' ), 'true', null ) as $unknown ) {
	$GLOBALS['installed_plugins_override'] = $unknown;
	$check_rejected( 'Unverified WordPress installed plugin inventory' );
}
unset( $GLOBALS['installed_plugins_override'] );

$manifest_without_hash = $manifest;
unset( $manifest_without_hash['certified_pairs'][0]['addon_main_file_sha256'] );
file_put_contents( MAD4B_SCP_DIR . 'config/wordpress-addon-catalog.json',
    json_encode( array( 'contract' => 'mad4b.wordpress-addon-catalog.v1',
        'addons' => array( $manifest_without_hash ) ) ) );
$check_rejected( 'Exact add-on pair missing installed main file SHA-256' );
foreach ( array( new WP_Error( 'certificate_unknown' ), 1, 'true', null ) as $uncertain ) {
    foreach ( array( 'exact_provider_version_required', 'exact_addon_version_required' ) as $flag ) {
        $manifest_invalid = $manifest;
        $manifest_invalid['certification'][ $flag ] = $uncertain;
        file_put_contents( MAD4B_SCP_DIR . 'config/wordpress-addon-catalog.json',
            json_encode( array( 'contract' => 'mad4b.wordpress-addon-catalog.v1',
                'addons' => array( $manifest_invalid ) ) ) );
        $check_rejected( 'Nonboolean exact provider/add-on version certification declaration' );
    }
    $manifest_invalid = $manifest;
    $manifest_invalid['failure_policy']['reconcile_before_retry_after_uncertain_write'] = $uncertain;
    file_put_contents( MAD4B_SCP_DIR . 'config/wordpress-addon-catalog.json',
        json_encode( array( 'contract' => 'mad4b.wordpress-addon-catalog.v1',
            'addons' => array( $manifest_invalid ) ) ) );
    $check_rejected( 'Unverified original uncertain-write reconciliation requirement' );
}
file_put_contents( MAD4B_SCP_DIR . 'config/wordpress-addon-catalog.json',
    json_encode( array( 'contract' => 'mad4b.wordpress-addon-catalog.v1',
        'addons' => array( $manifest ) ) ) );

$check( ! is_wp_error( MAD4B_SCP_Addon_Registry::execution_binding( 'mad4b-test-addon' ) ),
    'Valid original exact provider/add-on main file pair must survive negative proof checks' );

$guard = MAD4B_SCP_Addon_Registry::mutation_guard( 'mad4b-test-addon', $binding['pair_fingerprint'], $binding['certification_fingerprint'] );
$check( true === $guard, 'exact planned fingerprints did not pass commit guard' );

$stale = MAD4B_SCP_Addon_Registry::mutation_guard( 'mad4b-test-addon', str_repeat( 'f', 64 ), $binding['certification_fingerprint'] );
$check( is_wp_error( $stale ) && 'mad4b_addon_pair_fingerprint_drift' === $stale->get_error_code(), 'stale pair fingerprint did not fail closed' );

$GLOBALS['provider_version'] = '1.1.0';
$drift = MAD4B_SCP_Addon_Registry::execution_binding( 'mad4b-test-addon' );
$check( is_wp_error( $drift ) && 'mad4b_addon_pair_not_certified' === $drift->get_error_code(), 'provider version drift inherited certification' );

$GLOBALS['provider_version'] = '1.0.0';
$GLOBALS['addon_active'] = false;
$inactive = MAD4B_SCP_Addon_Registry::execution_binding( 'mad4b-test-addon' );
$check( is_wp_error( $inactive ) && 'mad4b_addon_pair_not_certified' === $inactive->get_error_code(), 'inactive add-on remained execution eligible' );

// Dynamic per-company ring tests retain the exact installed versions,
 // hashes, provider runtime proof, and source-bound execution fingerprint.
// Reset the original negative-path fixture's inactive-plugin state first.
$GLOBALS['addon_active'] = true;
$dynamic = $manifest;
$dynamic['release_ring'] = 'dynamic';
$dynamic['release_policy'] = array(
    'mode' => 'site_bound_certified_pair',
    'requires_enrolled_site_profile' => true,
    'unknown_pair_fallback' => 'shadow',
    'production_authorized' => false,
);
$dynamic['certified_pairs'][0]['site_uuid'] = MAD4B_SCP_Site_Profile::site_uuid();
$dynamic['certified_pairs'][0]['site_profile_digest'] = MAD4B_SCP_Site_Profile::profile_digest();
$dynamic['certified_pairs'][0]['release_ring'] = 'canary';
$write_dynamic = static function ( $configuration ) {
    file_put_contents( MAD4B_SCP_DIR . 'config/wordpress-addon-catalog.json',
        json_encode( array( 'contract' => MAD4B_SCP_Addon_Registry::CATALOG_CONTRACT,
            'addons' => array( $configuration ), 'defaults' => array( 'production_authorized' => false ) ) ) );
};
$write_dynamic( $dynamic );
$canary_status = MAD4B_SCP_Addon_Registry::status();
$row = $canary_status['addons'][0];
$check( $row['certified'] && 'canary' === $row['effective_release_ring']
    && $row['site_profile_bound'] && ! $row['mutating_capabilities_eligible'],
    'Company-bound canary must be certified but not execution eligible.' );
$canary_binding = MAD4B_SCP_Addon_Registry::execution_binding( 'mad4b-test-addon' );
$check( is_wp_error( $canary_binding )
    && 'mad4b_addon_release_ring_not_active' === $canary_binding->get_error_code(),
    'Canary ring improperly granted a normal add-on execution binding.' );
$dynamic['certified_pairs'][0]['release_ring'] = 'active';
$write_dynamic( $dynamic );
$active = MAD4B_SCP_Addon_Registry::execution_binding( 'mad4b-test-addon' );
$check( is_array( $active ) && 'active' === $active['release_ring']
    && $active['production_authorized'] === false,
    'Exact site-bound active ring should bind without authorizing Production.' );
$fp = $active['pair_fingerprint'];
MAD4B_SCP_Site_Profile::$environment = 'production';
$prod = MAD4B_SCP_Addon_Registry::execution_binding( 'mad4b-test-addon' );
$check( is_array( $prod ) && $prod['pair_fingerprint'] === $fp,
    'The WordPress environment string must not decide a signed release ring.' );
MAD4B_SCP_Site_Profile::$uuid = '11111111-2222-3333-4444-555555555555';
$wrong_site = MAD4B_SCP_Addon_Registry::execution_binding( 'mad4b-test-addon' );
$check( is_wp_error( $wrong_site )
    && 'mad4b_addon_pair_not_certified' === $wrong_site->get_error_code(),
    'Another enrolled company inherited a foreign site certificate.' );
$wrong_row = MAD4B_SCP_Addon_Registry::status()['addons'][0];
$check( 'shadow' === $wrong_row['effective_release_ring']
    && ! $wrong_row['mutating_capabilities_eligible'],
    'Foreign site identity failed to fall back to shadow.' );
MAD4B_SCP_Site_Profile::$uuid = 'a3a7cc36-db7e-4a14-8456-b41857def05c';
MAD4B_SCP_Site_Profile::$enrolled = false;
$not_enrolled = MAD4B_SCP_Addon_Registry::execution_binding( 'mad4b-test-addon' );
$check( is_wp_error( $not_enrolled ), 'Unenrolled company got a release binding.' );
MAD4B_SCP_Site_Profile::$enrolled = true;
MAD4B_SCP_Site_Profile::$origin_matches = false;
$foreign_origin = MAD4B_SCP_Addon_Registry::execution_binding( 'mad4b-test-addon' );
$check( is_wp_error( $foreign_origin ), 'Cloned or conflicting origin got a binding.' );
MAD4B_SCP_Site_Profile::$origin_matches = true;
MAD4B_SCP_Site_Profile::$digest = str_repeat( 'b', 64 );
$profile_changed = MAD4B_SCP_Addon_Registry::mutation_guard(
    'mad4b-test-addon', $active['pair_fingerprint'], $active['certification_fingerprint']
);
$check( is_wp_error( $profile_changed )
    && 'mad4b_addon_pair_not_certified' === $profile_changed->get_error_code(),
    'A changed company Site Profile digest inherited an old exact certificate.' );
// Once the new Site Profile is independently re-certified, the old planning
// fingerprint must still be rejected by the original mutation guard.
$updated_profile_pair = $dynamic;
$updated_profile_pair['certified_pairs'][0]['site_profile_digest'] = MAD4B_SCP_Site_Profile::profile_digest();
$write_dynamic( $updated_profile_pair );
$profile_changed_with_recert = MAD4B_SCP_Addon_Registry::mutation_guard(
    'mad4b-test-addon', $active['pair_fingerprint'], $active['certification_fingerprint']
);
$check( is_wp_error( $profile_changed_with_recert )
    && 'mad4b_addon_pair_fingerprint_drift' === $profile_changed_with_recert->get_error_code(),
    'New company profile certificate reused the old planned pair fingerprint.' );
MAD4B_SCP_Site_Profile::$digest = str_repeat( 'a', 64 );
$dynamic_invalid = $dynamic;
$dynamic_invalid['release_policy']['production_authorized'] = true;
$write_dynamic( $dynamic_invalid );
$bad_policy = MAD4B_SCP_Addon_Registry::status()['addons'][0];
$check( $bad_policy['state'] === 'manifest_invalid'
    && 'mad4b_addon_dynamic_release_policy_invalid' === $bad_policy['error_code'],
    'Untrusted dynamic release policy was accepted.' );
$dynamic_invalid = $dynamic;
$dynamic_invalid['release_ring'] = 'staging';
$write_dynamic( $dynamic_invalid );
$staging_ring = MAD4B_SCP_Addon_Registry::status()['addons'][0];
$check( $staging_ring['state'] === 'manifest_invalid'
    && 'mad4b_addon_release_ring_invalid' === $staging_ring['error_code'],
    'Server environment illegally substituted for a release lifecycle ring.' );

@unlink( $addon_file );
@unlink( MAD4B_SCP_DIR . 'config/wordpress-addon-catalog.json' );
@rmdir( WP_PLUGIN_DIR . '/mad4b-test-addon' );
@rmdir( WP_PLUGIN_DIR );
@rmdir( MAD4B_SCP_DIR . 'config' );
@rmdir( MAD4B_SCP_DIR );

echo "PASS: mad4b.wordpress-addon-execution-binding.v1 original exact source assertions\n";
