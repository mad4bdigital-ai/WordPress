<?php

define( 'ABSPATH', __DIR__ );

function add_filter() { return true; }
function is_wp_error( $value ) { return $value instanceof WP_Error; }

class WP_Error {
	private $code;
	public function __construct( $code, $message = '' ) { $this->code = (string) $code; }
	public function get_error_code() { return $this->code; }
}

$GLOBALS['mad4b_identity'] = array( 'request_id' => 'req-1', 'approval_ticket_id' => '' );
$GLOBALS['mad4b_audit_events'] = array();

class MAD4B_SCP_Identity_Context {
	public static function current() { return $GLOBALS['mad4b_identity']; }
}
class MAD4B_SCP_Staging_Write_Authority {
	public static function approval_ticket_from_input( $input ) {
		return is_array( $input ) && isset( $input['approval_ticket_id'] ) ? (string) $input['approval_ticket_id'] : '';
	}
}
class MAD4B_SCP_Audit {
	public static function record( $ability, array $summary, $status = 'ok' ) {
		$GLOBALS['mad4b_audit_events'][] = array( 'ability' => $ability, 'summary' => $summary, 'status' => $status );
		return true;
	}
}

require dirname( __DIR__ ) . '/includes/class-mad4b-scp-authorization.php';

function mad4b_assert( $condition, $message ) {
	if ( ! $condition ) { fwrite( STDERR, "FAIL: {$message}\n" ); exit( 1 ); }
}

$method = new ReflectionMethod( 'MAD4B_SCP_Authorization', 'audit_execution_denial' );
$method->setAccessible( true );
$ticket = '11111111-1111-4111-8111-111111111111';
$identity_ticket = '22222222-2222-4222-8222-222222222222';

$method->invoke( null, 'elementor/update-widget-settings', new WP_Error( 'mad4b_approval_replay_denied' ), array( 'approval_ticket_id' => $ticket ) );
$event = end( $GLOBALS['mad4b_audit_events'] );
mad4b_assert( $ticket === $event['summary']['approval_ticket_id'], 'Replay denial must preserve a valid governance-input ticket before Identity Context binding.' );

$method->invoke( null, 'elementor/update-widget-settings', new WP_Error( 'mad4b_approval_not_approved' ), array( 'approval_ticket_id' => $ticket ) );
$event = end( $GLOBALS['mad4b_audit_events'] );
mad4b_assert( '' === $event['summary']['approval_ticket_id'], 'Non-replay denials must not trust governance input as audit ticket identity.' );

$GLOBALS['mad4b_identity']['approval_ticket_id'] = $identity_ticket;
$method->invoke( null, 'elementor/update-widget-settings', new WP_Error( 'mad4b_approval_replay_denied' ), array( 'approval_ticket_id' => $ticket ) );
$event = end( $GLOBALS['mad4b_audit_events'] );
mad4b_assert( $identity_ticket === $event['summary']['approval_ticket_id'], 'Already-bound Identity Context ticket must remain authoritative over governance input.' );

$GLOBALS['mad4b_identity']['approval_ticket_id'] = '';
$method->invoke( null, 'elementor/update-widget-settings', new WP_Error( 'mad4b_approval_replay_denied' ), array( 'approval_ticket_id' => 'not-a-ticket' ) );
$event = end( $GLOBALS['mad4b_audit_events'] );
mad4b_assert( '' === $event['summary']['approval_ticket_id'], 'Malformed replay ticket identifiers must fail closed.' );

echo "mad4b.authorization-replay-audit.runtime.v1: PASS\n";
