<?php
// Pure assistant recovery test; never bootstrap WordPress or host writes.
if ( ! defined( 'ABSPATH' ) ) define( 'ABSPATH', __DIR__ . '/' );
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
echo "PASS: selected-HEAD recovery assistant no-authority contract\n";
