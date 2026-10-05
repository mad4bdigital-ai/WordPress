<?php
/** Real admin render/handler regressions with strict PHP warnings. */
define( 'ABSPATH', __DIR__ . '/' );
set_error_handler( static function ( $severity, $message, $file, $line ) { throw new ErrorException( $message, 0, $severity, $file, $line ); } );
function check( $ok, $why ) { if ( ! $ok ) throw new RuntimeException( $why ); }
function add_action() {}
function __( $text, $domain = '' ) { return $text; }
function _n( $one, $many, $count, $domain = '' ) { return 1 === $count ? $one : $many; }
function esc_attr( $value ) { return htmlspecialchars( (string) $value, ENT_QUOTES, 'UTF-8' ); }
function esc_html( $value ) { return esc_attr( $value ); }
function esc_html__( $text, $domain = '' ) { return esc_html( $text ); }
function esc_url( $value ) { return esc_attr( $value ); }
function wp_json_encode( $value ) { return json_encode( $value ); }
function admin_url( $path = '' ) { return 'https://fixture.test/wp-admin/' . $path; }
function wp_nonce_field( $action ) { echo '<input name="_wpnonce" value="fixture">'; }
function submit_button( $label, $style = '', $name = 'submit', $wrap = true ) { echo '<button type="submit">' . esc_html( $label ) . '</button>'; }
function selected( $value, $expected, $echo = true ) { return $value === $expected ? ' selected' : ''; }
function checked( $value, $expected, $echo = true ) { return $value === $expected ? ' checked' : ''; }
function add_query_arg( $args, $url ) { return $url . '?' . http_build_query( $args ); }
function sanitize_key( $value ) { return preg_replace( '/[^a-z0-9_-]/', '', strtolower( (string) $value ) ); }
function sanitize_text_field( $value ) { return trim( strip_tags( (string) $value ) ); }
function wp_unslash( $value ) { return $value; }
function current_user_can( $capability ) { return $GLOBALS['allowed']; }
function check_ajax_referer( $action, $field, $die ) { return $GLOBALS['nonce_valid']; }
function wp_doing_ajax() { return true; }
function is_wp_error( $value ) { return false; }
class JsonResult extends Exception { public $success; public $data; function __construct( $success, $data ) { $this->success = $success; $this->data = $data; } }
function wp_send_json_error( $data, $status = 200 ) { throw new JsonResult( false, $data ); }
function wp_send_json_success( $data ) { throw new JsonResult( true, $data ); }
$GLOBALS['allowed'] = true; $GLOBALS['nonce_valid'] = true;
final class MAD4B_SCP_Google_Drive_Context {
 const AUTH_MODE_MANAGED = 'managed_google'; const AUTH_MODE_DEDICATED = 'dedicated_google'; const AUTH_MODE_CUSTOM = 'custom_credentials';
 static $mode = 'dedicated_google'; static $configured = true; static $connected = false; static $managed = true; static $dedicated = true;
 static function credentials_status() { return array( 'configured' => self::$configured, 'configured_by_constants' => true, 'client_id' => 'PUBLIC_ID', 'managed_redirect_uri' => 'https://fixture.test/managed', 'dedicated_redirect_uri' => 'https://fixture.test/dedicated', 'custom_redirect_uri' => 'https://fixture.test/custom' ); }
 static function connection_status() { return array( 'connected' => self::$connected, 'write_available' => self::$connected, 'read_available' => self::$connected, 'account_email' => '', 'revocation_pending' => false, 'token_unreadable' => false ); }
 static function auth_mode_status() { return array( 'mode' => self::$mode, 'managed_google' => array( 'configured' => self::$managed ), 'dedicated_google' => array( 'configured' => self::$dedicated, 'redirect_uri' => 'https://fixture.test/dedicated' ) ); }
 static function workspace_grants_status() { return array( 'catalog' => array(), 'selection' => array( 'drive' => 'read' ), 'scope_count' => 1, 'grant_sha256' => str_repeat( 'a', 64 ), 'incremental_consent_required' => false ); }
 static function full_suite_grant_selection() { return array( 'drive' => 'full' ); }
}
final class MAD4B_SCP_Context_Authority {
 const AI_REVIEW_ABILITY = 'mad4b/context-ai-review';
 static $profile = array( 'brand_name' => 'Before', 'revision' => 2 ); static $sources = array(); static $policy = array( 'mode' => 'human_only', 'ai_agent_public_id' => '' );
 static function profile() { return self::$profile; }
 static function save_profile( $name ) { self::$profile = array( 'brand_name' => trim( $name ), 'revision' => self::$profile['revision'] + 1 ); return self::$profile; }
 static function sources() { return self::$sources; }
 static function review_queue() { return array( 'items' => array() ); }
 static function write_policies() { return array( 'read_only' => array( 'label' => 'Read-only' ), 'update_only' => array( 'label' => 'Update' ) ); }
 static function update_source_write_policy( $id, $policy, $confirmed ) { self::$sources[ $id ]['write_policy'] = $policy; return self::$sources[ $id ]; }
 static function review_policy() { return self::$policy; }
 static function ai_review_policy_status() { return array( 'exact_grant_ready' => false ); }
 static function source_allows_write( $id, $operation ) { return 'update' === $operation && 'update_only' === self::$sources[ $id ]['write_policy']; }
}
final class MAD4B_SCP_Staging_Write_Authority {
 static $current = false;
 static function persisted_status() { return array( 'ready' => true ); }
 static function candidate_binding_status() { return array( 'required' => true, 'match' => self::$current ); }
}
final class MAD4B_SCP_Servers {
 static function ability_is_mounted( $server, $ability ) { return true; }
 static function write_tools() { return array(); }
 static function external_write_tools() { return array(); }
}
require dirname( __DIR__ ) . '/includes/class-mad4b-scp-admin-settings-persistence.php';
require dirname( __DIR__ ) . '/includes/class-mad4b-scp-context-admin-ui.php';
function render( $method ) {
 $reflection = new ReflectionMethod( 'MAD4B_SCP_Context_Admin_UI', $method ); $reflection->setAccessible( true );
 ob_start(); try { $reflection->invoke( null ); return ob_get_contents(); } finally { ob_end_clean(); }
}
function views( $html ) {
 preg_match_all( '/data-mad4b-settings-view="([^"]+)"/', $html, $matches );
 return array_map( static function ( $value ) { return json_decode( html_entity_decode( $value, ENT_QUOTES, 'UTF-8' ), true ); }, $matches[1] );
}
foreach ( array( 'managed_google', 'dedicated_google', 'custom_credentials' ) as $mode ) {
 MAD4B_SCP_Google_Drive_Context::$mode = $mode;
 $html = render( 'render_google_drive' );
 preg_match( '/<form[^>]*class="mad4b-google-primary-signin-form"[^>]*>(.*?)<\/form>/s', $html, $form );
 check( ! empty( $form[1] ), 'Configured selected OAuth method has no primary connect form: ' . $mode );
 check( ( 'managed_google' === $mode ) === ( false !== strpos( $form[1], 'name="managed_signin"' ) ), 'Primary connect silently switched authentication method: ' . $mode );
}
MAD4B_SCP_Google_Drive_Context::$mode = 'dedicated_google'; MAD4B_SCP_Google_Drive_Context::$dedicated = false;
check( false === strpos( render( 'render_google_drive' ), 'class="mad4b-google-primary-signin-form"' ), 'Configured unrelated broker concealed missing Dedicated setup' );
MAD4B_SCP_Google_Drive_Context::$dedicated = true;
foreach ( array( 'first-source', 'second-source' ) as $id ) MAD4B_SCP_Context_Authority::$sources[ $id ] = array( 'source_id' => $id, 'label' => $id, 'external_root_id' => $id, 'mode' => 'governed', 'write_policy' => 'read_only', 'task_scope' => '', 'status' => 'active', 'asset_count' => 0, 'last_synced_at' => '' );
$_POST = array( 'source_id' => 'second-source', 'write_policy' => 'update_only' );
try { MAD4B_SCP_Context_Admin_UI::handle_update_source_policy(); throw new RuntimeException( 'AJAX did not terminate' ); } catch ( JsonResult $response ) {
 check( $response->success && $response->data['persistence_verified'], 'Source policy handler lost persisted readback' );
 $rendered = views( render( 'render_sources' ) );
 check( 2 === count( $rendered ) && $rendered[1] === $response->data['view_readback'], 'Server-rendered source form did not match its exact AJAX proof' );
}
$_POST = array( 'brand_name' => 'After' );
try { MAD4B_SCP_Context_Admin_UI::handle_save_profile(); throw new RuntimeException( 'AJAX did not terminate' ); } catch ( JsonResult $response ) {
 check( $response->success && array( 'brand_name' => 'After', 'revision' => 3 ) === $response->data['view_readback']['fields'], 'Brand proof lost typed persisted values' );
}
$policy_view = views( render( 'render_review_policy_panel' ) );
check( 1 === count( $policy_view ) && array( 'revision' => 3, 'mode' => 'human_only', 'ai_agent_public_id' => '' ) === $policy_view[0]['fields'], 'Review policy view proof used unrelated Site Profile CAS fields' );
$before = MAD4B_SCP_Context_Authority::$profile;
foreach ( array( 'nonce', 'capability' ) as $denied ) {
 $GLOBALS['nonce_valid'] = 'nonce' !== $denied; $GLOBALS['allowed'] = 'capability' !== $denied;
 try { MAD4B_SCP_Context_Admin_UI::handle_save_profile(); throw new RuntimeException( 'Denied AJAX did not terminate' ); } catch ( JsonResult $response ) { check( ! $response->success && $before === MAD4B_SCP_Context_Authority::$profile, 'View repair bypassed administrator/nonce admission' ); }
}
$GLOBALS['nonce_valid'] = true; $GLOBALS['allowed'] = true;
MAD4B_SCP_Google_Drive_Context::$connected = true;
check( false !== strpos( render( 'render_write_governance_readiness' ), 'runtime_authority_not_reconciled' ), 'Stale candidate did not show bounded reconciliation advice' );
MAD4B_SCP_Staging_Write_Authority::$current = true;
check( false !== strpos( render( 'render_write_governance_readiness' ), 'Governed Drive write checkpoint is current.' ), 'Current checkpoint was not rendered, or obsolete variables survived' );
echo "Context admin recovery render/handler runtime: PASS\n";
