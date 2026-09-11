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

$reset_request_ticket_overlay = static function () {
	$reflection = new ReflectionClass( 'MAD4B_SCP_Identity_Context' );
	$property = $reflection->getProperty( 'request_approval_ticket_id' );
	$property->setAccessible( true );
	$property->setValue( null, '' );
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
$target = MAD4B_SCP_Authorization::target_fingerprint( 'mad4b/mutation-undo', 'core', $input );
$check( is_string( $target ) && preg_match( '/^[a-f0-9]{64}$/', $target ), 'Unable to resolve deterministic budget-test target fingerprint.' );

// 1. Permission preflight with a missing approval must fail without reserving budget.
$missing_approval = MAD4B_SCP_Authorization::authorize_mutation( 'mad4b/mutation-undo', 'mad4b-admin', 'core', $input );
$check( is_wp_error( $missing_approval ) && 'mad4b_approval_required' === $missing_approval->get_error_code(), 'Missing approval did not fail in read-only permission preflight.' );
$windows_after_missing = $read_windows();
$used_after_missing = 0;
foreach ( $windows_after_missing as $window ) $used_after_missing += (int) $window['used_count'];
$check( 0 === $used_after_missing, 'Missing approval preflight consumed budget.' );

// 2. Exact approved ticket remains untouched by preflight; execution claim then reserves budget and moves the ticket to executing.
$ticket_one = MAD4B_SCP_Approval_Tickets::create_pending(
	$agent['public_id'], 'mad4b-admin', 'mad4b/mutation-undo', 'core', $target, $input, 'mutation', 'CI budget commit approval', 600
);
$check( is_array( $ticket_one ) && 'pending' === $ticket_one['status'], 'Unable to create first budget approval ticket.' );
$approved_one = MAD4B_SCP_Approval_Tickets::approve( $ticket_one['ticket_id'] );
$check( is_array( $approved_one ) && 'approved' === $approved_one['status'], 'Unable to approve first budget ticket.' );
$approval_ticket_id = $ticket_one['ticket_id'];
$preflight_one = MAD4B_SCP_Authorization::authorize_mutation( 'mad4b/mutation-undo', 'mad4b-admin', 'core', $input );
$check( is_array( $preflight_one ) && ! empty( $preflight_one['allowed'] ), 'Available budget + exact approval did not pass permission preflight.' );
$check( empty( $preflight_one['execution_side_effects'] ), 'Permission preflight incorrectly reported execution side effects.' );
$check( isset( $preflight_one['budget_costs']['requests'] ) && 1 === (int) $preflight_one['budget_costs']['requests'], 'Permission preflight did not expose the expected read-only request cost.' );
$windows_after_preflight = $read_windows();
$check( 0 === count( $windows_after_preflight ), 'Permission preflight created or committed a budget window.' );
$ticket_one_preflight = MAD4B_SCP_Approval_Tickets::get( $ticket_one['ticket_id'] );
$check( is_array( $ticket_one_preflight ) && 'approved' === $ticket_one_preflight['status'], 'Permission preflight consumed or claimed the exact approval ticket.' );

$claim_one = MAD4B_SCP_Authorization::claim_mutation( 'mad4b/mutation-undo', 'mad4b-admin', 'core', $input );
$check( is_array( $claim_one ) && ! empty( $claim_one['allowed'] ) && ! empty( $claim_one['execution_side_effects'] ), 'Execution claim did not authorize the budgeted request.' );
$check( ! empty( $claim_one['budget']['configured'] ), 'Execution claim did not report configured budget evidence.' );
$windows_after_claim = $read_windows();
$check( 1 === count( $windows_after_claim ) && 1 === (int) $windows_after_claim[0]['used_count'], 'Execution claim did not commit exactly one request budget unit.' );
$ticket_one_claimed = MAD4B_SCP_Approval_Tickets::get( $ticket_one['ticket_id'] );
$check( is_array( $ticket_one_claimed ) && 'executing' === $ticket_one_claimed['status'], 'Execution claim did not move the exact approval ticket to executing.' );
$finalized_one = MAD4B_SCP_Authorization::finalize_execution_claim( $claim_one, array( 'verified' => true ) );
$check( true === $finalized_one, 'Successful execution claim could not be finalized.' );
$ticket_one_after = MAD4B_SCP_Approval_Tickets::get( $ticket_one['ticket_id'] );
$check( is_array( $ticket_one_after ) && 'used' === $ticket_one_after['status'], 'Successful finalized execution did not consume the exact approval ticket.' );

// The next section represents a new request with a distinct approval ticket.
// Runtime HTTP/MCP naturally gets fresh request-local state; reset only the CI
// fixture overlay while preserving durable budget, grant and audit state.
$reset_request_ticket_overlay();

// 3. Exhaustion is enforced at execution claim, after observational preflight and before approval claim.
$ticket_two = MAD4B_SCP_Approval_Tickets::create_pending(
	$agent['public_id'], 'mad4b-admin', 'mad4b/mutation-undo', 'core', $target, $input, 'mutation', 'CI budget exhaustion approval', 600
);
$check( is_array( $ticket_two ), 'Unable to create exhaustion-test approval ticket.' );
$approved_two = MAD4B_SCP_Approval_Tickets::approve( $ticket_two['ticket_id'] );
$check( is_array( $approved_two ) && 'approved' === $approved_two['status'], 'Unable to approve exhaustion-test ticket.' );
$approval_ticket_id = $ticket_two['ticket_id'];
$preflight_two = MAD4B_SCP_Authorization::authorize_mutation( 'mad4b/mutation-undo', 'mad4b-admin', 'core', $input );
$check( is_array( $preflight_two ) && ! empty( $preflight_two['allowed'] ) && empty( $preflight_two['execution_side_effects'] ), 'Exhausted-budget request did not remain observational during permission preflight.' );
$exhausted = MAD4B_SCP_Authorization::claim_mutation( 'mad4b/mutation-undo', 'mad4b-admin', 'core', $input );
$check( is_wp_error( $exhausted ) && 'mad4b_budget_exhausted' === $exhausted->get_error_code(), 'Second execution claim was not denied by the exhausted budget.' );
$ticket_two_after = MAD4B_SCP_Approval_Tickets::get( $ticket_two['ticket_id'] );
$check( is_array( $ticket_two_after ) && 'approved' === $ticket_two_after['status'], 'Budget exhaustion claimed the approval ticket before execution could start.' );
$windows_after_exhausted = $read_windows();
$check( 1 === count( $windows_after_exhausted ) && 1 === (int) $windows_after_exhausted[0]['used_count'], 'Budget exhaustion mutated the committed counter.' );

// 4. Move the committed window into the immediately previous bucket and prove the same still-approved ticket can be claimed in a fresh current window.
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

$rollover_claim = MAD4B_SCP_Authorization::claim_mutation( 'mad4b/mutation-undo', 'mad4b-admin', 'core', $input );
$check( is_array( $rollover_claim ) && ! empty( $rollover_claim['allowed'] ) && ! empty( $rollover_claim['execution_side_effects'] ), 'Approved ticket did not become claimable in a fresh budget window.' );
$ticket_two_executing = MAD4B_SCP_Approval_Tickets::get( $ticket_two['ticket_id'] );
$check( is_array( $ticket_two_executing ) && 'executing' === $ticket_two_executing['status'], 'Rollover claim did not move the preserved approval ticket to executing.' );
$finalized_two = MAD4B_SCP_Authorization::finalize_execution_claim( $rollover_claim, array( 'verified' => true ) );
$check( true === $finalized_two, 'Rollover execution claim could not be finalized.' );
$ticket_two_rollover = MAD4B_SCP_Approval_Tickets::get( $ticket_two['ticket_id'] );
$check( is_array( $ticket_two_rollover ) && 'used' === $ticket_two_rollover['status'], 'Rollover finalized execution did not consume the previously preserved approval ticket.' );
$windows_after_rollover = $read_windows();
$check( count( $windows_after_rollover ) >= 2, 'Budget rollover did not preserve the prior window and create a fresh current window.' );
$latest_window = end( $windows_after_rollover );
$check( (int) $latest_window['window_start'] > $previous_window_start && 1 === (int) $latest_window['used_count'], 'Fresh budget window did not start with the exact committed usage.' );

echo "mad4b.site-control-plane.runtime-budget.v1: PASS\n";
