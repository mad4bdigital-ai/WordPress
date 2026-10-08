<?php
require __DIR__ . '/fixtures/search-runtime-fixtures.php';
set_error_handler( static function ( $severity, $message, $file, $line ) { if ( error_reporting() & $severity ) throw new ErrorException( $message, 0, $severity, $file, $line ); } );
function wp_unslash( $v ) { return is_array( $v ) ? array_map( 'wp_unslash', $v ) : ( is_string( $v ) ? stripslashes( $v ) : $v ); }
function esc_html( $v ) { return htmlspecialchars( (string) $v, ENT_QUOTES, 'UTF-8' ); }
function esc_attr( $v ) { return esc_html( $v ); }
function esc_url( $v ) { return esc_html( $v ); }
function esc_textarea( $v ) { return esc_html( $v ); }
function __( $v, $domain = '' ) { return (string) $v; }
function esc_html__( $v, $domain = '' ) { return esc_html( __( $v, $domain ) ); }
function esc_attr__( $v, $domain = '' ) { return esc_attr( __( $v, $domain ) ); }
function add_query_arg( $key, $value = null, $url = null ) {
	if ( is_array( $key ) ) { $query = $key; $url = $value; } else $query = array( $key => $value );
	$url = null === $url ? home_url( '/' ) : $url;
	$parts = explode( '?', $url, 2 ); $existing = array();
	if ( isset( $parts[1] ) ) parse_str( $parts[1], $existing );
	return $parts[0] . '?' . http_build_query( array_merge( $existing, $query ) );
}
function wp_nonce_field( $action ) { echo '<input name="_wpnonce" value="' . esc_attr( hash( 'sha256', $action ) ) . '">'; }
function wp_get_session_token() { return 'hermetic-administrator-session'; }
function wp_salt( $scheme ) { return 'hermetic-notice-signing-salt'; }
function check_admin_referer( $action ) { if ( ! isset( $_POST['_wpnonce'] ) || hash( 'sha256', $action ) !== $_POST['_wpnonce'] ) throw new RuntimeException( 'nonce_denied' ); }
function wp_die( $message, $title = '', $args = array() ) { throw new RuntimeException( 'post_denied' ); }
function submit_button( $text, $type, $name, $wrap ) { echo '<button>' . esc_html( $text ) . '</button>'; }
require dirname( __DIR__ ) . '/includes/class-mad4b-scp-admin-workspace.php';
MAD4B_SCP_Admin_Route_Registry::register( 'mad4b-search-intelligence', 'manage_options' );
MAD4B_SCP_Admin_Route_Registry::register( 'mad4b-control-plane-site-profile', 'manage_options' );
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
profile_case( 'guided_profile_accepts_checkbox_lists_and_renders_site_aware_defaults', static function () {
	$args = profile_form( 'guided.profile' ); $args['languages'] = array( 'en', 'ar' ); $args['engines'] = array( 'google' ); $args['market_country'] = 'eg';
	$result = MAD4B_SCP_Search_Profile_Admin::save( $args );
	profile_check( ! is_wp_error( $result ) && array( 'en', 'ar' ) === $result['profile']['language_policy']['desired'], 'flat checkbox language list accepted and normalized' );
	profile_check( 'EG' === $result['profile']['markets'][0]['country'], 'lowercase country input normalized to ISO uppercase' );
	ob_start(); MAD4B_SCP_Search_Profile_Admin::render( '' ); $html = ob_get_clean();
	foreach ( array( 'Guided setup', '1. Target market', '2. Audience languages', '3. Search engines', '4. Provider accounts', 'Technical identifiers (auto-filled)', 'name="languages[]"', 'name="engines[]"' ) as $text ) profile_check( false !== strpos( $html, $text ), 'guided setup element visible: ' . $text );
});
profile_case( 'audience_country_and_languages_are_explicit', static function () {
	ob_start(); MAD4B_SCP_Search_Profile_Admin::render( '' ); $html = ob_get_clean();
	profile_check( false !== strpos( $html, 'WordPress timezone are intentionally ignored' ), 'hosting timezone not used to infer target market' );
	profile_check( false !== strpos( $html, 'name="market_country" value=""' ), 'country starts unselected' );
	profile_check( false !== strpos( $html, 'name="additional_languages"' ) && false !== strpos( $html, 'name="languages[]" value="en"' ), 'extra languages and English are selectable' );
	profile_check( ! preg_match( '/name="languages\\[\\]"[^>]* checked/', $html ), 'site languages are not automatically targeted' );
	$args = profile_form( '' ); $args['market_id'] = ''; $args['market_country'] = 'us'; $args['languages'] = array(); $args['additional_languages'] = 'EN_us, ES';
	$first = MAD4B_SCP_Search_Profile_Admin::save( $args );
	profile_check( ! is_wp_error( $first ) && 'search-us' === $first['profile']['profile_id'] && 'market-us' === $first['profile']['markets'][0]['id'], 'IDs derive from US target market' );
	profile_check( array( 'en-us', 'es' ) === $first['profile']['language_policy']['desired'] && ! $first['profile']['enabled'] && $first['profile']['provider_policy']['freeze_spend'], 'selected audience languages remain paused and frozen' );
	$second = MAD4B_SCP_Search_Profile_Admin::save( $args );
	profile_check( ! is_wp_error( $second ) && 'search-us-2' === $second['profile']['profile_id'], 'second market profile gets collision-safe ID' );
	$args['profile_id'] = 'custom-us-audience'; $args['market_id'] = 'custom-market';
	$custom = MAD4B_SCP_Search_Profile_Admin::save( $args );
	profile_check( ! is_wp_error( $custom ) && 'custom-market' === $custom['profile']['markets'][0]['id'], 'explicit IDs supported' );
	$bad = $args; $bad['additional_languages'] = 'en, <script>';
	profile_check( is_wp_error( MAD4B_SCP_Search_Profile_Admin::save( $bad ) ), 'invalid audience language denied' );
	$bad = $args; $bad['market_country'] = '';
	profile_check( is_wp_error( MAD4B_SCP_Search_Profile_Admin::save( $bad ) ), 'missing audience country denied' );
	profile_check( 0 === count( $GLOBALS['fixture_http'] ), 'guided setup does not call search providers' );
} );
profile_case( 'profile_editor_preserves_policy_and_fences_stale_or_renamed_forms', static function () {
	$saved = MAD4B_SCP_Search_Profile_Admin::save( profile_form() ); $p = $saved['profile']; $raw = array_intersect_key( $p, array_flip( MAD4B_SCP_Search_Context::policy()['profile_fields'] ) );
	$raw['markets'][] = array( 'id' => 'second-market', 'country' => 'FR', 'provider_locations' => array( 'alpha' => array( 'id' => 'fixture-location', 'precision' => 'country' ) ) );
	$raw['refresh_policy']['baseline_seconds'] = 86400;
	$edit = array( 'operation' => 'edit', 'profile_id' => $p['profile_id'], 'expected_revision' => '1', 'profile_json' => json_encode( $raw ) );
	$result = MAD4B_SCP_Search_Profile_Admin::save( $edit ); profile_check( ! is_wp_error( $result ) && 2 === $result['profile']['revision'], 'current edit commits next revision' );
	profile_check( $raw['markets'] === $result['profile']['markets'] && 86400 === $result['profile']['refresh_policy']['baseline_seconds'], 'multi-market location mappings and advanced policy preserved' );
	profile_check( is_wp_error( MAD4B_SCP_Search_Profile_Admin::save( $edit ) ), 'stale revision denied' );
	$edit['expected_revision'] = '2';
	$state_edit = $raw; $state_edit['enabled'] = true; $edit['profile_json'] = json_encode( $state_edit ); profile_check( is_wp_error( MAD4B_SCP_Search_Profile_Admin::save( $edit ) ), 'generic JSON cannot resume observations' );
	$spend_edit = $raw; $spend_edit['provider_policy']['freeze_spend'] = false; $edit['profile_json'] = json_encode( $spend_edit ); profile_check( is_wp_error( MAD4B_SCP_Search_Profile_Admin::save( $edit ) ), 'generic JSON cannot unfreeze provider spend' );
	$raw['profile_id'] = 'renamed.profile'; $edit['profile_json'] = json_encode( $raw ); profile_check( is_wp_error( MAD4B_SCP_Search_Profile_Admin::save( $edit ) ), 'profile cannot be renamed through another form' );
	$raw['profile_id'] = $p['profile_id']; $raw['provider_policy']['endpoint'] = 'https://example.invalid'; $edit['profile_json'] = json_encode( $raw ); profile_check( is_wp_error( MAD4B_SCP_Search_Profile_Admin::save( $edit ) ), 'security fields remain denied by domain validator' );
	unset( $raw['provider_policy']['endpoint'] );
	foreach ( array( 'brand_id', 'objective' ) as $field ) { $bad = $raw; $bad[ $field ] = array( 'invalid' ); $edit['profile_json'] = json_encode( $bad ); profile_check( is_wp_error( MAD4B_SCP_Search_Profile_Admin::save( $edit ) ), 'typed profile identity/objective: ' . $field ); }
	$bad = $raw; $bad['markets'][0]['country'] = array( 'GB' ); $edit['profile_json'] = json_encode( $bad ); profile_check( is_wp_error( MAD4B_SCP_Search_Profile_Admin::save( $edit ) ), 'array country cannot reach the page renderer' );
} );

