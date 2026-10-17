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
function wp_json_encode( $data, $flags = 0 ) { return json_encode( $data, $flags ); }
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
    public static function begin( $context, $lifecycle, $claim ) {
        if ( is_array( self::$head ) ) return new WP_Error( 'mad4b_operation_journal_head_already_exists' );
        $claim_json = wp_json_encode( $claim, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
        self::$head = array( 'operation_key' => $context['operation_key'],
            'operation_binding_sha256' => $context['operation_binding_sha256'],
            'latest_sequence' => 1, 'latest_event_sha256' => str_repeat( 'f', 64 ),
            'hard_deadline_at' => gmdate( 'Y-m-d H:i:s', strtotime( $context['hard_deadline_at'] ) ) );
        self::$events = array( array( 'sequence' => 1, 'event_type' => 'operation_started',
            'checkpoint' => 'planned', 'lifecycle_state' => 'planned',
            'safe_metadata' => array( 'metadata' => array( 'sha256' => hash( 'sha256', $claim_json ),
                'length' => strlen( $claim_json ) ) ),
            'event_sha256' => str_repeat( 'f', 64 ) ) );
        return array( 'sequence' => 1 );
    }
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
            'event_type' => $type, 'checkpoint' => $args['checkpoint'],
            'lifecycle_state' => $args['lifecycle_state'], 'safe_metadata' => $args['metadata'],
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
    'latest_event_sha256' => str_repeat( 'f', 64 ),
    'hard_deadline_at' => gmdate( 'Y-m-d H:i:s', strtotime( $context['hard_deadline_at'] ) ) );
$genesis_json = wp_json_encode( MAD4B_SCP_Assistant_Task_Journal_Bridge::genesis_claim( $record ),
    JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
MAD4B_SCP_Operation_Journal::$events = array( array( 'sequence' => 1,
    'event_type' => 'operation_started', 'checkpoint' => 'planned',
    'lifecycle_state' => 'planned',
    'safe_metadata' => array( 'metadata' => array( 'sha256' => hash( 'sha256', $genesis_json ),
        'length' => strlen( $genesis_json ) ) ),
    'event_sha256' => str_repeat( 'f', 64 ) ) );
$original_genesis = MAD4B_SCP_Operation_Journal::$events[0];
MAD4B_SCP_Operation_Journal::$events[0]['safe_metadata']['metadata']['sha256'] = str_repeat( '8', 64 );
assert_task( 'forged journal genesis cannot initialize', is_wp_error(
    MAD4B_SCP_Assistant_Task_Journal_Bridge::initialize( $context, $record ) ) );
MAD4B_SCP_Operation_Journal::$events[0] = $original_genesis;
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
MAD4B_SCP_Operation_Journal::$events[0]['safe_metadata']['metadata']['sha256'] = str_repeat( '8', 64 );
assert_task( 'journal genesis remains anchored after subsequent events', is_wp_error(
    MAD4B_SCP_Assistant_Task_Journal_Bridge::read( $context ) ) );
MAD4B_SCP_Operation_Journal::$events[0] = $original_genesis;
$request = array( 'expected_revision' => 1, 'expected_last_event_sha256' => $record['last_event_sha256'],
    'next_state' => 'evidence_pending', 'reason_code' => 'needs_native_evidence' );
$next = MAD4B_SCP_Assistant_Task_Journal_Bridge::transition( $context, $record, $request );
assert_task( 'CAS transition stored and remains non-authorizing', is_array( $next )
    && 2 === $next['record']['revision'] && 'evidence_pending' === $next['record']['state']
    && ! $next['executable'] && ! $next['provider_entry_allowed'] );
assert_task( 'stale client record rejected', is_wp_error( MAD4B_SCP_Assistant_Task_Journal_Bridge::transition( $context, $record, $request ) ) );
$real_transition = MAD4B_SCP_Operation_Journal::$events[2];
MAD4B_SCP_Operation_Journal::$events[2]['safe_metadata']['reason_code'] = 'rewritten_reason';
assert_task( 'valid hash-chain hint alone cannot bypass semantic task replay', is_wp_error(
    MAD4B_SCP_Assistant_Task_Journal_Bridge::read( $context ) ) );
MAD4B_SCP_Operation_Journal::$events[2] = $real_transition;
assert_task( 'original transition restores consistent replay', is_array(
    MAD4B_SCP_Assistant_Task_Journal_Bridge::read( $context ) ) );
$GLOBALS['assistant_staging'] = false;
assert_task( 'Production cannot mutate ticket', is_wp_error( MAD4B_SCP_Assistant_Task_Journal_Bridge::transition( $context, $next['record'],
    array( 'expected_revision' => 2, 'expected_last_event_sha256' => $next['record']['last_event_sha256'],
        'next_state' => 'review_pending', 'reason_code' => 'review_required' ) ) ) );
$GLOBALS['assistant_staging'] = true;
$extended_deadline = $context;
$extended_deadline['hard_deadline_at'] = gmdate( 'c', time() + 7200 );
assert_task( 'caller cannot extend immutable journal deadline', is_wp_error(
    MAD4B_SCP_Assistant_Task_Journal_Bridge::read( $extended_deadline ) ) );
$stale_context = $context; $stale_context['operation_binding_sha256'] = str_repeat( '3', 64 );
assert_task( 'changed runtime binding rejected', is_wp_error( MAD4B_SCP_Assistant_Task_Journal_Bridge::read( $stale_context ) ) );
$next_req = array( 'expected_revision' => 2, 'expected_last_event_sha256' => $next['record']['last_event_sha256'],
    'next_state' => 'review_pending', 'reason_code' => 'review_required' );
MAD4B_SCP_Operation_Journal::$simulate_collision = true;
assert_task( 'concurrent journal CAS race is denied without success receipt', is_wp_error(
    MAD4B_SCP_Assistant_Task_Journal_Bridge::transition( $context, $next['record'], $next_req ) ) );
assert_task( 'journal no longer falsely complete following injected concurrency', is_wp_error(
    MAD4B_SCP_Assistant_Task_Journal_Bridge::read( $context ) ) );
MAD4B_SCP_Operation_Journal::$head = null;
$review_record = $record; $review_record['task_id'] = 'proposal-' . str_repeat( '4', 32 );
$review_context = $context;
$review_context['operation_id'] = '86d542eb-f29b-4f64-9c2d-45ca52817cb5';
$review_context['operation_key'] = $review_record['task_id'];
$forged = $review_record; $forged['state'] = 'authority_pending';
assert_task( 'review-ticket opener refuses invalid state before writing genesis', is_wp_error(
    MAD4B_SCP_Assistant_Task_Journal_Bridge::open_review_ticket( $review_context, $forged ) )
    && null === MAD4B_SCP_Operation_Journal::$head );
$started = MAD4B_SCP_Assistant_Task_Journal_Bridge::open_review_ticket( $review_context, $review_record );
assert_task( 'review opener creates genesis and durable nonexecutable proposal', is_array( $started )
    && 'PERSISTED_JOURNAL_CAS' === $started['persistence_status']
    && ! $started['executable'] && ! $started['authorizing']
    && 2 === MAD4B_SCP_Operation_Journal::$head['latest_sequence'] );
assert_task( 'review opener refuses duplicate genesis on retry', is_wp_error(
    MAD4B_SCP_Assistant_Task_Journal_Bridge::open_review_ticket( $review_context, $review_record ) ) );
echo 'ASSISTANT_TASK_JOURNAL_BRIDGE: PASS ' . $GLOBALS['checked'] . ' checks (hermetic only)' . PHP_EOL;
