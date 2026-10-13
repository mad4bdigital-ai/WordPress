<?php
/**
 * Standalone exact runtime admin form admission: zero WordPress/Google mutations.
 * Requires PHP 8.3 syntax/semantics, does NOT certify full update execution.
 */
if ( ! defined( 'ABSPATH' ) ) define( 'ABSPATH', __DIR__ . '/' );
class WP_Error {
    private $code;
    public function __construct( $code, $message = '' ) { $this->code = $code; }
    public function get_error_code() { return $this->code; }
}
function is_wp_error( $value ) { return $value instanceof WP_Error; }
function wp_unslash( $value ) { return $value; }
function sanitize_text_field( $value ) { return trim( (string) $value ); }
function mad4b_preflight_assert( $actual, $want, $case ) {
    $state = is_wp_error( $actual ) ? $actual->get_error_code() : ( true === $actual ? 'PASS' : 'UNEXPECTED' );
    if ( $state !== $want ) { fwrite( STDERR, 'FAIL ' . $case . ': observed ' . $state . ', wanted ' . $want . "\n" ); exit( 1 ); }
}
require dirname( __DIR__ ) . '/includes/class-mad4b-scp-runtime-release-set.php';
$installed = str_repeat( 'a', 40 );
$release = str_repeat( 'b', 64 );
$target_sha = str_repeat( 'c', 40 );
$runtime = array(
    'current' => array( 'control_plane' => array( 'version' => '0.4.0-rc.96', 'source_commit_sha' => $installed ) ),
    'target' => array( 'control_plane' => array( 'version' => '0.4.0-rc.97', 'source_commit_sha' => $target_sha ) ),
    'release_set_sha256' => $release,
    'transaction' => array(),
);
$input = array( 'expected_release_set_sha256' => $release, 'expected_current_source_commit_sha' => $installed );
$apply = static function ( $status, $form, $method = 'POST' ) {
    return MAD4B_SCP_Runtime_Release_Set::admin_form_preflight( $status, $form, $method );
};
mad4b_preflight_assert( $apply( $runtime, $input ), 'PASS', 'exact fresh signed POST' );
mad4b_preflight_assert( $apply( $runtime, $input, 'GET' ), 'mad4b_runtime_admin_post_required', 'GET forbidden' );
mad4b_preflight_assert( $apply( $runtime, array() ), 'mad4b_runtime_admin_stale_form', 'unsigned old form rejected' );
mad4b_preflight_assert( $apply( $runtime, array_merge( $input, array( 'expected_release_set_sha256' => str_repeat( 'd', 64 ) ) ) ), 'mad4b_runtime_admin_stale_form', 'changed release target' );
mad4b_preflight_assert( $apply( $runtime, array_merge( $input, array( 'expected_current_source_commit_sha' => str_repeat( 'e', 40 ) ) ) ), 'mad4b_runtime_admin_stale_form', 'installed package switched' );
$missing = $runtime; unset( $missing['target']['control_plane']['source_commit_sha'] );
mad4b_preflight_assert( $apply( $missing, $input ), 'mad4b_runtime_admin_target_unavailable', 'missing target commit' );
$missing = $runtime; unset( $missing['release_set_sha256'] );
mad4b_preflight_assert( $apply( $missing, $input ), 'mad4b_runtime_admin_target_unavailable', 'missing set digest' );
$older = $runtime; $older['target']['control_plane']['version'] = '0.4.0-rc.95';
mad4b_preflight_assert( $apply( $older, $input ), 'mad4b_runtime_admin_downgrade_blocked', 'stale rc.95 downgrade' );
$active = $runtime; $active['transaction'] = array( 'state' => 'awaiting_runtime_readback' );
mad4b_preflight_assert( $apply( $active, $input ), 'mad4b_runtime_admin_transition_in_progress', 'active update blocks replay' );
$committed = $runtime; $committed['transaction'] = array( 'state' => 'committed' );
mad4b_preflight_assert( $apply( $committed, $input ), 'PASS', 'committed transaction no longer active' );
$next_same_version = $runtime; $next_same_version['target']['control_plane']['version'] = '0.4.0-rc.96';
mad4b_preflight_assert( $apply( $next_same_version, $input ), 'PASS', 'same version different exact SHA requires independent plan' );
echo "PASS: runtime update admin form immutable source/target and downgrade/transaction boundaries\n";