profile_case( 'guided_existing_profile_edit_preserves_advanced_state_and_location_safety', static function () {
	$saved = MAD4B_SCP_Search_Profile_Admin::save( profile_form( 'guided-existing' ) );
	profile_check( ! is_wp_error( $saved ), 'created fixture' );
	$p = $saved['profile'];
	$raw = array_intersect_key( $p, array_flip( MAD4B_SCP_Search_Context::policy()['profile_fields'] ) );
	$raw['markets'][] = array( 'id' => 'second-market', 'country' => 'DE' );
	$raw['refresh_policy']['baseline_seconds'] = 86400;
	$advanced = MAD4B_SCP_Search_Profile_Admin::save( array( 'operation' => 'edit', 'profile_id' => $p['profile_id'], 'expected_revision' => '1', 'profile_json' => json_encode( $raw ) ) );
	profile_check( ! is_wp_error( $advanced ) && 2 === $advanced['profile']['revision'], 'advanced policy fixture' );
	ob_start(); MAD4B_SCP_Search_Profile_Admin::render( $p['profile_id'] ); $html = ob_get_clean();
	foreach ( array( 'Edit audience settings (guided)', 'name="audience_country"', 'name="audience_languages"', 'name="audience_devices"', 'name="objective"', 'name="operation" value="edit_guided"' ) as $text ) profile_check( false !== strpos( $html, $text ), 'guided edit UI: ' . $text );
	$form = array( 'operation' => 'edit_guided', 'profile_id' => $p['profile_id'], 'expected_revision' => '2', 'audience_country' => 'GB', 'audience_languages' => 'EN_us, ES', 'audience_devices' => 'mobile, desktop', 'objective' => 'US tourism tours' );
	$edited = MAD4B_SCP_Search_Profile_Admin::save( $form );
	profile_check( ! is_wp_error( $edited ) && 3 === $edited['profile']['revision'], 'guided edit applied and verified' );
	$q = $edited['profile'];
	profile_check( 'GB' === $q['markets'][0]['country'] && 'DE' === $q['markets'][1]['country'], 'other market preserved' );
	profile_check( array( 'en-us', 'es' ) === $q['language_policy']['desired'] && array( 'mobile', 'desktop' ) === $q['provider_policy']['devices'], 'audience settings saved' );
	profile_check( 86400 === $q['refresh_policy']['baseline_seconds'] && ! $q['enabled'] && $q['provider_policy']['freeze_spend'] && array() === $q['budget_policy']['nodes'], 'advanced configuration and frozen state preserved' );
	profile_check( is_wp_error( MAD4B_SCP_Search_Profile_Admin::save( $form ) ), 'stale revision denied' );
	$form['expected_revision'] = '3';
	$bad = $form; $bad['audience_devices'] = 'smartwatch'; profile_check( is_wp_error( MAD4B_SCP_Search_Profile_Admin::save( $bad ) ), 'unknown device denied' );
	$bad = $form; $bad['audience_languages'] = '<script>'; profile_check( is_wp_error( MAD4B_SCP_Search_Profile_Admin::save( $bad ) ), 'invalid language denied' );
	$bad = $form; $bad['audience_country'] = ''; profile_check( is_wp_error( MAD4B_SCP_Search_Profile_Admin::save( $bad ) ), 'missing country denied' );
	$bad = $form; $bad['objective'] = ''; profile_check( is_wp_error( MAD4B_SCP_Search_Profile_Admin::save( $bad ) ), 'empty objective denied' );
	$bad = $form; $bad['audience_country'] = 'US';
	$market_denied = MAD4B_SCP_Search_Profile_Admin::save( $bad );
	profile_check( is_wp_error( $market_denied ) && 'mad4b_search_profile_market_identity_locked' === $market_denied->get_error_code(), 'existing market country immutable even when no provider locations are configured' );
	$bad_json = array_intersect_key( $q, array_flip( MAD4B_SCP_Search_Context::policy()['profile_fields'] ) );
	$bad_json['markets'][0]['country'] = 'US';
	profile_check( is_wp_error( MAD4B_SCP_Search_Profile_Admin::save( array( 'operation' => 'edit', 'profile_id' => $p['profile_id'], 'expected_revision' => '3', 'profile_json' => json_encode( $bad_json ) ) ) ), 'advanced JSON editor cannot change country while retaining market ID' );
	$map = array_intersect_key( $q, array_flip( MAD4B_SCP_Search_Context::policy()['profile_fields'] ) );
	$map['markets'][0]['provider_locations'] = array( 'alpha' => array( 'id' => 'us-id', 'precision' => 'country' ) );
	$mapping = MAD4B_SCP_Search_Profile_Admin::save( array( 'operation' => 'edit', 'profile_id' => $p['profile_id'], 'expected_revision' => '3', 'profile_json' => json_encode( $map ) ) );
	profile_check( ! is_wp_error( $mapping ), 'provider mapping saved' );
	$form['expected_revision'] = '4'; $form['audience_country'] = 'FR';
	profile_check( is_wp_error( MAD4B_SCP_Search_Profile_Admin::save( $form ) ), 'country changes cannot reuse an existing provider mapping' );
	$form['audience_country'] = 'GB';
	$resume = MAD4B_SCP_Search_Experience::control( array( 'profile_id' => $p['profile_id'], 'control' => 'resume', 'expected_revision' => 4 ) );
	profile_check( ! is_wp_error( $resume ) && 5 === $resume['profile']['revision'], 'fixture resumes through explicit control' );
	$form['expected_revision'] = '5'; $form['audience_languages'] = 'fr';
	$blocked_active = MAD4B_SCP_Search_Profile_Admin::save( $form );
	profile_check( is_wp_error( $blocked_active ) && 'mad4b_search_profile_targeting_requires_pause_and_spend_freeze' === $blocked_active->get_error_code(), 'guided target mutation denied while observations run' );
	$active_raw = array_intersect_key( $resume['profile'], array_flip( MAD4B_SCP_Search_Context::policy()['profile_fields'] ) );
	$active_raw['language_policy']['desired'] = array( 'fr' );
	profile_check( is_wp_error( MAD4B_SCP_Search_Profile_Admin::save( array( 'operation' => 'edit', 'profile_id' => $p['profile_id'], 'expected_revision' => '5', 'profile_json' => json_encode( $active_raw ) ) ) ), 'advanced JSON cannot bypass the active profile retarget fence' );
	$pause = MAD4B_SCP_Search_Experience::control( array( 'profile_id' => $p['profile_id'], 'control' => 'pause', 'expected_revision' => 5 ) );
	profile_check( ! is_wp_error( $pause ) && 6 === $pause['profile']['revision'], 'fixture pauses through explicit control' );
	$unfreeze = MAD4B_SCP_Search_Experience::control( array( 'profile_id' => $p['profile_id'], 'control' => 'unfreeze_spend', 'expected_revision' => 6 ) );
	profile_check( ! is_wp_error( $unfreeze ) && 7 === $unfreeze['profile']['revision'], 'fixture unfreezes through explicit control' );
	$form['expected_revision'] = '7';
	$blocked_spend = MAD4B_SCP_Search_Profile_Admin::save( $form );
	profile_check( is_wp_error( $blocked_spend ) && 'mad4b_search_profile_targeting_requires_pause_and_spend_freeze' === $blocked_spend->get_error_code(), 'guided target mutation denied while provider spend is unfrozen' );
	$freeze = MAD4B_SCP_Search_Experience::control( array( 'profile_id' => $p['profile_id'], 'control' => 'freeze_spend', 'expected_revision' => 7 ) );
	profile_check( ! is_wp_error( $freeze ) && 8 === $freeze['profile']['revision'], 'fixture refreezes via explicit control' );
	$form['expected_revision'] = '8';
	$safe = MAD4B_SCP_Search_Profile_Admin::save( $form );
	profile_check( ! is_wp_error( $safe ) && 9 === $safe['profile']['revision'] && array( 'fr' ) === $safe['profile']['language_policy']['desired'], 'guided editing resumes only after paused and spend frozen' );
	profile_check( 0 === count( $GLOBALS['fixture_http'] ), 'guided edit never calls a paid provider' );
} );
profile_case( 'profile_runtime_state_controls_are_explicit_revision_fenced_and_read_back', static function () {
	$saved = MAD4B_SCP_Search_Profile_Admin::save( profile_form() ); $id = $saved['profile']['profile_id'];
	ob_start(); MAD4B_SCP_Search_Profile_Admin::render( $id ); $html = ob_get_clean();
	foreach ( array( 'Profile runtime controls', 'Resume observations', 'Unfreeze provider spend', 'cannot be changed through this JSON editor' ) as $text ) profile_check( false !== strpos( $html, $text ), 'explicit profile state control visible: ' . $text );
	$resume = MAD4B_SCP_Search_Experience::control( array( 'profile_id' => $id, 'control' => 'resume', 'expected_revision' => 1 ) );
	profile_check( ! is_wp_error( $resume ) && ! empty( $resume['control_readback_verified'] ) && ! empty( $resume['profile']['enabled'] ) && 2 === $resume['profile']['revision'], 'resume uses exact revision and verified readback' );
	profile_check( is_wp_error( MAD4B_SCP_Search_Experience::control( array( 'profile_id' => $id, 'control' => 'freeze_spend', 'expected_revision' => 1 ) ) ), 'stale runtime control revision denied' );
	$unfreeze = MAD4B_SCP_Search_Experience::control( array( 'profile_id' => $id, 'control' => 'unfreeze_spend', 'expected_revision' => 2 ) );
	profile_check( ! is_wp_error( $unfreeze ) && ! empty( $unfreeze['control_readback_verified'] ) && empty( $unfreeze['profile']['provider_policy']['freeze_spend'] ) && 3 === $unfreeze['profile']['revision'], 'spend unfreeze uses independent verified readback' );
	profile_check( 0 === count( $GLOBALS['fixture_http'] ), 'profile state controls never call a provider' );
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
	profile_check( false !== strpos( $html, 'Add or manage search API credentials' ) && false !== strpos( $html, 'section=providers' ), 'configured profile retains an actual registered API setup handoff' );
	$_GET = array( 'profile_id' => array( 'invalid' ) ); profile_check( '' === MAD4B_SCP_Search_Profile_Admin::selected_id(), 'array profile safely rejected' );
	$_GET = array( 'mad4b_notice_receipt' => MAD4B_SCP_Admin_Experience::notice_receipt( MAD4B_SCP_Search_Experience::PAGE_SLUG, 'profile_saved', $sha ) );
	profile_check( MAD4B_SCP_Admin_Experience::notice_verified( MAD4B_SCP_Search_Experience::PAGE_SLUG, 'profile_saved', $sha ), 'current view receipt valid' );
	profile_check( ! MAD4B_SCP_Admin_Experience::notice_verified( MAD4B_SCP_Search_Experience::PAGE_SLUG, 'profile_saved', 'other-state' ) && ! MAD4B_SCP_Admin_Experience::notice_verified( 'other-page', 'profile_saved', $sha ), 'state/page replay denied' );
	$_GET['mad4b_notice_receipt'][12] = 'x'; profile_check( ! MAD4B_SCP_Admin_Experience::notice_verified( MAD4B_SCP_Search_Experience::PAGE_SLUG, 'profile_saved', $sha ), 'tampered feedback denied' );
} );
$failed = array_filter( $cases, static function ( $row ) { return 'PASS' !== $row['status']; } );
echo json_encode( array( 'contract' => 'mad4b.adaptive-search-profile-admin-evidence.v1', 'status' => $failed ? 'FAIL' : 'PASS', 'fixture_count' => count( $cases ), 'assertions' => $assertions, 'fixtures' => $cases, 'authorizing' => false ), JSON_UNESCAPED_SLASHES ) . "\n"; exit( $failed ? 1 : 0 );
