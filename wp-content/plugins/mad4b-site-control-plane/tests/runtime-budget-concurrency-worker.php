<?php
/**
 * One independent WP-CLI process participating in the transactional budget race.
 */

if ( ! defined( 'ABSPATH' ) ) {
	throw new RuntimeException( 'WordPress is not loaded.' );
}

$worker = (string) getenv( 'MAD4B_CI_BUDGET_WORKER' );
$ready_file = (string) getenv( 'MAD4B_CI_BUDGET_READY_FILE' );
$release_file = (string) getenv( 'MAD4B_CI_BUDGET_RELEASE_FILE' );
if ( ! in_array( $worker, array( 'one', 'two' ), true )
	|| 0 !== strpos( $ready_file, '/tmp/mad4b-budget-sync-' )
	|| 0 !== strpos( $release_file, '/tmp/mad4b-budget-sync-' ) ) {
	throw new RuntimeException( 'Concurrency worker requires a valid worker id and bounded sync files.' );
}

$config = get_option( 'mad4b_ci_budget_concurrency', array() );
if ( ! is_array( $config ) || empty( $config['tickets'][ $worker ] ) || empty( $config['input'] ) ) {
	throw new RuntimeException( 'Concurrency setup state is unavailable.' );
}

$ticket_id = (string) $config['tickets'][ $worker ];
$subject_type = (string) $config['subject_type'];
$subject_identifier = (string) $config['subject_identifier'];
add_filter(
	'mad4b_scp_authenticated_subject_context',
	static function ( $context ) use ( $subject_type, $subject_identifier, $ticket_id, $worker ) {
		return array(
			'authenticated' => true,
			'subject_type' => $subject_type,
			'subject_identifier' => $subject_identifier,
			'token_scopes' => array( 'ability:mad4b/mutation-undo' ),
			'approval_ticket_id' => $ticket_id,
			'auth_method' => 'ci',
			'wp_user_id' => get_current_user_id(),
			'request_id' => 'ci-runtime-budget-race-' . $worker,
			'origin' => 'ci',
		);
	},
	999
);

if ( ! defined( 'MAD4B_MCP_MUTATION_ENABLED' ) ) define( 'MAD4B_MCP_MUTATION_ENABLED', true );

if ( false === @file_put_contents( $ready_file, $worker, LOCK_EX ) ) {
	throw new RuntimeException( 'Concurrency worker could not publish its ready marker.' );
}
$deadline = microtime( true ) + 30.0;
while ( ! is_file( $release_file ) ) {
	if ( microtime( true ) >= $deadline ) throw new RuntimeException( 'Concurrency worker timed out waiting for release.' );
	usleep( 10000 );
}

$result = MAD4B_SCP_Authorization::claim_mutation(
	'mad4b/mutation-undo',
	'mad4b-admin',
	'core',
	$config['input']
);

if ( is_wp_error( $result ) ) {
	$out = array( 'worker' => $worker, 'status' => 'denied', 'reason_code' => $result->get_error_code() );
} else {
	$finalized = MAD4B_SCP_Authorization::finalize_execution_claim( $result, array( 'verified' => true ) );
	if ( is_wp_error( $finalized ) ) {
		throw new RuntimeException( 'Winning contention claim could not finalize its approval ticket: ' . $finalized->get_error_code() );
	}
	$out = array( 'worker' => $worker, 'status' => 'allowed', 'reason_code' => 'allowed' );
}
echo 'MAD4B_BUDGET_WORKER_RESULT=' . wp_json_encode( $out, JSON_UNESCAPED_SLASHES ) . "\n";
