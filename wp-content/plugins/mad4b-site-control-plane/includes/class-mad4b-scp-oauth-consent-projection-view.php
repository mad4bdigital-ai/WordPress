<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Passive OAuth consent presentation contract. This is NOT an OAuth authority,
 * a grant verifier, a readiness override, or a host-repair executor.
 */
final class MAD4B_SCP_OAuth_Consent_Projection_View {
    const CONTRACT = 'mad4b.oauth-consent-projection-view.v1';

    public static function safe_list( $rows, $limit = 150 ) {
        $out = array();
        $invalid = 0;
        if ( ! is_array( $rows ) ) return array( 'rows' => array(), 'invalid' => 1, 'omitted' => 0 );
        $limit = max( 0, min( 150, (int) $limit ) );
        foreach ( $rows as $row ) {
            if ( ! is_array( $row ) ) { ++$invalid; continue; }
            $raw_name = isset( $row['ability'] ) && is_string( $row['ability'] ) ? $row['ability'] : '';
            $raw_provider = isset( $row['provider'] ) && is_string( $row['provider'] ) ? $row['provider'] : '';
            $name = trim( $raw_name ); $provider = trim( $raw_provider );
            if ( $raw_name !== $name || $raw_provider !== $provider
                || '' === $name || '' === $provider || strlen( $name ) > 191 || strlen( $provider ) > 80
                || ! preg_match( '/^[a-z][a-z0-9._-]*\/[a-z][a-z0-9._-]*$/D', $name )
                || ! preg_match( '/^[a-z0-9][a-z0-9._-]*$/D', $provider ) ) {
                ++$invalid;
                continue;
            }
            if ( count( $out ) < $limit ) $out[] = array( 'ability' => $name, 'provider' => $provider );
        }
        return array( 'rows' => $out, 'invalid' => $invalid,
            'omitted' => max( 0, count( $rows ) - $invalid - count( $out ) ) );
    }

    /** An authorized Developer agent may still be unable to run on this host. */
    public static function developer_execution( $authority_ready, $host ) {
        $host = is_array( $host ) ? $host : array();
        $backend = true === ( $host['process_backend_ready'] ?? null );
        $network = true === ( $host['normal_no_network_execution_ready'] ?? null );
        $blockers = array();
        foreach ( array( 'process_backend_blockers', 'normal_no_network_execution_blockers' ) as $key ) {
            if ( ! isset( $host[ $key ] ) || ! is_array( $host[ $key ] ) ) continue;
            foreach ( $host[ $key ] as $value ) {
                if ( ! is_string( $value ) || strlen( $value ) > 96
                    || ! preg_match( '/^[a-z][a-z0-9_]*$/D', $value ) ) continue;
                $blockers[ $value ] = true;
                if ( count( $blockers ) >= 12 ) break;
            }
        }
        if ( ! $backend || ! $network ) {
            if ( ! $blockers ) $blockers['host_execution_not_certified'] = true;
        }
        $prerequisites = $backend && $network;
        // OS binary/path probes are not an independent no-network execution
        // certificate. Until a bound host canary receipt is independently
        // verified, this presentation projection MUST fail closed.
        if ( $prerequisites ) $blockers['host_behavior_uncertified'] = true;
        return array(
            'contract' => self::CONTRACT, 'authority_ready' => true === $authority_ready,
            'host_prerequisites_ready' => $prerequisites,
            'host_execution_ready' => false,
            'host_execution_certification' => 'NOT_CERTIFIED',
            'operational_ready' => false, 'blockers' => array_keys( $blockers ),
            'read_only' => true, 'mutation_performed' => false,
            'host_installation_performed' => false, 'authorizing' => false,
        );
    }
}
