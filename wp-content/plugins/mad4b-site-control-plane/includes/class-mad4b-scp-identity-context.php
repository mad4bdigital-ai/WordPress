<?php

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class MAD4B_SCP_Identity_Context {
	const MAX_SCOPES = 200;
	private static $request_approval_ticket_id = '';
	private static $request_subject_override = array();
	private static $request_id = '';

	public static function current() {
		$context = array(
			'authenticated' => false,
			'subject_type' => '',
			'subject_fingerprint' => '',
			'issuer_fingerprint' => '',
			'client_fingerprint' => '',
			'session_fingerprint' => '',
			'token_scopes' => array(),
			'approval_ticket_id' => '',
			'auth_method' => '',
			'wp_user_id' => get_current_user_id(),
			'request_id' => self::request_id(),
			'origin' => '',
		);
		$context = apply_filters( 'mad4b_scp_authenticated_subject_context', $context );
		if ( is_array( $context ) && self::$request_subject_override ) {
			foreach ( self::$request_subject_override as $key => $value ) $context[ $key ] = $value;
		}
		if ( is_array( $context ) && empty( $context['approval_ticket_id'] ) && '' !== self::$request_approval_ticket_id ) {
			$context['approval_ticket_id'] = self::$request_approval_ticket_id;
		}
		return self::normalize( $context );
	}

	/**
	 * Bind a validated governance-input ticket to this PHP request only.
	 *
	 * This does not grant authority. The ticket remains exact-payload bound and
	 * one-time consumed by central authorization. The request overlay only keeps
	 * the authoritative ticket identity observable by synchronous audit/finalizer
	 * callbacks after the transport envelope has been stripped from provider input.
	 */
	public static function bind_approval_ticket_for_request( $ticket_id ) {
		$ticket_id = class_exists( 'MAD4B_SCP_Identifiers' ) ? MAD4B_SCP_Identifiers::approval_ticket_id( $ticket_id ) : '';
		if ( '' === $ticket_id ) return false;
		if ( '' !== self::$request_approval_ticket_id && ! hash_equals( self::$request_approval_ticket_id, $ticket_id ) ) return false;
		self::$request_approval_ticket_id = $ticket_id;
		return true;
	}

	/**
	 * Scope an approval ticket to one synchronous execution only.
	 *
	 * The previous request-local value is restored even when the callback throws,
	 * preventing one abandoned dispatcher permission phase from contaminating a
	 * later target in the same PHP request.
	 */
	public static function with_approval_ticket_for_request( $ticket_id, $callback ) {
		if ( ! is_callable( $callback ) ) return new WP_Error( 'mad4b_identity_approval_callback_invalid', 'Approval ticket scope requires a callable target.' );
		$ticket_id = class_exists( 'MAD4B_SCP_Identifiers' ) ? MAD4B_SCP_Identifiers::approval_ticket_id( $ticket_id ) : '';
		if ( '' === $ticket_id ) return new WP_Error( 'mad4b_identity_approval_invalid', 'Approval ticket identifier is malformed.' );
		$previous = self::$request_approval_ticket_id;
		if ( '' !== $previous && ! hash_equals( $previous, $ticket_id ) ) return new WP_Error( 'mad4b_identity_approval_rebind_conflict', 'A different approval ticket is already scoped to this execution.' );
		self::$request_approval_ticket_id = $ticket_id;
		try {
			return call_user_func( $callback );
		} finally {
			self::$request_approval_ticket_id = $previous;
		}
	}

	/**
	 * Apply one bounded request-local subject override while executing a nested
	 * Developer dispatcher target. This never changes token scopes, user identity,
	 * credentials or persistent bindings and is cleared in a finally block.
	 */
	public static function with_request_subject_override( $subject_type, $subject_fingerprint, $origin, $callback ) {
		if ( ! is_callable( $callback ) ) return new WP_Error( 'mad4b_identity_override_callback_invalid', 'Request-local identity override requires a callable target.' );
		if ( self::$request_subject_override ) return new WP_Error( 'mad4b_identity_override_nested_denied', 'Nested request-local identity overrides are not allowed.' );
		$subject_type = sanitize_key( (string) $subject_type );
		$subject_fingerprint = strtolower( trim( (string) $subject_fingerprint ) );
		$origin = sanitize_key( (string) $origin );
		if ( 'oauth_developer' !== $subject_type || 1 !== preg_match( '/^[a-f0-9]{64}$/', $subject_fingerprint ) || 'mcp_developer_dispatch' !== $origin ) {
			return new WP_Error( 'mad4b_identity_override_denied', 'Only the bounded Developer dispatcher identity override is permitted.' );
		}
		self::$request_subject_override = array(
			'subject_type' => $subject_type,
			'subject_fingerprint' => $subject_fingerprint,
			'origin' => $origin,
		);
		try {
			return call_user_func( $callback );
		} finally {
			self::$request_subject_override = array();
		}
	}

	public static function normalize( $context ) {
		if ( ! is_array( $context ) ) return new WP_Error( 'mad4b_identity_context_invalid', 'Authenticated subject context must be an array.' );
		foreach ( array_keys( $context ) as $key ) {
			if ( preg_match( '/(?:authorization|password|secret|bearer|raw[_-]?token|refresh[_-]?token|access[_-]?token)/i', (string) $key ) ) {
				return new WP_Error( 'mad4b_identity_secret_field_denied', 'Authenticated subject context must not contain raw credential material.' );
			}
		}

		$authenticated = ! empty( $context['authenticated'] );
		$type = isset( $context['subject_type'] ) ? sanitize_key( (string) $context['subject_type'] ) : '';
		$fingerprint = isset( $context['subject_fingerprint'] ) ? strtolower( trim( (string) $context['subject_fingerprint'] ) ) : '';
		$identifier = isset( $context['subject_identifier'] ) ? trim( (string) $context['subject_identifier'] ) : '';
		if ( '' === $fingerprint && '' !== $identifier ) $fingerprint = hash( 'sha256', $type . "\0" . $identifier );
		if ( '' !== $fingerprint && ! preg_match( '/^[a-f0-9]{64}$/', $fingerprint ) ) return new WP_Error( 'mad4b_identity_fingerprint_invalid', 'Subject fingerprint must be a lowercase SHA-256 hexadecimal value.' );
		if ( $authenticated && ( '' === $type || '' === $fingerprint ) ) return new WP_Error( 'mad4b_identity_subject_missing', 'Authenticated subject context is missing a stable subject type or fingerprint.' );

		$issuer_fingerprint = isset( $context['issuer_fingerprint'] ) ? strtolower( trim( (string) $context['issuer_fingerprint'] ) ) : '';
		$client_fingerprint = isset( $context['client_fingerprint'] ) ? strtolower( trim( (string) $context['client_fingerprint'] ) ) : '';
		$session_fingerprint = isset( $context['session_fingerprint'] ) ? strtolower( trim( (string) $context['session_fingerprint'] ) ) : '';
		foreach ( array(
			'issuer_fingerprint' => $issuer_fingerprint,
			'client_fingerprint' => $client_fingerprint,
			'session_fingerprint' => $session_fingerprint,
		) as $field => $value ) {
			if ( '' !== $value && 1 !== preg_match( '/^[a-f0-9]{64}$/', $value ) ) return new WP_Error( 'mad4b_identity_attribution_fingerprint_invalid', 'OAuth attribution fingerprint is malformed.', array( 'field' => $field ) );
		}

		$scopes = array();
		$input_scopes = isset( $context['token_scopes'] ) && is_array( $context['token_scopes'] ) ? $context['token_scopes'] : array();
		if ( count( $input_scopes ) > self::MAX_SCOPES ) return new WP_Error( 'mad4b_identity_scopes_too_many', 'Authenticated subject context contains too many token scopes.' );
		foreach ( $input_scopes as $scope ) {
			if ( ! is_string( $scope ) ) return new WP_Error( 'mad4b_identity_scope_invalid', 'Token scopes must be strings.' );
			$scope = trim( $scope );
			if ( '' === $scope || strlen( $scope ) > 255 ) return new WP_Error( 'mad4b_identity_scope_invalid', 'Token scope is empty or too long.' );
			if ( false !== strpos( $scope, '*' ) ) return new WP_Error( 'mad4b_identity_wildcard_scope_denied', 'Wildcard token scopes are not accepted by the MAD4B Production authorization contract.' );
			$scopes[] = $scope;
		}
		$approval_ticket_id = isset( $context['approval_ticket_id'] ) ? trim( (string) $context['approval_ticket_id'] ) : '';
		if ( '' !== $approval_ticket_id ) {
			$approval_ticket_id = class_exists( 'MAD4B_SCP_Identifiers' ) ? MAD4B_SCP_Identifiers::approval_ticket_id( $approval_ticket_id ) : '';
			if ( '' === $approval_ticket_id ) return new WP_Error( 'mad4b_identity_approval_invalid', 'Approval ticket identifier is malformed.' );
		}

		return array(
			'authenticated' => $authenticated,
			'subject_type' => $type,
			'subject_fingerprint' => $fingerprint,
			'issuer_fingerprint' => $issuer_fingerprint,
			'client_fingerprint' => $client_fingerprint,
			'session_fingerprint' => $session_fingerprint,
			'token_scopes' => array_values( array_unique( $scopes ) ),
			'approval_ticket_id' => strtolower( $approval_ticket_id ),
			'auth_method' => isset( $context['auth_method'] ) ? sanitize_key( (string) $context['auth_method'] ) : '',
			'wp_user_id' => isset( $context['wp_user_id'] ) ? absint( $context['wp_user_id'] ) : get_current_user_id(),
			'request_id' => isset( $context['request_id'] ) && is_string( $context['request_id'] ) && '' !== trim( $context['request_id'] ) ? substr( sanitize_text_field( $context['request_id'] ), 0, 64 ) : self::request_id(),
			'origin' => isset( $context['origin'] ) ? sanitize_key( (string) $context['origin'] ) : '',
		);
	}

	public static function request_scope_state() {
		return array(
			'approval_ticket_bound' => '' !== self::$request_approval_ticket_id,
			'subject_override_active' => ! empty( self::$request_subject_override ),
			'request_id' => self::$request_id,
		);
	}

	public static function reset_request_cache() {
		if ( '' !== self::$request_approval_ticket_id || ! empty( self::$request_subject_override ) ) {
			return new WP_Error( 'mad4b_request_scope_identity_active', 'Request identity overlays are still active and cannot cross a request boundary.' );
		}
		self::$request_approval_ticket_id = '';
		self::$request_subject_override = array();
		self::$request_id = '';
		return true;
	}

	public static function request_id() {
		if ( '' === self::$request_id ) self::$request_id = wp_generate_uuid4();
		return self::$request_id;
	}
}
