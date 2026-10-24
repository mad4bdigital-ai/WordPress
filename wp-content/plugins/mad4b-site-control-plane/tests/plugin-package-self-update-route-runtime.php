<?php
/** Hermetic guard: control-plane self updates may not enter third-party package plan. */
define( 'ABSPATH', __DIR__ . '/' );
class WP_Error {
    private $code;
    private $data;
    public function __construct( $code, $message, $data = null ) { $this->code = $code; $this->data = $data; }
    public function get_error_code() { return $this->code; }
    public function get_error_data() { return $this->data; }
}
require_once __DIR__ . '/../includes/class-mad4b-scp-plugin-package.php';
$authority = new ReflectionMethod( 'MAD4B_SCP_Plugin_Package', 'authority' );
$authority->setAccessible( true );
foreach ( array( 'mad4b', 'mad4b-site-control-plane', 'mad4b_site_control_plane' ) as $id ) {
    $result = $authority->invoke( null, $id, '' );
    if ( ! $result instanceof WP_Error || 'mad4b_control_plane_self_update_route_required' !== $result->get_error_code() ) {
        fwrite( STDERR, 'self-update incorrectly entered provider pipeline: ' . $id . PHP_EOL );
        exit( 1 );
    }
}
foreach ( array(
    'mad4b' => 'governed_control_plane_self_update',
    'mad4b-site-control-plane' => 'governed_control_plane_self_update',
    'missing-provider' => 'certified_provider_inventory',
) as $id => $expected_route ) {
    $result = $authority->invoke( null, $id, '' );
    $data = $result instanceof WP_Error ? $result->get_error_data() : null;
    if ( ! is_array( $data ) || ( $data['route'] ?? '' ) !== $expected_route
        || ( $data['production_allowed'] ?? true ) !== false
        || ( $data['auto_apply_allowed'] ?? true ) !== false
        || ( $data['mutation_performed'] ?? true ) !== false ) {
        fwrite( STDERR, 'non-authorizing route guidance invalid: ' . $id . PHP_EOL );
        exit( 1 );
    }
}
echo "plugin-package-self-update-route: PASS\n";
