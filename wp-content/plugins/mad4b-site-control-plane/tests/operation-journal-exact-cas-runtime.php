<?php
/** Hermetic test of the real Operation_Journal::append opt-in CAS branch.
 * It exercises transactional rejection with a mock database, not MariaDB.
 */
if ( ! defined( 'ABSPATH' ) ) define( 'ABSPATH', __DIR__ );
define( 'ARRAY_A', 'ARRAY_A' );
class WP_Error {
    private $code, $data;
    public function __construct( $code, $message = '', $data = array() ) { $this->code = $code; $this->data = $data; }
    public function get_error_code() { return $this->code; }
    public function get_error_data() { return $this->data; }
}
function is_wp_error( $value ) { return $value instanceof WP_Error; }
function sanitize_key( $key ) { return preg_replace( '/[^a-z0-9_-]/', '', strtolower( (string) $key ) ); }
function wp_json_encode( $value, $options = 0 ) { return json_encode( $value, $options ); }
function absint( $value ) { return abs( (int) $value ); }
class MAD4B_SCP_Identifiers {
    public static function operation_id_for_write( $id ) {
        return is_string( $id ) && preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D', $id ) ? $id : '';
    }
    public static function operation_lookup( $id ) {
        $canonical = self::operation_id_for_write( $id );
        return $canonical ? array( 'value' => $canonical, 'identity_class' => 'canonical_uuid',
            'historical_identity_preserved' => false, 'rewrite_allowed' => false )
            : new WP_Error( 'journal_bad_id' );
    }
}
class MAD4B_SCP_Schema {
    public static function tables() { return array( 'operation_heads' => 'heads', 'operation_events' => 'events' ); }
}
class MAD4B_SCP_Canonicalization {
    public static function digest( $version, $value ) { return hash( 'sha256', serialize( array( $version, $value ) ) ); }
}
class MAD4B_SCP_Database_Failure_Semantics {
    public static function classify( $phase, $error, $rollback ) {
        return array( 'reconciliation_required' => ! $rollback, 'blind_retry_allowed' => false );
    }
}
class MAD4B_SCP_Database_Transaction_Guard {
    public static $begin = 0, $commit = 0, $rollback = 0;
    public static $simulate_commit_uncertain = false, $simulate_rollback_uncertain = false;
    public static function preflight( $tables, $write ) { return true; }
    public static function begin( $name, $tables, $readonly ) {
        global $wpdb;
        ++self::$begin;
        $wpdb->tx_snapshot = array( $wpdb->head, $wpdb->inserted, $wpdb->updated, $wpdb->events );
        return array( 'name' => $name );
    }
    public static function commit( $t ) {
        global $wpdb;
        ++self::$commit;
        $wpdb->tx_snapshot = null;
        if ( self::$simulate_commit_uncertain ) {
            self::$simulate_commit_uncertain = false;
            return new WP_Error( 'simulated_commit_lost_response' );
        }
        return true;
    }
    public static function rollback( $t ) {
        global $wpdb;
        ++self::$rollback;
        if ( self::$simulate_rollback_uncertain ) {
            self::$simulate_rollback_uncertain = false;
            return new WP_Error( 'simulated_rollback_unverified' );
        }
        if ( is_array( $wpdb->tx_snapshot ) ) {
            list( $wpdb->head, $wpdb->inserted, $wpdb->updated, $wpdb->events ) = $wpdb->tx_snapshot;
        }
        $wpdb->tx_snapshot = null;
        return true;
    }
}
class FixtureJournalDB {
    public $last_error = '';
    public $inserted = 0, $updated = 0;
    public $head, $tx_snapshot;
    public $simulate_truncated_head = false;
    public $events = array();
    public $fail_next_event_insert = false, $fail_next_head_update = false;
    public function prepare( $sql ) { return array( $sql, array_slice( func_get_args(), 1 ) ); }
    public function get_row( $prepared, $output ) { return $this->head; }
    public function get_results( $prepared, $output ) {
        $rows = array();
        foreach ( $this->events as $args ) {
            if ( $args[0] !== $prepared[1][0] ) continue;
            $rows[] = array(
                'operation_id' => $args[0], 'operation_key' => $args[1],
                'operation_binding_sha256' => $args[2], 'sequence' => $args[3],
                'event_type' => $args[4], 'checkpoint' => $args[5],
                'lifecycle_state' => $args[6], 'terminal_outcome' => $args[7],
                'safe_metadata_json' => $args[8], 'previous_event_sha256' => $args[9],
                'event_sha256' => $args[10], 'created_at' => $args[11],
            );
        }
        usort( $rows, static function ( $a, $b ) { return (int) $a['sequence'] - (int) $b['sequence']; } );
        return array_slice( $rows, 0, (int) $prepared[1][1] );
    }
    public function query( $prepared ) {
        $sql = $prepared[0]; $args = $prepared[1];
        if ( 0 === strpos( $sql, 'INSERT IGNORE INTO ' ) ) {
            if ( is_array( $this->head ) ) return 0;
            $this->head = array( 'operation_key' => $this->simulate_truncated_head ? substr( $args[1], 0, 8 ) : $args[1],
                'operation_binding_sha256' => $args[2], 'latest_sequence' => 0,
                'latest_event_sha256' => str_repeat( '0', 64 ),
                'hard_deadline_at' => gmdate( 'Y-m-d H:i:s', strtotime( $args[7] ) ) );
            return 1;
        }
        if ( 0 === strpos( $sql, 'INSERT INTO ' ) ) {
            if ( $this->fail_next_event_insert ) { $this->fail_next_event_insert = false; return false; }
            ++$this->inserted; $this->events[] = $args; return 1;
        }
        if ( 0 === strpos( $sql, 'UPDATE ' ) ) {
            if ( $this->fail_next_head_update ) { $this->fail_next_head_update = false; return false; }
            ++$this->updated;
            $this->head['latest_sequence'] = $args[0];
            $this->head['latest_event_sha256'] = $args[1];
            return 1;
        }
        return false;
    }
}
$wpdb = new FixtureJournalDB();
$context = array( 'operation_id' => 'fd154c93-24ed-495a-90aa-52a5c3e0b3f6',
    'operation_key' => 'proposal-' . str_repeat( '1', 32 ),
    'operation_binding_sha256' => str_repeat( 'b', 64 ),
    'hard_deadline_at' => gmdate( 'c', time() + 600 ) );
