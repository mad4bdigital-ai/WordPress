<?php
/** Immutable, source-reviewed current Staging gap; no grant creation. */
if ( ! defined( 'ABSPATH' ) ) define( 'ABSPATH', __DIR__ . '/' );
$GLOBALS['test_env'] = 'staging';
function sanitize_key( $s ) { return preg_replace( '/[^a-z0-9_-]/', '', strtolower( (string) $s ) ); }
class MAD4B_SCP_Site_Profile {
    public static function current_environment() { return $GLOBALS['test_env']; }
}
function verify_grants( $condition, $message ) {
    if ( ! $condition ) { fwrite( STDERR, 'FAIL: ' . $message . "\n" ); exit( 1 ); }
}
require dirname( __DIR__ ) . '/includes/class-mad4b-scp-staging-write-grant-reconciliation.php';
$c = 'MAD4B_SCP_Staging_Write_Grant_Reconciliation';
$expected = array(
    'context/brand-profile-save' => 'google_drive_context',
    'context/governed-source-upsert' => 'google_drive_context',
    'context/remote-owner-review-apply' => 'google_drive_context',
    'mad4b/acceptance-target-provision' => 'core',
    'mad4b/business-activity-import-approve' => 'core',
    'mad4b/business-activity-import-batch-append' => 'core',
    'mad4b/business-activity-import-batch-approve' => 'core',
    'mad4b/business-activity-import-batch-archive' => 'core',
    'mad4b/business-activity-import-batch-begin' => 'core',
    'mad4b/business-activity-link-apply' => 'core',
    'mad4b/business-activity-sync-advance' => 'core',
    'mad4b/business-activity-sync-archive' => 'core',
    'mad4b/business-activity-sync-begin' => 'core',
    'mad4b/business-activity-sync-cancel' => 'core',
    'mad4b/business-activity-sync-finalize-reconciled' => 'core',
    'mad4b/business-activity-sync-recover' => 'core',
    'mad4b/business-activity-wpai-observation-arm' => 'core',
    'mad4b/content-delete-meta' => 'core',
    'mad4b/content-experience-profile-apply' => 'core',
    'mad4b/content-experience-profile-clone-apply' => 'core',
    'mad4b/content-experience-profile-delete-apply' => 'core',
    'mad4b/content-import-bundle' => 'core',
    'mad4b/content-set-meta' => 'core',
    'mad4b/content-trash-post' => 'core',
    'mad4b/control-plane-selected-head-apply' => 'core',
    'mad4b/deployment-binding-native-apply' => 'core',
    'mad4b/deployment-binding-setup-apply' => 'core',
    'mad4b/host-operation-apply' => 'core',
    'mad4b/market-growth-policy-update' => 'core',
    'mad4b/native-recovery-cleanup' => 'core',
    'mad4b/native-recovery-start' => 'core',
    'mad4b/runtime-release-set-apply' => 'core',
    'mad4b/staging-offline-attestor-enroll-apply' => 'core',
    'mad4b/taxonomy-update-term' => 'core',
    'mad4b/translation-link-posts' => 'translation',
    'mad4b/translation-set-post-language' => 'translation',
    'media/import-external' => 'media',
    'media/remote-import-apply' => 'media',
);
$allowed = $c::allowed_ability_providers();
verify_grants( ( $allowed['jetsmartfilters/update-filter-meta'] ?? '' ) === 'jetsmartfilters',
    'Exact reversible JetSmartFilters metadata writer must be source-reviewed on Staging.' );
verify_grants( !isset( $allowed['jetsmartfilters/delete-filter'] )
    && !isset( $allowed['elementor/update-widget-settings'] ),
    'A certified provider must not confer arbitrary extra write grants.' );
