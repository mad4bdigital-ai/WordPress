<?php
/* Hermetic G7 operator-journal integrity and redaction assertions. */
define( 'ABSPATH', __DIR__ . '/' );
class WP_Error { public function __construct( $code, $message = '', $data = array() ) {} }
function is_wp_error( $v ) { return $v instanceof WP_Error; }
function sanitize_key( $v ) { return strtolower( preg_replace( '/[^a-z0-9_\-]/i', '', (string) $v ) ); }
class MAD4B_SCP_Operation_Journal {
    public static $trace; public static $status; public static $read_ids = array();
    public static function trace( $operation_id, $limit = 200 ) { self::$read_ids[] = $operation_id; return self::$trace; }
    public static function status( $operation_id ) { self::$read_ids[] = $operation_id; return self::$status; }
}
require_once dirname( __DIR__ ) . '/includes/class-mad4b-scp-identifiers.php';
require dirname( __DIR__ ) . '/includes/class-mad4b-scp-g7-operator-journal.php';
function jassert( $yes, $message ) { if ( ! $yes ) { fwrite( STDERR, "FAIL: $message\n" ); exit( 1 ); } }
$id = '1077c7ee-f3a1-4866-9320-444444444444';
$sha = str_repeat( 'a', 64 );
MAD4B_SCP_Operation_Journal::$status = array( 'contract' => 'mad4b.dynamic-operation-status.v1',
    'read_only' => true, 'mutation_performed' => false, 'operation_id' => $id,
    'latest_sequence' => 1, 'journal_head_sha256' => $sha,
    'lifecycle_state' => 'completed', 'terminal_outcome' => 'committed' );
