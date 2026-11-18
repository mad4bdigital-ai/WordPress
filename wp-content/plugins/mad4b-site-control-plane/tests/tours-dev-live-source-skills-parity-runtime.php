<?php
/** Regression: live company Skills visibility, without privilege or source-management regression. */
error_reporting( E_ALL );
define( 'ABSPATH', __DIR__ . '/' );
$root = dirname( __DIR__ );
require_once $root . '/includes/class-mad4b-scp-skill-runtime-certification.php';
require_once $root . '/includes/class-mad4b-scp-skills-admin-ui.php';
function check_case( $value, $message ) {
    if ( ! $value ) { fwrite( STDERR, 'FAIL: ' . $message . "\n" ); exit( 1 ); }
}
$none = MAD4B_SCP_Skill_Runtime_Certification::capability_readiness( array() );
check_case( true !== $none['catalog_read_ready'], 'Empty evidence must not certify Skills catalog' );
check_case( false === $none['execution_authority_granted'], 'No execution grant from catalog' );
$requirements = array(
    'site_profile_enrolled','skills_feature_enabled','storage_initialized',
    'storage_writable','seed_pack_ready','provider_reconciliation_ready',
    'provider_mutation_absent','provider_skill_delete_absent',
    'base_skills_enabled','read_abilities_registered','skill_write_abilities_absent',
    'snapshot_has_enabled_skills','snapshot_identity_ready',
    'snapshot_identity_skill_count_matches','build_provenance_identity_ready',
);
$checks = array_fill_keys( $requirements, true );
$checks['profile_app_mapping_bound'] = false;
$checks['editor_enabled'] = false;
$checks['scripts_disabled'] = true;
$read = MAD4B_SCP_Skill_Runtime_Certification::capability_readiness( $checks );
check_case( true === $read['catalog_read_ready'], 'Verified catalog must read without optional App' );
check_case( true === $read['local_operations_ready'], 'Local operations must not depend on App' );
check_case( false === $read['external_app_projection_ready'], 'Optional external App must remain unbound' );
check_case( false === $read['authoring_ready'], 'Production editor must remain disabled' );
check_case( false === $read['execution_authority_granted'], 'Read readiness must not grant execution' );
$checks['storage_writable'] = false;
$blocked = MAD4B_SCP_Skill_Runtime_Certification::capability_readiness( $checks );
check_case( ! $blocked['catalog_read_ready'], 'Failed required check must block catalog readiness' );
check_case( in_array( 'storage_writable', $blocked['catalog_blockers'], true ), 'Missing capability blocker' );
$manifest = array(
    array( 'name'=>'core-reader', 'level'=>'site', 'target'=>'wordpress', 'enabled'=>true ),
    array( 'name'=>'nile-cruise', 'level'=>'site', 'target'=>'wordpress', 'enabled'=>true ),
    array( 'name'=>'optional-feature', 'level'=>'site', 'target'=>'wordpress', 'enabled'=>false ),
);
$installed = array(array( 'name'=>'core-reader', 'level'=>'site', 'target'=>'wordpress', 'enabled'=>true ));
$other_company = MAD4B_SCP_Skills_Admin_UI::coverage_plan( $installed, $manifest, false );
check_case( 0 === count($other_company['missing_required']), 'Cruise-specific Skill must be optional off cruise sites' );
check_case( 2 === count($other_company['missing_optional']), 'Optional Skills must be tracked, not auto-installed' );
check_case( false === $other_company['execution_allowed'], 'Coverage is not permission to execute' );
$cruise = MAD4B_SCP_Skills_Admin_UI::coverage_plan( $installed, $manifest, true );
check_case( 1 === count($cruise['missing_required']), 'Cruise Skill must be required only for applicable site' );
check_case( 'inspect_exact_seed_pack_before_governed_reconcile' === $cruise['next_action'], 'Missing Skills require review, not silent install' );
$ui = file_get_contents($root . '/includes/class-mad4b-scp-skills-admin-ui.php');
foreach (array('SCP_External_Skill_Sources::handle_admin( \'add\'','SCP_External_Skill_Sources::handle_admin( \'remove\'','SCP_External_Skill_Sources::render_admin();','mad4b_manage_company_operations','render_operations_page') as $guard ) {
    check_case( false !== strpos( $ui, $guard ), 'Existing source administration or company cap missing: '.$guard );
}
echo "PASS: Tours.dev Skills source parity 16+ read/auth/coverage and legacy source guards\n";
