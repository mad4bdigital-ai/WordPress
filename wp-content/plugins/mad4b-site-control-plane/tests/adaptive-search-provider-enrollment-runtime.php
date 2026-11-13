<?php
require __DIR__ . '/fixtures/search-runtime-fixtures.php';
set_error_handler( static function ( $severity, $message, $file, $line ) { if ( error_reporting() & $severity ) throw new ErrorException( $message, 0, $severity, $file, $line ); } );
function wp_salt( $scheme ) { return isset( $GLOBALS['enrollment_salt'] ) ? $GLOBALS['enrollment_salt'] : 'hermetic-site-secret-not-a-live-credential'; }
function esc_html( $v ) { return htmlspecialchars( (string) $v, ENT_QUOTES, 'UTF-8' ); }
function esc_attr( $v ) { return esc_html( $v ); }
function esc_url( $v ) { return esc_html( $v ); }
function __( $v, $domain = '' ) { return (string) $v; }
function esc_html__( $v, $domain = '' ) { return esc_html( __( $v, $domain ) ); }
function esc_attr__( $v, $domain = '' ) { return esc_attr( __( $v, $domain ) ); }
function wp_get_session_token() { return isset( $GLOBALS['enrollment_session'] ) ? $GLOBALS['enrollment_session'] : 'fixture-session'; }
function add_query_arg( $key, $value = null, $url = null ) {
	if ( is_array( $key ) ) { $query = $key; $url = $value; } else $query = array( $key => $value );
	$url = null === $url ? home_url( '/' ) : $url;
	$parts = explode( '?', $url, 2 ); $existing = array();
	if ( isset( $parts[1] ) ) parse_str( $parts[1], $existing );
	return $parts[0] . '?' . http_build_query( array_merge( $existing, $query ) );
}
function wp_unslash( $v ) { return is_array( $v ) ? array_map( 'wp_unslash', $v ) : ( is_string( $v ) ? stripslashes( $v ) : $v ); }
function wp_nonce_field( $action ) { echo '<input type="hidden" name="_wpnonce" value="' . esc_attr( hash( 'sha256', $action ) ) . '">'; }
function check_admin_referer( $action ) { if ( ! isset( $_POST['_wpnonce'] ) || hash( 'sha256', $action ) !== $_POST['_wpnonce'] ) throw new RuntimeException( 'nonce_denied' ); }
function wp_die( $message, $title = '', $args = array() ) { throw new RuntimeException( 'post_denied:' . ( isset( $args['response'] ) ? $args['response'] : '' ) ); }
function submit_button( $text, $type, $name, $wrap ) { echo '<button>' . esc_html( $text ) . '</button>'; }
// Use the actual registered route resolver; no synthetic link can bypass its capability checks.
if ( ! class_exists( 'MAD4B_SCP_Admin_Workspace' ) ) require dirname( __DIR__ ) . '/includes/class-mad4b-scp-admin-workspace.php';
MAD4B_SCP_Admin_Route_Registry::register( 'mad4b-search-intelligence', 'manage_options' );
MAD4B_SCP_Admin_Route_Registry::register( 'mad4b-control-plane-site-profile', 'manage_options' );
$assertions = 0; $results = array();
function enrollment_check( $condition, $message ) { ++$GLOBALS['assertions']; if ( ! $condition ) throw new RuntimeException( $message ); }
function enrollment_case( $id, $callback ) {
	$start = $GLOBALS['assertions']; asi_reset(); unset( $GLOBALS['enrollment_salt'], $GLOBALS['enrollment_session'] );
	$GLOBALS['fixture_providers'] = array( new MAD4B_SCP_Search_SerpApi_Adapter(), new MAD4B_SCP_Search_DataForSEO_Adapter() );
	$GLOBALS['fixture_http'] = array(); $GLOBALS['fixture_http_response'] = static function () { throw new RuntimeException( 'unexpected HTTP' ); };
	MAD4B_SCP_Search_Provider_Connections::boot(); $_GET = array(); $_POST = array();
	try { $callback(); $GLOBALS['results'][] = array( 'fixture' => $id, 'status' => 'PASS', 'assertions' => $GLOBALS['assertions'] - $start ); }
	catch ( Throwable $e ) { $GLOBALS['results'][] = array( 'fixture' => $id, 'status' => 'FAIL', 'assertions' => $GLOBALS['assertions'] - $start, 'error' => $e->getMessage(), 'at' => basename( $e->getFile() ) . ':' . $e->getLine() ); }
}
function enrollment_action( $id, $action, $revision, $credentials = array() ) { return MAD4B_SCP_Search_Provider_Connections::apply( array( 'provider_id' => $id, 'operation' => $action, 'expected_revision' => $revision, 'credentials' => $credentials ) ); }
function enrollment_row( $id ) { return MAD4B_SCP_Search_Store::read( MAD4B_SCP_Search_Provider_Connections::KIND, $id ); }
function enrollment_response( array $p ) { return array( 'response' => array( 'code' => 200 ), 'body' => json_encode( $p ) ); }
function enrollment_serp_receipt() { return array( 'account_id' => 'native-fixture-account', 'account_status' => 'Active', 'api_key' => 'fixture-serp-private', 'account_email' => 'private@example.test', 'total_searches_left' => 47, 'searches_per_month' => 250, 'this_month_usage' => 203, 'plan_renewal_date' => gmdate( 'Y-m-d', time() + 86400 * 20 ) ); }
function enrollment_verify_serp() {
	$saved = enrollment_action( 'serpapi', 'save', 0, array( 'api_key' => 'fixture-serp-private' ) );
	enrollment_check( ! is_wp_error( $saved ), 'save before account observation' );
	$GLOBALS['fixture_http_response'] = static function () { return enrollment_response( enrollment_serp_receipt() ); };
	return enrollment_action( 'serpapi', 'test', 1 );
}
function enrollment_no_secret( $value, array $secrets ) { $raw = json_encode( $value ); foreach ( $secrets as $secret ) enrollment_check( false === strpos( $raw, $secret ), 'secret absent from projection/storage' ); }

