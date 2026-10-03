<?php
// Passive endpoint rows preserve unknown measurements and retain navigable identities.
define( 'ABSPATH', __DIR__ . '/' );
function __( $value, $domain = '' ) { return $value; }
function esc_html( $value ) { return htmlspecialchars( $value, ENT_QUOTES, 'UTF-8' ); }
function esc_attr( $value ) { return esc_html( $value ); }
function esc_html__( $value, $domain = '' ) { return esc_html( $value ); }
function sanitize_key( $value ) { return strtolower( $value ); }
function rest_url( $path ) { return 'https://fixture.test/wp-json/' . $path; }
function esc_url_raw( $value ) { return $value; }
function current_user_can( $capability ) { return false; }
function submit_button( $label, ...$args ) { echo '<input type="submit" value="' . esc_attr( $label ) . '">'; }
class MAD4B_SCP_Servers { static function expected_server_ids() { return array( 'mad4b-chatgpt', 'mad4b-write' ); } static function registration_status() { return array(); } static function write_tools() { throw new RuntimeException( 'Passive UI built a catalog' ); } }
class MAD4B_SCP_MCP_Registration_Bridge { static function server_registration_identity_status( $id ) { return array( 'actual_registered' => false, 'identity_ready' => true, 'state' => 'identity_ready_deep_validation_deferred', 'deep_registration_deferred' => true ); } }
require dirname( __DIR__ ) . '/includes/class-mad4b-scp-connection-status.php';
require dirname( __DIR__ ) . '/includes/class-mad4b-scp-connection-admin-ui.php';
function check( $ok, $message ) { if ( ! $ok ) throw new RuntimeException( $message ); }
$rows = new ReflectionMethod( MAD4B_SCP_Connection_Status::class, 'server_status' ); $rows->setAccessible( true );
$servers = $rows->invoke( null, true );
foreach ( $servers as $row ) {
	check( null === $row['registered'] && null === $row['route_registered'] && null === $row['permission_callback_match'], 'Deferred evidence fabricated as no' );
	check( '' !== $row['surface'] && false !== strpos( $row['endpoint'], $row['server_id'] ), 'Deferred row lost server identity' );
}
$write = new ReflectionMethod( MAD4B_SCP_Connection_Status::class, 'write_surface_summary' ); $write->setAccessible( true );
$write = $write->invoke( null, $servers, true );
check( null === $write['mounted_write_tool_count'] && null === $write['registered'], 'Deferred write values fabricated as zero/no' );
$render = new ReflectionMethod( MAD4B_SCP_Connection_Admin_UI::class, 'render_endpoints' ); $render->setAccessible( true );
ob_start(); $render->invoke( null, array( 'servers' => $servers, 'write_surface' => $write ) ); $html = ob_get_clean();
check( 2 === substr_count( $html, 'data-check="registered">Not checked' ) && 2 === substr_count( $html, 'data-check="route_registered">Not checked' ), 'UI lost unknown registration/route measurements' );
check( false !== strpos( $html, 'data-write-check="tool_count">Not checked' ), 'Unmeasured write catalog rendered as zero' );
check( false !== strpos( $html, 'mad4b-endpoint-diagnostic-form' ) && false === strpos( $html, 'value="deep_endpoints"' ), 'UI submits the old whole-page deep job' );
check( false !== strpos( $html, '/wp-json/mcp/mad4b-chatgpt' ), 'Passive endpoint URL missing' );
$servers[0]['registered'] = false; $servers[0]['route_registered'] = true; $servers[0]['permission_callback_match'] = false;
ob_start(); $render->invoke( null, array( 'servers' => $servers, 'write_surface' => $write ) ); $html = ob_get_clean();
check( false !== strpos( $html, 'data-check="registered">no' ) && false !== strpos( $html, 'data-check="route_registered">yes' ), 'Observed measurements lost distinction from unknown' );
echo "PASS endpoint diagnostic UI: deferred values, complete identities, write counts and observed yes/no\n";
