<?php
if ( ! defined( 'ABSPATH' ) ) exit;

$fail = static function ( $message, $data = null ) {
	throw new RuntimeException( $message . ( null !== $data ? ' ' . wp_json_encode( $data, JSON_UNESCAPED_SLASHES ) : '' ) );
};
$network_base = getenv( 'MAD4B_PROJECTION_NETWORK_BASE' );
$network_host = getenv( 'MAD4B_PROJECTION_NETWORK_HOST' );
if ( ! is_string( $network_base ) || '' === trim( $network_base ) ) $fail( 'Authorized Breakglass proof requires independent HTTP mode.' );

$network = static function ( $bearer, $method, array $params, $session = '' ) use ( $network_base, $network_host, $fail ) {
	$headers = array(
		'Authorization' => 'Bearer ' . $bearer,
		'Accept' => 'application/json, text/event-stream',
		'Content-Type' => 'application/json',
		'MCP-Protocol-Version' => '2025-11-25',
	);
	if ( '' !== (string) $session ) $headers['Mcp-Session-Id'] = $session;
	if ( is_string( $network_host ) && '' !== $network_host ) $headers['Host'] = $network_host;
	$raw = wp_remote_request(
		rtrim( $network_base, '/' ) . '/wp-json/mcp/mad4b-chatgpt',
		array(
			'method' => 'POST',
			'headers' => $headers,
			'timeout' => 20,
			'redirection' => 0,
			'body' => wp_json_encode( array( 'jsonrpc' => '2.0', 'id' => wp_rand( 1000, 9999 ), 'method' => $method, 'params' => $params ) ),
		)
	);
	if ( is_wp_error( $raw ) ) $fail( 'Independent Breakglass HTTP request failed.', $raw->get_error_code() );
	$status = (int) wp_remote_retrieve_response_code( $raw );
	$body = json_decode( wp_remote_retrieve_body( $raw ), true );
	if ( ! is_array( $body ) ) $fail( 'Independent Breakglass HTTP response is not JSON.', array( 'status' => $status ) );
	return array( $status, $body, wp_remote_retrieve_headers( $raw ) );
};
$initialize = static function ( $bearer ) use ( $network, $fail ) {
	list( $status, $body, $headers ) = $network(
		$bearer,
		'initialize',
		array( 'protocolVersion' => '2025-11-25', 'clientInfo' => array( 'name' => 'mad4b-breakglass-ci', 'version' => '1.0.0' ) )
	);
	if ( 200 !== $status || ! isset( $body['result'] ) ) $fail( 'Breakglass initialize failed.', $body );
	foreach ( $headers as $name => $value ) {
		if ( 'mcp-session-id' !== strtolower( (string) $name ) ) continue;
		return is_array( $value ) ? (string) reset( $value ) : (string) $value;
	}
	$fail( 'Breakglass initialize session missing.' );
};
$list = static function ( $bearer, $session ) use ( $network, $fail ) {
	list( $status, $body ) = $network( $bearer, 'tools/list', array(), $session );
	if ( 200 !== $status || ! isset( $body['result']['tools'] ) || ! is_array( $body['result']['tools'] ) ) $fail( 'Breakglass tools/list failed.', $body );
	return array_values( array_filter( array_map( static function ( $tool ) {
		return is_array( $tool ) && isset( $tool['name'] ) ? (string) $tool['name'] : '';
	}, $body['result']['tools'] ) ) );
};
$call = static function ( $bearer, $session, array $arguments ) use ( $network ) {
	list( $status, $body ) = $network( $bearer, 'tools/call', array( 'name' => 'mad4b-database-raw-query', 'arguments' => $arguments ), $session );
	return array( $status, $body );
};
$denied = static function ( array $body ) {
	return isset( $body['error'] ) || ! empty( $body['result']['isError'] );
};

global $wpdb;
$tables = MAD4B_SCP_Schema::tables();
$admin_id = get_current_user_id();
if ( $admin_id < 1 || ! current_user_can( 'manage_options' ) ) $fail( 'Breakglass proof requires the disposable administrator.' );

$profile_before = get_option( MAD4B_SCP_Site_Profile::OPTION, false );
$gate_before = get_option( MAD4B_SCP_Governed_Runtime_Gates::OPTION, false );
$projection_before = get_option( MAD4B_SCP_ChatGPT_Tool_Projection::OPTION, false );
$created_user_id = 0;
$created_agent_id = 0;
$created_grant_id = 0;
$ticket_id = '';

