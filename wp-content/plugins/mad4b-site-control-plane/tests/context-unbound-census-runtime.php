<?php
/* Unbound identity diagnostics: only counts, no ownership attribution/write. */
define( 'ABSPATH', __DIR__ );
class WP_Error {
    private $code;
    public function __construct( $code, $message = '' ) { $this->code = $code; }
    public function get_error_code() { return $this->code; }
}
function is_wp_error( $x ) { return $x instanceof WP_Error; }
function wp_json_encode( $value, $flags = 0 ) { return json_encode( $value, $flags ); }
$GLOBALS['is_admin'] = true;
$GLOBALS['legacy_rows'] = array();
function current_user_can( $cap ) { return $GLOBALS['is_admin']; }
function get_option( $name, $default = false ) {
    return array_key_exists( $name, $GLOBALS['legacy_rows'] )
        ? $GLOBALS['legacy_rows'][ $name ] : $default;
}
class MAD4B_SCP_Policy { public static function can_read() { return true; } }
class MAD4B_SCP_Operational_Integrity {
    public static $blocked = true;
    public static function capture() {
        return self::$blocked
            ? new WP_Error( 'mad4b_integrity_scope_blocked' )
            : array( 'fingerprint' => str_repeat( 'a', 64 ) );
    }
    public static function assert_unchanged( $checkpoint, $mutation = false ) { return true; }
}
class MAD4B_SCP_Deployment_Mode_Resolver {
    public static $reason = 'SITE_DEPLOYMENT_BINDING_NOT_ENROLLED';
    public static function resolve() { return array( 'reason' => self::$reason, 'status' => 'BLOCKED' ); }
}
class MAD4B_SCP_Site_Profile {
    public static $production = false;
    public static $bound = false;
    public static function status() {
        return array(
            'configured_environment' => self::$production ? 'production' : 'staging',
            'environment' => self::$production ? 'production' : 'staging',
            'authority_ready' => true, 'origin_match' => true,
            'environment_match' => true,
            'deployment_binding_configured' => self::$bound,
            'deployment_binding_bound' => self::$bound,
        );
    }
}
require dirname( __DIR__ ) . '/includes/class-mad4b-scp-context-authority.php';
require dirname( __DIR__ ) . '/includes/class-mad4b-scp-operational-acceptance-evidence.php';
require dirname( __DIR__ ) . '/includes/class-mad4b-scp-brand-core-remote-recovery.php';
function assert_unbound( $ok, $message ) {
    if ( ! $ok ) { fwrite( STDERR, 'FAIL: ' . $message . PHP_EOL ); exit( 1 ); }
}
$src = MAD4B_SCP_Context_Authority::SOURCES_OPTION;
$asset = MAD4B_SCP_Context_Authority::ASSETS_OPTION;
$GLOBALS['legacy_rows'][$src] = array( 'secret-folder-id' => array( 'token' => 'DO_NOT_EXPOSE', 'site_uuid' => null ) );
$GLOBALS['legacy_rows'][$asset] = array();
for ( $i = 0; $i < 12; $i++ ) $GLOBALS['legacy_rows'][$asset]['asset-' . $i] = array( 'private_id' => 'DO_NOT_EXPOSE' );
$r = MAD4B_SCP_Context_Authority::legacy_reconciliation_census();
assert_unbound( is_array( $r )
    && 'UNVERIFIED_DEPLOYMENT_BINDING' === $r['state']
    && $r['source_quarantine_count'] === 1
    && $r['asset_quarantine_count'] === 12
    && $r['sources']['already_owned'] === 0
    && $r['assets']['already_owned'] === 0
    && $r['sources']['scope_unverified'] === 1
    && $r['assets']['scope_unverified'] === 12
    && $r['scope_fingerprint'] === ''
    && !$r['operational_scope_verified']
    && !$r['migration_authorized'] && !$r['legal_rights_verified'],
    'Staging unbound census returns counts without pretending ownership' );
assert_unbound( strpos( json_encode( $r ), 'DO_NOT_EXPOSE' ) === false
    && strpos( json_encode( $r ), 'secret-folder-id' ) === false,
    'do not leak source names or private provider identities' );
$facts = array( 'census' => $r, 'site' => array( 'site_uuid' => str_repeat( 'a', 36 ) ),
    'coverage' => array( 'ready' => true, 'coverage' => array(
        'brand_strategy' => array( 'ready' => true, 'eligible_asset_count' => 1 ),
        'tone_of_voice' => array( 'ready' => true, 'eligible_asset_count' => 1 ),
        'editorial_guidelines' => array( 'ready' => true, 'eligible_asset_count' => 1 ),
    ) ) );
$acceptance = MAD4B_SCP_Operational_Acceptance_Evidence::reduce( $facts, array() );
assert_unbound( !$acceptance['stages']['source_inventory']['evidence_observed']
    && !$acceptance['stages']['brand_approval']['evidence_observed']
    && !$acceptance['full_operational_acceptance'],
    'unbound aggregate must not be certified as accepted Brand evidence' );
$remote = MAD4B_SCP_Brand_Core_Remote_Recovery::reduce( array(
    'site_ready' => true, 'census' => $r ), array() );
assert_unbound( !$remote['stages']['source_inventory']['evidence_observed']
    && !$remote['stages']['brand_approval']['evidence_observed'],
    'remote recovery cannot turn unbound registry counts into ownership' );
MAD4B_SCP_Site_Profile::$production = true;
assert_unbound( is_wp_error( MAD4B_SCP_Context_Authority::legacy_reconciliation_census() ),
    'Production cannot enter diagnostic fallback' );
MAD4B_SCP_Site_Profile::$production = false;
MAD4B_SCP_Site_Profile::$bound = true;
assert_unbound( is_wp_error( MAD4B_SCP_Context_Authority::legacy_reconciliation_census() ),
    'already-bound profile cannot enter missing-binding fallback' );
MAD4B_SCP_Site_Profile::$bound = false;
MAD4B_SCP_Deployment_Mode_Resolver::$reason = 'BRAND_PROFILE_UNRESOLVED';
assert_unbound( is_wp_error( MAD4B_SCP_Context_Authority::legacy_reconciliation_census() ),
    'unrelated identity failures must stay blocked' );
MAD4B_SCP_Deployment_Mode_Resolver::$reason = 'SITE_DEPLOYMENT_BINDING_NOT_ENROLLED';
$GLOBALS['is_admin'] = false;
assert_unbound( is_wp_error( MAD4B_SCP_Context_Authority::legacy_reconciliation_census() ),
    'non-admin cannot observe even quarantined counts' );
$GLOBALS['is_admin'] = true;
$original_assets = $GLOBALS['legacy_rows'][$asset];
$GLOBALS['legacy_rows'][$asset] = 'MALFORMED_OPTIONS_MUST_NOT_BE_ZERO';
assert_unbound( is_wp_error( MAD4B_SCP_Context_Authority::legacy_reconciliation_census() ),
    'malformed WordPress option must not silently become zero quarantined records' );
$GLOBALS['legacy_rows'][$asset] = $original_assets;
for ( $i = 0; $i < 1001; $i++ ) $GLOBALS['legacy_rows'][$asset]['extra-' . $i] = array();
assert_unbound( is_wp_error( MAD4B_SCP_Context_Authority::legacy_reconciliation_census() ),
    'unbounded source list must remain blocked' );
echo "mad4b.site-control-plane.context-unbound-census.runtime.v1: PASS\n";
