<?php

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Aligns remote MCP OAuth challenges with the authoritative RFC 9728
 * protected-resource metadata URL.
 *
 * This is response metadata only. It creates no credential, subject, grant,
 * approval, mutation authority, or production authorization.
 */
final class MAD4B_SCP_OAuth_Challenge_Alignment {
	const CONTRACT = 'mad4b.oauth-challenge-alignment.v1';

	private static $booted = false;

	public static function boot() {
		if ( self::$booted ) return;
		self::$booted = true;
		add_filter( 'rest_post_dispatch', array( __CLASS__, 'align_response' ), 100, 3 );
	}

	public static function align_response( $response, $server, $request ) {
		if ( ! ( $response instanceof WP_REST_Response ) ) return $response;
		if ( ! is_object( $request ) || ! method_exists( $request, 'get_route' ) ) return $response;

		$route = '/' . ltrim( rtrim( (string) $request->get_route(), '/' ), '/' );
		if ( '/mcp/mad4b-read' !== $route ) return $response;

		$headers = $response->get_headers();
		$challenge = isset( $headers['WWW-Authenticate'] ) ? (string) $headers['WWW-Authenticate'] : '';
		if ( '' === $challenge || false === stripos( $challenge, 'resource_metadata=' ) ) return $response;
		if ( ! class_exists( 'MAD4B_SCP_MCP_Client_Compatibility' ) ) return $response;

		$metadata_url = MAD4B_SCP_MCP_Client_Compatibility::authoritative_well_known_url();
		if ( '' === $metadata_url || 'https' !== strtolower( (string) wp_parse_url( $metadata_url, PHP_URL_SCHEME ) ) ) return $response;

		$response->header(
			'WWW-Authenticate',
			'Bearer resource_metadata="' . esc_url_raw( $metadata_url ) . '", scope="' . MAD4B_SCP_OAuth_Resource_Bridge::READ_SCOPE . '"'
		);
		return $response;
	}
}
