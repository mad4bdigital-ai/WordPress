<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
require_once __DIR__ . '/class-mad4b-scp-adaptive-operations-context.php';

/**
 * Read-only, short-lived host observation. Binary discovery is not a successful
 * network sandbox rehearsal and cannot admit developer execution.
 */
final class MAD4B_SCP_G7_Host_Readiness {
    const CONTRACT = 'mad4b.feature007-g7-host-readiness.v1';
    const TTL = 120;

    public static function capture() {
        if ( ! class_exists( 'MAD4B_SCP_Developer_Host_Capabilities' ) ) return self::error( 'probe_unavailable' );
        $binding = MAD4B_SCP_Adaptive_Operations_Context::current();
        if ( is_wp_error( $binding ) ) return $binding;
        $snapshot = MAD4B_SCP_Developer_Host_Capabilities::snapshot();
        if ( ! is_array( $snapshot ) || ( $snapshot['contract'] ?? '' ) !== MAD4B_SCP_Developer_Host_Capabilities::CONTRACT ) return self::error( 'snapshot_untrusted' );
        $material = array(
            'contract' => self::CONTRACT,
            'binding' => $binding,
            'observed_at' => time(),
            'expires_at' => time() + self::TTL,
            'host_capability_fingerprint' => $snapshot['capability_fingerprint'] ?? '',
            'source_contract' => MAD4B_SCP_Developer_Host_Capabilities::CONTRACT,
        );
        if ( ! MAD4B_SCP_Adaptive_Operations_Context::sha( $material['host_capability_fingerprint'] ) ) return self::error( 'fingerprint_missing' );
        $sealed = MAD4B_SCP_Adaptive_Operations_Context::seal( self::CONTRACT, $material );
        if ( is_wp_error( $sealed ) ) return $sealed;
        return array( 'contract' => self::CONTRACT, 'sealed_observation' => $sealed,
            'assessment' => self::assess_snapshot( $snapshot ), 'read_only' => true,
            'authorizing' => false, 'mutation_performed' => false );
    }

    public static function verify( array $observation ) {
        $material = MAD4B_SCP_Adaptive_Operations_Context::unseal( self::CONTRACT, $observation['sealed_observation'] ?? array() );
        if ( is_wp_error( $material ) ) return $material;
        if ( ! is_array( $material ) || ( $material['contract'] ?? '' ) !== self::CONTRACT ||
            ( $material['source_contract'] ?? '' ) !== MAD4B_SCP_Developer_Host_Capabilities::CONTRACT ||
            ! isset( $material['binding'] ) || ! is_array( $material['binding'] ) ||
            ! isset( $material['observed_at'], $material['expires_at'] ) ||
            ! is_int( $material['observed_at'] ) || ! is_int( $material['expires_at'] ) ||
            $material['observed_at'] > time() + 5 || $material['expires_at'] <= time() ||
            $material['expires_at'] - $material['observed_at'] !== self::TTL ||
            ! MAD4B_SCP_Adaptive_Operations_Context::sha( $material['host_capability_fingerprint'] ?? '' ) ) return self::error( 'observation_stale_or_invalid' );
        $binding = MAD4B_SCP_Adaptive_Operations_Context::current();
        if ( is_wp_error( $binding ) ) return $binding;
        $same = MAD4B_SCP_Adaptive_Operations_Context::assert_same( $material['binding'], $binding );
        if ( is_wp_error( $same ) ) return $same;
        if ( ! class_exists( 'MAD4B_SCP_Developer_Host_Capabilities' ) ) return self::error( 'probe_unavailable' );
        $snapshot = MAD4B_SCP_Developer_Host_Capabilities::snapshot();
        if ( ! is_array( $snapshot ) || ! isset( $snapshot['capability_fingerprint'] ) ||
            ! is_string( $snapshot['capability_fingerprint'] ) ||
            ! hash_equals( $material['host_capability_fingerprint'], $snapshot['capability_fingerprint'] ) ) return self::error( 'host_drift' );
        return self::assess_snapshot( $snapshot );
    }

    /** Diagnostic only. A certified binary name never proves functional isolation. */
    public static function assess_snapshot( array $snapshot ) {
        $blockers = array();
        if ( ( $snapshot['contract'] ?? '' ) !== MAD4B_SCP_Developer_Host_Capabilities::CONTRACT ) $blockers[] = 'source_contract_invalid';
        if ( true !== ( $snapshot['resource_limiter_binary_present'] ?? null ) ||
             'prlimit' !== ( $snapshot['resource_limit_backend'] ?? null ) ) $blockers[] = 'prlimit_required';
        if ( true !== ( $snapshot['network_sandbox_binary_present'] ?? null ) ||
             true !== ( $snapshot['network_isolation_backend_certified'] ?? null ) ||
             ! in_array( $snapshot['network_isolation_backend'] ?? '', array( 'bubblewrap', 'unshare-net' ), true ) ) $blockers[] = 'network_sandbox_required';
        if ( true !== ( $snapshot['proc_open_available'] ?? null ) ) $blockers[] = 'process_probe_unavailable';
        if ( true !== ( $snapshot['non_root_verified'] ?? null ) ) $blockers[] = 'non_root_unverified';
        sort( $blockers, SORT_STRING );
        return array(
            'contract' => self::CONTRACT, 'state' => 'EXTERNAL_ACTION_REQUIRED',
            'candidate_prerequisites_present' => empty( $blockers ),
            'execution_eligible' => false, 'network_isolation_operationally_verified' => false,
            'blockers' => $blockers,
            'next_step' => empty( $blockers )
                ? 'certify_prlimit_and_network_isolation_using_separately_authorized_host_probe'
                : 'resolve_host_prerequisites_outside_wordpress_then_recheck_read_only',
            'installation_permitted' => false, 'host_mutation_performed' => false,
            'read_only' => true, 'authorizing' => false, 'mutation_performed' => false
        );
    }

    private static function error( $reason ) {
        return new WP_Error( 'mad4b_g7_host_' . $reason,
            'Host readiness requires fresh site-bound evidence and independent isolation certification.',
            array( 'authorizing' => false, 'mutation_performed' => false, 'reconciliation_required' => true ) );
    }
}
