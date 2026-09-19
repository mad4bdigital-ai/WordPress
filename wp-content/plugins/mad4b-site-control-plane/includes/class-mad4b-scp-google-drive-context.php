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
	const TOKEN_OPTION = 'mad4b_scp_google_drive_oauth_token_v1';

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
	const MAX_WRITE_BYTES = 1048576;
	const MAX_REVERSIBLE_TEXT_BYTES = 196608;
	const MAX_PARENT_DEPTH = 16;

	public static function redirect_uri() {
		return admin_url( 'admin-post.php?action=mad4b_context_google_callback' );
	}

	public static function credentials_status() {
		$credentials = self::credentials();
		return array(
			'contract' => self::CONTRACT,
			'configured' => ! is_wp_error( $credentials ),
			'configured_by_constants' => defined( 'MAD4B_GOOGLE_DRIVE_CLIENT_ID' ) && defined( 'MAD4B_GOOGLE_DRIVE_CLIENT_SECRET' ),
			'client_id' => is_wp_error( $credentials ) ? '' : sanitize_text_field( (string) $credentials['client_id'] ),
			'client_id_suffix' => is_wp_error( $credentials ) ? '' : self::suffix( $credentials['client_id'] ),
			'redirect_uri' => self::redirect_uri(),
			'read_scope' => self::READ_SCOPE,
			'write_scope' => self::WRITE_SCOPE,
			'supported_access_modes' => array( 'read_only', 'read_write' ),
		);
	}

	public static function save_credentials( $client_id, $client_secret ) {
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
		self::write_option( self::CONFIG_OPTION, $record );
		return self::credentials_status();
	}

	public static function connection_status() {
		$token = self::token_record();
		$credentials = self::credentials_status();
		$connected = is_array( $token ) && ! empty( $token['refresh_token'] );
		$scope = $connected && isset( $token['scope'] ) ? trim( (string) $token['scope'] ) : '';
		$read_available = $connected && self::scope_allows_read( $scope );
		$write_available = $connected && self::scope_allows_write( $scope );
		return array(
			'contract' => self::CONTRACT,
			'configured' => ! empty( $credentials['configured'] ),
			'connected' => $connected,
			'read_available' => $read_available,
			'write_available' => $write_available,
			'read_only' => $connected && ! $write_available,
			'access_mode' => $write_available ? 'read_write' : 'read_only',
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

	public static function authorization_url( $access_mode = 'read_only' ) {
		if ( ! current_user_can( 'manage_options' ) ) return new WP_Error( 'mad4b_google_drive_admin_required', 'Administrator capability is required to connect Google Drive.' );
		if ( ! class_exists( 'MAD4B_SCP_Site_Profile' ) || ! MAD4B_SCP_Site_Profile::origin_enrolled() || '' === MAD4B_SCP_Site_Profile::site_uuid() ) return new WP_Error( 'mad4b_google_drive_site_profile_required', 'Enroll this Site Profile before connecting Google Drive.' );
		$credentials = self::credentials();
		if ( is_wp_error( $credentials ) ) return $credentials;
		$access_mode = sanitize_key( (string) $access_mode );
		if ( ! in_array( $access_mode, array( 'read_only', 'read_write' ), true ) ) return new WP_Error( 'mad4b_google_drive_access_mode_invalid', 'Google Drive access mode must be read_only or read_write.' );
		$requested_scope = 'read_write' === $access_mode ? self::WRITE_SCOPE : self::READ_SCOPE;
		$state = wp_generate_password( 64, false, false );
		if ( '' === $state ) return new WP_Error( 'mad4b_google_drive_state_generation_failed', 'Unable to generate OAuth state.' );
		set_transient(
			self::state_key( get_current_user_id() ),
			array(
				'state' => hash( 'sha256', $state ),
				'redirect_uri' => self::redirect_uri(),
				'site_uuid' => class_exists( 'MAD4B_SCP_Site_Profile' ) ? MAD4B_SCP_Site_Profile::site_uuid() : '',
				'access_mode' => $access_mode,
				'requested_scope' => $requested_scope,
				'created_at' => time(),
			),
			10 * MINUTE_IN_SECONDS
		);
		return add_query_arg(
			array(
				'client_id' => $credentials['client_id'],
				'redirect_uri' => self::redirect_uri(),
				'response_type' => 'code',
				'scope' => $requested_scope,
				'access_type' => 'offline',
				'include_granted_scopes' => 'read_write' === $access_mode ? 'true' : 'false',
				'prompt' => 'consent',
				'state' => $state,
			),
			self::AUTH_ENDPOINT
		);
	}

	public static function complete_oauth( $code, $state ) {
		if ( ! current_user_can( 'manage_options' ) ) return new WP_Error( 'mad4b_google_drive_admin_required', 'Administrator capability is required to complete Google Drive connection.' );
		$stored = get_transient( self::state_key( get_current_user_id() ) );
		delete_transient( self::state_key( get_current_user_id() ) );
		$state = trim( (string) $state );
		if ( ! is_array( $stored ) || empty( $stored['state'] ) || '' === $state || ! hash_equals( (string) $stored['state'], hash( 'sha256', $state ) ) ) {
			return new WP_Error( 'mad4b_google_drive_oauth_state_invalid', 'Google OAuth state is missing, expired, or invalid.' );
		}
		$current_site_uuid = class_exists( 'MAD4B_SCP_Site_Profile' ) ? MAD4B_SCP_Site_Profile::site_uuid() : '';
		if ( empty( $stored['site_uuid'] ) || '' === $current_site_uuid || ! hash_equals( (string) $stored['site_uuid'], $current_site_uuid ) ) return new WP_Error( 'mad4b_google_drive_oauth_site_binding_changed', 'Site Profile binding changed during Google OAuth. Start the connection again.' );
		if ( empty( $stored['redirect_uri'] ) || ! hash_equals( (string) $stored['redirect_uri'], self::redirect_uri() ) ) return new WP_Error( 'mad4b_google_drive_oauth_redirect_binding_changed', 'OAuth redirect binding changed during Google connection. Start the connection again.' );
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
					'redirect_uri' => self::redirect_uri(),
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
		$record = self::persist_tokens(
			(string) $tokens['access_token'],
			$refresh,
			isset( $tokens['expires_in'] ) ? absint( $tokens['expires_in'] ) : 3600,
			isset( $tokens['scope'] ) ? (string) $tokens['scope'] : ( isset( $stored['requested_scope'] ) ? (string) $stored['requested_scope'] : self::READ_SCOPE ),
			array(),
			$requested_mode
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

	public static function disconnect() {
		$record = self::token_record();
		$revocation_attempted = false;
		$revocation_confirmed = false;
		$revocation_error = '';
		$token = is_array( $record ) && ! empty( $record['refresh_token'] ) ? (string) $record['refresh_token'] : ( is_array( $record ) && ! empty( $record['access_token'] ) ? (string) $record['access_token'] : '' );
		if ( '' !== $token ) {
			$revocation_attempted = true;
			$response = wp_remote_post(
				self::REVOKE_ENDPOINT,
				array(
					'timeout' => 20,
					'redirection' => 0,
					'headers' => array( 'Content-Type' => 'application/x-www-form-urlencoded' ),
					'body' => array( 'token' => $token ),
				)
			);
			if ( is_wp_error( $response ) ) $revocation_error = sanitize_key( $response->get_error_code() );
			else {
				$status = (int) wp_remote_retrieve_response_code( $response );
				$revocation_confirmed = 200 === $status;
				if ( ! $revocation_confirmed ) $revocation_error = 'google_revocation_http_' . $status;
			}
		}
		delete_option( self::TOKEN_OPTION );
		$status = self::connection_status();
		$status['remote_revocation_attempted'] = $revocation_attempted;
		$status['remote_revocation_confirmed'] = $revocation_confirmed;
		$status['remote_revocation_error'] = $revocation_error;
		return $status;
	}

	public static function about() {
		return self::api_get( self::DRIVE_API . '/about?fields=user(displayName,emailAddress,permissionId)' );
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
		$queue = array( array( 'id' => (string) $folder['id'], 'path' => (string) $folder['name'], 'depth' => 0 ) );
		$assets = array();
		$visited = array();
		while ( $queue && count( $assets ) < self::MAX_SCAN_FILES && count( $visited ) < self::MAX_SCAN_FOLDERS ) {
			$current = array_shift( $queue );
			$id = (string) $current['id'];
			if ( isset( $visited[ $id ] ) ) continue;
			$visited[ $id ] = true;
			$children = self::list_children( $id, false );
			if ( is_wp_error( $children ) ) return $children;
			foreach ( $children as $child ) {
				$mime = isset( $child['mimeType'] ) ? (string) $child['mimeType'] : '';
				$name = isset( $child['name'] ) ? (string) $child['name'] : 'Untitled';
				$path = rtrim( (string) $current['path'], '/' ) . '/' . $name;
				if ( 'application/vnd.google-apps.folder' === $mime ) {
					if ( $recursive && (int) $current['depth'] < 8 && count( $visited ) + count( $queue ) < self::MAX_SCAN_FOLDERS ) {
						$queue[] = array( 'id' => (string) $child['id'], 'path' => $path, 'depth' => (int) $current['depth'] + 1 );
					}
					continue;
				}
				$text = self::fetch_text_content( $child );
				if ( is_wp_error( $text ) ) $text = '';
				$basis = '' !== $text ? $text : ( isset( $child['md5Checksum'] ) && $child['md5Checksum'] ? (string) $child['md5Checksum'] : (string) $child['id'] . '|' . ( isset( $child['modifiedTime'] ) ? $child['modifiedTime'] : '' ) );
				$assets[] = array(
					'file_id' => isset( $child['id'] ) ? (string) $child['id'] : '',
					'title' => $name,
					'path' => $path,
					'mimeType' => $mime,
					'modifiedTime' => isset( $child['modifiedTime'] ) ? (string) $child['modifiedTime'] : '',
					'size' => isset( $child['size'] ) ? (string) $child['size'] : '',
					'webViewLink' => isset( $child['webViewLink'] ) ? esc_url_raw( (string) $child['webViewLink'] ) : '',
					'normalized_text' => $text,
					'content_hash' => hash( 'sha256', $basis ),
				);
				if ( count( $assets ) >= self::MAX_SCAN_FILES ) break;
			}
		}
		return array(
			'contract' => 'mad4b.google-drive-folder-scan.v1',
			'folder' => $folder,
			'recursive' => (bool) $recursive,
			'asset_count' => count( $assets ),
			'folder_count' => count( $visited ),
			'truncated' => count( $assets ) >= self::MAX_SCAN_FILES || count( $visited ) >= self::MAX_SCAN_FOLDERS,
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
		$file = self::create_provider_file( (string) $source['external_root_id'], $name, $content, $format );
		if ( is_wp_error( $file ) ) return $file;
		$observed = self::provider_observed_text( $file, $content );
		if ( is_wp_error( $observed ) ) return $observed;
		$asset = self::provider_asset_payload( $source, $file, $observed );
		$registered = MAD4B_SCP_Context_Authority::upsert_asset_from_provider( (string) $source['source_id'], $asset );
		if ( is_wp_error( $registered ) ) return $registered;
		return array(
			'contract' => 'mad4b.google-drive-asset-mutation.v1',
			'operation' => 'create',
			'source_id' => (string) $source['source_id'],
			'asset_id' => (string) $registered['asset_id'],
			'file_id' => (string) $registered['file_id'],
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
		$payload = self::provider_asset_payload( $source, $updated, $observed );
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
		$content = (string) $content;
		$content_guard = self::validate_write_content( $content );
		if ( is_wp_error( $content_guard ) ) return $content_guard;
		if ( strlen( $content ) > self::MAX_REVERSIBLE_TEXT_BYTES ) return new WP_Error( 'mad4b_google_drive_reversible_write_too_large', 'Governed Drive recreation exceeds the reversible snapshot limit.', array( 'max_bytes' => self::MAX_REVERSIBLE_TEXT_BYTES ) );
		$title = isset( $asset['title'] ) ? (string) $asset['title'] : 'Recreated Context Asset';
		$title = preg_replace( '/\s+\(recreated[^)]*\)$/i', '', $title );
		$file = self::create_provider_file( (string) $source['external_root_id'], $title, $content, $format );
		if ( is_wp_error( $file ) ) return $file;
		$observed = self::provider_observed_text( $file, $content );
		if ( is_wp_error( $observed ) ) return $observed;
		$payload = self::provider_asset_payload( $source, $file, $observed );
		$registered = MAD4B_SCP_Context_Authority::upsert_asset_from_provider( (string) $source['source_id'], $payload, $asset );
		if ( is_wp_error( $registered ) ) return $registered;
		MAD4B_SCP_Context_Authority::mark_asset_recreated( $asset_id, $registered );
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
		$state = array(
			'asset_id' => (string) $asset['asset_id'],
			'source_id' => (string) $asset['source_id'],
			'original_file_id' => isset( $asset['file_id'] ) ? (string) $asset['file_id'] : '',
			'status' => $status,
			'availability_reason' => isset( $asset['availability_reason'] ) ? (string) $asset['availability_reason'] : '',
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
		$payload = self::provider_asset_payload( $source, $restored, $observed );
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
		$deleted = self::delete_provider_file_for_rollback( (string) $current['replacement_file_id'], $source );
		if ( is_wp_error( $deleted ) ) return $deleted;
		return MAD4B_SCP_Context_Authority::rollback_recreated_asset( $asset_id, (string) $current['replacement_asset_id'], $before_state );
	}

	private static function provider_observed_text( array $file, $fallback ) {
		$observed = self::fetch_text_content( $file );
		if ( is_wp_error( $observed ) ) return $observed;
		if ( '' === (string) $observed && '' !== (string) $fallback ) return new WP_Error( 'mad4b_google_drive_write_readback_empty', 'Google Drive write completed but provider readback was unexpectedly empty.' );
		return (string) $observed;
	}

	private static function delete_provider_file_for_rollback( $file_id, array $source ) {
		$file_id = self::bounded_drive_id( $file_id );
		if ( '' === $file_id ) return new WP_Error( 'mad4b_google_drive_file_id_invalid', 'Google Drive replacement file ID is invalid.' );
		$membership = self::assert_file_within_source( $file_id, $source );
		if ( is_wp_error( $membership ) ) return $membership;
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
		if ( ! seems_utf8( (string) $content ) ) return new WP_Error( 'mad4b_google_drive_write_utf8_required', 'Drive asset content must be valid UTF-8 text.' );
		return true;
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

	private static function get_file_metadata( $file_id ) {
		$file_id = self::bounded_drive_id( $file_id );
		if ( '' === $file_id ) return new WP_Error( 'mad4b_google_drive_file_id_invalid', 'Google Drive file ID is invalid.' );
		$url = self::DRIVE_API . '/files/' . rawurlencode( $file_id ) . '?' . http_build_query(
			array( 'fields' => 'id,name,mimeType,parents,modifiedTime,size,md5Checksum,driveId,webViewLink', 'supportsAllDrives' => 'true' ),
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
			$parent_id = (string) reset( $parents );
			if ( isset( $visited[ $parent_id ] ) ) break;
			$visited[ $parent_id ] = true;
			$current = self::get_file_metadata( $parent_id );
			if ( is_wp_error( $current ) ) return $current;
		}
		return new WP_Error( 'mad4b_google_drive_asset_outside_selected_source', 'Drive asset is outside the selected Context source folder.' );
	}

	private static function provider_asset_payload( array $source, array $file, $content ) {
		$name = isset( $file['name'] ) ? (string) $file['name'] : 'Untitled';
		return array(
			'file_id' => isset( $file['id'] ) ? (string) $file['id'] : '',
			'title' => $name,
			'path' => ( isset( $source['label'] ) ? (string) $source['label'] : 'Drive' ) . '/' . $name,
			'mimeType' => isset( $file['mimeType'] ) ? (string) $file['mimeType'] : 'text/plain',
			'modifiedTime' => isset( $file['modifiedTime'] ) ? (string) $file['modifiedTime'] : gmdate( 'c' ),
			'webViewLink' => isset( $file['webViewLink'] ) ? esc_url_raw( (string) $file['webViewLink'] ) : '',
			'normalized_text' => (string) $content,
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

	private static function list_children( $parent_id, $folders_only ) {
		$parent_id = self::bounded_drive_id( $parent_id );
		if ( '' === $parent_id ) return new WP_Error( 'mad4b_google_drive_parent_id_invalid', 'Google Drive parent folder ID is invalid.' );
		$q = "'" . str_replace( "'", "\\'", $parent_id ) . "' in parents and trashed = false";
		if ( $folders_only ) $q .= " and mimeType = 'application/vnd.google-apps.folder'";
		$items = array();
		$page_token = '';
		$pages = 0;
		do {
			$params = array(
				'q' => $q,
				'pageSize' => 100,
				'fields' => 'nextPageToken,files(id,name,mimeType,modifiedTime,size,md5Checksum,parents,driveId,webViewLink,description)',
				'spaces' => 'drive',
				'supportsAllDrives' => 'true',
				'includeItemsFromAllDrives' => 'true',
			);
			if ( '' !== $page_token ) $params['pageToken'] = $page_token;
			$url = self::DRIVE_API . '/files?' . http_build_query( $params, '', '&', PHP_QUERY_RFC3986 );
			$data = self::api_get( $url );
			if ( is_wp_error( $data ) ) return $data;
			foreach ( isset( $data['files'] ) && is_array( $data['files'] ) ? $data['files'] : array() as $file ) {
				if ( is_array( $file ) ) $items[] = $file;
				if ( count( $items ) >= self::MAX_SCAN_FILES ) break 2;
			}
			$page_token = isset( $data['nextPageToken'] ) ? sanitize_text_field( (string) $data['nextPageToken'] ) : '';
			++$pages;
		} while ( '' !== $page_token && $pages < 10 );
		return $items;
	}

	private static function fetch_text_content( array $file ) {
		$file_id = self::bounded_drive_id( isset( $file['id'] ) ? $file['id'] : '' );
		if ( '' === $file_id ) return new WP_Error( 'mad4b_google_drive_file_id_invalid', 'Google Drive file ID is invalid.' );
		$mime = isset( $file['mimeType'] ) ? strtolower( (string) $file['mimeType'] ) : '';
		$url = '';
		if ( 'application/vnd.google-apps.document' === $mime ) {
			$url = self::DRIVE_API . '/files/' . rawurlencode( $file_id ) . '/export?mimeType=' . rawurlencode( 'text/plain' );
		} elseif ( 'application/vnd.google-apps.spreadsheet' === $mime ) {
			$url = self::DRIVE_API . '/files/' . rawurlencode( $file_id ) . '/export?mimeType=' . rawurlencode( 'text/csv' );
		} elseif ( 0 === strpos( $mime, 'text/' ) || in_array( $mime, array( 'application/json', 'application/xml', 'application/csv' ), true ) ) {
			$url = self::DRIVE_API . '/files/' . rawurlencode( $file_id ) . '?alt=media&supportsAllDrives=true';
		} else {
			return '';
		}
		$token = self::access_token();
		if ( is_wp_error( $token ) ) return $token;
		$response = wp_remote_get(
			$url,
			array(
				'timeout' => 20,
				'redirection' => 2,
				'headers' => array( 'Authorization' => 'Bearer ' . $token ),
				'limit_response_size' => self::MAX_TEXT_BYTES,
			)
		);
		if ( is_wp_error( $response ) ) return $response;
		$status = (int) wp_remote_retrieve_response_code( $response );
		if ( $status < 200 || $status >= 300 ) return new WP_Error( 'mad4b_google_drive_content_fetch_failed', 'Google Drive content export returned a non-success status.', array( 'status' => $status ) );
		$body = (string) wp_remote_retrieve_body( $response );
		if ( strlen( $body ) > self::MAX_TEXT_BYTES ) $body = substr( $body, 0, self::MAX_TEXT_BYTES );
		return wp_check_invalid_utf8( $body, true );
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
		if ( ! empty( $record['access_token'] ) && ! empty( $record['expires_at'] ) && (int) $record['expires_at'] > time() + 90 ) return (string) $record['access_token'];
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
			isset( $record['access_mode'] ) ? (string) $record['access_mode'] : ( self::scope_allows_write( isset( $record['scope'] ) ? $record['scope'] : '' ) ? 'read_write' : 'read_only' )
		);
		if ( is_wp_error( $persisted ) ) return $persisted;
		return (string) $persisted['access_token'];
	}

	private static function persist_tokens( $access_token, $refresh_token, $expires_in, $scope, $existing = array(), $requested_mode = 'read_only' ) {
		$scope = trim( (string) $scope );
		if ( ! self::scope_is_allowed( $scope ) ) return new WP_Error( 'mad4b_google_drive_scope_not_allowed', 'Google granted a scope set outside the governed Drive read/read-write contracts.' );
		$requested_mode = sanitize_key( (string) $requested_mode );
		if ( 'read_write' === $requested_mode && ! self::scope_allows_write( $scope ) ) return new WP_Error( 'mad4b_google_drive_write_scope_missing', 'Google did not grant the required Drive read+write scope.' );
		if ( 'read_only' === $requested_mode && ! self::scope_allows_read( $scope ) ) return new WP_Error( 'mad4b_google_drive_read_scope_missing', 'Google did not grant a Drive read scope.' );
		$record = is_array( $existing ) ? $existing : array();
		$record['contract'] = self::CONTRACT;
		$record['access_token'] = trim( (string) $access_token );
		$record['refresh_token'] = trim( (string) $refresh_token );
		$record['expires_at'] = time() + max( 60, absint( $expires_in ) );
		$record['scope'] = $scope;
		$record['access_mode'] = self::scope_allows_write( $scope ) ? 'read_write' : 'read_only';
		$record['updated_at'] = gmdate( 'c' );
		$sealed = self::seal_token_record( $record );
		if ( is_wp_error( $sealed ) ) return $sealed;
		self::write_option( self::TOKEN_OPTION, $sealed );
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
			'allowed_operations' => array( 'create', 'update', 'recreate' ),
			'delete_supported' => false,
			'trash_supported' => false,
			'blockers' => ! empty( $status['write_available'] ) ? array() : array( 'google_drive_write_scope_not_granted' ),
		);
	}

	private static function credentials() {
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
		if ( empty( $credentials['configured'] ) ) $blockers[] = 'google_oauth_credentials_missing';
		if ( ! is_array( $token ) || empty( $token['refresh_token'] ) ) $blockers[] = 'google_drive_not_connected';
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

	private static function suffix( $value ) {
		$value = (string) $value;
		return strlen( $value ) <= 8 ? $value : '…' . substr( $value, -8 );
	}

	private static function write_option( $name, $value ) {
		if ( false === get_option( $name, false ) ) return add_option( $name, $value, '', false );
		return update_option( $name, $value, false );
	}
}
