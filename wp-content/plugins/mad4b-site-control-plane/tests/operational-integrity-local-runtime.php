<?php
/** Synthetic PHP runtime regressions. Does not assert live MCP or Staging acceptance. */
if ( ! defined( 'ABSPATH' ) ) define( 'ABSPATH', __DIR__ . '/' );
class WP_Error {
    private $code;
    public function __construct( $code, $message = '', $data = null ) { $this->code = $code; }
    public function get_error_code() { return $this->code; }
}
function is_wp_error( $value ) { return $value instanceof WP_Error; }
function add_action() {}
class MAD4B_SCP_Identifiers {}
class Mock_Ability {
    private $args;
    public function __construct( $args ) { $this->args = $args; }
    public function get_category() { return $this->args['category']; }
    public function get_meta() { return $this->args['meta']; }
}
$GLOBALS['mock_abilities'] = array();
function wp_has_ability( $name ) { return isset( $GLOBALS['mock_abilities'][ $name ] ); }
function wp_get_ability( $name ) { return $GLOBALS['mock_abilities'][ $name ] ?? null; }
function wp_register_ability( $name, $args ) {
    if ( wp_has_ability( $name ) ) return null;
    return $GLOBALS['mock_abilities'][ $name ] = new Mock_Ability( $args );
}
function acceptance( $truth, $message ) {
    if ( ! $truth ) { fwrite( STDERR, 'FAIL ' . $message . PHP_EOL ); exit( 1 ); }
}
require_once dirname( __DIR__ ) . '/includes/class-mad4b-scp-deployment-mode-resolver.php';
require_once dirname( __DIR__ ) . '/includes/class-mad4b-scp-content-jobs.php';
$ability = MAD4B_SCP_Deployment_Mode_Resolver::ABILITY;
acceptance( MAD4B_SCP_Deployment_Mode_Resolver::mcp_registration_status()['code'] === 'REGISTERED_NOT_MOUNTED', 'no auto-mount without owned registration' );
MAD4B_SCP_Deployment_Mode_Resolver::register_ability();
acceptance( MAD4B_SCP_Deployment_Mode_Resolver::mcp_registration_status()['ready'] === true, 'registered own ability eligible for dedicated server only' );
acceptance( wp_get_ability( $ability )->get_meta()['mcp']['public'] === false, 'ability is not public on default server' );
MAD4B_SCP_Deployment_Mode_Resolver::register_ability();
acceptance( MAD4B_SCP_Deployment_Mode_Resolver::mcp_registration_status()['ready'] === true, 'own idempotent registration remains eligible' );
$GLOBALS['mock_abilities'][ $ability ] = new Mock_Ability( array( 'category' => 'mad4b-read', 'meta' => array( 'mcp' => array( 'public' => false ) ) ) );
acceptance( MAD4B_SCP_Deployment_Mode_Resolver::mcp_registration_status()['code'] === 'ABILITY_REGISTRATION_COLLISION', 'foreign registry instance rejected' );
MAD4B_SCP_Deployment_Mode_Resolver::register_ability();
acceptance( MAD4B_SCP_Deployment_Mode_Resolver::mcp_registration_status()['ready'] === false, 'collision remains fail-closed' );

$method = new ReflectionMethod( 'MAD4B_SCP_Content_Jobs', 'normalize_datetime' );
$correct = array(
    '2026-10-10T10:00:00+03:00' => '2026-10-10 07:00:00',
    '2026-10-10T07:00:00Z' => '2026-10-10 07:00:00',
    '2026-10-10T08:30:00.500+01:30' => '2026-10-10 07:00:00',
);
foreach ( $correct as $input => $expected ) {
    acceptance( $method->invoke( null, $input ) === $expected, 'UTC normalization of exact offset ' . $input );
}
acceptance( null === $method->invoke( null, '' ), 'missing publish date remains unscheduled' );
foreach ( array(
    'next Tuesday at 10', '2026-10-10 10:00:00', '2026-10-10T10:00:00',
    '2026-02-30T10:00:00+03:00', '2026-10-10T25:00:00Z',
    '2026-10-10T07:00:00+14:15', '2026-10-10T07:00:00+15:00',
) as $unsafe ) {
    $result = $method->invoke( null, $unsafe );
    acceptance( is_wp_error( $result ), 'reject ambiguous, invalid or timezone-free timestamp ' . $unsafe );
}
$source = file_get_contents( dirname( __DIR__ ) . '/includes/class-mad4b-scp-servers.php' );
acceptance( substr_count( $source, 'MAD4B_SCP_Deployment_Mode_Resolver::mcp_registration_status()' ) >= 2, 'both owned custom server projections are registered' );
echo "PASS: local operational-integrity synthetic runtime (no live MCP certification)" . PHP_EOL;
