<?php

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Prevent the local WordPress resource verifier from making HTTP requests back
 * into the same WordPress origin while probing OAuth discovery/JWKS candidates.
 *
 * The guard recognizes only exact URLs derived from the configured local issuer
 * and returns the same public metadata/JWKS that the local authority publishes.
 * No caller-controlled URL becomes trusted through this hook.
 */
final class MAD4B_SCP_Local_OAuth_Loopback_Guard {
	private static $booted = false;

	public static function boot() {
		if ( self::$booted ) return;
		self::$booted = true;
		add_filter( 'pre_http_request', array( __CLASS__, 'intercept' ), 0, 3 );
	}

	public static function intercept( $preempt, $args, $url ) {
		if ( ! defined( 'MAD4B_MCP_LOCAL_OAUTH_ENABLED' ) || true !== constant( 'MAD4B_MCP_LOCAL_OAUTH_ENABLED' ) ) return $preempt;
		if ( ! class_exists( 'MAD4B_SCP_Local_OAuth_Server' ) ) return $preempt;

		$url = untrailingslashit( (string) $url );
		$issuer = untrailingslashit( MAD4B_SCP_Local_OAuth_Server::issuer() );
		$metadata_urls = array(
			untrailingslashit( MAD4B_SCP_Local_OAuth_Server::metadata_url() ),
			$issuer . '/.well-known/openid-configuration',
			$issuer . '/.well-known/oauth-authorization-server',
		);
		foreach ( $metadata_urls as $candidate ) {
			if ( hash_equals( $candidate, $url ) ) return self::json_response( MAD4B_SCP_Local_OAuth_Server::metadata() );
		}

		if ( hash_equals( untrailingslashit( MAD4B_SCP_Local_OAuth_Server::jwks_url() ), $url ) ) {
			$jwks = MAD4B_SCP_Local_OAuth_Server::jwks_document();
			if ( is_wp_error( $jwks ) ) return $preempt;
			return self::json_response( $jwks );
		}
		return $preempt;
	}

	private static function json_response( array $payload ) {
		return array(
			'headers' => array( 'content-type' => 'application/json; charset=utf-8', 'cache-control' => 'no-store' ),
			'body' => wp_json_encode( $payload ),
			'response' => array( 'code' => 200, 'message' => 'OK' ),
			'cookies' => array(),
			'filename' => null,
		);
	}
}
