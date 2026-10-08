<?php
/* G9 hermetic runtime contract: no WordPress boot, live host or network required. */
define( 'ABSPATH', __DIR__ . '/' );
$_SERVER['DOCUMENT_ROOT'] = __DIR__;
class WP_Error {
    private $code; private $message;
    public function __construct( $code, $message, $data = null ) { $this->code = $code; $this->message = $message; }
    public function get_error_code() { return $this->code; }
    public function get_error_message() { return $this->message; }
}
function is_wp_error( $v ) { return $v instanceof WP_Error; }
$GLOBALS['g9_options'] = array();
function get_option( $k, $default = false ) { return $GLOBALS['g9_options'][ $k ] ?? $default; }
function update_option( $k, $v, $autoload = false ) { $GLOBALS['g9_options'][ $k ] = $v; return true; }
$dir = sys_get_temp_dir() . '/mad4b-g9-' . bin2hex( random_bytes( 8 ) );
define( 'MAD4B_SCP_RESILIENCE_ANCHOR_DIRECTORY', $dir );
require_once __DIR__ . '/../includes/class-mad4b-scp-g9-resilience-gates.php';
function g9_check( $condition, $msg ) { if ( ! $condition ) { fwrite( STDERR, "G9 FAIL: $msg\n" ); exit( 1 ); } }
function g9_error( $v, $suffix ) {
    g9_check( is_wp_error( $v ) && false !== strpos( $v->get_error_code(), $suffix ), $suffix );
}
$hash = str_repeat( 'a', 64 );
$binding = array(
    'site_uuid' => '11111111-1111-4111-8111-111111111111', 'blog_id' => 1,
    'environment' => 'staging', 'canonical_origin' => 'https://staging.example.invalid',
    'runtime_generation_sha256' => $hash, 'artifact_sha256' => $hash,
    'site_profile_revision' => 1, 'site_profile_sha256' => $hash,
    'registry_revision' => 0, 'registry_sha256' => $hash,
    'restore_epoch' => 1, 'external_record_sha256' => $hash,
);
$bad = $binding; $bad['canonical_origin'] = 'https://oneuser@staging.example.invalid';
g9_error( MAD4B_SCP_Resilience_Context::validate_binding( $bad ), 'origin_invalid' );
$bad = $binding; $bad['restore_epoch'] = '1';
g9_error( MAD4B_SCP_Resilience_Context::validate_binding( $bad ), 'binding_type_invalid' );
$initial = MAD4B_SCP_Resilience_Anchor::read( $binding );
g9_check( ! is_wp_error( $initial ) && 0 === $initial['revision'] && ! is_dir( $dir ),
    'first read does not require or create external directory' );
