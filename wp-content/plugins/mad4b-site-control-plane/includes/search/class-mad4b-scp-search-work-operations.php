<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Registered semantic work descriptors; registration never creates execution authority. */
final class MAD4B_SCP_Search_Work_Operations {
	public static function definitions( array $base = array() ) {
		$registered = apply_filters( 'mad4b_scp_semantic_work_operations', array( 'search.serp.capture' => array( 'executor' => 'provider_adapter', 'provider_family' => 'serp', 'authority_surface' => 'read_external', 'side_effect_class' => 'external_cost', 'retry_semantics' => 'reconciliation_first', 'idempotency' => 'semantic_digest', 'budget_class' => 'serp_search', 'production_policy' => 'deny', 'validate_payload' => array( __CLASS__, 'validate_capture_reference' ) ) ) );
		foreach ( (array) $registered as $id => $row ) {
			if ( isset( $base[ $id ] ) || ! is_string( $id ) || ! preg_match( '/^[a-z0-9][a-z0-9._-]{0,95}$/D', $id ) || ! is_array( $row ) || ! isset( $row['executor'], $row['authority_surface'], $row['production_policy'], $row['validate_payload'] ) || 'deny' !== $row['production_policy'] || ! is_callable( $row['validate_payload'] ) ) continue;
			$base[ $id ] = $row;
		}
		return $base;
	}
	public static function validate_capture_reference( array $payload ) {
		return ! array_diff( array_keys( $payload ), array( 'job_id', 'plan_sha256' ) ) && isset( $payload['job_id'], $payload['plan_sha256'] ) && MAD4B_SCP_Search_Contracts::sha( $payload['job_id'] ) && MAD4B_SCP_Search_Contracts::sha( $payload['plan_sha256'] );
	}
}
