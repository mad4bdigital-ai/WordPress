<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Pure compatibility view for a canonical Developer Authority V1 plan.
 *
 * The original plan, its SHA, historical signed approvals and grant inventory
 * are immutable. This additive V2 view may explain a first-party registered
 * operator policy, but cannot authorize Developer Authority or mutate a Grant.
 */
final class MAD4B_SCP_Developer_Authority_Policy_V2 {
    const CONTRACT = 'mad4b.developer-authority-policy-projection.v2';
    const LEGACY_PLAN_CONTRACT = 'mad4b.developer-authority.v1';

    public static function project( array $canonical_plan, $admission, $expected_ability ) {
        if ( ! is_array( $admission ) || is_wp_error( $admission )
            || 'eligible_for_exact_canonical_plan_only' !== ( $admission['state'] ?? null )
            || false !== ( $admission['authorization_issued'] ?? null )
            || false !== ( $admission['mutation_performed'] ?? null )
            || 'mad4b.registered-write-admission.v1' !== ( $admission['contract'] ?? null )
            || (string) $expected_ability !== (string) ( $admission['ability'] ?? '' ) ) {
            return new WP_Error( 'mad4b_developer_policy_v2_admission_invalid',
                'Exact registered first-party operation admission is required.' );
        }
        if ( self::LEGACY_PLAN_CONTRACT !== ( $canonical_plan['contract'] ?? null )
            || false !== ( $canonical_plan['production_allowed'] ?? null ) ) {
            return new WP_Error( 'mad4b_developer_policy_v2_plan_contract_unknown',
                'A complete canonical V1 Developer Authority plan is required.' );
        }
        $sha = strtolower( (string) ( $canonical_plan['plan_sha256'] ?? '' ) );
        $without_sha = $canonical_plan;
        unset( $without_sha['plan_sha256'] );
        $json = wp_json_encode( $without_sha, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
        if ( ! is_string( $json ) || 1 !== preg_match( '/^[a-f0-9]{64}$/D', $sha )
            || ! hash_equals( $sha, hash( 'sha256', $json ) ) ) {
            return new WP_Error( 'mad4b_developer_policy_v2_plan_sha_mismatch',
                'Current V1 plan SHA does not match its exact original bytes.' );
        }
        $uuid = strtolower( (string) ( $canonical_plan['site_uuid'] ?? '' ) );
        $digest = strtolower( (string) ( $canonical_plan['site_profile_digest'] ?? '' ) );
        $environment = sanitize_key( (string) ( $canonical_plan['environment'] ?? '' ) );
        $revision = (int) ( $canonical_plan['site_profile_revision'] ?? 0 );
        if ( ! hash_equals( $uuid, strtolower( (string) ( $admission['site_uuid'] ?? '' ) ) )
            || ! hash_equals( $digest, strtolower( (string) ( $admission['profile_digest'] ?? '' ) ) )
            || $revision < 1 || $revision !== (int) ( $admission['profile_revision'] ?? 0 )
            || '' === $environment || $environment !== (string) ( $admission['environment'] ?? '' ) ) {
            return new WP_Error( 'mad4b_developer_policy_v2_profile_binding_mismatch',
                'V1 plan and current V2 governance resolve different enrolled site revisions.' );
        }
        if ( 1 !== preg_match( '/^[a-f0-9]{64}$/D', (string) ( $admission['policy_sha256'] ?? '' ) )
            || empty( $admission['operation_id'] ) ) {
            return new WP_Error( 'mad4b_developer_policy_v2_policy_digest_invalid',
                'Current registered governance policy digest is missing.' );
        }
        $view = array(
            'contract' => self::CONTRACT,
            'legacy_plan_contract' => self::LEGACY_PLAN_CONTRACT,
            'legacy_plan_sha256' => $sha,
            'legacy_production_allowed_field' => false,
            'legacy_production_field_semantics' => 'historical_v1_preserved_not_current_policy',
            'operation_id' => (string) $admission['operation_id'],
            'ability' => (string) $expected_ability,
            'site_uuid' => $uuid, 'profile_revision' => $revision,
            'profile_digest' => $digest, 'environment' => $environment,
            'governance_policy_sha256' => strtolower( (string) $admission['policy_sha256'] ),
            'eligibility' => 'current_policy_preflight_only',
            'canonical_plan_modified' => false,
            'authorization_issued' => false, 'grant_issued' => false,
            'approval_issued' => false, 'mutation_performed' => false,
            'v2_view_is_authorizing' => false,
        );
        $view['view_sha256'] = hash( 'sha256', wp_json_encode( $view,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
        return $view;
    }
}
