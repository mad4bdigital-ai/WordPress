<?php
/** IMP13 practical review of real-world typo/currency/serialized data shapes. */
require __DIR__ . '/imp01-import-preview-runtime.php';

$commercial = $clean;
$commercial['rows'][0]['base_currency'] = 'ERU';
$commercial['rows'][0]['_wpml_import_after_process_post_status'] = 'puplished';
$commercial['headers'][] = 'related_properties_id';
foreach ( $commercial['rows'] as &$line ) $line['related_properties_id'] = '';
unset( $line );
$commercial['rows'][0]['related_properties_id'] = 'a:2:{i:0;i:234;i:1;i:235;}';
$review = MAD4B_SCP_Activity_Import_Review::plan( $commercial );
ck( !is_wp_error( $review ) &&
    $review['block_issue_count'] >= 3 &&
    isset( $review['issue_counts_by_reason']['currency_not_in_approved_allowlist'] ) &&
    isset( $review['issue_counts_by_reason']['invalid_wordpress_post_status'] ) &&
    isset( $review['issue_counts_by_reason']['serialized_relation_requires_certified_driver'] ),
    'Commercial source currency/status/serialized relation was silently approved.' );
$prior_snapshot = get_option( $storeKey, false );
delete_option( $storeKey ); // isolated fixture: prior approved source is not deleted on a real site
$review_only = MAD4B_SCP_Activity_Import_Snapshot::stage(
    'pricing', $commercial, $review, 'admin_csv_upload', 'test-admin' );
ck( !is_wp_error( $review_only ),
    'Unsafe commercial rows should be stageable for a transparent issue review.' );
$rejected = MAD4B_SCP_Activity_Import_Snapshot::approve(
    'pricing', $review_only['snapshot_sha256'], true );
ck( is_wp_error( $rejected ),
    'Blocking serialized relationship payload was approved for export.' );
$GLOBALS['imp01_options'][ $storeKey ] = $prior_snapshot;
$normalized = $commercial;
$normalized['rows'][0]['base_currency'] = 'EUR';
$normalized['rows'][0]['_wpml_import_after_process_post_status'] = 'draft';
$normalized['rows'][0]['related_properties_id'] = '';
$second = MAD4B_SCP_Activity_Import_Review::plan( $normalized );
ck( !is_wp_error( $second ) && $second['block_issue_count'] === 0,
    'Correcting currency, status and source serialization did not clear blockers.' );
echo "PASS IMP13 commercial currency/status/serialized relations are refused until typed provider review (ISOLATED PHP)\n";
