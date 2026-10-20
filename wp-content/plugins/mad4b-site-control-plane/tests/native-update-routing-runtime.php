<?php
/** Pure route guidance; does not call WordPress, approvals or the installer. */
define( 'ABSPATH', __DIR__ . '/' );
require dirname( __DIR__ ) . '/includes/class-mad4b-scp-self-update.php';
function route_check( $ok, $why ) { if ( ! $ok ) throw new RuntimeException( $why ); }
$ready = array( 'staging_exact' => true, 'write_authority_ready' => true,
    'write_dispatch_mounted' => true, 'native_admin_mounted' => true,
    'native_direct_mounted' => false, 'bootstrap_direct_mounted' => true,
    'candidate_drift_eligible' => true );
$guide = static function ( $signals ) { return MAD4B_SCP_Self_Update::native_execution_guidance_from_signals( $signals ); };
$normal = $guide( $ready );
route_check( 'mad4b/control-plane-native-apply' === $normal['apply_ability']
    && 'mad4b/write-execute' === $normal['dispatch_tool']
    && $normal['bootstrap_must_not_replace_normal_apply'], 'effective Write chose Bootstrap or required an absent direct tool' );
route_check( ! $normal['host_tool_visibility_verified'] && ! $normal['route_is_authority']
    && ! $normal['automatic_execution_allowed'] && $normal['original_approval_required']
    && ! $normal['included_in_plan_sha256'], 'guidance claimed execution authority or poisoned the package plan hash' );
$missing = $guide( array_replace( $ready, array( 'write_dispatch_mounted' => false ) ) );
route_check( '' === $missing['dispatch_tool'] && 'normal_apply_transport_unavailable' === $missing['state']
    && 'mad4b-runtime-components' === $missing['local_admin_handoff']['page']
    && $missing['local_admin_handoff']['requires_fresh_plan_and_nonce'], 'missing normal transport fell back to Bootstrap' );
$direct = $guide( array_replace( $ready, array( 'write_dispatch_mounted' => false, 'native_direct_mounted' => true ) ) );
route_check( 'mad4b/control-plane-native-apply' === $direct['dispatch_tool']
    && ! $direct['host_tool_visibility_verified'], 'server projection was treated as proof of host visibility' );
$drift = $guide( array_replace( $ready, array( 'write_authority_ready' => false ) ) );
route_check( MAD4B_SCP_Self_Update::BOOTSTRAP_APPLY_ABILITY === $drift['apply_ability'], 'reviewed candidate drift lost its separate route' );
$unclean = $guide( array_replace( $ready, array( 'write_authority_ready' => false, 'candidate_drift_eligible' => false ) ) );
route_check( '' === $unclean['apply_ability'], 'unclean authority gained a bootstrap suggestion' );
$unknown = $guide( array_replace( $ready, array( 'write_authority_ready' => null ) ) );
route_check( '' === $unknown['apply_ability'], 'unknown authority was treated as a bootstrap candidate' );
$foreign = $guide( array_replace( $ready, array( 'staging_exact' => false ) ) );
route_check( '' === $foreign['apply_ability'] && 'environment_or_origin_not_exact' === $foreign['state'], 'foreign origin or Production selected a Staging route' );
echo "NATIVE_UPDATE_ROUTING_PASS\n";
