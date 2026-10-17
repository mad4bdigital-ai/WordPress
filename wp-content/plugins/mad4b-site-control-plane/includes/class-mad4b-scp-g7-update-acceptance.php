<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
require_once __DIR__ . '/class-mad4b-scp-adaptive-operations-context.php';

/**
 * Non-authorizing update comparison. Does not prepare, perform or roll back
 * updates and never mints release acceptance or rebinds credentials/grants.
 */
final class MAD4B_SCP_G7_Update_Acceptance {
    const CONTRACT = 'mad4b.feature007-g7-update-observation.v1';
    const MAX_AGE = 604800; // Seven days of immutable before/after observations.

    public static function capture() {
        $binding = MAD4B_SCP_Adaptive_Operations_Context::current();
        if ( is_wp_error( $binding ) ) return $binding;
        if ( ! class_exists( 'MAD4B_SCP_Runtime_Evidence_Graph' ) ) return self::error( 'graph_unavailable' );
        $graph = MAD4B_SCP_Runtime_Evidence_Graph::snapshot( array( 'refresh' => true ) );
        if ( is_wp_error( $graph ) ) return $graph;
        if ( ! is_array( $graph ) || ( $graph['contract'] ?? '' ) !== MAD4B_SCP_Runtime_Evidence_Graph::CONTRACT ||
            ! MAD4B_SCP_Adaptive_Operations_Context::sha( $graph['generation_sha256'] ?? '' ) ) return self::error( 'graph_invalid' );
        // Keep the graph and its site/runtime/restore binding in one stable observation.
        $post_binding = MAD4B_SCP_Adaptive_Operations_Context::current();
        if ( is_wp_error( $post_binding ) ) return $post_binding;
        $same = MAD4B_SCP_Adaptive_Operations_Context::assert_same( $binding, $post_binding );
        if ( is_wp_error( $same ) ) return $same;
        $complete = self::graph_complete( $graph );
        $material = array(
            'contract' => self::CONTRACT, 'binding' => $binding, 'observed_at' => time(),
            'graph_generation_sha256' => $graph['generation_sha256'], 'graph_complete' => $complete,
            'source_contract' => MAD4B_SCP_Runtime_Evidence_Graph::CONTRACT,
            'authority_registry_recaptured' => false, 'skills_recertified' => false,
            'external_effects_verified' => false, 'reversal_verified' => false
        );
        $sealed = MAD4B_SCP_Adaptive_Operations_Context::seal( self::CONTRACT, $material );
        if ( is_wp_error( $sealed ) ) return $sealed;
        return array( 'contract' => self::CONTRACT, 'sealed_observation' => $sealed,
            'graph_complete' => $complete, 'read_only' => true, 'authorizing' => false,
            'acceptance_receipt_issued' => false, 'mutation_performed' => false );
    }

