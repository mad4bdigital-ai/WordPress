<?php
/**
 * Disposable runtime proof for transactional NHI budget enforcement.
 * Runs only in CI after the governance visibility smoke.
 */

if ( ! defined( 'ABSPATH' ) ) {
	throw new RuntimeException( 'WordPress is not loaded.' );
}

$check = static function ( $condition, $message ) {
	if ( ! $condition ) throw new RuntimeException( $message );
};

$check( class_exists( 'MAD4B_SCP_Budgets' ), 'Budget service is unavailable.' );
$check( class_exists( 'MAD4B_SCP_Authorization' ), 'Central authorization is unavailable.' );
$check( class_exists( 'MAD4B_SCP_Approval_Tickets' ), 'Approval service is unavailable.' );
$check( MAD4B_SCP_Schema::is_ready(), 'Governance schema is unavailable.' );

$subject_type = 'ci-budget';
$subject_identifier = 'mad4b-runtime-budget-agent';
$subject_fingerprint = hash( 'sha256', $subject_type . "\0" . $subject_identifier );
$agent = MAD4B_SCP_Agent_Registry::create_agent(
	array(
		'slug' => 'ci-runtime-budget-agent',
		'label' => 'CI Runtime Budget Agent',
		'status' => 'enabled',
		'environment' => 'all',
		'wp_user_id' => get_current_user_id(),
	)
);
$check( is_array( $agent ) && ! empty( $agent['public_id'] ) && ! empty( $agent['id'] ), 'Unable to create disposable budget-test NHI.' );
$bound = MAD4B_SCP_Agent_Registry::bind_subject( $agent['public_id'], $subject_type, $subject_fingerprint, 'CI runtime budget subject' );
$check( true === $bound, 'Unable to bind disposable budget-test subject.' );
$grant = MAD4B_SCP_Agent_Registry::grant_ability( $agent['public_id'], 'mad4b-admin', 'mad4b/mutation-undo', 'core', array(), 'allow', 'all' );
$check( true === $grant, 'Unable to create exact undo grant for budget runtime proof.' );

$budget = MAD4B_SCP_Budgets::set_budget( $agent['public_id'], 'requests', 60, 1, true );
$check( is_array( $budget ) && 'requests' === $budget['budget_type'] && 1 === (int) $budget['max_count'], 'Unable to configure one-request runtime budget.' );

$approval_ticket_id = '';
add_filter(
	'mad4b_scp_authenticated_subject_context',
	static function ( $context ) use ( $subject_type, $subject_identifier, &$approval_ticket_id ) {
		return array(
			'authenticated' => true,
			'subject_type' => $subject_type,
			'subject_identifier' => $subject_identifier,
			'token_scopes' => array( 'ability:mad4b/mutation-undo' ),
			'approval_ticket_id' => $approval_ticket_id,
			'auth_method' => 'ci',
			'wp_user_id' => get_current_user_id(),
			'request_id' => 'ci-runtime-budget-request',
			'origin' => 'ci',
		);
	},
	999
);

if ( ! defined( 'MAD4B_MCP_MUTATION_ENABLED' ) ) define( 'MAD4B_MCP_MUTATION_ENABLED', true );

$tables = MAD4B_SCP_Schema::tables();
$agent_id = (int) $agent['id'];
$read_windows = static function () use ( $tables, $agent_id ) {
	global $wpdb;
	$rows = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT id,budget_type,window_start,window_seconds,used_count FROM {$tables['budget_windows']} WHERE agent_id = %d AND budget_type = %s ORDER BY window_start ASC",
			$agent_id,
			'requests'
		),
		ARRAY_A
	); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
	return is_array( $rows ) ? $rows : array();
};

$input = array(
	'mutation_id' => wp_generate_uuid4(),
	'reason' => 'CI budget authorization proof',
);

// 1. A missing approval must roll back the provisional budget reservation.
$missing_approval = MAD4B_SCP_Authorization::authorize_mutation( 'mad4b/mutation-undo', 'mad4b-admin', 'core', $input );
$check( is_wp_error( $missing_approval ) && 'mad4b_approval_required' === $missing_approval->get_error_code(), 'Missing approval did not fail after provisional budget reservation.' );
$windows_after_missing = $read_windows();
$used_after_missing = 0;
foreach ( $windows_after_missing as $window ) $used_after_missing += (int) $window['used_count'];
$check( 0 === $used_after_missing, 'Missing approval consumed budget despite transactional rollback.' );

