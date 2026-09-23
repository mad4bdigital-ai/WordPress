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
	const CONTRACT = 'mad4b.oauth-challenge-alignment.v2';

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
		$server_map = array(
			'/mcp/mad4b-chatgpt' => 'mad4b-chatgpt',
			'/mcp/mad4b-enrollment' => 'mad4b-enrollment',
			'/mcp/mad4b-developer' => 'mad4b-developer',
			'/mcp/mad4b-developer-breakglass' => 'mad4b-developer-breakglass',
		);
		if ( ! isset( $server_map[ $route ] ) ) return $response;

		$headers = $response->get_headers();
		$challenge = isset( $headers['WWW-Authenticate'] ) ? (string) $headers['WWW-Authenticate'] : '';
		if ( '' === $challenge || false === stripos( $challenge, 'resource_metadata=' ) ) return $response;
		if ( ! class_exists( 'MAD4B_SCP_MCP_Client_Compatibility' ) || ! class_exists( 'MAD4B_SCP_OAuth_Resource_Bridge' ) ) return $response;

		$server_id = $server_map[ $route ];
		$metadata_url = MAD4B_SCP_MCP_Client_Compatibility::authoritative_well_known_url( $server_id );
		if ( '' === $metadata_url || 'https' !== strtolower( (string) wp_parse_url( $metadata_url, PHP_URL_SCHEME ) ) ) return $response;
		$resource = MAD4B_SCP_OAuth_Resource_Bridge::resource_identifier( $server_id );
		$scopes = MAD4B_SCP_OAuth_Resource_Bridge::scopes_for_resource( $resource );

		$response->header(
			'WWW-Authenticate',
			'Bearer resource_metadata="' . esc_url_raw( $metadata_url ) . '", scope="' . implode( ' ', $scopes ) . '"'
		);
		return $response;
	}
}
