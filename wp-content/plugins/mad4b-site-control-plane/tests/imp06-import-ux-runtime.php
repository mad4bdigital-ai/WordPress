<?php
/** IMP06 deterministic operator journey simulation without WordPress or writes. */
define( 'ABSPATH', '/' );
class WP_Error {
    private $code;
    public function __construct( $code ) { $this->code = $code; }
    public function get_error_code() { return $this->code; }
}
function is_wp_error( $v ) { return $v instanceof WP_Error; }
function ux( $assertion, $reason ) {
    if ( ! $assertion ) throw new RuntimeException( 'IMP06 UX failed: ' . $reason );
}
require __DIR__ . '/../includes/class-mad4b-scp-activity-import-experience.php';
$p = array(
    array( 'slug' => 'rates', 'label' => 'Rates', 'post_type' => 'tour_rate',
        'enabled' => true, 'has_import_contract' => true,
        'enabled_modes' => array( 'admin_csv_upload', 'signed_generic_webhook',
            'wp_all_import_wizard' ), 'preferred_mode' => 'admin_csv_upload' ),
    array( 'slug' => 'draft', 'label' => 'Draft', 'post_type' => 'article',
        'enabled' => true, 'has_import_contract' => false,
        'enabled_modes' => array(), 'preferred_mode' => '' ),
    array( 'slug' => 'disabled', 'label' => 'Disabled', 'post_type' => 'rate',
        'enabled' => false, 'has_import_contract' => true,
        'enabled_modes' => array( 'admin_csv_upload' ), 'preferred_mode' => 'admin_csv_upload' ),
);
$catalog = array( 'modes' => array(
    array( 'id' => 'admin_csv_upload', 'detected' => true,
        'review_intake_implemented' => true, 'state' => 'review_only_ready',
        'requirements' => array( 'admin', 'staging' ) ),
    array( 'id' => 'signed_generic_webhook', 'detected' => false,
        'review_intake_implemented' => true, 'state' => 'not_configured',
        'requirements' => array( 'site_secret' ) ),
    array( 'id' => 'wp_all_import_wizard', 'detected' => true,
        'review_intake_implemented' => false,
        'state' => 'handoff_or_adapter_required', 'requirements' => array( 'plugin_installed' ) ),
    array( 'id' => 'arbitrary_unapproved_adapter', 'detected' => true,
        'review_intake_implemented' => true, 'state' => 'review_only_ready',
        'requirements' => array() ),
) );
$empty = array( 'state' => 'no_staged_feed' );
$welcome = MAD4B_SCP_Activity_Import_Experience::journey(
    $p, '', $catalog, $empty, '', 4 );
ux( $welcome['step'] === 1 && !$welcome['selected_profile'],
    'Welcome improperly skips Profile selection' );
ux( count( $welcome['profiles'] ) === 2,
    'Disabled Profile appears in configured destination selector' );
$unconfigured = MAD4B_SCP_Activity_Import_Experience::journey(
    $p, 'draft', $catalog, $empty, '', 3 );
ux( $unconfigured['step'] === 1 && !$unconfigured['has_import_policy'],
    'Unconfigured Profile accepts source or review' );
$initial = MAD4B_SCP_Activity_Import_Experience::journey(
    $p, 'rates', $catalog, $empty, '', 1 );
ux( $initial['selected_mode_id'] === 'admin_csv_upload' &&
    $initial['can_upload_new'] && count( $initial['available_modes'] ) === 3,
    'Allowed Profile Mode list and default wrong' );
$withoutSource = MAD4B_SCP_Activity_Import_Experience::journey(
    $p, 'rates', $catalog, $empty, '', 4 );
ux( $withoutSource['step'] === 2 && !$withoutSource['approval_candidate'],
    'Approval shown before data staged' );
$unknown = MAD4B_SCP_Activity_Import_Experience::journey(
    $p, 'rates', $catalog, $empty, 'arbitrary_unapproved_adapter', 2 );
ux( $unknown['selected_mode_id'] === '' &&
    $unknown['requested_mode_invalid'],
    'Disallowed Mode fell back silently to preferred source' );
$pending = array(
    'state' => 'requires_review', 'snapshot_sha256' => str_repeat( 'a', 64 ),
    'plan' => array( 'block_issue_count' => 180, 'review_issue_count' => 12,
        'issue_count_observed' => 392, 'issues_truncated' => true )
);
$blocked = MAD4B_SCP_Activity_Import_Experience::journey(
    $p, 'rates', $catalog, $pending, 'admin_csv_upload', 3 );
ux( $blocked['review_pending'] && !$blocked['can_upload_new'] &&
    !$blocked['approval_candidate'] && $blocked['blocking_issue_count'] === 180 &&
    $blocked['issue_count_total'] === 392,
    'Blocking errors or pending inbox not surfaced clearly' );
$cleanReview = array(
    'state' => 'requires_review', 'snapshot_sha256' => str_repeat( 'b', 64 ),
    'plan' => array( 'block_issue_count' => 0, 'review_issue_count' => 3,
        'issue_count_observed' => 3, 'issues_truncated' => false )
);
$reviewed = MAD4B_SCP_Activity_Import_Experience::journey(
    $p, 'rates', $catalog, $cleanReview, '', 4 );
ux( $reviewed['approval_candidate'] && !$reviewed['can_start_import'] &&
    $reviewed['never_auto_execute_or_publish'],
    'Manual source approval was misrepresented as actual importer execution' );
$allWarnings = array(
    'state' => 'requires_review', 'snapshot_sha256' => str_repeat( 'c', 64 ),
    'plan' => array( 'block_issue_count' => 0, 'review_issue_count' => 390,
        'issue_count_observed' => 390, 'issues_truncated' => true )
);
$many = MAD4B_SCP_Activity_Import_Experience::journey(
    $p, 'rates', $catalog, $allWarnings, '', 4 );
ux( $many['approval_candidate'] && $many['review_issue_count'] === 390 &&
    !$many['can_start_import'],
    'Large warning-only import was permanently blocked by display pagination' );
$corrupt = MAD4B_SCP_Activity_Import_Experience::journey(
    $p, 'rates', $catalog, new WP_Error( 'legacy' ), '', 2 );
ux( $corrupt['review_unavailable'] && !$corrupt['can_upload_new'],
    'Legacy/unreadable existing review exposed new upload' );
$bounded = MAD4B_SCP_Activity_Import_Experience::journey(
    $p, 'rates', $catalog, $cleanReview, '', 500000 );
ux( $bounded['step'] === 4, 'Out-of-range step produced undefined UI' );
ux( strpos( MAD4B_SCP_Activity_Import_Experience::issue_label(
    'currency_not_in_approved_allowlist' ), 'Currency' ) !== false,
    'Human-readable explanation missing for commercial currency blocker' );
echo "PASS IMP06 simulated onboarding, allowlisted Modes, no source, blockers, manual-only approval, invalid selection, unreadable review and bounded steps (ISOLATED PHP)\n";
