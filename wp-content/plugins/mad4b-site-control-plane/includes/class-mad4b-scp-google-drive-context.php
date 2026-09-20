<?php

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Governed Google Drive source provider for Context Authority.
 *
 * OAuth supports explicit read-only and read+write modes. Full Drive OAuth
 * authority never implies MAD4B mutation authority: writes remain bounded to
 * selected Context source folders and are intended to run through mad4b-write
 * with exact NHI grants and one-time approval.
 *
 * Credentials/tokens are encrypted at rest with a site-bound key derived from
 * WordPress salts.
 */
final class MAD4B_SCP_Google_Drive_Context {
	const CONTRACT = 'mad4b.google-drive-context.v1';
	const CONFIG_OPTION = 'mad4b_scp_google_drive_oauth_config_v1';
	const DEDICATED_CONFIG_OPTION = 'mad4b_scp_google_drive_dedicated_oauth_config_v1';
	const TOKEN_OPTION = 'mad4b_scp_google_drive_oauth_token_v1';
	const AUTH_MODE_OPTION = 'mad4b_scp_google_drive_auth_mode_v1';
	const AUTH_MODE_CONTRACT = 'mad4b.google-drive-auth-mode.v1';
	const AUTH_MODE_CUSTOM = 'custom_credentials';
	const AUTH_MODE_MANAGED = 'managed_google';
	const AUTH_MODE_DEDICATED = 'dedicated_google';
	const MANAGED_SESSION_CONTRACT = 'mad4b.google-managed-oauth-session.v1';
	const MANAGED_REDEEM_CONTRACT = 'mad4b.google-managed-oauth-redemption.v1';
	const MANAGED_REFRESH_CONTRACT = 'mad4b.google-managed-oauth-refresh.v1';

	const AUTH_ENDPOINT = 'https://accounts.google.com/o/oauth2/v2/auth';
	const TOKEN_ENDPOINT = 'https://oauth2.googleapis.com/token';
	const REVOKE_ENDPOINT = 'https://oauth2.googleapis.com/revoke';
	const DRIVE_API = 'https://www.googleapis.com/drive/v3';
	const DRIVE_UPLOAD_API = 'https://www.googleapis.com/upload/drive/v3';
	const DOCS_API = 'https://docs.googleapis.com/v1';
	const READ_SCOPE = 'https://www.googleapis.com/auth/drive.readonly';
	const WRITE_SCOPE = 'https://www.googleapis.com/auth/drive';

	const MAX_SCAN_FILES = 500;
	const MAX_SCAN_FOLDERS = 120;
	const MAX_TEXT_BYTES = 262144;
	const MAX_BINARY_BYTES = 16777216;
	const MAX_WRITE_BYTES = 1048576;
	const MAX_REVERSIBLE_TEXT_BYTES = 196608;
	const MAX_PARENT_DEPTH = 16;

	public static function redirect_uri() {
		return admin_url( 'admin-post.php?action=mad4b_context_google_callback' );
	}

	public static function managed_redirect_uri() {
		return admin_url( 'admin-post.php?action=mad4b_context_google_managed_callback' );
	}

	public static function dedicated_redirect_uri() {
		if ( ! class_exists( 'MAD4B_SCP_Site_Profile' ) || ! MAD4B_SCP_Site_Profile::origin_enrolled() || ! MAD4B_SCP_Site_Profile::site_urls_match_enrollment() ) return '';
		$origin = rtrim( (string) MAD4B_SCP_Site_Profile::site_origin(), '/' );
		if ( '' === $origin ) return '';
		return add_query_arg( 'action', 'mad4b_context_google_dedicated_callback', $origin . '/wp-admin/admin-post.php' );
	}

	public static function auth_mode() {
		$record = get_option( self::AUTH_MODE_OPTION, array() );
		if ( is_array( $record ) && self::AUTH_MODE_CONTRACT === ( isset( $record['contract'] ) ? (string) $record['contract'] : '' ) ) {
			$mode = isset( $record['mode'] ) ? sanitize_key( (string) $record['mode'] ) : '';
			if ( in_array( $mode, array( self::AUTH_MODE_CUSTOM, self::AUTH_MODE_MANAGED, self::AUTH_MODE_DEDICATED ), true ) ) return $mode;
		}
		return self::AUTH_MODE_CUSTOM;
	}

	public static function managed_broker_status() {
		$base = self::managed_broker_base_url( self::AUTH_MODE_MANAGED );
		$configured = ! is_wp_error( $base );
		return array(
			'contract' => 'mad4b.google-managed-oauth-broker-status.v1',
			'configured' => $configured,
			'base_url' => $configured ? (string) $base : '',
			'session_endpoint' => $configured ? self::managed_broker_endpoint( 'session' ) : '',
			'redeem_endpoint' => $configured ? self::managed_broker_endpoint( 'redeem' ) : '',
			'refresh_endpoint' => $configured ? self::managed_broker_endpoint( 'refresh' ) : '',
			'google_client_secret_on_site' => false,
			'one_time_handoff' => true,
			'verifier_bound' => true,
			'blockers' => $configured ? array() : array( 'managed_google_oauth_broker_not_configured' ),
		);
	}

	public static function dedicated_oauth_status() {
		$credentials = self::credentials( self::AUTH_MODE_DEDICATED );
		$redirect_uri = self::dedicated_redirect_uri();
		return array(
			'contract' => 'mad4b.google-dedicated-oauth-status.v1',
			'configured' => ! is_wp_error( $credentials ) && '' !== $redirect_uri,
			'configured_by_constants' => defined( 'MAD4B_GOOGLE_DEDICATED_CLIENT_ID' ) && defined( 'MAD4B_GOOGLE_DEDICATED_CLIENT_SECRET' ),
			'client_id' => is_wp_error( $credentials ) ? '' : sanitize_text_field( (string) $credentials['client_id'] ),
			'client_id_suffix' => is_wp_error( $credentials ) ? '' : self::suffix( $credentials['client_id'] ),
			'redirect_uri' => $redirect_uri,
			'credential_custody' => 'dedicated_site_managed',
			'google_client_secret_on_site' => true,
			'central_mad4b_dependency' => false,
			'primary_domain_derived_from_site_profile' => true,
			'blockers' => ! is_wp_error( $credentials ) && '' !== $redirect_uri ? array() : array( is_wp_error( $credentials ) ? 'dedicated_google_oauth_credentials_missing' : 'dedicated_google_site_origin_unavailable' ),
		);
	}

	public static function auth_mode_status() {
		$mode = self::auth_mode();
		$broker = self::managed_broker_status();
		$dedicated_oauth = self::dedicated_oauth_status();
		$stored = get_option( self::TOKEN_OPTION, array() );
		$connected_record_present = is_array( $stored ) && self::CONTRACT === ( isset( $stored['contract'] ) ? (string) $stored['contract'] : '' );
		return array(
			'contract' => self::AUTH_MODE_CONTRACT,
			'mode' => $mode,
			'supported_modes' => array( self::AUTH_MODE_MANAGED, self::AUTH_MODE_DEDICATED, self::AUTH_MODE_CUSTOM ),
			'recommended_mode' => self::AUTH_MODE_MANAGED,
			'switch_requires_disconnect' => true,
			'switch_blocked' => $connected_record_present,
			'managed_google' => array(
				'configured' => ! empty( $broker['configured'] ),
				'credential_custody' => 'mad4b_managed_oauth',
				'google_client_secret_on_site' => false,
				'broker' => $broker,
			),
			'dedicated_google' => $dedicated_oauth,
			'custom_credentials' => array(
				'credential_custody' => 'site_managed',
				'google_client_secret_on_site' => true,
			),
		);
	}

	public static function set_auth_mode( $mode ) {
		$mode = sanitize_key( (string) $mode );
		if ( ! in_array( $mode, array( self::AUTH_MODE_CUSTOM, self::AUTH_MODE_MANAGED, self::AUTH_MODE_DEDICATED ), true ) ) return new WP_Error( 'mad4b_google_drive_auth_mode_invalid', 'Google Drive authentication mode is invalid.' );
		$stored = get_option( self::TOKEN_OPTION, array() );
		if ( is_array( $stored ) && self::CONTRACT === ( isset( $stored['contract'] ) ? (string) $stored['contract'] : '' ) ) {
			return new WP_Error( 'mad4b_google_drive_auth_mode_change_requires_disconnect', 'Disconnect and revoke the current Google connection before changing authentication mode.' );
		}
		if ( self::AUTH_MODE_MANAGED === $mode ) {
			$broker = self::managed_broker_base_url();
			if ( is_wp_error( $broker ) ) return $broker;
		}
		if ( self::AUTH_MODE_DEDICATED === $mode && '' === self::dedicated_redirect_uri() ) return new WP_Error( 'mad4b_google_dedicated_site_origin_unavailable', 'Dedicated Google Sign-In requires an enrolled Site Profile whose canonical origin matches this site.' );
		$record = array( 'contract' => self::AUTH_MODE_CONTRACT, 'mode' => $mode, 'updated_at' => gmdate( 'c' ) );
		if ( ! self::write_option( self::AUTH_MODE_OPTION, $record ) ) return new WP_Error( 'mad4b_google_drive_auth_mode_persist_failed', 'Google Drive authentication mode could not be persisted.' );
		return self::auth_mode_status();
	}

	public static function credentials_status() {
		$mode = self::auth_mode();
		$credentials = self::credentials( self::auth_mode() );
		$broker = self::managed_broker_status();
		$dedicated_oauth = self::dedicated_oauth_status();
		$custom_configured = ! is_wp_error( $credentials );
		$managed_configured = ! empty( $broker['configured'] );
		$dedicated_configured = ! empty( $dedicated_oauth['configured'] );
		return array(
			'contract' => self::CONTRACT,
			'auth_mode_contract' => self::AUTH_MODE_CONTRACT,
			'auth_mode' => $mode,
			'configured' => self::AUTH_MODE_MANAGED === $mode ? $managed_configured : ( self::AUTH_MODE_DEDICATED === $mode ? $dedicated_configured : $custom_configured ),
			'configured_by_constants' => self::AUTH_MODE_CUSTOM === $mode && defined( 'MAD4B_GOOGLE_DRIVE_CLIENT_ID' ) && defined( 'MAD4B_GOOGLE_DRIVE_CLIENT_SECRET' ),
			'credential_custody' => self::AUTH_MODE_MANAGED === $mode ? 'mad4b_managed_oauth' : ( self::AUTH_MODE_DEDICATED === $mode ? 'dedicated_site_managed' : 'site_managed' ),
			'google_client_secret_on_site' => in_array( $mode, array( self::AUTH_MODE_CUSTOM, self::AUTH_MODE_DEDICATED ), true ),
			'central_mad4b_dependency' => self::AUTH_MODE_MANAGED === $mode,
			'client_id' => self::AUTH_MODE_CUSTOM === $mode && ! is_wp_error( $credentials ) ? sanitize_text_field( (string) $credentials['client_id'] ) : '',
			'client_id_suffix' => self::AUTH_MODE_CUSTOM === $mode && ! is_wp_error( $credentials ) ? self::suffix( $credentials['client_id'] ) : '',
			'redirect_uri' => self::AUTH_MODE_MANAGED === $mode ? self::managed_redirect_uri() : ( self::AUTH_MODE_DEDICATED === $mode ? self::dedicated_redirect_uri() : self::redirect_uri() ),
			'custom_redirect_uri' => self::redirect_uri(),
			'managed_redirect_uri' => self::managed_redirect_uri(),
			'managed_broker' => $broker,
			'dedicated_oauth' => $dedicated_oauth,
			'read_scope' => self::READ_SCOPE,
			'write_scope' => self::WRITE_SCOPE,
			'supported_access_modes' => array( 'read_only', 'read_write' ),
		);
	}

	public static function save_credentials( $client_id, $client_secret ) {
		if ( self::AUTH_MODE_CUSTOM !== self::auth_mode() ) return new WP_Error( 'mad4b_google_drive_custom_credentials_mode_inactive', 'Switch Google Drive authentication to Custom OAuth App before saving client credentials.' );
		$constant_id = defined( 'MAD4B_GOOGLE_DRIVE_CLIENT_ID' );
		$constant_secret = defined( 'MAD4B_GOOGLE_DRIVE_CLIENT_SECRET' );
		if ( $constant_id xor $constant_secret ) return new WP_Error( 'mad4b_google_drive_constants_incomplete', 'Define both Google Drive OAuth constants or neither of them.' );
		if ( $constant_id && $constant_secret ) return new WP_Error( 'mad4b_google_drive_credentials_managed_by_constants', 'Google Drive credentials are managed by wp-config constants.' );
		$client_id = trim( sanitize_text_field( (string) $client_id ) );
		$client_secret = trim( (string) $client_secret );
		if ( '' === $client_id || strlen( $client_id ) > 512 ) return new WP_Error( 'mad4b_google_drive_client_id_invalid', 'A valid Google OAuth client ID is required.' );
		$current = get_option( self::CONFIG_OPTION, array() );
		$current = is_array( $current ) ? $current : array();
		$secret_envelope = isset( $current['client_secret'] ) ? (string) $current['client_secret'] : '';
		$current_client_id = isset( $current['client_id'] ) ? trim( (string) $current['client_id'] ) : '';
		if ( '' === $client_secret && '' !== $current_client_id && ! hash_equals( $current_client_id, $client_id ) ) return new WP_Error( 'mad4b_google_drive_client_secret_required_for_new_client', 'Client Secret is required when changing the Google OAuth Client ID.' );
		if ( '' !== $client_secret ) {
			$secret_envelope = self::encrypt_secret( $client_secret );
			if ( is_wp_error( $secret_envelope ) ) return $secret_envelope;
		}
		if ( '' === $secret_envelope ) return new WP_Error( 'mad4b_google_drive_client_secret_required', 'Google OAuth client secret is required on first setup.' );
		$record = array(
			'contract' => self::CONTRACT,
			'client_id' => $client_id,
			'client_secret' => $secret_envelope,
			'redirect_uri' => self::redirect_uri(),
			'scope' => self::READ_SCOPE,
			'updated_at' => gmdate( 'c' ),
		);
		if ( ! self::write_option( self::CONFIG_OPTION, $record ) ) return new WP_Error( 'mad4b_google_drive_config_persist_failed', 'Google OAuth configuration could not be persisted.' );
		return self::credentials_status();
	}

	public static function save_dedicated_credentials( $client_id, $client_secret ) {
		if ( self::AUTH_MODE_DEDICATED !== self::auth_mode() ) return new WP_Error( 'mad4b_google_drive_dedicated_mode_inactive', 'Switch Google Drive authentication to Dedicated Site OAuth before saving dedicated client credentials.' );
		if ( '' === self::dedicated_redirect_uri() ) return new WP_Error( 'mad4b_google_dedicated_site_origin_unavailable', 'Dedicated Google Sign-In requires an enrolled Site Profile whose canonical origin matches this site.' );
		$constant_id = defined( 'MAD4B_GOOGLE_DEDICATED_CLIENT_ID' );
		$constant_secret = defined( 'MAD4B_GOOGLE_DEDICATED_CLIENT_SECRET' );
		if ( $constant_id xor $constant_secret ) return new WP_Error( 'mad4b_google_dedicated_constants_incomplete', 'Define both dedicated Google OAuth constants or neither of them.' );
		if ( $constant_id && $constant_secret ) return new WP_Error( 'mad4b_google_dedicated_credentials_managed_by_constants', 'Dedicated Google OAuth credentials are managed by wp-config constants.' );
		$client_id = trim( sanitize_text_field( (string) $client_id ) );
		$client_secret = trim( (string) $client_secret );
		if ( '' === $client_id || strlen( $client_id ) > 512 ) return new WP_Error( 'mad4b_google_dedicated_client_id_invalid', 'A valid dedicated Google OAuth client ID is required.' );
		$current = get_option( self::DEDICATED_CONFIG_OPTION, array() );
		$current = is_array( $current ) ? $current : array();
		$secret_envelope = isset( $current['client_secret'] ) ? (string) $current['client_secret'] : '';
		$current_client_id = isset( $current['client_id'] ) ? trim( (string) $current['client_id'] ) : '';
		if ( '' === $client_secret && '' !== $current_client_id && ! hash_equals( $current_client_id, $client_id ) ) return new WP_Error( 'mad4b_google_dedicated_client_secret_required_for_new_client', 'Client Secret is required when changing the dedicated Google OAuth Client ID.' );
		if ( '' !== $client_secret ) {
			$secret_envelope = self::encrypt_secret( $client_secret );
			if ( is_wp_error( $secret_envelope ) ) return $secret_envelope;
		}
		if ( '' === $secret_envelope ) return new WP_Error( 'mad4b_google_dedicated_client_secret_required', 'Dedicated Google OAuth client secret is required on first setup.' );
		$record = array(
			'contract' => self::CONTRACT,
			'client_id' => $client_id,
			'client_secret' => $secret_envelope,
			'redirect_uri' => self::dedicated_redirect_uri(),
			'scope' => self::READ_SCOPE,
			'updated_at' => gmdate( 'c' ),
		);
		if ( ! self::write_option( self::DEDICATED_CONFIG_OPTION, $record ) ) return new WP_Error( 'mad4b_google_dedicated_config_persist_failed', 'Dedicated Google OAuth configuration could not be persisted.' );
		return self::dedicated_oauth_status();
	}

	public static function connection_status() {
		$stored_token = get_option( self::TOKEN_OPTION, array() );
		$stored_token_present = is_array( $stored_token ) && self::CONTRACT === ( isset( $stored_token['contract'] ) ? (string) $stored_token['contract'] : '' );
		$token = self::token_record();
		$token_unreadable = $stored_token_present && ( ! is_array( $token ) || empty( $token['refresh_token'] ) );
		$credentials = self::credentials_status();
		$connected = ! $token_unreadable && is_array( $token ) && ! empty( $token['refresh_token'] );
		$revocation_pending = $connected && ! empty( $token['revocation_pending'] );
		$scope = $connected && isset( $token['scope'] ) ? trim( (string) $token['scope'] ) : '';
		$read_available = $connected && ! $revocation_pending && self::scope_allows_read( $scope );
		$write_available = $connected && ! $revocation_pending && self::scope_allows_write( $scope );
		return array(
			'contract' => self::CONTRACT,
			'auth_mode' => self::auth_mode(),
			'credential_custody' => self::AUTH_MODE_MANAGED === self::auth_mode() ? 'mad4b_managed_oauth' : ( self::AUTH_MODE_DEDICATED === self::auth_mode() ? 'dedicated_site_managed' : 'site_managed' ),
			'configured' => ! empty( $credentials['configured'] ),
			'connected' => $connected,
			'read_available' => $read_available,
			'write_available' => $write_available,
			'read_only' => $connected && ! $write_available,
			'access_mode' => $token_unreadable ? 'token_unreadable' : ( $revocation_pending ? 'revocation_pending' : ( $write_available ? 'read_write' : 'read_only' ) ),
			'revocation_pending' => $revocation_pending,
			'token_unreadable' => $token_unreadable,
			'revocation_error' => $revocation_pending && isset( $token['revocation_error'] ) ? sanitize_key( (string) $token['revocation_error'] ) : '',
			'revocation_attempted_at' => $revocation_pending && isset( $token['revocation_attempted_at'] ) ? sanitize_text_field( (string) $token['revocation_attempted_at'] ) : '',
			'scope' => $scope,
			'account_email' => $connected && isset( $token['account_email'] ) ? sanitize_email( (string) $token['account_email'] ) : '',
			'account_name' => $connected && isset( $token['account_name'] ) ? sanitize_text_field( (string) $token['account_name'] ) : '',
			'permission_id' => $connected && isset( $token['permission_id'] ) ? sanitize_text_field( (string) $token['permission_id'] ) : '',
			'expires_at' => $connected && isset( $token['expires_at'] ) ? (int) $token['expires_at'] : 0,
			'token_healthy' => $connected && ( ! empty( $token['access_token'] ) || ! empty( $token['refresh_token'] ) ),
			'last_verified_at' => $connected && isset( $token['last_verified_at'] ) ? sanitize_text_field( (string) $token['last_verified_at'] ) : '',
			'blockers' => self::connection_blockers( $credentials, $token ),
			'write_blockers' => $write_available ? array() : array( 'google_drive_write_scope_not_granted' ),
		);
	}

	public static function public_connection_status() {
		$status = self::connection_status();
		$public = array(
			'contract' => 'mad4b.google-drive-public-connection.v1',
			'auth_mode' => isset( $status['auth_mode'] ) ? (string) $status['auth_mode'] : self::AUTH_MODE_CUSTOM,
			'credential_custody' => isset( $status['credential_custody'] ) ? (string) $status['credential_custody'] : 'site_managed',
			'configured' => ! empty( $status['configured'] ),
			'connected' => ! empty( $status['connected'] ),
			'read_available' => ! empty( $status['read_available'] ),
			'write_available' => ! empty( $status['write_available'] ),
			'read_only' => ! empty( $status['read_only'] ),
			'access_mode' => isset( $status['access_mode'] ) ? (string) $status['access_mode'] : 'read_only',
			'revocation_pending' => ! empty( $status['revocation_pending'] ),
			'token_unreadable' => ! empty( $status['token_unreadable'] ),
			'revocation_error' => isset( $status['revocation_error'] ) ? (string) $status['revocation_error'] : '',
			'revocation_attempted_at' => isset( $status['revocation_attempted_at'] ) ? (string) $status['revocation_attempted_at'] : '',
			'expires_at' => isset( $status['expires_at'] ) ? (int) $status['expires_at'] : 0,
			'token_healthy' => ! empty( $status['token_healthy'] ),
			'last_verified_at' => isset( $status['last_verified_at'] ) ? (string) $status['last_verified_at'] : '',
			'blockers' => isset( $status['blockers'] ) && is_array( $status['blockers'] ) ? array_values( $status['blockers'] ) : array(),
			'write_blockers' => isset( $status['write_blockers'] ) && is_array( $status['write_blockers'] ) ? array_values( $status['write_blockers'] ) : array(),
		);
		return $public;
	}

