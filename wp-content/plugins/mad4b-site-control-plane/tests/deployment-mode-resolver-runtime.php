<?php
/* Synthetic isolated WordPress Dedicated mode tests; no database or mutation. */
define( 'ABSPATH', '/' );
$GLOBALS['mad4b_mock_actions'] = array();
$GLOBALS['mad4b_mock_abilities'] = array();
$GLOBALS['mad4b_mock_blog'] = 5;
$GLOBALS['mad4b_mock_network'] = 2;
function add_action( $hook, $callback, $priority = 10 ) {
    $GLOBALS['mad4b_mock_actions'][] = $hook;
}
function wp_has_ability( $id ) { return isset( $GLOBALS['mad4b_mock_abilities'][$id] ); }
function wp_register_ability( $id, $args ) { $GLOBALS['mad4b_mock_abilities'][$id] = $args; }
function get_current_blog_id() { return $GLOBALS['mad4b_mock_blog']; }
function get_current_network_id() { return $GLOBALS['mad4b_mock_network']; }
function get_option( $key, $default = null ) {
    return isset( $GLOBALS['mad4b_blog_records'][ $GLOBALS['mad4b_mock_blog'] ][ $key ] )
        ? $GLOBALS['mad4b_blog_records'][ $GLOBALS['mad4b_mock_blog'] ][ $key ] : $default;
}
class MAD4B_SCP_Policy { public static function can_read() { return false; } }
class MAD4B_SCP_Site_Profile {
    const OPTION = 'mad4b_scp_site_profile_v2';
    public static $status = array();
    public static function current_origin() {
        return isset( $GLOBALS['mad4b_mock_origins'][ $GLOBALS['mad4b_mock_blog'] ] )
            ? $GLOBALS['mad4b_mock_origins'][ $GLOBALS['mad4b_mock_blog'] ] : '';
    }
    public static function status() { return self::$status; }
}
class MAD4B_SCP_Context_Authority {
    public static $profile = array();
    public static function profile() { return self::$profile; }
}
require_once dirname( __DIR__ ) . '/includes/class-mad4b-scp-deployment-mode-resolver.php';
function expect( $test, $label ) {
    if ( ! $test ) { fwrite( STDERR, 'FAIL: ' . $label . PHP_EOL ); exit( 1 ); }
}
$uuid = '11111111-2222-4333-8444-555555555555';
MAD4B_SCP_Site_Profile::$status = array(
    'configured' => true, 'site_uuid' => $uuid,
    'origin_match' => true, 'environment_match' => true,
    'deployment_binding_match' => true, 'deployment_binding_bound' => true,
    'deployment_binding_configured' => true, 'authority_ready' => true, 'revision' => 7,
    'environment' => 'staging',
);
$GLOBALS['mad4b_mock_origins'] = array( 5 => 'https://site.example/', 6 => 'https://other.example/' );
$GLOBALS['mad4b_blog_records'] = array(
    5 => array( MAD4B_SCP_Site_Profile::OPTION => array( 'site_uuid' => $uuid, 'revision' => 7, 'canonical_origin' => 'https://site.example/' ) ),
    6 => array( MAD4B_SCP_Site_Profile::OPTION => array( 'site_uuid' => '99999999-2222-4333-8444-555555555555', 'revision' => 3, 'canonical_origin' => 'https://other.example/' ) ),
);
MAD4B_SCP_Context_Authority::$profile = array(
    'site_uuid' => $uuid,
    'brand_id' => 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa', 'revision' => 3,
);
MAD4B_SCP_Deployment_Mode_Resolver::boot();
MAD4B_SCP_Deployment_Mode_Resolver::register_ability();
expect( in_array( 'wp_abilities_api_init', $GLOBALS['mad4b_mock_actions'], true ), 'registers at abilities lifecycle' );
expect( isset( $GLOBALS['mad4b_mock_abilities'][MAD4B_SCP_Deployment_Mode_Resolver::ABILITY] ), 'ability registered' );
expect( $GLOBALS['mad4b_mock_abilities'][MAD4B_SCP_Deployment_Mode_Resolver::ABILITY]['permission_callback'] === array( 'MAD4B_SCP_Policy', 'can_read' ), 'uses existing policy' );
$a = MAD4B_SCP_Deployment_Mode_Resolver::resolve();
expect( $a['status'] === 'RESOLVED_FOR_REVIEW_ONLY', 'dedicated recognized' );
expect( $a['active_mode'] === 'wordpress_dedicated', 'wp mode active' );
expect( $a['common_contract'] === 'mad4b.context-deployment-mode.v1', 'common contract aligned' );
expect( $a['adapter_version'] === '1.1.0', 'adapter version present' );
expect( $a['portable_state'] === 'BOUND_FOR_REVIEW_ONLY', 'portable status projection' );
expect( $a['dependency_revision'] === array( 'site_profile' => 7, 'brand_profile' => 3 ), 'source revisions recorded' );
expect( count( $a['supported_modes'] ) === 4, 'all common modes kept' );
expect( $a['scope']['tenant_ref'] === 'wp-site:' . $uuid, 'tenant from site only' );
expect( $a['scope']['brand_ref'] === str_repeat( 'a', 32 ), 'brand from profile only' );
expect( $a['scope']['blog_id'] === 5 && $a['scope']['network_id'] === 2, 'multisite bound' );
expect( $a['dependency_status']['provider_detection_grants_authority'] === false, 'provider presence no authority' );
expect( $a['dependency_status']['mcp_discovery']['status'] === 'TRANSPORT_NOT_OBSERVED', 'abilities registered does not prove MCP runtime' );
expect( $a['dependency_status']['mcp_discovery']['adapter_class_observed'] === false, 'unloaded official adapter must be reported' );
expect( $a['dependency_status']['mcp_discovery']['pair_certification_required'] === true, 'adapter/control-plane release pair requires certification' );
expect( $a['dependency_status']['mcp_discovery']['pair_certification_verified'] === false, 'adapter cannot certify itself' );
expect( $a['dependency_status']['mcp_discovery']['adapter_runtime_version'] === '', 'unknown adapter version remains unknown' );
expect( $a['dependency_status']['mcp_discovery']['transport_and_registration_independently_certified'] === false, 'MCP requires independent runtime acceptance' );
expect( $a['host_binding_requires_independent_acceptance'] === true, 'runtime evidence still independent' );
expect( $a['execution_authorized'] === false && $a['publication_authorized'] === false, 'no write privileges' );
expect( MAD4B_SCP_Deployment_Mode_Resolver::resolve( $a['scope'] )['status'] === 'RESOLVED_FOR_REVIEW_ONLY', 'exact scope accepted' );
expect( MAD4B_SCP_Deployment_Mode_Resolver::resolve( array( 'tenant_ref' => 'client-chosen' ) )['reason'] === 'REQUEST_SCOPE_MISMATCH', 'tenant spoof rejected' );
expect( MAD4B_SCP_Deployment_Mode_Resolver::resolve( array( 'deployment_mode' => 'shared_multi_tenant' ) )['reason'] === 'REQUEST_SCOPE_MISMATCH', 'mode spoof rejected' );
expect( MAD4B_SCP_Deployment_Mode_Resolver::resolve( array( 'brand_ref' => str_repeat( 'b', 32 ) ) )['reason'] === 'REQUEST_SCOPE_MISMATCH', 'brand spoof rejected' );
expect( MAD4B_SCP_Deployment_Mode_Resolver::resolve( array( 'unapproved' => 'x' ) )['reason'] === 'REQUEST_SCOPE_MISMATCH', 'unknown scope rejected' );
MAD4B_SCP_Site_Profile::$status['revision'] = 0;
expect( MAD4B_SCP_Deployment_Mode_Resolver::resolve()['reason'] === 'SITE_PROFILE_REVISION_MISSING', 'site profile revision required' );
MAD4B_SCP_Site_Profile::$status['revision'] = 7;
MAD4B_SCP_Site_Profile::$status['environment'] = 'mystery';
expect( MAD4B_SCP_Deployment_Mode_Resolver::resolve()['reason'] === 'WORDPRESS_ENVIRONMENT_UNSUPPORTED', 'environment must be explicit and supported' );
MAD4B_SCP_Site_Profile::$status['environment'] = 'staging';
MAD4B_SCP_Site_Profile::$status['deployment_binding_bound'] = false;
expect( MAD4B_SCP_Deployment_Mode_Resolver::resolve()['reason'] === 'SITE_DEPLOYMENT_BINDING_NOT_ENROLLED', 'unbound legacy profile blocked even when match is true' );
MAD4B_SCP_Site_Profile::$status['deployment_binding_bound'] = true;
MAD4B_SCP_Site_Profile::$status['deployment_binding_configured'] = false;
expect( MAD4B_SCP_Deployment_Mode_Resolver::resolve()['reason'] === 'SITE_DEPLOYMENT_BINDING_NOT_ENROLLED', 'deployment binding missing blocked' );
MAD4B_SCP_Site_Profile::$status['deployment_binding_configured'] = true;
MAD4B_SCP_Context_Authority::$profile['revision'] = 0;
expect( MAD4B_SCP_Deployment_Mode_Resolver::resolve()['reason'] === 'BRAND_PROFILE_REVISION_MISSING', 'unversioned brand context blocked' );
MAD4B_SCP_Context_Authority::$profile['revision'] = 3;
MAD4B_SCP_Site_Profile::$status['deployment_binding_match'] = false;
expect( MAD4B_SCP_Deployment_Mode_Resolver::resolve()['reason'] === 'SITE_IDENTITY_NOT_READY', 'clone drift blocks' );
MAD4B_SCP_Site_Profile::$status['deployment_binding_match'] = true;
MAD4B_SCP_Context_Authority::$profile['site_uuid'] = '99999999-2222-4333-8444-555555555555';
expect( MAD4B_SCP_Deployment_Mode_Resolver::resolve()['reason'] === 'BRAND_PROFILE_UNRESOLVED', 'foreign brand blocked' );
MAD4B_SCP_Context_Authority::$profile['site_uuid'] = $uuid;
expect( MAD4B_SCP_Deployment_Mode_Resolver::resolve( array( 'blog_id' => 6 ) )['reason'] === 'REQUEST_SCOPE_MISMATCH', 'request cannot override current blog' );
$GLOBALS['mad4b_mock_blog'] = 6;
expect( MAD4B_SCP_Deployment_Mode_Resolver::resolve()['reason'] === 'SITE_BLOG_LOCAL_BINDING_MISMATCH', 'switched blog may not reuse static site profile cache' );
$GLOBALS['mad4b_mock_blog'] = 5;
$GLOBALS['mad4b_blog_records'][5][ MAD4B_SCP_Site_Profile::OPTION ]['revision'] = 8;
expect( MAD4B_SCP_Deployment_Mode_Resolver::resolve()['reason'] === 'SITE_BLOG_LOCAL_BINDING_MISMATCH', 'same blog changed source revision blocks stale cache' );
$GLOBALS['mad4b_blog_records'][5][ MAD4B_SCP_Site_Profile::OPTION ]['revision'] = 7;
$GLOBALS['mad4b_blog_records'][5][ MAD4B_SCP_Site_Profile::OPTION ]['canonical_origin'] = 'https://cloned.example/';
expect( MAD4B_SCP_Deployment_Mode_Resolver::resolve()['reason'] === 'SITE_BLOG_LOCAL_BINDING_MISMATCH', 'foreign persisted origin blocks' );
$GLOBALS['mad4b_blog_records'][5][ MAD4B_SCP_Site_Profile::OPTION ]['canonical_origin'] = 'https://site.example/';
$saved_blog_record = $GLOBALS['mad4b_blog_records'][5][ MAD4B_SCP_Site_Profile::OPTION ];
unset( $GLOBALS['mad4b_blog_records'][5][ MAD4B_SCP_Site_Profile::OPTION ] );
expect( MAD4B_SCP_Deployment_Mode_Resolver::resolve()['reason'] === 'SITE_NOT_ENROLLED', 'status call must never migrate missing site profile option' );
$GLOBALS['mad4b_blog_records'][5][ MAD4B_SCP_Site_Profile::OPTION ] = $saved_blog_record;
MAD4B_SCP_Site_Profile::$status['configured'] = false;
expect( MAD4B_SCP_Deployment_Mode_Resolver::resolve()['reason'] === 'SITE_NOT_ENROLLED', 'unconfigured blocks' );
echo "PASS wordpress dedicated isolated fixture\n";
