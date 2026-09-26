<?php

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Read-only scheduler/backlog admission evaluator.
 * Implements repository semantics from mad4b.cron-growth.v1 and
 * mad4b.fairness-local-autonomy.v1 without dispatching work.
 */
final class MAD4B_SCP_Scheduler_Admission {
	const CONTRACT = 'mad4b.scheduler-admission.v1';

	public static function boot() {
		add_action( 'wp_abilities_api_init', array( __CLASS__, 'register_abilities' ), 39 );
	}
	public static function register_abilities() {
		if ( ! function_exists( 'wp_register_ability' ) ) return;
		if ( ! ( function_exists( 'wp_has_ability' ) && wp_has_ability( 'mad4b/scheduler-admission-evaluate' ) ) ) {
			wp_register_ability( 'mad4b/scheduler-admission-evaluate', array(
				'label' => 'Scheduler Admission Evaluation',
				'description' => 'Evaluate fair scheduling, backlog, quota, provider throttling and local-autonomy constraints. Read-only.',
				'category' => 'mad4b-read',
				'execute_callback' => array( __CLASS__, 'evaluate' ),
				'permission_callback' => array( 'MAD4B_SCP_Policy', 'can_read' ),
				'input_schema' => array( 'type' => 'object', 'additionalProperties' => true ),
				'output_schema' => array( 'type' => 'object', 'additionalProperties' => true ),
				'meta' => array(
					'public' => false, 'show_in_rest' => false,
					'mcp' => array( 'public' => false, 'type' => 'tool', 'surface' => 'read' ),
					'annotations' => array( 'readonly' => true, 'destructive' => false, 'idempotent' => true ),
				),
			) );
		}
		if ( ! ( function_exists( 'wp_has_ability' ) && wp_has_ability( 'mad4b/scheduler-fair-rank' ) ) ) {
			wp_register_ability( 'mad4b/scheduler-fair-rank', array(
				'label' => 'Scheduler Fair Queue Ranking',
				'description' => 'Rank already-admitted queued work with deterministic weighted aging. Read-only and non-authorizing.',
				'category' => 'mad4b-read',
				'execute_callback' => array( __CLASS__, 'fair_rank' ),
				'permission_callback' => array( 'MAD4B_SCP_Policy', 'can_read' ),
				'input_schema' => array( 'type' => 'object', 'additionalProperties' => true ),
				'output_schema' => array( 'type' => 'object', 'additionalProperties' => true ),
				'meta' => array(
					'public' => false, 'show_in_rest' => false,
					'mcp' => array( 'public' => false, 'type' => 'tool', 'surface' => 'read' ),
					'annotations' => array( 'readonly' => true, 'destructive' => false, 'idempotent' => true ),
				),
			) );
		}
	}