	public static function runtime_readiness() {
		$credentials = self::credentials_status();
		$connection = self::public_connection_status();
		$gemini_enabled_defined = defined( 'MAD4B_CONTEXT_GEMINI_ENABLED' );
		$gemini_enabled_effective = $gemini_enabled_defined && true === (bool) constant( 'MAD4B_CONTEXT_GEMINI_ENABLED' );
		$gemini_key_present = defined( 'MAD4B_CONTEXT_GEMINI_API_KEY' ) && '' !== trim( (string) constant( 'MAD4B_CONTEXT_GEMINI_API_KEY' ) );
		$gemini_model_present = defined( 'MAD4B_CONTEXT_GEMINI_MODEL' ) && '' !== trim( (string) constant( 'MAD4B_CONTEXT_GEMINI_MODEL' ) );
		$generic_url_present = defined( 'MAD4B_CONTEXT_EXTRACTOR_URL' ) && '' !== trim( (string) constant( 'MAD4B_CONTEXT_EXTRACTOR_URL' ) );
		$generic_token_present = defined( 'MAD4B_CONTEXT_EXTRACTOR_TOKEN' ) && '' !== trim( (string) constant( 'MAD4B_CONTEXT_EXTRACTOR_TOKEN' ) );
		$dependencies = array(
			'ziparchive' => array( 'available' => class_exists( 'ZipArchive' ), 'required_for' => array( 'xlsx', 'pptx', 'docx', 'odf', 'epub', 'zip', 'google_forms' ) ),
			'mbstring' => array( 'available' => extension_loaded( 'mbstring' ) && function_exists( 'mb_strlen' ), 'required_for' => array( 'unicode_text_normalization' ) ),
			'zlib' => array( 'available' => extension_loaded( 'zlib' ) && function_exists( 'gzuncompress' ), 'required_for' => array( 'compressed_pdf_streams', 'archive_payloads' ) ),
			'imagick' => array( 'available' => extension_loaded( 'imagick' ) && class_exists( 'Imagick' ), 'required_for' => array( 'optional_local_raster_preparation' ) ),
		);
		return array(
			'contract' => 'mad4b.google-drive-context-runtime-readiness.v1',
			'php_version' => PHP_VERSION,
			'dependencies' => $dependencies,
			'oauth' => array(
				'auth_mode' => self::auth_mode(),
				'auth_mode_contract' => self::AUTH_MODE_CONTRACT,
				'credentials_configured' => ! empty( $credentials['configured'] ),
				'managed_broker' => self::managed_broker_status(),
				'dedicated_oauth' => self::dedicated_oauth_status(),
				'central_mad4b_dependency' => self::AUTH_MODE_MANAGED === self::auth_mode(),
				'google_client_secret_on_site' => in_array( self::auth_mode(), array( self::AUTH_MODE_CUSTOM, self::AUTH_MODE_DEDICATED ), true ),
				'configured_by_constants' => ! empty( $credentials['configured_by_constants'] ),
				'redirect_uri_configured' => '' !== (string) self::redirect_uri_for_mode( self::auth_mode() ),
				'redirect_uri' => self::redirect_uri_for_mode( self::auth_mode() ),
				'state_site_binding' => true,
				'pkce_s256' => true,
				'connection' => $connection,
			),
			'extractor' => array(
				'gemini_enabled_defined' => $gemini_enabled_defined,
				'gemini_enabled_effective' => $gemini_enabled_effective,
				'gemini_api_key_present' => $gemini_key_present,
				'gemini_model_present' => $gemini_model_present,
				'gemini_model_effective' => self::gemini_model(),
				'generic_extractor_url_present' => $generic_url_present,
				'generic_extractor_token_present' => $generic_token_present,
				'gemini_configured' => self::gemini_extractor_configured(),
				'generic_extractor_configured' => self::generic_extractor_configured(),
				'external_extractor_configured' => self::external_extractor_configured(),
			),
			'normalization_capabilities' => self::normalization_capabilities(),
			'limits' => array(
				'max_text_bytes' => self::MAX_TEXT_BYTES,
				'max_binary_bytes' => self::MAX_BINARY_BYTES,
				'max_write_bytes' => self::MAX_WRITE_BYTES,
				'max_reversible_text_bytes' => self::MAX_REVERSIBLE_TEXT_BYTES,
			),
			'secrets_exposed' => false,
		);
	}

	public static function authorization_url( $access_mode = 'read_only' ) {
		if ( ! in_array( self::auth_mode(), array( self::AUTH_MODE_CUSTOM, self::AUTH_MODE_DEDICATED ), true ) ) return new WP_Error( 'mad4b_google_drive_local_oauth_mode_inactive', 'Custom or Dedicated Site Google OAuth must be the active authentication mode.' );
		if ( ! current_user_can( 'manage_options' ) ) return new WP_Error( 'mad4b_google_drive_admin_required', 'Administrator capability is required to connect Google Drive.' );
		if ( ! class_exists( 'MAD4B_SCP_Site_Profile' ) || ! MAD4B_SCP_Site_Profile::origin_enrolled() || '' === MAD4B_SCP_Site_Profile::site_uuid() ) return new WP_Error( 'mad4b_google_drive_site_profile_required', 'Enroll this Site Profile before connecting Google Drive.' );
		$credentials = self::credentials( self::auth_mode() );
		if ( is_wp_error( $credentials ) ) return $credentials;
		$stored_token = get_option( self::TOKEN_OPTION, array() );
		$stored_token_present = is_array( $stored_token ) && self::CONTRACT === ( isset( $stored_token['contract'] ) ? (string) $stored_token['contract'] : '' );
		$current_token = self::token_record();
		if ( $stored_token_present && ( ! is_array( $current_token ) || empty( $current_token['refresh_token'] ) ) ) return new WP_Error( 'mad4b_google_drive_token_unreadable_manual_revoke_required', 'Stored Google token is unreadable. Clear the local token and revoke MAD4B access in the Google account before reconnecting.' );
		if ( is_array( $current_token ) && ! empty( $current_token['revocation_pending'] ) ) return new WP_Error( 'mad4b_google_drive_revocation_pending', 'Google Drive revocation is still pending. Retry revoke before starting a new OAuth connection.' );
		$access_mode = sanitize_key( (string) $access_mode );
		if ( ! in_array( $access_mode, array( 'read_only', 'read_write' ), true ) ) return new WP_Error( 'mad4b_google_drive_access_mode_invalid', 'Google Drive access mode must be read_only or read_write.' );
		$requested_scope = 'read_write' === $access_mode ? self::WRITE_SCOPE : self::READ_SCOPE;
		try {
			$pkce_verifier = rtrim( strtr( base64_encode( random_bytes( 48 ) ), '+/', '-_' ), '=' );
		} catch ( Exception $e ) {
			return new WP_Error( 'mad4b_google_drive_pkce_generation_failed', 'Unable to generate the Google OAuth PKCE verifier.' );
		}
		if ( ! preg_match( '/^[A-Za-z0-9._~-]{43,128}$/', $pkce_verifier ) ) return new WP_Error( 'mad4b_google_drive_pkce_generation_failed', 'Generated Google OAuth PKCE verifier is invalid.' );
		$pkce_challenge = rtrim( strtr( base64_encode( hash( 'sha256', $pkce_verifier, true ) ), '+/', '-_' ), '=' );
		$state = wp_generate_password( 64, false, false );
		if ( '' === $state ) return new WP_Error( 'mad4b_google_drive_state_generation_failed', 'Unable to generate OAuth state.' );
		$redirect_uri = self::redirect_uri_for_mode( self::auth_mode() );
		if ( '' === $redirect_uri ) return new WP_Error( 'mad4b_google_drive_oauth_redirect_unavailable', 'Google OAuth redirect URI is unavailable for the selected authentication mode.' );
		set_transient(
			self::state_key( get_current_user_id() ),
			array(
				'state' => hash( 'sha256', $state ),
				'redirect_uri' => $redirect_uri,
				'auth_mode' => self::auth_mode(),
				'site_uuid' => class_exists( 'MAD4B_SCP_Site_Profile' ) ? MAD4B_SCP_Site_Profile::site_uuid() : '',
				'access_mode' => $access_mode,
				'requested_scope' => $requested_scope,
				'pkce_verifier' => $pkce_verifier,
				'created_at' => time(),
			),
			10 * MINUTE_IN_SECONDS
		);
		return add_query_arg(
			array(
				'client_id' => $credentials['client_id'],
				'redirect_uri' => $redirect_uri,
				'response_type' => 'code',
				'scope' => $requested_scope,
				'access_type' => 'offline',
				'include_granted_scopes' => 'false',
				'prompt' => 'consent',
				'code_challenge' => $pkce_challenge,
				'code_challenge_method' => 'S256',
				'state' => $state,
			),
			self::AUTH_ENDPOINT
		);
	}

	public static function complete_oauth( $code, $state ) {
		if ( ! in_array( self::auth_mode(), array( self::AUTH_MODE_CUSTOM, self::AUTH_MODE_DEDICATED ), true ) ) return new WP_Error( 'mad4b_google_drive_local_oauth_mode_inactive', 'Custom or Dedicated Site Google OAuth must be the active authentication mode.' );
		if ( ! current_user_can( 'manage_options' ) ) return new WP_Error( 'mad4b_google_drive_admin_required', 'Administrator capability is required to complete Google Drive connection.' );
		$stored = get_transient( self::state_key( get_current_user_id() ) );
		delete_transient( self::state_key( get_current_user_id() ) );
		$state = trim( (string) $state );
		if ( ! is_array( $stored ) || empty( $stored['state'] ) || '' === $state || ! hash_equals( (string) $stored['state'], hash( 'sha256', $state ) ) ) {
			return new WP_Error( 'mad4b_google_drive_oauth_state_invalid', 'Google OAuth state is missing, expired, or invalid.' );
		}
		$current_site_uuid = class_exists( 'MAD4B_SCP_Site_Profile' ) ? MAD4B_SCP_Site_Profile::site_uuid() : '';
		if ( empty( $stored['site_uuid'] ) || '' === $current_site_uuid || ! hash_equals( (string) $stored['site_uuid'], $current_site_uuid ) ) return new WP_Error( 'mad4b_google_drive_oauth_site_binding_changed', 'Site Profile binding changed during Google OAuth. Start the connection again.' );
		if ( empty( $stored['auth_mode'] ) || ! hash_equals( (string) $stored['auth_mode'], self::auth_mode() ) ) return new WP_Error( 'mad4b_google_drive_oauth_mode_binding_changed', 'Google OAuth authentication mode changed during connection. Start again.' );
		if ( empty( $stored['redirect_uri'] ) || ! hash_equals( (string) $stored['redirect_uri'], self::redirect_uri_for_mode( self::auth_mode() ) ) ) return new WP_Error( 'mad4b_google_drive_oauth_redirect_binding_changed', 'OAuth redirect binding changed during Google connection. Start the connection again.' );
		$pkce_verifier = isset( $stored['pkce_verifier'] ) ? (string) $stored['pkce_verifier'] : '';
		if ( ! preg_match( '/^[A-Za-z0-9._~-]{43,128}$/', $pkce_verifier ) ) return new WP_Error( 'mad4b_google_drive_oauth_pkce_invalid', 'Google OAuth PKCE verifier is missing, expired, or invalid.' );
		$code = trim( (string) $code );
		if ( '' === $code || strlen( $code ) > 4096 ) return new WP_Error( 'mad4b_google_drive_oauth_code_invalid', 'Google OAuth authorization code is missing or invalid.' );
		$credentials = self::credentials();
		if ( is_wp_error( $credentials ) ) return $credentials;
		$response = wp_remote_post(
			self::TOKEN_ENDPOINT,
			array(
				'timeout' => 20,
				'redirection' => 0,
				'body' => array(
					'client_id' => $credentials['client_id'],
					'client_secret' => $credentials['client_secret'],
					'code' => $code,
					'grant_type' => 'authorization_code',
					'redirect_uri' => self::redirect_uri_for_mode( self::auth_mode() ),
					'code_verifier' => $pkce_verifier,
				),
			)
		);
		$tokens = self::decode_json_response( $response, 'mad4b_google_drive_oauth_exchange_failed' );
		if ( is_wp_error( $tokens ) ) return $tokens;
		if ( empty( $tokens['access_token'] ) ) return new WP_Error( 'mad4b_google_drive_access_token_missing', 'Google did not return an access token.' );
		$existing = self::token_record();
		$requested_mode = isset( $stored['access_mode'] ) ? sanitize_key( (string) $stored['access_mode'] ) : 'read_only';
		$new_refresh = isset( $tokens['refresh_token'] ) ? trim( (string) $tokens['refresh_token'] ) : '';
		if ( '' === $new_refresh && 'read_only' === $requested_mode && is_array( $existing ) && self::scope_allows_write( isset( $existing['scope'] ) ? $existing['scope'] : '' ) ) {
			return new WP_Error( 'mad4b_google_drive_readonly_downgrade_requires_revoke', 'Cannot prove a least-privilege downgrade while the previous read+write refresh grant remains. Disconnect and revoke Google access, then connect Read-only.' );
		}
		$refresh = '' !== $new_refresh ? $new_refresh : ( is_array( $existing ) && isset( $existing['refresh_token'] ) ? (string) $existing['refresh_token'] : '' );
		if ( '' === $refresh ) return new WP_Error( 'mad4b_google_drive_refresh_token_missing', 'Google did not return a refresh token. Reconnect and grant offline access.' );
		$granted_scope = isset( $tokens['scope'] ) ? trim( (string) $tokens['scope'] ) : '';
		if ( '' === $granted_scope ) return new WP_Error( 'mad4b_google_drive_granted_scope_missing', 'Google OAuth token exchange did not return an explicit granted scope set; connection remains fail-closed.' );
		$record = self::persist_tokens(
			(string) $tokens['access_token'],
			$refresh,
			isset( $tokens['expires_in'] ) ? absint( $tokens['expires_in'] ) : 3600,
			$granted_scope,
			array(),
			$requested_mode,
			self::auth_mode()
		);
		if ( is_wp_error( $record ) ) return $record;
		$about = self::about();
		if ( ! is_wp_error( $about ) && isset( $about['user'] ) && is_array( $about['user'] ) ) {
			$record = self::token_record();
			$record['account_email'] = isset( $about['user']['emailAddress'] ) ? sanitize_email( (string) $about['user']['emailAddress'] ) : '';
			$record['account_name'] = isset( $about['user']['displayName'] ) ? sanitize_text_field( (string) $about['user']['displayName'] ) : '';
			$record['permission_id'] = isset( $about['user']['permissionId'] ) ? sanitize_text_field( (string) $about['user']['permissionId'] ) : '';
			$record['last_verified_at'] = gmdate( 'c' );
			self::write_option( self::TOKEN_OPTION, self::seal_token_record( $record ) );
		}
		return self::connection_status();
	}


	public static function managed_authorization_url( $access_mode = 'read_only' ) {
		if ( self::AUTH_MODE_MANAGED !== self::auth_mode() ) return new WP_Error( 'mad4b_google_drive_managed_oauth_mode_inactive', 'Managed Google Sign-In is not the active authentication mode.' );
		if ( ! current_user_can( 'manage_options' ) ) return new WP_Error( 'mad4b_google_drive_admin_required', 'Administrator capability is required to connect Google Drive.' );
		if ( ! class_exists( 'MAD4B_SCP_Site_Profile' ) || ! MAD4B_SCP_Site_Profile::origin_enrolled() || '' === MAD4B_SCP_Site_Profile::site_uuid() ) return new WP_Error( 'mad4b_google_drive_site_profile_required', 'Enroll this Site Profile before connecting Google Drive.' );
		$broker = self::managed_broker_base_url( self::auth_mode() );
		if ( is_wp_error( $broker ) ) return $broker;
		$stored_token = get_option( self::TOKEN_OPTION, array() );
		$stored_token_present = is_array( $stored_token ) && self::CONTRACT === ( isset( $stored_token['contract'] ) ? (string) $stored_token['contract'] : '' );
		$current_token = self::token_record();
		if ( $stored_token_present && ( ! is_array( $current_token ) || empty( $current_token['refresh_token'] ) ) ) return new WP_Error( 'mad4b_google_drive_token_unreadable_manual_revoke_required', 'Stored Google token is unreadable. Clear the local token and revoke MAD4B access in the Google account before reconnecting.' );
		if ( is_array( $current_token ) && ! empty( $current_token['revocation_pending'] ) ) return new WP_Error( 'mad4b_google_drive_revocation_pending', 'Google Drive revocation is still pending. Retry revoke before starting a new OAuth connection.' );
		$access_mode = sanitize_key( (string) $access_mode );
		if ( ! in_array( $access_mode, array( 'read_only', 'read_write' ), true ) ) return new WP_Error( 'mad4b_google_drive_access_mode_invalid', 'Google Drive access mode must be read_only or read_write.' );
		$requested_scope = 'read_write' === $access_mode ? self::WRITE_SCOPE : self::READ_SCOPE;
		try {
			$verifier = rtrim( strtr( base64_encode( random_bytes( 48 ) ), '+/', '-_' ), '=' );
		} catch ( Exception $e ) {
			return new WP_Error( 'mad4b_google_managed_verifier_generation_failed', 'Unable to generate the Managed Google Sign-In verifier.' );
		}
		if ( ! preg_match( '/^[A-Za-z0-9._~-]{43,128}$/', $verifier ) ) return new WP_Error( 'mad4b_google_managed_verifier_generation_failed', 'Generated Managed Google Sign-In verifier is invalid.' );
		$challenge = rtrim( strtr( base64_encode( hash( 'sha256', $verifier, true ) ), '+/', '-_' ), '=' );
		$state = wp_generate_password( 64, false, false );
		if ( '' === $state ) return new WP_Error( 'mad4b_google_drive_state_generation_failed', 'Unable to generate OAuth state.' );
		$site_uuid = MAD4B_SCP_Site_Profile::site_uuid();
		$origin = self::canonical_origin();
		$callback = self::managed_redirect_uri();
		$request = array(
			'contract' => self::MANAGED_SESSION_CONTRACT,
			'site_uuid' => $site_uuid,
			'origin' => $origin,
			'callback_uri' => $callback,
			'access_mode' => $access_mode,
			'requested_scope' => $requested_scope,
			'state' => $state,
			'verifier_challenge' => $challenge,
			'verifier_method' => 'S256',
		);
		$response = self::managed_broker_post( 'session', $request, 'mad4b_google_managed_session_failed', self::auth_mode() );
		if ( is_wp_error( $response ) ) return $response;
		if ( self::MANAGED_SESSION_CONTRACT !== ( isset( $response['contract'] ) ? (string) $response['contract'] : '' ) ) return new WP_Error( 'mad4b_google_managed_session_contract_invalid', 'Managed Google Sign-In broker returned an unexpected session contract.' );
		$authorization_url = isset( $response['authorization_url'] ) ? self::validated_https_url( $response['authorization_url'] ) : '';
		$session_id = isset( $response['session_id'] ) ? trim( sanitize_text_field( (string) $response['session_id'] ) ) : '';
		if ( '' === $authorization_url || '' === $session_id || strlen( $session_id ) > 255 ) return new WP_Error( 'mad4b_google_managed_session_response_invalid', 'Managed Google Sign-In broker returned an invalid authorization session.' );
		set_transient(
			self::managed_state_key( get_current_user_id() ),
			array(
				'state' => hash( 'sha256', $state ),
				'site_uuid' => $site_uuid,
				'origin' => $origin,
				'callback_uri' => $callback,
				'access_mode' => $access_mode,
				'requested_scope' => $requested_scope,
				'verifier' => $verifier,
				'session_id' => $session_id,
				'broker_base_url' => (string) $broker,
				'auth_mode' => self::auth_mode(),
				'created_at' => time(),
			),
			10 * MINUTE_IN_SECONDS
		);
		return $authorization_url;
	}

	public static function complete_managed_oauth( $handoff_code, $state ) {
		if ( self::AUTH_MODE_MANAGED !== self::auth_mode() ) return new WP_Error( 'mad4b_google_drive_managed_oauth_mode_inactive', 'Managed Google Sign-In is not the active authentication mode.' );
		if ( ! current_user_can( 'manage_options' ) ) return new WP_Error( 'mad4b_google_drive_admin_required', 'Administrator capability is required to complete Google Drive connection.' );
		$stored = get_transient( self::managed_state_key( get_current_user_id() ) );
		delete_transient( self::managed_state_key( get_current_user_id() ) );
		$state = trim( (string) $state );
		if ( ! is_array( $stored ) || empty( $stored['state'] ) || '' === $state || ! hash_equals( (string) $stored['state'], hash( 'sha256', $state ) ) ) return new WP_Error( 'mad4b_google_managed_oauth_state_invalid', 'Managed Google Sign-In state is missing, expired, or invalid.' );
		$current_site_uuid = class_exists( 'MAD4B_SCP_Site_Profile' ) ? MAD4B_SCP_Site_Profile::site_uuid() : '';
		$current_origin = self::canonical_origin();
		if ( empty( $stored['site_uuid'] ) || '' === $current_site_uuid || ! hash_equals( (string) $stored['site_uuid'], $current_site_uuid ) ) return new WP_Error( 'mad4b_google_managed_site_binding_changed', 'Site Profile binding changed during Managed Google Sign-In.' );
		if ( empty( $stored['origin'] ) || '' === $current_origin || ! hash_equals( (string) $stored['origin'], $current_origin ) ) return new WP_Error( 'mad4b_google_managed_origin_binding_changed', 'Canonical origin changed during Managed Google Sign-In.' );
		if ( empty( $stored['auth_mode'] ) || ! hash_equals( (string) $stored['auth_mode'], self::auth_mode() ) ) return new WP_Error( 'mad4b_google_managed_auth_mode_binding_changed', 'Google broker authentication mode changed during sign-in.' );
		if ( empty( $stored['callback_uri'] ) || ! hash_equals( (string) $stored['callback_uri'], self::managed_redirect_uri() ) ) return new WP_Error( 'mad4b_google_managed_callback_binding_changed', 'Managed Google Sign-In callback binding changed.' );
		$broker = self::managed_broker_base_url( self::auth_mode() );
		if ( is_wp_error( $broker ) ) return $broker;
		if ( empty( $stored['broker_base_url'] ) || ! hash_equals( (string) $stored['broker_base_url'], (string) $broker ) ) return new WP_Error( 'mad4b_google_managed_broker_binding_changed', 'Managed Google Sign-In broker binding changed.' );
		$verifier = isset( $stored['verifier'] ) ? (string) $stored['verifier'] : '';
		if ( ! preg_match( '/^[A-Za-z0-9._~-]{43,128}$/', $verifier ) ) return new WP_Error( 'mad4b_google_managed_verifier_invalid', 'Managed Google Sign-In verifier is missing, expired, or invalid.' );
		$handoff_code = trim( (string) $handoff_code );
		if ( '' === $handoff_code || strlen( $handoff_code ) > 4096 ) return new WP_Error( 'mad4b_google_managed_handoff_code_invalid', 'Managed Google Sign-In handoff code is missing or invalid.' );
		$request = array(
			'contract' => 'mad4b.google-managed-oauth-redeem-request.v1',
			'handoff_code' => $handoff_code,
			'session_id' => isset( $stored['session_id'] ) ? (string) $stored['session_id'] : '',
			'verifier' => $verifier,
			'site_uuid' => $current_site_uuid,
			'origin' => $current_origin,
			'callback_uri' => self::managed_redirect_uri(),
		);
		$tokens = self::managed_broker_post( 'redeem', $request, 'mad4b_google_managed_redeem_failed', self::auth_mode() );
		if ( is_wp_error( $tokens ) ) return $tokens;
		if ( self::MANAGED_REDEEM_CONTRACT !== ( isset( $tokens['contract'] ) ? (string) $tokens['contract'] : '' ) ) return new WP_Error( 'mad4b_google_managed_redeem_contract_invalid', 'Managed Google Sign-In broker returned an unexpected redemption contract.' );
		if ( empty( $tokens['access_token'] ) || empty( $tokens['refresh_token'] ) ) return new WP_Error( 'mad4b_google_managed_token_missing', 'Managed Google Sign-In redemption did not return the required token envelope.' );
		$granted_scope = isset( $tokens['scope'] ) ? trim( (string) $tokens['scope'] ) : '';
		if ( '' === $granted_scope ) return new WP_Error( 'mad4b_google_drive_granted_scope_missing', 'Managed Google Sign-In did not return an explicit granted scope set.' );
		$requested_mode = isset( $stored['access_mode'] ) ? sanitize_key( (string) $stored['access_mode'] ) : 'read_only';
		$record = self::persist_tokens(
			(string) $tokens['access_token'],
			(string) $tokens['refresh_token'],
			isset( $tokens['expires_in'] ) ? absint( $tokens['expires_in'] ) : 3600,
			$granted_scope,
			array(),
			$requested_mode,
			self::auth_mode()
		);
		if ( is_wp_error( $record ) ) return $record;
		self::refresh_account_identity();
		return self::connection_status();
	}


