<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Provider-account economic authority.
 *
 * A WordPress-local reservation can protect one database only and therefore
 * MUST report local_best_effort. A hard_global claim requires an authoritative
 * shared coordinator supplied through the explicit filters below.
 */
final class MAD4B_SCP_Provider_Account_Budget_Authority {
	const CONTRACT = 'mad4b.provider-account-budget-authority.v1';
	const OPTION_PREFIX = 'mad4b_scp_provider_budget_';
	const MAX_UNITS = 100000000;
	const MAX_TTL_SECONDS = 86400;

	private static function account_key( array $input ) {
		$provider = sanitize_key( (string) ( $input['provider_id'] ?? '' ) );
		$account = trim( (string) ( $input['provider_account_ref'] ?? '' ) );
		$credential = trim( (string) ( $input['credential_ref'] ?? '' ) );
		if ( '' === $provider || ( '' === $account && '' === $credential ) ) return new WP_Error( 'mad4b_provider_budget_account_identity_required', 'Provider and account/credential reference are required.' );
		return hash( 'sha256', $provider . "\n" . $account . "\n" . $credential );
	}

	private static function option_key( $account_key ) {
		return self::OPTION_PREFIX . substr( preg_replace( '/[^a-f0-9]/', '', strtolower( (string) $account_key ) ), 0, 40 );
	}

	private static function local_lock_name( $account_key ) {
		if ( ! class_exists( 'MAD4B_SCP_Distributed_Lock' ) ) return '';
		return MAD4B_SCP_Distributed_Lock::catalog_name( 'provider-account-budget:' . $account_key );
	}

	private static function validate_request( array $input ) {
		$key = self::account_key( $input );
		if ( is_wp_error( $key ) ) return $key;
		$mode = sanitize_key( (string) ( $input['enforcement_mode'] ?? 'local_best_effort' ) );
		if ( ! in_array( $mode, array( 'hard_global', 'local_best_effort' ), true ) ) return new WP_Error( 'mad4b_provider_budget_mode_invalid', 'Unknown provider-account budget enforcement mode.' );
		$units = (int) ( $input['units'] ?? 0 );
		$allowance = (int) ( $input['hard_allowance'] ?? 0 );
		$reserve = max( 0, (int) ( $input['protected_reserve'] ?? 0 ) );
		if ( $units < 1 || $units > self::MAX_UNITS || $allowance < 1 || $allowance > self::MAX_UNITS || $reserve >= $allowance ) {
			return new WP_Error( 'mad4b_provider_budget_bounds_invalid', 'Provider-account budget request is outside allowed bounds.' );
		}
		$cycle = sanitize_text_field( (string) ( $input['billing_cycle_id'] ?? '' ) );
		if ( '' === $cycle || strlen( $cycle ) > 128 ) return new WP_Error( 'mad4b_provider_budget_cycle_required', 'Billing cycle identity is required.' );
		$idempotency = trim( (string) ( $input['idempotency_key'] ?? '' ) );
		if ( '' === $idempotency || strlen( $idempotency ) > 256 ) return new WP_Error( 'mad4b_provider_budget_idempotency_required', 'Budget reservation requires an idempotency key.' );
		$ttl = max( 60, min( self::MAX_TTL_SECONDS, (int) ( $input['reservation_ttl_seconds'] ?? 900 ) ) );
		return array(
			'contract' => self::CONTRACT,
			'provider_id' => sanitize_key( (string) $input['provider_id'] ),
			'account_key' => $key,
			'enforcement_mode' => $mode,
			'units' => $units,
			'hard_allowance' => $allowance,
			'protected_reserve' => $reserve,
			'billing_cycle_id' => $cycle,
			'idempotency_key' => hash( 'sha256', $idempotency ),
			'reservation_ttl_seconds' => $ttl,
		);
	}

