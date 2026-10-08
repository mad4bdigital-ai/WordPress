<?php
/* Strict hermetic fleet reducer: no WP server or real fleet access. */
define( 'ABSPATH', __DIR__ . '/' );
class WP_Error {
    private $code;
    public function __construct( $code, $message, $data = null ) { $this->code = $code; }
    public function get_error_code() { return $this->code; }
}
function is_wp_error( $v ) { return $v instanceof WP_Error; }
function g9_assert( $condition, $message ) {
    if ( ! $condition ) { fwrite( STDERR, "G9 fleet FAIL: $message\n" ); exit( 1 ); }
}
function g9_error( $v, $reason ) {
    g9_assert( is_wp_error( $v ) && false !== strpos( $v->get_error_code(), $reason ), $reason );
}
require_once __DIR__ . '/../includes/class-mad4b-scp-g9-fleet-rollout.php';
function binding( $uuid, $origin, $artifact ) {
    $hash = str_repeat( 'a', 64 );
    return array( 'site_uuid'=>$uuid, 'blog_id'=>1, 'environment'=>'staging',
        'canonical_origin'=>$origin, 'runtime_generation_sha256'=>$hash,
        'artifact_sha256'=>$artifact, 'site_profile_revision'=>1,
        'site_profile_sha256'=>$hash, 'registry_revision'=>0,
        'registry_sha256'=>$hash, 'restore_epoch'=>1,
        'external_record_sha256'=>$hash );
}
function observation( $binding ) {
    $b = MAD4B_SCP_Resilience_Context::digest( $binding );
    $s = array(
        'contract'=>MAD4B_SCP_Resilience_Context::CONTRACT,
        'binding'=>$binding, 'binding_sha256'=>$b,
        'authority'=>array( 'site_uuid'=>$binding['site_uuid'],
            'environment'=>$binding['environment'],
            'runtime_generation_sha256'=>$binding['runtime_generation_sha256'],
            'restore_epoch'=>$binding['restore_epoch'] ),
        'facets'=>array( 'registry'=>$binding['registry_sha256'] ),
        'authorizing'=>false, 'mutation_performed'=>false,
    );
    $s['snapshot_sha256'] = MAD4B_SCP_Resilience_Context::snapshot_digest( $s );
    return $s;
}
$hash = str_repeat( 'a', 64 );
$a = observation( binding( '11111111-1111-4111-8111-111111111111',
    'https://one.invalid', $hash ) );
$b = observation( binding( '22222222-2222-4222-8222-222222222222',
    'https://two.invalid', $hash ) );
$aKey = MAD4B_SCP_Resilience_Context::site_key( $a['binding'] );
$bKey = MAD4B_SCP_Resilience_Context::site_key( $b['binding'] );
$event = array(
    'contract'=>MAD4B_SCP_G9_Fleet_Rollout::CONTRACT,
    'site_key'=>$aKey, 'binding_sha256'=>$a['binding_sha256'],
    'sequence'=>1, 'operation_sha256'=>$hash, 'action'=>'promote',
    'state'=>'COMMITTED',
);
$result = MAD4B_SCP_G9_Fleet_Rollout::inspect( array( $a, $b ), array( $event ) );
g9_assert( ! is_wp_error( $result ) && count( $result['missing_site_evidence'] )===1
    && !$result['release_acceptance_verified'] && !$result['cohort_promotion_allowed'],
    'remote reported COMMITTED cannot grant cohort acceptance' );
$rollback = $event;
$rollback['site_key'] = $bKey;
$rollback['binding_sha256'] = $b['binding_sha256'];
$rollback['action'] = 'rollback';
$rollback['state'] = 'RECONCILING';
$rollback['operation_sha256'] = str_repeat( 'b', 64 );
$result = MAD4B_SCP_G9_Fleet_Rollout::inspect( array( $a, $b ), array( $event, $rollback ) );
g9_assert( ! is_wp_error( $result ) && $result['partial_rollback_observed']
    && count( $result['uncertain_site_evidence'] )===1
    && !$result['automatic_rollback_allowed'], 'mixed fleet rollback uncertainty' );
$unknown = $event; $unknown['site_key'] = 'foreign:abc';
g9_error( MAD4B_SCP_G9_Fleet_Rollout::inspect( array( $a, $b ), array( $unknown ) ), 'event_invalid' );
$gap = $event; $gap['sequence'] = 3;
g9_error( MAD4B_SCP_G9_Fleet_Rollout::inspect( array( $a, $b ), array( $gap ) ), 'event_gap_or_replay' );
$conflict = $event; $conflict['sequence']=2; $conflict['action']='rollback';
g9_error( MAD4B_SCP_G9_Fleet_Rollout::inspect( array( $a, $b ), array( $event, $conflict ) ), 'operation_action_conflict' );
$forged = $event; $forged['binding_sha256'] = str_repeat( 'c', 64 );
g9_error( MAD4B_SCP_G9_Fleet_Rollout::inspect( array( $a, $b ), array( $forged ) ), 'event_invalid' );
g9_error( MAD4B_SCP_G9_Fleet_Rollout::inspect( array( $a, $a ), array() ), 'duplicate_site' );
echo "G9 fleet reducer: PASS (claimed commit, partial rollback, foreign, gaps, replay, clone)\n";
