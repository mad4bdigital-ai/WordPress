<?php
/** Full admin surface smoke: real disposable WordPress, never live certification. */
if ( ! defined( 'ABSPATH' ) || ! current_user_can( 'manage_options' ) ) throw new RuntimeException( 'Disposable administrator WordPress required.' );
require_once ABSPATH . 'wp-admin/includes/admin.php';
set_current_screen( 'dashboard' );
$GLOBALS['menu'] = array(); $GLOBALS['submenu'] = array(); $GLOBALS['admin_page_hooks'] = array(); $GLOBALS['_registered_pages'] = array();
do_action( 'admin_menu' );
$assertions = 0; $renders = 0; $requests = 0; $render_failures = array();
$check = static function ( $ok, $message ) use ( &$assertions ) { ++$assertions; if ( ! $ok ) throw new RuntimeException( $message ); };
$stop_http = static function () use ( &$requests ) { ++$requests; return new WP_Error( 'admin_smoke_outbound_denied', 'Ordinary page rendering must use local or cached evidence.' ); };
add_filter( 'pre_http_request', $stop_http, PHP_INT_MAX );
set_error_handler( static function ( $severity, $message, $file, $line ) { if ( ( error_reporting() & $severity ) && false !== strpos( $file, 'mad4b-site-control-plane/' ) ) throw new ErrorException( $message, 0, $severity, $file, $line ); } );
$pages = array(
	'mad4b-control-plane' => array( 'MAD4B_SCP_Admin_UI', 'render_page', array( 'overview', 'agents', 'approvals', 'mutations', 'audit' ) ),
	'mad4b-control-plane-context' => array( 'MAD4B_SCP_Context_Admin_UI', 'render_page', array( 'overview', 'google-drive', 'sources', 'assets', 'quality', 'intelligence' ) ),
	'mad4b-control-plane-connection' => array( 'MAD4B_SCP_Connection_Admin_UI', 'render_page', array( 'readiness', 'oauth', 'endpoints', 'isolation', 'certification' ) ),
	'mad4b-control-plane-chatgpt' => array( 'MAD4B_SCP_ChatGPT_Connection_Admin_UI', 'render_page', array( '' ) ),
	'mad4b-control-plane-content-pipeline' => array( 'MAD4B_SCP_Dynamic_Content_Pipeline_Admin', 'render', array( '' ) ),
	'mad4b-control-plane-site-profile' => array( 'MAD4B_SCP_Site_Profile_Admin', 'render_page', array( '' ) ),
	'mad4b-control-plane-performance' => array( 'MAD4B_SCP_Admin_Query_Performance_UI', 'render_page', array( '' ) ),
	'mad4b-control-plane-skills' => array( 'MAD4B_SCP_Skills_Admin_UI', 'render_page', array( '' ) ),
	'mad4b-browser-acceptance' => array( 'MAD4B_SCP_Browser_Acceptance_Admin_UI', 'render_page', array( '' ) ),
	'mad4b-control-plane-oauth-canary' => array( 'MAD4B_SCP_Local_OAuth_Browser_Canary', 'render_page', array( '' ) ),
	'mad4b-runtime-components' => array( 'MAD4B_SCP_Runtime_Components_Admin_UI', 'render_page', array( 'overview', 'core', 'plugins', 'mu-plugins', 'drop-ins', 'themes', 'astra', 'maintenance' ) ),
	'mad4b-adapter-coverage' => array( 'MAD4B_SCP_Adapter_Coverage_Admin_UI', 'render_page', array( 'overview', 'installed', 'priority', 'functional', 'requests' ) ),
	'mad4b-operator-control-center' => array( 'MAD4B_SCP_Operator_Control_Center', 'render_page', array( '' ) ),
	'mad4b-approval-decisions' => array( 'MAD4B_SCP_Approval_Decision_Admin', 'render_page', array( 'actionable', 'history' ) ),
	'mad4b-search-intelligence' => array( 'MAD4B_SCP_Search_Experience', 'render', MAD4B_SCP_Search_Context::policy()['section_order'] ),
	'mad4b-growth-providers' => array( 'MAD4B_SCP_G5_External_Providers', 'render', array( '' ) ),
	'mad4b-ai-knowledge-workspace' => array( 'MAD4B_SCP_G6_Acceptance', 'render', array( '' ) ),
);
$routes = MAD4B_SCP_Admin_Route_Registry::routes();
$check( ! array_diff( array_keys( $routes ), array_keys( $pages ) ) && ! array_diff( array_keys( $pages ), array_keys( $routes ) ), 'Every declared page must have a smoke case.' );
$workspaces = MAD4B_SCP_Admin_Workspace::inventory();
$check( ! array_diff( array_keys( $routes ), array_keys( $workspaces ) ) && ! array_diff( array_keys( $workspaces ), array_keys( $routes ) ), 'Every registered page is discoverable in the workspace directory.' );
foreach ( $workspaces as $slug => $workspace ) {
	$_GET = array( 'page' => $slug );
	$check( $slug === MAD4B_SCP_Admin_Workspace::current_page(), 'Workspace exact route and capability: ' . $slug );
	$check( false !== strpos( $workspace['url'], 'admin.php?page=' ) && false === $workspace['authorizing'], 'Workspace canonical, non-authorizing URL: ' . $slug );
}
$_GET = array( 'page' => 'mad4b-unregistered' );
$check( '' === MAD4B_SCP_Admin_Workspace::current_page(), 'An unregistered prefix match receives no workspace.' );
$_GET = array( 'page' => array( 'mad4b-control-plane' ) );
$check( '' === MAD4B_SCP_Admin_Workspace::current_page(), 'Nested route input is rejected.' );
$before_profile = get_option( MAD4B_SCP_Site_Profile::OPTION );
$before_pipeline = get_option( MAD4B_SCP_Dynamic_Content_Pipeline::OPTION );
$malformed = array();
foreach ( array( 'agent', 'folder', 'mode_filter', 'category_filter', 'review_filter', 'status_filter', 'asset_search', 'intel_action', '_wpnonce', 'category', 'query', 'task_scope', 'asset_id', 'skill', 'level', 'target', 'paged', 'profile_id', 'section', 'mad4b_notice', 'mad4b_error', 'mad4b_pipeline_saved', 'mad4b_pipeline_error', 'mad4b_site_profile', 'mad4b_skill_notice', 'mad4b_skill_error', 'saved', 'mad4b_decision_result', 'mad4b_decision_status', 'mad4b_notice_receipt', 'mad4b_performance_apply', 'mad4b_runtime_release_set', 'mad4b_runtime_release_detail', 'mad4b_provider_notice', 'mad4b_provider_operation' ) as $key ) $malformed[ $key ] = array( 'nested' => array( 'invalid' ) );
$render = static function ( $slug, array $page, array $query ) use ( &$renders, $check ) {
	$_GET = array_merge( array( 'page' => $slug ), $query ); $_POST = array(); $_REQUEST = $_GET;
	set_current_screen( 'mad4b-control-plane_page_' . $slug );
	ob_start();
	try { call_user_func( array( $page[0], $page[1] ) ); MAD4B_SCP_MCP_Registration_Diagnostics_Admin::render(); MAD4B_SCP_Runtime_Release_Set::admin_notice(); $html = ob_get_clean(); }
	catch ( Throwable $error ) { ob_end_clean(); throw new RuntimeException( $slug . ': ' . $error->getMessage() . ' (' . basename( $error->getFile() ) . ':' . $error->getLine() . ')', 0, $error ); }
	++$renders; $check( false !== strpos( $html, '<h1>' ), $slug . ': page renders a title' );
	$check( ! preg_match( '#/wp-admin/mad4b-[a-z-]+["?]#', $html ), $slug . ': canonical admin URLs' );
	$doc = new DOMDocument(); @$doc->loadHTML( '<!doctype html><html><body>' . $html . '</body></html>' ); $xpath = new DOMXPath( $doc );
	foreach ( $xpath->query( '//form[translate(@method,"POST","post")="post"]' ) as $form ) {
		$check( $xpath->query( './/input[contains(@name,"nonce")]', $form )->length > 0, $slug . ': every write form has a nonce' );
		if ( false !== strpos( $form->getAttribute( 'action' ), 'admin-post.php' ) ) foreach ( $xpath->query( './/input[@name="action"]', $form ) as $action ) $check( false !== has_action( 'admin_post_' . $action->getAttribute( 'value' ) ), $slug . ': submitted admin action is registered: ' . $action->getAttribute( 'value' ) );
	}
	return $html;
};
$probe_render = static function ( $slug, array $page, array $query ) use ( $render, &$render_failures ) {
	try { return $render( $slug, $page, $query ); }
	catch ( Throwable $error ) { $render_failures[] = $error->getMessage(); fwrite( STDERR, 'Admin page smoke: ' . $error->getMessage() . "\n" ); return ''; }
};
foreach ( $pages as $slug => $page ) {
	foreach ( $page[2] as $tab ) {
		$key = 'mad4b-search-intelligence' === $slug ? 'section' : ( 'mad4b-approval-decisions' === $slug ? 'view' : 'tab' );
		$probe_render( $slug, $page, array( $key => $tab ) );
		$probe_render( $slug, $page, array_merge( $malformed, array( $key => $tab ) ) );
	}
	$probe_render( $slug, $page, array( 'tab' => array( 'invalid' ), 'view' => array( 'invalid' ), 'section' => array( 'invalid' ) ) );
}
$_GET = array( 'mad4b_notice_receipt' => MAD4B_SCP_Admin_Experience::notice_receipt( 'test-page', 'saved', 'state-a' ) );
$check( MAD4B_SCP_Admin_Experience::notice_verified( 'test-page', 'saved', 'state-a' ), 'Issued notice verifies exact actor/view.' );
$check( ! MAD4B_SCP_Admin_Experience::notice_verified( 'test-page', 'saved', 'state-b' ) && ! MAD4B_SCP_Admin_Experience::notice_verified( 'other-page', 'saved', 'state-a' ), 'Stale view and cross-page notices denied.' );
$_GET['mad4b_notice_receipt'] .= '0';
$check( ! MAD4B_SCP_Admin_Experience::notice_verified( 'test-page', 'saved', 'state-a' ), 'Tampered notice denied.' );
$html = $render( 'mad4b-browser-acceptance', $pages['mad4b-browser-acceptance'], array( 'saved' => '1' ) );
$check( false === strpos( $html, 'Preference saved; execution and browser acceptance remain separately unverified.' ), 'Forged Browser Acceptance URL success denied.' );
$check( false !== strpos( $html, 'expected_configuration_revision' ), 'Browser preference form binds current revision.' );
$html = $render( 'mad4b-control-plane-content-pipeline', $pages['mad4b-control-plane-content-pipeline'], array( 'mad4b_pipeline_saved' => '1' ) );
$check( false === strpos( $html, 'Pipeline settings saved and verified.' ), 'A GET flag cannot fabricate pipeline success.' );
$html = $render( 'mad4b-control-plane-context', $pages['mad4b-control-plane-context'], array( 'mad4b_notice' => 'google_connected' ) );
$check( false === strpos( $html, 'Google Drive connected. Access mode:' ), 'A GET flag cannot fabricate Google connection.' );
$html = $render( 'mad4b-runtime-components', $pages['mad4b-runtime-components'], array( 'tab' => 'maintenance', 'mad4b_runtime_release_set' => 'committed' ) );
$check( false === strpos( $html, 'MAD4B runtime release set: committed' ), 'A GET flag cannot fabricate an installed runtime release.' );
$html = $render( 'mad4b-control-plane-performance', $pages['mad4b-control-plane-performance'], array( 'mad4b_performance_apply' => 'queued' ) );
$check( false === strpos( $html, 'Maintenance request: queued' ), 'A GET flag cannot fabricate worker admission.' );
$check( $before_profile === get_option( MAD4B_SCP_Site_Profile::OPTION ) && $before_pipeline === get_option( MAD4B_SCP_Dynamic_Content_Pipeline::OPTION ), 'GET rendering preserves Site Profile and pipeline settings.' );
$old_home = get_option( 'home' ); $old_siteurl = get_option( 'siteurl' );
update_option( 'home', 'https://asi-disposable.test' ); update_option( 'siteurl', 'https://asi-disposable.test' );
$enrolled = MAD4B_SCP_Site_Profile::save_current_site( array( 'environment' => 'staging', 'display_name' => 'Disposable admin acceptance', 'oauth_enabled' => false, 'skills_enabled' => false, 'write_enabled' => false ) );
$check( ! is_wp_error( $enrolled ) && MAD4B_SCP_Search_Runtime::can_configure(), 'Explicit disposable enrollment enables local configuration without remote writes.' );
$saved = MAD4B_SCP_Search_Profile_Admin::save( array( 'operation' => 'create', 'profile_id' => 'disposable.admin.profile', 'expected_revision' => '0', 'brand_id' => '', 'market_id' => 'sample-market', 'market_country' => 'GB', 'languages' => 'en,ar', 'engines' => 'google', 'providers' => array() ) );
$check( ! is_wp_error( $saved ), 'Real WordPress saves and verifies the first paused profile.' );
$check( ! $saved['profile']['enabled'] && $saved['profile']['provider_policy']['freeze_spend'], 'Real WordPress draft remains paused with spend frozen.' );
$enrolled_profile = get_option( MAD4B_SCP_Site_Profile::OPTION );
$authority = get_option( MAD4B_SCP_Staging_Write_Authority::OPTION );
foreach ( $pages['mad4b-search-intelligence'][2] as $section ) {
	$probe_render( 'mad4b-search-intelligence', $pages['mad4b-search-intelligence'], array( 'section' => $section ) );
	$probe_render( 'mad4b-search-intelligence', $pages['mad4b-search-intelligence'], array( 'section' => $section, 'profile_id' => 'disposable.admin.profile', 'mad4b_notice_receipt' => array( 'invalid' ) ) );
}
$check( $enrolled_profile === get_option( MAD4B_SCP_Site_Profile::OPTION ) && $authority === get_option( MAD4B_SCP_Staging_Write_Authority::OPTION ), 'Configured Search GET views preserve Site Profile and write authority.' );
$html = $render( 'mad4b-search-intelligence', $pages['mad4b-search-intelligence'], array( 'section' => 'providers', 'mad4b_provider_notice' => 'serpapi', 'mad4b_provider_operation' => 'save' ) );
$check( false === strpos( $html, 'Provider settings saved and verified.' ), 'A provider GET flag cannot fabricate save success.' );
$check( false !== strpos( $html, 'Add or manage search API credentials' ) && false !== strpos( $html, 'search-providers' ), 'Provider setup remains prominent after a profile is created.' );
$check( 0 === $requests, 'All ordinary GET views make zero outbound requests.' );
$admin_id = get_current_user_id();
$deny_die = static function () { return static function () { throw new RuntimeException( 'expected_permission_denial' ); }; };
add_filter( 'wp_die_handler', $deny_die, PHP_INT_MAX );
wp_set_current_user( 0 );
$check( ! MAD4B_SCP_Admin_Workspace::inventory(), 'Revoked actor receives no workspace inventory.' );
foreach ( $pages as $slug => $page ) {
	$_GET = array( 'page' => $slug ); $_POST = array(); $_REQUEST = $_GET;
	ob_start(); $denied = false;
	try { call_user_func( array( $page[0], $page[1] ) ); }
	catch ( RuntimeException $e ) { if ( 'expected_permission_denial' !== $e->getMessage() ) throw $e; $denied = true; }
	$html = ob_get_clean();
	$check( $denied || '' === trim( $html ), $slug . ': unauthorized direct renderer exposes no settings' );
}
wp_set_current_user( $admin_id ); remove_filter( 'wp_die_handler', $deny_die, PHP_INT_MAX );
update_option( 'home', $old_home ); update_option( 'siteurl', $old_siteurl );
if ( false === $before_profile ) delete_option( MAD4B_SCP_Site_Profile::OPTION ); else update_option( MAD4B_SCP_Site_Profile::OPTION, $before_profile );
$check( ! $render_failures, 'All views must pass: ' . implode( ' | ', array_unique( $render_failures ) ) );
remove_filter( 'pre_http_request', $stop_http, PHP_INT_MAX ); restore_error_handler();
echo wp_json_encode( array( 'contract' => 'mad4b.admin-pages-disposable-evidence.v1', 'status' => 'PASS', 'page_count' => count( $pages ), 'renders' => $renders, 'assertions' => $assertions, 'outbound_requests' => $requests, 'evidence_class' => 'disposable_wordpress', 'live_browser_acceptance' => false, 'authorizing' => false ), JSON_UNESCAPED_SLASHES ) . "\n";