$wpdb->head = array( 'operation_key' => $context['operation_key'],
    'operation_binding_sha256' => $context['operation_binding_sha256'],
    'latest_sequence' => 1, 'latest_event_sha256' => str_repeat( 'a', 64 ) );
require dirname( __DIR__ ) . '/includes/class-mad4b-scp-operation-journal.php';
$GLOBALS['tests'] = 0;
function assert_journal( $what, $condition ) {
    ++$GLOBALS['tests']; if ( ! $condition ) {
        fwrite( STDERR, 'FAIL ' . $what . PHP_EOL ); exit( 1 );
    }
    echo 'PASS ' . $what . PHP_EOL;
}
$args = array( 'expected_sequence' => 1, 'expected_event_sha256' => str_repeat( 'a', 64 ),
    'metadata' => array( 'task_id' => $context['operation_key'] ) );
$unsafe_unbound = MAD4B_SCP_Operation_Journal::append( $context, 'assistant_task_transition',
    array( 'metadata' => array( 'task_id' => $context['operation_key'] ) ) );
assert_journal( 'assistant journal event always requires exact-head CAS', is_wp_error( $unsafe_unbound )
    && 'mad4b_operation_journal_cas_required' === $unsafe_unbound->get_error_code()
    && 0 === MAD4B_SCP_Database_Transaction_Guard::$begin );
$bad = $args; unset( $bad['expected_event_sha256'] );
$denial = MAD4B_SCP_Operation_Journal::append( $context, 'assistant_task_transition', $bad );
assert_journal( 'partial expected head must be rejected before DB', is_wp_error( $denial )
    && 'mad4b_operation_journal_cas_invalid' === $denial->get_error_code()
    && MAD4B_SCP_Database_Transaction_Guard::$begin === 0 );
