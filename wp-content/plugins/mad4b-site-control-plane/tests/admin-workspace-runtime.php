<?php
/** Local UI contract fixtures; real WordPress coverage lives in admin-pages-wordpress.php. */
define( 'ABSPATH', __DIR__ );
define( 'MAD4B_SCP_DIR', dirname( __DIR__ ) . '/' );
define( 'MAD4B_SCP_FILE', MAD4B_SCP_DIR . 'mad4b-site-control-plane.php' );
define( 'MAD4B_SCP_VERSION', 'fixture' );
$GLOBALS['workspace_caps'] = array( 'manage_options' => true );
$GLOBALS['workspace_admin'] = true;
$GLOBALS['workspace_ajax'] = false;
$GLOBALS['workspace_assets'] = array();
function __( $s, $domain = '' ) { return isset( $GLOBALS['workspace_i18n'][ $s ] ) ? $GLOBALS['workspace_i18n'][ $s ] : $s; }
function esc_html__( $s, $domain = '' ) { return esc_html( $s ); }
function esc_attr__( $s, $domain = '' ) { return esc_html( $s ); }
function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' ); }
function esc_attr( $s ) { return esc_html( $s ); }
function esc_url( $s ) { return esc_html( $s ); }
function wp_unslash( $s ) { return $s; }
function sanitize_key( $s ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( $s ) ); }
function is_admin() { return $GLOBALS['workspace_admin']; }
function wp_doing_ajax() { return $GLOBALS['workspace_ajax']; }
function current_user_can( $cap ) { return ! empty( $GLOBALS['workspace_caps'][ $cap ] ); }
function admin_url( $path = '' ) { return 'https://fixture.invalid/wp-admin/' . $path; }
function add_query_arg( $key, $value, $url = null ) {
	if ( is_array( $key ) ) { $query = $key; $url = $value; } else $query = array( $key => $value );
	return $url . ( false === strpos( $url, '?' ) ? '?' : '&' ) . http_build_query( $query );
}
function plugins_url( $path, $file ) { return 'https://fixture.invalid/wp-content/plugins/mad4b-site-control-plane/' . $path; }
function wp_enqueue_style( $handle, $url, $deps, $version ) { $GLOBALS['workspace_assets'][] = array( 'css', $handle, $url, $version ); }
function wp_enqueue_script( $handle, $url, $deps, $version, $footer ) { $GLOBALS['workspace_assets'][] = array( 'js', $handle, $url, $version ); }
function wp_get_environment_type() { return 'production'; }
function add_action() {}
function add_filter() {}
function update_option() { throw new RuntimeException( 'UI attempted a persistent write.' ); }
function wp_remote_get() { throw new RuntimeException( 'UI attempted outbound discovery.' ); }
final class MAD4B_SCP_Site_Profile { public static function current_environment() { return 'staging'; } }
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-admin-route-registry.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-admin-experience.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-admin-workspace.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-operator-workspace.php';
$assertions = 0;
$check = static function ( $value, $message ) use ( &$assertions ) { ++$assertions; if ( ! $value ) throw new RuntimeException( $message ); };
$pages = array( 'mad4b-control-plane', 'mad4b-operator-control-center', 'mad4b-control-plane-site-profile', 'mad4b-control-plane-connection', 'mad4b-control-plane-chatgpt', 'mad4b-control-plane-oauth-canary', 'mad4b-control-plane-context', 'mad4b-search-intelligence', 'mad4b-control-plane-content-pipeline', 'mad4b-approval-decisions', 'mad4b-adapter-coverage', 'mad4b-runtime-components', 'mad4b-control-plane-skills', 'mad4b-control-plane-performance' );
foreach ( $pages as $slug ) MAD4B_SCP_Admin_Route_Registry::register( $slug, 'manage_options' );
$inventory = MAD4B_SCP_Admin_Workspace::inventory();
$check( count( $inventory ) === 14, 'All fourteen registered workspaces are discoverable.' );
foreach ( $inventory as $row ) $check( false !== strpos( $row['url'], '/wp-admin/admin.php?page=' ) && false === $row['authorizing'], 'Canonical non-authorizing workspace links.' );
$_GET = array( 'page' => 'mad4b-search-intelligence' );
$check( 'mad4b-search-intelligence' === MAD4B_SCP_Admin_Workspace::current_page(), 'Exact registered page admitted.' );
MAD4B_SCP_Admin_Workspace::enqueue();
$check( 2 === count( $GLOBALS['workspace_assets'] ), 'Only two local presentation assets are loaded.' );
$url = MAD4B_SCP_Admin_Workspace::link( 'mad4b-search-intelligence', array( 'section' => 'providers' ), 'search-providers' );
$check( false !== strpos( $url, 'section=providers#search-providers' ), 'API setup is directly linked before configuration.' );
ob_start(); MAD4B_SCP_Admin_Workspace::render(); $html = ob_get_clean();
$check( false !== strpos( $html, 'Search API credentials' ) && false !== strpos( $html, 'aria-current="page"' ), 'Setup discovery and active page semantics render.' );
$check( 14 === substr_count( $html, 'data-mad4b-workspace-item' ), 'No registered workspace is hidden by default.' );
foreach ( array( array( 'page' => array( 'mad4b-search-intelligence' ) ), array( 'page' => 'mad4b-unregistered' ), array( 'page' => 'mad4b-search-intelligence<script>' ) ) as $query ) {
	$_GET = $query; $GLOBALS['workspace_assets'] = array(); MAD4B_SCP_Admin_Workspace::enqueue();
	$check( '' === MAD4B_SCP_Admin_Workspace::current_page() && ! $GLOBALS['workspace_assets'], 'Malformed or unregistered pages have no assets.' );
}
$_GET = array( 'page' => 'mad4b-control-plane' );
$GLOBALS['workspace_admin'] = false; $check( '' === MAD4B_SCP_Admin_Workspace::current_page(), 'Frontend denied.' ); $GLOBALS['workspace_admin'] = true;
$GLOBALS['workspace_ajax'] = true; $check( '' === MAD4B_SCP_Admin_Workspace::current_page(), 'AJAX denied.' ); $GLOBALS['workspace_ajax'] = false;
$GLOBALS['workspace_caps'] = array();
$check( ! MAD4B_SCP_Admin_Workspace::inventory() && '' === MAD4B_SCP_Admin_Workspace::current_page(), 'Revoked actor sees no workspace inventory.' );
ob_start(); MAD4B_SCP_Operator_Workspace::render( array() ); $denied = ob_get_clean(); $check( '' === $denied, 'Direct operator view denies unauthorized actors.' );
$GLOBALS['workspace_caps'] = array( 'manage_options' => true );
$model = MAD4B_SCP_Operator_Workspace::model( array( 'signals' => array( 'write_authority_ready' => 1, 'candidate_binding_match' => null, 'database_topology_ready' => false, 'governed_write_lane_ready' => true ), 'reasons' => array( 'mutation_state_uncertain', 'write_authority_not_current', 'runtime_authority_candidate_not_reconciled', 'provider_closure_actions_pending', 'live_evidence_unbound' ), 'auto_repaired_count' => 500, 'automation_percentage' => 95 ) );
$check( null === $model['checks'][0]['value'] && null === $model['checks'][1]['value'], 'Truthy and missing values never become readiness.' );
$check( false === $model['checks'][2]['value'] && true === $model['checks'][3]['value'], 'Observed false and true retain their distinct meanings.' );
$check( null === $model['automation_percentage'] && null === $model['auto_repaired_count'], 'Caller repair and automation claims are ignored.' );
$check( ! in_array( 'AUTO_REPAIRED', array_column( $model['actions'], 'state' ), true ), 'No repair is claimed without runtime receipts.' );
$check( 'recovery' === $model['actions'][0]['id'], 'Uncertain change reconciliation takes precedence.' );
foreach ( $model['actions'] as $action ) $check( '' !== MAD4B_SCP_Admin_Workspace::link( $action['page'], $action['query'] ), 'Each required action has a registered handoff.' );
ob_start(); MAD4B_SCP_Operator_Workspace::render( array( 'reasons' => array( '<script>alert(1)</script>' ) ) ); $html = ob_get_clean();
$check( false === strpos( $html, '<script>' ) && false !== strpos( $html, 'Not checked' ), 'Untrusted evidence is escaped and unknown checks remain visible.' );
echo json_encode( array( 'contract' => 'mad4b.admin-workspace-runtime.v1', 'status' => 'PASS', 'assertions' => $assertions, 'page_count' => count( $inventory ), 'live_acceptance' => false, 'authorizing' => false ) ) . "\n";

