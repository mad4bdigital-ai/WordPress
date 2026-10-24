<?php
/** Hermetic guard: control-plane self updates may not enter third-party package plan. */
define( 'ABSPATH', __DIR__ . '/' );
class WP_Error {
    private $code;
    public function __construct( $code, $message ) { $this->code = $code; }
    public function get_error_code() { return $this->code; }
}
require_once __DIR__ . '/../includes/class-mad4b-scp-plugin-package.php';
$authority = new ReflectionMethod( 'MAD4B_SCP_Plugin_Package', 'authority' );
foreach ( array( 'mad4b', 'mad4b-site-control-plane', 'mad4b_site_control_plane' ) as $id ) {
    $result = $authority->invoke( null, $id, '' );
    if ( ! $result instanceof WP_Error || 'mad4b_control_plane_self_update_route_required' !== $result->get_error_code() ) {
        fwrite( STDERR, 'self-update incorrectly entered provider pipeline: ' . $id . PHP_EOL );
        exit( 1 );
    }
}
echo "plugin-package-self-update-route: PASS\n";
