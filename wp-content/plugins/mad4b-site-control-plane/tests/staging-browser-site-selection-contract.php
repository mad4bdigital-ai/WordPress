<?php
define( 'ABSPATH', __DIR__ . '/' );
class WP_Error {
	private $code;
	public function __construct( $code ) { $this->code = $code; }
	public function get_error_code() { return $this->code; }
}
function is_wp_error( $value ) { return $value instanceof WP_Error; }
$GLOBALS['browser_test_option'] = array();
function get_option( $key, $default = false ) {
	return isset( $GLOBALS['browser_test_option'][ $key ] ) ? $GLOBALS['browser_test_option'][ $key ] : $default;
}
$GLOBALS['browser_test_providers'] = array();
$GLOBALS['browser_test_verdict'] = 'PASS';
$GLOBALS['browser_test_digest'] = str_repeat( 'a', 64 );
$GLOBALS['browser_test_actual_plan'] = array();
class MAD4B_SCP_Browser_Acceptance_Core {
	public static function capabilities() {
		return array( 'providers' => array_map( static function( $id ) {
			return array( 'provider_id' => $id );
		}, $GLOBALS['browser_test_providers'] ) );
	}
	public static function plan( $input ) {
		$GLOBALS['browser_test_actual_plan'] = $input;
		return array( 'state' => 'ready', 'plan_digest' => str_repeat( 'a', 64 ), 'plan_signature' => str_repeat( 'b', 64 ) );
	}
	public static function result( $input ) {
		return array( 'verdict' => $GLOBALS['browser_test_verdict'],
			'verification' => array( 'browser_runtime_parity_verified' => true ),
			'plan_digest' => $GLOBALS['browser_test_digest'] );
	}
}
require_once dirname( __DIR__ ) . '/includes/class-mad4b-scp-browser-acceptance-admin-ui.php';
require_once dirname( __DIR__ ) . '/includes/class-mad4b-scp-staging-certification.php';
function expect_browser( $ok, $why ) {
	if ( ! $ok ) { fwrite( STDERR, "FAIL: {$why}\n" ); exit(1); }
}
$ref = new ReflectionMethod( 'MAD4B_SCP_Staging_Certification', 'browser_status' );
$ref->setAccessible( true );
$GLOBALS['browser_test_providers'] = array();
$empty = $ref->invoke( null );
expect_browser( ! $empty['ready'] && in_array( 'site_browser_provider_missing', $empty['blockers'], true ), 'zero providers fail closed' );
$GLOBALS['browser_test_providers'] = array( 'site-a', 'site-b' );
$ambiguous = $ref->invoke( null );
expect_browser( ! $ambiguous['ready'] && in_array( 'site_browser_provider_selection_required', $ambiguous['blockers'], true ), 'multiple providers require selection' );
$GLOBALS['browser_test_providers'] = array( 'site-a' );
$profile_missing = $ref->invoke( null );
expect_browser( ! $profile_missing['ready'] && in_array( 'site_browser_acceptance_profile_required', $profile_missing['blockers'], true ), 'generic provider needs profile' );
$GLOBALS['browser_test_option'][MAD4B_SCP_Browser_Acceptance_Admin_UI::OPTION] = array(
	'executor' => 'auto', 'site_provider_id' => 'site-a', 'profile_id' => 'all-royal' );
$valid = $ref->invoke( null );
expect_browser( $valid['ready'] && $valid['selected_provider_id'] === 'site-a' && $valid['selected_profile_id'] === 'all-royal', 'selected provider and profile in live plan' );
expect_browser( $GLOBALS['browser_test_actual_plan']['provider_id'] === 'site-a'
	&& $GLOBALS['browser_test_actual_plan']['profile_id'] === 'all-royal', 'not hardcoded ETG' );
$GLOBALS['browser_test_verdict'] = 'FAIL';
$rejected_verdict = $ref->invoke( null );
expect_browser( ! $rejected_verdict['ready'], 'negative verdict cannot pass acceptance' );
$GLOBALS['browser_test_verdict'] = 'PASS';
$GLOBALS['browser_test_digest'] = str_repeat( 'd', 64 );
$rejected_digest = $ref->invoke( null );
expect_browser( ! $rejected_digest['ready'], 'mismatched signed plan digest must fail' );
$GLOBALS['browser_test_digest'] = str_repeat( 'a', 64 );
$GLOBALS['browser_test_option'][MAD4B_SCP_Browser_Acceptance_Admin_UI::OPTION]['site_provider_id'] = 'missing';
$unregistered = $ref->invoke( null );
expect_browser( ! $unregistered['ready'] && in_array( 'selected_site_browser_provider_unregistered', $unregistered['blockers'], true ), 'unregistered selection fail closed' );
$GLOBALS['browser_test_option'][MAD4B_SCP_Browser_Acceptance_Admin_UI::OPTION] = array( 'executor' => 'auto', 'profile_id' => '', 'site_provider_id' => '' );
$GLOBALS['browser_test_providers'] = array( 'etg-dfsb' );
$legacy = $ref->invoke( null );
expect_browser( $legacy['ready'] && $legacy['selected_profile_id'] === 'tours', 'ETG legacy fixture preserved for actual ETG registration' );
echo "MAD4B_STAGING_BROWSER_SITE_SELECTION: PASS\n";