// 2. Exact approved ticket + available budget must authorize, commit one unit, and consume the ticket.
$ticket_one = MAD4B_SCP_Approval_Tickets::create_pending(
	$agent['public_id'], 'mad4b-admin', 'mad4b/mutation-undo', 'core', '', $input, 'mutation', 'CI budget commit approval', 600
);
$check( is_array( $ticket_one ) && 'pending' === $ticket_one['status'], 'Unable to create first budget approval ticket.' );
$approved_one = MAD4B_SCP_Approval_Tickets::approve( $ticket_one['ticket_id'] );
$check( is_array( $approved_one ) && 'approved' === $approved_one['status'], 'Unable to approve first budget ticket.' );
$approval_ticket_id = $ticket_one['ticket_id'];
$allowed = MAD4B_SCP_Authorization::authorize_mutation( 'mad4b/mutation-undo', 'mad4b-admin', 'core', $input );
$check( is_array( $allowed ) && ! empty( $allowed['allowed'] ), 'Available budget + exact approval did not authorize.' );
$check( ! empty( $allowed['budget']['configured'] ), 'Authorization did not report configured budget evidence.' );
$windows_after_allowed = $read_windows();
$check( 1 === count( $windows_after_allowed ) && 1 === (int) $windows_after_allowed[0]['used_count'], 'Successful authorization did not commit exactly one request budget unit.' );
$ticket_one_after = MAD4B_SCP_Approval_Tickets::get( $ticket_one['ticket_id'] );
$check( is_array( $ticket_one_after ) && 'used' === $ticket_one_after['status'], 'Successful budgeted authorization did not consume the exact approval ticket.' );

// 3. Exhaustion must happen before approval consumption; a fresh approved ticket remains reusable for a future window.
$ticket_two = MAD4B_SCP_Approval_Tickets::create_pending(
	$agent['public_id'], 'mad4b-admin', 'mad4b/mutation-undo', 'core', '', $input, 'mutation', 'CI budget exhaustion approval', 600
);
$check( is_array( $ticket_two ), 'Unable to create exhaustion-test approval ticket.' );
$approved_two = MAD4B_SCP_Approval_Tickets::approve( $ticket_two['ticket_id'] );
$check( is_array( $approved_two ) && 'approved' === $approved_two['status'], 'Unable to approve exhaustion-test ticket.' );
$approval_ticket_id = $ticket_two['ticket_id'];
$exhausted = MAD4B_SCP_Authorization::authorize_mutation( 'mad4b/mutation-undo', 'mad4b-admin', 'core', $input );
$check( is_wp_error( $exhausted ) && 'mad4b_budget_exhausted' === $exhausted->get_error_code(), 'Second request was not denied by the exhausted budget.' );
$ticket_two_after = MAD4B_SCP_Approval_Tickets::get( $ticket_two['ticket_id'] );
$check( is_array( $ticket_two_after ) && 'approved' === $ticket_two_after['status'], 'Budget exhaustion consumed the approval ticket before execution could be authorized.' );
$windows_after_exhausted = $read_windows();
$check( 1 === count( $windows_after_exhausted ) && 1 === (int) $windows_after_exhausted[0]['used_count'], 'Budget exhaustion mutated the committed counter.' );

// 4. Move the committed window into the immediately previous bucket and prove a fresh current window is created.
global $wpdb;
$old_window_start = (int) $windows_after_exhausted[0]['window_start'];
$previous_window_start = max( 0, $old_window_start - 60 );
$moved = $wpdb->query(
	$wpdb->prepare(
		"UPDATE {$tables['budget_windows']} SET window_start = %d WHERE id = %d AND window_start = %d",
		$previous_window_start,
		(int) $windows_after_exhausted[0]['id'],
		$old_window_start
	)
); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
$check( 1 === (int) $moved, 'Unable to prepare previous-window state for rollover proof.' );

$rollover = MAD4B_SCP_Authorization::authorize_mutation( 'mad4b/mutation-undo', 'mad4b-admin', 'core', $input );
$check( is_array( $rollover ) && ! empty( $rollover['allowed'] ), 'Approved ticket did not become usable in a fresh budget window.' );
$ticket_two_rollover = MAD4B_SCP_Approval_Tickets::get( $ticket_two['ticket_id'] );
$check( is_array( $ticket_two_rollover ) && 'used' === $ticket_two_rollover['status'], 'Rollover authorization did not consume the previously preserved approval ticket.' );
$windows_after_rollover = $read_windows();
$check( count( $windows_after_rollover ) >= 2, 'Budget rollover did not preserve the prior window and create a fresh current window.' );
$latest_window = end( $windows_after_rollover );
$check( (int) $latest_window['window_start'] > $previous_window_start && 1 === (int) $latest_window['used_count'], 'Fresh budget window did not start with the exact committed usage.' );

echo "mad4b.site-control-plane.runtime-budget.v1: PASS\n";