$bad = $args; $bad['expected_sequence'] = 0;
$denial = MAD4B_SCP_Operation_Journal::append( $context, 'assistant_task_transition', $bad );
assert_journal( 'stale exact sequence rejected', is_wp_error( $denial )
    && 'mad4b_operation_journal_cas_stale' === $denial->get_error_code()
    && 0 === $wpdb->inserted && 0 === $wpdb->updated
    && 1 === MAD4B_SCP_Database_Transaction_Guard::$rollback );
$bad = $args; $bad['expected_event_sha256'] = str_repeat( 'c', 64 );
$denial = MAD4B_SCP_Operation_Journal::append( $context, 'assistant_task_transition', $bad );
assert_journal( 'same sequence but wrong hash rejected', is_wp_error( $denial )
    && 'mad4b_operation_journal_cas_stale' === $denial->get_error_code()
    && 0 === $wpdb->inserted && 0 === $wpdb->updated );
$ok = MAD4B_SCP_Operation_Journal::append( $context, 'assistant_task_transition', $args );
assert_journal( 'matching exact journal CAS commits one event and head', is_array( $ok )
    && 2 === $ok['sequence'] && 1 === $wpdb->inserted && 1 === $wpdb->updated
    && 1 === MAD4B_SCP_Database_Transaction_Guard::$commit );
$replay = MAD4B_SCP_Operation_Journal::append( $context, 'assistant_task_transition', $args );
assert_journal( 'reused journal expected head cannot replay', is_wp_error( $replay )
    && 'mad4b_operation_journal_cas_stale' === $replay->get_error_code()
    && 1 === $wpdb->inserted );
$legacy = MAD4B_SCP_Operation_Journal::append( $context, 'existing_legacy_journal_event',
    array( 'metadata' => array( 'operation_phase' => 'readback' ) ) );
assert_journal( 'unrelated producers without opt-in CAS retain contract', is_array( $legacy )
    && 3 === $legacy['sequence'] && 2 === $wpdb->inserted );
$wpdb = new FixtureJournalDB();
$new_ctx = $context; $new_ctx['operation_id'] = '02f421a9-ad22-49ce-b1bc-d347122168c2';
$oversized_genesis = array();
for ( $i = 0; $i < 80; $i++ ) $oversized_genesis['case_' . $i] = str_repeat( 'x', 400 );
$blocked_genesis = MAD4B_SCP_Operation_Journal::begin( $new_ctx, 'planned', $oversized_genesis );
assert_journal( 'oversized genesis metadata refuses before orphan head insertion',
    is_wp_error( $blocked_genesis )
    && 'mad4b_operation_metadata_too_large' === $blocked_genesis->get_error_code()
    && null === $wpdb->head && 0 === $wpdb->inserted && 0 === $wpdb->updated );
$first = MAD4B_SCP_Operation_Journal::begin( $new_ctx, 'planned', array( 'ticket' => 'proposal' ) );
assert_journal( 'first journal begin creates exactly one genesis', is_array( $first )
    && 1 === $first['sequence'] && 1 === $wpdb->inserted
    && 1 === $wpdb->updated );
$again = MAD4B_SCP_Operation_Journal::begin( $new_ctx, 'planned', array( 'ticket' => 'proposal' ) );
assert_journal( 'same journal begin cannot generate duplicate genesis', is_wp_error( $again )
    && 'mad4b_operation_journal_head_already_exists' === $again->get_error_code()
    && 1 === $wpdb->inserted && 1 === $wpdb->updated );
$wpdb = new FixtureJournalDB();
$oversized_key_context = $new_ctx;
$oversized_key_context['operation_key'] = str_repeat( 'z', 192 );
$invalid_key = MAD4B_SCP_Operation_Journal::begin( $oversized_key_context );
assert_journal( 'oversized operation key refused before SQL insertion',
    is_wp_error( $invalid_key ) && null === $wpdb->head );