MAD4B_SCP_Operation_Journal::$trace = array(
    'contract' => 'mad4b.dynamic-operation-trace.v1', 'read_only' => true,
    'mutation_performed' => false, 'operation_id' => $id,
    'chain_valid' => true, 'complete' => true, 'count' => 1,
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
jassert( $id === $projection['operation_id'] && array( $id, $id ) === MAD4B_SCP_Operation_Journal::$read_ids, 'lowercase UUID native read identity' );
jassert( false === $projection['outcome_is_commit_proof'] && false === $projection['authorizing'] && false === $projection['mutation_performed'], 'journal never proves commit or grants authority' );
jassert( 'EXACT_COMPENSATION_AND_CURRENT_READBACK_REQUIRED' === $projection['undo_eligibility'], 'no history-only Undo' );
jassert( '[REDACTED]' === $projection['events'][0]['safe_metadata']['api_token'], 'token redacted' );
jassert( false === strpos( json_encode( $projection ), 'secret-must-not-leak' ), 'sensitive content cannot leak' );
$good = MAD4B_SCP_Operation_Journal::$trace;
$good_status = MAD4B_SCP_Operation_Journal::$status;
MAD4B_SCP_Operation_Journal::$read_ids = array();
$uppercase = MAD4B_SCP_G7_Operator_Journal::project( strtoupper( $id ) );
jassert( 'HISTORY_ONLY' === $uppercase['state'] && true === $uppercase['verified_history'] &&
    $id === $uppercase['operation_id'] && array( $id, $id ) === MAD4B_SCP_Operation_Journal::$read_ids,
    'real native UUID policy resolves uppercase input before exact journal comparison' );
MAD4B_SCP_Operation_Journal::$trace['chain_valid'] = false;
$bad = MAD4B_SCP_G7_Operator_Journal::project( $id );
jassert( 'RECONCILIATION_REQUIRED' === $bad['state'] && count( $bad['events'] ) === 0, 'forged hash chain denied' );
MAD4B_SCP_Operation_Journal::$trace = $good;
MAD4B_SCP_Operation_Journal::$trace['contract'] = 'foreign.journal.v1';
jassert( 'RECONCILIATION_REQUIRED' === MAD4B_SCP_G7_Operator_Journal::project( $id )['state'], 'foreign trace source contract denied' );
MAD4B_SCP_Operation_Journal::$trace = $good;
MAD4B_SCP_Operation_Journal::$trace['read_only'] = false;
jassert( 'RECONCILIATION_REQUIRED' === MAD4B_SCP_G7_Operator_Journal::project( $id )['state'], 'mutating trace source denied' );
MAD4B_SCP_Operation_Journal::$trace = $good;
MAD4B_SCP_Operation_Journal::$status['contract'] = 'foreign.status.v1';
jassert( 'RECONCILIATION_REQUIRED' === MAD4B_SCP_G7_Operator_Journal::project( $id )['state'], 'foreign status source contract denied' );
MAD4B_SCP_Operation_Journal::$status['contract'] = 'mad4b.dynamic-operation-status.v1';
MAD4B_SCP_Operation_Journal::$trace['events'][0]['safe_metadata'] = 'invalid-opaque-blob';
jassert( 'RECONCILIATION_REQUIRED' === MAD4B_SCP_G7_Operator_Journal::project( $id )['state'], 'malformed redaction source metadata denied' );
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
MAD4B_SCP_Operation_Journal::$status['operation_id'] = $id;
jassert( 'RECONCILIATION_REQUIRED' === MAD4B_SCP_G7_Operator_Journal::project( 'another-operation' )['state'], 'matching trace and status must still match the requested operation' );
jassert( is_wp_error( MAD4B_SCP_G7_Operator_Journal::project( array( 'untrusted' => 'id' ) ) ), 'invalid operation input rejected before calling native journal' );
MAD4B_SCP_Operation_Journal::$trace = $good;
MAD4B_SCP_Operation_Journal::$status = $good_status;
$reads = count( MAD4B_SCP_Operation_Journal::$read_ids );
jassert( is_wp_error( MAD4B_SCP_G7_Operator_Journal::project( '1077c7ee-f3a1-1866-9320-444444444444' ) ), 'real native policy rejects non-v4 UUID before journal reads' );
jassert( is_wp_error( MAD4B_SCP_G7_Operator_Journal::project( 'short' ) ) &&
    $reads === count( MAD4B_SCP_Operation_Journal::$read_ids ), 'invalid legacy identity cannot reach journal reads' );
$foreign_id = '1077c7ee-f3a1-4866-9320-555555555555';
MAD4B_SCP_Operation_Journal::$trace['operation_id'] = $foreign_id;
MAD4B_SCP_Operation_Journal::$status['operation_id'] = $foreign_id;
jassert( 'RECONCILIATION_REQUIRED' === MAD4B_SCP_G7_Operator_Journal::project( strtoupper( $id ) )['state'], 'normalized UUID request still denies a matching foreign trace and status' );
MAD4B_SCP_Operation_Journal::$trace['operation_id'] = strtoupper( $id );
MAD4B_SCP_Operation_Journal::$status['operation_id'] = strtoupper( $id );
jassert( 'RECONCILIATION_REQUIRED' === MAD4B_SCP_G7_Operator_Journal::project( $id )['state'], 'source identities must match exact native lookup output' );
$legacy_id = 'legacy.op:2024.Run-ABC_01';
$legacy_lookup = MAD4B_SCP_Identifiers::operation_lookup( $legacy_id );
jassert( is_array( $legacy_lookup ) && 'legacy_read_only' === $legacy_lookup['identity_class'] &&
    $legacy_id === $legacy_lookup['value'] && true === $legacy_lookup['historical_identity_preserved'] &&
    false === $legacy_lookup['rewrite_allowed'], 'real native legacy policy preserves historical bytes' );
MAD4B_SCP_Operation_Journal::$trace['operation_id'] = $legacy_id;
MAD4B_SCP_Operation_Journal::$status['operation_id'] = $legacy_id;
MAD4B_SCP_Operation_Journal::$read_ids = array();
$legacy_projection = MAD4B_SCP_G7_Operator_Journal::project( $legacy_id );
jassert( 'HISTORY_ONLY' === $legacy_projection['state'] && true === $legacy_projection['verified_history'] &&
    $legacy_id === $legacy_projection['operation_id'] &&
    array( $legacy_id, $legacy_id ) === MAD4B_SCP_Operation_Journal::$read_ids, 'legacy read identity remains exact through projection' );
jassert( 'RECONCILIATION_REQUIRED' === MAD4B_SCP_G7_Operator_Journal::project( strtolower( $legacy_id ) )['state'], 'legacy case change is a different operation, never a UUID-style alias' );
echo "mad4b.feature007-g7-operator-journal.v1: PASS\n";