g9_error( MAD4B_SCP_Resilience_Anchor::transact( $binding, '0', function ( $current ) { return $current; } ), 'revision_conflict' );
$anchor = MAD4B_SCP_Resilience_Anchor::transact( $binding, 0, function ( $current ) {
    $current['scopes']['ring:pilot'] = array( 'fenced' => true ); return $current;
});
g9_check( ! is_wp_error( $anchor ) && $anchor['revision'] === 1, 'committed anchor' );
g9_error( MAD4B_SCP_Resilience_Anchor::transact( $binding, 0, function ( $current ) { return $current; } ), 'revision_conflict' );
g9_error( MAD4B_SCP_Resilience_Anchor::transact( $binding, 1, function ( $current ) { $current['scopes'] = array(); return $current; } ), 'history_truncation' );
g9_error( MAD4B_SCP_Resilience_Anchor::transact( $binding, 1, function ( $current ) {
    $current['scopes']['ring:pilot']['fenced'] = false; return $current;
} ), 'history_truncation' );
$snapshot = array(
    'contract' => MAD4B_SCP_Resilience_Context::CONTRACT, 'binding' => $binding,
    'binding_sha256' => MAD4B_SCP_Resilience_Context::digest( $binding ),
    'captured_at' => MAD4B_SCP_Resilience_Context::now(),
    'authority' => array( 'site_uuid'=>$binding['site_uuid'], 'environment'=>'staging',
        'runtime_generation_sha256'=>$hash, 'restore_epoch'=>1,
        'grant_snapshot_sha256'=>$hash, 'eligible'=>true ),
    'identity_blockers'=>array(), 'worker_current'=>true, 'restore_bound'=>true,
    'providers'=>array( 'core'=>array(
        'ready'=>true, 'revoked'=>false,
        'site_key'=>MAD4B_SCP_Resilience_Context::site_key( $binding ),
        'generation_sha256'=>$hash, 'certification_sha256'=>$hash ) ),
    'host'=>array( 'isolation_verified'=>true, 'local_readback_verified'=>true,
        'single_host_exclusive_verified'=>true ),
    'gates'=>array( 'prior_ring_health_accepted'=>false,
        'provider_inventory_complete'=>true, 'host_inventory_complete'=>true,
        'external_effect_inventory_complete'=>true, 'health_sample_window_complete'=>true ),
    'external_effects'=>array(),
    'health'=>array( 'sample_count'=>100, 'error_rate_bps'=>20,
        'p95_ms'=>120, 'observed_at'=>MAD4B_SCP_Resilience_Context::now() ),
    'facets'=>array( 'database'=>$hash, 'files'=>$hash, 'runtime_package'=>$hash,
        'site_profile'=>$hash, 'registry'=>$hash ),
);
$snapshot['snapshot_sha256'] = MAD4B_SCP_Resilience_Context::snapshot_digest( $snapshot );
$key = MAD4B_SCP_Resilience_Context::site_key( $binding );
$target = array( 'contract'=>MAD4B_SCP_G9_Resilience_Gates::RING_CONTRACT, 'ring'=>'pilot',
    'cohort_id'=>'pilot-one', 'site_key'=>$key, 'binding_sha256'=>$snapshot['binding_sha256'],
    'baseline_snapshot_sha256'=>$snapshot['snapshot_sha256'] );
