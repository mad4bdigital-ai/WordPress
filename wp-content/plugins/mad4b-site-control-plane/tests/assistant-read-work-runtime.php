<?php
/** Hermetic adapter payload constraints; no worker or database is launched. */
if ( ! defined( 'ABSPATH' ) ) define( 'ABSPATH', __DIR__ );
require dirname( __DIR__ ) . '/includes/class-mad4b-scp-assistant-read-work-operations.php';
$GLOBALS['asserts'] = 0;
function check_case( $n, $pass ) { ++$GLOBALS['asserts']; if ( ! $pass ) { fwrite( STDERR, 'FAIL ' . $n . PHP_EOL ); exit( 1 ); } echo 'PASS ' . $n . PHP_EOL; }
$p = array( 'contract' => MAD4B_SCP_Assistant_Read_Work_Operations::CONTRACT,
    'task_id' => 'proposal-' . str_repeat( 'a', 32 ),
    'plan_sha256' => str_repeat( 'b', 64 ), 'binding_sha256' => str_repeat( 'c', 64 ),
    'capabilities' => array( 'seo.rankmath' ), 'provider_ids' => array( 'rankmath' ),
    'purpose' => 'catalog_read' );
check_case( 'read contract', MAD4B_SCP_Assistant_Read_Work_Operations::validate_payload( $p ) );
$d = MAD4B_SCP_Assistant_Read_Work_Operations::definitions( array() );
check_case( 'three semantic operations', 3 === count( $d ) );
foreach ( $d as $id => $settings ) {
    check_case( 'read-only no credentials ' . $id, true === $settings['read_only']
        && false === $settings['credential_access_allowed'] && false === $settings['plugin_lifecycle_allowed'] );
    check_case( 'production denied ' . $id, 'deny' === $settings['production_policy'] );
    check_case( 'schema applies ' . $id, call_user_func( $settings['validate_payload'], $p ) );
}
check_case( 'existing operation is never overwritten', 'existing' ===
    MAD4B_SCP_Assistant_Read_Work_Operations::definitions( array(
        'assistant_provider_catalog_snapshot' => array( 'marker' => 'existing' ) ) )['assistant_provider_catalog_snapshot']['marker'] );
