<?php
/* Synthetic scope/brand-isolation contract; no WordPress database or network. */
define( 'ABSPATH', '/' );
class WP_Error {
    private $code;
    public function __construct( $code, $message = '', $data = array() ) { $this->code = $code; }
    public function get_error_code() { return $this->code; }
}
function is_wp_error( $value ) { return $value instanceof WP_Error; }
function sanitize_key( $value ) { return strtolower( preg_replace( '/[^a-z0-9_\-]/i', '', (string) $value ) ); }
function get_current_blog_id() { return 1; }
function get_current_network_id() { return 1; }
function get_current_user_id() { return 7; }
function wp_json_encode( $v, $flags = 0 ) { return json_encode( $v, $flags ); }
function get_option( $name, $fallback = array() ) { return $GLOBALS['mad4b_options'][$name] ?? $fallback; }
function check( $yes, $name ) { if ( ! $yes ) { fwrite( STDERR, "FAIL: " . $name . PHP_EOL ); exit( 1 ); } }
class MAD4B_SCP_Site_Profile {
    public static function status() { return array( 'configured' => true, 'site_uuid' => $GLOBALS['site_uuid'], 'origin_match' => true, 'environment_match' => true, 'environment' => 'staging' ); }
}
class MAD4B_SCP_Deployment_Mode_Resolver {
    public static $blocked = false;
    public static function resolve() {
        if ( self::$blocked ) return array( 'status' => 'BLOCKED', 'reason' => 'SOURCE_REVOKED' );
        return array( 'status' => 'RESOLVED_FOR_REVIEW_ONLY', 'active_mode' => 'wordpress_dedicated',
            'scope' => array( 'site_uuid' => $GLOBALS['site_uuid'], 'brand_ref' => $GLOBALS['brand_id'], 'tenant_ref' => 'wp-site:' . $GLOBALS['site_uuid'], 'blog_id' => 1, 'network_id' => 1, 'environment' => 'staging', 'deployment_mode' => 'wordpress_dedicated' ),
            'dependency_revision' => array( 'site_profile' => 2, 'brand_profile' => 3 ) );
    }
}
require_once dirname( __DIR__ ) . '/includes/class-mad4b-scp-operational-integrity.php';
require_once dirname( __DIR__ ) . '/includes/class-mad4b-scp-operational-scope-guard.php';
require_once dirname( __DIR__ ) . '/includes/class-mad4b-scp-context-authority.php';
$GLOBALS['site_uuid'] = '11111111-2222-4333-8444-555555555555';
$GLOBALS['brand_id'] = str_repeat( 'a', 32 );
$GLOBALS['mad4b_options'] = array(
    MAD4B_SCP_Context_Authority::PROFILE_OPTION => array(
        'contract' => MAD4B_SCP_Context_Authority::PROFILE_CONTRACT, 'site_uuid' => $GLOBALS['site_uuid'],
        'brand_id' => $GLOBALS['brand_id'], 'brand_name' => 'Original Name', 'revision' => 3 ),
);
$scope = MAD4B_SCP_Operational_Scope_Guard::require_current();
check( ! is_wp_error( $scope ) && $scope['brand_ref'] === $GLOBALS['brand_id'], 'trusted scope resolved' );
check( ! is_wp_error( MAD4B_SCP_Operational_Scope_Guard::require_brand( $GLOBALS['brand_id'], $scope ) ), 'own brand accepted' );
$stale_scope = $scope;
$stale_scope['brand_ref'] = str_repeat( 'b', 32 );
check( is_wp_error( MAD4B_SCP_Operational_Scope_Guard::require_brand( $GLOBALS['brand_id'], $stale_scope ) ), 'caller-provided foreign brand assertion rejected' );
$stale_scope = $scope;
$stale_scope['blog_id'] = 99;
check( is_wp_error( MAD4B_SCP_Operational_Scope_Guard::require_brand( $GLOBALS['brand_id'], $stale_scope ) ), 'caller-provided stale blog scope rejected' );