	public static function disconnect() {
		$stored = get_option( self::TOKEN_OPTION, array() );
		$stored_present = is_array( $stored ) && self::CONTRACT === ( isset( $stored['contract'] ) ? (string) $stored['contract'] : '' );
		$record = self::token_record();
		if ( $stored_present && ( ! is_array( $record ) || empty( $record['refresh_token'] ) ) ) {
			self::delete_option_verified( self::TOKEN_OPTION );
			return new WP_Error(
				'mad4b_google_drive_token_unreadable_manual_revoke_required',
				'Stored Google token cannot be decrypted, so remote revocation cannot be proven. Local credentials were discarded; revoke MAD4B access manually in the Google account before reconnecting.'
			);
		}
		$token = is_array( $record ) && ! empty( $record['refresh_token'] ) ? (string) $record['refresh_token'] : ( is_array( $record ) && ! empty( $record['access_token'] ) ? (string) $record['access_token'] : '' );
		if ( '' === $token ) {
			self::delete_option_verified( self::TOKEN_OPTION );
			$status = self::connection_status();
			$status['remote_revocation_attempted'] = false;
			$status['remote_revocation_confirmed'] = false;
			$status['remote_revocation_error'] = '';
			return $status;
		}

		$response = wp_remote_post(
			self::REVOKE_ENDPOINT,
			array(
				'timeout' => 20,
				'redirection' => 0,
				'headers' => array( 'Content-Type' => 'application/x-www-form-urlencoded' ),
				'body' => array( 'token' => $token ),
			)
		);
		$revocation_error = '';
		$confirmed = false;
		if ( is_wp_error( $response ) ) $revocation_error = sanitize_key( $response->get_error_code() );
		else {
			$status_code = (int) wp_remote_retrieve_response_code( $response );
			$confirmed = 200 === $status_code;
			if ( ! $confirmed ) $revocation_error = 'google_revocation_http_' . $status_code;
		}

		if ( $confirmed ) {
			if ( ! self::delete_option_verified( self::TOKEN_OPTION ) ) return new WP_Error( 'mad4b_google_drive_local_token_delete_failed', 'Google access was revoked remotely, but the local encrypted token could not be removed.' );
			$status = self::connection_status();
			$status['remote_revocation_attempted'] = true;
			$status['remote_revocation_confirmed'] = true;
			$status['remote_revocation_error'] = '';
			return $status;
		}

		$record['revocation_pending'] = true;
		$record['revocation_error'] = $revocation_error ? $revocation_error : 'google_revocation_unconfirmed';
		$record['revocation_attempted_at'] = gmdate( 'c' );
		$sealed = self::seal_token_record( $record );
		if ( is_wp_error( $sealed ) ) {
			self::delete_option_verified( self::TOKEN_OPTION );
			return new WP_Error( 'mad4b_google_drive_revocation_state_persist_failed', 'Google revocation was not confirmed and the disabled retry state could not be sealed. Local credentials were discarded; revoke Google access manually before reconnecting.' );
		}
		if ( ! self::write_option( self::TOKEN_OPTION, $sealed ) ) {
			self::delete_option_verified( self::TOKEN_OPTION );
			return new WP_Error( 'mad4b_google_drive_revocation_state_persist_failed', 'Google revocation was not confirmed and the disabled retry state could not be persisted. Local credentials were discarded; revoke Google access manually before reconnecting.' );
		}
		return new WP_Error(
			'mad4b_google_drive_remote_revocation_unconfirmed',
			'Google access could not be confirmed revoked. The connection is disabled locally and retained only to retry revocation.',
			array(
				'revocation_pending' => true,
				'revocation_error' => (string) $record['revocation_error'],
				'revocation_attempted_at' => (string) $record['revocation_attempted_at'],
			)
		);
	}

	public static function about() {
		return self::api_get( self::DRIVE_API . '/about?fields=user(displayName,emailAddress,permissionId)' );
	}

	public static function normalization_capabilities() {
		$zip = class_exists( 'ZipArchive' );
		return array(
			array( 'type' => 'Google Docs', 'mime' => 'application/vnd.google-apps.document', 'mode' => 'full_text', 'status' => 'ready', 'note' => 'Exported as bounded plain text.' ),
			array( 'type' => 'Google Sheets', 'mime' => 'application/vnd.google-apps.spreadsheet', 'mode' => 'structured_text', 'status' => $zip ? 'ready' : 'runtime_dependency', 'note' => $zip ? 'Exported as XLSX and normalized across worksheets.' : 'ZipArchive is required for full workbook normalization.' ),
			array( 'type' => 'Google Slides', 'mime' => 'application/vnd.google-apps.presentation', 'mode' => 'structured_text', 'status' => $zip ? 'ready' : 'runtime_dependency', 'note' => $zip ? 'Exported as PPTX and normalized across slides and notes.' : 'ZipArchive is required for presentation normalization.' ),
			array( 'type' => 'Google Drawings', 'mime' => 'application/vnd.google-apps.drawing', 'mode' => 'full_text', 'status' => 'ready', 'note' => 'Exported as SVG and normalized from visible text.' ),
			array( 'type' => 'Google Apps Script', 'mime' => 'application/vnd.google-apps.script', 'mode' => 'full_text', 'status' => 'ready', 'note' => 'Exported as bounded JSON.' ),
			array( 'type' => 'Google Forms', 'mime' => 'application/vnd.google-apps.form', 'mode' => 'structured_text', 'status' => $zip ? 'ready' : 'runtime_dependency', 'note' => 'Downloaded read-only as ZIP and normalized from textual entries.' ),
			array( 'type' => 'Google Sites', 'mime' => 'application/vnd.google-apps.site', 'mode' => 'full_text', 'status' => 'ready', 'note' => 'Downloaded read-only as raw text.' ),
			array( 'type' => 'Jamboard', 'mime' => 'application/vnd.google-apps.jam', 'mode' => 'text_or_ocr', 'status' => 'ready_with_ocr_fallback', 'note' => 'Downloaded read-only as PDF, then normalized through the PDF path.' ),
			array( 'type' => 'Google Vids', 'mime' => 'application/vnd.google-apps.vid', 'mode' => 'transcription', 'status' => self::external_extractor_configured() ? 'ready' : 'extractor_required', 'note' => 'Downloaded via Drive files.download and passed to the governed media extractor.' ),
			array( 'type' => 'Other Google-native content', 'mime' => 'application/vnd.google-apps.* / application/vnd.google-gemini.*', 'mode' => 'auto_detect', 'status' => self::external_extractor_configured() ? 'ready' : 'local_or_extractor', 'note' => 'Future/legacy Google-native types use read-only files.download, content sniffing, then governed extraction when needed. Folders and third-party shortcuts remain metadata-only by definition.' ),
			array( 'type' => 'Text / Markdown / CSV / JSON / XML / HTML / SVG', 'mime' => 'text/*', 'mode' => 'full_text', 'status' => 'ready', 'note' => 'Downloaded and normalized as bounded text.' ),
			array( 'type' => 'DOCX / XLSX / PPTX / ODT / ODS / ODP / EPUB', 'mime' => 'application/*+zip', 'mode' => 'structured_text', 'status' => $zip ? 'ready' : 'runtime_dependency', 'note' => $zip ? 'Normalized locally from the archive without mutating Drive.' : 'ZipArchive is required for archive document normalization.' ),
			array( 'type' => 'RTF', 'mime' => 'application/rtf', 'mode' => 'full_text', 'status' => 'ready', 'note' => 'Normalized locally with bounded RTF decoding.' ),
			array( 'type' => 'PDF', 'mime' => 'application/pdf', 'mode' => 'text_or_ocr', 'status' => 'ready_with_ocr_fallback', 'note' => 'Text PDFs are normalized locally; image-only/scanned PDFs route to the governed media extractor when configured.' ),
			array( 'type' => 'Raster images', 'mime' => 'image/*', 'mode' => 'ocr', 'status' => self::external_extractor_configured() ? 'ready' : 'extractor_required', 'note' => 'SVG is local; raster OCR uses the governed external media extractor.' ),
			array( 'type' => 'Audio / Video / Google Vids', 'mime' => 'audio/*,video/*', 'mode' => 'transcription', 'status' => self::external_extractor_configured() ? 'ready' : 'extractor_required', 'note' => 'Transcription uses the governed external media extractor; Drive OAuth scope is not widened.' ),
		);
	}

	public static function get_folder( $folder_id ) {
		$folder_id = self::bounded_drive_id( $folder_id );
		if ( '' === $folder_id ) return new WP_Error( 'mad4b_google_drive_folder_id_invalid', 'Google Drive folder ID is invalid.' );
		if ( 'root' === $folder_id ) return array( 'id' => 'root', 'name' => 'My Drive', 'mimeType' => 'application/vnd.google-apps.folder', 'parents' => array() );
		$url = self::DRIVE_API . '/files/' . rawurlencode( $folder_id ) . '?' . http_build_query(
			array(
				'fields' => 'id,name,mimeType,parents,driveId,webViewLink',
				'supportsAllDrives' => 'true',
			),
			'',
			'&',
			PHP_QUERY_RFC3986
		);
		$file = self::api_get( $url );
		if ( is_wp_error( $file ) ) return $file;
		if ( 'application/vnd.google-apps.folder' !== ( isset( $file['mimeType'] ) ? (string) $file['mimeType'] : '' ) ) return new WP_Error( 'mad4b_google_drive_not_folder', 'Selected Google Drive item is not a folder.' );
		return $file;
	}

	public static function list_folders( $parent_id = 'root' ) {
		$items = self::list_children( $parent_id, true );
		if ( is_wp_error( $items ) ) return $items;
		usort( $items, static function ( $a, $b ) { return strcasecmp( isset( $a['name'] ) ? $a['name'] : '', isset( $b['name'] ) ? $b['name'] : '' ); } );
		return $items;
	}

	public static function scan_folder( $folder_id, $recursive = true ) {
		$folder = self::get_folder( $folder_id );
		if ( is_wp_error( $folder ) ) return $folder;
		$started_at = gmdate( 'c' );
		$scan_generation = hash( 'sha256', (string) $folder['id'] . '|' . $started_at . '|' . ( function_exists( 'wp_generate_uuid4' ) ? wp_generate_uuid4() : uniqid( 'mad4b-', true ) ) );
		$queue = array( array( 'id' => (string) $folder['id'], 'path' => (string) $folder['name'], 'depth' => 0 ) );
		$assets = array();
		$visited = array();
		$complete = true;
		$truncation_reasons = array();

		while ( $queue ) {
			if ( count( $assets ) >= self::MAX_SCAN_FILES ) {
				$complete = false;
				$truncation_reasons[] = 'scan_file_limit';
				break;
			}
			if ( count( $visited ) >= self::MAX_SCAN_FOLDERS ) {
				$complete = false;
				$truncation_reasons[] = 'scan_folder_limit';
				break;
			}

			$current = array_shift( $queue );
			$id = (string) $current['id'];
			if ( isset( $visited[ $id ] ) ) continue;
			$visited[ $id ] = true;

			$children_result = self::list_children( $id, false, true );
			if ( is_wp_error( $children_result ) ) return $children_result;
			if ( empty( $children_result['complete'] ) ) {
				$complete = false;
				foreach ( isset( $children_result['truncation_reasons'] ) && is_array( $children_result['truncation_reasons'] ) ? $children_result['truncation_reasons'] : array( 'child_listing_incomplete' ) as $reason ) {
					$truncation_reasons[] = (string) $reason;
				}
			}

			foreach ( isset( $children_result['items'] ) && is_array( $children_result['items'] ) ? $children_result['items'] : array() as $child ) {
				$mime = isset( $child['mimeType'] ) ? (string) $child['mimeType'] : '';
				$name = isset( $child['name'] ) ? (string) $child['name'] : 'Untitled';
				$path = rtrim( (string) $current['path'], '/' ) . '/' . $name;

				if ( 'application/vnd.google-apps.folder' === $mime ) {
					if ( ! $recursive ) continue;
					if ( (int) $current['depth'] >= 8 ) {
						$complete = false;
						$truncation_reasons[] = 'scan_depth_limit';
						continue;
					}
					if ( count( $visited ) + count( $queue ) >= self::MAX_SCAN_FOLDERS ) {
						$complete = false;
						$truncation_reasons[] = 'scan_folder_limit';
						continue;
					}
					$queue[] = array( 'id' => (string) $child['id'], 'path' => $path, 'depth' => (int) $current['depth'] + 1 );
					continue;
				}

				if ( count( $assets ) >= self::MAX_SCAN_FILES ) {
					$complete = false;
					$truncation_reasons[] = 'scan_file_limit';
					break;
				}

				$content_record = self::fetch_text_content_record( $child );
				if ( is_wp_error( $content_record ) ) {
					$content_record = array(
						'content' => '',
						'complete' => false,
						'bytes' => 0,
						'normalization_status' => 'error',
						'normalization_reason' => $content_record->get_error_code(),
					);
				}
				$content_complete = ! empty( $content_record['complete'] );
				$text = $content_complete && isset( $content_record['content'] ) ? (string) $content_record['content'] : '';
				$basis = '' !== $text
					? $text
					: ( isset( $child['md5Checksum'] ) && $child['md5Checksum'] ? (string) $child['md5Checksum'] : (string) $child['id'] . '|' . ( isset( $child['modifiedTime'] ) ? $child['modifiedTime'] : '' ) );

				$assets[] = array(
					'file_id' => isset( $child['id'] ) ? (string) $child['id'] : '',
					'parent_folder_id' => ! empty( $child['parents'] ) && is_array( $child['parents'] ) ? (string) reset( $child['parents'] ) : '',
					'title' => $name,
					'path' => $path,
					'mimeType' => $mime,
					'modifiedTime' => isset( $child['modifiedTime'] ) ? (string) $child['modifiedTime'] : '',
					'size' => isset( $child['size'] ) ? (string) $child['size'] : '',
					'webViewLink' => isset( $child['webViewLink'] ) ? esc_url_raw( (string) $child['webViewLink'] ) : '',
					'normalized_text' => $text,
					'content_complete' => $content_complete,
					'content_bytes' => isset( $content_record['bytes'] ) ? (int) $content_record['bytes'] : strlen( $text ),
					'normalization_status' => isset( $content_record['normalization_status'] ) ? sanitize_key( (string) $content_record['normalization_status'] ) : ( $content_complete ? 'ready' : 'incomplete' ),
					'normalization_reason' => isset( $content_record['normalization_reason'] ) ? sanitize_key( (string) $content_record['normalization_reason'] ) : '',
					'content_hash' => hash( 'sha256', $basis ),
				);
			}
		}

		if ( ! empty( $queue ) ) {
			$complete = false;
			$truncation_reasons[] = 'scan_queue_incomplete';
		}
		$truncation_reasons = array_values( array_unique( array_filter( array_map( 'sanitize_key', $truncation_reasons ) ) ) );
		return array(
			'contract' => 'mad4b.google-drive-folder-scan.v2',
			'scan_generation' => $scan_generation,
			'started_at' => $started_at,
			'completed_at' => gmdate( 'c' ),
			'folder' => $folder,
			'recursive' => (bool) $recursive,
			'asset_count' => count( $assets ),
			'folder_count' => count( $visited ),
			'complete' => (bool) $complete,
			'truncated' => ! $complete,
			'truncation_reasons' => $truncation_reasons,
			'assets' => $assets,
		);
	}

	public static function create_asset( $source_id, $name, $content, $format = 'markdown' ) {
		$source = self::write_source( $source_id, 'create' );
		if ( is_wp_error( $source ) ) return $source;
		$name = trim( sanitize_text_field( (string) $name ) );
		$content = (string) $content;
		$format = sanitize_key( (string) $format );
		if ( '' === $name || strlen( $name ) > 180 ) return new WP_Error( 'mad4b_google_drive_asset_name_invalid', 'Drive asset name is required and must be 180 characters or fewer.' );
		$content_guard = self::validate_write_content( $content );
		if ( is_wp_error( $content_guard ) ) return $content_guard;
		$target_folder_id = self::bounded_drive_id( isset( $source['external_root_id'] ) ? $source['external_root_id'] : '' );
		if ( '' === $target_folder_id || 'root' === $target_folder_id ) return new WP_Error( 'mad4b_google_drive_write_folder_invalid', 'A specific selected Drive folder is required for creates.' );
		$file = self::create_provider_file( $target_folder_id, $name, $content, $format );
		if ( is_wp_error( $file ) ) return $file;
		$parent_verified = self::verify_created_file_parent( $file, $target_folder_id );
		if ( is_wp_error( $parent_verified ) ) return self::compensate_created_file_failure( $parent_verified, $file, $source, 'create' );
		$observed = self::provider_observed_text( $file, $content );
		if ( is_wp_error( $observed ) ) return self::compensate_created_file_failure( $observed, $file, $source, 'create' );
		$asset = self::provider_asset_payload( $source, $file, $observed );
		$registered = MAD4B_SCP_Context_Authority::upsert_asset_from_provider( (string) $source['source_id'], $asset );
		if ( is_wp_error( $registered ) ) return self::compensate_created_file_failure( $registered, $file, $source, 'create' );
		if ( class_exists( 'MAD4B_SCP_Audit' ) ) {
			MAD4B_SCP_Audit::record(
				'context/create-drive-asset',
				array(
					'source_id' => (string) $source['source_id'],
					'asset_id' => (string) $registered['asset_id'],
					'file_id' => (string) $registered['file_id'],
					'target_folder_id' => $target_folder_id,
					'content_sha256' => (string) $registered['content_hash'],
				),
				'ok'
			);
		}
		return array(
			'contract' => 'mad4b.google-drive-asset-mutation.v1',
			'operation' => 'create',
			'source_id' => (string) $source['source_id'],
			'asset_id' => (string) $registered['asset_id'],
			'file_id' => (string) $registered['file_id'],
			'target_folder_id' => $target_folder_id,
			'content_sha256' => (string) $registered['content_hash'],
			'mime_type' => (string) $registered['mime_type'],
			'status' => 'created',
		);
	}

	public static function update_asset( $asset_id, $expected_content_hash, $content ) {
		$asset = class_exists( 'MAD4B_SCP_Context_Authority' ) ? MAD4B_SCP_Context_Authority::asset( $asset_id ) : array();
		if ( empty( $asset ) ) return new WP_Error( 'mad4b_context_asset_not_found', 'Context asset was not found.' );
		$source = self::write_source( isset( $asset['source_id'] ) ? $asset['source_id'] : '', 'update' );
		if ( is_wp_error( $source ) ) return $source;
		$expected_content_hash = strtolower( trim( (string) $expected_content_hash ) );
		if ( ! preg_match( '/^[a-f0-9]{64}$/', $expected_content_hash ) ) return new WP_Error( 'mad4b_google_drive_expected_hash_invalid', 'Expected content hash must be SHA-256.' );
		if ( empty( $asset['content_hash'] ) || ! hash_equals( (string) $asset['content_hash'], $expected_content_hash ) ) return new WP_Error( 'mad4b_google_drive_asset_registry_stale', 'Context asset registry changed since the mutation was planned.', array( 'current_content_hash' => isset( $asset['content_hash'] ) ? (string) $asset['content_hash'] : '' ) );
		$content = (string) $content;
		$content_guard = self::validate_write_content( $content );
		if ( is_wp_error( $content_guard ) ) return $content_guard;
		if ( strlen( $content ) > self::MAX_REVERSIBLE_TEXT_BYTES ) return new WP_Error( 'mad4b_google_drive_reversible_write_too_large', 'Governed Drive update exceeds the reversible snapshot limit.', array( 'max_bytes' => self::MAX_REVERSIBLE_TEXT_BYTES ) );
		$file_id = isset( $asset['file_id'] ) ? (string) $asset['file_id'] : '';
		$membership = self::assert_file_within_source( $file_id, $source );
		if ( is_wp_error( $membership ) ) return $membership;
		$metadata = self::get_file_metadata( $file_id );
		if ( is_wp_error( $metadata ) ) return $metadata;
		$current_text = self::fetch_text_content( $metadata );
		if ( is_wp_error( $current_text ) ) return $current_text;
		$current_hash = hash( 'sha256', (string) $current_text );
		if ( ! hash_equals( $expected_content_hash, $current_hash ) ) return new WP_Error( 'mad4b_google_drive_asset_remote_stale', 'Google Drive content changed since the Context asset was read.', array( 'current_content_hash' => $current_hash ) );
		$updated = self::replace_provider_file_content( $metadata, $content );
		if ( is_wp_error( $updated ) ) return $updated;
		$observed = self::provider_observed_text( $updated, $content );
		if ( is_wp_error( $observed ) ) return $observed;
		$payload = self::provider_asset_payload( $source, $updated, $observed, $asset );
		$registered = MAD4B_SCP_Context_Authority::upsert_asset_from_provider( (string) $source['source_id'], $payload, $asset );
		if ( is_wp_error( $registered ) ) return $registered;
		return array(
			'contract' => 'mad4b.google-drive-asset-mutation.v1',
			'operation' => 'update',
			'source_id' => (string) $source['source_id'],
			'asset_id' => (string) $registered['asset_id'],
			'file_id' => (string) $registered['file_id'],
			'before_sha256' => $expected_content_hash,
			'after_sha256' => (string) $registered['content_hash'],
			'status' => 'updated',
		);
	}