foreach ( array( 'url', 'sql', 'command', 'php', 'approval', 'install', 'secret' ) as $key ) {
    $bad = $p; $bad[ $key ] = 'unsafe';
    check_case( 'unknown ' . $key . ' rejected', ! MAD4B_SCP_Assistant_Read_Work_Operations::validate_payload( $bad ) );
}
$bad = $p; $bad['purpose'] = 'plugin_install';
check_case( 'installation purpose refused', ! MAD4B_SCP_Assistant_Read_Work_Operations::validate_payload( $bad ) );
$bad = $p; $bad['capabilities'] = array( 'seo.rankmath', 'seo.rankmath' );
check_case( 'duplicate capability refused', ! MAD4B_SCP_Assistant_Read_Work_Operations::validate_payload( $bad ) );
$bad = $p; $bad['capabilities'] = array( new stdClass() );
check_case( 'objects refused', ! MAD4B_SCP_Assistant_Read_Work_Operations::validate_payload( $bad ) );
$bad = $p; $bad['plan_sha256'] = 'invalid';
check_case( 'stale/invalid SHA refused', ! MAD4B_SCP_Assistant_Read_Work_Operations::validate_payload( $bad ) );
$bad = $p; $bad['task_id'] = 'proposal-../../../';
check_case( 'path-like task ID refused', ! MAD4B_SCP_Assistant_Read_Work_Operations::validate_payload( $bad ) );
$bad = $p; $bad['provider_ids'] = array( 'provider/path' );
check_case( 'unsafe provider ID refused', ! MAD4B_SCP_Assistant_Read_Work_Operations::validate_payload( $bad ) );
$bad = $p; $bad['capabilities'] = array();
check_case( 'empty capability target refused', ! MAD4B_SCP_Assistant_Read_Work_Operations::validate_payload( $bad ) );
$bad = $p; $bad['contract'] = 'mad4b.fake';
check_case( 'foreign contract refused', ! MAD4B_SCP_Assistant_Read_Work_Operations::validate_payload( $bad ) );
function is_wp_error( $x ) { return $x instanceof WP_Error; }
class WP_Error {
    private $code;
    public function __construct( $code = '', $message = '', $data = array() ) { $this->code = $code; }
    public function get_error_code() { return $this->code; }
}
define( 'DAY_IN_SECONDS', 86400 );
function sanitize_key( $v ) { return strtolower( preg_replace( '/[^a-z0-9_\-]/i', '', (string) $v ) ); }
function wp_json_encode( $v, $flags = 0 ) { return json_encode( $v, $flags ); }
function maybe_serialize( $v ) { return is_array( $v ) || is_object( $v ) ? serialize( $v ) : (string) $v; }
function wp_cache_delete( $k, $g = '' ) { return true; }
$GLOBALS['opts'] = array(); $GLOBALS['uuid_counter'] = 0;
function get_option( $k, $d = false ) { return array_key_exists( $k, $GLOBALS['opts'] ) ? $GLOBALS['opts'][ $k ] : $d; }
function update_option( $k, $v, $autoload = false ) { $GLOBALS['opts'][ $k ] = $v; return true; }
function add_option( $k, $v, $deprecated = '', $autoload = false ) {
    if ( array_key_exists( $k, $GLOBALS['opts'] ) ) return false;
    $GLOBALS['opts'][ $k ] = $v; return true;
}
function wp_generate_uuid4() { return sprintf( '00000000-0000-4000-8000-%012d', ++$GLOBALS['uuid_counter'] ); }
function wp_rand() { return 12345; }
class Assistant_Read_Memory_DB {
    public $options = 'wp_options';
    public function delete( $table, $where, $formats ) {
        $key = $where['option_name'];
        if ( ! array_key_exists( $key, $GLOBALS['opts'] )
            || maybe_serialize( $GLOBALS['opts'][ $key ] ) !== $where['option_value'] ) return 0;
        unset( $GLOBALS['opts'][ $key ] ); return 1;
    }
}
$GLOBALS['wpdb'] = new Assistant_Read_Memory_DB();
class MAD4B_SCP_Site_Profile {
    public static $configured = true;
    public static function configured() { return self::$configured; }
    public static function origin_enrolled() { return true; }
    public static function site_urls_match_enrollment() { return true; }
    public static function site_uuid() { return $GLOBALS['context']['site_uuid']; }
    public static function current_environment() { return $GLOBALS['context']['environment']; }
    public static function profile_digest() { return $GLOBALS['context']['profile_digest']; }
    public static function current_origin() { return $GLOBALS['context']['origin']; }
}
class MAD4B_SCP_Runtime_Generation_Fence {
    public static function capture() { return array( 'generation_sha256' => $GLOBALS['context']['runtime_generation'] ); }
}
class MAD4B_SCP_Live_Acceptance_Observer {
    public static function build_provenance_status() { return array( 'runtime_manifest_match' => true,
        'package_manifest_digest' => $GLOBALS['context']['artifact_sha256'] ); }
}
class MAD4B_SCP_Restore_Epoch {
    public static function status( $a, $b ) { return array( 'ready' => true, 'epoch' => $GLOBALS['context']['restore_epoch'],
        'external_record_sha256' => $GLOBALS['context']['external_record_sha256'] ); }
}
require dirname( __DIR__ ) . '/includes/class-mad4b-scp-adaptive-operations-context.php';
require dirname( __DIR__ ) . '/includes/class-mad4b-scp-remote-work-queue.php';
$base = array( 'site_uuid' => '49c562d1-8f2f-456f-b454-26816c6ba4cb',
    'environment' => 'staging', 'profile_digest' => str_repeat( '1', 64 ),
    'origin' => 'https://staging.example.invalid', 'runtime_generation' => str_repeat( '3', 64 ),
    'artifact_sha256' => str_repeat( '4', 64 ), 'restore_epoch' => 2, 'external_record_sha256' => str_repeat( '5', 64 ) );
