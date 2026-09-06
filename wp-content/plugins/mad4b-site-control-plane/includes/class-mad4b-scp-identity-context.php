<?php

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class MAD4B_SCP_Identity_Context {
	const MAX_SCOPES = 200;

	public static function current() {
		$context = array(
			'authenticated' => false,
			'subject_type' => '',
			'subject_fingerprint' => '',
			'token_scopes' => array(),
			'approval_ticket_id' => '',
			'auth_method' => '',
			'wp_user_id' => get_current_user_id(),
			'request_id' => self::request_id(),
			'origin' => '',
		);
		$context = apply_filters( 'mad4b_scp_authenticated_subject_context', $context );
		return self::normalize( $context );
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
		if ( '' !== $approval_ticket_id && ! preg_match( '/^[a-f0-9-]{36}$/i', $approval_ticket_id ) ) return new WP_Error( 'mad4b_identity_approval_invalid', 'Approval ticket identifier is malformed.' );

		return array(
			'authenticated' => $authenticated,
			'subject_type' => $type,
			'subject_fingerprint' => $fingerprint,
			'token_scopes' => array_values( array_unique( $scopes ) ),
			'approval_ticket_id' => strtolower( $approval_ticket_id ),
			'auth_method' => isset( $context['auth_method'] ) ? sanitize_key( (string) $context['auth_method'] ) : '',
			'wp_user_id' => isset( $context['wp_user_id'] ) ? absint( $context['wp_user_id'] ) : get_current_user_id(),
			'request_id' => isset( $context['request_id'] ) && is_string( $context['request_id'] ) && '' !== trim( $context['request_id'] ) ? substr( sanitize_text_field( $context['request_id'] ), 0, 64 ) : self::request_id(),
			'origin' => isset( $context['origin'] ) ? sanitize_key( (string) $context['origin'] ) : '',
		);
	}

	public static function request_id() {
		static $request_id = null;
		if ( null === $request_id ) $request_id = wp_generate_uuid4();
		return $request_id;
	}
}
