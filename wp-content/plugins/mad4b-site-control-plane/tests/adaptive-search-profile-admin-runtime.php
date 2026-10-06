<?php
require __DIR__ . '/fixtures/search-runtime-fixtures.php';
set_error_handler( static function ( $severity, $message, $file, $line ) { if ( error_reporting() & $severity ) throw new ErrorException( $message, 0, $severity, $file, $line ); } );
function wp_unslash( $v ) { return is_array( $v ) ? array_map( 'wp_unslash', $v ) : ( is_string( $v ) ? stripslashes( $v ) : $v ); }
function esc_html( $v ) { return htmlspecialchars( (string) $v, ENT_QUOTES, 'UTF-8' ); }
function esc_attr( $v ) { return esc_html( $v ); }
function esc_url( $v ) { return esc_html( $v ); }
function esc_textarea( $v ) { return esc_html( $v ); }
function wp_nonce_field( $action ) { echo '<input name="_wpnonce" value="' . esc_attr( hash( 'sha256', $action ) ) . '">'; }
function wp_get_session_token() { return 'hermetic-administrator-session'; }
function wp_salt( $scheme ) { return 'hermetic-notice-signing-salt'; }
function check_admin_referer( $action ) { if ( ! isset( $_POST['_wpnonce'] ) || hash( 'sha256', $action ) !== $_POST['_wpnonce'] ) throw new RuntimeException( 'nonce_denied' ); }
function wp_die( $message, $title = '', $args = array() ) { throw new RuntimeException( 'post_denied' ); }
function submit_button( $text, $type, $name, $wrap ) { echo '<button>' . esc_html( $text ) . '</button>'; }
$assertions = 0; $cases = array();
function profile_check( $ok, $message ) { ++$GLOBALS['assertions']; if ( ! $ok ) throw new RuntimeException( $message ); }
function profile_case( $id, $callback ) { asi_reset(); $_GET = array(); $_POST = array(); $start = $GLOBALS['assertions']; try { $callback(); $GLOBALS['cases'][] = array( 'fixture' => $id, 'status' => 'PASS', 'assertions' => $GLOBALS['assertions'] - $start ); } catch ( Throwable $e ) { $GLOBALS['cases'][] = array( 'fixture' => $id, 'status' => 'FAIL', 'assertions' => $GLOBALS['assertions'] - $start, 'error' => $e->getMessage(), 'at' => basename( $e->getFile() ) . ':' . $e->getLine() ); } }
function profile_form( $id = 'operator.profile' ) { return array( 'operation' => 'create', 'profile_id' => $id, 'expected_revision' => '0', 'brand_id' => '', 'market_id' => 'first-market', 'market_country' => 'GB', 'languages' => 'en, ar', 'engines' => 'google', 'providers' => array( 'alpha' ) ); }
profile_case( 'profile_form_creates_paused_draft_exact_readback_without_authority', static function () {
	$GLOBALS['fixture_http'] = array(); $args = profile_form(); $args['enabled'] = true; $args['authority'] = array( 'production' => true );
	$result = MAD4B_SCP_Search_Profile_Admin::save( $args ); profile_check( ! is_wp_error( $result ), 'first draft created' );
	$p = $result['profile']; profile_check( ! $p['enabled'] && $p['provider_policy']['freeze_spend'], 'new draft paused and spend frozen despite extra inputs' );
	profile_check( ! $p['authorizing'] && array() === $p['budget_policy']['nodes'], 'no authority or budget allocation created' );
	$v = MAD4B_SCP_Search_Runtime::profile_verify( array( 'profile_id' => $p['profile_id'] ) ); profile_check( $v['valid'] && 1 === $v['revision'], 'exact persisted fingerprint verifies' );
	profile_check( isset( MAD4B_SCP_Search_Profile_Admin::profiles()[ $p['profile_id'] ] ), 'draft discoverable in registry' );
	profile_check( $p['profile_id'] === MAD4B_SCP_Search_Profile_Admin::selected_id(), 'sidebar with omitted profile chooses saved profile' );
	$_GET = array( 'profile_id' => 'missing.profile' ); profile_check( 'missing.profile' === MAD4B_SCP_Search_Profile_Admin::selected_id(), 'explicit missing profile not silently substituted' );
	$_GET = array(); ob_start(); MAD4B_SCP_Search_Profile_Admin::render( $p['profile_id'] ); $html = ob_get_clean();
	foreach ( array( 'Selected profile', 'Edit selected profile policy', 'Create a search profile', 'expected_revision', 'Profile policy JSON' ) as $text ) profile_check( false !== strpos( $html, $text ), 'operator path discoverable: ' . $text );
	profile_check( 0 === count( $GLOBALS['fixture_http'] ), 'draft save and rendering never call provider' );
} );
profile_case( 'profile_editor_preserves_policy_and_fences_stale_or_renamed_forms', static function () {
	$saved = MAD4B_SCP_Search_Profile_Admin::save( profile_form() ); $p = $saved['profile']; $raw = array_intersect_key( $p, array_flip( MAD4B_SCP_Search_Context::policy()['profile_fields'] ) );
	$raw['markets'][] = array( 'id' => 'second-market', 'country' => 'FR', 'provider_locations' => array( 'alpha' => array( 'id' => 'fixture-location', 'precision' => 'country' ) ) );
	$raw['refresh_policy']['baseline_seconds'] = 86400;
	$edit = array( 'operation' => 'edit', 'profile_id' => $p['profile_id'], 'expected_revision' => '1', 'profile_json' => json_encode( $raw ) );
	$result = MAD4B_SCP_Search_Profile_Admin::save( $edit ); profile_check( ! is_wp_error( $result ) && 2 === $result['profile']['revision'], 'current edit commits next revision' );
	profile_check( $raw['markets'] === $result['profile']['markets'] && 86400 === $result['profile']['refresh_policy']['baseline_seconds'], 'multi-market location mappings and advanced policy preserved' );
	profile_check( is_wp_error( MAD4B_SCP_Search_Profile_Admin::save( $edit ) ), 'stale revision denied' );
	$edit['expected_revision'] = '2'; $raw['profile_id'] = 'renamed.profile'; $edit['profile_json'] = json_encode( $raw ); profile_check( is_wp_error( MAD4B_SCP_Search_Profile_Admin::save( $edit ) ), 'profile cannot be renamed through another form' );
	$raw['profile_id'] = $p['profile_id']; $raw['provider_policy']['endpoint'] = 'https://example.invalid'; $edit['profile_json'] = json_encode( $raw ); profile_check( is_wp_error( MAD4B_SCP_Search_Profile_Admin::save( $edit ) ), 'security fields remain denied by domain validator' );
} );
profile_case( 'profile_form_denies_malformed_inputs_role_production_and_bad_nonce', static function () {
	foreach ( array( 'profile_id', 'expected_revision', 'languages', 'market_country', 'providers', 'operation' ) as $field ) { $bad = profile_form(); $bad[ $field ] = array( array( 'invalid' ) ); profile_check( is_wp_error( MAD4B_SCP_Search_Profile_Admin::save( $bad ) ), 'nested form value denied: ' . $field ); }
	$bad = profile_form(); $bad['expected_revision'] = '1e0'; profile_check( is_wp_error( MAD4B_SCP_Search_Profile_Admin::save( $bad ) ), 'revision cannot be loosely cast' );
	$bad = profile_form(); $bad['providers'] = array( 'unknown' ); profile_check( is_wp_error( MAD4B_SCP_Search_Profile_Admin::save( $bad ) ), 'unknown adapter denied' );
	$GLOBALS['fixture_admin'] = false; profile_check( is_wp_error( MAD4B_SCP_Search_Profile_Admin::save( profile_form() ) ), 'nonadministrator denied' );
	$GLOBALS['fixture_admin'] = true; $GLOBALS['fixture_environment'] = 'production'; profile_check( is_wp_error( MAD4B_SCP_Search_Profile_Admin::save( profile_form() ) ), 'Production denied' );
	$GLOBALS['fixture_environment'] = 'staging'; $_POST = profile_form(); try { MAD4B_SCP_Search_Profile_Admin::post(); throw new RuntimeException( 'nonce bypassed' ); } catch ( RuntimeException $e ) { profile_check( 'nonce_denied' === $e->getMessage(), 'POST requires exact action nonce' ); }
	profile_check( array() === MAD4B_SCP_Search_Profile_Admin::profiles(), 'denials leave registry untouched' );
} );
profile_case( 'profile_navigation_and_action_feedback_are_typed_and_view_bound', static function () {
	$saved = MAD4B_SCP_Search_Profile_Admin::save( profile_form() ); $id = $saved['profile']['profile_id']; $sha = $saved['profile']['profile_sha256'];
	$_GET = array( 'section' => array( 'invalid' ) ); ob_start(); MAD4B_SCP_Search_Experience::render(); $html = ob_get_clean(); profile_check( false !== strpos( $html, 'PROFILE DRAFTED' ), 'saved profile renders after malformed section defaults' );
	$_GET = array( 'profile_id' => array( 'invalid' ) ); profile_check( '' === MAD4B_SCP_Search_Profile_Admin::selected_id(), 'array profile safely rejected' );
	$_GET = array( 'mad4b_notice_receipt' => MAD4B_SCP_Admin_Experience::notice_receipt( MAD4B_SCP_Search_Experience::PAGE_SLUG, 'profile_saved', $sha ) );
	profile_check( MAD4B_SCP_Admin_Experience::notice_verified( MAD4B_SCP_Search_Experience::PAGE_SLUG, 'profile_saved', $sha ), 'current view receipt valid' );
	profile_check( ! MAD4B_SCP_Admin_Experience::notice_verified( MAD4B_SCP_Search_Experience::PAGE_SLUG, 'profile_saved', 'other-state' ) && ! MAD4B_SCP_Admin_Experience::notice_verified( 'other-page', 'profile_saved', $sha ), 'state/page replay denied' );
	$_GET['mad4b_notice_receipt'][12] = 'x'; profile_check( ! MAD4B_SCP_Admin_Experience::notice_verified( MAD4B_SCP_Search_Experience::PAGE_SLUG, 'profile_saved', $sha ), 'tampered feedback denied' );
} );
$failed = array_filter( $cases, static function ( $row ) { return 'PASS' !== $row['status']; } );
echo json_encode( array( 'contract' => 'mad4b.adaptive-search-profile-admin-evidence.v1', 'status' => $failed ? 'FAIL' : 'PASS', 'fixture_count' => count( $cases ), 'assertions' => $assertions, 'fixtures' => $cases, 'authorizing' => false ), JSON_UNESCAPED_SLASHES ) . "\n"; exit( $failed ? 1 : 0 );
