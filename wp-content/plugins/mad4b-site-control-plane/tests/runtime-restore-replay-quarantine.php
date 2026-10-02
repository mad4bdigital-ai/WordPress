<?php
if ( ! defined( 'ABSPATH' ) ) throw new RuntimeException( 'WordPress is not loaded.' );

$fail = static function ( $message, $data = null ) {
	throw new RuntimeException( $message . ( null !== $data ? ' ' . wp_json_encode( $data, JSON_UNESCAPED_SLASHES ) : '' ) );
};
$code = static function ( $value ) { return is_wp_error( $value ) ? $value->get_error_code() : ''; };

foreach ( array( 'MAD4B_SCP_Restore_Epoch', 'MAD4B_SCP_Durable_Execution', 'MAD4B_SCP_Approval_Tickets', 'MAD4B_SCP_Site_Profile', 'MAD4B_SCP_Schema' ) as $class ) {
	if ( ! class_exists( $class ) ) $fail( 'Required restore replay class is unavailable.', $class );
}

global $wpdb;
$tables = MAD4B_SCP_Schema::tables();
$profile_key = MAD4B_SCP_Site_Profile::OPTION;
$epoch_key = MAD4B_SCP_Restore_Epoch::OPTION;
$projection_key = class_exists( 'MAD4B_SCP_ChatGPT_Tool_Projection' ) ? MAD4B_SCP_ChatGPT_Tool_Projection::OPTION : 'mad4b_scp_chatgpt_tool_projection_v1';

$profile_before = get_option( $profile_key, null );
$epoch_before = get_option( $epoch_key, null );
$projection_before = get_option( $projection_key, null );
$external_path = MAD4B_SCP_Restore_Epoch::external_path_for_test();
if ( is_wp_error( $external_path ) ) $fail( 'Restore epoch external path is unavailable.', $external_path->get_error_code() );
$external_before_exists = is_file( $external_path );
$external_before = $external_before_exists ? file_get_contents( $external_path ) : null;

$ticket_id = wp_generate_uuid4();
$scope_key = '';
$idempotency_key = '';
$work_id = '';
$cleanup = static function () use ( $wpdb, $tables, $profile_key, $epoch_key, $projection_key, $profile_before, $epoch_before, $projection_before, $external_path, $external_before_exists, $external_before, &$ticket_id, &$scope_key, &$idempotency_key, &$work_id ) {
	if ( $ticket_id ) $wpdb->delete( $tables['approvals'], array( 'ticket_id' => $ticket_id ), array( '%s' ) );
	if ( $scope_key && $idempotency_key ) $wpdb->delete( $tables['idempotency'], array( 'scope_key' => $scope_key, 'idempotency_key' => $idempotency_key ), array( '%s', '%s' ) );
	if ( $work_id ) $wpdb->delete( $tables['work_leases'], array( 'work_id' => $work_id ), array( '%s' ) );
	if ( null === $profile_before ) delete_option( $profile_key ); else update_option( $profile_key, $profile_before, false );
	if ( null === $epoch_before ) delete_option( $epoch_key ); else update_option( $epoch_key, $epoch_before, false );
	if ( null === $projection_before ) delete_option( $projection_key ); else update_option( $projection_key, $projection_before, false );
	if ( $external_before_exists ) {
		@mkdir( dirname( $external_path ), 0700, true );
		file_put_contents( $external_path, (string) $external_before );
	} else {
		@unlink( $external_path );
	}
	MAD4B_SCP_Site_Profile::reset_cache();
	MAD4B_SCP_Restore_Epoch::reset_request_cache();
};

