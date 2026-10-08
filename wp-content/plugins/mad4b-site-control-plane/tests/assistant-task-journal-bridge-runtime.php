<?php
/** Hermetic contract/transaction boundary test. NOT disposable database certification. */
if ( ! defined( 'ABSPATH' ) ) define( 'ABSPATH', __DIR__ );
define( 'DAY_IN_SECONDS', 86400 );
class WP_Error {
    private $code;
    public function __construct( $code, $message = '', $data = array() ) { $this->code = $code; }
    public function get_error_code() { return $this->code; }
}
function is_wp_error( $x ) { return $x instanceof WP_Error; }
$GLOBALS['assistant_allowed'] = true; $GLOBALS['assistant_staging'] = true;
function current_user_can( $cap ) { return $GLOBALS['assistant_allowed'] && 'manage_options' === $cap; }
class MAD4B_SCP_Adaptive_Operations_Context {
    public static function current() {
        return array( 'site_uuid' => '8e979fb2-64d8-4bd5-a384-95e75fbcb884',
            'environment' => $GLOBALS['assistant_staging'] ? 'staging' : 'production',
            'profile_digest' => str_repeat( 'a', 64 ), 'origin_sha256' => str_repeat( 'b', 64 ),
            'runtime_generation' => str_repeat( 'c', 64 ), 'artifact_sha256' => str_repeat( 'd', 64 ),
            'restore_epoch' => 4, 'external_record_sha256' => str_repeat( 'e', 64 ) );
    }
}
class MAD4B_SCP_Operation_Journal {
    public static $head;
    public static $events;
    public static $simulate_collision = false;
    public static function head( $id ) { return self::$head; }
    public static function trace( $id, $limit ) {
        return array( 'chain_valid' => true, 'complete' => count( self::$events ) === self::$head['latest_sequence'],
            'events' => self::$events );
    }
    public static function append( $context, $type, $args ) {
        if ( self::$simulate_collision ) {
            self::$simulate_collision = false;
            ++self::$head['latest_sequence'];
            self::$head['latest_event_sha256'] = str_repeat( '9', 64 );
            return new WP_Error( 'mad4b_operation_journal_cas_stale' );
        }
        if ( ! isset( $args['expected_sequence'], $args['expected_event_sha256'] )
            || $args['expected_sequence'] !== self::$head['latest_sequence']
            || ! hash_equals( self::$head['latest_event_sha256'], $args['expected_event_sha256'] ) ) {
            return new WP_Error( 'mad4b_operation_journal_cas_stale' );
        }
        $new_hash = hash( 'sha256', serialize( array( $type, $args, self::$head['latest_event_sha256'] ) ) );
        $event = array( 'sequence' => self::$head['latest_sequence'] + 1,
            'event_type' => $type, 'safe_metadata' => $args['metadata'],
            'event_sha256' => $new_hash );
        self::$events[] = $event;
        self::$head['latest_sequence']++;
        self::$head['latest_event_sha256'] = $new_hash;
        return array( 'journal_head_sha256' => $new_hash, 'sequence' => self::$head['latest_sequence'] );
    }
}
require dirname( __DIR__ ) . '/includes/class-mad4b-scp-assistant-task-contract.php';
require dirname( __DIR__ ) . '/includes/class-mad4b-scp-assistant-task-journal-bridge.php';
$GLOBALS['checked'] = 0;
function assert_task( $why, $predicate ) {
    ++$GLOBALS['checked'];
    if ( ! $predicate ) { fwrite( STDERR, 'FAIL ' . $why . PHP_EOL ); exit( 1 ); }
    echo 'PASS ' . $why . PHP_EOL;
}
$identity = MAD4B_SCP_Adaptive_Operations_Context::current();
$values = array();
foreach ( array( 'site_uuid', 'environment', 'profile_digest', 'origin_sha256', 'runtime_generation',
    'artifact_sha256', 'restore_epoch', 'external_record_sha256' ) as $k ) $values[] = $identity[ $k ];
$bound = hash( 'sha256', serialize( $values ) );
$record = array( 'contract' => MAD4B_SCP_Assistant_Task_Contract::CONTRACT,
    'task_id' => 'proposal-' . str_repeat( '1', 32 ), 'plan_sha256' => str_repeat( '2', 64 ),
    'binding_sha256' => $bound, 'revision' => 1, 'state' => 'proposed',
    'last_event_sha256' => str_repeat( '0', 64 ) );
