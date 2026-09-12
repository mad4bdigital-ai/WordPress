<?php

define( 'ABSPATH', __DIR__ );
function add_filter() { return true; }
function add_action() { return true; }

require dirname( __DIR__ ) . '/includes/class-mad4b-scp-live-acceptance-reconciler.php';

function mad4b_assert( $condition, $message ) {
	if ( ! $condition ) { fwrite( STDERR, "FAIL: {$message}\n" ); exit( 1 ); }
}

$method = new ReflectionMethod( 'MAD4B_SCP_Live_Acceptance_Reconciler', 'find_replay_event' );
$method->setAccessible( true );
$ticket = '11111111-1111-4111-8111-111111111111';
$ability = 'elementor/update-widget-settings';
$audit_ability = 'mad4b/authorization:' . $ability;
$base = array(
	'sequence' => 11,
	'entry_hash' => str_repeat( 'a', 64 ),
	'user_id' => 7,
	'ability' => $audit_ability,
	'status' => 'denied',
	'summary' => array( 'reason_code' => 'mad4b_approval_replay_denied', 'approval_ticket_id' => $ticket ),
);

$exact = $method->invoke( null, array( $base ), $ticket, $ability, 7, 10, 20 );
mad4b_assert( ! empty( $exact ) && 11 === (int) $exact['sequence'], 'Exact ticket-bound replay denial must reconstruct.' );

$wrong = $base;
$wrong['summary']['approval_ticket_id'] = '22222222-2222-4222-8222-222222222222';
mad4b_assert( empty( $method->invoke( null, array( $wrong ), $ticket, $ability, 7, 10, 20 ) ), 'A non-empty mismatching ticket must never fall back to legacy reconstruction.' );

$legacy = $base;
$legacy['summary']['approval_ticket_id'] = '';
$legacy_match = $method->invoke( null, array( $legacy ), $ticket, $ability, 7, 10, 20 );
mad4b_assert( ! empty( $legacy_match ), 'One ticket-less rc.27 replay denial bounded by exact ability/user/sequence must reconstruct.' );

$legacy_two = $legacy;
$legacy_two['sequence'] = 12;
mad4b_assert( empty( $method->invoke( null, array( $legacy, $legacy_two ), $ticket, $ability, 7, 10, 20 ) ), 'Multiple ticket-less replay candidates must fail closed as ambiguous.' );

$wrong_ability = $legacy;
$wrong_ability['ability'] = 'mad4b/authorization:other/write';
mad4b_assert( empty( $method->invoke( null, array( $wrong_ability ), $ticket, $ability, 7, 10, 20 ) ), 'Legacy replay evidence from another ability must fail closed.' );

$wrong_user = $legacy;
$wrong_user['user_id'] = 8;
mad4b_assert( empty( $method->invoke( null, array( $wrong_user ), $ticket, $ability, 7, 10, 20 ) ), 'Legacy replay evidence from another WordPress subject must fail closed.' );

echo "mad4b.live-acceptance-replay-binding.runtime.v1: PASS\n";
