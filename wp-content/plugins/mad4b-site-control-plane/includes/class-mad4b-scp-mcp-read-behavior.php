<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Operator-pinned behavioral canary evidence, not server annotations.
 * A manifest row is a pointer to separately reviewed Staging observations,
 * never an automatic assertion that an untested MCP tool is side-effect-free.
 */
final class MAD4B_SCP_MCP_Read_Behavior {
    const CONTRACT = 'mad4b.native-mcp-read-behavior.v1';
    const MAX_EVIDENCE_AGE = 2592000;

    public static function certified( $row, $peer_id, $ability, $tool ) {
        if ( ! is_array( $row ) || ! is_string( $peer_id )
            || ! is_string( $ability ) || ! is_string( $tool ) ) return false;
        $canary = $row['read_behavior_certification'] ?? null;
        if ( ! is_array( $canary ) || ( $canary['contract'] ?? '' ) !== self::CONTRACT
            || true !== ( $canary['owner_reviewed'] ?? false )
            || true !== ( $canary['no_side_effects_observed'] ?? false )
            || ( $canary['environment'] ?? '' ) !== 'staging'
            || ( $canary['peer_id'] ?? '' ) !== $peer_id
            || ( $canary['ability'] ?? '' ) !== $ability
            || ( $canary['tool'] ?? '' ) !== $tool
            || ( $canary['schema_sha256'] ?? '' ) !== ( $row['schema_sha256'] ?? '' )
            || ! is_int( $canary['observed_at'] ?? null )
            || ! is_int( $canary['valid_until'] ?? null )
            || $canary['observed_at'] <= 0
            || $canary['observed_at'] > time() + 300
            || $canary['observed_at'] + self::MAX_EVIDENCE_AGE < time()
            || $canary['valid_until'] <= time()
            || $canary['valid_until'] > $canary['observed_at'] + self::MAX_EVIDENCE_AGE ) return false;
        foreach ( array( 'before_state_sha256', 'after_state_sha256',
            'independent_evidence_sha256' ) as $field ) {
            if ( ! is_string( $canary[$field] ?? null )
                || ! preg_match( '/^[a-f0-9]{64}$/D', $canary[$field] ) )
                return false;
        }
        // Canaries must prove the declared observation scope was unchanged.
        // A SHA-256 alone is not proof of provenance; source authority and
        // actual evidence are verified by the owner before manifest pinning.
        return hash_equals( $canary['before_state_sha256'], $canary['after_state_sha256'] );
    }
}