$limits = array( 'min_samples'=>25, 'max_error_rate_bps'=>100, 'max_p95_ms'=>250 );
$preview = MAD4B_SCP_G9_Resilience_Gates::release_preview( $snapshot, $target, $limits );
g9_check( ! is_wp_error( $preview ) && $preview['health_gate_passed'] && ! $preview['execution_supported'], 'non-authorizing pilot' );
$changed = $target; $changed['baseline_snapshot_sha256'] = str_repeat( 'b', 64 );
g9_error( MAD4B_SCP_G9_Resilience_Gates::release_preview( $snapshot, $changed, $limits ), 'stale_cohort' );
$changed = $snapshot; $changed['binding']['environment'] = 'production';
$changed['authority']['environment'] = 'production';
$changed['binding_sha256'] = MAD4B_SCP_Resilience_Context::digest( $changed['binding'] );
$changed['snapshot_sha256'] = MAD4B_SCP_Resilience_Context::snapshot_digest( $changed );
g9_error( MAD4B_SCP_G9_Resilience_Gates::release_preview( $changed, $target, $limits ), 'production_ring_denied' );
$incomplete = $snapshot; $incomplete['gates']['provider_inventory_complete'] = false;
$incomplete['snapshot_sha256'] = MAD4B_SCP_Resilience_Context::snapshot_digest( $incomplete );
$incomplete_target = $target; $incomplete_target['baseline_snapshot_sha256'] = $incomplete['snapshot_sha256'];
g9_error( MAD4B_SCP_G9_Resilience_Gates::release_preview( $incomplete, $incomplete_target, $limits ), 'inventory_incomplete' );
$stale = $snapshot; $stale['health']['observed_at'] -= 1000;
$stale['snapshot_sha256'] = MAD4B_SCP_Resilience_Context::snapshot_digest( $stale );
$stale_target = $target; $stale_target['baseline_snapshot_sha256'] = $stale['snapshot_sha256'];
g9_error( MAD4B_SCP_G9_Resilience_Gates::release_preview( $stale, $stale_target, $limits ), 'health_stale' );
$changed = $snapshot; $changed['health']['p95_ms'] = 999;
$changed['snapshot_sha256'] = MAD4B_SCP_Resilience_Context::snapshot_digest( $changed );
$changedtarget = $target; $changedtarget['baseline_snapshot_sha256'] = $changed['snapshot_sha256'];
g9_error( MAD4B_SCP_G9_Resilience_Gates::release_preview( $changed, $changedtarget, $limits ), 'pilot_health_failed' );
$revoked = $snapshot; $revoked['providers']['core']['revoked'] = true;
$revoked['snapshot_sha256'] = MAD4B_SCP_Resilience_Context::snapshot_digest( $revoked );
$revokedtarget = $target; $revokedtarget['baseline_snapshot_sha256'] = $revoked['snapshot_sha256'];
g9_error( MAD4B_SCP_G9_Resilience_Gates::release_preview( $revoked, $revokedtarget, $limits ), 'provider_revoked_or_foreign' );
$unrewound = $snapshot; $unrewound['external_effects']['payment'] = array( 'state'=>'unknown', 'site_key'=>$key, 'receipt_sha256'=>$hash );
$unrewound['snapshot_sha256'] = MAD4B_SCP_Resilience_Context::snapshot_digest( $unrewound );
$unrewoundtarget = $target; $unrewoundtarget['baseline_snapshot_sha256'] = $unrewound['snapshot_sha256'];
g9_error( MAD4B_SCP_G9_Resilience_Gates::release_preview( $unrewound, $unrewoundtarget, $limits ), 'external_effect_uncertain' );
$nohost = $snapshot; $nohost['host']['isolation_verified'] = false;
$nohost['snapshot_sha256'] = MAD4B_SCP_Resilience_Context::snapshot_digest( $nohost );
$nohosttarget = $target; $nohosttarget['baseline_snapshot_sha256'] = $nohost['snapshot_sha256'];
g9_error( MAD4B_SCP_G9_Resilience_Gates::release_preview( $nohost, $nohosttarget, $limits ), 'host_isolation_unknown' );
$distributed = $snapshot; $distributed['host']['single_host_exclusive_verified'] = false;
$distributed['snapshot_sha256'] = MAD4B_SCP_Resilience_Context::snapshot_digest( $distributed );
$distributed_target = $target; $distributed_target['baseline_snapshot_sha256'] = $distributed['snapshot_sha256'];
g9_error( MAD4B_SCP_G9_Resilience_Gates::release_preview(
    $distributed, $distributed_target, $limits ), 'distributed_fence_unavailable' );
$wider = $target; $wider['ring'] = 'general';
g9_error( MAD4B_SCP_G9_Resilience_Gates::release_preview( $snapshot, $wider, $limits ), 'prior_ring_missing' );
g9_error( MAD4B_SCP_G9_Resilience_Gates::fleet_inventory( array( $snapshot, $snapshot ) ), 'duplicate_site' );
$cloned = $snapshot;
$cloned['binding']['canonical_origin'] = 'https://clone.example.invalid';
$cloned['binding_sha256'] = MAD4B_SCP_Resilience_Context::digest( $cloned['binding'] );
$cloned['snapshot_sha256'] = MAD4B_SCP_Resilience_Context::snapshot_digest( $cloned );
g9_error( MAD4B_SCP_G9_Resilience_Gates::fleet_inventory( array( $snapshot, $cloned ) ), 'cloned_site_uuid' );
$mutated = $snapshot;
$mutated['binding']['registry_sha256'] = str_repeat( 'b', 64 );
$mutated['binding_sha256'] = MAD4B_SCP_Resilience_Context::digest( $mutated['binding'] );
$mutated['snapshot_sha256'] = MAD4B_SCP_Resilience_Context::snapshot_digest( $mutated );
$diff = MAD4B_SCP_G9_Resilience_Gates::fleet_diff( array( $snapshot ), array( $mutated ) );
g9_check( ! is_wp_error( $diff ) && count( $diff['changed'] ) === 1
    && !$diff['promotion_authorized'], 'bounded non-authorizing cohort diff' );
