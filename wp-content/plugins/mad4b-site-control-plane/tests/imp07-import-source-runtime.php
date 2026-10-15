<?php
/** IMP07 regression: dynamic site periods, normalized policy idempotence and XLSX fail closed. */
require __DIR__ . '/imp01-import-preview-runtime.php';
require __DIR__ . '/../includes/class-mad4b-scp-activity-import-xlsx.php';

$profile = MAD4B_SCP_Content_Experience_Profiles::profile( 'pricing' );
$contract = $profile['import_contract'];
$contract['enabled_modes'] = array( 'admin_csv_upload', 'admin_xlsx_convert' );
$contract['fallback_modes'] = array();
$contract['preferred_mode'] = 'admin_csv_upload';
$rechecked = MAD4B_SCP_Activity_Import_Authority::normalize_contract(
    $contract, $profile['meta_keys'] );
ck( ! is_wp_error( $rechecked ) && false === $rechecked['auto_execute'] &&
    $rechecked['validation']['identity_field'] === 'ID',
    'Reapplying a normalized independent Import Contract failed roundtrip' );
$unauthorized = $contract;
$unauthorized['auto_execute'] = true;
$denied = MAD4B_SCP_Activity_Import_Authority::normalize_contract(
    $unauthorized, $profile['meta_keys'] );
ck( is_wp_error( $denied ) &&
    $denied->get_error_code() === 'mad4b_import_implicit_execution_denied',
    'Revised Import Contract could silently enable execution' );

$input = array(
    'profile_slug' => 'pricing',
    'headers' => array( 'ID', 'base_currency', 'single_price', 'double_price',
        'period_start', 'period_end',
        '_wpml_import_translation_group',
        '_wpml_import_language_code',
        '_wpml_import_after_process_post_status' ),
    'rows' => array(
        array( 'ID' => 1, 'base_currency' => 'USD',
            'single_price' => 100, 'double_price' => 80,
            'period_start' => '2023-12-31', 'period_end' => '2023-01-01',
            '_wpml_import_translation_group' => 'grp1',
            '_wpml_import_language_code' => 'en',
            '_wpml_import_after_process_post_status' => 'draft' ),
        array( 'ID' => 2, 'base_currency' => 'EUR',
            'single_price' => 100, 'double_price' => 80,
            'period_start' => '2024-02-30', 'period_end' => '2024-03-01',
            '_wpml_import_translation_group' => 'grp1',
            '_wpml_import_language_code' => 'fr',
            '_wpml_import_after_process_post_status' => 'draft' ),
    )
);
$GLOBALS['imp07_period_test'] = true;
$plan = MAD4B_SCP_Activity_Import_Review::plan( $input );
ck( ! is_wp_error( $plan ) &&
    isset( $plan['issue_counts_by_reason']['date_interval_reversed'] ) &&
    isset( $plan['issue_counts_by_reason']['date_period_invalid'] ),
    'Site-configured ISO intervals failed to reject reversed and impossible dates' );
unset( $GLOBALS['imp07_period_test'] );

$parser = MAD4B_SCP_Activity_Import_Xlsx::parse( array() );
ck( is_wp_error( $parser ) &&
    in_array( $parser->get_error_code(),
        array( 'mad4b_xlsx_parser_unavailable', 'mad4b_xlsx_upload_invalid' ), true ),
    'Unconfigured/untrusted XLSX upload was not refused' );
echo "PASS IMP07 policy normalization, strict site-owned ISO dates, no silent executable XLSX (ISOLATED PHP)\n";
