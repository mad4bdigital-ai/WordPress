<?php
/** IMP09 source-integrated read-only gates under isolated WordPress stubs. */
require __DIR__ . '/imp01-import-preview-runtime.php';
require __DIR__ . '/../includes/class-mad4b-scp-activity-import-xlsx.php';
require __DIR__ . '/../includes/class-mad4b-scp-import-acceptance-gates.php';
$gate = MAD4B_SCP_Import_Acceptance_Gates::status(
    array( 'profile_slug' => 'pricing' ) );
ck( !is_wp_error( $gate ) &&
    $gate['runtime_probe_executed'] &&
    $gate['provider_presence_checks']['enrolled_site'] &&
    $gate['provider_presence_checks']['staging_environment'] &&
    $gate['provider_presence_checks']['profile_owned_import_validation'] &&
    $gate['provider_presence_checks']['aes_gcm_encrypted_review_key'] &&
    $gate['provider_presence_not_certification'] &&
    count( $gate['uncertified_acceptance_gates'] ) >= 8 &&
    !$gate['ready_for_automatic_import'] &&
    !$gate['production_promotion_authorized'] &&
    !$gate['source_code_release_ready'] &&
    !$gate['mutation_performed'],
    'Readiness inventory promoted mere plugin detection to production certification' );
$GLOBALS['imp02_enabled_modes'] = array();
$empty = MAD4B_SCP_Import_Acceptance_Gates::status(
    array( 'profile_slug' => 'pricing' ) );
ck( !is_wp_error( $empty ) &&
    !$empty['provider_presence_checks']['source_mode_allowlist_nonempty'] &&
    !$empty['provider_presence_checks']['bounded_batch_review_allowed'],
    'Empty site Mode allowlist was reported ready' );
unset( $GLOBALS['imp02_enabled_modes'] );
$notFound = MAD4B_SCP_Import_Acceptance_Gates::status(
    array( 'profile_slug' => 'unconfigured' ) );
ck( is_wp_error( $notFound ), 'Unconfigured site Profile was accepted' );
echo "PASS IMP09 site-profile/source-key-provider presence vs independent runtime certification (ISOLATED PHP)\n";
