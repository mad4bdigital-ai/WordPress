<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
require_once __DIR__ . '/class-mad4b-scp-g9-resilience-gates.php';

/**
 * Non-authorizing fleet outcome reducer. Site receipts must be verified by
 * their OWN current local execution/acceptance plane; a caller-provided status
 * or hash is merely an assertion, never an authority or acceptance record.
 */
final class MAD4B_SCP_G9_Fleet_Rollout {
    const CONTRACT = 'mad4b.g9.fleet-rollout-observation.v1';
    const MAX_EVENTS = 256;
    const MAX_PER_SITE = 32;

    private static function error( $reason ) {
        return new WP_Error( 'mad4b_g9_fleet_' . $reason,
            'Fleet evidence is incomplete, conflicting or from an unknown site.',
            array( 'authorizing'=>false, 'mutation_performed'=>false,
                'blind_retry_allowed'=>false, 'reconciliation_required'=>true ) );
    }

    /**
     * Aggregate bounded multi-site claims without transporting grants or
     * mutating a host. A successful native mutation never becomes a fleet
     * release certificate; signed per-site acceptance is a separate gate.
     */
    public static function inspect( array $observations, array $events ) {
        $inventory = MAD4B_SCP_G9_Resilience_Gates::fleet_inventory( $observations );
        if ( is_wp_error( $inventory ) ) return $inventory;
        if ( count( $events ) > self::MAX_EVENTS ) return self::error( 'event_capacity' );
        $sites = array();
        foreach ( $inventory['sites'] as $site ) {
            $sites[ $site['site_key'] ] = array(
                'site_key'=>$site['site_key'],
                'binding_sha256'=>$site['binding_sha256'],
                'last_sequence'=>0,
                'last_reported_state'=>'NO_EVENT',
                'event_count'=>0,
                'reported_rollback'=>false,
                'reported_rollback_completed'=>false,
                'reported_native_commit'=>false,
                'local_release_acceptance_verified'=>false,
                'external_effect_readback_verified'=>false,
            );
        }
        $ledger = array();
        $seen = array();
        $last_operation_state = array();
        foreach ( $events as $event ) {
            if ( ! is_array( $event ) || ( $event['contract'] ?? '' ) !== self::CONTRACT
                || ! is_string( $event['site_key'] ?? null )
                || ! isset( $sites[ $event['site_key'] ] )
                || ( $event['binding_sha256'] ?? '' ) !== $sites[ $event['site_key'] ]['binding_sha256']
                || ! is_int( $event['sequence'] ?? null ) || $event['sequence'] < 1
                || ! MAD4B_SCP_Resilience_Context::is_hash( $event['operation_sha256'] ?? '' )
                || ! in_array( $event['action'] ?? null, array( 'promote', 'rollback' ), true )
                || ! in_array( $event['state'] ?? null,
                    array( 'PREPARED', 'EXECUTING', 'COMMITTED', 'FAILED', 'RECONCILING', 'UNKNOWN' ), true ) )
                return self::error( 'event_invalid' );
            $key = $event['site_key'];
            if ( $sites[ $key ]['event_count'] >= self::MAX_PER_SITE )
                return self::error( 'per_site_capacity' );
            // Require a single ordered stream per site; gaps indicate lost
            // events, not proof that the absent transition never happened.
            if ( $event['sequence'] !== $sites[ $key ]['last_sequence'] + 1 )
                return self::error( 'event_gap_or_replay' );
            $opkey = $key . ':' . $event['operation_sha256'];
            if ( isset( $seen[ $opkey ] )
                && $seen[ $opkey ] !== $event['action'] )
                return self::error( 'operation_action_conflict' );
            // A terminal or uncertain observation cannot later reverse into
            // an incompatible state for the SAME native operation. This is
            // not a verified execution journal; contradictions are errors.
            if ( isset( $last_operation_state[ $opkey ] ) ) {
                $previous = $last_operation_state[ $opkey ];
                $next = $event['state'];
                if ( in_array( $previous, array( 'COMMITTED', 'FAILED', 'UNKNOWN', 'RECONCILING' ), true )
                    && $next !== $previous )
                    return self::error( 'contradictory_terminal_claim' );
                if ( 'EXECUTING' === $previous && 'PREPARED' === $next )
                    return self::error( 'regressive_operation_claim' );
            }
            $last_operation_state[ $opkey ] = $event['state'];
            $seen[ $opkey ] = $event['action'];
            $sites[ $key ]['last_sequence'] = $event['sequence'];
            $sites[ $key ]['last_reported_state'] = $event['state'];
            $sites[ $key ]['event_count']++;
            if ( 'rollback' === $event['action'] ) {
                // A PREPARED/RECONCILING rollback is only intent, not evidence
                // that any rollback finished. Do not label it "observed
                // rollback completed" in fleet summaries.
                $sites[ $key ]['reported_rollback'] = true;
                if ( 'COMMITTED' === $event['state'] )
                    $sites[ $key ]['reported_rollback_completed'] = true;
            }
            if ( 'promote' === $event['action'] && 'COMMITTED' === $event['state'] )
                $sites[ $key ]['reported_native_commit'] = true;
            $ledger[] = array(
                'site_key'=>$key, 'sequence'=>$event['sequence'],
                'operation_sha256'=>$event['operation_sha256'],
                'action'=>$event['action'], 'reported_state'=>$event['state'],
                'cryptographic_acceptance_verified'=>false,
                'provider_mutation_performed_by_reducer'=>false,
            );
        }
        $uncertain = array(); $missing = array();
        $reported_committed = array(); $reported_rollbacks = array();
        $completed_rollback_claims = array();
        foreach ( $sites as $key => $site ) {
            if ( 0 === $site['event_count'] ) $missing[] = $key;
            // Last event of a different operation must not obscure an older
            // PREPARED, FAILED or uncertain operation at the same site.
            // A PREPARED operation has not reached a terminal outcome.
            $site_uncertain = false;
            foreach ( $last_operation_state as $operation_key => $operation_state ) {
                if ( 0 === strpos( $operation_key, $key . ':' )
                    && 'COMMITTED' !== $operation_state ) {
                    $site_uncertain = true;
                    break;
                }
            }
            if ( $site_uncertain ) $uncertain[] = $key;
            if ( $site['reported_native_commit'] ) $reported_committed[] = $key;
            if ( $site['reported_rollback'] ) $reported_rollbacks[] = $key;
            if ( $site['reported_rollback_completed'] ) $completed_rollback_claims[] = $key;
        }
        ksort( $sites, SORT_STRING );
        return array(
            'contract'=>self::CONTRACT,
            'site_count'=>count( $sites ),
            'sites'=>array_values( $sites ),
            'event_count'=>count( $ledger ),
            'ledger'=>$ledger,
            'missing_site_evidence'=>$missing,
            'uncertain_site_evidence'=>$uncertain,
            'reported_committed_sites'=>$reported_committed,
            'reported_rollback_sites'=>$reported_rollbacks,
            'reported_rollback_completed_sites'=>$completed_rollback_claims,
            'rollback_risk_detected'=>! empty( $reported_rollbacks )
                && ( count( $completed_rollback_claims ) < count( $sites )
                    || ! empty( $uncertain ) || ! empty( $missing ) ),
            // Only a claimed terminal rollback can count as "observed";
            // even then this is NOT cryptographically verified acceptance.
            'partial_rollback_observed'=>! empty( $completed_rollback_claims )
                && ( count( $completed_rollback_claims ) < count( $sites )
                    || ! empty( $uncertain ) || ! empty( $missing ) ),
            'release_acceptance_verified'=>false,
            'all_site_effects_reconciled'=>false,
            'cohort_promotion_allowed'=>false,
            'automatic_rollback_allowed'=>false,
            'site_local_signed_acceptance_required'=>true,
            'independent_execution_readback_required'=>true,
            'authorizing'=>false, 'mutation_performed'=>false,
        );
    }
}
