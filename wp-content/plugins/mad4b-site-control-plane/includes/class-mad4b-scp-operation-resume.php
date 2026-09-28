<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Safe read-only resume guidance for durable idempotent operations.
 *
 * Never guesses whether a timed-out write took effect. Pending/expired work is
 * always reconciliation-first.
 */
final class MAD4B_SCP_Operation_Resume {
	const CONTRACT = 'mad4b.operation-resume-status.v1';

	public static function boot() {
		add_action( 'wp_abilities_api_init', array( __CLASS__, 'register_ability' ), 34 );
	}

	public static function register_ability() {
		if ( ! function_exists( 'wp_register_ability' ) || ( function_exists( 'wp_has_ability' ) && wp_has_ability( 'mad4b/operation-resume-status' ) ) ) return;
		wp_register_ability( 'mad4b/operation-resume-status', array(
			'label' => 'Operation Resume Status',
			'description' => 'Read durable idempotency state after reconnect and return safe resume/reconciliation guidance without replaying mutation.',
			'category' => 'mad4b-read',
			'execute_callback' => array( __CLASS__, 'status' ),
			'permission_callback' => array( 'MAD4B_SCP_Policy', 'can_read' ),
			'input_schema' => array(
				'type' => 'object',
				'properties' => array(
					'scope_key' => array( 'type' => 'string', 'minLength' => 64, 'maxLength' => 64 ),
					'idempotency_key' => array( 'type' => 'string', 'minLength' => 1, 'maxLength' => 191 ),
					'request_sha256' => array( 'type' => 'string', 'minLength' => 64, 'maxLength' => 64 ),
				),
				'required' => array( 'scope_key', 'idempotency_key', 'request_sha256' ),
				'additionalProperties' => false,
			),
			'output_schema' => array( 'type' => 'object', 'additionalProperties' => true ),
			'meta' => array(
				'public' => false,
				'show_in_rest' => false,
				'mcp' => array( 'public' => false, 'type' => 'tool', 'surface' => 'read' ),
				'annotations' => array( 'readonly' => true, 'destructive' => false, 'idempotent' => true ),
			),
		) );
	}

	public static function status( $input ) {
		global $wpdb;
		$input = is_array( $input ) ? $input : array();
		$scope = strtolower( trim( isset( $input['scope_key'] ) ? (string) $input['scope_key'] : '' ) );
		$key = trim( isset( $input['idempotency_key'] ) ? (string) $input['idempotency_key'] : '' );
		$request_sha = strtolower( trim( isset( $input['request_sha256'] ) ? (string) $input['request_sha256'] : '' ) );
		if ( ! preg_match( '/^[a-f0-9]{64}$/', $scope ) || ! preg_match( '/^[a-f0-9]{64}$/', $request_sha ) || '' === $key ) {
			return new WP_Error( 'mad4b_operation_resume_identity_invalid', 'Exact durable operation identity is required.' );
		}
		if ( ! class_exists( 'MAD4B_SCP_Schema' ) || ! MAD4B_SCP_Schema::is_ready() ) return new WP_Error( 'mad4b_operation_resume_schema_unavailable', 'Durable execution schema is unavailable.' );
		$t = MAD4B_SCP_Schema::tables();
		$row = $wpdb->get_row( $wpdb->prepare(
			"SELECT scope_key,idempotency_key,request_sha256,claim_epoch,status,result_sha256,reconciliation_ref,expires_at,created_at,updated_at FROM {$t['idempotency']} WHERE scope_key=%s AND idempotency_key=%s LIMIT 1",
			$scope, $key
		), ARRAY_A );
		if ( ! is_array( $row ) ) return new WP_Error( 'mad4b_operation_resume_not_found', 'No durable operation exists for the supplied identity.' );
		if ( ! hash_equals( (string) $row['request_sha256'], $request_sha ) ) return new WP_Error( 'mad4b_operation_resume_hash_conflict', 'Durable operation request hash does not match.' );

		$status = sanitize_key( (string) $row['status'] );
		$expired = empty( $row['expires_at'] ) || strtotime( (string) $row['expires_at'] . ' UTC' ) <= time();
		$action = 'do_not_retry';
		$reconciliation_required = false;
		$retry_allowed = false;
		if ( 'completed' === $status ) $action = 'consume_completed_receipt';
		elseif ( 'released_verified_no_effect' === $status ) { $action = 'replan_then_retry'; $retry_allowed = true; }
		elseif ( 'pending' === $status && $expired ) { $action = 'reconcile_provider_state_before_any_retry'; $reconciliation_required = true; }
		elseif ( 'pending' === $status ) $action = 'wait_or_reconnect_without_replay';

		return array(
			'contract' => self::CONTRACT,
			'found' => true,
			'status' => $status,
			'claim_epoch' => isset( $row['claim_epoch'] ) ? (int) $row['claim_epoch'] : 0,
			'expired' => $expired,
			'result_sha256' => isset( $row['result_sha256'] ) ? (string) $row['result_sha256'] : '',
			'reconciliation_ref_present' => ! empty( $row['reconciliation_ref'] ),
			'expires_at' => isset( $row['expires_at'] ) ? (string) $row['expires_at'] : '',
			'updated_at' => isset( $row['updated_at'] ) ? (string) $row['updated_at'] : '',
			'reconciliation_required' => $reconciliation_required,
			'retry_allowed' => $retry_allowed,
			'client_action' => $action,
			'automatic_mutation_retry_allowed' => false,
			'result_payload_exposed' => false,
			'mutation_performed' => false,
			'authority_created' => false,
		);
	}
}