	public static function recreate_asset( $asset_id, $content, $format = 'google_doc' ) {
		$asset = class_exists( 'MAD4B_SCP_Context_Authority' ) ? MAD4B_SCP_Context_Authority::asset( $asset_id ) : array();
		if ( empty( $asset ) ) return new WP_Error( 'mad4b_context_asset_not_found', 'Context asset was not found.' );
		if ( 'unavailable' !== ( isset( $asset['status'] ) ? (string) $asset['status'] : '' ) ) return new WP_Error( 'mad4b_google_drive_recreate_requires_unavailable_asset', 'Recreate is only allowed for a Context asset confirmed unavailable by the latest source scan.' );
		$source = self::write_source( isset( $asset['source_id'] ) ? $asset['source_id'] : '', 'recreate' );
		if ( is_wp_error( $source ) ) return $source;
		$absence = self::assert_original_file_absent( isset( $asset['file_id'] ) ? $asset['file_id'] : '', $asset, $source );
		if ( is_wp_error( $absence ) ) return $absence;
		$target_folder_id = self::resolve_recreate_target_folder( $asset, $source );
		if ( is_wp_error( $target_folder_id ) ) return $target_folder_id;
		$content = (string) $content;
		$content_guard = self::validate_write_content( $content );
		if ( is_wp_error( $content_guard ) ) return $content_guard;
		if ( strlen( $content ) > self::MAX_REVERSIBLE_TEXT_BYTES ) return new WP_Error( 'mad4b_google_drive_reversible_write_too_large', 'Governed Drive recreation exceeds the reversible snapshot limit.', array( 'max_bytes' => self::MAX_REVERSIBLE_TEXT_BYTES ) );
		$title = isset( $asset['title'] ) ? (string) $asset['title'] : 'Recreated Context Asset';
		$title = preg_replace( '/\s+\(recreated[^)]*\)$/i', '', $title );
		$file = self::create_provider_file( $target_folder_id, $title, $content, $format );
		if ( is_wp_error( $file ) ) return $file;
		$parent_verified = self::verify_created_file_parent( $file, $target_folder_id );
		if ( is_wp_error( $parent_verified ) ) return self::compensate_created_file_failure( $parent_verified, $file, $source, 'recreate' );
		$observed = self::provider_observed_text( $file, $content );
		if ( is_wp_error( $observed ) ) return self::compensate_created_file_failure( $observed, $file, $source, 'recreate' );
		$payload = self::provider_asset_payload( $source, $file, $observed, $asset );
		$transition = MAD4B_SCP_Context_Authority::register_recreated_asset( (string) $asset_id, (string) $source['source_id'], $payload, $asset );
		if ( is_wp_error( $transition ) ) return self::compensate_created_file_failure( $transition, $file, $source, 'recreate' );
		$registered = isset( $transition['replacement'] ) && is_array( $transition['replacement'] ) ? $transition['replacement'] : array();
		if ( empty( $registered['asset_id'] ) || empty( $registered['file_id'] ) ) {
			return self::compensate_created_file_failure(
				new WP_Error( 'mad4b_context_recreate_registry_transition_invalid', 'Context recreation registry transition returned an invalid replacement binding.' ),
				$file,
				$source,
				'recreate'
			);
		}
		return array(
			'contract' => 'mad4b.google-drive-asset-mutation.v1',
			'operation' => 'recreate',
			'source_id' => (string) $source['source_id'],
			'previous_asset_id' => (string) $asset_id,
			'asset_id' => (string) $registered['asset_id'],
			'file_id' => (string) $registered['file_id'],
			'content_sha256' => (string) $registered['content_hash'],
			'status' => 'recreated',
		);
	}

	public static function reversible_update_state( $asset_id, $expected_content_hash = '' ) {
		$asset = class_exists( 'MAD4B_SCP_Context_Authority' ) ? MAD4B_SCP_Context_Authority::asset( $asset_id ) : array();
		if ( empty( $asset ) ) return new WP_Error( 'mad4b_context_asset_not_found', 'Context asset was not found.' );
		if ( 'ready' !== ( isset( $asset['status'] ) ? (string) $asset['status'] : '' ) ) return new WP_Error( 'mad4b_google_drive_update_asset_not_ready', 'Only a ready Context asset can be updated.' );
		$source = self::write_source( isset( $asset['source_id'] ) ? $asset['source_id'] : '', 'update' );
		if ( is_wp_error( $source ) ) return $source;
		$file_id = isset( $asset['file_id'] ) ? (string) $asset['file_id'] : '';
		$membership = self::assert_file_within_source( $file_id, $source );
		if ( is_wp_error( $membership ) ) return $membership;
		$metadata = self::get_file_metadata( $file_id );
		if ( is_wp_error( $metadata ) ) return $metadata;
		$mime = isset( $metadata['mimeType'] ) ? strtolower( (string) $metadata['mimeType'] ) : '';
		if ( 'application/vnd.google-apps.document' === $mime ) return new WP_Error( 'mad4b_google_docs_rich_rollback_not_certified', 'In-place Google Docs text replacement is not mounted until rich document structure has an exact rollback contract. Recreate remains available for assets confirmed unavailable.' );
		if ( 0 !== strpos( $mime, 'text/' ) && ! in_array( $mime, array( 'application/json', 'application/xml', 'application/csv' ), true ) ) return new WP_Error( 'mad4b_google_drive_reversible_update_type_unsupported', 'This Drive file type is not certified for reversible in-place text update.', array( 'mime_type' => $mime ) );
		$content = self::fetch_text_content( $metadata );
		if ( is_wp_error( $content ) ) return $content;
		if ( strlen( $content ) > self::MAX_REVERSIBLE_TEXT_BYTES ) return new WP_Error( 'mad4b_google_drive_reversible_snapshot_too_large', 'Current Drive asset exceeds the reversible snapshot limit.', array( 'max_bytes' => self::MAX_REVERSIBLE_TEXT_BYTES ) );
		$hash = hash( 'sha256', (string) $content );
		$expected_content_hash = strtolower( trim( (string) $expected_content_hash ) );
		if ( '' !== $expected_content_hash && ( ! preg_match( '/^[a-f0-9]{64}$/', $expected_content_hash ) || ! hash_equals( $expected_content_hash, $hash ) ) ) return new WP_Error( 'mad4b_google_drive_reversible_snapshot_stale', 'Drive asset changed before reversible mutation capture.', array( 'current_content_hash' => $hash ) );
		return array(
			'asset_id' => (string) $asset['asset_id'],
			'source_id' => (string) $asset['source_id'],
			'file_id' => $file_id,
			'mime_type' => isset( $metadata['mimeType'] ) ? (string) $metadata['mimeType'] : '',
			'status' => (string) $asset['status'],
			'content' => (string) $content,
			'content_sha256' => $hash,
		);
	}

	public static function reversible_recreate_state( $asset_id ) {
		$asset = class_exists( 'MAD4B_SCP_Context_Authority' ) ? MAD4B_SCP_Context_Authority::asset( $asset_id ) : array();
		if ( empty( $asset ) ) return new WP_Error( 'mad4b_context_asset_not_found', 'Context asset was not found.' );
		$source = self::write_source( isset( $asset['source_id'] ) ? $asset['source_id'] : '', 'recreate' );
		if ( is_wp_error( $source ) ) return $source;
		$status = isset( $asset['status'] ) ? (string) $asset['status'] : '';
		$replacement_asset_id = isset( $asset['replacement_asset_id'] ) ? (string) $asset['replacement_asset_id'] : '';

		if ( 'unavailable' === $status && '' === $replacement_asset_id ) {
			$absence = self::assert_original_file_absent( isset( $asset['file_id'] ) ? $asset['file_id'] : '', $asset, $source );
			if ( is_wp_error( $absence ) ) return $absence;
		}
		if ( 'rollback_pending' === $status && '' !== $replacement_asset_id ) {
			return new WP_Error(
				'mad4b_google_drive_recreate_recovery_required',
				'Recreate rollback is already pending. Complete registry/provider recovery before another mutation is attempted.',
				array(
					'asset_id' => (string) $asset['asset_id'],
					'replacement_asset_id' => $replacement_asset_id,
					'rollback_started_at' => isset( $asset['rollback_started_at'] ) ? (string) $asset['rollback_started_at'] : '',
				)
			);
		}

		$state = array(
			'asset_id' => (string) $asset['asset_id'],
			'source_id' => (string) $asset['source_id'],
			'original_file_id' => isset( $asset['file_id'] ) ? (string) $asset['file_id'] : '',
			'parent_folder_id' => isset( $asset['parent_folder_id'] ) ? (string) $asset['parent_folder_id'] : '',
			'status' => $status,
			'availability_reason' => isset( $asset['availability_reason'] ) ? (string) $asset['availability_reason'] : '',
			'absence_scan_generation' => isset( $asset['absence_scan_generation'] ) ? (string) $asset['absence_scan_generation'] : '',
			'replacement_asset_id' => $replacement_asset_id,
			'replacement_file_id' => '',
			'replacement_content_sha256' => '',
		);
		if ( 'unavailable' === $status && '' === $replacement_asset_id ) return $state;
		if ( 'recreated' !== $status || '' === $replacement_asset_id ) return new WP_Error( 'mad4b_google_drive_recreate_state_invalid', 'Context asset is neither an unavailable original nor a verified recreated asset.' );

		$replacement = MAD4B_SCP_Context_Authority::asset( $replacement_asset_id );
		if ( empty( $replacement ) || empty( $replacement['file_id'] ) || ! hash_equals( (string) $asset['source_id'], (string) $replacement['source_id'] ) ) return new WP_Error( 'mad4b_google_drive_replacement_registry_invalid', 'Recreated Context asset replacement binding is missing or invalid.' );
		$membership = self::assert_file_within_source( (string) $replacement['file_id'], $source );
		if ( is_wp_error( $membership ) ) return $membership;
		$metadata = self::get_file_metadata( (string) $replacement['file_id'] );
		if ( is_wp_error( $metadata ) ) return $metadata;
		$content = self::fetch_text_content( $metadata );
		if ( is_wp_error( $content ) ) return $content;
		if ( strlen( $content ) > self::MAX_REVERSIBLE_TEXT_BYTES ) return new WP_Error( 'mad4b_google_drive_reversible_snapshot_too_large', 'Recreated Drive asset exceeds the reversible snapshot limit.', array( 'max_bytes' => self::MAX_REVERSIBLE_TEXT_BYTES ) );
		$state['replacement_file_id'] = (string) $replacement['file_id'];
		$state['replacement_content_sha256'] = hash( 'sha256', (string) $content );
		return $state;
	}

	public static function restore_update_state( array $target, array $before_state ) {
		$asset_id = isset( $target['asset_id'] ) ? (string) $target['asset_id'] : '';
		$asset = class_exists( 'MAD4B_SCP_Context_Authority' ) ? MAD4B_SCP_Context_Authority::asset( $asset_id ) : array();
		if ( empty( $asset ) || ! array_key_exists( 'content', $before_state ) ) return new WP_Error( 'mad4b_google_drive_update_restore_state_invalid', 'Drive update rollback state is incomplete.' );
		foreach ( array( 'source_id', 'file_id' ) as $field ) {
			if ( empty( $target[ $field ] ) || empty( $before_state[ $field ] ) || ! hash_equals( (string) $target[ $field ], (string) $before_state[ $field ] ) ) return new WP_Error( 'mad4b_google_drive_update_restore_target_mismatch', 'Drive update rollback target no longer matches the captured asset identity.' );
		}
		if ( ! hash_equals( (string) $asset['source_id'], (string) $target['source_id'] ) || ! hash_equals( (string) $asset['file_id'], (string) $target['file_id'] ) ) return new WP_Error( 'mad4b_google_drive_update_restore_registry_drift', 'Context asset identity changed after the recorded Drive update.' );
		$source = self::write_source( (string) $asset['source_id'], 'update' );
		if ( is_wp_error( $source ) ) return $source;
		$membership = self::assert_file_within_source( (string) $asset['file_id'], $source );
		if ( is_wp_error( $membership ) ) return $membership;
		$metadata = self::get_file_metadata( (string) $asset['file_id'] );
		if ( is_wp_error( $metadata ) ) return $metadata;
		$restored = self::replace_provider_file_content( $metadata, (string) $before_state['content'] );
		if ( is_wp_error( $restored ) ) return $restored;
		$observed = self::provider_observed_text( $restored, (string) $before_state['content'] );
		if ( is_wp_error( $observed ) ) return $observed;
		$payload = self::provider_asset_payload( $source, $restored, $observed, $asset );
		$registered = MAD4B_SCP_Context_Authority::upsert_asset_from_provider( (string) $source['source_id'], $payload, $asset );
		return is_wp_error( $registered ) ? $registered : true;
	}

	public static function restore_recreate_state( array $target, array $before_state ) {
		$asset_id = isset( $target['asset_id'] ) ? (string) $target['asset_id'] : '';
		$current = self::reversible_recreate_state( $asset_id );
		if ( is_wp_error( $current ) ) return $current;
		if ( 'recreated' !== ( isset( $current['status'] ) ? (string) $current['status'] : '' ) || empty( $current['replacement_asset_id'] ) || empty( $current['replacement_file_id'] ) ) return new WP_Error( 'mad4b_google_drive_recreate_restore_state_invalid', 'Recreated Drive asset has no exact replacement to undo.' );
		if ( empty( $before_state['asset_id'] ) || ! hash_equals( (string) $before_state['asset_id'], $asset_id ) || 'unavailable' !== ( isset( $before_state['status'] ) ? (string) $before_state['status'] : '' ) ) return new WP_Error( 'mad4b_google_drive_recreate_restore_before_invalid', 'Recreate rollback does not contain the unavailable original state.' );
		$source = self::write_source( (string) $current['source_id'], 'recreate' );
		if ( is_wp_error( $source ) ) return $source;
		$audit = class_exists( 'MAD4B_SCP_Audit' ) ? MAD4B_SCP_Audit::storage_status() : array();
		if ( ! is_array( $audit ) || empty( $audit['ready'] ) ) return new WP_Error( 'mad4b_google_drive_recreate_undo_audit_not_ready', 'Append-only audit storage must be ready before deleting a replacement during canonical undo.' );

		$intent = MAD4B_SCP_Context_Authority::begin_recreated_asset_rollback( $asset_id, (string) $current['replacement_asset_id'] );
		if ( is_wp_error( $intent ) ) return $intent;

		$deleted = self::delete_provider_file_for_rollback( (string) $current['replacement_file_id'], $source );
		if ( is_wp_error( $deleted ) ) {
			$cancel = MAD4B_SCP_Context_Authority::cancel_recreated_asset_rollback( $asset_id, (string) $current['replacement_asset_id'], $deleted->get_error_code() );
			if ( is_wp_error( $cancel ) ) {
				return new WP_Error(
					'mad4b_google_drive_recreate_rollback_recovery_required',
					'Provider deletion failed and rollback intent could not be cancelled cleanly. Manual governed recovery is required.',
					array(
						'provider_error_code' => $deleted->get_error_code(),
						'registry_error_code' => $cancel->get_error_code(),
						'asset_id' => $asset_id,
						'replacement_asset_id' => (string) $current['replacement_asset_id'],
						'replacement_file_id' => (string) $current['replacement_file_id'],
						'provider_deleted' => false,
					)
				);
			}
			return $deleted;
		}

		$finalized = MAD4B_SCP_Context_Authority::rollback_recreated_asset( $asset_id, (string) $current['replacement_asset_id'], $before_state );
		if ( is_wp_error( $finalized ) ) {
			return new WP_Error(
				'mad4b_google_drive_recreate_rollback_recovery_required',
				'The exact replacement file was deleted, but the Context registry could not finalize rollback. The persisted rollback intent prevents further mutation until recovery completes.',
				array(
					'registry_error_code' => $finalized->get_error_code(),
					'asset_id' => $asset_id,
					'replacement_asset_id' => (string) $current['replacement_asset_id'],
					'replacement_file_id' => (string) $current['replacement_file_id'],
					'provider_deleted' => true,
					'rollback_intent_contract' => isset( $intent['contract'] ) ? (string) $intent['contract'] : '',
				)
			);
		}
		return $finalized;
	}

	private static function verify_created_file_parent( array $file, $expected_folder_id ) {
		$expected_folder_id = self::bounded_drive_id( $expected_folder_id );
		$file_id = self::bounded_drive_id( isset( $file['id'] ) ? $file['id'] : '' );
		if ( '' === $expected_folder_id || 'root' === $expected_folder_id || '' === $file_id ) return new WP_Error( 'mad4b_google_drive_created_parent_verification_invalid', 'Created Drive file cannot be bound to an invalid target folder or provider identity.' );
		$parents = isset( $file['parents'] ) && is_array( $file['parents'] ) ? array_values( array_filter( array_map( 'strval', $file['parents'] ) ) ) : array();
		if ( empty( $parents ) ) {
			$metadata = self::get_file_metadata( $file_id );
			if ( is_wp_error( $metadata ) ) return $metadata;
			$parents = isset( $metadata['parents'] ) && is_array( $metadata['parents'] ) ? array_values( array_filter( array_map( 'strval', $metadata['parents'] ) ) ) : array();
		}
		if ( 1 !== count( $parents ) || ! hash_equals( $expected_folder_id, (string) reset( $parents ) ) ) {
			return new WP_Error(
				'mad4b_google_drive_created_parent_mismatch',
				'Google Drive created the asset outside the exact selected target folder; the write will be compensated.',
				array( 'file_id' => $file_id, 'expected_folder_id' => $expected_folder_id, 'observed_parent_count' => count( $parents ) )
			);
		}
		return true;
	}

	private static function provider_observed_text( array $file, $fallback ) {
		$observed = self::fetch_text_content( $file );
		if ( is_wp_error( $observed ) ) return $observed;
		if ( '' === (string) $observed && '' !== (string) $fallback ) return new WP_Error( 'mad4b_google_drive_write_readback_empty', 'Google Drive write completed but provider readback was unexpectedly empty.' );
		return (string) $observed;
	}

	private static function compensate_created_file_failure( $provider_error, array $file, array $source, $operation ) {
		$provider_error = is_wp_error( $provider_error ) ? $provider_error : new WP_Error( 'mad4b_google_drive_post_create_verification_failed', 'Google Drive file creation could not be verified.' );
		$file_id = self::bounded_drive_id( isset( $file['id'] ) ? $file['id'] : '' );
		$operation = sanitize_key( (string) $operation );
		if ( '' === $file_id ) return $provider_error;

		$cleanup = self::delete_provider_file_for_rollback( $file_id, $source, false );
		$cleanup_ok = true === $cleanup;
		if ( class_exists( 'MAD4B_SCP_Audit' ) ) {
			MAD4B_SCP_Audit::record(
				'mad4b/context-drive-post-create-compensation',
				array(
					'operation' => $operation,
					'source_id' => isset( $source['source_id'] ) ? (string) $source['source_id'] : '',
					'file_id' => $file_id,
					'provider_error_code' => $provider_error->get_error_code(),
					'cleanup_ok' => $cleanup_ok,
					'cleanup_error_code' => is_wp_error( $cleanup ) ? $cleanup->get_error_code() : '',
				),
				$cleanup_ok ? 'ok' : 'failure'
			);
		}
		if ( $cleanup_ok ) return $provider_error;
		return new WP_Error(
			'mad4b_google_drive_post_create_compensation_failed',
			'Google Drive creation failed verification and the exact newly-created file could not be removed automatically.',
			array(
				'operation' => $operation,
				'source_id' => isset( $source['source_id'] ) ? (string) $source['source_id'] : '',
				'file_id' => $file_id,
				'provider_error_code' => $provider_error->get_error_code(),
				'cleanup_error_code' => is_wp_error( $cleanup ) ? $cleanup->get_error_code() : 'unknown',
			)
		);
	}

	private static function delete_provider_file_for_rollback( $file_id, array $source, $require_source_membership = true ) {
		$file_id = self::bounded_drive_id( $file_id );
		if ( '' === $file_id ) return new WP_Error( 'mad4b_google_drive_file_id_invalid', 'Google Drive replacement file ID is invalid.' );
		if ( $require_source_membership ) {
			$membership = self::assert_file_within_source( $file_id, $source );
			if ( is_wp_error( $membership ) ) return $membership;
		}
		$token = self::access_token();
		if ( is_wp_error( $token ) ) return $token;
		$url = self::DRIVE_API . '/files/' . rawurlencode( $file_id ) . '?supportsAllDrives=true';
		$response = wp_remote_request(
			esc_url_raw( $url ),
			array(
				'method' => 'DELETE',
				'timeout' => 25,
				'redirection' => 0,
				'headers' => array( 'Authorization' => 'Bearer ' . $token, 'Accept' => 'application/json' ),
			)
		);
		if ( is_wp_error( $response ) ) return $response;
		$status = (int) wp_remote_retrieve_response_code( $response );
		if ( 200 !== $status && 204 !== $status ) return new WP_Error( 'mad4b_google_drive_rollback_delete_failed', 'Canonical Drive rollback could not remove the exact replacement file.', array( 'status' => $status ) );
		return true;
	}

	public static function read_context_asset( $asset_id ) {
		$asset = class_exists( 'MAD4B_SCP_Context_Authority' ) ? MAD4B_SCP_Context_Authority::asset( $asset_id ) : array();
		if ( empty( $asset ) ) return new WP_Error( 'mad4b_context_asset_not_found', 'Context asset was not found.' );
		if ( 'ready' !== ( isset( $asset['status'] ) ? (string) $asset['status'] : '' ) ) return new WP_Error( 'mad4b_context_asset_not_ready', 'Context asset is not ready for runtime loading.' );
		if ( array_key_exists( 'content_complete', $asset ) && empty( $asset['content_complete'] ) ) return new WP_Error( 'mad4b_context_asset_content_incomplete', 'Context asset normalization is incomplete and cannot be used as governed runtime context.' );
		$source = class_exists( 'MAD4B_SCP_Context_Authority' ) ? MAD4B_SCP_Context_Authority::source( isset( $asset['source_id'] ) ? $asset['source_id'] : '' ) : array();
		if ( empty( $source ) || 'google_drive' !== ( isset( $source['provider'] ) ? (string) $source['provider'] : '' ) ) return new WP_Error( 'mad4b_context_asset_source_invalid', 'Context asset is not bound to the governed Google Drive source provider.' );
		$file_id = isset( $asset['file_id'] ) ? (string) $asset['file_id'] : '';
		$membership = self::assert_file_within_source( $file_id, $source );
		if ( is_wp_error( $membership ) ) return $membership;
		$metadata = self::get_file_metadata( $file_id );
		if ( is_wp_error( $metadata ) ) return $metadata;
		$content_record = self::fetch_text_content_record( $metadata );
		if ( is_wp_error( $content_record ) ) return $content_record;
		if ( empty( $content_record['complete'] ) ) return new WP_Error( 'mad4b_context_asset_content_incomplete', 'Context asset provider readback is incomplete; refresh and normalize the source before using it.' );
		$content = isset( $content_record['content'] ) ? (string) $content_record['content'] : '';
		if ( '' === $content ) return new WP_Error( 'mad4b_context_asset_text_unavailable', 'Context asset does not expose normalized text through the certified read provider.' );
		$observed_hash = hash( 'sha256', $content );
		$registered_hash = isset( $asset['content_hash'] ) ? strtolower( trim( (string) $asset['content_hash'] ) ) : '';
		if ( ! preg_match( '/^[a-f0-9]{64}$/', $registered_hash ) || ! hash_equals( $registered_hash, $observed_hash ) ) {
			return new WP_Error(
				'mad4b_context_asset_remote_drift',
				'Context asset changed in Google Drive after the last governed scan; refresh and review the asset before using it as runtime context.',
				array(
					'asset_id' => isset( $asset['asset_id'] ) ? (string) $asset['asset_id'] : '',
					'registered_content_hash' => $registered_hash,
					'observed_content_hash' => $observed_hash,
				)
			);
		}
		return array(
			'contract' => 'mad4b.context-asset-read.v2',
			'asset_id' => isset( $asset['asset_id'] ) ? (string) $asset['asset_id'] : '',
			'source_id' => isset( $asset['source_id'] ) ? (string) $asset['source_id'] : '',
			'file_id' => $file_id,
			'content' => $content,
			'bytes' => strlen( $content ),
			'content_complete' => true,
			'content_sha256' => $observed_hash,
			'mime_type' => isset( $metadata['mimeType'] ) ? (string) $metadata['mimeType'] : '',
			'observed_at' => gmdate( 'c' ),
		);
	}