$bad_deadline_context = $new_ctx;
$bad_deadline_context['hard_deadline_at'] = 'not-a-datetime';
$invalid_deadline = MAD4B_SCP_Operation_Journal::begin( $bad_deadline_context );
assert_journal( 'invalid deadline refused instead of fallback to implicit expiry',
    is_wp_error( $invalid_deadline ) && null === $wpdb->head );
$wpdb->simulate_truncated_head = true;
$truncated = MAD4B_SCP_Operation_Journal::begin( $new_ctx, 'planned', array( 'ticket' => 'proposal' ) );
assert_journal( 'coerced journal head identity must roll back before genesis event',
    is_wp_error( $truncated ) && null === $wpdb->head && array() === $wpdb->events );
$wpdb = new FixtureJournalDB();
$failure_context = $new_ctx;
$failure_context['operation_id'] = '08728fa8-166a-468d-9b93-89adb51db64e';
$wpdb->fail_next_event_insert = true;
$bad_event = MAD4B_SCP_Operation_Journal::begin( $failure_context, 'planned', array( 'ticket' => 'proposal' ) );
assert_journal( 'failed genesis event rolls back head atomically', is_wp_error( $bad_event )
    && 'mad4b_operation_journal_genesis_failed' === $bad_event->get_error_code()
    && null === $wpdb->head && 0 === $wpdb->inserted && 0 === $wpdb->updated && array() === $wpdb->events );
$recovered = MAD4B_SCP_Operation_Journal::begin( $failure_context, 'planned', array( 'ticket' => 'proposal' ) );
assert_journal( 'verified rollback permits clean new genesis', is_array( $recovered )
    && 1 === $wpdb->head['latest_sequence'] && 1 === count( $wpdb->events ) );
$wpdb = new FixtureJournalDB();
$wpdb->fail_next_head_update = true;
$bad_cas = MAD4B_SCP_Operation_Journal::begin( $failure_context, 'planned', array( 'ticket' => 'proposal' ) );
assert_journal( 'genesis CAS failure rolls back both rows', is_wp_error( $bad_cas )
    && null === $wpdb->head && 0 === $wpdb->inserted && 0 === $wpdb->updated && array() === $wpdb->events );
$wpdb = new FixtureJournalDB();
$wpdb->fail_next_event_insert = true;
MAD4B_SCP_Database_Transaction_Guard::$simulate_rollback_uncertain = true;
$uncertain_rollback = MAD4B_SCP_Operation_Journal::begin( $failure_context, 'planned', array( 'ticket' => 'proposal' ) );
assert_journal( 'unknown rollback refuses success and blind retry', is_wp_error( $uncertain_rollback )
    && 'mad4b_operation_journal_genesis_persistence_uncertain' === $uncertain_rollback->get_error_code()
    && true === $uncertain_rollback->get_error_data()['reconciliation_required']
    && false === $uncertain_rollback->get_error_data()['blind_retry_allowed'] );
$wpdb = new FixtureJournalDB();
MAD4B_SCP_Database_Transaction_Guard::$simulate_commit_uncertain = true;
$unknown_commit = MAD4B_SCP_Operation_Journal::begin( $failure_context, 'planned', array( 'ticket' => 'proposal' ) );
assert_journal( 'unknown commit is not a success certificate', is_wp_error( $unknown_commit )
    && 'mad4b_operation_journal_genesis_commit_uncertain' === $unknown_commit->get_error_code()
    && true === $unknown_commit->get_error_data()['reconciliation_required']
    && false === $unknown_commit->get_error_data()['blind_retry_allowed']
    && 1 === $wpdb->head['latest_sequence'] && 1 === count( $wpdb->events ) );
$duplicate = MAD4B_SCP_Operation_Journal::begin( $failure_context, 'planned', array( 'ticket' => 'proposal' ) );
assert_journal( 'uncertain commit cannot be duplicated on retry', is_wp_error( $duplicate )
    && 'mad4b_operation_journal_head_already_exists' === $duplicate->get_error_code()
    && 1 === count( $wpdb->events ) );