	private static function stable( $value ) {
		if ( is_array( $value ) ) {
			$is_list = array_keys( $value ) === range( 0, count( $value ) - 1 );
			if ( ! $is_list ) ksort( $value, SORT_STRING );
			foreach ( $value as $k => $v ) $value[ $k ] = self::stable( $v );
		}
		return $value;
	}
	private static function digest( $value ) {
		return hash( 'sha256', wp_json_encode( self::stable( $value ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
	}

	public static function evaluate( $input = array() ) {
		$input = is_array( $input ) ? $input : array();
		$work = isset( $input['work'] ) && is_array( $input['work'] ) ? $input['work'] : array();
		$queue = isset( $input['queue_state'] ) && is_array( $input['queue_state'] ) ? $input['queue_state'] : array();
		$quotas = isset( $input['quotas'] ) && is_array( $input['quotas'] ) ? $input['quotas'] : array();
		$central = isset( $input['central_state'] ) && is_array( $input['central_state'] ) ? $input['central_state'] : array();

		$tenant = sanitize_key( (string) ( $work['tenant_id'] ?? '' ) );
		$site = sanitize_key( (string) ( $work['site_id'] ?? '' ) );
		$job_type = sanitize_key( (string) ( $work['job_type'] ?? '' ) );
		$provider = sanitize_key( (string) ( $work['provider_id'] ?? '' ) );
		$priority = sanitize_key( (string) ( $work['priority_class'] ?? 'normal' ) );
		$high_risk = ! empty( $work['high_risk'] );
		$reasons = array();

		if ( '' === $tenant || '' === $site || '' === $job_type ) $reasons[] = 'work_identity_required';

		$central_available = ! array_key_exists( 'available', $central ) || ! empty( $central['available'] );
		$evidence_current = ! empty( $central['signed_evidence_current'] );
		$independent_local = ! empty( $central['independent_local_policy_authorizes'] );
		if ( ! $central_available && $high_risk && ! ( $evidence_current && $independent_local ) ) {
			$decision = 'FAIL_CLOSED';
			$reasons[] = 'central_governance_unavailable_for_high_risk';
		} else {
			$max_queued = isset( $quotas['max_queued_jobs'] ) ? max( 0, (int) $quotas['max_queued_jobs'] ) : 0;
			$max_running = isset( $quotas['max_concurrent_jobs'] ) ? max( 0, (int) $quotas['max_concurrent_jobs'] ) : 0;
			$queued = isset( $queue['tenant_queued'] ) ? max( 0, (int) $queue['tenant_queued'] ) : 0;
			$running = isset( $queue['tenant_running'] ) ? max( 0, (int) $queue['tenant_running'] ) : 0;
			$provider_rate_limited = ! empty( $queue['provider_rate_limited'] );
			$reserved_remaining = isset( $queue['reserved_recovery_slots_remaining'] ) ? max( 0, (int) $queue['reserved_recovery_slots_remaining'] ) : 0;
			$recovery_classes = array( 'incident_repair', 'rollback', 'authority_health', 'publication_verification' );
			$is_recovery = in_array( $job_type, $recovery_classes, true );

			if ( ! empty( $reasons ) ) {
				$decision = 'DENY';
			} elseif ( $max_queued > 0 && $queued >= $max_queued ) {
				$decision = 'DENY';
				$reasons[] = 'queued_quota_exhausted';
			} elseif ( $is_recovery && $reserved_remaining > 0 ) {
				$decision = 'RESERVED_LANE';
				$reasons[] = 'reserved_operational_capacity';
			} elseif ( $provider_rate_limited && '' !== $provider ) {
				$decision = 'QUEUE';
				$reasons[] = 'provider_rate_limited';
			} elseif ( $max_running > 0 && $running >= $max_running ) {
				$decision = 'QUEUE';
				$reasons[] = 'concurrency_quota_reached';
			} else {
				$decision = 'ADMIT';
			}
		}

		$weight = isset( $work['fairness_weight'] ) ? max( 1, (int) $work['fairness_weight'] ) : 1;
		$evidence = array(
			'tenant_id' => $tenant, 'site_id' => $site, 'job_type' => $job_type,
			'provider_id' => $provider, 'priority_class' => $priority,
			'fairness_weight' => $weight, 'central_available' => $central_available,
		);
		return array(
			'contract' => self::CONTRACT,
			'decision' => $decision,
			'reasons' => array_values( array_unique( $reasons ) ),
			'evidence' => $evidence,
			'decision_fingerprint' => self::digest( array( $decision, $reasons, $evidence, $quotas ) ),
			'dispatch_performed' => false,
			'authority_bypassed' => false,
			'mutation_performed' => false,
			'authorizing' => false,
		);
	}

	public static function fair_rank( $input = array() ) {
		$input = is_array( $input ) ? $input : array();
		$items = isset( $input['items'] ) && is_array( $input['items'] ) ? array_values( $input['items'] ) : array();
		$now = isset( $input['now_epoch'] ) ? max( 0, (int) $input['now_epoch'] ) : 0;
		if ( $now <= 0 ) {
			return array(
				'contract' => 'mad4b.scheduler-fair-rank.v1',
				'eligible' => array(), 'rejected' => array(),
				'reason_code' => 'now_epoch_required',
				'authorizing' => false, 'mutation_performed' => false,
			);
		}
		$eligible = array();
		$rejected = array();
		foreach ( $items as $index => $item ) {
			if ( ! is_array( $item ) ) { $rejected[] = array( 'index' => $index, 'reason_code' => 'item_invalid' ); continue; }
			$id = sanitize_text_field( (string) ( $item['job_id'] ?? '' ) );
			$tenant = sanitize_key( (string) ( $item['tenant_id'] ?? '' ) );
			$site = sanitize_key( (string) ( $item['site_id'] ?? '' ) );
			$admitted = ! empty( $item['admitted'] );
			$authority_current = ! array_key_exists( 'authority_current', $item ) || ! empty( $item['authority_current'] );
			$quota_current = ! array_key_exists( 'quota_current', $item ) || ! empty( $item['quota_current'] );
			$enqueued_at = isset( $item['enqueued_at_epoch'] ) ? max( 0, (int) $item['enqueued_at_epoch'] ) : 0;
			$weight = isset( $item['fairness_weight'] ) ? max( 1, (int) $item['fairness_weight'] ) : 1;
			$priority = sanitize_key( (string) ( $item['priority_class'] ?? 'normal' ) );
			if ( '' === $id || '' === $tenant || '' === $site || $enqueued_at <= 0 || $enqueued_at > $now ) {
				$rejected[] = array( 'job_id' => $id, 'reason_code' => 'queue_identity_invalid' ); continue;
			}
			if ( ! $admitted ) { $rejected[] = array( 'job_id' => $id, 'reason_code' => 'not_admitted' ); continue; }
			if ( ! $authority_current ) { $rejected[] = array( 'job_id' => $id, 'reason_code' => 'authority_not_current' ); continue; }
			if ( ! $quota_current ) { $rejected[] = array( 'job_id' => $id, 'reason_code' => 'quota_not_current' ); continue; }
			$age = max( 0, $now - $enqueued_at );
			$priority_boost = in_array( $priority, array( 'incident', 'recovery', 'high' ), true ) ? 3600 : 0;
			// Aging dominates bounded weight over time; low-weight work therefore cannot starve forever.
			$score = $age + $priority_boost + min( 3600, 300 * $weight );
			$eligible[] = array(
				'job_id' => $id, 'tenant_id' => $tenant, 'site_id' => $site,
				'priority_class' => $priority, 'fairness_weight' => $weight,
				'age_seconds' => $age, 'fair_score' => $score,
			);
		}
		usort( $eligible, static function( $a, $b ) {
			if ( $a['fair_score'] === $b['fair_score'] ) return strcmp( $a['job_id'], $b['job_id'] );
			return $a['fair_score'] > $b['fair_score'] ? -1 : 1;
		} );
		return array(
			'contract' => 'mad4b.scheduler-fair-rank.v1',
			'eligible' => $eligible,
			'rejected' => $rejected,
			'next_job_id' => empty( $eligible ) ? '' : (string) $eligible[0]['job_id'],
			'anti_starvation' => true,
			'authority_bypass_allowed' => false,
			'quota_bypass_allowed' => false,
			'authorizing' => false,
			'mutation_performed' => false,
		);
	}

}

MAD4B_SCP_Scheduler_Admission::boot();
