<?php
/**
 * Disposable runtime proof for approval-ticket expiry.
 *
 * Proves both boundaries without sleeping:
 * 1. an expired pending ticket cannot be approved;
 * 2. an approved ticket that later expires cannot be consumed and remains unused.
 */

if ( ! defined( 'ABSPATH' ) ) {
	throw new RuntimeException( 'WordPress is not loaded.' );
}

$check = static function ( $condition, $message ) {
	if ( ! $condition ) throw new RuntimeException( $message );
};

$check( current_user_can( 'manage_options' ), 'Approval-expiry runtime proof requires the CI administrator.' );
$check( MAD4B_SCP_Schema::is_ready(), 'Governance schema is not ready.' );

$agent = MAD4B_SCP_Agent_Registry::create_agent(
	array(
		'slug' => 'ci-approval-expiry-agent',
		'label' => 'CI Approval Expiry Agent',
		'status' => 'enabled',
		'environment' => 'all',
		'wp_user_id' => get_current_user_id(),
	)
);
$check( is_array( $agent ) && ! empty( $agent['public_id'] ), 'Unable to create approval-expiry test agent.' );

$grant = MAD4B_SCP_Agent_Registry::grant_ability(
	$agent['public_id'],
	'mad4b-admin',
	'mad4b/mutation-undo',
	'core',
	array(),
	'allow',
	'all'
);
$check( true === $grant, 'Unable to create exact undo grant for approval-expiry proof.' );

$server_id = 'mad4b-admin';
$ability_name = 'mad4b/mutation-undo';
$provider = 'core';
$ticket_class = 'mutation';
$past = '2000-01-01 00:00:00';
$t = MAD4B_SCP_Schema::tables();
global $wpdb;

// Boundary A: expiry before approval must keep the ticket pending and unapproved.
$pending_target = 'mutation:' . wp_generate_uuid4();
$pending_input = array(
	'mutation_id' => wp_generate_uuid4(),
	'reason' => 'CI pending ticket expiry proof',
);
$pending = MAD4B_SCP_Approval_Tickets::create_pending(
	$agent['public_id'],
	$server_id,
	$ability_name,
	$provider,
	$pending_target,
	$pending_input,
	$ticket_class,
	'CI proves expired pending approval is not approvable',
	60
);
$check( is_array( $pending ) && 'pending' === $pending['status'], 'Unable to create pending ticket for pre-approval expiry proof.' );

$expired_pending = $wpdb->update(
	$t['approvals'],
	array( 'expires_at' => $past ),
	array( 'ticket_id' => $pending['ticket_id'] ),
	array( '%s' ),
	array( '%s' )
); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
$check( 1 === (int) $expired_pending, 'Unable to backdate pending ticket expiry in the disposable runtime fixture.' );

$approve_result = MAD4B_SCP_Approval_Tickets::approve( $pending['ticket_id'] );
$check(
	is_wp_error( $approve_result ) && 'mad4b_approval_not_approvable' === $approve_result->get_error_code(),
	'Expired pending ticket was approvable.'
);
$pending_after = MAD4B_SCP_Approval_Tickets::get( $pending['ticket_id'] );
$check( is_array( $pending_after ) && 'pending' === $pending_after['status'], 'Expired pending ticket changed status during rejected approval.' );
$check( 0 === (int) $pending_after['approved_by'] && empty( $pending_after['approved_at'] ), 'Expired pending ticket recorded approval evidence.' );
$check( empty( $pending_after['used_at'] ), 'Expired pending ticket unexpectedly recorded use.' );

// Boundary B: a ticket approved while valid must become non-consumable after expiry.
$approved_target = 'mutation:' . wp_generate_uuid4();
$approved_input = array(
	'mutation_id' => wp_generate_uuid4(),
	'reason' => 'CI approved ticket expiry proof',
);
$approved = MAD4B_SCP_Approval_Tickets::create_pending(
	$agent['public_id'],
	$server_id,
	$ability_name,
	$provider,
	$approved_target,
	$approved_input,
	$ticket_class,
	'CI proves approved ticket cannot be consumed after expiry',
	60
);
$check( is_array( $approved ) && 'pending' === $approved['status'], 'Unable to create ticket for post-approval expiry proof.' );

$approved_result = MAD4B_SCP_Approval_Tickets::approve( $approved['ticket_id'] );
$check( is_array( $approved_result ) && 'approved' === $approved_result['status'], 'Valid ticket could not be approved before expiry.' );

$expired_approved = $wpdb->update(
	$t['approvals'],
	array( 'expires_at' => $past ),
	array( 'ticket_id' => $approved['ticket_id'] ),
	array( '%s' ),
	array( '%s' )
); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
$check( 1 === (int) $expired_approved, 'Unable to backdate approved ticket expiry in the disposable runtime fixture.' );

$consume_result = MAD4B_SCP_Approval_Tickets::consume_exact(
	$approved['ticket_id'],
	$agent,
	$server_id,
	$ability_name,
	$provider,
	$approved_target,
	$approved_input,
	$ticket_class
);
$check(
	is_wp_error( $consume_result ) && 'mad4b_approval_expired' === $consume_result->get_error_code(),
	'Expired approved ticket was consumable or returned the wrong denial.'
);
$approved_after = MAD4B_SCP_Approval_Tickets::get( $approved['ticket_id'] );
$check( is_array( $approved_after ) && 'approved' === $approved_after['status'], 'Rejected expired consume changed ticket status.' );
$check( empty( $approved_after['used_at'] ), 'Expired approved ticket was marked used.' );
$check( ! empty( $approved_after['approved_at'] ) && get_current_user_id() === (int) $approved_after['approved_by'], 'Approved ticket lost its original approval evidence after expiry denial.' );

echo "mad4b.site-control-plane.runtime-approval-expiry.v1: PASS\n";
