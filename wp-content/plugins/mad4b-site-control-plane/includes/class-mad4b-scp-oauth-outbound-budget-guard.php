<?php

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Cross-process outbound request budget for OAuth discovery/JWKS retrieval.
 *
 * The Resource Bridge already caches metadata/JWKS and throttles kid-miss
 * refreshes with a transient. This guard adds a database-unique, atomic outer
 * bound so concurrent PHP workers cannot all win a transient get/set race and
 * amplify outbound traffic. At most two bridge-shaped requests to the same URL
 * are admitted per 30-second window; this still permits initial JWKS retrieval
 * plus one key-rotation refresh.
 */
final class MAD4B_SCP_OAuth_Outbound_Budget_Guard {
	const CONTRACT = 'mad4b.oauth-outbound-budget-guard.v1';
	const WINDOW_SECONDS = 30;
	const MAX_REQUESTS_PER_URL = 2;
	private static $booted = false;

	public static function boot() {
		if ( self::$booted ) return;
		self::$booted = true;
		add_filter( 'pre_http_request', array( __CLASS__, 'enforce' ), -1, 3 );
	}

	public static function enforce( $preempt, $args, $url ) {
		if ( false !== $preempt && null !== $preempt ) return $preempt;
		if ( ! class_exists( 'MAD4B_SCP_OAuth_Resource_Bridge' ) ) return $preempt;
		if ( ! self::bridge_shaped_request( $args ) || ! self::trusted_authority_url( $url ) ) return $preempt;
		if ( self::claim_url_budget( $url ) ) return $preempt;
		return new WP_Error( 'mad4b_oauth_outbound_budget_exhausted', 'OAuth discovery/JWKS outbound budget is temporarily exhausted for this authority URL.' );
	}

	public static function claim_url_budget( $url, $now = null ) {
		$url = esc_url_raw( (string) $url );
		if ( '' === $url ) return false;
		$now = null === $now ? time() : (int) $now;
		$prefix = 'mad4b_oauth_http_budget_' . substr( hash( 'sha256', $url ), 0, 32 ) . '_';
		for ( $slot = 1; $slot <= self::MAX_REQUESTS_PER_URL; ++$slot ) {
			$name = $prefix . $slot;
			$existing = get_option( $name, false );
			if ( false !== $existing ) {
				$timestamp = is_numeric( $existing ) ? (int) $existing : 0;
				if ( $timestamp > 0 && $timestamp > $now - self::WINDOW_SECONDS ) continue;
				delete_option( $name );
			}
			// add_option is backed by the unique option_name key, so only one PHP
			// worker can atomically acquire a given slot.
			if ( add_option( $name, $now, '', false ) ) return true;
		}
		return false;
	}

	public static function status() {
		return array(
			'contract' => self::CONTRACT,
			'window_seconds' => self::WINDOW_SECONDS,
			'max_requests_per_url' => self::MAX_REQUESTS_PER_URL,
			'cross_process_atomic_slots' => true,
			'credential_material_stored' => false,
			'creates_authority' => false,
		);
	}

	private static function bridge_shaped_request( $args ) {
		if ( ! is_array( $args ) ) return false;
		$timeout = isset( $args['timeout'] ) ? (float) $args['timeout'] : 0.0;
		$redirection = isset( $args['redirection'] ) ? (int) $args['redirection'] : -1;
		$accept = '';
		if ( isset( $args['headers'] ) && is_array( $args['headers'] ) ) {
			foreach ( $args['headers'] as $name => $value ) if ( 'accept' === strtolower( (string) $name ) ) $accept = strtolower( trim( (string) $value ) );
		}
		return $timeout > 0 && $timeout <= 5.0 && 0 === $redirection && 'application/json' === $accept;
	}

	private static function trusted_authority_url( $url ) {
		$parts = wp_parse_url( (string) $url );
		if ( ! is_array( $parts ) || empty( $parts['scheme'] ) || empty( $parts['host'] ) || 'https' !== strtolower( (string) $parts['scheme'] ) ) return false;
		$url_port = isset( $parts['port'] ) ? (int) $parts['port'] : 443;
		foreach ( MAD4B_SCP_OAuth_Resource_Bridge::trusted_issuers() as $issuer ) {
			$issuer_parts = wp_parse_url( (string) $issuer );
			if ( ! is_array( $issuer_parts ) || empty( $issuer_parts['host'] ) ) continue;
			$issuer_port = isset( $issuer_parts['port'] ) ? (int) $issuer_parts['port'] : 443;
			if ( strtolower( (string) $issuer_parts['host'] ) === strtolower( (string) $parts['host'] ) && $issuer_port === $url_port ) return true;
		}
		return false;
	}
}