enrollment_case( 'local_enrollment_encryption_unconfigured_ui_and_secret_projection', static function () {
	$s = enrollment_action( 'serpapi', 'save', 0, array( 'api_key' => 'fixture-serp-private' ) );
	enrollment_check( ! is_wp_error( $s ) && $s['configured'] && 1 === $s['revision'] && 'NOT_CHECKED' === $s['connection_state'], 'save is unverified configuration' );
	enrollment_check( 0 === count( $GLOBALS['fixture_http'] ), 'save never makes an outbound request' );
	enrollment_no_secret( array( $s, enrollment_row( 'serpapi' ) ), array( 'fixture-serp-private' ) );
	$a = new MAD4B_SCP_Search_SerpApi_Adapter(); $d = $a->descriptor();
	enrollment_check( ! $d['active'] && ! $d['certified'], 'configuration cannot activate or certify capture' );
	enrollment_check( 'fixture-serp-private' === apply_filters( 'mad4b_scp_search_secret_resolve', null, $d['secret_handle'], 'serpapi' ), 'exact local handle resolves at the server boundary' );
	enrollment_check( null === apply_filters( 'mad4b_scp_search_secret_resolve', null, $d['secret_handle'], 'dataforseo' ), 'handle cannot be borrowed by another adapter' );
	enrollment_check( 'external-credential' === apply_filters( 'mad4b_scp_search_secret_resolve', 'external-credential', 'external.vault', 'serpapi' ), 'external vault integration preserved' );
	$_GET = array( 'section' => 'providers' ); ob_start(); MAD4B_SCP_Search_Experience::render(); $html = ob_get_clean();
	foreach ( array( 'SerpApi', 'DataForSEO', 'credentials[api_key]', 'credentials[login]', 'credentials[password]', 'Test saved connection', 'Governed budget' ) as $text ) enrollment_check( false !== strpos( $html, $text ), 'unconfigured page exposes enrollment ' . $text );
	foreach ( array( 'fixture-serp-private', 'asi.local.' ) as $secret ) enrollment_check( false === strpos( $html, $secret ), 'no saved secret or handle in HTML' );
	enrollment_check( ! preg_match( '/name="credentials\[[^"]+\]" value="[^"]+"/', $html ), 'all rendered credential values blank' );
	enrollment_check( 0 === count( $GLOBALS['fixture_http'] ), 'page render remains a read-only projection' );
	ob_start(); MAD4B_SCP_Search_Provider_Connections::render( 'fixture.search' ); $profile_html = ob_get_clean();
	enrollment_check( false !== strpos( $profile_html, 'name="profile_id" value="fixture.search"' ), 'provider enrollment preserves the selected profile on return' );
	$keep = enrollment_action( 'serpapi', 'save', 1, array( 'api_key' => '' ) );
	enrollment_check( 1 === $keep['revision'] && $keep['configured'], 'blank save preserves the exact connection' );
} );
enrollment_case( 'provider_feedback_bound_to_current_revision_operation_and_session', static function () {
	$saved = enrollment_action( 'serpapi', 'save', 0, array( 'api_key' => 'fixture-serp-private' ) );
	$binding = 'serpapi:' . $saved['revision'] . ':save:' . $saved['connection_state'];
	$_GET = array( 'mad4b_provider_notice' => 'serpapi', 'mad4b_provider_operation' => 'save' );
	$render = static function () { ob_start(); MAD4B_SCP_Search_Provider_Connections::render(); return ob_get_clean(); };
	enrollment_check( false === strpos( $render(), 'notice-success' ), 'query flags cannot manufacture successful feedback' );
	$_GET['mad4b_notice_receipt'] = MAD4B_SCP_Admin_Experience::notice_receipt( 'mad4b-search-intelligence', 'provider_save', $binding );
	enrollment_check( false !== strpos( $render(), 'Provider settings saved and verified.' ), 'current signed save receipt renders useful feedback' );
	$GLOBALS['enrollment_session'] = 'different-fixture-session';
	enrollment_check( false === strpos( $render(), 'notice-success' ), 'receipt cannot move to a different session' );
	unset( $GLOBALS['enrollment_session'] );
	$_GET['mad4b_provider_operation'] = 'test';
	enrollment_check( false === strpos( $render(), 'notice-success' ), 'receipt cannot claim a different operation' );
	$_GET['mad4b_provider_operation'] = 'save';
	enrollment_action( 'serpapi', 'save', 1, array( 'api_key' => 'fixture-rotated-key' ) );
	enrollment_check( false === strpos( $render(), 'notice-success' ), 'stale revision cannot claim the current saved configuration' );
	enrollment_check( 0 === count( $GLOBALS['fixture_http'] ), 'feedback never probes an account or submits a search' );
} );
enrollment_case( 'native_account_probe_quota_privacy_and_capture_boundary', static function () {
	enrollment_action( 'serpapi', 'save', 0, array( 'api_key' => 'fixture-serp-private' ) );
	$GLOBALS['fixture_http_response'] = static function ( $url, $args ) {
		parse_str( parse_url( $url, PHP_URL_QUERY ), $q );
		enrollment_check( 'https://serpapi.com/account.json' === strtok( $url, '?' ) && 'fixture-serp-private' === $q['api_key'], 'only native pinned account endpoint receives the key' );
		enrollment_check( 'GET' === $args['method'] && 0 === $args['redirection'] && true === $args['sslverify'], 'account check is bounded and TLS verified' );
		enrollment_check( ! isset( $q['q'], $args['body'] ), 'no search query or paid task submitted' );
		return enrollment_response( array_merge( enrollment_serp_receipt(), array( 'unexpected_secret' => 'private payload' ) ) );
	};
	$s = enrollment_action( 'serpapi', 'test', 1 );
	enrollment_check( ! is_wp_error( $s ) && 'VERIFIED' === $s['connection_state'] && 2 === $s['revision'], 'account check CAS receipt' );
	enrollment_check( 47 === $s['quota']['remaining'] && 250 === $s['quota']['monthly_limit'] && 203 === $s['quota']['monthly_used'], 'native account limits are dynamic, not a fixed free-plan quota' );
	enrollment_no_secret( array( $s, enrollment_row( 'serpapi' ) ), array( 'fixture-serp-private', 'private@example.test', 'private payload' ) );
	$a = new MAD4B_SCP_Search_SerpApi_Adapter(); $d = $a->descriptor();
	enrollment_check( $d['shared_account'] && MAD4B_SCP_Search_Contracts::sha( $d['account_id'] ), 'observed shared identity reaches provider selection' );
	enrollment_check( isset( $s['quota']['renewal_date'] ) && ! isset( $s['quota']['reset_at'] ) && empty( $d['usage'] ), 'date-only renewal is not fabricated into an exact budget reset' );
	enrollment_check( ! $d['certified'] && ! $d['active'] && empty( $d['economics'] ) && empty( $d['evidence_rights'] ), 'account observation grants no rights, pricing or behavioral certification' );
	$r = array_merge( asi_candidate(), array( 'depth' => 3, 'requested_country' => 'US' ) );
	$denied = $a->execute( $a->prepare( $r, asi_profile()['markets'][0] ) );
	enrollment_check( is_wp_error( $denied ) && 1 === count( $GLOBALS['fixture_http'] ), 'capture remains fail-closed after account verification' );
	enrollment_check( false === $s['authorizing'], 'account receipt grants no authority' );
} );
enrollment_case( 'native_credit_pool_and_malformed_receipts_do_not_invent_allowance', static function () {
	$s = enrollment_verify_serp(); enrollment_check( 'VERIFIED' === $s['connection_state'], 'valid initial account' );
	$GLOBALS['fixture_http_response'] = static function () { return enrollment_response( array_merge( enrollment_serp_receipt(), array( 'plan_renewal_date' => null, 'total_searches_left' => 0 ) ) ); };
	$s = enrollment_action( 'serpapi', 'test', 2 );
	enrollment_check( 0 === $s['quota']['remaining'] && ! isset( $s['quota']['reset_at'] ), 'zero allowance is valid; unknown renewal is not fabricated' );
	enrollment_check( empty( ( new MAD4B_SCP_Search_SerpApi_Adapter() )->descriptor()['usage'] ), 'unknown renewal cannot enter the quota admission path' );
	$revision = 3;
	foreach ( array( array( 'total_searches_left' => -1 ), array( 'total_searches_left' => '100' ), array( 'account_status' => 'Inactive' ), array( 'account_id' => array( 'bad' ) ) ) as $bad ) {
		$GLOBALS['fixture_http_response'] = static function () use ( $bad ) { return enrollment_response( array_merge( enrollment_serp_receipt(), $bad ) ); };
		$result = enrollment_action( 'serpapi', 'test', $revision++ );
		enrollment_check( is_wp_error( $result ), 'malformed or inactive account receipt denied' );
		$s = MAD4B_SCP_Search_Provider_Connections::status( 'serpapi' );
		enrollment_check( 'CHECK_FAILED' === $s['connection_state'] && empty( $s['quota'] ), 'failed check invalidates the old verified allowance' );
	}
	$GLOBALS['fixture_http_response'] = static function () { return new WP_Error( 'upstream', 'fixture-serp-private', array( 'url' => 'private' ) ); };
	$error = enrollment_action( 'serpapi', 'test', $revision );
	enrollment_check( is_wp_error( $error ) && false === strpos( $error->get_error_message(), 'fixture-serp-private' ), 'transport error sanitized' );
} );
enrollment_case( 'dataforseo_api_credentials_balance_and_atomic_replacement', static function () {
	$c = array( 'login' => 'fixture-api-login', 'password' => 'fixture:api password' );
	$s = enrollment_action( 'dataforseo', 'save', 0, $c ); enrollment_check( ! is_wp_error( $s ), 'API credential pair saved' );
	$GLOBALS['fixture_http_response'] = static function ( $url, $args ) use ( $c ) {
		enrollment_check( 'https://api.dataforseo.com/v3/appendix/user_data' === $url && 'GET' === $args['method'], 'DataForSEO native account check only' );
		enrollment_check( 'Basic ' . base64_encode( $c['login'] . ':' . $c['password'] ) === $args['headers']['Authorization'], 'API password colon and space preserved literally' );
		return enrollment_response( array( 'status_code' => 20000, 'tasks' => array( array( 'status_code' => 20000, 'result' => array( array( 'login' => $c['login'], 'money' => array( 'balance' => 42.75 ), 'price' => array( 'untrusted' => $c['password'] ) ) ) ) ) ) );
	};
	$s = enrollment_action( 'dataforseo', 'test', 1 );
	enrollment_check( ! is_wp_error( $s ) && 42.75 === $s['balance']['amount'] && 'USD' === $s['balance']['currency'], 'typed native monetary balance displayed' );
	enrollment_check( empty( $s['quota'] ) && empty( ( new MAD4B_SCP_Search_DataForSEO_Adapter() )->descriptor()['usage'] ), 'wallet balance never converted into search units' );
	enrollment_no_secret( array( $s, enrollment_row( 'dataforseo' ) ), array_values( $c ) );
	foreach ( array( array( 'login' => 'new-login', 'password' => '' ), array( 'login' => 'bad:login', 'password' => 'valid' ), array( 'login' => array(), 'password' => 'valid' ), array( 'login' => 'valid', 'password' => "bad\npassword" ), array( 'login' => 'valid', 'password' => str_repeat( 'x', 2049 ) ) ) as $bad ) {
		enrollment_check( is_wp_error( enrollment_action( 'dataforseo', 'save', 2, $bad ) ), 'partial, ambiguous or unbounded replacement denied' );
		enrollment_check( 2 === MAD4B_SCP_Search_Provider_Connections::status( 'dataforseo' )['revision'], 'denied replacement leaves verified credentials unchanged' );
	}
	enrollment_check( 1 === count( $GLOBALS['fixture_http'] ), 'credential validation never probes the provider' );
} );
enrollment_case( 'credential_rotation_probe_race_revision_conflict_and_tombstone', static function () {
	enrollment_action( 'serpapi', 'save', 0, array( 'api_key' => 'fixture-serp-private' ) );
	$a = new MAD4B_SCP_Search_SerpApi_Adapter(); $old_handle = $a->descriptor()['secret_handle'];
	$GLOBALS['fixture_http_response'] = static function () {
		$rotated = enrollment_action( 'serpapi', 'save', 1, array( 'api_key' => 'fixture-rotated-key' ) );
		enrollment_check( ! is_wp_error( $rotated ) && 'NOT_CHECKED' === $rotated['connection_state'], 'rotation wins while the previous key is being probed' );
		return enrollment_response( enrollment_serp_receipt() );
	};
	enrollment_check( is_wp_error( enrollment_action( 'serpapi', 'test', 1 ) ), 'old probe cannot overwrite a concurrent credential rotation' );
	$s = MAD4B_SCP_Search_Provider_Connections::status( 'serpapi' );
	enrollment_check( 2 === $s['revision'] && 'NOT_CHECKED' === $s['connection_state'] && empty( $s['quota'] ), 'winning new credentials retain no old account evidence' );
	enrollment_check( null === apply_filters( 'mad4b_scp_search_secret_resolve', null, $old_handle, 'serpapi' ), 'old secret handle revoked' );
	enrollment_check( 'fixture-rotated-key' === apply_filters( 'mad4b_scp_search_secret_resolve', null, $a->descriptor()['secret_handle'], 'serpapi' ), 'new handle resolves rotated credential' );
	enrollment_check( is_wp_error( enrollment_action( 'serpapi', 'test', 1 ) ) && 1 === count( $GLOBALS['fixture_http'] ), 'stale repeated submit denied before network' );
	$removed = enrollment_action( 'serpapi', 'remove', 2 );
	enrollment_check( ! $removed['configured'] && 3 === $removed['revision'] && 'NOT_CONFIGURED' === $removed['connection_state'], 'removal persists a CAS tombstone' );
	enrollment_check( is_wp_error( enrollment_action( 'serpapi', 'save', 0, array( 'api_key' => 'stale-replay' ) ) ), 'tombstone prevents stale form replay' );
	enrollment_check( ! isset( enrollment_row( 'serpapi' )['envelope'] ), 'deletion removes the encrypted secret material' );
	$events = MAD4B_SCP_Search_Store::list_rows( 'event', '', 50 );
	enrollment_no_secret( $events, array( 'fixture-serp-private', 'fixture-rotated-key' ) );
} );
enrollment_case( 'authenticated_envelope_rejects_tampering_provider_and_site_replay', static function () {
	enrollment_verify_serp(); $row = enrollment_row( 'serpapi' ); $store = $GLOBALS['fixture_store'];
	$key = MAD4B_SCP_Search_Store::key( MAD4B_SCP_Search_Provider_Connections::KIND, 'serpapi' );
	$bad = $row; $bad['envelope']['ciphertext'] = base64_encode( 'tampered' );
	enrollment_check( $store->compare_exchange( $key, $row, $bad ), 'tamper fixture stored' );
	enrollment_check( is_wp_error( MAD4B_SCP_Search_Provider_Connections::status( 'serpapi' ) ), 'ciphertext tampering denied' );
	enrollment_check( '' === ( new MAD4B_SCP_Search_SerpApi_Adapter() )->descriptor()['secret_handle'], 'tampered descriptor cannot retain old execution handle' );
	enrollment_check( $store->compare_exchange( $key, $bad, $row ), 'restore fixture' );
	$bad = $row; $bad['_revision']++;
	enrollment_check( $store->compare_exchange( $key, $row, $bad ), 'revision replay fixture stored' );
	enrollment_check( is_wp_error( MAD4B_SCP_Search_Provider_Connections::status( 'serpapi' ) ), 'authenticated revision mismatch denied' );
	enrollment_check( $store->compare_exchange( $key, $bad, $row ), 'restore revision' );
	$other_key = MAD4B_SCP_Search_Store::key( MAD4B_SCP_Search_Provider_Connections::KIND, 'dataforseo' );
	$other = $row; $other['provider_id'] = 'dataforseo';
	enrollment_check( $store->compare_exchange( $other_key, null, $other ), 'provider replay fixture stored' );
	enrollment_check( is_wp_error( MAD4B_SCP_Search_Provider_Connections::status( 'dataforseo' ) ), 'provider binding rejects copied ciphertext' );
	$GLOBALS['fixture_site'] = '22222222-2222-4222-8222-222222222222';
	$clone_key = MAD4B_SCP_Search_Store::key( MAD4B_SCP_Search_Provider_Connections::KIND, 'serpapi' );
	enrollment_check( $store->compare_exchange( $clone_key, null, $row ), 'site clone replay fixture stored' );
	enrollment_check( is_wp_error( MAD4B_SCP_Search_Provider_Connections::status( 'serpapi' ) ), 'site binding rejects copied ciphertext' );
	unset( $GLOBALS['fixture_site'] ); $GLOBALS['enrollment_salt'] = 'rotated-hermetic-salt-with-no-live-authority';
	enrollment_check( is_wp_error( MAD4B_SCP_Search_Provider_Connections::status( 'serpapi' ) ), 'changed WordPress salt requires replacing credentials' );
	enrollment_check( ! is_wp_error( enrollment_action( 'serpapi', 'save', 2, array( 'api_key' => 'replacement-after-salt-change' ) ) ), 'owner can recover with a complete new credential set' );
} );
enrollment_case( 'local_authorization_nonce_and_strict_post_admission', static function () {
	$GLOBALS['fixture_admin'] = false;
	foreach ( array( 'save', 'test', 'remove' ) as $action ) enrollment_check( is_wp_error( enrollment_action( 'serpapi', $action, 0, array( 'api_key' => 'not-saved' ) ) ), 'administrator admission for ' . $action );
	$GLOBALS['fixture_admin'] = true; $GLOBALS['fixture_environment'] = 'production';
	foreach ( array( 'save', 'test', 'remove' ) as $action ) enrollment_check( is_wp_error( enrollment_action( 'serpapi', $action, 0, array( 'api_key' => 'not-saved' ) ) ), 'Production has no enrollment authority for ' . $action );
	$GLOBALS['fixture_environment'] = 'staging';
	$_POST = array( 'provider_id' => 'serpapi', 'operation' => 'test', 'expected_revision' => '0' );
	try { MAD4B_SCP_Search_Provider_Connections::post(); enrollment_check( false, 'missing nonce must terminate' ); } catch ( RuntimeException $e ) { enrollment_check( 'nonce_denied' === $e->getMessage(), 'provider-bound nonce before any secret or network action' ); }
	$_POST['profile_id'] = array( 'invalid' );
	try { MAD4B_SCP_Search_Provider_Connections::post(); enrollment_check( false, 'invalid profile must terminate' ); } catch ( RuntimeException $e ) { enrollment_check( 'post_denied:400' === $e->getMessage(), 'invalid return profile cannot alter provider credentials' ); }
	foreach ( array( null, array(), '1e2', '-1', '01', '9999999999999999999999' ) as $bad ) enrollment_check( is_wp_error( MAD4B_SCP_Search_Provider_Connections::post_input( array( 'provider_id' => 'serpapi', 'operation' => 'test', 'expected_revision' => $bad ) ) ), 'strict bounded revision admission' );
	enrollment_check( is_wp_error( enrollment_action( array(), 'save', 0, array() ) ), 'array provider input rejected without coercion' );
	enrollment_check( is_wp_error( enrollment_action( 'serpapi', 'save', 0, array( 'api_key' => array() ) ) ), 'array credential rejected' );
	enrollment_check( is_wp_error( enrollment_action( 'serpapi', 'save', 0, array( 'api_key' => 'x', 'endpoint' => 'https://untrusted.example/' ) ) ), 'no arbitrary endpoint or unknown configuration fields' );
	enrollment_check( 0 === count( $GLOBALS['fixture_http'] ) && null === enrollment_row( 'serpapi' ), 'all denied requests leave storage and network untouched' );
} );
$failed = array_filter( $results, static function ( $r ) { return 'FAIL' === $r['status']; } );
echo json_encode( array( 'contract' => 'mad4b.search-provider-enrollment-fixtures.v1', 'evidence_class' => 'hermetic_local_admin_account_enrollment', 'fixtures' => $results, 'fixture_count' => count( $results ), 'assertions' => $assertions, 'status' => $failed ? 'FAIL' : 'PASS', 'authorizing' => false ), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n";
exit( $failed ? 1 : 0 );