try {
	$username = 'mad4b-breakglass-ci';
	$user = get_user_by( 'login', $username );
	if ( ! $user ) {
		$created_user_id = wp_create_user( $username, wp_generate_password( 40, true, true ), 'mad4b-breakglass-ci@example.invalid' );
		if ( is_wp_error( $created_user_id ) ) $fail( 'Cannot create isolated Breakglass OAuth subject.', $created_user_id->get_error_code() );
		$user = get_userdata( $created_user_id );
		$user->set_role( 'administrator' );
	}
	$breakglass_user_id = (int) $user->ID;

	$profile = MAD4B_SCP_Site_Profile::profile();
	$saved = MAD4B_SCP_Site_Profile::save_current_site( array(
		'expected_revision' => MAD4B_SCP_Site_Profile::revision(),
		'environment' => MAD4B_SCP_Site_Profile::current_environment(),
		'display_name' => isset( $profile['display_name'] ) ? (string) $profile['display_name'] : 'MAD4B Breakglass CI',
		'chatgpt_app_id' => isset( $profile['chatgpt_app_id'] ) ? (string) $profile['chatgpt_app_id'] : '',
		'oauth_user_ids' => array_values( array_unique( array_merge( MAD4B_SCP_Site_Profile::oauth_user_ids(), array( $admin_id, $breakglass_user_id ) ) ) ),
		'oauth_enabled' => true,
		'skills_enabled' => MAD4B_SCP_Site_Profile::skills_enabled(),
		'write_enabled' => true,
		'production_write_confirmed' => false,
		'provider_isolation_enabled' => MAD4B_SCP_Site_Profile::provider_isolation_enabled(),
		'managed_runtime_enabled' => MAD4B_SCP_Site_Profile::managed_runtime_enabled(),
		'acceptance_enabled' => MAD4B_SCP_Site_Profile::acceptance_enabled(),
	) );
	if ( is_wp_error( $saved ) ) $fail( 'Cannot enable disposable governed-write profile for Breakglass proof.', $saved->get_error_code() );
	if ( ! MAD4B_SCP_Site_Profile::governed_write_ready() ) $fail( 'Disposable governed-write profile did not become ready.' );

	$gate = array(
		'contract' => MAD4B_SCP_Governed_Runtime_Gates::CONTRACT,
		'version' => MAD4B_SCP_Governed_Runtime_Gates::VERSION,
		'revision' => 1,
		'site_uuid' => MAD4B_SCP_Site_Profile::site_uuid(),
		'profile_revision' => MAD4B_SCP_Site_Profile::revision(),
		'profile_digest' => MAD4B_SCP_Site_Profile::profile_digest(),
		'raw_sql_breakglass_enabled' => true,
		'raw_sql_write_enabled' => false,
		'raw_sql_ddl_enabled' => false,
		'production_mutation_enabled' => false,
		'production_auto_enable' => false,
		'updated_at' => gmdate( 'c' ),
	);
	update_option( MAD4B_SCP_Governed_Runtime_Gates::OPTION, $gate, false );
	wp_cache_delete( MAD4B_SCP_Governed_Runtime_Gates::OPTION, 'options' );
	if ( ! MAD4B_SCP_Governed_Runtime_Gates::raw_sql_breakglass_enabled() ) $fail( 'Disposable Breakglass runtime gate did not become effective.' );

	$issuer = MAD4B_SCP_Local_OAuth_Server::issuer();
	$subject_fingerprint = hash( 'sha256', 'oauth' . "\0" . $issuer . "\0" . 'user:' . $breakglass_user_id );
	$binding = MAD4B_SCP_Agent_Registry::subject_binding( 'oauth', $subject_fingerprint );
	if ( is_wp_error( $binding ) ) $fail( 'Breakglass subject lookup failed.', $binding->get_error_code() );
	if ( is_array( $binding ) ) {
		$agent = MAD4B_SCP_Agent_Registry::get_agent_by_id( (int) $binding['agent_id'] );
	} else {
		$agent = MAD4B_SCP_Agent_Registry::create_agent( array(
			'slug' => 'breakglass-http-ci-' . $breakglass_user_id,
			'label' => 'Breakglass HTTP CI',
			'status' => 'enabled',
			'environment' => 'staging',
			'wp_user_id' => $breakglass_user_id,
		) );
		if ( is_wp_error( $agent ) || ! is_array( $agent ) ) $fail( 'Breakglass CI agent creation failed.', is_wp_error( $agent ) ? $agent->get_error_code() : $agent );
		$created_agent_id = (int) $agent['id'];
		$bound = MAD4B_SCP_Agent_Registry::bind_subject( $agent['public_id'], 'oauth', $subject_fingerprint, 'Breakglass HTTP CI' );
		if ( is_wp_error( $bound ) || true !== $bound ) $fail( 'Breakglass OAuth subject binding failed.', is_wp_error( $bound ) ? $bound->get_error_code() : $bound );
	}
	if ( ! is_array( $agent ) || 'enabled' !== (string) $agent['status'] ) $fail( 'Breakglass CI agent is unavailable.' );

	$grant = MAD4B_SCP_Agent_Registry::exact_grant( (int) $agent['id'], 'mad4b-breakglass', 'mad4b/database-raw-query', 'core' );
	if ( is_wp_error( $grant ) ) {
		$allow_grant = static function () { return true; };
		add_filter( 'mad4b_scp_allow_breakglass_grant_creation', $allow_grant, PHP_INT_MAX );
		try {
			$created = MAD4B_SCP_Agent_Registry::grant_ability( $agent['public_id'], 'mad4b-breakglass', 'mad4b/database-raw-query', 'core', array(), 'allow', 'staging' );
		} finally {
			remove_filter( 'mad4b_scp_allow_breakglass_grant_creation', $allow_grant, PHP_INT_MAX );
		}
		if ( is_wp_error( $created ) || true !== $created ) $fail( 'Exact Breakglass grant creation failed.', is_wp_error( $created ) ? $created->get_error_code() : $created );
		$grant = MAD4B_SCP_Agent_Registry::exact_grant( (int) $agent['id'], 'mad4b-breakglass', 'mad4b/database-raw-query', 'core' );
		if ( is_wp_error( $grant ) ) $fail( 'Exact Breakglass grant is still unavailable.', $grant->get_error_code() );
		$created_grant_id = (int) $grant['id'];
	}

	$row = MAD4B_SCP_ChatGPT_Tool_Projection::describe_ability( 'mad4b/database-raw-query' );
	if (
		is_wp_error( $row )
		|| 'breakglass' !== ( $row['lane'] ?? '' )
		|| empty( $row['breakglass'] )
		|| empty( $row['projection_eligible'] )
		|| empty( $row['execution_eligible'] )
		|| 'core' !== ( $row['execution_provider'] ?? '' )
	) {
		$fail( 'Core Raw SQL Ability did not classify on the exact Breakglass lane.', is_wp_error( $row ) ? $row->get_error_code() : $row );
	}
	update_option( MAD4B_SCP_ChatGPT_Tool_Projection::OPTION, array(
		'contract' => MAD4B_SCP_ChatGPT_Tool_Projection::CONTRACT,
		'revision' => 700,
		'binding' => MAD4B_SCP_ChatGPT_Tool_Projection::current_binding(),
		'abilities' => array( 'mad4b/database-raw-query' => $row ),
		'updated_at' => gmdate( 'c' ),
	), false );
	wp_cache_delete( MAD4B_SCP_ChatGPT_Tool_Projection::OPTION, 'options' );

	$clean_input = array(
		'sql' => 'SELECT 1 AS ok LIMIT 1',
		'max_rows' => 1,
		'reason' => 'Independent HTTP authorized Breakglass proof',
	);
	$target = MAD4B_SCP_Authorization::target_fingerprint( 'mad4b/database-raw-query', 'core', $clean_input, $agent, array() );
	if ( ! is_string( $target ) || '' === $target ) $fail( 'Breakglass target fingerprint is unavailable.' );
	$ticket = MAD4B_SCP_Approval_Tickets::create_pending(
		$agent['public_id'], 'mad4b-breakglass', 'mad4b/database-raw-query', 'core',
		$target, $clean_input, 'breakglass', 'Independent HTTP Breakglass proof', 300
	);
	if ( is_wp_error( $ticket ) || ! is_array( $ticket ) ) $fail( 'Breakglass approval ticket creation failed.', is_wp_error( $ticket ) ? $ticket->get_error_code() : $ticket );
	$ticket_id = (string) $ticket['ticket_id'];
	$approved = MAD4B_SCP_Approval_Tickets::approve( $ticket_id );
	if ( is_wp_error( $approved ) || 'approved' !== (string) ( $approved['status'] ?? '' ) ) $fail( 'Breakglass approval ticket was not approved.', is_wp_error( $approved ) ? $approved->get_error_code() : $approved );

	$mint = new ReflectionMethod( 'MAD4B_SCP_Local_OAuth_Server', 'mint_access_token' );
	$mint->setAccessible( true );
	$resource = MAD4B_SCP_Local_OAuth_Server::resource_identifier();
	$read = $mint->invoke( null, MAD4B_SCP_Local_OAuth_Server::CHATGPT_CIMD_CLIENT_ID, $breakglass_user_id, $resource, array( 'mad4b:read' ) );
	$step = $mint->invoke( null, MAD4B_SCP_Local_OAuth_Server::CHATGPT_CIMD_CLIENT_ID, $breakglass_user_id, $resource, array( 'mad4b:read', MAD4B_SCP_OAuth_Resource_Bridge::AUTHORITY_STEP_UP_SCOPE ) );
	$authorized = $mint->invoke( null, MAD4B_SCP_Local_OAuth_Server::CHATGPT_CIMD_CLIENT_ID, $breakglass_user_id, $resource, array( 'mad4b:read', MAD4B_SCP_OAuth_Resource_Bridge::AUTHORITY_STEP_UP_SCOPE, 'server:mad4b-breakglass' ) );
	if ( is_wp_error( $read ) || is_wp_error( $step ) || is_wp_error( $authorized ) ) $fail( 'Cannot mint Breakglass proof tokens.' );

	wp_set_current_user( 0 );
	$read_session = $initialize( $read );
	$step_session = $initialize( $step );
	$authorized_session = $initialize( $authorized );

	if ( in_array( 'mad4b-database-raw-query', $list( $read, $read_session ), true ) ) $fail( 'Read bearer could see projected Breakglass.' );
	if ( in_array( 'mad4b-database-raw-query', $list( $step, $step_session ), true ) ) $fail( 'Step-up bearer without exact Breakglass scope could see projected Breakglass.' );
	if ( ! in_array( 'mad4b-database-raw-query', $list( $authorized, $authorized_session ), true ) ) $fail( 'Authorized Breakglass bearer could not see projected Breakglass.' );

	list( $denied_status, $denied_body ) = $call( $step, $step_session, $clean_input );
	if ( $denied_status >= 500 || ! $denied( $denied_body ) ) $fail( 'Breakglass call without exact OAuth scope was not denied.', $denied_body );

	$arguments = $clean_input;
	$arguments[ MAD4B_SCP_Staging_Write_Authority::APPROVAL_INPUT_KEY ] = $ticket_id;
	list( $ok_status, $ok_body ) = $call( $authorized, $authorized_session, $arguments );
	if ( 200 !== $ok_status || $denied( $ok_body ) ) $fail( 'Exact authorized Breakglass call failed.', $ok_body );
	$encoded = wp_json_encode( $ok_body, JSON_UNESCAPED_SLASHES );
	if ( false === strpos( (string) $encoded, '"verb":"SELECT"' ) || false === strpos( (string) $encoded, '"ok"' ) ) $fail( 'Authorized Breakglass result did not contain the bounded SELECT evidence.', $ok_body );

	list( $replay_status, $replay_body ) = $call( $authorized, $authorized_session, $arguments );
	if ( $replay_status >= 500 || ! $denied( $replay_body ) ) $fail( 'One-time Breakglass approval replay was accepted.', $replay_body );

	fwrite( STDOUT, "mad4b.breakglass-independent-http.v1: PASS exact_surface exact_scope exact_grant one_time_approval\n" );
} finally {
	wp_set_current_user( $admin_id );
	if ( false === $projection_before ) delete_option( MAD4B_SCP_ChatGPT_Tool_Projection::OPTION );
	else update_option( MAD4B_SCP_ChatGPT_Tool_Projection::OPTION, $projection_before, false );
	if ( false === $gate_before ) delete_option( MAD4B_SCP_Governed_Runtime_Gates::OPTION );
	else update_option( MAD4B_SCP_Governed_Runtime_Gates::OPTION, $gate_before, false );
	if ( false === $profile_before ) delete_option( MAD4B_SCP_Site_Profile::OPTION );
	else update_option( MAD4B_SCP_Site_Profile::OPTION, $profile_before, false );
	MAD4B_SCP_Site_Profile::reset_cache();

	if ( '' !== $ticket_id ) $wpdb->delete( $tables['approvals'], array( 'ticket_id' => $ticket_id ), array( '%s' ) );
	if ( $created_grant_id > 0 ) $wpdb->delete( $tables['grants'], array( 'id' => $created_grant_id ), array( '%d' ) );
	if ( $created_agent_id > 0 ) {
		$wpdb->delete( $tables['budget_windows'], array( 'agent_id' => $created_agent_id ), array( '%d' ) );
		$wpdb->delete( $tables['budgets'], array( 'agent_id' => $created_agent_id ), array( '%d' ) );
		$wpdb->delete( $tables['subjects'], array( 'agent_id' => $created_agent_id ), array( '%d' ) );
		$wpdb->delete( $tables['grants'], array( 'agent_id' => $created_agent_id ), array( '%d' ) );
		$wpdb->delete( $tables['agents'], array( 'id' => $created_agent_id ), array( '%d' ) );
	}
	if ( $created_user_id > 0 ) {
		require_once ABSPATH . 'wp-admin/includes/user.php';
		wp_delete_user( $created_user_id );
	}
}
