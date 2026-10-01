<?php

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Cross-version runtime maintenance lease.
 *
 * One atomic option owns the current maintenance lane while the two legacy
 * options remain mirrored as compatibility fences for older loaded runtimes.
 * The short lease is renewable; the hard deadline prevents an immediately
 * expired lease from being stolen while a slow dbDelta/filesystem phase is
 * still completing. Token readback acts as a fencing check before a worker may
 * publish readiness.
 */
final class MAD4B_SCP_Runtime_Maintenance_Lease {
	const CONTRACT = 'mad4b.runtime-maintenance-lease.v1';
	const OPTION = 'mad4b_scp_runtime_maintenance_lock_v1';
	const LEASE_TTL = 300;
	const HARD_TTL = 1200;
	const LEGACY_EXPIRY_GRACE = 300;

	public static function legacy_options() {
		return array(
			'mad4b_scp_runtime_convergence_lock_v1',
			'mad4b_scp_schema_lifecycle_lock_v1',
		);
	}

	public static function acquire( $owner ) {
		$owner = sanitize_key( (string) $owner );
		if ( '' === $owner ) return new WP_Error( 'mad4b_runtime_maintenance_owner_invalid', 'Runtime maintenance owner is required.' );
		$now = time();
		foreach ( array_merge( array( self::OPTION ), self::legacy_options() ) as $option ) {
			$current = get_option( $option, array() );
			if ( ! is_array( $current ) || empty( $current['token'] ) ) {
				if ( is_array( $current ) && ! empty( $current ) ) delete_option( $option );
				continue;
			}
			$soft = absint( isset( $current['expires_at'] ) ? $current['expires_at'] : 0 );
			$hard = isset( $current['hard_expires_at'] )
				? absint( $current['hard_expires_at'] )
				: ( $soft > 0 ? $soft + self::LEGACY_EXPIRY_GRACE : 0 );
			if ( $hard > $now ) {
				return new WP_Error( 'mad4b_runtime_maintenance_busy', 'Runtime maintenance already has an active fenced lease.', array(
					'owner' => isset( $current['owner'] ) ? sanitize_key( (string) $current['owner'] ) : '',
					'expires_at' => $soft,
					'hard_expires_at' => $hard,
					'legacy_expiry_grace_applied' => ! isset( $current['hard_expires_at'] ),
				) );
			}
			delete_option( $option );
		}

		$token = function_exists( 'wp_generate_uuid4' )
			? strtolower( wp_generate_uuid4() )
			: hash( 'sha256', $owner . "\0" . microtime( true ) . "\0" . wp_rand() );
		$record = array(
			'contract' => self::CONTRACT,
			'token' => $token,
			'fence_token' => $token,
			'owner' => $owner,
			'acquired_at' => gmdate( 'c' ),
			'expires_at' => $now + self::LEASE_TTL,
			'hard_expires_at' => $now + self::HARD_TTL,
		);
		if ( ! add_option( self::OPTION, $record, '', false ) ) return new WP_Error( 'mad4b_runtime_maintenance_lock_failed', 'Unable to acquire the shared runtime maintenance lease.' );
		foreach ( self::legacy_options() as $option ) {
			if ( add_option( $option, $record, '', false ) ) continue;
			self::release( $token, $owner );
			return new WP_Error( 'mad4b_runtime_maintenance_lock_failed', 'Unable to establish the cross-version runtime maintenance fence.' );
		}
		return $token;
	}

	public static function refresh( $token, $owner ) {
		$token = (string) $token;
		$owner = sanitize_key( (string) $owner );
		$current = get_option( self::OPTION, array() );
		if ( ! self::record_owned_by( $current, $token, $owner ) ) return new WP_Error( 'mad4b_runtime_maintenance_lease_lost', 'Runtime maintenance lease ownership was lost.' );
		$now = time();
		$hard = isset( $current['hard_expires_at'] ) ? absint( $current['hard_expires_at'] ) : 0;
		if ( $hard <= $now ) return new WP_Error( 'mad4b_runtime_maintenance_lease_hard_expired', 'Runtime maintenance lease reached its hard deadline.' );
		$current['expires_at'] = min( $hard, $now + self::LEASE_TTL );
		$current['refreshed_at'] = gmdate( 'c' );
		update_option( self::OPTION, $current, false );
		foreach ( self::legacy_options() as $option ) {
			$legacy = get_option( $option, array() );
			if ( ! self::record_owned_by( $legacy, $token, $owner ) ) return new WP_Error( 'mad4b_runtime_maintenance_fence_lost', 'Cross-version runtime maintenance fence ownership was lost.', array( 'option' => $option ) );
			$legacy['expires_at'] = $current['expires_at'];
			$legacy['hard_expires_at'] = $hard;
			$legacy['refreshed_at'] = $current['refreshed_at'];
			update_option( $option, $legacy, false );
		}
		$readback = get_option( self::OPTION, array() );
		return self::record_owned_by( $readback, $token, $owner ) && absint( $readback['expires_at'] ) > $now
			? true
			: new WP_Error( 'mad4b_runtime_maintenance_refresh_readback_failed', 'Runtime maintenance lease refresh could not be verified.' );
	}

