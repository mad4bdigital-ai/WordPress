<?php
/** IMP10 exact-site dynamic mapping evolution negative acceptance fixture. */
require __DIR__ . '/imp01-import-preview-runtime.php';
require __DIR__ . '/../includes/class-mad4b-scp-import-mapping-evolution.php';

$profile = MAD4B_SCP_Content_Experience_Profiles::profile( 'pricing' );
$headers = array( 'ID', 'base_currency', 'singlePrice', 'double_price',
    '_wpml_import_translation_group', '_wpml_import_language_code',
    '_wpml_import_after_process_post_status' );
$base = array( 'profile_slug' => 'pricing',
    'observed_headers' => $headers,
    'expected_profile_authority_sha256' => $profile['authority_sha256'] );
$plan = MAD4B_SCP_Import_Mapping_Evolution::plan( $base );
ck( ! is_wp_error( $plan ) &&
    $plan['source_evidence_kind'] === 'header_only_unstaged' &&
    !$plan['safe_to_reuse_old_mapping'] &&
    !$plan['mapping_mutation_authorized'] &&
    $plan['requires_new_governed_profile_revision'] &&
    count( $plan['rename_suggestions'] ) === 1 &&
    $plan['rename_suggestions'][0]['old_source_column'] === 'single_price' &&
    $plan['rename_suggestions'][0]['proposed_source_column'] === 'singlePrice',
    'Renamed source column was either missed or silently mapped into production' );
ck( $plan['rename_suggestions'][0]['confidence_class'] ===
    'lexical_candidate_not_semantic_proof' &&
    $plan['header_only_is_not_accepted_import_data'],
    'Header-only inference was wrongly treated as commercial import validation' );
$policy = $profile['import_contract']['validation'];
$proposal = $policy;
unset( $proposal['field_mapping']['single_price'] );
$proposal['field_mapping']['singlePrice'] = 'single_price';
$proposal['price_fields'] = array( 'singlePrice', 'double_price' );
$proposal['required_columns'] = array_map(
    static function( $x ) { return $x === 'single_price' ? 'singlePrice' : $x; },
    $proposal['required_columns'] );
$simulation = MAD4B_SCP_Import_Mapping_Evolution::simulate(
    array_merge( $base, array(
        'proposal_plan_sha256' => $plan['plan_sha256'],
        'candidate_validation' => $proposal ) ) );
ck( ! is_wp_error( $simulation ) &&
    $simulation['schema_preview_passed'] &&
    in_array( 'field_mapping', $simulation['high_impact_changes'], true ) &&
    !$simulation['candidate_live_import_authorized'] &&
    !$simulation['candidate_profile_apply_authorized'] &&
    !$simulation['mutation_performed'],
    'Dangerous schema mapping changes were silently applied' );
$identity_change = $proposal;
$identity_change['destination_identity_meta_key'] = 'single_price';
$identity_denied = MAD4B_SCP_Import_Mapping_Evolution::simulate(
    array_merge( $base, array(
        'proposal_plan_sha256' => $plan['plan_sha256'],
        'candidate_validation' => $identity_change ) ) );
ck( is_wp_error( $identity_denied ) &&
    $identity_denied->get_error_code() ===
        'mad4b_mapping_identity_registry_mutation_denied',
    'Destination identity registry was rewritten from a suggestion' );
$override = $base;
$override['untrusted_policy_override'] = true;
ck( is_wp_error( MAD4B_SCP_Import_Mapping_Evolution::plan( $override ) ),
    'Import source supplied its own validation policy' );
$stale = $base;
$stale['expected_profile_authority_sha256'] = str_repeat( '0', 64 );
ck( is_wp_error( MAD4B_SCP_Import_Mapping_Evolution::plan( $stale ) ),
    'Stale profile authority accepted a mapping candidate' );
$duplicate = $base;
$duplicate['observed_headers'][] = 'singlePrice';
ck( is_wp_error( MAD4B_SCP_Import_Mapping_Evolution::plan( $duplicate ) ),
    'Duplicate source headers accepted' );
$wrong = $proposal;
$wrong['field_mapping']['singlePrice'] = 'not_allowlisted_private_field';
ck( is_wp_error( MAD4B_SCP_Import_Mapping_Evolution::simulate(
    array_merge( $base, array(
        'proposal_plan_sha256' => $plan['plan_sha256'],
        'candidate_validation' => $wrong ) ) ) ),
    'Unauthorized destination Meta field accepted' );
echo "PASS IMP10 dynamic header drift, lexical-only suggestions, exact proposal simulation and no mapping mutation (ISOLATED PHP)\n";
