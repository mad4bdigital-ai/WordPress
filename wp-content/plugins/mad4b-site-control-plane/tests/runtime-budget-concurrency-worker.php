<?php
/**
 * One independent WP-CLI process participating in the transactional budget race.
 */

if ( ! defined( 'ABSPATH' ) ) {
	throw new RuntimeException( 'WordPress is not loaded.' );
}

$worker = (string) getenv( 'MAD4B_CI_BUDGET_WORKER' );
$barrier = (float) getenv( 'MAD4B_CI_BUDGET_BARRIER' );
if ( ! in_array( $worker, array( 'one', 'two' ), true ) || $barrier <= microtime( true ) ) {
	throw new RuntimeException( 'Concurrency worker requires a valid worker id and a future barrier.' );
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
while ( microtime( true ) < $barrier ) usleep( 10000 );

$result = MAD4B_SCP_Authorization::authorize_mutation(
	'mad4b/mutation-undo',
	'mad4b-admin',
	'core',
	$config['input']
);

if ( is_wp_error( $result ) ) {
	$out = array( 'worker' => $worker, 'status' => 'denied', 'reason_code' => $result->get_error_code() );
} else {
	$out = array( 'worker' => $worker, 'status' => 'allowed', 'reason_code' => 'allowed' );
}
echo 'MAD4B_BUDGET_WORKER_RESULT=' . wp_json_encode( $out, JSON_UNESCAPED_SLASHES ) . "\n";