$wpdb = new FixtureJournalDB();
$orphan_ctx = $new_ctx;
$wpdb->head = array( 'operation_key' => $orphan_ctx['operation_key'],
    'operation_binding_sha256' => $orphan_ctx['operation_binding_sha256'],
    'latest_sequence' => 0, 'latest_event_sha256' => str_repeat( '0', 64 ) );
$zero_trace = MAD4B_SCP_Operation_Journal::trace( $orphan_ctx['operation_id'] );
assert_journal( 'orphan genesis head is never a valid complete trace', is_array( $zero_trace )
    && false === $zero_trace['chain_valid'] && false === $zero_trace['complete']
    && 0 === $zero_trace['count'] );
$wpdb = new FixtureJournalDB();
$good_trace_genesis = MAD4B_SCP_Operation_Journal::begin( $orphan_ctx, 'planned', array( 'ticket' => 'proposal' ) );
$good_trace = MAD4B_SCP_Operation_Journal::trace( $orphan_ctx['operation_id'] );
assert_journal( 'single committed genesis has a valid complete trace', is_array( $good_trace_genesis )
    && true === $good_trace['chain_valid'] && true === $good_trace['complete']
    && 1 === $good_trace['count'] );
$wpdb->head['operation_key'] = 'corrupted-head-identity';
$forged_head = MAD4B_SCP_Operation_Journal::trace( $orphan_ctx['operation_id'] );
assert_journal( 'trace detects event-to-head identity drift despite a signed chain',
    is_array( $forged_head ) && false === $forged_head['chain_valid'] && false === $forged_head['complete'] );
$wpdb->head['operation_key'] = $orphan_ctx['operation_key'];
$wpdb->events[0][4] = 'forged_genesis';
$forged_genesis = MAD4B_SCP_Operation_Journal::trace( $orphan_ctx['operation_id'] );
assert_journal( 'trace rejects a history without operation_started genesis',
    is_array( $forged_genesis ) && false === $forged_genesis['chain_valid'] );
$wpdb = new FixtureJournalDB();
$reserved = MAD4B_SCP_Operation_Journal::append( $orphan_ctx, 'operation_started' );
assert_journal( 'legacy append cannot fabricate a second genesis', is_wp_error( $reserved )
    && 'mad4b_operation_genesis_event_reserved' === $reserved->get_error_code()
    && 0 === $wpdb->inserted && 0 === $wpdb->updated );
$bounded = MAD4B_SCP_Operation_Journal::append( $orphan_ctx, 'assistant_task_transition', array(
    'expected_sequence' => 1, 'expected_event_sha256' => str_repeat( 'a', 64 ),
    'checkpoint' => str_repeat( 'c', 65 ) ) );
assert_journal( 'overlong event schema fields are rejected before storage', is_wp_error( $bounded )
    && 'mad4b_operation_event_schema_bounds' === $bounded->get_error_code()
    && 0 === $wpdb->inserted && 0 === $wpdb->updated );
$wpdb = new FixtureJournalDB();
$wpdb->head = array( 'operation_key' => $orphan_ctx['operation_key'],
    'operation_binding_sha256' => $orphan_ctx['operation_binding_sha256'],
    'latest_sequence' => 0, 'latest_event_sha256' => str_repeat( '0', 64 ) );
$orphan_append = MAD4B_SCP_Operation_Journal::append( $orphan_ctx, 'assistant_task_transition', array(
    'expected_sequence' => 0, 'expected_event_sha256' => str_repeat( '0', 64 ),
    'metadata' => array( 'task_id' => $orphan_ctx['operation_key'] ) ) );
assert_journal( 'uncommitted genesis cannot accept even correctly fenced CAS append',
    is_wp_error( $orphan_append ) && 0 === $wpdb->inserted && 0 === $wpdb->updated
    && MAD4B_SCP_Database_Transaction_Guard::$rollback > 0 );
echo 'OPERATION_JOURNAL_EXACT_CAS: PASS ' . $GLOBALS['tests'] . ' checks (hermetic DB stub)' . PHP_EOL;
