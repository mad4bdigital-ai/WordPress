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
			$hard = isset( $current['hard_expires_at'] ) ? absint( $current['hard_expires_at'] ) : absint( isset( $current['expires_at'] ) ? $current['expires_at'] : 0 );
			if ( $hard > $now ) {
				return new WP_Error( 'mad4b_runtime_maintenance_busy', 'Runtime maintenance already has an active fenced lease.', array(
					'owner' => isset( $current['owner'] ) ? sanitize_key( (string) $current['owner'] ) : '',
					'expires_at' => isset( $current['expires_at'] ) ? absint( $current['expires_at'] ) : 0,
					'hard_expires_at' => $hard,
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
		$current = get_option( self::OPTION, array() );
		$now = time();
		return array(
			'contract' => self::CONTRACT,
			'active' => is_array( $current ) && ! empty( $current['token'] ) && absint( isset( $current['hard_expires_at'] ) ? $current['hard_expires_at'] : 0 ) > $now,
			'owner' => is_array( $current ) && isset( $current['owner'] ) ? sanitize_key( (string) $current['owner'] ) : '',
			'expires_at' => is_array( $current ) && isset( $current['expires_at'] ) ? absint( $current['expires_at'] ) : 0,
			'hard_expires_at' => is_array( $current ) && isset( $current['hard_expires_at'] ) ? absint( $current['hard_expires_at'] ) : 0,
			'read_only' => true,
		);
	}

	private static function record_owned_by( $record, $token, $owner ) {
		if ( ! is_array( $record ) || empty( $record['token'] ) || empty( $record['owner'] ) ) return false;
		return hash_equals( (string) $record['token'], (string) $token )
			&& hash_equals( sanitize_key( (string) $record['owner'] ), sanitize_key( (string) $owner ) );
	}
}