check( is_wp_error( MAD4B_SCP_Operational_Scope_Guard::require_brand( str_repeat( 'b', 32 ), $scope ) ), 'foreign brand rejected' );
$source = static function ( $id, $brand ) {
    return array( 'contract' => MAD4B_SCP_Context_Authority::SOURCE_CONTRACT, 'source_id' => $id,
        'site_uuid' => $GLOBALS['site_uuid'], 'brand_id' => $brand, 'external_root_id' => 'folder-1',
        'mode' => 'governed', 'write_policy' => 'read_only' );
};
$sources = array(
    'own' => $source( 'own', $GLOBALS['brand_id'] ),
    'foreign' => $source( 'foreign', str_repeat( 'b', 32 ) ),
    'missing' => $source( 'missing', '' ),
);
check( MAD4B_SCP_Operational_Scope_Guard::source_in_scope( $sources['own'], $scope ), 'owned source stays in bound scope' );
$stored = $sources['own'];
$stored['tenant_id'] = 'wp-site:' . $GLOBALS['site_uuid'];
$stored['blog_id'] = 1;
$stored['network_id'] = 1;
$stored['environment'] = 'staging';
check( MAD4B_SCP_Operational_Scope_Guard::record_metadata_matches( $stored, $scope ), 'matching persisted tenant and blog evidence accepted' );
foreach ( array(
    'tenant_id' => 'wp-site:foreign',
    'tenant_ref' => 'wp-site:foreign',
    'blog_id' => 2,
    'network_id' => 2,
    'environment' => 'production',
    'deployment_mode' => 'shared_multi_tenant'
) as $field => $foreign ) {
    $forged = $stored;
    $forged[ $field ] = $foreign;
    check( ! MAD4B_SCP_Operational_Scope_Guard::record_metadata_matches( $forged, $scope ),
        'contradicting persisted metadata denied: ' . $field );
}
$legacy_unknown = $stored;
unset( $legacy_unknown['brand_id'] );
check( ! MAD4B_SCP_Operational_Scope_Guard::record_metadata_matches( $legacy_unknown, $scope ),
    'unknown legacy brand is quarantined rather than adopted or deleted' );
$census = MAD4B_SCP_Operational_Scope_Guard::legacy_reconciliation_census( array(
    $stored,
    array_merge( $stored, array( 'tenant_id' => 'wp-site:foreign' ) ),
    $legacy_unknown,
    array_merge( $stored, array( 'brand_id' => str_repeat( 'b', 32 ) ) ),
    'invalid'
) );
check( ! is_wp_error( $census ) && $census['rows_inspected'] === 5 &&
    $census['counts']['already_owned'] === 1 &&
    $census['counts']['conflicting_metadata'] === 1 &&
    $census['counts']['owner_unknown'] === 1 &&
    $census['counts']['foreign_scope'] === 1 &&
    $census['counts']['malformed'] === 1 &&
    $census['quarantined'] === 4 &&
    $census['migration_authorized'] === false &&
    $census['mutation_performed'] === false,
    'legacy census classifies without adopting, deleting or exposing records' );
check( ! MAD4B_SCP_Operational_Scope_Guard::source_in_scope( $sources['own'], $stale_scope ), 'stale asserted scope cannot authorize owned source' );
$ref = new ReflectionClass( 'MAD4B_SCP_Context_Authority' );
$source_filter = $ref->getMethod( 'authorized_sources_from_records' );
$source_filter->setAccessible( true );
$visible = $source_filter->invoke( null, $sources, array( 'site_uuid' => $GLOBALS['site_uuid'] ) );
check( array_keys( $visible ) === array( 'own' ), 'only current brand source visible' );
$asset_filter = $ref->getMethod( 'authorized_assets_from_records' );
$asset_filter->setAccessible( true );
$mk_asset = static function ( $id, $src, $brand = '' ) {
    return array( 'contract' => MAD4B_SCP_Context_Authority::ASSET_CONTRACT, 'asset_id' => $id,
        'site_uuid' => $GLOBALS['site_uuid'], 'brand_id' => $brand, 'file_id' => 'f-' . $id,
        'source_id' => $src, 'source_mode' => 'governed' );
};
$assets = array(
    'own' => $mk_asset( 'own', 'own', $GLOBALS['brand_id'] ),
    'foreign' => $mk_asset( 'foreign', 'foreign', str_repeat( 'b', 32 ) ),
    'orphan' => $mk_asset( 'orphan', 'missing', '' ),
    'forged' => $mk_asset( 'forged', 'own', str_repeat( 'b', 32 ) ),
);
$visible_assets = $asset_filter->invoke( null, $assets, $visible, array( 'site_uuid' => $GLOBALS['site_uuid'] ) );
check( array_keys( $visible_assets ) === array( 'own' ), 'foreign, orphan and forged assets quarantined' );
MAD4B_SCP_Deployment_Mode_Resolver::$blocked = true;
check( is_wp_error( MAD4B_SCP_Operational_Scope_Guard::require_current() ), 'missing host binding fails closed' );
check( empty( $source_filter->invoke( null, $sources, array( 'site_uuid' => $GLOBALS['site_uuid'] ) ) ), 'revoked site sees no sources' );
$mutation = $ref->getMethod( 'with_registry_lock' );
$mutation->setAccessible( true );
$blocked_mutation = $mutation->invoke( null, 'upsert_source', static function () { return true; } );
check( is_wp_error( $blocked_mutation ) && 'mad4b_scope_not_bound' === $blocked_mutation->get_error_code(),
    'all Context governance mutations are fenced before locks and callbacks' );
echo 'PASS unified operational scope synthetic PHP tests' . PHP_EOL;