try {
	$origin = MAD4B_SCP_Site_Profile::current_origin();
	$environment = MAD4B_SCP_Site_Profile::current_environment();
	$site_uuid = wp_generate_uuid4();
	$now_iso = gmdate( 'c' );
	$profile = array(
		'contract' => MAD4B_SCP_Site_Profile::CONTRACT,
		'version' => MAD4B_SCP_Site_Profile::VERSION,
		'site_uuid' => $site_uuid,
		'revision' => 1,
		'environment' => $environment,
		'canonical_origin' => $origin,
		'display_name' => 'Restore Replay CI',
		'chatgpt_app_id' => '',
		'oauth_user_ids' => array( get_current_user_id() ),
		'related_origins' => array( $environment => $origin ),
		'features' => array(
			'oauth' => false, 'skills' => false, 'write' => false,
			'production_write_confirmed' => false, 'provider_isolation' => false,
			'managed_runtime' => false, 'acceptance' => false,
		),
		'legacy_agent_slug' => '',
		'legacy_zero_touch' => false,
		'created_at' => $now_iso,
		'updated_at' => $now_iso,
	);
	update_option( $profile_key, $profile, false );
	MAD4B_SCP_Site_Profile::reset_cache();
	if ( ! MAD4B_SCP_Site_Profile::origin_enrolled() ) $fail( 'Disposable restore profile is not exactly enrolled.', MAD4B_SCP_Site_Profile::status() );

	delete_option( $epoch_key );
	@unlink( $external_path );
	MAD4B_SCP_Restore_Epoch::reset_request_cache();
	$epoch1 = MAD4B_SCP_Restore_Epoch::ensure_bound();
	if ( is_wp_error( $epoch1 ) || 1 !== (int) $epoch1['epoch'] ) $fail( 'Initial restore epoch did not bind.', $epoch1 );
	$stale_binding = get_option( $epoch_key, array() );

	$scope_key = MAD4B_SCP_Durable_Execution::scope_key( $site_uuid, 'restore-ci', 'replay', 'target-1' );
	$idempotency_key = 'restore-ci-' . wp_generate_uuid4();
	$request_sha = hash( 'sha256', 'restore-ci:' . $idempotency_key );
	$claim = MAD4B_SCP_Durable_Execution::begin_idempotency( $scope_key, $idempotency_key, $request_sha, 3600 );
	if ( is_wp_error( $claim ) || empty( $claim['claimed'] ) ) $fail( 'Could not create idempotency snapshot fixture.', $claim );
	$result = array( 'provider_execution_ref' => 'restore-ci-exec', 'state' => 'completed-before-snapshot' );
	$complete = MAD4B_SCP_Durable_Execution::complete_idempotency( $claim, $result );
	if ( is_wp_error( $complete ) ) $fail( 'Could not complete idempotency snapshot fixture.', $complete );
	$stale_idempotency = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$tables['idempotency']} WHERE scope_key=%s AND idempotency_key=%s", $scope_key, $idempotency_key ), ARRAY_A );
	if ( ! is_array( $stale_idempotency ) || 'completed' !== (string) $stale_idempotency['status'] ) $fail( 'Completed idempotency snapshot row is missing.' );

	$ticket_id = wp_generate_uuid4();
	$inserted = $wpdb->insert( $tables['approvals'], array(
		'ticket_id' => $ticket_id,
		'ticket_class' => 'mutation',
		'agent_id' => 999999,
		'server_id' => 'mad4b-write',
		'ability_name' => 'mad4b-ci/restore-replay',
		'payload_sha256' => hash( 'sha256', 'restore-approval:' . $ticket_id ),
		'status' => 'approved',
		'reason' => 'restore replay fixture',
		'expires_at' => gmdate( 'Y-m-d H:i:s', time() + 3600 ),
		'created_at' => gmdate( 'Y-m-d H:i:s' ),
	), array( '%s','%s','%d','%s','%s','%s','%s','%s','%s','%s' ) );
	if ( 1 !== (int) $inserted ) $fail( 'Could not insert approval snapshot fixture.', $wpdb->last_error );
	$stale_approval = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$tables['approvals']} WHERE ticket_id=%s", $ticket_id ), ARRAY_A );

	$work_id = wp_generate_uuid4();
	$lease = MAD4B_SCP_Durable_Execution::acquire_lease( $work_id, 'restore-ci', 'aggregate-1', 'worker-restore-ci', 1, 300 );
	if ( is_wp_error( $lease ) ) $fail( 'Could not create lease snapshot fixture.', $lease );
	$stale_lease = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$tables['work_leases']} WHERE work_id=%s", $work_id ), ARRAY_A );

	$stale_projection = array(
		'contract' => 'mad4b.chatgpt-tool-projection.v1',
		'revision' => 77,
		'binding' => array( 'site_uuid' => $site_uuid, 'environment' => $environment, 'origin' => $origin ),
		'abilities' => array( 'mad4b-ci/restore-replay' => array( 'ability_name' => 'mad4b-ci/restore-replay' ) ),
	);
	update_option( $projection_key, $stale_projection, false );

	$epoch2 = MAD4B_SCP_Restore_Epoch::advance( 'restore_replay_ci' );
	if ( is_wp_error( $epoch2 ) || 2 !== (int) $epoch2['epoch'] ) $fail( 'Restore epoch did not advance after snapshot.', $epoch2 );

	// Model later authoritative state, then restore the old DB snapshot while
	// intentionally leaving the external epoch at epoch 2.
	$wpdb->update( $tables['approvals'], array( 'status' => 'revoked' ), array( 'ticket_id' => $ticket_id ), array( '%s' ), array( '%s' ) );
	$wpdb->update( $tables['idempotency'], array( 'status' => 'pending', 'result_json' => null, 'result_sha256' => '' ), array( 'scope_key' => $scope_key, 'idempotency_key' => $idempotency_key ), array( '%s','%s','%s' ), array( '%s','%s' ) );
	$wpdb->update( $tables['work_leases'], array( 'status' => 'completed' ), array( 'work_id' => $work_id ), array( '%s' ), array( '%s' ) );
	update_option( $projection_key, array( 'contract' => 'later-state', 'revision' => 78 ), false );

	update_option( $epoch_key, $stale_binding, false );
	$wpdb->replace( $tables['approvals'], $stale_approval );
	$wpdb->replace( $tables['idempotency'], $stale_idempotency );
	$wpdb->replace( $tables['work_leases'], $stale_lease );
	update_option( $projection_key, $stale_projection, false );
	MAD4B_SCP_Restore_Epoch::reset_request_cache();

	$quarantined = MAD4B_SCP_Restore_Epoch::status( false, true );
	if ( ! empty( $quarantined['ready'] ) || ! in_array( 'restore_epoch_database_snapshot_detected', $quarantined['blockers'], true ) ) {
		$fail( 'Restored snapshot was not quarantined.', $quarantined );
	}

	$durable_replay = MAD4B_SCP_Durable_Execution::begin_idempotency( $scope_key, $idempotency_key, $request_sha, 3600 );
	if ( 'mad4b_durable_restore_epoch_quarantined' !== $code( $durable_replay ) ) {
		$fail( 'Completed idempotency replay escaped restore quarantine.', is_wp_error( $durable_replay ) ? $durable_replay->get_error_data() : $durable_replay );
	}

	$approval_replay = MAD4B_SCP_Approval_Tickets::claim_exact(
		$ticket_id, array(), 'mad4b-write', 'mad4b-ci/restore-replay', 'core', 'target', array(), 'mutation'
	);
	if ( 'mad4b_restore_epoch_not_ready' !== $code( $approval_replay ) ) {
		$fail( 'Restored approved ticket escaped restore quarantine.', is_wp_error( $approval_replay ) ? $approval_replay->get_error_data() : $approval_replay );
	}

	$foreign_profile = $profile;
	$foreign_profile['site_uuid'] = wp_generate_uuid4();
	update_option( $profile_key, $foreign_profile, false );
	MAD4B_SCP_Site_Profile::reset_cache();
	MAD4B_SCP_Restore_Epoch::reset_request_cache();
	$foreign = MAD4B_SCP_Restore_Epoch::status( false, true );
	if ( ! in_array( 'restore_epoch_site_identity_mismatch', $foreign['blockers'] ?? array(), true ) ) $fail( 'Copied external epoch was accepted by a foreign Site UUID.', $foreign );

	update_option( $profile_key, $profile, false );
	MAD4B_SCP_Site_Profile::reset_cache();
	MAD4B_SCP_Restore_Epoch::reset_request_cache();
	$external_record = json_decode( (string) file_get_contents( $external_path ), true );
	if ( ! is_array( $external_record ) || empty( $external_record['external_record_sha256'] ) ) $fail( 'External restore epoch record is unreadable.' );

	$ack = MAD4B_SCP_Restore_Epoch::acknowledge_restore_after_quarantine( $external_record['external_record_sha256'] );
	if ( is_wp_error( $ack ) || empty( $ack['ready'] ) || 2 !== (int) $ack['epoch'] ) $fail( 'Governed restore reconciliation did not rebind exact external epoch.', $ack );

	$approval_after = $wpdb->get_row( $wpdb->prepare( "SELECT status FROM {$tables['approvals']} WHERE ticket_id=%s", $ticket_id ), ARRAY_A );
	if ( ! is_array( $approval_after ) || 'revoked' !== (string) $approval_after['status'] ) $fail( 'Restore reconciliation did not revoke restored approval.', $approval_after );

	$id_after = $wpdb->get_row( $wpdb->prepare( "SELECT status,expires_at,reconciliation_ref FROM {$tables['idempotency']} WHERE scope_key=%s AND idempotency_key=%s", $scope_key, $idempotency_key ), ARRAY_A );
	if ( ! is_array( $id_after ) || 'pending' !== (string) $id_after['status'] || 0 !== strpos( (string) $id_after['reconciliation_ref'], 'restore_epoch:' ) || strtotime( $id_after['expires_at'] . ' UTC' ) > time() ) {
		$fail( 'Restore reconciliation did not quarantine completed idempotency result.', $id_after );
	}
	$replay_after_ack = MAD4B_SCP_Durable_Execution::begin_idempotency( $scope_key, $idempotency_key, $request_sha, 3600 );
	if ( 'mad4b_idempotency_reconciliation_required' !== $code( $replay_after_ack ) ) $fail( 'Restore acknowledgement resurrected completed idempotency replay.', $replay_after_ack );

	$lease_after = $wpdb->get_row( $wpdb->prepare( "SELECT status,reconciliation_ref FROM {$tables['work_leases']} WHERE work_id=%s", $work_id ), ARRAY_A );
	if ( ! is_array( $lease_after ) || 'failed' !== (string) $lease_after['status'] || 0 !== strpos( (string) $lease_after['reconciliation_ref'], 'restore_epoch:' ) ) {
		$fail( 'Restore reconciliation did not quarantine active lease.', $lease_after );
	}
	if ( false !== get_option( $projection_key, false ) ) $fail( 'Restore reconciliation retained stale dynamic projection state.' );

	echo "mad4b.restore-replay-quarantine.real-db.v1: PASS
";
} finally {
	$cleanup();
}