// Optional disposable render output for visual QA, never a live acceptance receipt.
$fixture_dir = getenv( 'MAD4B_UI_FIXTURE_DIR' );
if ( is_string( $fixture_dir ) && '' !== $fixture_dir && is_dir( $fixture_dir ) ) {
	$translations = array(); $key = '';
	foreach ( file( MAD4B_SCP_DIR . 'languages/mad4b-site-control-plane-ar.po', FILE_IGNORE_NEW_LINES ) as $line ) {
		if ( 0 === strpos( $line, 'msgid ' ) ) $key = json_decode( substr( $line, 6 ), true );
		if ( 0 === strpos( $line, 'msgstr ' ) && '' !== $key ) $translations[ $key ] = json_decode( substr( $line, 7 ), true );
	}
	foreach ( array( 'en', 'ar' ) as $locale ) {
		$GLOBALS['workspace_i18n'] = 'ar' === $locale ? $translations : array();
		$_GET = array( 'page' => 'mad4b-operator-control-center' );
		$rendered = new ReflectionProperty( 'MAD4B_SCP_Admin_Workspace', 'rendered' ); $rendered->setAccessible( true ); $rendered->setValue( null, false );
		$styles = new ReflectionProperty( 'MAD4B_SCP_Admin_Experience', 'styles_rendered' ); $styles->setAccessible( true ); $styles->setValue( null, false );
		ob_start(); MAD4B_SCP_Admin_Workspace::render();
		MAD4B_SCP_Operator_Workspace::render( array( 'signals' => array( 'write_authority_ready' => true, 'candidate_binding_match' => true, 'database_topology_ready' => null, 'governed_write_lane_ready' => false ), 'reasons' => array( 'write_authority_not_current', 'provider_closure_actions_pending', 'live_evidence_unbound', 'developer_lane_not_ready' ) ), array( 'providers' => array( 'fixture-provider' => array( 'capabilities' => array( 'fixture-read' => array( 'state' => 'STRUCTURALLY_COMPATIBLE' ) ) ) ) ) );
		$content = ob_get_clean();
		$base = 'body{margin:0;background:#f0f0f1;font:14px/1.6 system-ui,sans-serif;color:#1d2327}#wpbody-content{padding:12px}.wrap{margin:12px 20px;box-sizing:border-box}h1{font-size:24px}h2{font-size:20px}a{color:#135e96}.button{display:inline-flex;align-items:center;padding:8px 12px;border:1px solid #135e96;border-radius:4px;background:#fff;color:#135e96;text-decoration:none;box-sizing:border-box}.widefat{width:100%;border-collapse:collapse;background:#fff}.widefat td,.widefat th{text-align:start;padding:12px;border-bottom:1px solid #c3c4c7}.screen-reader-text{position:absolute;width:1px;height:1px;overflow:hidden;clip:rect(1px,1px,1px,1px)}input{padding:8px;border:1px solid #8c8f94;border-radius:4px}';
		$css = file_get_contents( MAD4B_SCP_DIR . 'assets/admin-workspace.css' );
		$js = file_get_contents( MAD4B_SCP_DIR . 'assets/admin-workspace.js' );
		$html = '<!doctype html><html lang="' . $locale . '" dir="' . ( 'ar' === $locale ? 'rtl' : 'ltr' ) . '"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Disposable workspace UI fixture</title><style>' . $base . $css . '</style></head><body class="mad4b-workspace-page' . ( 'ar' === $locale ? ' rtl' : '' ) . '"><div id="wpbody-content">' . $content . '</div><script>' . $js . '</script></body></html>';
		file_put_contents( $fixture_dir . '/workspace-' . $locale . '.html', $html );
	}
}
