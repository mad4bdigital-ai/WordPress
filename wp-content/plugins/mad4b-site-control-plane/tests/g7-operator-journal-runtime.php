<?php
/* Hermetic G7 operator-journal integrity and redaction assertions. */
define( 'ABSPATH', __DIR__ . '/' );
class WP_Error { public function __construct( $code, $message = '', $data = array() ) {} }
function is_wp_error( $v ) { return $v instanceof WP_Error; }
function sanitize_key( $v ) { return strtolower( preg_replace( '/[^a-z0-9_\-]/i', '', (string) $v ) ); }
class MAD4B_SCP_Operation_Journal {
    public static $trace; public static $status;
    public static function trace( $operation_id, $limit = 200 ) { return self::$trace; }
    public static function status( $operation_id ) { return self::$status; }
}
require dirname( __DIR__ ) . '/includes/class-mad4b-scp-g7-operator-journal.php';
function jassert( $yes, $message ) { if ( ! $yes ) { fwrite( STDERR, "FAIL: $message\n" ); exit( 1 ); } }
$id = '1077c7ee-f3a1-4866-9320-444444444444';
$sha = str_repeat( 'a', 64 );
MAD4B_SCP_Operation_Journal::$status = array( 'operation_id' => $id, 'latest_sequence' => 1, 'journal_head_sha256' => $sha, 'lifecycle_state' => 'completed', 'terminal_outcome' => 'committed' );
MAD4B_SCP_Operation_Journal::$trace = array(
    'operation_id' => $id, 'chain_valid' => true, 'complete' => true, 'count' => 1,
    'events' => array( array(
        'sequence' => 1, 'event_sha256' => $sha, 'event_type' => 'operation_completed',
        'checkpoint' => 'readback', 'lifecycle_state' => 'completed', 'terminal_outcome' => 'committed',
        'safe_metadata' => array(
            'actor_id' => 'human-7', 'agent_id' => 'agent-2', 'reason_code' => 'approved-plan',
            'object_type' => 'post', 'object_id' => '42',
            'api_token' => 'secret-must-not-leak', 'response_body' => array( 'key' => 'secret-must-not-leak' )
        ),
    ) ),
);
$projection = MAD4B_SCP_G7_Operator_Journal::project( $id );
jassert( 'HISTORY_ONLY' === $projection['state'] && true === $projection['verified_history'], 'verified hash-chain projection' );
jassert( false === $projection['outcome_is_commit_proof'] && false === $projection['authorizing'] && false === $projection['mutation_performed'], 'journal never proves commit or grants authority' );
jassert( 'EXACT_COMPENSATION_AND_CURRENT_READBACK_REQUIRED' === $projection['undo_eligibility'], 'no history-only Undo' );
jassert( '[REDACTED]' === $projection['events'][0]['safe_metadata']['api_token'], 'token redacted' );
jassert( false === strpos( json_encode( $projection ), 'secret-must-not-leak' ), 'sensitive content cannot leak' );
$good = MAD4B_SCP_Operation_Journal::$trace;
MAD4B_SCP_Operation_Journal::$trace['chain_valid'] = false;
$bad = MAD4B_SCP_G7_Operator_Journal::project( $id );
jassert( 'RECONCILIATION_REQUIRED' === $bad['state'] && count( $bad['events'] ) === 0, 'forged hash chain denied' );
MAD4B_SCP_Operation_Journal::$trace = $good;
MAD4B_SCP_Operation_Journal::$trace['complete'] = false;
jassert( 'RECONCILIATION_REQUIRED' === MAD4B_SCP_G7_Operator_Journal::project( $id )['state'], 'truncation denied' );
MAD4B_SCP_Operation_Journal::$trace = $good;
MAD4B_SCP_Operation_Journal::$status['journal_head_sha256'] = str_repeat( 'b', 64 );
jassert( 'RECONCILIATION_REQUIRED' === MAD4B_SCP_G7_Operator_Journal::project( $id )['state'], 'head mismatch denied' );
MAD4B_SCP_Operation_Journal::$status['journal_head_sha256'] = $sha;
MAD4B_SCP_Operation_Journal::$trace['events'][0]['sequence'] = 2;
jassert( 'RECONCILIATION_REQUIRED' === MAD4B_SCP_G7_Operator_Journal::project( $id )['state'], 'invalid event ordering denied' );
MAD4B_SCP_Operation_Journal::$trace = $good;
MAD4B_SCP_Operation_Journal::$status['operation_id'] = 'foreign';
jassert( 'RECONCILIATION_REQUIRED' === MAD4B_SCP_G7_Operator_Journal::project( $id )['state'], 'cross-operation identity denied' );
echo "mad4b.feature007-g7-operator-journal.v1: PASS\n";