$GLOBALS['context'] = $base;
function current_payload( $template ) {
    $current = MAD4B_SCP_Adaptive_Operations_Context::current();
    if ( is_wp_error( $current ) ) return $current;
    $exact = array();
    foreach ( array( 'site_uuid', 'environment', 'profile_digest', 'origin_sha256',
        'runtime_generation', 'artifact_sha256', 'restore_epoch', 'external_record_sha256' ) as $key ) $exact[] = $current[ $key ];
    $template['binding_sha256'] = hash( 'sha256', serialize( $exact ) );
    return $template;
}
$payload = $p;
$payload = current_payload( $payload );
check_case( 'Staging runtime binding matches', MAD4B_SCP_Assistant_Read_Work_Operations::runtime_binding_matches( $payload ) );
check_case( 'correct purpose matches operation',
    MAD4B_SCP_Assistant_Read_Work_Operations::validate_for_operation( 'assistant_provider_catalog_snapshot', $payload ) );
check_case( 'wrong operation purpose rejected',
    ! MAD4B_SCP_Assistant_Read_Work_Operations::validate_for_operation( 'assistant_configuration_diff', $payload ) );
$stale = $payload; $stale['binding_sha256'] = str_repeat( 'a', 64 );
check_case( 'changed binding rejected', ! MAD4B_SCP_Assistant_Read_Work_Operations::runtime_binding_matches( $stale ) );
$GLOBALS['context']['environment'] = 'production';
check_case( 'Production never allowed', ! MAD4B_SCP_Assistant_Read_Work_Operations::runtime_binding_matches( $payload ) );
MAD4B_SCP_Site_Profile::$configured = false;
check_case( 'Runtime diagnostic error denies enqueue',
    ! MAD4B_SCP_Assistant_Read_Work_Operations::runtime_binding_matches( $payload ) );
MAD4B_SCP_Site_Profile::$configured = true;
$GLOBALS['context'] = $base;
$identity = array( 'source_commit_sha' => str_repeat( 'a', 40 ), 'build_fingerprint' => str_repeat( 'b', 64 ),
    'package_manifest_digest' => str_repeat( '4', 64 ) );
$operation = 'assistant_provider_catalog_snapshot';
$queued = MAD4B_SCP_Remote_Work_Queue::enqueue( $operation, $payload, $identity );
check_case( 'registered assistant operation actually enqueues', is_array( $queued ) && 'queued' === $queued['state'] );
$wrong = $identity; $wrong['package_manifest_digest'] = str_repeat( '9', 64 );
check_case( 'runtime artifact and expected manifest must match at enqueue',
    is_wp_error( MAD4B_SCP_Remote_Work_Queue::enqueue( $operation, $payload, $wrong ) ) );
check_case( 'queue rejects operation purpose substitution',
    is_wp_error( MAD4B_SCP_Remote_Work_Queue::enqueue( 'assistant_configuration_diff', $payload, $identity ) ) );
$extra = $payload; $extra['command'] = 'unsafe';
check_case( 'queue rejects executable payload fields',
    is_wp_error( MAD4B_SCP_Remote_Work_Queue::enqueue( $operation, $extra, $identity ) ) );
