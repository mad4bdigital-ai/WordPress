<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Pure, non-authorizing policy reducer for the future unified remote operator.
 *
 * Input facts MUST come from separately verified OAuth, owner attestation,
 * registered Host Runner identity, and immutable Site Profile records.
 * Passing a caller-supplied "verified" flag grants no authority by itself.
 * This class executes no command, SQL, filesystem mutation or subprocess.
 */
final class MAD4B_SCP_Operator_Session_Policy {
    const CONTRACT = 'mad4b.operator-session-policy.v1';
    const MAX_TTL_SECONDS = 900;

    private static function deny( $reason ) {
        return array(
            'contract' => self::CONTRACT,
            'allowed' => false,
            'reason' => (string) $reason,
            'mutation_performed' => false,
            'production_authorized' => false,
        );
    }

    /**
     * Return a candidate policy decision; caller still has to verify the
     * session's cryptographic origin and consume any required approval.
     */
    public static function evaluate( $session, $operation, $trusted_facts ) {
        if ( ! is_array( $session ) || ! is_array( $trusted_facts ) || ! is_string( $operation ) ) {
            return self::deny( 'invalid_input' );
        }
        if ( ( $trusted_facts['environment'] ?? null ) !== 'staging'
            || ( $session['environment'] ?? null ) !== 'staging' ) {
            return self::deny( 'staging_only' );
        }
        if ( ! empty( $trusted_facts['kill_switch_enabled'] ) ) {
            return self::deny( 'kill_switch_enabled' );
        }
        foreach ( array( 'site_uuid', 'target_fingerprint', 'agent_public_id' ) as $key ) {
            $a = (string) ( $session[ $key ] ?? '' );
            $b = (string) ( $trusted_facts[ $key ] ?? '' );
            if ( '' === $a || '' === $b || ! hash_equals( $a, $b ) ) {
                return self::deny( 'exact_binding_mismatch_' . $key );
            }
        }
        if ( 'mad4b.operator-session.v1' !== ( $session['contract'] ?? null ) ) {
            return self::deny( 'session_contract_invalid' );
        }
        $issued = $session['issued_at_epoch'] ?? null;
        $expires = $session['expires_at_epoch'] ?? null;
        $now = $trusted_facts['now_epoch'] ?? null;
        if ( ! is_int( $issued ) || ! is_int( $expires ) || ! is_int( $now )
            || $expires <= $issued || $expires - $issued > self::MAX_TTL_SECONDS
            || $issued > $now || $expires <= $now ) {
            return self::deny( 'session_expired_or_invalid' );
        }
        $scope_map = array(
            'shell' => 'shell',
            'wp-cli' => 'wp-cli',
            'sql.read' => 'sql.read',
            'sql.write' => 'sql.write',
            'sql.schema' => 'sql.schema',
        );
        if ( ! isset( $scope_map[ $operation ] ) ) {
            return self::deny( 'operation_not_supported' );
        }
        $scopes = $session['scopes'] ?? null;
        if ( ! is_array( $scopes ) || ! in_array( $scope_map[ $operation ], $scopes, true ) ) {
            return self::deny( 'scope_denied' );
        }
        // This is a risk classification, not a substitute for approval.
        $risk = in_array( $operation, array( 'sql.write', 'sql.schema', 'shell' ), true )
            ? 'operator_mutation_possible' : 'operator_read_or_wpcli';
        return array(
            'contract' => self::CONTRACT,
            'allowed' => true,
            'reason' => 'candidate_scoped_session',
            'risk' => $risk,
            'operation' => $operation,
            'session_expires_at_epoch' => $expires,
            'requires_independent_authentication' => true,
            'requires_worker_binding_and_attested_receipt' => true,
            'mutation_performed' => false,
            'production_authorized' => false,
        );
    }
}