	private static function validate_authoritative_reservation( $response, array $request ) {
		if ( ! is_array( $response ) || empty( $response['authoritative'] ) || empty( $response['reservation_id'] ) || empty( $response['fencing_epoch'] ) ) {
			return new WP_Error( 'mad4b_provider_budget_shared_authority_required', 'Hard-global provider budget enforcement requires an authoritative shared reservation backend.' );
		}
		$used_after = isset( $response['used_after'] ) ? (int) $response['used_after'] : -1;
		$allowance = isset( $response['hard_allowance'] ) ? (int) $response['hard_allowance'] : (int) $request['hard_allowance'];
		if ( $used_after < 0 || $allowance < 1 || $used_after > $allowance - (int) $request['protected_reserve'] ) {
			return new WP_Error( 'mad4b_provider_budget_authoritative_overspend', 'Authoritative provider-account reservation would violate the configured hard allowance.' );
		}
		return array(
			'contract' => self::CONTRACT,
			'enforcement_scope' => 'hard_global',
			'hard_global_guarantee' => true,
			'provider_id' => $request['provider_id'],
			'account_key' => $request['account_key'],
			'billing_cycle_id' => $request['billing_cycle_id'],
			'reservation_id' => (string) $response['reservation_id'],
			'fencing_epoch' => (string) $response['fencing_epoch'],
			'units' => (int) $request['units'],
			'used_after' => $used_after,
			'hard_allowance' => $allowance,
			'authoritative_backend' => sanitize_key( (string) ( $response['backend_id'] ?? 'external_shared_authority' ) ),
			'authorizing' => false,
		);
	}

	public static function reserve( array $input ) {
		$request = self::validate_request( $input );
		if ( is_wp_error( $request ) ) return $request;
		if ( 'hard_global' === $request['enforcement_mode'] ) {
			$response = apply_filters( 'mad4b_scp_provider_account_budget_authoritative_reserve', null, $request );
			return self::validate_authoritative_reservation( $response, $request );
		}
		return self::reserve_local( $request );
	}

	private static function reserve_local( array $request ) {
		if ( ! function_exists( 'get_option' ) || ! function_exists( 'update_option' ) || ! class_exists( 'MAD4B_SCP_Distributed_Lock' ) ) {
			return new WP_Error( 'mad4b_provider_budget_local_backend_unavailable', 'Local provider budget backend requires WordPress option storage and distributed locking.' );
		}
		$lock = self::local_lock_name( $request['account_key'] );
		if ( '' === $lock ) return new WP_Error( 'mad4b_provider_budget_local_lock_unavailable', 'Local provider budget lock is unavailable.' );
		$acquired = MAD4B_SCP_Distributed_Lock::acquire( $lock );
		if ( is_wp_error( $acquired ) ) return $acquired;
		try {
			$key = self::option_key( $request['account_key'] );
			$state = get_option( $key, array() );
			if ( ! is_array( $state ) || (string) ( $state['billing_cycle_id'] ?? '' ) !== $request['billing_cycle_id'] ) {
				$state = array(
					'contract' => self::CONTRACT,
					'billing_cycle_id' => $request['billing_cycle_id'],
					'committed_used' => 0,
					'provider_observed_used' => 0,
					'reservations' => array(),
				);
			}
			$now = time();
			foreach ( is_array( $state['reservations'] ?? null ) ? $state['reservations'] : array() as $id => $row ) {
				if ( 'reserved' === (string) ( $row['state'] ?? '' ) && (int) ( $row['expires_at'] ?? 0 ) <= $now ) unset( $state['reservations'][ $id ] );
			}
			$reservation_id = substr( hash( 'sha256', $request['account_key'] . "\n" . $request['billing_cycle_id'] . "\n" . $request['idempotency_key'] ), 0, 48 );
			if ( isset( $state['reservations'][ $reservation_id ] ) ) {
				$row = $state['reservations'][ $reservation_id ];
				if ( (int) ( $row['units'] ?? 0 ) !== (int) $request['units'] ) return new WP_Error( 'mad4b_provider_budget_idempotency_conflict', 'Budget reservation idempotency key was reused with different units.' );
				return self::local_receipt( $request, $reservation_id, $state, true );
			}
			$reserved = 0;
			foreach ( $state['reservations'] as $row ) if ( 'reserved' === (string) ( $row['state'] ?? '' ) ) $reserved += (int) ( $row['units'] ?? 0 );
			$effective_used = max( (int) ( $state['committed_used'] ?? 0 ), (int) ( $state['provider_observed_used'] ?? 0 ) );
			$ceiling = (int) $request['hard_allowance'] - (int) $request['protected_reserve'];
			if ( (int) $request['units'] > $ceiling - $effective_used - $reserved ) {
				return new WP_Error( 'mad4b_provider_budget_exhausted', 'Local provider-account budget reservation would exceed the configured allowance.', array(
					'enforcement_scope' => 'local_best_effort',
					'hard_global_guarantee' => false,
					'effective_used' => $effective_used,
					'reserved_units' => $reserved,
					'remaining' => max( 0, $ceiling - $effective_used - $reserved ),
				) );
			}
			$state['reservations'][ $reservation_id ] = array(
				'state' => 'reserved',
				'units' => (int) $request['units'],
				'expires_at' => $now + (int) $request['reservation_ttl_seconds'],
				'idempotency_key' => $request['idempotency_key'],
			);
			update_option( $key, $state, false );
			return self::local_receipt( $request, $reservation_id, $state, false );
		} finally {
			MAD4B_SCP_Distributed_Lock::release( $lock );
		}
	}

