<?php
/**
 * Real HTTP MCP regression for request-local governed execution fencing.
 *
 * The test drives the official MCP Adapter route, injects a nested same-request
 * tools/call from inside the provider side effect, and proves that the provider
 * is invoked once while completed duplicate entry reuses the first result.
 */
$wp_path = getenv( 'MAD4B_TEST_WP_PATH' );
if ( ! is_string( $wp_path ) || '' === trim( $wp_path ) ) {
	fwrite( STDERR, "FAIL mcp-governed-execution-fence: MAD4B_TEST_WP_PATH is required\n" );
	exit( 1 );
}
$wp_path = rtrim( $wp_path, '/\\' );
if ( ! is_file( $wp_path . '/wp-load.php' ) ) {
	fwrite( STDERR, "FAIL mcp-governed-execution-fence: wp-load.php not found\n" );
	exit( 1 );
}

$_SERVER['HTTP_HOST'] = 'staging.egypttourgates.com';
$_SERVER['HTTPS'] = 'on';
$_SERVER['SERVER_PORT'] = '443';
$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['REQUEST_URI'] = '/wp-json/mcp/mad4b-write';

require $wp_path . '/wp-load.php';
wp_set_current_user( 1 );

$fail = static function ( $message ) {
	fwrite( STDERR, 'FAIL mcp-governed-execution-fence: ' . $message . PHP_EOL );
	exit( 1 );
};
$check = static function ( $condition, $message ) use ( $fail ) { if ( ! $condition ) $fail( $message ); };

$check( class_exists( 'MAD4B_SCP_Execution_Fence' ), 'execution fence class unavailable' );
$check( MAD4B_SCP_Execution_Fence::CONTRACT === 'mad4b.same-request-execution-fence.v1', 'unexpected execution fence contract' );
$check( function_exists( 'wp_get_environment_type' ) && 'staging' === wp_get_environment_type(), 'fixture is not staging' );
$check( 'staging.egypttourgates.com' === wp_parse_url( home_url( '/' ), PHP_URL_HOST ), 'fixture is not the governed Staging origin' );
$check( defined( 'MAD4B_MCP_MUTATION_ENABLED' ) && true === MAD4B_MCP_MUTATION_ENABLED, 'Staging mutation gate not configured' );
$provenance = MAD4B_SCP_Live_Acceptance_Observer::build_provenance_status();
$check(
	is_array( $provenance )
		&& ! empty( $provenance['manifest_present'] )
		&& ! empty( $provenance['manifest_valid'] )
		&& ! empty( $provenance['runtime_manifest_match'] )
		&& empty( $provenance['stale'] ),
	'exact candidate provenance is unavailable: ' . wp_json_encode( $provenance )
);

// Materialize the real REST/MCP lifecycle before assertions.
rest_get_server();
$registration = MAD4B_SCP_Servers::registration_status();
$check( ! empty( $registration['mad4b-write']['registered'] ), 'mad4b-write server is not registered' );
$check( in_array( 'media/update-metadata', MAD4B_SCP_Servers::write_tools(), true ), 'Media mutation is not projected on mad4b-write' );

$subject_type = 'ci-mcp-fence';
$subject_identifier = 'ci-mcp-fence-subject';
$subject_fingerprint = hash( 'sha256', $subject_type . "\0" . $subject_identifier );
$request_id = 'ci-mcp-fence-request-1';
$approval_ticket_id = '';
$agent = MAD4B_SCP_Agent_Registry::create_agent( array(
	'slug' => 'ci-mcp-fence-agent-' . substr( wp_generate_uuid4(), 0, 8 ),
	'label' => 'CI MCP Fence Agent',
	'status' => 'enabled',
	'environment' => 'staging',
	'wp_user_id' => get_current_user_id(),
) );
$check( is_array( $agent ) && ! empty( $agent['public_id'] ), 'unable to create CI fence agent' );
$check( true === MAD4B_SCP_Agent_Registry::bind_subject( $agent['public_id'], $subject_type, $subject_fingerprint, 'CI real MCP execution fence' ), 'unable to bind CI fence subject' );
$check( true === MAD4B_SCP_Agent_Registry::grant_ability( $agent['public_id'], 'mad4b-write', 'media/update-metadata', 'media', array(), 'allow', 'staging' ), 'unable to grant Media write authority' );
$check( true === MAD4B_SCP_Agent_Registry::grant_ability( $agent['public_id'], 'mad4b-write', 'mad4b/mutation-undo', 'core', array(), 'allow', 'staging' ), 'unable to grant undo authority' );