// Native build admission and optional self-certified installation must each
// have an exact, Staging-only reviewed provider pair. This allowlist test
// does not approve either operation or create any grant.
$native_reviewed = array(
    'mad4b/wordpress-native-candidate-opt-in-apply' => 'core',
    'mad4b/wp-native-build-start' => 'core',
    'mad4b/wp-native-self-approve-install' => 'core',
);
verify_grants( count( $native_reviewed ) === 3,
    'Exactly three discrete native Staging operations reviewed; none is a wildcard grant.' );
verify_grants( isset( $allowed['mad4b/provider-connection-refresh'] )
    && 'core' === $allowed['mad4b/provider-connection-refresh'],
    'Provider OAuth refresh is exactly reviewed core-only on Staging.' );
foreach ( $native_reviewed as $ability => $provider ) {
    verify_grants( isset( $allowed[ $ability ] ) && $allowed[ $ability ] === $provider,
        'Native Staging ability requires exact core provider: ' . $ability );
}

verify_grants( count( $expected ) === 38, 'Exactly 38 source-reviewed missing Staging Ability/provider pairs.' );
foreach ( $expected as $ability => $provider ) {
    verify_grants( isset( $allowed[ $ability ] ) && $allowed[ $ability ] === $provider,
        'Staging review must match exact Ability and provider: ' . $ability );
}
verify_grants( !isset( $allowed['mad4b/database-raw-query'] )
    && !isset( $allowed['mad4b/unknown-future-grant'] )
    && !isset( $allowed['malicious/unknown-ability'] ),
    'No raw SQL, wildcard or future/third-party unreviewed grant.' );
verify_grants( $allowed === array_replace( $allowed, array() )
    && array_keys( $allowed ) === array_values( array_unique( array_keys( $allowed ) ) ),
    'Provider policy remains finite and unique.' );
$GLOBALS['test_env'] = 'production';
$production = $c::allowed_ability_providers();
verify_grants( ( $production['jetsmartfilters/update-filter-meta'] ?? '' ) === 'jetsmartfilters',
    'JetSmartFilters certified metadata writer must use the same source provider on Production.' );
// Preserve the original execution purpose for exact Staging support lanes;
 // operational content and media capabilities are environment-neutral only as
 // reviewed provider metadata, not as grants or release authority.
$staging_only_from_expected = array(
    'mad4b/acceptance-target-provision',
    'mad4b/business-activity-wpai-observation-arm',
    'mad4b/control-plane-selected-head-apply',
    'mad4b/deployment-binding-setup-apply',
    'mad4b/native-recovery-cleanup',
    'mad4b/native-recovery-start',
    'mad4b/runtime-release-set-apply',
    'mad4b/staging-offline-attestor-enroll-apply',
);
foreach ( $expected as $ability => $provider ) {
    if ( in_array( $ability, $staging_only_from_expected, true ) ) {
        verify_grants( ! isset( $production[ $ability ] ),
            'Exact Staging rehearsal or source control operation must retain its own boundary: ' . $ability );
    } else {
        verify_grants( ( $production[ $ability ] ?? '' ) === $provider,
            'Company operation must have the same reviewed source provider: ' . $ability );
    }
}
verify_grants( ( $production['mad4b/attachment-transfer'] ?? '' ) === 'core'
    && ( $production['mad4b/optional-governance-apply'] ?? '' ) === 'core',
    'Scoped media transfer and exact advisory governance share one provider on both environments.' );
verify_grants( ! isset( $production['mad4b/provider-connection-refresh'] ),
    'Provider token refresh must not be automatically granted on Production.' );
foreach ( $native_reviewed as $ability => $provider ) {
    verify_grants( ! isset( $production[ $ability ] ),
        'Native candidate grant review must never expand Production: ' . $ability );
}
$GLOBALS['test_env'] = 'staging';
$retire = $c::retirable_stale_ability_providers();
foreach ( $expected as $ability => $provider ) {
    verify_grants( ( $retire[ $ability ] ?? '' ) === $provider,
        'Current Staging review can retire exactly its stale version: ' . $ability );
}
echo "PASS: 38 reviewed Staging pairs classified by execution purpose; shared company providers match Production, no grants\n";