	private static function local_receipt( array $request, $reservation_id, array $state, $replayed ) {
		$reserved = 0;
		foreach ( is_array( $state['reservations'] ?? null ) ? $state['reservations'] : array() as $row ) if ( 'reserved' === (string) ( $row['state'] ?? '' ) ) $reserved += (int) ( $row['units'] ?? 0 );
		$effective_used = max( (int) ( $state['committed_used'] ?? 0 ), (int) ( $state['provider_observed_used'] ?? 0 ) );
		return array(
			'contract' => self::CONTRACT,
			'enforcement_scope' => 'local_best_effort',
			'hard_global_guarantee' => false,
			'provider_id' => $request['provider_id'],
			'account_key' => $request['account_key'],
			'billing_cycle_id' => $request['billing_cycle_id'],
			'reservation_id' => (string) $reservation_id,
			'fencing_epoch' => '',
			'units' => (int) $request['units'],
			'effective_used' => $effective_used,
			'reserved_units' => $reserved,
			'hard_allowance' => (int) $request['hard_allowance'],
			'protected_reserve' => (int) $request['protected_reserve'],
			'idempotent_replay' => (bool) $replayed,
			'claim' => 'local_best_effort_plus_provider_reconciliation',
			'authorizing' => false,
		);
	}

	public static function commit( array $reservation ) {
		if ( 'hard_global' === (string) ( $reservation['enforcement_scope'] ?? '' ) ) {
			$response = apply_filters( 'mad4b_scp_provider_account_budget_authoritative_commit', null, $reservation );
			return is_array( $response ) && ! empty( $response['authoritative'] ) ? $response : new WP_Error( 'mad4b_provider_budget_shared_commit_required', 'Hard-global reservation requires authoritative commit acknowledgement.' );
		}
		return self::transition_local( $reservation, 'committed' );
	}

	public static function release( array $reservation ) {
		if ( 'hard_global' === (string) ( $reservation['enforcement_scope'] ?? '' ) ) {
			$response = apply_filters( 'mad4b_scp_provider_account_budget_authoritative_release', null, $reservation );
			return is_array( $response ) && ! empty( $response['authoritative'] ) ? $response : new WP_Error( 'mad4b_provider_budget_shared_release_required', 'Hard-global reservation requires authoritative release acknowledgement.' );
		}
		return self::transition_local( $reservation, 'released' );
	}

