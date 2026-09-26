<?php
/**
 * Provision one exact NHI budget and two exact approval capabilities for a real
 * two-process budget contention test. Disposable CI runtime only.
 */

if ( ! defined( 'ABSPATH' ) ) {
	throw new RuntimeException( 'WordPress is not loaded.' );
}

$check = static function ( $condition, $message ) {
	if ( ! $condition ) throw new RuntimeException( $message );
};

$subject_type = 'ci-budget-race';
$subject_identifier = 'mad4b-runtime-budget-race-agent';
$subject_fingerprint = hash( 'sha256', $subject_type . "\0" . $subject_identifier );
$agent = MAD4B_SCP_Agent_Registry::create_agent(
	array(
		'slug' => 'ci-runtime-budget-race-agent',
		'label' => 'CI Runtime Budget Race Agent',
		'status' => 'enabled',
		'environment' => 'all',
		'wp_user_id' => get_current_user_id(),
	)
);
$check( is_array( $agent ) && ! empty( $agent['public_id'] ) && ! empty( $agent['id'] ), 'Unable to create contention-test NHI.' );
$bound = MAD4B_SCP_Agent_Registry::bind_subject( $agent['public_id'], $subject_type, $subject_fingerprint, 'CI runtime budget race subject' );
$check( true === $bound, 'Unable to bind contention-test subject.' );
$grant = MAD4B_SCP_Agent_Registry::grant_ability( $agent['public_id'], 'mad4b-admin', 'mad4b/mutation-undo', 'core', array(), 'allow', 'all' );
$check( true === $grant, 'Unable to create exact contention-test grant.' );
$budget = MAD4B_SCP_Budgets::set_budget( $agent['public_id'], 'requests', 60, 1, true );
$check( is_array( $budget ) && 1 === (int) $budget['max_count'], 'Unable to configure one-request contention budget.' );

$input = array(
	'mutation_id' => wp_generate_uuid4(),
	'reason' => 'CI concurrent budget authorization proof',
);
$target = MAD4B_SCP_Authorization::target_fingerprint( 'mad4b/mutation-undo', 'core', $input, $agent, array( 'planning' => true ) );
$tickets = array();
foreach ( array( 'one', 'two' ) as $worker ) {
	$ticket = MAD4B_SCP_Approval_Tickets::create_pending(
		$agent['public_id'],
		'mad4b-admin',
		'mad4b/mutation-undo',
		'core',
		$target,
		$input,
		'mutation',
		'CI concurrent budget worker ' . $worker,
		600
	);
	$check( is_array( $ticket ) && 'pending' === $ticket['status'], 'Unable to create contention approval for worker ' . $worker . '.' );
	$approved = MAD4B_SCP_Approval_Tickets::approve( $ticket['ticket_id'] );
	$check( is_array( $approved ) && 'approved' === $approved['status'], 'Unable to approve contention ticket for worker ' . $worker . '.' );
	$tickets[ $worker ] = $ticket['ticket_id'];
}

update_option(
	'mad4b_ci_budget_concurrency',
	array(
		'agent_id' => (int) $agent['id'],
		'agent_public_id' => $agent['public_id'],
		'subject_type' => $subject_type,
		'subject_identifier' => $subject_identifier,
		'input' => $input,
		'tickets' => $tickets,
	),
	false
);

echo "mad4b.site-control-plane.runtime-budget-concurrency-setup.v1: PASS\n";