	public static function owned( $token, $owner ) {
		return self::record_owned_by( get_option( self::OPTION, array() ), (string) $token, sanitize_key( (string) $owner ) );
	}

	public static function release( $token, $owner = '' ) {
		$token = (string) $token;
		$owner = sanitize_key( (string) $owner );
		foreach ( array_merge( array( self::OPTION ), self::legacy_options() ) as $option ) {
			$current = get_option( $option, array() );
			if ( ! is_array( $current ) || empty( $current['token'] ) || ! hash_equals( (string) $current['token'], $token ) ) continue;
			if ( '' !== $owner && isset( $current['owner'] ) && ! hash_equals( sanitize_key( (string) $current['owner'] ), $owner ) ) continue;
			delete_option( $option );
		}
	}

	public static function status() {
		$now = time();
		$active_fences = array();
		$shared_active = false;

		foreach ( array_merge( array( self::OPTION ), self::legacy_options() ) as $option ) {
			$current = get_option( $option, array() );
			if ( ! is_array( $current ) || empty( $current['token'] ) ) continue;

			$soft = absint( isset( $current['expires_at'] ) ? $current['expires_at'] : 0 );
			$legacy_grace = ! isset( $current['hard_expires_at'] ) && $soft > 0;
			$hard = isset( $current['hard_expires_at'] )
				? absint( $current['hard_expires_at'] )
				: ( $legacy_grace ? $soft + self::LEGACY_EXPIRY_GRACE : 0 );
			if ( $hard <= $now ) continue;

			if ( self::OPTION === $option ) $shared_active = true;
			$active_fences[] = array(
				'option' => $option,
				'token' => (string) $current['token'],
				'owner' => isset( $current['owner'] ) ? sanitize_key( (string) $current['owner'] ) : '',
				'expires_at' => $soft,
				'hard_expires_at' => $hard,
				'legacy_expiry_grace_applied' => $legacy_grace,
			);
		}

		$active = ! empty( $active_fences );
		$primary = array(
			'option' => '',
			'token' => '',
			'owner' => '',
			'expires_at' => 0,
			'hard_expires_at' => 0,
			'legacy_expiry_grace_applied' => false,
		);
		if ( $active ) {
			usort( $active_fences, static function ( $left, $right ) {
				$left_shared = self::OPTION === ( isset( $left['option'] ) ? (string) $left['option'] : '' );
				$right_shared = self::OPTION === ( isset( $right['option'] ) ? (string) $right['option'] : '' );
				if ( $left_shared !== $right_shared ) return $left_shared ? -1 : 1;
				return (int) $right['hard_expires_at'] <=> (int) $left['hard_expires_at'];
			} );
			$primary = $active_fences[0];
		}

		$tokens = array();
		foreach ( $active_fences as $fence ) $tokens[] = isset( $fence['token'] ) ? (string) $fence['token'] : '';
		$tokens = array_values( array_unique( array_filter( $tokens, 'strlen' ) ) );
		$soft = isset( $primary['expires_at'] ) ? absint( $primary['expires_at'] ) : 0;
		$hard = isset( $primary['hard_expires_at'] ) ? absint( $primary['hard_expires_at'] ) : 0;

		return array(
			'contract' => self::CONTRACT,
			'active' => $active,
			'owner' => isset( $primary['owner'] ) ? sanitize_key( (string) $primary['owner'] ) : '',
			'expires_at' => $soft,
			'hard_expires_at' => $hard,
			'soft_lease_expired' => $active && $soft <= $now,
			'retry_after_seconds' => $active ? max( 1, min( 30, ( $soft > $now ? $soft : $hard ) - $now ) ) : 0,
			'fence_source' => isset( $primary['option'] ) ? (string) $primary['option'] : '',
			'active_fence_count' => count( $active_fences ),
			'legacy_only_fence' => $active && ! $shared_active,
			'legacy_expiry_grace_applied' => $active && ! empty( $primary['legacy_expiry_grace_applied'] ),
			'fence_token_conflict' => count( $tokens ) > 1,
			'read_only' => true,
		);
	}