$context = array( 'operation_id' => '4fcd585e-ac71-46f2-91ce-8bd69ad9a621',
    'operation_key' => $record['task_id'], 'operation_binding_sha256' => $bound,
    'hard_deadline_at' => gmdate( 'c', time() + 3600 ) );
MAD4B_SCP_Operation_Journal::$head = array( 'operation_key' => $context['operation_key'],
    'operation_binding_sha256' => $bound, 'latest_sequence' => 1,
    'latest_event_sha256' => str_repeat( 'f', 64 ) );
MAD4B_SCP_Operation_Journal::$events = array( array( 'sequence' => 1,
    'event_type' => 'operation_started', 'safe_metadata' => array(),
    'event_sha256' => str_repeat( 'f', 64 ) ) );
$GLOBALS['assistant_allowed'] = false;
assert_task( 'anonymous cannot initialize a ticket', is_wp_error( MAD4B_SCP_Assistant_Task_Journal_Bridge::initialize( $context, $record ) ) );
$GLOBALS['assistant_allowed'] = true;
$bad = $record; $bad['state'] = 'authority_pending';
assert_task( 'cannot manufacture an authorized initial state', is_wp_error( MAD4B_SCP_Assistant_Task_Journal_Bridge::initialize( $context, $bad ) ) );
$opened = MAD4B_SCP_Assistant_Task_Journal_Bridge::initialize( $context, $record );
assert_task( 'initial ticket is persisted but not executable', is_array( $opened )
    && 'PERSISTED_JOURNAL_CAS' === $opened['persistence_status'] && ! $opened['authorizing'] && ! $opened['executable']
    && 2 === MAD4B_SCP_Operation_Journal::$head['latest_sequence'] );
assert_task( 'same operation cannot initialize again', is_wp_error( MAD4B_SCP_Assistant_Task_Journal_Bridge::initialize( $context, $record ) ) );
$read = MAD4B_SCP_Assistant_Task_Journal_Bridge::read( $context );
assert_task( 'authoritative journal readback matches record', is_array( $read )
    && $record === $read['record'] && $read['chain_valid'] && $read['read_only'] );
$request = array( 'expected_revision' => 1, 'expected_last_event_sha256' => $record['last_event_sha256'],
    'next_state' => 'evidence_pending', 'reason_code' => 'needs_native_evidence' );
$next = MAD4B_SCP_Assistant_Task_Journal_Bridge::transition( $context, $record, $request );
assert_task( 'CAS transition stored and remains non-authorizing', is_array( $next )
    && 2 === $next['record']['revision'] && 'evidence_pending' === $next['record']['state']
    && ! $next['executable'] && ! $next['provider_entry_allowed'] );
assert_task( 'stale client record rejected', is_wp_error( MAD4B_SCP_Assistant_Task_Journal_Bridge::transition( $context, $record, $request ) ) );
$GLOBALS['assistant_staging'] = false;
assert_task( 'Production cannot mutate ticket', is_wp_error( MAD4B_SCP_Assistant_Task_Journal_Bridge::transition( $context, $next['record'],
    array( 'expected_revision' => 2, 'expected_last_event_sha256' => $next['record']['last_event_sha256'],
        'next_state' => 'review_pending', 'reason_code' => 'review_required' ) ) ) );
$GLOBALS['assistant_staging'] = true;
$stale_context = $context; $stale_context['operation_binding_sha256'] = str_repeat( '3', 64 );
assert_task( 'changed runtime binding rejected', is_wp_error( MAD4B_SCP_Assistant_Task_Journal_Bridge::read( $stale_context ) ) );
$next_req = array( 'expected_revision' => 2, 'expected_last_event_sha256' => $next['record']['last_event_sha256'],
    'next_state' => 'review_pending', 'reason_code' => 'review_required' );
MAD4B_SCP_Operation_Journal::$simulate_collision = true;
assert_task( 'concurrent journal CAS race is denied without success receipt', is_wp_error(
    MAD4B_SCP_Assistant_Task_Journal_Bridge::transition( $context, $next['record'], $next_req ) ) );
assert_task( 'journal no longer falsely complete following injected concurrency', is_wp_error(
    MAD4B_SCP_Assistant_Task_Journal_Bridge::read( $context ) ) );
echo 'ASSISTANT_TASK_JOURNAL_BRIDGE: PASS ' . $GLOBALS['checked'] . ' checks (hermetic only)' . PHP_EOL;
