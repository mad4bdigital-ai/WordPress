<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
/**
 * Reuse of PREVIOUSLY APPROVED grants only. This classifier is the gate for an
 * eventual lifecycle executor, not an authorization mechanism on its own.
 * The authoritative sealed baseline MUST be obtained from server storage,
 * never directly from MCP or user input.
 */
final class MAD4B_SCP_Nonproduction_Approved_Grants {
    const CONTRACT = 'mad4b.nonproduction-approved-grants.v1';
    private static function pairs( $rows ) {
        if ( ! is_array( $rows ) ) return null;
        $pairs = array();
        foreach ( $rows as $row ) {
            if ( ! is_array( $row ) || ! isset( $row['server_id'], $row['ability'], $row['provider'] ) ) return null;
            $server = (string) $row['server_id'];
            $ability = (string) $row['ability'];
            $provider = (string) $row['provider'];
            if ( ! in_array( $server, array( 'mad4b-write', 'mad4b-chatgpt' ), true )
                || ! preg_match( '/^[a-z0-9][a-z0-9_\/-]{2,127}$/D', $ability )
                || ! preg_match( '/^[a-z0-9_-]{2,64}$/D', $provider ) ) return null;
            $key = $server . "\0" . $ability . "\0" . $provider;
            if ( isset( $pairs[ $key ] ) ) return null;
            $pairs[ $key ] = true;
        }
        ksort( $pairs, SORT_STRING );
        return $pairs;
    }

    /**
     * @param array $policy Output of shared Environment Convergence Policy.
     * @param array $baseline Locally authenticated and sealed BEFORE update.
     * @param array $current Current immutable identity, grant/agent/site data.
     * @param array $missing Exact missing pairs from a fresh trusted inventory.
     */
    public static function evaluate( $policy, $baseline, $current, $missing ) {
        $reason = '';
        $approved = is_array( $baseline ) ? self::pairs( $baseline['approved_grants'] ?? null ) : null;
        $requested = self::pairs( $missing );
        if ( ! is_array( $policy ) || empty( $policy['auto_convergence_eligible'] )
            || ! in_array( $policy['environment'] ?? '', array( 'local', 'development', 'staging' ), true ) ) {
            $reason = 'nonproduction_authority_required';
        } elseif ( ! is_array( $baseline ) || empty( $baseline['seal_verified'] )
            || empty( $baseline['captured_before_update'] ) || empty( $baseline['audit_verified'] ) ) {
            $reason = 'sealed_prior_approval_required';
        } elseif ( ! is_array( $current ) || empty( $current['exact_profile_bound'] )
            || empty( $current['manifest_verified'] ) || empty( $current['agent_verified'] )
            || empty( $current['write_inventory_verified'] ) || ! empty( $current['breakglass_enabled'] )
            || ! empty( $current['wildcard_grants'] ) || ! empty( $current['explicit_denies'] ) ) {
            $reason = 'current_authority_unverified';
        } elseif ( $approved === null || $requested === null ) {
            $reason = 'invalid_or_duplicate_grant_pairs';
        } elseif ( empty( $baseline['site_uuid'] ) || empty( $baseline['agent_public_id'] )
            || empty( $baseline['profile_digest'] ) || empty( $baseline['previous_package_sha'] )
            || empty( $current['site_uuid'] ) || empty( $current['agent_public_id'] )
            || empty( $current['profile_digest'] ) || empty( $current['source_commit_sha'] )
            || ! hash_equals( (string) $baseline['site_uuid'], (string) $current['site_uuid'] )
            || ! hash_equals( (string) $baseline['agent_public_id'], (string) $current['agent_public_id'] )
            || ! hash_equals( (string) $baseline['profile_digest'], (string) $current['profile_digest'] )
            || empty( $current['approved_update_from_sha'] )
            || ! hash_equals( (string) $baseline['previous_package_sha'], (string) $current['approved_update_from_sha'] ) ) {
            $reason = 'source_identity_drift';
        } else {
            foreach ( $requested as $key => $v ) {
                if ( ! isset( $approved[$key] ) ) { $reason = 'grant_not_previously_approved'; break; }
            }
        }
        return array(
            'contract' => self::CONTRACT,
            'eligible' => '' === $reason,
            'reason' => $reason,
            'grant_count' => '' === $reason ? count( $requested ) : 0,
            'mutation_performed' => false,
            'production_allowed' => false,
            'new_grants_allowed' => false,
            'requires_exact_postcondition_and_audit' => true,
        );
    }
}
