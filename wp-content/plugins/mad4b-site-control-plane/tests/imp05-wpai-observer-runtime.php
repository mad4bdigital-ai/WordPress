<?php
/** IMP05 isolated hooks: no actual WP All Import importer launched. */
class PMXI_Plugin {}
$GLOBALS['imp05_hooks'] = array();
function add_action( $hook, $callback, $priority = 10, $argc = 1 ) {
    $GLOBALS['imp05_hooks'][ $hook ] = $callback;
}
function update_option( $key, $value, $autoload = false ) {
    $GLOBALS['imp01_options'][ $key ] = $value;
    return true;
}
function wp_all_import_get_import_id() { return 12; }
require __DIR__ . '/imp04-reconciliation-runtime.php';
require __DIR__ . '/../includes/class-mad4b-scp-activity-wpai-observer.php';
ck( isset( $GLOBALS['imp05_hooks']['pmxi_before_xml_import'],
    $GLOBALS['imp05_hooks']['pmxi_saved_post'],
    $GLOBALS['imp05_hooks']['pmxi_after_xml_import'] ),
    'Documented WP All Import hooks were not registered' );
$review = MAD4B_SCP_Activity_Import_Review::review(
    array( 'profile_slug' => 'pricing' ) );
$GLOBALS['imp02_enabled_modes'] = array( 'admin_csv_upload',
    'signed_generic_webhook', 'wp_all_import_wizard' );
$input = array( 'profile_slug' => 'pricing',
    'snapshot_sha256' => $review['snapshot_sha256'], 'import_id' => 12 );
$plan = MAD4B_SCP_Activity_WPAI_Observer::arm_plan( $input );
ck( !is_wp_error( $plan ) && !$plan['job_execution_authorized'] &&
    !$plan['approved_file_source_config_verified'],
    'Observation plan spoofed import authorization or source proof' );
$arm = MAD4B_SCP_Activity_WPAI_Observer::arm( array_merge( $input,
    array( 'plan_sha256' => $plan['plan_sha256'], 'confirmed' => true ) ) );
ck( !is_wp_error( $arm ) && $arm['state'] === 'armed_observation_only',
    'Exact Staging import observation was not armed' );
MAD4B_SCP_Activity_WPAI_Observer::on_before( 12 );
MAD4B_SCP_Activity_WPAI_Observer::on_saved( 101, null, false );
MAD4B_SCP_Activity_WPAI_Observer::on_after( 12, null );
$status = MAD4B_SCP_Activity_WPAI_Observer::status( array( 'import_id' => 12 ) );
ck( !is_wp_error( $status ) &&
    $status['state'] === 'external_import_end_observed_unverified' &&
    $status['post_save_events_observed'] === 1 &&
    !$status['source_provenance_verified'] &&
    $status['requires_independent_reconciliation'],
    'Hook end was incorrectly promoted to verified import' );
$duplicate = MAD4B_SCP_Activity_WPAI_Observer::arm( array_merge( $input,
    array( 'plan_sha256' => $plan['plan_sha256'], 'confirmed' => true ) ) );
ck( is_wp_error( $duplicate ) &&
    $duplicate->get_error_code() === 'mad4b_wpai_observation_active',
    'Concurrent rearming replaced active observation journal' );
unset( $GLOBALS['imp02_enabled_modes'] );
echo "PASS IMP05 observed public hook lifecycle, exact approved arm, duplicate denial, no false provider provenance (PHP fixture only)\n";
