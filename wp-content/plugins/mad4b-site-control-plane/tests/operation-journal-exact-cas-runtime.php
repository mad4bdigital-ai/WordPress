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
class MAD4B_SCP_Identifiers {
    public static function operation_id_for_write( $id ) {
        return is_string( $id ) && preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D', $id ) ? $id : '';
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
    public $events = array();
    public $fail_next_event_insert = false, $fail_next_head_update = false;
    public function prepare( $sql ) { return array( $sql, array_slice( func_get_args(), 1 ) ); }
    public function get_row( $prepared, $output ) { return $this->head; }
    public function query( $prepared ) {
        $sql = $prepared[0]; $args = $prepared[1];
        if ( 0 === strpos( $sql, 'INSERT IGNORE INTO ' ) ) {
            if ( is_array( $this->head ) ) return 0;
            $this->head = array( 'operation_key' => $args[1],
                'operation_binding_sha256' => $args[2], 'latest_sequence' => 0,
                'latest_event_sha256' => str_repeat( '0', 64 ) );
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
echo 'OPERATION_JOURNAL_EXACT_CAS: PASS ' . $GLOBALS['tests'] . ' checks (hermetic DB stub)' . PHP_EOL;