	public static function asset_write_capabilities( $asset_id ) {
		$asset = class_exists( 'MAD4B_SCP_Context_Authority' ) ? MAD4B_SCP_Context_Authority::asset( $asset_id ) : array();
		if ( empty( $asset ) ) return array( 'update' => false, 'recreate' => false, 'blockers' => array( 'context_asset_not_found' ) );
		$source = MAD4B_SCP_Context_Authority::source( isset( $asset['source_id'] ) ? $asset['source_id'] : '' );
		$status = self::connection_status();
		$blockers = array();
		if ( empty( $status['write_available'] ) ) $blockers[] = 'google_drive_write_scope_not_granted';
		if ( empty( $source ) ) $blockers[] = 'context_source_not_found';
		if ( ! empty( $source ) && 'task_attachment' === ( isset( $source['mode'] ) ? (string) $source['mode'] : '' ) ) $blockers[] = 'task_source_write_forbidden';
		$mime = isset( $asset['mime_type'] ) ? strtolower( (string) $asset['mime_type'] ) : '';
		$text_update_type = 0 === strpos( $mime, 'text/' ) || in_array( $mime, array( 'application/json', 'application/xml', 'application/csv' ), true );
		$update = ! empty( $status['write_available'] )
			&& 'ready' === ( isset( $asset['status'] ) ? (string) $asset['status'] : '' )
			&& $text_update_type
			&& ! empty( $source )
			&& MAD4B_SCP_Context_Authority::source_allows_write( (string) $source['source_id'], 'update' );
		$recreate = ! empty( $status['write_available'] )
			&& 'unavailable' === ( isset( $asset['status'] ) ? (string) $asset['status'] : '' )
			&& ! empty( $source )
			&& MAD4B_SCP_Context_Authority::source_allows_write( (string) $source['source_id'], 'recreate' );
		if ( 'ready' === ( isset( $asset['status'] ) ? (string) $asset['status'] : '' ) && 'application/vnd.google-apps.document' === $mime ) $blockers[] = 'google_docs_rich_rollback_not_certified';
		elseif ( 'ready' === ( isset( $asset['status'] ) ? (string) $asset['status'] : '' ) && ! $text_update_type ) $blockers[] = 'asset_type_not_certified_for_reversible_update';
		if ( 'ready' === ( isset( $asset['status'] ) ? (string) $asset['status'] : '' ) && ! empty( $source ) && ! MAD4B_SCP_Context_Authority::source_allows_write( (string) $source['source_id'], 'update' ) ) $blockers[] = 'source_policy_blocks_update';
		if ( 'unavailable' === ( isset( $asset['status'] ) ? (string) $asset['status'] : '' ) && ! empty( $source ) && ! MAD4B_SCP_Context_Authority::source_allows_write( (string) $source['source_id'], 'recreate' ) ) $blockers[] = 'source_policy_blocks_recreate';
		return array(
			'contract' => 'mad4b.context-asset-write-capabilities.v1',
			'asset_id' => isset( $asset['asset_id'] ) ? (string) $asset['asset_id'] : '',
			'source_id' => isset( $asset['source_id'] ) ? (string) $asset['source_id'] : '',
			'write_available' => ! empty( $status['write_available'] ),
			'update' => (bool) $update,
			'recreate' => (bool) $recreate,
			'update_reversible' => (bool) $update,
			'recreate_reversible' => (bool) $recreate,
			'blockers' => array_values( array_unique( $blockers ) ),
		);
	}

	private static function write_source( $source_id, $operation ) {
		$status = self::connection_status();
		if ( empty( $status['write_available'] ) ) return new WP_Error( 'mad4b_google_drive_write_scope_required', 'Google Drive is connected without governed read+write scope. Upgrade the connection before attempting a Drive mutation.' );
		if ( ! class_exists( 'MAD4B_SCP_Context_Authority' ) ) return new WP_Error( 'mad4b_context_authority_unavailable', 'Context Authority is unavailable.' );
		$source = MAD4B_SCP_Context_Authority::source( $source_id );
		if ( empty( $source ) || 'google_drive' !== ( isset( $source['provider'] ) ? (string) $source['provider'] : '' ) ) return new WP_Error( 'mad4b_google_drive_source_not_found', 'Selected Context source is not a Google Drive source.' );
		if ( 'root' === (string) $source['external_root_id'] ) return new WP_Error( 'mad4b_google_drive_root_write_forbidden', 'Drive write operations require a specific selected source folder; My Drive root is intentionally read-only.' );
		$operation = sanitize_key( (string) $operation );
		if ( ! MAD4B_SCP_Context_Authority::source_allows_write( (string) $source['source_id'], $operation ) ) {
			return new WP_Error(
				'mad4b_google_drive_source_write_policy_denied',
				'The selected Context source policy does not allow this Drive mutation.',
				array(
					'source_id' => (string) $source['source_id'],
					'operation' => $operation,
					'write_policy' => isset( $source['write_policy'] ) ? (string) $source['write_policy'] : 'read_only',
				)
			);
		}
		return $source;
	}

	private static function validate_write_content( $content ) {
		$bytes = strlen( (string) $content );
		if ( $bytes < 1 ) return new WP_Error( 'mad4b_google_drive_empty_write_denied', 'Drive asset content cannot be empty.' );
		if ( $bytes > self::MAX_WRITE_BYTES ) return new WP_Error( 'mad4b_google_drive_write_too_large', 'Drive asset content exceeds the governed write size limit.', array( 'max_bytes' => self::MAX_WRITE_BYTES ) );
		if ( '' === wp_check_invalid_utf8( (string) $content ) ) return new WP_Error( 'mad4b_google_drive_write_utf8_required', 'Drive asset content must be valid UTF-8 text.' );
		return true;
	}

	private static function recreate_parent_candidate( array $asset, array $source ) {
		$root_id = self::bounded_drive_id( isset( $source['external_root_id'] ) ? $source['external_root_id'] : '' );
		if ( '' === $root_id || 'root' === $root_id ) return new WP_Error( 'mad4b_google_drive_write_folder_invalid', 'A specific selected Drive source folder is required for recreation.' );
		$parent_folder_id = self::bounded_drive_id( isset( $asset['parent_folder_id'] ) ? $asset['parent_folder_id'] : '' );
		if ( '' === $parent_folder_id ) {
			return new WP_Error(
				'mad4b_google_drive_recreate_parent_unavailable',
				'Original asset has no exact parent-folder lineage. Rescan the governed source before attempting recreation.'
			);
		}
		$is_source_root = hash_equals( $root_id, $parent_folder_id );
		if ( ! $is_source_root && empty( $source['recursive'] ) ) {
			return new WP_Error(
				'mad4b_google_drive_recreate_parent_outside_source',
				'Non-recursive Context sources may recreate assets only in the selected source folder itself.',
				array(
					'parent_folder_id' => $parent_folder_id,
					'root_id' => $root_id,
					'reason' => 'non_recursive_source_boundary',
				)
			);
		}
		return array(
			'root_id' => $root_id,
			'parent_folder_id' => $parent_folder_id,
			'is_source_root' => $is_source_root,
		);
	}

	private static function resolve_recreate_target_folder( array $asset, array $source ) {
		$candidate = self::recreate_parent_candidate( $asset, $source );
		if ( is_wp_error( $candidate ) ) return $candidate;
		$parent_folder_id = (string) $candidate['parent_folder_id'];
		if ( ! empty( $candidate['is_source_root'] ) ) return $parent_folder_id;

		$metadata = self::get_file_metadata( $parent_folder_id );
		if ( is_wp_error( $metadata ) ) {
			$data = $metadata->get_error_data();
			return new WP_Error(
				'mad4b_google_drive_recreate_parent_unavailable',
				'Original parent folder is not currently readable. Recreation will not fall back to the source root.',
				array(
					'parent_folder_id' => $parent_folder_id,
					'provider_error_code' => $metadata->get_error_code(),
					'http_status' => is_array( $data ) && isset( $data['status'] ) ? (int) $data['status'] : 0,
				)
			);
		}
		if ( 'application/vnd.google-apps.folder' !== ( isset( $metadata['mimeType'] ) ? (string) $metadata['mimeType'] : '' ) ) {
			return new WP_Error( 'mad4b_google_drive_recreate_parent_not_folder', 'Stored parent lineage no longer points to a Google Drive folder.' );
		}
		$membership = self::assert_file_within_source( $parent_folder_id, $source );
		if ( is_wp_error( $membership ) ) {
			return new WP_Error(
				'mad4b_google_drive_recreate_parent_outside_source',
				'Original parent folder is no longer inside the selected governed source.',
				array( 'parent_folder_id' => $parent_folder_id, 'membership_error' => $membership->get_error_code() )
			);
		}
		return $parent_folder_id;
	}

	private static function create_provider_file( $folder_id, $name, $content, $format ) {
		$folder_id = self::bounded_drive_id( $folder_id );
		if ( '' === $folder_id || 'root' === $folder_id ) return new WP_Error( 'mad4b_google_drive_write_folder_invalid', 'A specific selected Drive folder is required for writes.' );
		$format = sanitize_key( (string) $format );
		$target_mime = '';
		$media_mime = 'text/plain; charset=UTF-8';
		if ( 'google_doc' === $format ) $target_mime = 'application/vnd.google-apps.document';
		elseif ( 'markdown' === $format ) { $target_mime = 'text/markdown'; $media_mime = 'text/markdown; charset=UTF-8'; if ( ! preg_match( '/\.md$/i', $name ) ) $name .= '.md'; }
		elseif ( 'text' === $format ) { $target_mime = 'text/plain'; if ( ! preg_match( '/\.txt$/i', $name ) ) $name .= '.txt'; }
		else return new WP_Error( 'mad4b_google_drive_write_format_invalid', 'Drive write format must be google_doc, markdown, or text.' );
		$metadata = array( 'name' => $name, 'parents' => array( $folder_id ), 'mimeType' => $target_mime );
		$boundary = 'mad4b_' . wp_generate_password( 24, false, false );
		$body = '--' . $boundary . "\r\nContent-Type: application/json; charset=UTF-8\r\n\r\n" . wp_json_encode( $metadata, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE )
			. "\r\n--" . $boundary . "\r\nContent-Type: " . $media_mime . "\r\n\r\n" . (string) $content . "\r\n--" . $boundary . "--";
		$url = self::DRIVE_UPLOAD_API . '/files?uploadType=multipart&supportsAllDrives=true&fields=' . rawurlencode( 'id,name,mimeType,parents,modifiedTime,webViewLink' );
		return self::authorized_json_request( 'POST', $url, $body, 'multipart/related; boundary=' . $boundary, 'mad4b_google_drive_create_failed' );
	}

	private static function replace_provider_file_content( array $metadata, $content ) {
		$file_id = self::bounded_drive_id( isset( $metadata['id'] ) ? $metadata['id'] : '' );
		$mime = isset( $metadata['mimeType'] ) ? strtolower( (string) $metadata['mimeType'] ) : '';
		if ( '' === $file_id ) return new WP_Error( 'mad4b_google_drive_file_id_invalid', 'Google Drive file ID is invalid.' );
		if ( 'application/vnd.google-apps.document' === $mime ) return self::replace_google_doc_body( $file_id, $content );
		if ( 0 !== strpos( $mime, 'text/' ) && ! in_array( $mime, array( 'application/json', 'application/xml', 'application/csv' ), true ) ) {
			return new WP_Error( 'mad4b_google_drive_asset_update_unsupported', 'This Drive file type cannot be safely updated as governed text. Use recreate instead.', array( 'mime_type' => $mime ) );
		}
		$url = self::DRIVE_UPLOAD_API . '/files/' . rawurlencode( $file_id ) . '?uploadType=media&supportsAllDrives=true&fields=' . rawurlencode( 'id,name,mimeType,parents,modifiedTime,webViewLink' );
		return self::authorized_json_request( 'PATCH', $url, (string) $content, ( $mime ? $mime : 'text/plain' ) . '; charset=UTF-8', 'mad4b_google_drive_update_failed' );
	}