foreach ( array( 'restore_epoch' => 3, 'runtime_generation' => str_repeat( '6', 64 ),
    'origin' => 'https://other.example.invalid', 'site_uuid' => '59c562d1-8f2f-456f-b454-26816c6ba4cb',
    'profile_digest' => str_repeat( '7', 64 ), 'external_record_sha256' => str_repeat( '8', 64 ),
    'environment' => 'production' ) as $field => $value ) {
    $GLOBALS['opts'] = array(); $GLOBALS['context'] = $base;
    $queued = MAD4B_SCP_Remote_Work_Queue::enqueue( $operation, $payload, $identity );
    $job_id = $queued['job']['job_id'];
    $GLOBALS['context'][ $field ] = $value;
    $before = get_option( MAD4B_SCP_Remote_Work_Queue::OPTION );
    check_case( 'stale ' . $field . ' denied before claim',
        is_wp_error( MAD4B_SCP_Remote_Work_Queue::claim( $job_id, 'fixture-agent', 60, $identity ) ) );
    check_case( 'claim denial preserves pending record ' . $field,
        $before === get_option( MAD4B_SCP_Remote_Work_Queue::OPTION ) );
    check_case( 'pending stale work remains cancellable ' . $field,
        'cancelled_no_effect' === MAD4B_SCP_Remote_Work_Queue::cancel( $job_id )['state'] );
    $GLOBALS['opts'] = array(); $GLOBALS['context'] = $base;
    $queued = MAD4B_SCP_Remote_Work_Queue::enqueue( $operation, $payload, $identity );
    $job_id = $queued['job']['job_id'];
    $claimed = MAD4B_SCP_Remote_Work_Queue::claim( $job_id, 'fixture-agent', 60, $identity );
    check_case( 'current binding can claim ' . $field, is_array( $claimed ) && 'claimed' === $claimed['state'] );
    $GLOBALS['context'][ $field ] = $value;
    $before = get_option( MAD4B_SCP_Remote_Work_Queue::OPTION );
    check_case( 'stale ' . $field . ' denied before provider entry',
        is_wp_error( MAD4B_SCP_Remote_Work_Queue::provider_checkpoint( $job_id, 'fixture-agent', $claimed['lease_token'], 'provider_entered' ) ) );
    check_case( 'entry denial preserves not-entered record ' . $field,
        $before === get_option( MAD4B_SCP_Remote_Work_Queue::OPTION ) );
    $cancel = MAD4B_SCP_Remote_Work_Queue::cancel( $job_id );
    check_case( 'claimed stale work can request cancellation ' . $field, 'reconciling' === $cancel['state'] );
    $ack = MAD4B_SCP_Remote_Work_Queue::acknowledge_cancellation( $job_id, 'fixture-agent',
        $claimed['lease_token'], $cancel['job']['cancel_generation'] );
    check_case( 'not-entered stale work can acknowledge no-effect ' . $field,
        is_array( $ack ) && 'cancelled_no_effect' === $ack['state'] );
}
$GLOBALS['opts'] = array(); $GLOBALS['context'] = $base;
$queued = MAD4B_SCP_Remote_Work_Queue::enqueue( $operation, $payload, $identity );
$job_id = $queued['job']['job_id'];
$claimed = MAD4B_SCP_Remote_Work_Queue::claim( $job_id, 'fixture-agent', 60, $identity );
check_case( 'current binding admits provider entry', is_array( MAD4B_SCP_Remote_Work_Queue::provider_checkpoint(
    $job_id, 'fixture-agent', $claimed['lease_token'], 'provider_entered' ) ) );
$GLOBALS['context']['restore_epoch'] = 3;
check_case( 'provider return remains recordable across stale binding', is_array( MAD4B_SCP_Remote_Work_Queue::provider_checkpoint(
    $job_id, 'fixture-agent', $claimed['lease_token'], 'provider_returned' ) ) );
$cancel = MAD4B_SCP_Remote_Work_Queue::cancel( $job_id );
$proof = array( 'postcondition_verified' => true, 'provider_effect_state' => 'no_effect',
    'provider_execution_ref' => 'fixture:read-only:1', 'evidence_sha256' => str_repeat( 'e', 64 ) );
$completed = MAD4B_SCP_Remote_Work_Queue::complete( $job_id, 'fixture-agent', $claimed['lease_token'], $proof );
check_case( 'entered stale work retains explicit reconciliation', is_array( $completed ) && 'cancelled_no_effect' === $completed['state'] );
echo 'ASSISTANT_READ_WORK: PASS ' . $GLOBALS['asserts'] . ' checks' . PHP_EOL;