	private static function transition_local( array $reservation, $target_state ) {
		if ( ! function_exists( 'get_option' ) || ! function_exists( 'update_option' ) || ! class_exists( 'MAD4B_SCP_Distributed_Lock' ) ) return new WP_Error( 'mad4b_provider_budget_local_backend_unavailable', 'Local provider budget backend unavailable.' );
		$account_key = (string) ( $reservation['account_key'] ?? '' );
		$reservation_id = (string) ( $reservation['reservation_id'] ?? '' );
		if ( '' === $account_key || '' === $reservation_id ) return new WP_Error( 'mad4b_provider_budget_reservation_invalid', 'Reservation identity is incomplete.' );
		$lock = self::local_lock_name( $account_key );
		$acquired = MAD4B_SCP_Distributed_Lock::acquire( $lock );
		if ( is_wp_error( $acquired ) ) return $acquired;
		try {
			$key = self::option_key( $account_key );
			$state = get_option( $key, array() );
			if ( ! is_array( $state ) || ! isset( $state['reservations'][ $reservation_id ] ) ) return new WP_Error( 'mad4b_provider_budget_reservation_missing', 'Local provider-account reservation was not found.' );
			$row = $state['reservations'][ $reservation_id ];
			if ( $target_state === (string) ( $row['state'] ?? '' ) ) return array( 'contract' => self::CONTRACT, 'state' => $target_state, 'idempotent_replay' => true );
			if ( 'reserved' !== (string) ( $row['state'] ?? '' ) ) return new WP_Error( 'mad4b_provider_budget_reservation_state_invalid', 'Reservation cannot transition from its current state.' );
			$units = (int) ( $row['units'] ?? 0 );
			$state['reservations'][ $reservation_id ]['state'] = $target_state;
			if ( 'committed' === $target_state ) $state['committed_used'] = (int) ( $state['committed_used'] ?? 0 ) + $units;
			update_option( $key, $state, false );
			return array( 'contract' => self::CONTRACT, 'state' => $target_state, 'units' => $units, 'idempotent_replay' => false );
		} finally {
			MAD4B_SCP_Distributed_Lock::release( $lock );
		}
	}

	public static function reconcile( array $input ) {
		$key = self::account_key( $input );
		if ( is_wp_error( $key ) ) return $key;
		$mode = sanitize_key( (string) ( $input['enforcement_mode'] ?? 'local_best_effort' ) );
		if ( 'hard_global' === $mode ) {
			$response = apply_filters( 'mad4b_scp_provider_account_budget_authoritative_reconcile', null, $input + array( 'account_key' => $key ) );
			return is_array( $response ) && ! empty( $response['authoritative'] ) ? $response : new WP_Error( 'mad4b_provider_budget_shared_reconciliation_required', 'Hard-global reconciliation requires authoritative shared account evidence.' );
		}
		if ( ! function_exists( 'get_option' ) || ! function_exists( 'update_option' ) || ! class_exists( 'MAD4B_SCP_Distributed_Lock' ) ) return new WP_Error( 'mad4b_provider_budget_local_backend_unavailable', 'Local provider budget backend unavailable.' );
		$provider_used = max( 0, (int) ( $input['provider_observed_used'] ?? 0 ) );
		$cycle = sanitize_text_field( (string) ( $input['billing_cycle_id'] ?? '' ) );
		$allowance = max( 1, (int) ( $input['hard_allowance'] ?? 1 ) );
		$lock = self::local_lock_name( $key );
		$acquired = MAD4B_SCP_Distributed_Lock::acquire( $lock );
		if ( is_wp_error( $acquired ) ) return $acquired;
		try {
			$option = self::option_key( $key );
			$state = get_option( $option, array() );
			if ( ! is_array( $state ) || (string) ( $state['billing_cycle_id'] ?? '' ) !== $cycle ) $state = array( 'contract' => self::CONTRACT, 'billing_cycle_id' => $cycle, 'committed_used' => 0, 'provider_observed_used' => 0, 'reservations' => array() );
			$state['provider_observed_used'] = max( (int) ( $state['provider_observed_used'] ?? 0 ), $provider_used );
			update_option( $option, $state, false );
			$effective = max( (int) $state['committed_used'], (int) $state['provider_observed_used'] );
			return array(
				'contract' => self::CONTRACT,
				'enforcement_scope' => 'local_best_effort',
				'hard_global_guarantee' => false,
				'effective_used' => $effective,
				'provider_observed_used' => (int) $state['provider_observed_used'],
				'locally_committed_used' => (int) $state['committed_used'],
				'exhausted' => $effective >= $allowance,
				'provider_reconciliation_applied' => true,
			);
		} finally {
			MAD4B_SCP_Distributed_Lock::release( $lock );
		}
	}
}