	/**
	 * Read-only update admission projection for the shared maintenance fence.
	 *
	 * No token values are exposed. Active hard fences are never reclaimed here;
	 * acquire() remains the only mutation path and may clean only records whose
	 * bounded hard deadline has already elapsed.
	 */
	public static function preflight( $requester = '' ) {
		$requester = sanitize_key( (string) $requester );
		$status = self::status();
		$now = time();
		$stale_sources = array();
		$malformed_sources = array();

		foreach ( array_merge( array( self::OPTION ), self::legacy_options() ) as $option ) {
			$current = get_option( $option, array() );
			if ( ! is_array( $current ) || empty( $current ) ) continue;
			$token_present = ! empty( $current['token'] );
			$owner_present = ! empty( $current['owner'] );
			$soft = absint( isset( $current['expires_at'] ) ? $current['expires_at'] : 0 );
			$hard = isset( $current['hard_expires_at'] )
				? absint( $current['hard_expires_at'] )
				: ( $soft > 0 ? $soft + self::LEGACY_EXPIRY_GRACE : 0 );
			if ( ! $token_present || ! $owner_present || $hard < 1 ) {
				$malformed_sources[] = (string) $option;
				continue;
			}
			if ( $hard <= $now ) $stale_sources[] = (string) $option;
		}

		$classification = 'CLEAR';
		$safe_to_acquire = true;
		$retryable = false;
		$operator_action_required = false;

		if ( ! empty( $malformed_sources ) ) {
			$classification = 'STALE_REPAIR_REQUIRED';
			$safe_to_acquire = false;
			$operator_action_required = true;
		} elseif ( ! empty( $status['active'] ) ) {
			$safe_to_acquire = false;
			if ( ! empty( $status['fence_token_conflict'] ) ) {
				$classification = 'FENCE_CONFLICT';
				$operator_action_required = true;
			} elseif ( ! empty( $status['legacy_only_fence'] ) && ! empty( $status['legacy_expiry_grace_applied'] ) ) {
				$classification = 'LEGACY_GRACE';
				$retryable = true;
			} else {
				$classification = 'ACTIVE_RETRYABLE';
				$retryable = true;
			}
		} elseif ( ! empty( $stale_sources ) ) {
			$classification = 'STALE_RECLAIMABLE';
			$safe_to_acquire = true;
		}

		return array(
			'contract' => 'mad4b.runtime-maintenance-preflight.v1',
			'classification' => $classification,
			'requester' => $requester,
			'safe_to_acquire' => $safe_to_acquire,
			'retryable' => $retryable,
			'automatic_mutation_retry_allowed' => false,
			'preflight_recheck_allowed' => true,
			'operator_action_required' => $operator_action_required,
			'active' => ! empty( $status['active'] ),
			'owner' => isset( $status['owner'] ) ? sanitize_key( (string) $status['owner'] ) : '',
			'expires_at' => isset( $status['expires_at'] ) ? absint( $status['expires_at'] ) : 0,
			'hard_expires_at' => isset( $status['hard_expires_at'] ) ? absint( $status['hard_expires_at'] ) : 0,
			'soft_lease_expired' => ! empty( $status['soft_lease_expired'] ),
			'retry_after_seconds' => $retryable && isset( $status['retry_after_seconds'] ) ? absint( $status['retry_after_seconds'] ) : 0,
			'fence_source' => isset( $status['fence_source'] ) ? (string) $status['fence_source'] : '',
			'active_fence_count' => isset( $status['active_fence_count'] ) ? absint( $status['active_fence_count'] ) : 0,
			'legacy_only_fence' => ! empty( $status['legacy_only_fence'] ),
			'legacy_expiry_grace_applied' => ! empty( $status['legacy_expiry_grace_applied'] ),
			'fence_token_conflict' => ! empty( $status['fence_token_conflict'] ),
			'stale_source_count' => count( $stale_sources ),
			'malformed_source_count' => count( $malformed_sources ),
			'read_only' => true,
			'mutation_performed' => false,
		);
	}

	private static function record_owned_by( $record, $token, $owner ) {
		if ( ! is_array( $record ) || empty( $record['token'] ) || empty( $record['owner'] ) ) return false;
		return hash_equals( (string) $record['token'], (string) $token )
			&& hash_equals( sanitize_key( (string) $record['owner'] ), sanitize_key( (string) $owner ) );
	}
}
