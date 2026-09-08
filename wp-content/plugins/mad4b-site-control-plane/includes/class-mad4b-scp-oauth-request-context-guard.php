<?php

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Clears request-local OAuth authority before any bearer/subject policy runs.
 *
 * A PHP process can execute multiple REST requests (for example rest_do_request
 * subrequests). The resource bridge maps a verified bearer to a fixed WordPress
 * service user, so a later subrequest must never inherit that identity. This
 * guard runs before the deny-only Subject Gate and before the cryptographic
 * Resource Bridge. It grants nothing and stores nothing.
 */
final class MAD4B_SCP_OAuth_Request_Context_Guard {
	const CONTRACT = 'mad4b.oauth-request-context-guard.v1';
	private static $booted = false;

	public static function boot() {
		if ( self::$booted ) return;
		self::$booted = true;
		add_filter( 'rest_pre_dispatch', array( __CLASS__, 'reset_request_context' ), -1, 3 );
	}

	public static function reset_request_context( $result, $server, $request ) {
		if ( ! is_object( $request ) || ! method_exists( $request, 'get_route' ) ) return $result;
		$route = '/' . ltrim( rtrim( (string) $request->get_route(), '/' ), '/' );
		if ( '/mcp/mad4b-read' !== $route ) return $result;
		if ( method_exists( $request, 'get_method' ) && 'OPTIONS' === strtoupper( (string) $request->get_method() ) ) return $result;
		if ( ! class_exists( 'MAD4B_SCP_OAuth_Resource_Bridge' ) ) return $result;

		$authorization = method_exists( $request, 'get_header' ) ? trim( (string) $request->get_header( 'authorization' ) ) : '';
		$stale_oauth_identity = MAD4B_SCP_OAuth_Resource_Bridge::verified_bearer_active();

		// Any bearer-shaped request starts anonymous. A no-bearer request is also
		// demoted when the current service-user selection came from a prior OAuth
		// request in this same PHP process. A clean local WP admin session remains.
		if ( '' !== $authorization || $stale_oauth_identity ) {
			MAD4B_SCP_OAuth_Resource_Bridge::reset_verified_bearer_context( true );
		} else {
			MAD4B_SCP_OAuth_Resource_Bridge::reset_verified_bearer_context( false );
		}
		return $result;
	}

	public static function status() {
		return array(
			'contract' => self::CONTRACT,
			'priority' => -1,
			'runs_before_subject_gate' => true,
			'runs_before_resource_bridge' => true,
			'clears_stale_oauth_service_user' => true,
			'preserves_clean_local_admin_session' => true,
			'creates_authority' => false,
			'stores_credentials' => false,
		);
	}
}
