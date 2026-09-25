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
		if ( function_exists( 'wp_has_ability' ) && wp_has_ability( 'mad4b/scheduler-admission-evaluate' ) ) return;
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
}

MAD4B_SCP_Scheduler_Admission::boot();