	private static function replace_google_doc_body( $file_id, $content ) {
		$url = self::DOCS_API . '/documents/' . rawurlencode( $file_id );
		$document = self::authorized_json_request( 'GET', $url, null, '', 'mad4b_google_docs_read_failed' );
		if ( is_wp_error( $document ) ) return $document;
		$end_index = 1;
		foreach ( isset( $document['body']['content'] ) && is_array( $document['body']['content'] ) ? $document['body']['content'] : array() as $node ) {
			if ( isset( $node['endIndex'] ) ) $end_index = max( $end_index, absint( $node['endIndex'] ) );
		}
		$requests = array();
		if ( $end_index > 2 ) $requests[] = array( 'deleteContentRange' => array( 'range' => array( 'startIndex' => 1, 'endIndex' => $end_index - 1 ) ) );
		$requests[] = array( 'insertText' => array( 'location' => array( 'index' => 1 ), 'text' => (string) $content ) );
		$updated = self::authorized_json_request( 'POST', $url . ':batchUpdate', wp_json_encode( array( 'requests' => $requests ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ), 'application/json; charset=UTF-8', 'mad4b_google_docs_update_failed' );
		if ( is_wp_error( $updated ) ) return $updated;
		return self::get_file_metadata( $file_id );
	}

	private static function provider_absence_from_metadata_result( $result ) {
		if ( ! is_wp_error( $result ) ) {
			return new WP_Error(
				'mad4b_google_drive_recreate_original_restored',
				'Original Google Drive asset exists again. Rescan Context before attempting recreation.'
			);
		}
		$data = $result->get_error_data();
		$status = is_array( $data ) && isset( $data['status'] ) ? (int) $data['status'] : 0;
		if ( 404 === $status ) return true;
		return new WP_Error(
			'mad4b_google_drive_recreate_absence_unverified',
			'Google Drive did not prove the original asset is absent; recreation remains fail-closed.',
			array(
				'provider_error_code' => $result->get_error_code(),
				'http_status' => $status,
			)
		);
	}

	private static function assert_original_file_absent( $file_id, array $asset = array(), array $source = array() ) {
		$file_id = self::bounded_drive_id( $file_id );
		if ( '' === $file_id ) return new WP_Error( 'mad4b_google_drive_file_id_invalid', 'Original Google Drive file ID is invalid.' );
		if ( empty( $source ) || empty( $source['external_root_id'] ) ) return new WP_Error( 'mad4b_google_drive_recreate_absence_source_invalid', 'Recreation requires the exact governed source binding used by the last complete scan.' );
		if ( empty( $source['last_scan_complete'] ) || empty( $source['last_complete_scan_generation'] ) ) return new WP_Error( 'mad4b_google_drive_recreate_complete_scan_required', 'Recreation requires a complete governed source scan proving the original asset was not observed.' );
		if ( empty( $asset['absence_scan_generation'] ) || ! hash_equals( (string) $source['last_complete_scan_generation'], (string) $asset['absence_scan_generation'] ) ) {
			return new WP_Error( 'mad4b_google_drive_recreate_absence_generation_stale', 'Unavailable-asset evidence is not bound to the latest complete source scan.' );
		}

		$root = self::get_folder( (string) $source['external_root_id'] );
		if ( is_wp_error( $root ) ) return new WP_Error( 'mad4b_google_drive_recreate_source_unreadable', 'The governed source root is not currently readable; absence cannot be proven.', array( 'provider_error_code' => $root->get_error_code() ) );

		$parent_id = self::bounded_drive_id( isset( $asset['parent_folder_id'] ) ? $asset['parent_folder_id'] : '' );
		if ( '' === $parent_id ) return new WP_Error( 'mad4b_google_drive_recreate_parent_unavailable', 'Original parent lineage is required before absence can be proven.' );
		$parent = self::get_file_metadata( $parent_id );
		if ( is_wp_error( $parent ) ) return new WP_Error( 'mad4b_google_drive_recreate_parent_unreadable', 'Original parent folder is not currently readable; absence cannot be proven.', array( 'provider_error_code' => $parent->get_error_code() ) );
		if ( 'application/vnd.google-apps.folder' !== ( isset( $parent['mimeType'] ) ? (string) $parent['mimeType'] : '' ) ) return new WP_Error( 'mad4b_google_drive_recreate_parent_not_folder', 'Stored parent lineage no longer resolves to a folder.' );
		if ( ! hash_equals( (string) $source['external_root_id'], $parent_id ) ) {
			$membership = self::assert_file_within_source( $parent_id, $source );
			if ( is_wp_error( $membership ) ) return new WP_Error( 'mad4b_google_drive_recreate_parent_outside_source', 'Original parent folder is no longer inside the governed source.', array( 'membership_error' => $membership->get_error_code() ) );
		}

		$absence = self::provider_absence_from_metadata_result( self::get_file_metadata( $file_id ) );
		if ( is_wp_error( $absence ) ) return $absence;

		$siblings = self::list_children( $parent_id, false, true );
		if ( is_wp_error( $siblings ) ) return $siblings;
		if ( empty( $siblings['complete'] ) ) return new WP_Error( 'mad4b_google_drive_recreate_parent_listing_incomplete', 'Parent-folder listing is incomplete; duplicate-safe recreation remains fail-closed.' );
		$title = isset( $asset['title'] ) ? trim( (string) $asset['title'] ) : '';
		foreach ( isset( $siblings['items'] ) && is_array( $siblings['items'] ) ? $siblings['items'] : array() as $sibling ) {
			$sibling_id = isset( $sibling['id'] ) ? (string) $sibling['id'] : '';
			$sibling_name = isset( $sibling['name'] ) ? trim( (string) $sibling['name'] ) : '';
			if ( '' !== $sibling_id && hash_equals( $file_id, $sibling_id ) ) return new WP_Error( 'mad4b_google_drive_recreate_original_restored', 'Original Google Drive asset exists again. Rescan Context before attempting recreation.' );
			if ( '' !== $title && '' !== $sibling_name && 0 === strcasecmp( $title, $sibling_name ) ) return new WP_Error( 'mad4b_google_drive_recreate_duplicate_title_detected', 'A file with the original asset title already exists in the exact parent folder; recreation requires operator review.' );
		}
		return true;
	}

	private static function get_file_metadata( $file_id ) {
		$file_id = self::bounded_drive_id( $file_id );
		if ( '' === $file_id ) return new WP_Error( 'mad4b_google_drive_file_id_invalid', 'Google Drive file ID is invalid.' );
		$url = self::DRIVE_API . '/files/' . rawurlencode( $file_id ) . '?' . http_build_query(
			array( 'fields' => 'id,name,mimeType,parents,modifiedTime,size,md5Checksum,driveId,webViewLink,shortcutDetails(targetId,targetMimeType),capabilities(canDownload)', 'supportsAllDrives' => 'true' ),
			'', '&', PHP_QUERY_RFC3986
		);
		return self::api_get( $url );
	}

	private static function assert_file_within_source( $file_id, array $source ) {
		$root_id = isset( $source['external_root_id'] ) ? (string) $source['external_root_id'] : '';
		if ( '' === $root_id || 'root' === $root_id ) return new WP_Error( 'mad4b_google_drive_write_folder_invalid', 'A specific selected Drive folder is required for writes.' );
		$current = self::get_file_metadata( $file_id );
		if ( is_wp_error( $current ) ) return $current;
		$visited = array();
		for ( $depth = 0; $depth < self::MAX_PARENT_DEPTH; $depth++ ) {
			$parents = isset( $current['parents'] ) && is_array( $current['parents'] ) ? $current['parents'] : array();
			foreach ( $parents as $parent_id ) if ( hash_equals( $root_id, (string) $parent_id ) ) return true;
			if ( empty( $parents ) ) break;
			if ( 0 === $depth && empty( $source['recursive'] ) ) {
				return new WP_Error(
					'mad4b_google_drive_asset_outside_selected_source',
					'Drive asset is not an immediate child of this non-recursive Context source.',
					array( 'reason' => 'non_recursive_source_boundary', 'root_id' => $root_id )
				);
			}
			$parent_id = (string) reset( $parents );
			if ( isset( $visited[ $parent_id ] ) ) break;
			$visited[ $parent_id ] = true;
			$current = self::get_file_metadata( $parent_id );
			if ( is_wp_error( $current ) ) return $current;
		}
		return new WP_Error( 'mad4b_google_drive_asset_outside_selected_source', 'Drive asset is outside the selected Context source folder.' );
	}

	private static function provider_asset_payload( array $source, array $file, $content, array $preserve = array() ) {
		$name = isset( $file['name'] ) ? (string) $file['name'] : 'Untitled';
		$parents = isset( $file['parents'] ) && is_array( $file['parents'] ) ? $file['parents'] : array();
		$parent_folder_id = $parents ? self::bounded_drive_id( (string) reset( $parents ) ) : '';
		$path = ( isset( $source['label'] ) ? (string) $source['label'] : 'Drive' ) . '/' . $name;
		$preserved_parent = isset( $preserve['parent_folder_id'] ) ? self::bounded_drive_id( (string) $preserve['parent_folder_id'] ) : '';
		if ( '' !== $parent_folder_id && '' !== $preserved_parent && hash_equals( $parent_folder_id, $preserved_parent ) && ! empty( $preserve['path'] ) ) $path = (string) $preserve['path'];
		return array(
			'file_id' => isset( $file['id'] ) ? (string) $file['id'] : '',
			'parent_folder_id' => $parent_folder_id,
			'title' => $name,
			'path' => $path,
			'mimeType' => isset( $file['mimeType'] ) ? (string) $file['mimeType'] : 'text/plain',
			'modifiedTime' => isset( $file['modifiedTime'] ) ? (string) $file['modifiedTime'] : gmdate( 'c' ),
			'webViewLink' => isset( $file['webViewLink'] ) ? esc_url_raw( (string) $file['webViewLink'] ) : '',
			'normalized_text' => (string) $content,
			'content_complete' => true,
			'content_bytes' => strlen( (string) $content ),
			'normalization_status' => 'ready',
			'normalization_reason' => '',
			'content_hash' => hash( 'sha256', (string) $content ),
		);
	}

	private static function authorized_json_request( $method, $url, $body, $content_type, $error_code ) {
		$token = self::access_token();
		if ( is_wp_error( $token ) ) return $token;
		$args = array(
			'method' => strtoupper( (string) $method ),
			'timeout' => 25,
			'redirection' => 0,
			'headers' => array( 'Authorization' => 'Bearer ' . $token, 'Accept' => 'application/json' ),
		);
		if ( '' !== (string) $content_type ) $args['headers']['Content-Type'] = (string) $content_type;
		if ( null !== $body ) $args['body'] = $body;
		$response = wp_remote_request( esc_url_raw( $url ), $args );
		return self::decode_json_response( $response, $error_code );
	}

	private static function list_children( $parent_id, $folders_only, $detailed = false ) {
		$parent_id = self::bounded_drive_id( $parent_id );
		if ( '' === $parent_id ) return new WP_Error( 'mad4b_google_drive_parent_id_invalid', 'Google Drive parent folder ID is invalid.' );
		$q = "'" . str_replace( "'", "\\'", $parent_id ) . "' in parents and trashed = false";
		if ( $folders_only ) $q .= " and mimeType = 'application/vnd.google-apps.folder'";
		$items = array();
		$page_token = '';
		$pages = 0;
		$limit_hit = false;
		$reasons = array();

		do {
			$params = array(
				'q' => $q,
				'pageSize' => 100,
				'fields' => 'nextPageToken,files(id,name,mimeType,modifiedTime,size,md5Checksum,parents,driveId,webViewLink,description,shortcutDetails(targetId,targetMimeType),capabilities(canDownload))',
				'spaces' => 'drive',
				'supportsAllDrives' => 'true',
				'includeItemsFromAllDrives' => 'true',
			);
			if ( '' !== $page_token ) $params['pageToken'] = $page_token;
			$url = self::DRIVE_API . '/files?' . http_build_query( $params, '', '&', PHP_QUERY_RFC3986 );
			$data = self::api_get( $url );
			if ( is_wp_error( $data ) ) return $data;

			$next_page_token = isset( $data['nextPageToken'] ) ? sanitize_text_field( (string) $data['nextPageToken'] ) : '';
			foreach ( isset( $data['files'] ) && is_array( $data['files'] ) ? $data['files'] : array() as $file ) {
				if ( is_array( $file ) ) $items[] = $file;
				if ( count( $items ) >= self::MAX_SCAN_FILES ) {
					$limit_hit = true;
					$reasons[] = 'child_item_limit';
					break;
				}
			}
			++$pages;
			$page_token = $next_page_token;
			if ( $limit_hit ) break;
		} while ( '' !== $page_token && $pages < 10 );

		if ( '' !== $page_token ) $reasons[] = $pages >= 10 ? 'child_page_limit' : 'child_listing_continuation';
		$complete = ! $limit_hit && '' === $page_token;
		$result = array(
			'items' => $items,
			'complete' => $complete,
			'next_page_token' => $page_token,
			'page_count' => $pages,
			'truncation_reasons' => array_values( array_unique( $reasons ) ),
		);
		return $detailed ? $result : $items;
	}

	private static function normalization_record( $content, $complete = true, $status = 'ready', $reason = '', $bytes = null ) {
		$content = wp_check_invalid_utf8( (string) $content, true );
		$observed = null === $bytes ? strlen( $content ) : (int) $bytes;
		if ( strlen( $content ) > self::MAX_TEXT_BYTES ) {
			return array(
				'content' => substr( $content, 0, self::MAX_TEXT_BYTES ),
				'complete' => false,
				'bytes' => $observed,
				'normalization_status' => 'incomplete',
				'normalization_reason' => 'max_text_bytes_exceeded',
			);
		}
		return array(
			'content' => $content,
			'complete' => (bool) $complete,
			'bytes' => $observed,
			'normalization_status' => sanitize_key( (string) $status ),
			'normalization_reason' => sanitize_key( (string) $reason ),
		);
	}

	private static function fetch_bounded_bytes( $url, $max_bytes, $error_code ) {
		$token = self::access_token();
		if ( is_wp_error( $token ) ) return $token;
		$response = wp_remote_get(
			$url,
			array(
				'timeout' => 30,
				'redirection' => 2,
				'headers' => array( 'Authorization' => 'Bearer ' . $token ),
				'limit_response_size' => (int) $max_bytes + 1,
			)
		);
		if ( is_wp_error( $response ) ) return $response;
		$status = (int) wp_remote_retrieve_response_code( $response );
		if ( $status < 200 || $status >= 300 ) return new WP_Error( $error_code, 'Google Drive content download returned a non-success status.', array( 'status' => $status ) );
		$body = (string) wp_remote_retrieve_body( $response );
		if ( strlen( $body ) > (int) $max_bytes ) return new WP_Error( 'mad4b_google_drive_binary_too_large', 'Drive binary exceeds the certified normalization limit.', array( 'max_bytes' => (int) $max_bytes, 'bytes_observed' => strlen( $body ) ) );
		return $body;
	}

	private static function download_blob_bytes( $file_id ) {
		$file_id = self::bounded_drive_id( $file_id );
		if ( '' === $file_id ) return new WP_Error( 'mad4b_google_drive_file_id_invalid', 'Google Drive file ID is invalid.' );
		return self::fetch_bounded_bytes( self::DRIVE_API . '/files/' . rawurlencode( $file_id ) . '?alt=media&supportsAllDrives=true', self::MAX_BINARY_BYTES, 'mad4b_google_drive_binary_fetch_failed' );
	}

	private static function export_workspace_bytes( $file_id, $mime_type ) {
		$file_id = self::bounded_drive_id( $file_id );
		if ( '' === $file_id ) return new WP_Error( 'mad4b_google_drive_file_id_invalid', 'Google Drive file ID is invalid.' );
		$url = self::DRIVE_API . '/files/' . rawurlencode( $file_id ) . '/export?mimeType=' . rawurlencode( (string) $mime_type );
		return self::fetch_bounded_bytes( $url, self::MAX_BINARY_BYTES, 'mad4b_google_drive_export_failed' );
	}

	private static function download_workspace_lro_bytes( $file_id, $mime_type = '' ) {
		$file_id = self::bounded_drive_id( $file_id );
		if ( '' === $file_id ) return new WP_Error( 'mad4b_google_drive_file_id_invalid', 'Google Drive file ID is invalid.' );
		$token = self::access_token();
		if ( is_wp_error( $token ) ) return $token;
		$url = self::DRIVE_API . '/files/' . rawurlencode( $file_id ) . '/download';
		if ( '' !== (string) $mime_type ) $url .= '?mimeType=' . rawurlencode( (string) $mime_type );
		$response = wp_remote_request(
			$url,
			array(
				'method' => 'POST',
				'timeout' => 20,
				'redirection' => 0,
				'headers' => array( 'Authorization' => 'Bearer ' . $token, 'Accept' => 'application/json', 'Content-Length' => '0' ),
				'body' => '',
			)
		);
		if ( is_wp_error( $response ) ) return $response;
		$status = (int) wp_remote_retrieve_response_code( $response );
		$operation = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( $status < 200 || $status >= 300 || ! is_array( $operation ) ) return new WP_Error( 'mad4b_google_drive_download_lro_failed', 'Drive long-running download could not be started.', array( 'status' => $status ) );
		for ( $attempt = 0; $attempt < 4 && empty( $operation['done'] ); ++$attempt ) {
			$name = isset( $operation['name'] ) ? trim( (string) $operation['name'] ) : '';
			if ( '' === $name || strlen( $name ) > 512 || ! preg_match( '/^[A-Za-z0-9_\.\-\/]+$/', $name ) ) break;
			if ( function_exists( 'usleep' ) ) usleep( 200000 * ( $attempt + 1 ) );
			$poll = wp_remote_get(
				'https://www.googleapis.com/drive/v3/operations/' . implode( '/', array_map( 'rawurlencode', explode( '/', $name ) ) ),
				array( 'timeout' => 15, 'redirection' => 0, 'headers' => array( 'Authorization' => 'Bearer ' . $token, 'Accept' => 'application/json' ) )
			);
			if ( is_wp_error( $poll ) ) return $poll;
			$poll_status = (int) wp_remote_retrieve_response_code( $poll );
			$operation = json_decode( (string) wp_remote_retrieve_body( $poll ), true );
			if ( $poll_status < 200 || $poll_status >= 300 || ! is_array( $operation ) ) return new WP_Error( 'mad4b_google_drive_download_lro_poll_failed', 'Drive long-running download polling failed.', array( 'status' => $poll_status ) );
		}
		if ( empty( $operation['done'] ) ) return new WP_Error( 'mad4b_google_drive_download_lro_pending', 'Drive long-running download is still processing; retry the source scan after the operation is ready.' );
		if ( ! empty( $operation['error'] ) ) return new WP_Error( 'mad4b_google_drive_download_lro_provider_error', 'Drive long-running download completed with a provider error.' );
		$download_uri = isset( $operation['response']['downloadUri'] ) ? esc_url_raw( (string) $operation['response']['downloadUri'] ) : '';
		if ( '' === $download_uri || 0 !== strpos( $download_uri, 'https://' ) ) return new WP_Error( 'mad4b_google_drive_download_uri_missing', 'Drive long-running download did not return a valid HTTPS download URI.' );
		return self::fetch_bounded_bytes( $download_uri, self::MAX_BINARY_BYTES, 'mad4b_google_drive_download_uri_fetch_failed' );
	}

	private static function zip_entries( $binary ) {
		if ( ! class_exists( 'ZipArchive' ) ) return new WP_Error( 'mad4b_context_ziparchive_unavailable', 'ZipArchive is required to normalize this document type.' );
		$tmp = tempnam( sys_get_temp_dir(), 'mad4b-context-' );
		if ( false === $tmp ) return new WP_Error( 'mad4b_context_tempfile_unavailable', 'Unable to allocate a bounded normalization tempfile.' );
		$written = @file_put_contents( $tmp, (string) $binary );
		if ( false === $written || $written !== strlen( (string) $binary ) ) { @unlink( $tmp ); return new WP_Error( 'mad4b_context_tempfile_write_failed', 'Unable to write document normalization tempfile.' ); }
		$zip = new ZipArchive();
		$opened = $zip->open( $tmp );
		if ( true !== $opened ) { @unlink( $tmp ); return new WP_Error( 'mad4b_context_archive_invalid', 'Document archive could not be opened.' ); }
		$entries = array();
		$total = 0;
		for ( $i = 0; $i < $zip->numFiles; ++$i ) {
			$stat = $zip->statIndex( $i );
			if ( ! is_array( $stat ) || empty( $stat['name'] ) ) continue;
			$name = (string) $stat['name'];
			if ( substr( $name, -1 ) === '/' ) continue;
			$size = isset( $stat['size'] ) ? (int) $stat['size'] : 0;
			$total += max( 0, $size );
			if ( $total > self::MAX_BINARY_BYTES * 4 ) { $zip->close(); @unlink( $tmp ); return new WP_Error( 'mad4b_context_archive_expanded_too_large', 'Expanded document archive exceeds the certified normalization limit.' ); }
			$data = $zip->getFromIndex( $i );
			if ( false !== $data ) $entries[ $name ] = (string) $data;
		}
		$zip->close();
		@unlink( $tmp );
		return $entries;
	}

	private static function xml_visible_text( $xml ) {
		$xml = (string) $xml;
		$xml = preg_replace( '/<\/(?:[A-Za-z0-9_\-]+:)?(?:p|row|tr|text:p|text:h)>/i', "\n", $xml );
		$xml = preg_replace( '/<(?:[A-Za-z0-9_\-]+:)?(?:br|tab)[^>]*\/?\s*>/i', "\t", $xml );
		$text = html_entity_decode( strip_tags( $xml ), ENT_QUOTES | ENT_XML1, 'UTF-8' );
		$text = preg_replace( "/[ \t]+/u", ' ', $text );
		$text = preg_replace( "/\r\n?|\n{3,}/u", "\n", $text );
		return trim( (string) $text );
	}

	private static function html_visible_text( $html ) {
		$html = preg_replace( '#<(script|style|noscript)[^>]*>.*?</\1>#is', ' ', (string) $html );
		$html = preg_replace( '#</(?:p|div|li|tr|h[1-6]|section|article)>#i', "\n", $html );
		$html = preg_replace( '#<(?:br|hr)[^>]*>#i', "\n", $html );
		$text = html_entity_decode( strip_tags( $html ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$text = preg_replace( "/[ \t]+/u", ' ', $text );
		$text = preg_replace( "/\r\n?|\n{3,}/u", "\n", $text );
		return trim( (string) $text );
	}

	private static function normalize_docx( $binary ) {
		$entries = self::zip_entries( $binary );
		if ( is_wp_error( $entries ) ) return $entries;
		$parts = array();
		foreach ( $entries as $name => $xml ) {
			if ( preg_match( '#^word/(document|header[0-9]*|footer[0-9]*|footnotes|endnotes|comments)\.xml$#i', $name ) ) {
				$text = self::xml_visible_text( $xml );
				if ( '' !== $text ) $parts[] = $text;
			}
		}
		return self::normalization_record( implode( "\n\n", $parts ), true, 'ready', 'docx_local' );
	}

	private static function normalize_xlsx( $binary ) {
		$entries = self::zip_entries( $binary );
		if ( is_wp_error( $entries ) ) return $entries;
		$shared = array();
		if ( isset( $entries['xl/sharedStrings.xml'] ) && preg_match_all( '#<si\b[^>]*>(.*?)</si>#is', $entries['xl/sharedStrings.xml'], $matches ) ) {
			foreach ( $matches[1] as $si ) $shared[] = self::xml_visible_text( $si );
		}
		$sheets = array();
		foreach ( $entries as $name => $xml ) if ( preg_match( '#^xl/worksheets/sheet([0-9]+)\.xml$#i', $name, $m ) ) $sheets[ (int) $m[1] ] = $xml;
		ksort( $sheets, SORT_NUMERIC );
		$out = array();
		foreach ( $sheets as $number => $xml ) {
			$lines = array();
			if ( preg_match_all( '#<row\b[^>]*>(.*?)</row>#is', $xml, $rows ) ) {
				foreach ( $rows[1] as $row ) {
					$cells = array();
					if ( preg_match_all( '#<c\b([^>]*)>(.*?)</c>#is', $row, $cell_matches, PREG_SET_ORDER ) ) {
						foreach ( $cell_matches as $cell ) {
							$attrs = $cell[1];
							$body = $cell[2];
							$value = '';
							if ( preg_match( '#<is\b[^>]*>(.*?)</is>#is', $body, $inline ) ) $value = self::xml_visible_text( $inline[1] );
							elseif ( preg_match( '#<v\b[^>]*>(.*?)</v>#is', $body, $v ) ) {
								$value = html_entity_decode( strip_tags( $v[1] ), ENT_QUOTES | ENT_XML1, 'UTF-8' );
								if ( preg_match( '/\bt=["\']s["\']/i', $attrs ) ) { $idx = (int) $value; $value = isset( $shared[ $idx ] ) ? $shared[ $idx ] : $value; }
							}
							$cells[] = trim( (string) $value );
						}
					}
					if ( $cells ) $lines[] = implode( "\t", $cells );
				}
			}
			$out[] = '[Sheet ' . $number . "]\n" . implode( "\n", $lines );
		}
		return self::normalization_record( implode( "\n\n", $out ), true, 'ready', 'xlsx_local' );
	}

	private static function normalize_pptx( $binary ) {
		$entries = self::zip_entries( $binary );
		if ( is_wp_error( $entries ) ) return $entries;
		$slides = array();
		$notes = array();
		foreach ( $entries as $name => $xml ) {
			if ( preg_match( '#^ppt/slides/slide([0-9]+)\.xml$#i', $name, $m ) ) $slides[ (int) $m[1] ] = self::xml_visible_text( $xml );
			elseif ( preg_match( '#^ppt/notesSlides/notesSlide([0-9]+)\.xml$#i', $name, $m ) ) $notes[ (int) $m[1] ] = self::xml_visible_text( $xml );
		}
		ksort( $slides, SORT_NUMERIC );
		$out = array();
		foreach ( $slides as $number => $text ) {
			$chunk = '[Slide ' . $number . "]\n" . $text;
			if ( isset( $notes[ $number ] ) && '' !== $notes[ $number ] ) $chunk .= "\n[Notes]\n" . $notes[ $number ];
			$out[] = $chunk;
		}
		return self::normalization_record( implode( "\n\n", $out ), true, 'ready', 'pptx_local' );
	}

	private static function normalize_odf( $binary, $kind ) {
		$entries = self::zip_entries( $binary );
		if ( is_wp_error( $entries ) ) return $entries;
		if ( empty( $entries['content.xml'] ) ) return new WP_Error( 'mad4b_context_odf_content_missing', 'OpenDocument archive has no content.xml.' );
		return self::normalization_record( self::xml_visible_text( $entries['content.xml'] ), true, 'ready', sanitize_key( (string) $kind ) . '_local' );
	}

	private static function normalize_epub( $binary ) {
		$entries = self::zip_entries( $binary );
		if ( is_wp_error( $entries ) ) return $entries;
		ksort( $entries, SORT_STRING );
		$parts = array();
		foreach ( $entries as $name => $content ) if ( preg_match( '/\.(xhtml|html|htm)$/i', $name ) ) {
			$text = self::html_visible_text( $content );
			if ( '' !== $text ) $parts[] = $text;
		}
		return self::normalization_record( implode( "\n\n", $parts ), true, 'ready', 'epub_local' );
	}

	private static function normalize_generic_archive( $binary, $reason = 'archive_local' ) {
		$entries = self::zip_entries( $binary );
		if ( is_wp_error( $entries ) ) return $entries;
		ksort( $entries, SORT_STRING );
		$parts = array();
		foreach ( $entries as $name => $content ) {
			if ( ! preg_match( '/\.(txt|md|markdown|csv|tsv|json|xml|xhtml|html|htm|yaml|yml|rtf)$/i', $name ) ) continue;
			if ( preg_match( '/\.(xhtml|html|htm)$/i', $name ) ) $text = self::html_visible_text( $content );
			elseif ( preg_match( '/\.rtf$/i', $name ) ) {
				$record = self::normalize_rtf( $content );
				$text = is_wp_error( $record ) ? '' : ( isset( $record['content'] ) ? (string) $record['content'] : '' );
			} else $text = self::xml_visible_text( $content );
			if ( '' !== trim( $text ) ) $parts[] = '[' . $name . "]\n" . trim( $text );
		}
		if ( empty( $parts ) ) return self::normalization_record( '', false, 'extractor_required', 'archive_no_text_entries', strlen( (string) $binary ) );
		return self::normalization_record( implode( "\n\n", $parts ), true, 'ready', $reason );
	}

	private static function normalize_rtf( $rtf ) {
		$text = (string) $rtf;
		$text = preg_replace_callback( "/\\\\'([0-9a-fA-F]{2})/", static function ( $m ) { return chr( hexdec( $m[1] ) ); }, $text );
		$text = preg_replace_callback( '/\\\\u(-?[0-9]+)\??/', static function ( $m ) {
			$n = (int) $m[1]; if ( $n < 0 ) $n += 65536;
			if ( function_exists( 'mb_convert_encoding' ) ) return mb_convert_encoding( '&#' . $n . ';', 'UTF-8', 'HTML-ENTITIES' );
			return $n < 128 ? chr( $n ) : ' ';
		}, $text );
		$text = preg_replace( '/\\\\(par|line|tab)\b ?/i', "\n", $text );
		$text = preg_replace( '/\\\\[a-zA-Z]+-?[0-9]* ?/', '', $text );
		$text = str_replace( array( '\\{', '\\}', '\\\\' ), array( '{', '}', '\\' ), $text );
		$text = str_replace( array( '{', '}' ), '', $text );
		$text = preg_replace( "/[ \t]+/u", ' ', $text );
		$text = preg_replace( "/\n{3,}/u", "\n\n", $text );
		return self::normalization_record( trim( $text ), true, 'ready', 'rtf_local' );
	}

	private static function pdf_decode_literal( $value ) {
		$value = preg_replace_callback( '/\\\\([0-7]{1,3})/', static function ( $m ) { return chr( octdec( $m[1] ) ); }, (string) $value );
		return strtr( $value, array( '\\n' => "\n", '\\r' => "\r", '\\t' => "\t", '\\b' => "\b", '\\f' => "\f", '\\(' => '(', '\\)' => ')', '\\\\' => '\\' ) );
	}

	private static function pdf_stream_text( $stream ) {
		$out = array();
		if ( preg_match_all( '/BT(.*?)ET/s', (string) $stream, $blocks ) ) {
			foreach ( $blocks[1] as $block ) {
				if ( preg_match_all( '/\((?:\\\\.|[^\\)])*\)/s', $block, $strings ) ) {
					foreach ( $strings[0] as $literal ) {
						$value = self::pdf_decode_literal( substr( $literal, 1, -1 ) );
						if ( '' !== trim( $value ) ) $out[] = $value;
					}
				}
				if ( preg_match_all( '/<([0-9A-Fa-f]{4,})>\s*(?:Tj|TJ|\'|\")/', $block, $hexes ) ) {
					foreach ( $hexes[1] as $hex ) {
						if ( strlen( $hex ) % 2 ) $hex .= '0';
						$bytes = @hex2bin( $hex );
						if ( false === $bytes ) continue;
						if ( 0 === strncmp( $bytes, "\xFE\xFF", 2 ) && function_exists( 'mb_convert_encoding' ) ) $bytes = mb_convert_encoding( substr( $bytes, 2 ), 'UTF-8', 'UTF-16BE' );
						if ( '' !== trim( $bytes ) ) $out[] = $bytes;
					}
				}
			}
		}
		return trim( implode( ' ', $out ) );
	}

	private static function normalize_pdf( $binary ) {
		$binary = (string) $binary;
		if ( 0 !== strpos( $binary, '%PDF-' ) ) return new WP_Error( 'mad4b_context_pdf_invalid', 'PDF signature is invalid.' );
		$texts = array();
		$unsupported_filter = false;
		if ( preg_match_all( '/stream\r?\n(.*?)\r?\nendstream/s', $binary, $streams, PREG_OFFSET_CAPTURE ) ) {
			foreach ( $streams[1] as $capture ) {
				$data = (string) $capture[0];
				$offset = (int) $capture[1];
				$prefix = substr( $binary, max( 0, $offset - 512 ), min( 512, $offset ) );
				if ( preg_match( '#/(DCTDecode|JPXDecode|LZWDecode|ASCII85Decode|CCITTFaxDecode)#', $prefix ) ) $unsupported_filter = true;
				if ( false !== strpos( $prefix, '/FlateDecode' ) ) {
					$decoded = @gzuncompress( $data );
					if ( false === $decoded ) $decoded = @gzinflate( $data );
					if ( false === $decoded ) { $unsupported_filter = true; continue; }
					$data = $decoded;
				}
				$text = self::pdf_stream_text( $data );
				if ( '' !== $text ) $texts[] = $text;
			}
		}
		$text = trim( implode( "\n", $texts ) );
		if ( '' === $text ) return self::normalization_record( '', false, 'extractor_required', 'pdf_ocr_required', strlen( $binary ) );
		return self::normalization_record( $text, ! $unsupported_filter, $unsupported_filter ? 'incomplete' : 'ready', $unsupported_filter ? 'pdf_mixed_filters_not_fully_decoded' : 'pdf_local_text', strlen( $binary ) );
	}

	private static function gemini_extractor_configured() {
		return defined( 'MAD4B_CONTEXT_GEMINI_ENABLED' ) && true === (bool) constant( 'MAD4B_CONTEXT_GEMINI_ENABLED' )
			&& defined( 'MAD4B_CONTEXT_GEMINI_API_KEY' )
			&& '' !== trim( (string) constant( 'MAD4B_CONTEXT_GEMINI_API_KEY' ) );
	}

	private static function gemini_model() {
		$model = defined( 'MAD4B_CONTEXT_GEMINI_MODEL' ) ? trim( (string) constant( 'MAD4B_CONTEXT_GEMINI_MODEL' ) ) : 'gemini-3.8-flash';
		return preg_match( '/^[A-Za-z0-9._\-]+$/', $model ) ? $model : 'gemini-3.8-flash';
	}

	private static function generic_extractor_configured() {
		return defined( 'MAD4B_CONTEXT_EXTRACTOR_URL' ) && defined( 'MAD4B_CONTEXT_EXTRACTOR_TOKEN' )
			&& '' !== trim( (string) constant( 'MAD4B_CONTEXT_EXTRACTOR_URL' ) )
			&& '' !== trim( (string) constant( 'MAD4B_CONTEXT_EXTRACTOR_TOKEN' ) );
	}

	private static function external_extractor_configured() {
		return self::gemini_extractor_configured() || self::generic_extractor_configured();
	}

	private static function normalize_binary_content( $mime, $binary, $name = '' ) {
		$mime = strtolower( trim( (string) $mime ) );
		if ( 'application/vnd.openxmlformats-officedocument.wordprocessingml.document' === $mime ) return self::normalize_docx( $binary );
		if ( 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' === $mime ) return self::normalize_xlsx( $binary );
		if ( 'application/vnd.openxmlformats-officedocument.presentationml.presentation' === $mime ) return self::normalize_pptx( $binary );
		if ( 'application/vnd.oasis.opendocument.text' === $mime ) return self::normalize_odf( $binary, 'odt' );
		if ( 'application/vnd.oasis.opendocument.spreadsheet' === $mime ) return self::normalize_odf( $binary, 'ods' );
		if ( 'application/vnd.oasis.opendocument.presentation' === $mime ) return self::normalize_odf( $binary, 'odp' );
		if ( 'application/epub+zip' === $mime ) return self::normalize_epub( $binary );
		if ( in_array( $mime, array( 'application/zip', 'application/x-zip-compressed' ), true ) ) return self::normalize_generic_archive( $binary );
		if ( in_array( $mime, array( 'application/rtf', 'text/rtf' ), true ) ) return self::normalize_rtf( $binary );
		if ( 'application/pdf' === $mime ) return self::normalize_pdf( $binary );
		if ( 'text/html' === $mime || 'application/xhtml+xml' === $mime || 'image/svg+xml' === $mime ) return self::normalization_record( self::html_visible_text( $binary ), true, 'ready', 'markup_local' );
		return self::normalization_record( '', false, 'extractor_required', 'external_extractor_required', strlen( (string) $binary ) );
	}

	private static function normalize_native_download_bytes( $binary ) {
		$binary = (string) $binary;
		if ( 0 === strpos( $binary, '%PDF-' ) ) return self::normalize_pdf( $binary );
		if ( 0 === strpos( $binary, "PK\x03\x04" ) ) return self::normalize_generic_archive( $binary, 'google_native_archive_download' );
		$sample = substr( $binary, 0, min( strlen( $binary ), 65536 ) );
		$has_nul = false !== strpos( $sample, "\x00" );
		$utf8 = wp_check_invalid_utf8( $sample, true );
		if ( ! $has_nul && '' !== trim( (string) $utf8 ) ) {
			$trimmed = ltrim( $binary );
			if ( 0 === strpos( $trimmed, '<' ) ) return self::normalization_record( self::html_visible_text( $binary ), true, 'ready', 'google_native_markup_download' );
			return self::normalization_record( $binary, true, 'ready', 'google_native_text_download' );
		}
		return self::normalization_record( '', false, 'extractor_required', 'google_native_binary_extractor_required', strlen( $binary ) );
	}

	private static function native_extractor_mime( $mime ) {
		$mime = strtolower( trim( (string) $mime ) );
		$map = array(
			'application/vnd.google-apps.audio' => 'audio/mpeg',
			'application/vnd.google-apps.video' => 'video/mp4',
			'application/vnd.google-apps.photo' => 'image/jpeg',
			'application/vnd.google-apps.pic' => 'image/jpeg',
		);
		return isset( $map[ $mime ] ) ? $map[ $mime ] : $mime;
	}


	private static function fetch_text_content_record( array $file, $shortcut_depth = 0 ) {
		$file_id = self::bounded_drive_id( isset( $file['id'] ) ? $file['id'] : '' );
		if ( '' === $file_id ) return new WP_Error( 'mad4b_google_drive_file_id_invalid', 'Google Drive file ID is invalid.' );
		$mime = isset( $file['mimeType'] ) ? strtolower( (string) $file['mimeType'] ) : '';
		$name = isset( $file['name'] ) ? (string) $file['name'] : '';

		if ( 'application/vnd.google-apps.shortcut' === $mime ) {
			if ( $shortcut_depth >= 4 ) return new WP_Error( 'mad4b_google_drive_shortcut_depth_exceeded', 'Drive shortcut chain exceeds the certified normalization depth.' );
			$target_id = isset( $file['shortcutDetails']['targetId'] ) ? self::bounded_drive_id( $file['shortcutDetails']['targetId'] ) : '';
			if ( '' === $target_id ) return self::normalization_record( '', false, 'incomplete', 'shortcut_target_missing' );
			$target = self::get_file_metadata( $target_id );
			if ( is_wp_error( $target ) ) return $target;
			return self::fetch_text_content_record( $target, $shortcut_depth + 1 );
		}

		if ( 'application/vnd.google-apps.document' === $mime ) {
			$bytes = self::export_workspace_bytes( $file_id, 'text/plain' );
			return is_wp_error( $bytes ) ? $bytes : self::normalization_record( $bytes, true, 'ready', 'google_doc_export' );
		}
		if ( 'application/vnd.google-apps.spreadsheet' === $mime ) {
			$bytes = self::export_workspace_bytes( $file_id, 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' );
			return is_wp_error( $bytes ) ? $bytes : self::normalize_xlsx( $bytes );
		}
		if ( 'application/vnd.google-apps.presentation' === $mime ) {
			$bytes = self::export_workspace_bytes( $file_id, 'application/vnd.openxmlformats-officedocument.presentationml.presentation' );
			return is_wp_error( $bytes ) ? $bytes : self::normalize_pptx( $bytes );
		}
		if ( 'application/vnd.google-apps.drawing' === $mime ) {
			$bytes = self::export_workspace_bytes( $file_id, 'image/svg+xml' );
			return is_wp_error( $bytes ) ? $bytes : self::normalization_record( self::html_visible_text( $bytes ), true, 'ready', 'google_drawing_svg_export' );
		}
		if ( 'application/vnd.google-apps.script' === $mime ) {
			$bytes = self::export_workspace_bytes( $file_id, 'application/vnd.google-apps.script+json' );
			return is_wp_error( $bytes ) ? $bytes : self::normalization_record( $bytes, true, 'ready', 'google_apps_script_export' );
		}

		if ( 'application/vnd.google-apps.form' === $mime ) {
			$bytes = self::download_workspace_lro_bytes( $file_id, 'application/zip' );
			return is_wp_error( $bytes ) ? $bytes : self::normalize_generic_archive( $bytes, 'google_form_download' );
		}
		if ( 'application/vnd.google-apps.site' === $mime ) {
			$bytes = self::download_workspace_lro_bytes( $file_id, 'text/raw' );
			return is_wp_error( $bytes ) ? $bytes : self::normalization_record( $bytes, true, 'ready', 'google_site_download' );
		}
		if ( in_array( $mime, array( 'application/vnd.google-apps.jam', 'application/vnd.google-apps.jamboard' ), true ) ) {
			$bytes = self::download_workspace_lro_bytes( $file_id, 'application/pdf' );
			if ( is_wp_error( $bytes ) ) return $bytes;
			$local = self::normalize_pdf( $bytes );
			if ( ! is_wp_error( $local ) && ! empty( $local['complete'] ) ) return $local;
			$fallback = is_wp_error( $local ) ? self::normalization_record( '', false, 'extractor_required', 'jamboard_pdf_extractor_required', strlen( $bytes ) ) : $local;
			return self::external_extractor_record( array_merge( $file, array( 'mimeType' => 'application/pdf' ) ), $bytes, $fallback );
		}
		if ( 'application/vnd.google-apps.vid' === $mime ) {
			$bytes = self::download_workspace_lro_bytes( $file_id, 'video/mp4' );
			if ( is_wp_error( $bytes ) ) return $bytes;
			return self::external_extractor_record( array_merge( $file, array( 'mimeType' => 'video/mp4' ) ), $bytes, self::normalization_record( '', false, 'extractor_required', 'video_transcription_required', strlen( $bytes ) ) );
		}

		if ( in_array( $mime, array( 'application/vnd.google-apps.folder', 'application/vnd.google-apps.drive-sdk' ), true ) ) {
			return self::normalization_record( '', false, 'metadata_only', 'google_drive_metadata_only_type' );
		}

		$is_google_native = 0 === strpos( $mime, 'application/vnd.google-apps.' ) || 0 === strpos( $mime, 'application/vnd.google-gemini.' );
		if ( $is_google_native ) {
			$bytes = self::download_workspace_lro_bytes( $file_id );
			if ( is_wp_error( $bytes ) ) return $bytes;
			$local = self::normalize_native_download_bytes( $bytes );
			if ( is_wp_error( $local ) ) return $local;
			if ( ! empty( $local['complete'] ) ) return $local;
			$extractor_file = array_merge( $file, array( 'mimeType' => self::native_extractor_mime( $mime ) ) );
			return self::external_extractor_record( $extractor_file, $bytes, $local );
		}

		if ( 0 === strpos( $mime, 'text/' ) || in_array( $mime, array( 'application/json', 'application/xml', 'application/csv', 'application/javascript', 'application/x-javascript' ), true ) ) {
			$url = self::DRIVE_API . '/files/' . rawurlencode( $file_id ) . '?alt=media&supportsAllDrives=true';
			$bytes = self::fetch_bounded_bytes( $url, self::MAX_TEXT_BYTES, 'mad4b_google_drive_content_fetch_failed' );
			if ( is_wp_error( $bytes ) ) return $bytes;
			if ( 'text/html' === $mime || 'image/svg+xml' === $mime ) return self::normalization_record( self::html_visible_text( $bytes ), true, 'ready', 'markup_local' );
			return self::normalization_record( $bytes, true, 'ready', 'text_blob' );
		}

		$binary = self::download_blob_bytes( $file_id );
		if ( is_wp_error( $binary ) ) return $binary;
		$local = self::normalize_binary_content( $mime, $binary, $name );
		if ( is_wp_error( $local ) ) return $local;
		if ( ! empty( $local['complete'] ) ) return $local;
		$normalization_status = isset( $local['normalization_status'] ) ? (string) $local['normalization_status'] : '';
		$multimodal = 'application/pdf' === $mime
			|| 0 === strpos( $mime, 'image/' )
			|| 0 === strpos( $mime, 'audio/' )
			|| 0 === strpos( $mime, 'video/' );
		if ( $multimodal || 'extractor_required' === $normalization_status ) return self::external_extractor_record( $file, $binary, $local );
		return $local;
	}

	private static function fetch_text_content( array $file ) {
		$record = self::fetch_text_content_record( $file );
		if ( is_wp_error( $record ) ) return $record;
		if ( empty( $record['complete'] ) ) {
			if ( 'unsupported' === ( isset( $record['normalization_status'] ) ? (string) $record['normalization_status'] : '' ) ) return '';
			return new WP_Error(
				'mad4b_context_asset_content_incomplete',
				'Google Drive text normalization exceeded the certified bounded read limit; incomplete content cannot become governed runtime context.',
				array(
					'bytes_observed' => isset( $record['bytes'] ) ? (int) $record['bytes'] : 0,
					'max_bytes' => self::MAX_TEXT_BYTES,
					'reason' => isset( $record['normalization_reason'] ) ? (string) $record['normalization_reason'] : 'incomplete',
				)
			);
		}
		return isset( $record['content'] ) ? (string) $record['content'] : '';
	}

	private static function gemini_extractor_record( array $file, $binary, array $fallback ) {
		if ( ! self::gemini_extractor_configured() ) return $fallback;
		$mime = isset( $file['mimeType'] ) ? strtolower( (string) $file['mimeType'] ) : 'application/octet-stream';
		$prompt = 'Extract complete usable context for a governed brand knowledge system. ';
		if ( 'application/pdf' === $mime ) $prompt .= 'OCR all readable pages when needed and preserve page order, headings, tables, labels, and meaningful visual text. ';
		elseif ( 0 === strpos( $mime, 'image/' ) ) $prompt .= 'OCR all visible text and describe meaningful charts, diagrams, logos, labels, and layout relationships factually. ';
		elseif ( 0 === strpos( $mime, 'audio/' ) ) $prompt .= 'Transcribe all speech, speaker changes, and timestamps when available, including meaningful non-speech cues. ';
		elseif ( 0 === strpos( $mime, 'video/' ) ) $prompt .= 'Transcribe speech with timestamps and extract visible text plus concise factual descriptions of meaningful visual events. ';
		else $prompt .= 'Extract all readable text and structured factual information without summarizing away source details. ';
		$prompt .= 'Return only canonical extracted text. Do not invent facts or add recommendations.';
		$body = array(
			'contents' => array(
				array(
					'parts' => array(
						array( 'text' => $prompt ),
						array( 'inline_data' => array( 'mime_type' => $mime, 'data' => base64_encode( (string) $binary ) ) ),
					),
				),
			),
			'generationConfig' => array( 'temperature' => 0, 'maxOutputTokens' => 16384 ),
		);
		$response = wp_remote_post(
			'https://generativelanguage.googleapis.com/v1beta/models/' . rawurlencode( self::gemini_model() ) . ':generateContent',
			array(
				'timeout' => 90,
				'redirection' => 0,
				'headers' => array(
					'x-goog-api-key' => trim( (string) constant( 'MAD4B_CONTEXT_GEMINI_API_KEY' ) ),
					'Content-Type' => 'application/json',
					'Accept' => 'application/json',
				),
				'body' => wp_json_encode( $body ),
			)
		);
		if ( is_wp_error( $response ) ) return self::normalization_record( '', false, 'error', 'gemini_extractor_transport_failed', strlen( (string) $binary ) );
		$status = (int) wp_remote_retrieve_response_code( $response );
		$data = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( $status < 200 || $status >= 300 || ! is_array( $data ) ) return self::normalization_record( '', false, 'error', 'gemini_extractor_response_invalid', strlen( (string) $binary ) );
		$parts = isset( $data['candidates'][0]['content']['parts'] ) && is_array( $data['candidates'][0]['content']['parts'] ) ? $data['candidates'][0]['content']['parts'] : array();
		$texts = array();
		foreach ( $parts as $part ) if ( is_array( $part ) && isset( $part['text'] ) && '' !== trim( (string) $part['text'] ) ) $texts[] = (string) $part['text'];
		$text = trim( implode( "\n", $texts ) );
		$finish = isset( $data['candidates'][0]['finishReason'] ) ? sanitize_key( (string) $data['candidates'][0]['finishReason'] ) : '';
		$complete = '' === $finish || 'stop' === $finish;
		if ( '' === $text ) return self::normalization_record( '', false, 'incomplete', 'gemini_extractor_empty', strlen( (string) $binary ) );
		return self::normalization_record( $text, $complete, $complete ? 'ready' : 'incomplete', $complete ? 'gemini_multimodal' : 'gemini_output_incomplete', strlen( (string) $binary ) );
	}


	private static function external_extractor_record( array $file, $binary, array $fallback ) {
		if ( self::gemini_extractor_configured() ) return self::gemini_extractor_record( $file, $binary, $fallback );
		if ( ! self::generic_extractor_configured() ) return $fallback;
		$url = esc_url_raw( (string) constant( 'MAD4B_CONTEXT_EXTRACTOR_URL' ) );
		$token = trim( (string) constant( 'MAD4B_CONTEXT_EXTRACTOR_TOKEN' ) );
		if ( '' === $url || '' === $token || 0 !== strpos( $url, 'https://' ) ) return self::normalization_record( '', false, 'error', 'external_extractor_configuration_invalid', strlen( (string) $binary ) );
		$mime = isset( $file['mimeType'] ) ? strtolower( (string) $file['mimeType'] ) : 'application/octet-stream';
		$payload = array(
			'contract' => 'mad4b.context-extraction-request.v1',
			'file_id_sha256' => hash( 'sha256', isset( $file['id'] ) ? (string) $file['id'] : '' ),
			'name' => isset( $file['name'] ) ? sanitize_text_field( (string) $file['name'] ) : '',
			'mime_type' => $mime,
			'content_sha256' => hash( 'sha256', (string) $binary ),
			'content_base64' => base64_encode( (string) $binary ),
			'max_text_bytes' => self::MAX_TEXT_BYTES,
		);
		$response = wp_remote_post(
			$url,
			array(
				'timeout' => 60,
				'redirection' => 0,
				'headers' => array(
					'Authorization' => 'Bearer ' . $token,
					'Content-Type' => 'application/json',
					'Accept' => 'application/json',
				),
				'body' => wp_json_encode( $payload ),
			)
		);
		if ( is_wp_error( $response ) ) return self::normalization_record( '', false, 'error', 'external_extractor_transport_failed', strlen( (string) $binary ) );
		$status = (int) wp_remote_retrieve_response_code( $response );
		$data = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( $status < 200 || $status >= 300 || ! is_array( $data ) || 'mad4b.context-extraction-result.v1' !== ( isset( $data['contract'] ) ? (string) $data['contract'] : '' ) ) return self::normalization_record( '', false, 'error', 'external_extractor_response_invalid', strlen( (string) $binary ) );
		$text = isset( $data['text'] ) ? (string) $data['text'] : '';
		$complete = ! empty( $data['complete'] );
		if ( '' === trim( $text ) ) return self::normalization_record( '', false, 'incomplete', 'external_extractor_empty', strlen( (string) $binary ) );
		return self::normalization_record( $text, $complete, $complete ? 'ready' : 'incomplete', isset( $data['reason'] ) ? sanitize_key( (string) $data['reason'] ) : 'external_extractor', strlen( (string) $binary ) );
	}


	private static function api_get( $url ) {
		$token = self::access_token();
		if ( is_wp_error( $token ) ) return $token;
		$response = wp_remote_get(
			esc_url_raw( $url ),
			array(
				'timeout' => 20,
				'redirection' => 2,
				'headers' => array(
					'Authorization' => 'Bearer ' . $token,
					'Accept' => 'application/json',
				),
			)
		);
		return self::decode_json_response( $response, 'mad4b_google_drive_api_failed' );
	}

	private static function access_token() {
		$record = self::token_record();
		if ( ! is_array( $record ) || empty( $record['refresh_token'] ) ) return new WP_Error( 'mad4b_google_drive_not_connected', 'Google Drive is not connected.' );
		if ( ! empty( $record['revocation_pending'] ) ) return new WP_Error( 'mad4b_google_drive_revocation_pending', 'Google Drive access is disabled while remote revocation is pending.' );
		if ( ! empty( $record['access_token'] ) && ! empty( $record['expires_at'] ) && (int) $record['expires_at'] > time() + 90 ) return (string) $record['access_token'];
		$record_mode = isset( $record['auth_mode'] ) ? sanitize_key( (string) $record['auth_mode'] ) : self::AUTH_MODE_CUSTOM;
		if ( self::AUTH_MODE_MANAGED === $record_mode ) return self::refresh_managed_access_token( $record );
		$credentials = self::credentials( $record_mode );
		if ( is_wp_error( $credentials ) ) return $credentials;
		$response = wp_remote_post(
			self::TOKEN_ENDPOINT,
			array(
				'timeout' => 20,
				'redirection' => 0,
				'body' => array(
					'client_id' => $credentials['client_id'],
					'client_secret' => $credentials['client_secret'],
					'refresh_token' => (string) $record['refresh_token'],
					'grant_type' => 'refresh_token',
				),
			)
		);
		$tokens = self::decode_json_response( $response, 'mad4b_google_drive_token_refresh_failed' );
		if ( is_wp_error( $tokens ) ) return $tokens;
		if ( empty( $tokens['access_token'] ) ) return new WP_Error( 'mad4b_google_drive_refreshed_token_missing', 'Google token refresh did not return an access token.' );
		$persisted = self::persist_tokens(
			(string) $tokens['access_token'],
			(string) $record['refresh_token'],
			isset( $tokens['expires_in'] ) ? absint( $tokens['expires_in'] ) : 3600,
			isset( $tokens['scope'] ) ? (string) $tokens['scope'] : ( isset( $record['scope'] ) ? (string) $record['scope'] : self::READ_SCOPE ),
			$record,
			isset( $record['access_mode'] ) ? (string) $record['access_mode'] : ( self::scope_allows_write( isset( $record['scope'] ) ? $record['scope'] : '' ) ? 'read_write' : 'read_only' ),
			$record_mode
		);
		if ( is_wp_error( $persisted ) ) return $persisted;
		return (string) $persisted['access_token'];
	}

	private static function persist_tokens( $access_token, $refresh_token, $expires_in, $scope, $existing = array(), $requested_mode = 'read_only', $auth_mode = '' ) {
		$scope = trim( (string) $scope );
		if ( ! self::scope_is_allowed( $scope ) ) return new WP_Error( 'mad4b_google_drive_scope_not_allowed', 'Google granted a scope set outside the governed Drive read/read-write contracts.' );
		$requested_mode = sanitize_key( (string) $requested_mode );
		if ( ! self::scope_matches_requested_mode( $scope, $requested_mode ) ) {
			if ( 'read_only' === $requested_mode && self::scope_allows_write( $scope ) ) return new WP_Error( 'mad4b_google_drive_readonly_scope_escalated', 'Google returned Drive write authority for a read-only connection. Revoke Google access and reconnect with Read-only to restore least privilege.' );
			if ( 'read_write' === $requested_mode && ! self::scope_allows_write( $scope ) ) return new WP_Error( 'mad4b_google_drive_write_scope_missing', 'Google did not grant the required Drive read+write scope.' );
			if ( 'read_only' === $requested_mode && ! self::scope_allows_read( $scope ) ) return new WP_Error( 'mad4b_google_drive_read_scope_missing', 'Google did not grant a Drive read scope.' );
			return new WP_Error( 'mad4b_google_drive_scope_set_not_exact', 'Google returned a Drive scope set that does not exactly match the requested governed access mode.' );
		}
		$record = is_array( $existing ) ? $existing : array();
		$record['contract'] = self::CONTRACT;
		$record['access_token'] = trim( (string) $access_token );
		$record['refresh_token'] = trim( (string) $refresh_token );
		$record['expires_at'] = time() + max( 60, absint( $expires_in ) );
		$record['scope'] = $scope;
		$record['access_mode'] = self::scope_allows_write( $scope ) ? 'read_write' : 'read_only';
		$auth_mode = sanitize_key( (string) $auth_mode );
		if ( '' === $auth_mode ) $auth_mode = isset( $record['auth_mode'] ) ? sanitize_key( (string) $record['auth_mode'] ) : self::auth_mode();
		if ( ! in_array( $auth_mode, array( self::AUTH_MODE_CUSTOM, self::AUTH_MODE_MANAGED, self::AUTH_MODE_DEDICATED ), true ) ) return new WP_Error( 'mad4b_google_drive_auth_mode_invalid', 'Google Drive token authentication mode is invalid.' );
		$record['auth_mode'] = $auth_mode;
		$record['updated_at'] = gmdate( 'c' );
		$sealed = self::seal_token_record( $record );
		if ( is_wp_error( $sealed ) ) return $sealed;
		if ( ! self::write_option( self::TOKEN_OPTION, $sealed ) ) return new WP_Error( 'mad4b_google_drive_token_persist_failed', 'Google OAuth token could not be persisted securely.' );
		return $record;
	}

	private static function token_record() {
		$stored = get_option( self::TOKEN_OPTION, array() );
		if ( ! is_array( $stored ) || self::CONTRACT !== ( isset( $stored['contract'] ) ? (string) $stored['contract'] : '' ) ) return array();
		$record = $stored;
		foreach ( array( 'access_token', 'refresh_token' ) as $field ) {
			$envelope = isset( $stored[ $field ] ) ? (string) $stored[ $field ] : '';
			$plain = '' === $envelope ? '' : self::decrypt_secret( $envelope );
			if ( is_wp_error( $plain ) ) return array();
			$record[ $field ] = $plain;
		}
		return $record;
	}

	private static function seal_token_record( array $record ) {
		foreach ( array( 'access_token', 'refresh_token' ) as $field ) {
			$value = isset( $record[ $field ] ) ? (string) $record[ $field ] : '';
			$record[ $field ] = '' === $value ? '' : self::encrypt_secret( $value );
			if ( is_wp_error( $record[ $field ] ) ) return $record[ $field ];
		}
		return $record;
	}

	private static function scope_items( $scope ) {
		$items = preg_split( '/\s+/', trim( (string) $scope ) );
		return array_values( array_unique( array_filter( is_array( $items ) ? $items : array() ) ) );
	}

	private static function scope_is_allowed( $scope ) {
		$items = self::scope_items( $scope );
		if ( empty( $items ) ) return false;
		foreach ( $items as $item ) {
			if ( ! hash_equals( self::READ_SCOPE, (string) $item ) && ! hash_equals( self::WRITE_SCOPE, (string) $item ) ) return false;
		}
		return true;
	}

	private static function scope_allows_read( $scope ) {
		$items = self::scope_items( $scope );
		return in_array( self::READ_SCOPE, $items, true ) || in_array( self::WRITE_SCOPE, $items, true );
	}

	private static function scope_allows_write( $scope ) {
		return in_array( self::WRITE_SCOPE, self::scope_items( $scope ), true );
	}

	private static function scope_matches_requested_mode( $scope, $requested_mode ) {
		$items = self::scope_items( $scope );
		$requested_mode = sanitize_key( (string) $requested_mode );
		if ( 'read_only' === $requested_mode ) return 1 === count( $items ) && in_array( self::READ_SCOPE, $items, true );
		if ( 'read_write' === $requested_mode ) return 1 === count( $items ) && in_array( self::WRITE_SCOPE, $items, true );
		return false;
	}

	public static function write_capability_status() {
		$status = self::connection_status();
		return array(
			'contract' => 'mad4b.google-drive-write-capability.v1',
			'connected' => ! empty( $status['connected'] ),
			'read_available' => ! empty( $status['read_available'] ),
			'write_available' => ! empty( $status['write_available'] ),
			'access_mode' => isset( $status['access_mode'] ) ? (string) $status['access_mode'] : 'read_only',
			'selected_source_count' => class_exists( 'MAD4B_SCP_Context_Authority' ) ? count( MAD4B_SCP_Context_Authority::sources() ) : 0,
			'create_source_count' => class_exists( 'MAD4B_SCP_Context_Authority' ) ? MAD4B_SCP_Context_Authority::writable_source_count( 'create' ) : 0,
			'update_source_count' => class_exists( 'MAD4B_SCP_Context_Authority' ) ? MAD4B_SCP_Context_Authority::writable_source_count( 'update' ) : 0,
			'recreate_source_count' => class_exists( 'MAD4B_SCP_Context_Authority' ) ? MAD4B_SCP_Context_Authority::writable_source_count( 'recreate' ) : 0,
			'allowed_operations' => array( 'update', 'recreate' ),
			'blocked_operations' => array( 'create' => 'mad4b_google_drive_create_rollback_not_certified' ),
			'delete_supported' => false,
			'trash_supported' => false,
			'blockers' => ! empty( $status['write_available'] ) ? array() : array( 'google_drive_write_scope_not_granted' ),
		);
	}


	private static function refresh_managed_access_token( array $record ) {
		$request = array(
			'contract' => 'mad4b.google-managed-oauth-refresh-request.v1',
			'site_uuid' => class_exists( 'MAD4B_SCP_Site_Profile' ) ? MAD4B_SCP_Site_Profile::site_uuid() : '',
			'origin' => self::canonical_origin(),
			'refresh_token' => isset( $record['refresh_token'] ) ? (string) $record['refresh_token'] : '',
			'requested_scope' => isset( $record['scope'] ) ? (string) $record['scope'] : '',
			'access_mode' => isset( $record['access_mode'] ) ? (string) $record['access_mode'] : 'read_only',
		);
		$record_mode = isset( $record['auth_mode'] ) ? sanitize_key( (string) $record['auth_mode'] ) : self::AUTH_MODE_MANAGED;
		$tokens = self::managed_broker_post( 'refresh', $request, 'mad4b_google_managed_refresh_failed', $record_mode );
		if ( is_wp_error( $tokens ) ) return $tokens;
		if ( self::MANAGED_REFRESH_CONTRACT !== ( isset( $tokens['contract'] ) ? (string) $tokens['contract'] : '' ) ) return new WP_Error( 'mad4b_google_managed_refresh_contract_invalid', 'Managed Google Sign-In broker returned an unexpected refresh contract.' );
		if ( empty( $tokens['access_token'] ) ) return new WP_Error( 'mad4b_google_drive_refreshed_token_missing', 'Managed Google Sign-In refresh did not return an access token.' );
		$scope = isset( $tokens['scope'] ) && '' !== trim( (string) $tokens['scope'] ) ? (string) $tokens['scope'] : ( isset( $record['scope'] ) ? (string) $record['scope'] : '' );
		$persisted = self::persist_tokens(
			(string) $tokens['access_token'],
			(string) $record['refresh_token'],
			isset( $tokens['expires_in'] ) ? absint( $tokens['expires_in'] ) : 3600,
			$scope,
			$record,
			isset( $record['access_mode'] ) ? (string) $record['access_mode'] : 'read_only',
			$record_mode
		);
		if ( is_wp_error( $persisted ) ) return $persisted;
		return (string) $persisted['access_token'];
	}

	private static function refresh_account_identity() {
		$about = self::about();
		if ( is_wp_error( $about ) || ! isset( $about['user'] ) || ! is_array( $about['user'] ) ) return $about;
		$record = self::token_record();
		if ( ! is_array( $record ) || empty( $record['refresh_token'] ) ) return new WP_Error( 'mad4b_google_drive_not_connected', 'Google Drive token record is unavailable after OAuth completion.' );
		$record['account_email'] = isset( $about['user']['emailAddress'] ) ? sanitize_email( (string) $about['user']['emailAddress'] ) : '';
		$record['account_name'] = isset( $about['user']['displayName'] ) ? sanitize_text_field( (string) $about['user']['displayName'] ) : '';
		$record['permission_id'] = isset( $about['user']['permissionId'] ) ? sanitize_text_field( (string) $about['user']['permissionId'] ) : '';
		$record['last_verified_at'] = gmdate( 'c' );
		$sealed = self::seal_token_record( $record );
		if ( is_wp_error( $sealed ) ) return $sealed;
		if ( ! self::write_option( self::TOKEN_OPTION, $sealed ) ) return new WP_Error( 'mad4b_google_drive_identity_persist_failed', 'Google account identity could not be persisted after connection.' );
		return $record;
	}

	private static function managed_broker_base_url() {
		if ( ! defined( 'MAD4B_GOOGLE_MANAGED_OAUTH_BROKER_URL' ) ) return new WP_Error( 'mad4b_google_managed_broker_not_configured', 'Managed Google Sign-In requires MAD4B_GOOGLE_MANAGED_OAUTH_BROKER_URL.' );
		$url = self::validated_https_url( (string) constant( 'MAD4B_GOOGLE_MANAGED_OAUTH_BROKER_URL' ) );
		if ( '' === $url ) return new WP_Error( 'mad4b_google_managed_broker_invalid', 'Managed Google Sign-In broker URL must be a valid HTTPS URL.' );
		$parts = function_exists( 'wp_parse_url' ) ? wp_parse_url( $url ) : parse_url( $url );
		if ( ! is_array( $parts ) || ! empty( $parts['query'] ) || ! empty( $parts['fragment'] ) || ! empty( $parts['user'] ) || ! empty( $parts['pass'] ) ) return new WP_Error( 'mad4b_google_managed_broker_invalid', 'Managed Google Sign-In broker URL cannot contain query, fragment, or user-info components.' );
		return rtrim( $url, '/' );
	}

	private static function managed_broker_endpoint( $operation ) {
		$base = self::managed_broker_base_url();
		if ( is_wp_error( $base ) ) return '';
		$operation = sanitize_key( (string) $operation );
		if ( ! in_array( $operation, array( 'session', 'redeem', 'refresh' ), true ) ) return '';
		return rtrim( (string) $base, '/' ) . '/v1/google/oauth/' . $operation;
	}

	private static function managed_broker_post( $operation, array $payload, $error_code ) {
		$endpoint = self::managed_broker_endpoint( $operation );
		if ( '' === $endpoint ) return new WP_Error( 'mad4b_google_managed_broker_not_configured', 'Managed Google Sign-In broker is unavailable.' );
		$response = wp_remote_post(
			$endpoint,
			array(
				'timeout' => 20,
				'redirection' => 0,
				'headers' => array( 'Content-Type' => 'application/json', 'Accept' => 'application/json' ),
				'body' => wp_json_encode( $payload, JSON_UNESCAPED_SLASHES ),
			)
		);
		if ( is_wp_error( $response ) ) return $response;
		$status = (int) wp_remote_retrieve_response_code( $response );
		$data = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( $status < 200 || $status >= 300 ) {
			$provider_code = is_array( $data ) && isset( $data['error'] ) ? sanitize_key( is_array( $data['error'] ) && isset( $data['error']['code'] ) ? (string) $data['error']['code'] : (string) $data['error'] ) : '';
			return new WP_Error( $error_code, 'Managed Google Sign-In broker returned a non-success status.', array( 'status' => $status, 'provider_code' => $provider_code ) );
		}
		if ( ! is_array( $data ) ) return new WP_Error( $error_code, 'Managed Google Sign-In broker returned invalid JSON.', array( 'status' => $status ) );
		return $data;
	}

	private static function validated_https_url( $value ) {
		$value = trim( (string) $value );
		if ( '' === $value || strlen( $value ) > 2048 ) return '';
		$url = esc_url_raw( $value );
		$parts = function_exists( 'wp_parse_url' ) ? wp_parse_url( $url ) : parse_url( $url );
		if ( ! is_array( $parts ) || empty( $parts['scheme'] ) || 'https' !== strtolower( (string) $parts['scheme'] ) || empty( $parts['host'] ) ) return '';
		if ( ! empty( $parts['user'] ) || ! empty( $parts['pass'] ) ) return '';
		return $url;
	}

	private static function canonical_origin() {
		if ( class_exists( 'MAD4B_SCP_Site_Profile' ) && MAD4B_SCP_Site_Profile::origin_enrolled() ) {
			$origin = (string) MAD4B_SCP_Site_Profile::site_origin();
			if ( '' !== $origin ) return rtrim( $origin, '/' );
		}
		return rtrim( (string) home_url( '/' ), '/' );
	}

	private static function redirect_uri_for_mode( $mode ) {
		$mode = sanitize_key( (string) $mode );
		if ( self::AUTH_MODE_DEDICATED === $mode ) return self::dedicated_redirect_uri();
		if ( self::AUTH_MODE_MANAGED === $mode ) return self::managed_redirect_uri();
		return self::redirect_uri();
	}

	private static function credentials( $mode = '' ) {
		$mode = sanitize_key( (string) $mode );
		if ( '' === $mode ) $mode = self::auth_mode();
		if ( self::AUTH_MODE_DEDICATED === $mode ) {
			$constant_id = defined( 'MAD4B_GOOGLE_DEDICATED_CLIENT_ID' );
			$constant_secret = defined( 'MAD4B_GOOGLE_DEDICATED_CLIENT_SECRET' );
			if ( $constant_id xor $constant_secret ) return new WP_Error( 'mad4b_google_dedicated_constants_incomplete', 'Define both dedicated Google OAuth constants or neither of them.' );
			if ( $constant_id && $constant_secret ) {
				$id = trim( (string) constant( 'MAD4B_GOOGLE_DEDICATED_CLIENT_ID' ) );
				$secret = trim( (string) constant( 'MAD4B_GOOGLE_DEDICATED_CLIENT_SECRET' ) );
				if ( '' !== $id && '' !== $secret ) return array( 'client_id' => $id, 'client_secret' => $secret );
			}
			$record = get_option( self::DEDICATED_CONFIG_OPTION, array() );
			if ( ! is_array( $record ) || self::CONTRACT !== ( isset( $record['contract'] ) ? (string) $record['contract'] : '' ) || empty( $record['client_id'] ) || empty( $record['client_secret'] ) ) return new WP_Error( 'mad4b_google_dedicated_credentials_missing', 'Dedicated Google OAuth client credentials are not configured.' );
			$secret = self::decrypt_secret( (string) $record['client_secret'] );
			if ( is_wp_error( $secret ) ) return $secret;
			return array( 'client_id' => (string) $record['client_id'], 'client_secret' => $secret );
		}
		$constant_id = defined( 'MAD4B_GOOGLE_DRIVE_CLIENT_ID' );
		$constant_secret = defined( 'MAD4B_GOOGLE_DRIVE_CLIENT_SECRET' );
		if ( $constant_id xor $constant_secret ) return new WP_Error( 'mad4b_google_drive_constants_incomplete', 'Define both Google Drive OAuth constants or neither of them.' );
		if ( $constant_id && $constant_secret ) {
			$id = trim( (string) constant( 'MAD4B_GOOGLE_DRIVE_CLIENT_ID' ) );
			$secret = trim( (string) constant( 'MAD4B_GOOGLE_DRIVE_CLIENT_SECRET' ) );
			if ( '' !== $id && '' !== $secret ) return array( 'client_id' => $id, 'client_secret' => $secret );
		}
		$record = get_option( self::CONFIG_OPTION, array() );
		if ( ! is_array( $record ) || self::CONTRACT !== ( isset( $record['contract'] ) ? (string) $record['contract'] : '' ) || empty( $record['client_id'] ) || empty( $record['client_secret'] ) ) {
			return new WP_Error( 'mad4b_google_drive_credentials_missing', 'Google OAuth client credentials are not configured.' );
		}
		$secret = self::decrypt_secret( (string) $record['client_secret'] );
		if ( is_wp_error( $secret ) ) return $secret;
		return array( 'client_id' => (string) $record['client_id'], 'client_secret' => $secret );
	}

	private static function encrypt_secret( $plain ) {
		if ( ! function_exists( 'openssl_encrypt' ) ) return new WP_Error( 'mad4b_google_drive_crypto_unavailable', 'OpenSSL is required to protect Google OAuth secrets.' );
		$plain = (string) $plain;
		$key = self::crypto_key();
		try {
			$iv = random_bytes( 12 );
		} catch ( Exception $e ) {
			return new WP_Error( 'mad4b_google_drive_random_unavailable', 'Secure random bytes are unavailable.' );
		}
		$tag = '';
		$cipher = openssl_encrypt( $plain, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag, 'mad4b-google-drive-v1' );
		if ( false === $cipher ) return new WP_Error( 'mad4b_google_drive_encrypt_failed', 'Unable to encrypt Google OAuth secret.' );
		return base64_encode( wp_json_encode( array( 'v' => 1, 'iv' => base64_encode( $iv ), 'tag' => base64_encode( $tag ), 'ct' => base64_encode( $cipher ) ) ) );
	}

	private static function decrypt_secret( $envelope ) {
		if ( ! function_exists( 'openssl_decrypt' ) ) return new WP_Error( 'mad4b_google_drive_crypto_unavailable', 'OpenSSL is required to read Google OAuth secrets.' );
		$json = base64_decode( (string) $envelope, true );
		$data = is_string( $json ) ? json_decode( $json, true ) : null;
		if ( ! is_array( $data ) || 1 !== ( isset( $data['v'] ) ? (int) $data['v'] : 0 ) ) return new WP_Error( 'mad4b_google_drive_secret_envelope_invalid', 'Stored Google OAuth secret envelope is invalid.' );
		$iv = isset( $data['iv'] ) ? base64_decode( (string) $data['iv'], true ) : false;
		$tag = isset( $data['tag'] ) ? base64_decode( (string) $data['tag'], true ) : false;
		$cipher = isset( $data['ct'] ) ? base64_decode( (string) $data['ct'], true ) : false;
		if ( false === $iv || false === $tag || false === $cipher ) return new WP_Error( 'mad4b_google_drive_secret_envelope_invalid', 'Stored Google OAuth secret envelope is malformed.' );
		$plain = openssl_decrypt( $cipher, 'aes-256-gcm', self::crypto_key(), OPENSSL_RAW_DATA, $iv, $tag, 'mad4b-google-drive-v1' );
		if ( false === $plain ) return new WP_Error( 'mad4b_google_drive_secret_decrypt_failed', 'Stored Google OAuth secret cannot be decrypted on this site.' );
		return $plain;
	}

	private static function crypto_key() {
		$material = function_exists( 'wp_salt' ) ? wp_salt( 'auth' ) : ( defined( 'AUTH_KEY' ) ? (string) AUTH_KEY : 'mad4b-context-fallback' );
		$material .= '|' . ( function_exists( 'home_url' ) ? home_url( '/' ) : 'unknown' ) . '|mad4b-google-drive-v1';
		return hash( 'sha256', $material, true );
	}

	private static function decode_json_response( $response, $error_code ) {
		if ( is_wp_error( $response ) ) return $response;
		$status = (int) wp_remote_retrieve_response_code( $response );
		$body = (string) wp_remote_retrieve_body( $response );
		$data = json_decode( $body, true );
		if ( $status < 200 || $status >= 300 ) {
			$provider_code = '';
			if ( is_array( $data ) && isset( $data['error'] ) ) {
				if ( is_string( $data['error'] ) ) $provider_code = sanitize_key( $data['error'] );
				elseif ( is_array( $data['error'] ) && isset( $data['error']['status'] ) ) $provider_code = sanitize_key( (string) $data['error']['status'] );
			}
			return new WP_Error( $error_code, 'Google API returned a non-success status.', array( 'status' => $status, 'provider_code' => $provider_code ) );
		}
		if ( ! is_array( $data ) ) return new WP_Error( $error_code, 'Google API returned an invalid JSON response.', array( 'status' => $status ) );
		return $data;
	}

	private static function connection_blockers( array $credentials, $token ) {
		$blockers = array();
		if ( empty( $credentials['configured'] ) ) $blockers[] = self::AUTH_MODE_MANAGED === self::auth_mode() ? 'managed_google_oauth_broker_not_configured' : ( self::AUTH_MODE_DEDICATED === self::auth_mode() ? 'dedicated_google_oauth_credentials_missing' : 'google_oauth_credentials_missing' );
		$stored = get_option( self::TOKEN_OPTION, array() );
		$stored_present = is_array( $stored ) && self::CONTRACT === ( isset( $stored['contract'] ) ? (string) $stored['contract'] : '' );
		if ( $stored_present && ( ! is_array( $token ) || empty( $token['refresh_token'] ) ) ) $blockers[] = 'google_drive_token_unreadable';
		elseif ( ! is_array( $token ) || empty( $token['refresh_token'] ) ) $blockers[] = 'google_drive_not_connected';
		elseif ( ! empty( $token['revocation_pending'] ) ) $blockers[] = 'google_drive_revocation_pending';
		return $blockers;
	}

	private static function bounded_drive_id( $value ) {
		$value = trim( sanitize_text_field( (string) $value ) );
		if ( 'root' === $value ) return 'root';
		if ( '' === $value || strlen( $value ) > 255 || ! preg_match( '/^[A-Za-z0-9_\-\.]+$/', $value ) ) return '';
		return $value;
	}

	private static function state_key( $user_id ) {
		return 'mad4b_scp_gdrive_oauth_' . absint( $user_id );
	}

	private static function managed_state_key( $user_id ) {
		return 'mad4b_scp_gdrive_managed_oauth_' . absint( $user_id );
	}

	private static function suffix( $value ) {
		$value = (string) $value;
		return strlen( $value ) <= 8 ? $value : '…' . substr( $value, -8 );
	}

	private static function write_option( $name, $value ) {
		$current = get_option( $name, false );
		if ( false !== $current && $current === $value ) return true;
		$result = false === $current ? add_option( $name, $value, '', false ) : update_option( $name, $value, false );
		if ( true === $result ) return true;
		return get_option( $name, false ) === $value;
	}

	private static function delete_option_verified( $name ) {
		if ( false === get_option( $name, false ) ) return true;
		if ( delete_option( $name ) ) return true;
		return false === get_option( $name, false );
	}
}
