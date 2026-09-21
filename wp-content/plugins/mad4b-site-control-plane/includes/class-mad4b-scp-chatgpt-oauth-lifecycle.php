<?php

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * ChatGPT OAuth lifecycle compatibility for the local WordPress authority.
 *
 * ChatGPT can complete Authorization Code + PKCE without explicitly requesting
 * the optional `offline_access` scope. MAD4B access tokens are intentionally
 * short-lived, so that shape would force an unnecessary browser re-authorization
 * after expiry even though the local authority supports rotating refresh tokens.
 *
 * This component is deliberately narrow: only the exact allowlisted ChatGPT
 * CIMD identity, the local authorize endpoint, the canonical ChatGPT protected
 * resource, Authorization Code, and PKCE S256 are eligible. It adds lifecycle
 * scope only; it never broadens the protected-resource permission beyond
 * `mad4b:read`, never runs for another client, and never touches Production
 * enablement or mutation authority.
 */
final class MAD4B_SCP_ChatGPT_OAuth_Lifecycle {
	const CONTRACT = 'mad4b.chatgpt-oauth-lifecycle.v1';

	private static $booted = false;

	public static function boot() {
		if ( self::$booted ) return;
		self::$booted = true;
		// Local OAuth handles the endpoint at parse_request -10. Normalize the
		// exact ChatGPT authorization request before validation/consent at -30.
		add_action( 'parse_request', array( __CLASS__, 'augment_authorization_scope' ), -30 );
	}

	public static function augment_authorization_scope() {
		if ( ! class_exists( 'MAD4B_SCP_Local_OAuth_Server' ) ) return false;
		if ( ! defined( 'MAD4B_MCP_LOCAL_OAUTH_ENABLED' ) || true !== constant( 'MAD4B_MCP_LOCAL_OAUTH_ENABLED' ) ) return false;

		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : 'GET';
		if ( ! in_array( $method, array( 'GET', 'POST' ), true ) ) return false;

		$uri = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : '';
		$path = wp_parse_url( $uri, PHP_URL_PATH );
		$authorize_path = wp_parse_url( MAD4B_SCP_Local_OAuth_Server::authorize_url(), PHP_URL_PATH );
		if ( ! is_string( $path ) || ! is_string( $authorize_path ) || ! hash_equals( rtrim( $authorize_path, '/' ), rtrim( $path, '/' ) ) ) return false;

		if ( 'POST' === $method ) return self::augment_params( $_POST );
		return self::augment_params( $_GET );
	}

	private static function augment_params( &$params ) {
		if ( ! is_array( $params ) ) return false;

		$client_id = self::scalar_param( $params, 'client_id', 191 );
		$response_type = self::scalar_param( $params, 'response_type', 32 );
		$resource = self::scalar_param( $params, 'resource', 2048 );
		$pkce_method = self::scalar_param( $params, 'code_challenge_method', 16 );
		$scope = self::scalar_param( $params, 'scope', 1024, true );
		if ( null === $client_id || null === $response_type || null === $resource || null === $pkce_method || null === $scope ) return false;

		if ( ! hash_equals( MAD4B_SCP_Local_OAuth_Server::CHATGPT_CIMD_CLIENT_ID, $client_id ) ) return false;
		if ( 'code' !== $response_type || 'S256' !== strtoupper( $pkce_method ) ) return false;
		if ( ! hash_equals( MAD4B_SCP_Local_OAuth_Server::resource_identifier(), untrailingslashit( $resource ) ) ) return false;

		$scope = trim( $scope );
		$scopes = '' === $scope ? array( 'mad4b:read' ) : preg_split( '/\s+/', $scope );
		$scopes = is_array( $scopes ) ? array_values( array_unique( array_filter( array_map( 'trim', $scopes ) ) ) ) : array();
		if ( ! in_array( 'mad4b:read', $scopes, true ) ) return false;
		if ( in_array( 'offline_access', $scopes, true ) ) return false;

		$scopes[] = 'offline_access';
		$params['scope'] = implode( ' ', $scopes );
		return true;
	}

	private static function scalar_param( array $params, $name, $max_bytes, $optional = false ) {
		if ( ! array_key_exists( $name, $params ) ) return $optional ? '' : null;
		$value = wp_unslash( $params[ $name ] );
		if ( is_array( $value ) || is_object( $value ) ) return null;
		$value = trim( (string) $value );
		if ( strlen( $value ) > (int) $max_bytes ) return null;
		return $value;
	}
}
