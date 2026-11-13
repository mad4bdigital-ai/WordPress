<?php
/** Tests projection over exact immutable Developer V1 plan, without any grant mutation. */
require __DIR__ . '/operator-grants-convergence-smoke.php';
$ability = MAD4B_SCP_Operator_Grant_Convergence::ABILITY;
$canonical = MAD4B_SCP_Developer_Authority::plan();
$unchanged = wp_json_encode( $canonical, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
$admitted = MAD4B_SCP_Registered_Write_Admission::preflight( $ability );
function expect_projection( $label, $plan, $admission, $ability, $code = '' ) {
    $value = MAD4B_SCP_Developer_Authority_Policy_V2::project( $plan, $admission, $ability );
    $got = is_wp_error( $value ) ? $value->get_error_code() : '';
    if ( $code !== $got || ( '' === $code && ( ! is_array( $value )
        || ( $value['legacy_plan_sha256'] ?? '' ) !== ( $plan['plan_sha256'] ?? null )
        || ! empty( $value['authorization_issued'] ) || ! empty( $value['grant_issued'] )
        || ! empty( $value['approval_issued'] ) || ! empty( $value['mutation_performed'] )
        || ! empty( $value['canonical_plan_modified'] ) ) ) ) {
        fwrite( STDERR, 'FAIL ' . $label . ': ' . $got . PHP_EOL ); exit( 2 );
    }
    echo 'PASS ', $label, PHP_EOL;
}
expect_projection( 'valid exact V1 plan yields non-authorizing V2 policy', $canonical, $admitted, $ability );
$bad = $canonical; $bad['ready_to_apply'] = false;
expect_projection( 'tampered canonical plan rejected', $bad, $admitted, $ability,
    'mad4b_developer_policy_v2_plan_sha_mismatch' );
$bad = $canonical; $bad['plan_sha256'] = str_repeat( 'f', 64 );
expect_projection( 'forged canonical SHA rejected', $bad, $admitted, $ability,
    'mad4b_developer_policy_v2_plan_sha_mismatch' );
$bad = $canonical; $bad['production_allowed'] = true;
expect_projection( 'V1 production flag cannot be promoted to authorization', $bad, $admitted, $ability,
    'mad4b_developer_policy_v2_plan_contract_unknown' );
$bad = $admitted; $bad['authorization_issued'] = true;
expect_projection( 'forged authority in preflight denied', $canonical, $bad, $ability,
    'mad4b_developer_policy_v2_admission_invalid' );
$bad = $admitted; $bad['profile_revision'] = 5;
expect_projection( 'Profile revision mismatch denied', $canonical, $bad, $ability,
    'mad4b_developer_policy_v2_profile_binding_mismatch' );
$bad = $admitted; $bad['environment'] = 'staging';
expect_projection( 'environment mismatch denied', $canonical, $bad, $ability,
    'mad4b_developer_policy_v2_profile_binding_mismatch' );
$bad = $admitted; $bad['site_uuid'] = '33333333-3333-4333-8333-333333333333';
expect_projection( 'site identity mismatch denied', $canonical, $bad, $ability,
    'mad4b_developer_policy_v2_profile_binding_mismatch' );
$bad = $admitted; $bad['policy_sha256'] = '';
expect_projection( 'missing current policy digest denied', $canonical, $bad, $ability,
    'mad4b_developer_policy_v2_policy_digest_invalid' );
$bad = $admitted; $bad['ability'] = 'mad4b/developer-breakglass';
expect_projection( 'other operation policy cannot be borrowed', $canonical, $bad, $ability,
    'mad4b_developer_policy_v2_admission_invalid' );
if ( $unchanged !== wp_json_encode( $canonical, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE )
    || MAD4B_SCP_Developer_Authority::$apply_calls !== 0 ) exit( 3 );
echo 'PASS V1 plan remained byte-for-byte unchanged; zero authority/Grants created', PHP_EOL;
