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
			'url_scope_exact' => true,
			'host_wide_budgeting' => false,
			'non_oauth_same_origin_requests_ignored' => true,
			'explicit_request_marker_required' => true,
			'allowed_request_markers' => array( 'discovery', 'jwks' ),
		);
	}

	private static function bridge_shaped_request( $args ) {
		if ( ! is_array( $args ) ) return false;
		$purpose = isset( $args['mad4b_oauth_fetch'] ) ? sanitize_key( (string) $args['mad4b_oauth_fetch'] ) : '';
		if ( ! in_array( $purpose, array( 'discovery', 'jwks' ), true ) ) return false;
		$timeout = isset( $args['timeout'] ) ? (float) $args['timeout'] : 0.0;
		$redirection = isset( $args['redirection'] ) ? (int) $args['redirection'] : -1;
		$accept = '';
		if ( isset( $args['headers'] ) && is_array( $args['headers'] ) ) {
			foreach ( $args['headers'] as $name => $value ) if ( 'accept' === strtolower( (string) $name ) ) $accept = strtolower( trim( (string) $value ) );
		}
		return $timeout > 0 && $timeout <= 5.0 && 0 === $redirection && 'application/json' === $accept;
	}

	private static function trusted_authority_url( $url ) {
		$url = untrailingslashit( esc_url_raw( (string) $url ) );
		if ( '' === $url ) return false;

		foreach ( MAD4B_SCP_OAuth_Resource_Bridge::trusted_issuers() as $issuer ) {
			$issuer = rtrim( trim( (string) $issuer ), '/' );
			if ( '' === $issuer ) continue;

			// Discovery is a finite, deterministic URL set. Same host/port alone is
			// intentionally insufficient: Site Health and provider APIs may share the
			// exact WordPress origin with the local OAuth authority.
			if ( method_exists( 'MAD4B_SCP_OAuth_Resource_Bridge', 'authorization_server_metadata_urls' ) ) {
				foreach ( MAD4B_SCP_OAuth_Resource_Bridge::authorization_server_metadata_urls( $issuer ) as $metadata_url ) {
					$metadata_url = untrailingslashit( esc_url_raw( (string) $metadata_url ) );
					if ( '' !== $metadata_url && hash_equals( $metadata_url, $url ) ) return true;
				}
			}

			// JWKS is learned from validated discovery and cached issuer-bound before
			// the first JWKS fetch. Only that exact cached URI receives a budget slot.
			$cache_key = 'mad4b_oauth_discovery_' . substr( hash( 'sha256', $issuer ), 0, 32 );
			$metadata = function_exists( 'get_transient' ) ? get_transient( $cache_key ) : false;
			if ( is_array( $metadata ) && ! empty( $metadata['jwks_uri'] ) ) {
				$jwks_uri = untrailingslashit( esc_url_raw( (string) $metadata['jwks_uri'] ) );
				if ( '' !== $jwks_uri && hash_equals( $jwks_uri, $url ) ) return true;
			}
		}
		return false;
	}
}