add_filter( 'mad4b_scp_authenticated_subject_context', static function () use ( $subject_type, $subject_identifier, &$request_id, &$approval_ticket_id ) {
	return array(
		'authenticated' => true,
		'subject_type' => $subject_type,
		'subject_identifier' => $subject_identifier,
		'token_scopes' => array( 'server:mad4b-write' ),
		'approval_ticket_id' => $approval_ticket_id,
		'auth_method' => 'ci',
		'wp_user_id' => get_current_user_id(),
		'request_id' => $request_id,
		'origin' => 'ci',
	);
}, 999 );

// Media is intentionally low-impact in the generic policy. Real remote Staging
// OAuth forces an exact one-time approval for every write. This CI-only filter
// reproduces that mandatory-approval property without pretending the CI subject
// is an external OAuth bearer or weakening candidate binding.
add_filter( 'mad4b_scp_low_impact_requires_approval', static function ( $required, $ability_name, $provider, $input ) {
	if ( 'media/update-metadata' === (string) $ability_name && 'media' === sanitize_key( (string) $provider ) ) return true;
	return $required;
}, PHP_INT_MAX, 4 );

$dispatch = static function ( array $payload, $session_id = '' ) {
	$request = new WP_REST_Request( 'POST', '/mcp/mad4b-write' );
	$request->set_header( 'Accept', 'application/json, text/event-stream' );
	$request->set_header( 'Content-Type', 'application/json' );
	$request->set_header( 'Mcp-Protocol-Version', '2025-11-25' );
	if ( '' !== $session_id ) $request->set_header( 'Mcp-Session-Id', $session_id );
	$request->set_body( wp_json_encode( $payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
	return rest_do_request( $request );
};

// Internal WordPress REST dispatch does not reliably propagate the transport's
// Mcp-Session-Id response header. Keep initialize on the real MCP route, but
// recover the exact session that initialize persisted through MCP Adapter's
// official SessionManager when the header is absent.
$session_manager = '\\WP\\MCP\\Transport\\Infrastructure\\SessionManager';
$check( class_exists( $session_manager ) && method_exists( $session_manager, 'get_all_user_sessions' ), 'MCP Adapter SessionManager unavailable' );
$sessions_before_initialize = $session_manager::get_all_user_sessions( get_current_user_id() );

$init = $dispatch( array(
	'jsonrpc' => '2.0',
	'id' => 1,
	'method' => 'initialize',
	'params' => array(
		'protocolVersion' => '2025-11-25',
		'capabilities' => array(),
		'clientInfo' => array( 'name' => 'mad4b-ci-fence', 'version' => '1.0.0' ),
	),
) );
$check( $init instanceof WP_REST_Response && 200 === $init->get_status(), 'MCP initialize failed: ' . wp_json_encode( $init instanceof WP_REST_Response ? $init->get_data() : $init ) );
$headers = $init->get_headers();
$session_id = isset( $headers['Mcp-Session-Id'] ) ? (string) $headers['Mcp-Session-Id'] : ( isset( $headers['mcp-session-id'] ) ? (string) $headers['mcp-session-id'] : '' );
if ( '' === $session_id ) {
	$sessions_after_initialize = $session_manager::get_all_user_sessions( get_current_user_id() );
	$new_session_ids = array_values( array_diff( array_keys( $sessions_after_initialize ), array_keys( $sessions_before_initialize ) ) );
	$check( 1 === count( $new_session_ids ), 'MCP initialize did not expose exactly one recoverable session' );
	$session_id = (string) $new_session_ids[0];
}
$check( '' !== $session_id, 'MCP initialize did not create a usable session id' );

$initialized = $dispatch( array( 'jsonrpc' => '2.0', 'method' => 'notifications/initialized' ), $session_id );
$check( $initialized instanceof WP_REST_Response && in_array( $initialized->get_status(), array( 200, 202 ), true ), 'initialized notification failed' );
$list = $dispatch( array( 'jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list', 'params' => array() ), $session_id );
$check( $list instanceof WP_REST_Response && 200 === $list->get_status(), 'tools/list failed' );
$check( false !== strpos( wp_json_encode( $list->get_data() ), 'media-update-metadata' ), 'real MCP tool inventory does not contain media-update-metadata' );

$attachment_id = wp_insert_attachment( array(
	'post_title' => 'MAD4B Fence Before',
	'post_excerpt' => 'before',
	'post_content' => 'before',
	'post_mime_type' => 'image/jpeg',
	'post_status' => 'inherit',
), false, 0, true );
$check( ! is_wp_error( $attachment_id ) && $attachment_id > 0, 'unable to create disposable attachment' );
update_post_meta( $attachment_id, '_wp_attachment_image_alt', 'before alt' );
$media_get = wp_get_ability( 'media/get' );
$before = $media_get->execute( array( 'attachment_id' => $attachment_id ) );
$check( ! is_wp_error( $before ) && ! empty( $before['sha256'] ), 'unable to read disposable Media state' );

$clean_input = array(
	'attachment_id' => $attachment_id,
	'expected_sha256' => $before['sha256'],
	'alt' => 'after fence alt',
);
$target = MAD4B_SCP_Authorization::target_fingerprint( 'media/update-metadata', 'media', $clean_input );
$check( is_string( $target ) && preg_match( '/^[a-f0-9]{64}$/', $target ), 'unable to resolve Media target fingerprint' );
$ticket = MAD4B_SCP_Approval_Tickets::create_pending( $agent['public_id'], 'mad4b-write', 'media/update-metadata', 'media', $target, $clean_input, 'mutation', 'CI real MCP fence approval', 600 );
$check( is_array( $ticket ) && 'pending' === $ticket['status'], 'unable to create execution approval' );
$candidate_binding = MAD4B_SCP_Approval_Tickets::bind_ticket_to_current_candidate( $ticket['ticket_id'] );
$check(
	is_array( $candidate_binding ) && 'mad4b.approval-candidate-binding.v1' === $candidate_binding['contract'],
	'unable to bind execution approval to exact candidate: ' . ( is_wp_error( $candidate_binding ) ? $candidate_binding->get_error_message() : wp_json_encode( $candidate_binding ) )
);
$approved = MAD4B_SCP_Approval_Tickets::approve( $ticket['ticket_id'] );
$check( is_array( $approved ) && 'approved' === $approved['status'], 'unable to approve execution ticket' );
$approval_ticket_id = $ticket['ticket_id'];
$call_input = $clean_input;
$call_input[ MAD4B_SCP_Staging_Write_Authority::APPROVAL_INPUT_KEY ] = $approval_ticket_id;
$call_payload = array( 'jsonrpc' => '2.0', 'id' => 3, 'method' => 'tools/call', 'params' => array( 'name' => 'media-update-metadata', 'arguments' => $call_input ) );

$provider_invocations = 0;
$nested_response = null;
$inside_nested = false;
$provider_probe = static function ( $check_value, $object_id, $meta_key, $meta_value, $prev_value ) use ( $attachment_id, &$provider_invocations, &$nested_response, &$inside_nested, $dispatch, $session_id, $call_payload ) {
	if ( (int) $object_id !== (int) $attachment_id || '_wp_attachment_image_alt' !== $meta_key ) return $check_value;
	++$provider_invocations;
	if ( ! $inside_nested ) {
		$inside_nested = true;
		$nested_response = $dispatch( $call_payload, $session_id );
		$inside_nested = false;
	}
	return $check_value;
};
add_filter( 'update_post_metadata', $provider_probe, 10, 5 );

$first = $dispatch( $call_payload, $session_id );
$check( $first instanceof WP_REST_Response && 200 === $first->get_status(), 'first tools/call transport failed' );
$first_json = wp_json_encode( $first->get_data(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
$check( false === strpos( $first_json, 'mad4b_execution_reentry_denied' ), 'outer tools/call was incorrectly denied as re-entry' );
$check( $nested_response instanceof WP_REST_Response, 'provider hook did not issue nested real MCP call' );
$nested_json = wp_json_encode( $nested_response->get_data(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
$check(
	is_string( $nested_json )
		&& false !== strpos( $nested_json, '"isError":true' )
		&& false !== strpos( $nested_json, 'already executing in this request' ),
	'nested same-request call was not fenced through serialized MCP error envelope: ' . $nested_json
);
$check( 1 === $provider_invocations, 'provider side effect ran more than once during nested same-request call' );

$tables = MAD4B_SCP_Schema::tables();
global $wpdb;
$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$tables['mutations']} WHERE approval_ticket_id = %s ORDER BY id ASC", $approval_ticket_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
$check( 1 === count( $rows ), 'execution ticket did not produce exactly one mutation record' );
$mutation = $rows[0];
$check( 'verified' === $mutation['status'] && ! empty( $mutation['mutation_id'] ), 'first real MCP mutation was not verified' );
$check( false !== strpos( $first_json, (string) $mutation['mutation_id'] ), 'real MCP success response omitted mutation_id' );
$used = MAD4B_SCP_Approval_Tickets::get( $approval_ticket_id );
$check( is_array( $used ) && 'used' === $used['status'], 'execution ticket did not terminalize as used: ' . wp_json_encode( $used ) );

// Same logical request + same exact operation must reuse the first completed result.
$completed_duplicate = $dispatch( array( 'jsonrpc' => '2.0', 'id' => 4, 'method' => 'tools/call', 'params' => array( 'name' => 'media-update-metadata', 'arguments' => $call_input ) ), $session_id );
$completed_json = wp_json_encode( $completed_duplicate->get_data(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
$check( false !== strpos( $completed_json, (string) $mutation['mutation_id'] ), 'completed same-request duplicate did not return cached verified result' );
$check( 1 === $provider_invocations, 'completed same-request duplicate re-ran provider side effect' );

// A genuinely independent logical request must hit canonical single-use replay denial.
$request_id = 'ci-mcp-fence-request-2';
$replay = $dispatch( array( 'jsonrpc' => '2.0', 'id' => 5, 'method' => 'tools/call', 'params' => array( 'name' => 'media-update-metadata', 'arguments' => $call_input ) ), $session_id );
$replay_json = wp_json_encode( $replay->get_data(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
$check( false !== strpos( $replay_json, 'replay' ) || false !== strpos( $replay_json, 'terminal or already claimed' ), 'independent tools/call did not hit replay denial: ' . $replay_json );
$check( 1 === $provider_invocations, 'independent replay reached provider' );

// The nested Media probe exists only to prove same-request re-entry fencing.
// Remove it before recovery so the restore write itself cannot recursively issue
// an unrelated stale Media tools/call under the new undo approval identity.
remove_filter( 'update_post_metadata', $provider_probe, 10 );

// Independent undo approval restores the exact original state.
$reflection = new ReflectionClass( 'MAD4B_SCP_Identity_Context' );
$property = $reflection->getProperty( 'request_approval_ticket_id' );
$property->setAccessible( true );
$property->setValue( null, '' );
$approval_ticket_id = '';
$request_id = 'ci-mcp-fence-undo-request';
$undo_input = array( 'mutation_id' => $mutation['mutation_id'], 'reason' => 'CI restores real MCP execution fence fixture' );
$undo_target = MAD4B_SCP_Authorization::target_fingerprint( 'mad4b/mutation-undo', 'core', $undo_input );
$undo_ticket = MAD4B_SCP_Approval_Tickets::create_pending( $agent['public_id'], 'mad4b-write', 'mad4b/mutation-undo', 'core', $undo_target, $undo_input, 'mutation', 'CI real MCP fence undo', 600 );
$check( is_array( $undo_ticket ) && 'pending' === $undo_ticket['status'], 'unable to create undo ticket' );
$undo_binding = MAD4B_SCP_Approval_Tickets::bind_ticket_to_current_candidate( $undo_ticket['ticket_id'] );
$check(
	is_array( $undo_binding ) && 'mad4b.approval-candidate-binding.v1' === $undo_binding['contract'],
	'unable to bind undo approval to exact candidate: ' . ( is_wp_error( $undo_binding ) ? $undo_binding->get_error_message() : wp_json_encode( $undo_binding ) )
);
$undo_approved = MAD4B_SCP_Approval_Tickets::approve( $undo_ticket['ticket_id'] );
$check( is_array( $undo_approved ) && 'approved' === $undo_approved['status'], 'unable to approve undo ticket' );
$approval_ticket_id = $undo_ticket['ticket_id'];
$undo_args = $undo_input;
$undo_args[ MAD4B_SCP_Staging_Write_Authority::APPROVAL_INPUT_KEY ] = $approval_ticket_id;
$undo = $dispatch( array( 'jsonrpc' => '2.0', 'id' => 6, 'method' => 'tools/call', 'params' => array( 'name' => 'mad4b-mutation-undo', 'arguments' => $undo_args ) ), $session_id );
$undo_json = wp_json_encode( $undo->get_data(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
$check( false !== strpos( $undo_json, 'undone' ), 'real MCP undo did not complete: ' . $undo_json );
$restored = $media_get->execute( array( 'attachment_id' => $attachment_id ) );
$check( ! is_wp_error( $restored ) && hash_equals( $before['sha256'], $restored['sha256'] ), 'undo did not restore exact initial Media SHA' );
$undo_used = MAD4B_SCP_Approval_Tickets::get( $approval_ticket_id );
$check( is_array( $undo_used ) && 'used' === $undo_used['status'], 'undo ticket did not terminalize as used: ' . wp_json_encode( $undo_used ) );

wp_delete_attachment( $attachment_id, true );
echo "mad4b.site-control-plane.mcp-governed-execution-fence.v1: PASS\n";
