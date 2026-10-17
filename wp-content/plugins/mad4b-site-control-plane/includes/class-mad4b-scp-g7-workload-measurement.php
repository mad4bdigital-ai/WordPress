<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Truthful coverage denominator projection over pre-existing metric buckets.
 * Aggregates alone are not proof of receipt-bound verified automation.
 */
final class MAD4B_SCP_G7_Workload_Measurement {
    const CONTRACT = 'mad4b.feature007-g7-workload-measurement.v1';
    const METRICS = array(
        'g7.eligible_workload', 'g7.verified_automatic_repair',
        'g7.human_intervention', 'g7.reconciliation', 'g7.repair_latency_ms',
        'g7.repair_cost_micros'
    );
    public static function status( $hours = 24 ) {
        $hours = (int) $hours;
        if ( $hours < 1 || $hours > 720 ) return self::error( 'window_invalid' );
        if ( ! class_exists( 'MAD4B_SCP_Runtime_Metrics' ) ||
            ! method_exists( 'MAD4B_SCP_Runtime_Metrics', 'summary' ) ) return self::error( 'metric_source_unavailable' );
        $source = MAD4B_SCP_Runtime_Metrics::summary( self::METRICS, $hours );
        if ( is_wp_error( $source ) ) return $source;
        if ( ! is_array( $source ) ||
            ( $source['contract'] ?? '' ) !== MAD4B_SCP_Runtime_Metrics::CONTRACT ||
            ! isset( $source['metrics'] ) || ! is_array( $source['metrics'] ) ||
            ( $source['hours'] ?? null ) !== $hours ) return self::error( 'metric_envelope_invalid' );
        $rows = array(); $valid = true;
        foreach ( $source['metrics'] as $metric ) {
            if ( ! is_array( $metric ) || ! in_array( $metric['name'] ?? null, self::METRICS, true ) ||
                ! isset( $metric['count'], $metric['sum'] ) || ! is_int( $metric['count'] ) ||
                ! is_int( $metric['sum'] ) || $metric['count'] < 0 || $metric['sum'] < 0 ||
                isset( $rows[ $metric['name'] ] ) ) { $valid = false; break; }
            $rows[ $metric['name'] ] = array( 'count' => $metric['count'], 'sum' => $metric['sum'] );
        }
        if ( ! $valid ) return self::error( 'metric_rows_invalid' );
        $eligible = isset( $rows['g7.eligible_workload'] ) ? $rows['g7.eligible_workload']['sum'] : null;
        $verified = isset( $rows['g7.verified_automatic_repair'] ) ? $rows['g7.verified_automatic_repair']['sum'] : null;
        $interventions = isset( $rows['g7.human_intervention'] ) ? $rows['g7.human_intervention']['sum'] : null;
        // No independent operation-id deduplication, receipt association or
        // signed policy denominator exists yet: rates must remain unavailable.
        return array(
            'contract' => self::CONTRACT, 'hours' => $hours,
            'state' => 'INCOMPLETE_EVIDENCE',
            'bucket_counts_unverified' => array(
                'eligible_workload' => $eligible,
                'automatic_repairs_labelled_verified' => $verified,
                'human_interventions' => $interventions,
            ),
            'eligible_workload_denominator_verified' => false,
            'operation_receipt_deduplication_verified' => false,
            'automatic_repair_rate_percent' => null,
            'intervention_rate_percent' => null,
            'mttr_seconds' => null, 'failure_rate_percent' => null,
            'cost_per_verified_repair' => null,
            'target_90_95_percent_achieved' => false,
            'next_step' => 'instrument_exact_operation_ids_and_signed_terminal_readback_receipts_before_certifying_rates',
            'authorizing' => false, 'mutation_performed' => false, 'read_only' => true
        );
    }

    private static function error( $reason ) {
        return new WP_Error( 'mad4b_g7_metrics_' . $reason, 'Verified workload metrics need a bounded trusted metric source.', array( 'authorizing' => false ) );
    }
}
