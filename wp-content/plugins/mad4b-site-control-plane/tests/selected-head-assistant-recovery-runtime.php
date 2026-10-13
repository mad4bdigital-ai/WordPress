<?php
// Pure assistant recovery test; never bootstrap WordPress or host writes.
if ( ! defined( 'ABSPATH' ) ) define( 'ABSPATH', __DIR__ . '/' );
$GLOBALS['staging_environment'] = true;
function wp_get_environment_type() { return $GLOBALS['staging_environment'] ? 'staging' : 'production'; }
class MAD4B_SCP_Site_Profile {
    public static function status() { return array( 'site_uuid' => 'staging-only-fixture' ); }
}
class MAD4B_SCP_WordPress_Native_Opt_In {
    public static function site_policy( $site ) {
        return array( 'eligible' => true, 'host_runner_required' => false,
            'production_allowed' => false );
    }
}
require_once dirname( __DIR__ ) . '/includes/class-mad4b-scp-selected-head-update.php';
$keys = array(
	'selected_head_opt_in_disabled',
	'exact_staging_binding_required',
	'mad4b_selected_head_certified_artifact_unavailable',
	'candidate_not_certified',
	'already_on_exact_source_commit',
	'unknown_future_blocker',
);
$steps = MAD4B_SCP_Selected_Head_Update::blocker_recovery( $keys );
if ( count( $steps ) !== count( $keys ) ) exit( "FAIL: count\n" );
foreach ( $steps as $i => $step ) {
	if ( $step['blocker'] !== $keys[ $i ] || $step['remote_mcp_auto_apply_allowed'] ||
		! isset( $step['actor'], $step['step_id'], $step['surface'] ) ) exit( "FAIL: policy $i\n" );
}
if ( count( MAD4B_SCP_Selected_Head_Update::blocker_recovery( array( 'selected_head_opt_in_disabled', 'selected_head_opt_in_disabled' ) ) ) !== 1 )
	exit( "FAIL: dedupe\n" );
$native = MAD4B_SCP_Selected_Head_Update::blocker_recovery( array(
    'selected_head_opt_in_disabled', 'exact_staging_binding_required'
) );
foreach ( $native as $recovery ) {
    if ( $recovery['tool'] !== 'mad4b/wordpress-native-candidate-opt-in-plan'
        || $recovery['apply_ability'] !== 'mad4b/wordpress-native-candidate-opt-in-apply'
        || $recovery['next_after_enable'] !== 'mad4b/wp-native-build-plan'
        || empty( $recovery['requires_separate_approval'] )
        || empty( $recovery['requires_exact_nhi_grant'] )
        || !empty( $recovery['host_runner_required'] )
        || !empty( $recovery['release_or_install_certified'] )
        || !empty( $recovery['remote_mcp_auto_apply_allowed'] )
        || !empty( $recovery['production_allowed'] ) )
        exit( "FAIL: no binding-free native bootstrap with separate owner approval\n" );
}
$GLOBALS['staging_environment'] = false;
$blocked_prod = MAD4B_SCP_Selected_Head_Update::blocker_recovery( array(
    'selected_head_opt_in_disabled', 'exact_staging_binding_required' ) );
foreach( $blocked_prod as $fallback ) {
    if ( empty( $fallback['requires_explicit_consent'] )
        || !empty( $fallback['remote_mcp_auto_apply_allowed'] )
        || $fallback['actor'] !== 'staging_host_operator' )
        exit( "FAIL: non-Staging recovered through native bootstrap\n" );
}
$GLOBALS['staging_environment'] = true;
$without_ci = MAD4B_SCP_Selected_Head_Update::blocker_recovery( array(
    'mad4b_selected_head_certified_artifact_unavailable', 'candidate_not_certified'
) );
foreach ( $without_ci as $entry ) {
    if ( ! isset( $entry['accepted_evidence_modes'], $entry['tool'] ) ||
        $entry['accepted_evidence_modes'] !== array( 'owner_signed_ci_outage', 'github_ci_verdict' ) ||
        $entry['github_actions_queue_clearance_required'] !== false ||
        $entry['independent_ed25519_signer_required_without_ci'] !== true ||
        $entry['source_bound_native_tests_required'] !== true ||
        $entry['staging_only'] !== true ||
        $entry['remote_mcp_auto_apply_allowed'] !== false ) {
        exit( "FAIL: offline recovery must not require GitHub Actions\n" );
    }
}

echo "PASS: selected-HEAD recovery assistant no-authority contract\n";