    public static function compare( array $before, array $after ) {
        $old = self::read( $before );
        if ( is_wp_error( $old ) ) return $old;
        $new = self::read( $after );
        if ( is_wp_error( $new ) ) return $new;
        if ( $old['observed_at'] > $new['observed_at'] ||
            $new['observed_at'] - $old['observed_at'] > self::MAX_AGE ||
            $new['observed_at'] < time() - 300 ) return self::error( 'observation_order_or_freshness_invalid' );
        $same_site = MAD4B_SCP_Adaptive_Operations_Context::assert_same( $old['binding'], $new['binding'], true );
        if ( is_wp_error( $same_site ) ) return $same_site;
        $current = MAD4B_SCP_Adaptive_Operations_Context::current();
        if ( is_wp_error( $current ) ) return $current;
        $same_current = MAD4B_SCP_Adaptive_Operations_Context::assert_same( $new['binding'], $current );
        if ( is_wp_error( $same_current ) ) return $same_current;
        if ( ! class_exists( 'MAD4B_SCP_Runtime_Evidence_Graph' ) ) return self::error( 'graph_unavailable' );
        $live_graph = MAD4B_SCP_Runtime_Evidence_Graph::snapshot( array( 'refresh' => true ) );
        if ( ! is_array( $live_graph ) || ( $live_graph['contract'] ?? '' ) !== MAD4B_SCP_Runtime_Evidence_Graph::CONTRACT ||
            ! MAD4B_SCP_Adaptive_Operations_Context::sha( $live_graph['generation_sha256'] ?? '' ) ||
            ! hash_equals( $new['graph_generation_sha256'], $live_graph['generation_sha256'] ) ||
            self::graph_complete( $live_graph ) !== $new['graph_complete'] ) {
            return self::error( 'post_observation_graph_drift' );
        }
        // A matching graph hash cannot hide a restore/runtime change during readback.
        $post_binding = MAD4B_SCP_Adaptive_Operations_Context::current();
        if ( is_wp_error( $post_binding ) ) return $post_binding;
        $same = MAD4B_SCP_Adaptive_Operations_Context::assert_same( $new['binding'], $post_binding );
        if ( is_wp_error( $same ) ) return $same;
        $runtime_changed = ! hash_equals( $old['binding']['runtime_generation'], $new['binding']['runtime_generation'] ) ||
            ! hash_equals( $old['binding']['artifact_sha256'], $new['binding']['artifact_sha256'] );
        $graph_changed = ! hash_equals( $old['graph_generation_sha256'], $new['graph_generation_sha256'] );
        $complete = true === $old['graph_complete'] && true === $new['graph_complete'];
        $state = ! $complete ? 'RECONCILIATION_REQUIRED'
            : ( ! $runtime_changed && ! $graph_changed ? 'NO_UPDATE_OBSERVED'
            : ( $graph_changed ? 'RECONCILIATION_REQUIRED' : 'APPROVAL_REQUIRED' ) );
        if ( 'production' === $new['binding']['environment'] ) $state = 'RECONCILIATION_REQUIRED';
        $comparison = array(
            'contract' => 'mad4b.feature007-g7-update-comparison.v1',
            'before_graph_sha256' => $old['graph_generation_sha256'],
            'after_graph_sha256' => $new['graph_generation_sha256'],
            'before_runtime_sha256' => $old['binding']['runtime_generation'],
            'after_runtime_sha256' => $new['binding']['runtime_generation'],
            'runtime_changed' => $runtime_changed, 'graph_changed' => $graph_changed,
            'graph_complete' => $complete, 'state' => $state,
            'reason' => ! $complete ? 'incomplete_graph_or_unknown_side_effects'
                : ( $graph_changed ? 'capability_graph_drift' :
                    ( $runtime_changed ? 'release_recertification_required' : 'no_update_observed' ) ),
            'authority_rebind_required' => $runtime_changed, 'skills_recertification_required' => $runtime_changed,
            'eligible_grant_projection_recheck_required' => $runtime_changed,
            'reversal_preapproval_required' => true, 'automatic_rollback_allowed' => false,
            'acceptance_receipt_issued' => false, 'external_effects_verified' => false,
            'authorizing' => false, 'mutation_performed' => false
        );
        $digest = MAD4B_SCP_Adaptive_Operations_Context::digest( $comparison['contract'], $comparison );
        if ( is_wp_error( $digest ) ) return $digest;
        $comparison['comparison_sha256'] = $digest;
        return $comparison;
    }

    /** Completeness is separately checked from the graph hash on fresh readback. */
    private static function graph_complete( array $graph ) {
        if ( true !== ( $graph['complete_for_absence'] ?? null ) ||
             true !== ( $graph['edge_status']['trustworthy_for_impact'] ?? null ) ) return false;
        $discovery = isset( $graph['discovery'] ) && is_array( $graph['discovery'] ) ? $graph['discovery'] : array();
        foreach ( array( 'callbacks_executed', 'unknown_plugin_code_executed', 'unknown_endpoints_invoked',
            'secret_values_read', 'writes_performed' ) as $key ) {
            if ( false !== ( $discovery[ $key ] ?? null ) ) return false;
        }
        return true;
    }

    private static function read( array $observation ) {
        if ( ! class_exists( 'MAD4B_SCP_Runtime_Evidence_Graph' ) ) return self::error( 'graph_unavailable' );
        $sealed = $observation['sealed_observation'] ?? array();
        if ( ! is_array( $sealed ) ) return self::error( 'observation_format_invalid' );
        $material = MAD4B_SCP_Adaptive_Operations_Context::unseal( self::CONTRACT, $sealed );
        if ( is_wp_error( $material ) ) return $material;
        if ( ! is_array( $material ) || ( $material['contract'] ?? '' ) !== self::CONTRACT ||
            ! isset( $material['binding'] ) || ! is_array( $material['binding'] ) ||
            ( $material['source_contract'] ?? '' ) !== MAD4B_SCP_Runtime_Evidence_Graph::CONTRACT ||
            ! MAD4B_SCP_Adaptive_Operations_Context::sha( $material['graph_generation_sha256'] ?? '' ) ||
            ! isset( $material['observed_at'] ) || ! is_int( $material['observed_at'] ) ||
            $material['observed_at'] < time() - self::MAX_AGE || $material['observed_at'] > time() + 5 ||
            ! isset( $material['graph_complete'] ) || ! is_bool( $material['graph_complete'] ) ||
            false !== ( $material['authority_registry_recaptured'] ?? null ) ||
            false !== ( $material['skills_recertified'] ?? null ) ||
            false !== ( $material['external_effects_verified'] ?? null ) ||
            false !== ( $material['reversal_verified'] ?? null ) ) return self::error( 'observation_invalid_or_stale' );
        $valid = MAD4B_SCP_Adaptive_Operations_Context::validate( $material['binding'] );
        return is_wp_error( $valid ) ? $valid : $material;
    }

    private static function error( $reason ) {
        return new WP_Error( 'mad4b_g7_update_' . $reason,
            'An exact verified post-update observation is required; no release acceptance has been issued.',
            array( 'authorizing' => false, 'mutation_performed' => false, 'reconciliation_required' => true ) );
    }
}