$fleet = MAD4B_SCP_G9_Resilience_Gates::fleet_inventory( array( $snapshot ) );
g9_check( ! is_wp_error( $fleet ) && $fleet['site_count'] === 1 && ! $fleet['sites'][0]['eligible_for_execution'], 'fleet non-authorizing' );
$restore = MAD4B_SCP_G9_Resilience_Gates::restore_preview( $snapshot, $snapshot, array( 'unknown'=>array( 'state'=>'unknown' ) ) );
g9_check( ! is_wp_error( $restore ) && count( $restore['unresolved_external_effect_keys'] ) === 1 && ! $restore['post_restore_acceptance_issued'], 'unrewound effects' );
$foreign = $snapshot; $foreign['binding']['site_uuid'] = '22222222-2222-4222-8222-222222222222';
$foreign['authority']['site_uuid'] = $foreign['binding']['site_uuid'];
$foreign['binding_sha256'] = MAD4B_SCP_Resilience_Context::digest( $foreign['binding'] );
$foreign['snapshot_sha256'] = MAD4B_SCP_Resilience_Context::snapshot_digest( $foreign );
g9_error( MAD4B_SCP_G9_Resilience_Gates::restore_preview( $snapshot, $foreign, array() ), 'foreign_restore' );
$path = $dir . '/resilience-' . $binding['site_uuid'] . '-1-staging.json';
// A lock held by another handle rejects split-brain attempts.
$lock = fopen( $path . '.lock', 'c+' );
g9_check( is_resource( $lock ) && flock( $lock, LOCK_EX | LOCK_NB ), 'test lock acquired' );
g9_error( MAD4B_SCP_Resilience_Anchor::transact( $binding, 1, function ( $current ) {
    $current['scopes']['g9:split-brain'] = array( 'claimed'=>true ); return $current;
} ), 'locked' );
flock( $lock, LOCK_UN ); fclose( $lock );
// Unsafely created keys and caller-owned metadata are never persisted.
g9_error( MAD4B_SCP_Resilience_Anchor::transact( $binding, 1, function ( $current ) {
    $current['scopes']['../../escape'] = array( 'claimed'=>true ); return $current;
} ), 'scope_invalid' );
$metadata = MAD4B_SCP_Resilience_Anchor::transact( $binding, 1, function ( $current ) {
    $current['site']['environment'] = 'production';
    $current['unauthorized_status'] = 'ready';
    $current['scopes']['g9:safe-scope'] = array( 'claimed'=>false );
    return $current;
} );
g9_check( ! is_wp_error( $metadata ) && $metadata['revision'] === 2
    && ! isset( $metadata['unauthorized_status'] )
    && $metadata['site']['environment'] === 'staging',
    'anchor metadata not controlled by closure' );
$original = file_get_contents( $path );
$forged = json_decode( $original, true );
$forged['clock_floor'] = time() + 3600;
unset( $forged['anchor_sha256'] );
$forged['anchor_sha256'] = MAD4B_SCP_Resilience_Context::digest( $forged );
file_put_contents( $path, json_encode( $forged ) );
g9_error( MAD4B_SCP_Resilience_Anchor::read( $binding ), 'clock_rollback' );
file_put_contents( $path, $original );
$broken = json_decode( $original, true ); $broken['revision'] = '2';
file_put_contents( $path, json_encode( $broken ) );
g9_error( MAD4B_SCP_Resilience_Anchor::read( $binding ), 'corrupt' );
file_put_contents( $path, $original );
@unlink( $path ); @unlink( $path . '.lock' ); @rmdir( $dir );
g9_error( MAD4B_SCP_Resilience_Anchor::read( $binding ), 'lost' );
echo "G9 resilience gates: PASS (27 isolation/restore/ring checks)\n";
