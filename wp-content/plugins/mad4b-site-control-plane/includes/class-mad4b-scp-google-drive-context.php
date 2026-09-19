<?php

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Read-only Google Drive source provider for Context Authority.
 *
 * No Google Drive mutation endpoints are implemented. Credentials/tokens are
 * encrypted at rest with a site-bound key derived from WordPress salts.
 */
final class MAD4B_SCP_Google_Drive_Context {
	const CONTRACT = 'mad4b.google-drive-context.v1';
	const CONFIG_OPTION = 'mad4b_scp_google_drive_oauth_config_v1';
	const TOKEN_OPTION = 'mad4b_scp_google_drive_oauth_token_v1';

	const AUTH_ENDPOINT = 'https://accounts.google.com/o/oauth2/v2/auth';
	const TOKEN_ENDPOINT = 'https://oauth2.googleapis.com/token';
	const DRIVE_API = 'https://www.googleapis.com/drive/v3';
	const DRIVE_SCOPE = 'https://www.googleapis.com/auth/drive.readonly';

	const MAX_SCAN_FILES = 500;
	const MAX_SCAN_FOLDERS = 120;
	const MAX_TEXT_BYTES = 262144;

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
			'scope' => self::DRIVE_SCOPE,
			'read_only' => true,
		);
	}

	public static function save_credentials( $client_id, $client_secret ) {
		if ( defined( 'MAD4B_GOOGLE_DRIVE_CLIENT_ID' ) || defined( 'MAD4B_GOOGLE_DRIVE_CLIENT_SECRET' ) ) {
			return new WP_Error( 'mad4b_google_drive_credentials_managed_by_constants', 'Google Drive credentials are managed by wp-config constants.' );
		}
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
			'scope' => self::DRIVE_SCOPE,
			'updated_at' => gmdate( 'c' ),
		);
		self::write_option( self::CONFIG_OPTION, $record );
		return self::credentials_status();
	}

	public static function connection_status() {
		$token = self::token_record();
		$credentials = self::credentials_status();
		$connected = is_array( $token ) && ! empty( $token['refresh_token'] );
		return array(
			'contract' => self::CONTRACT,
			'configured' => ! empty( $credentials['configured'] ),
			'connected' => $connected,
			'read_only' => true,
			'scope' => self::DRIVE_SCOPE,
			'account_email' => $connected && isset( $token['account_email'] ) ? sanitize_email( (string) $token['account_email'] ) : '',
			'account_name' => $connected && isset( $token['account_name'] ) ? sanitize_text_field( (string) $token['account_name'] ) : '',
			'permission_id' => $connected && isset( $token['permission_id'] ) ? sanitize_text_field( (string) $token['permission_id'] ) : '',
			'expires_at' => $connected && isset( $token['expires_at'] ) ? (int) $token['expires_at'] : 0,
			'token_healthy' => $connected && ( ! empty( $token['access_token'] ) || ! empty( $token['refresh_token'] ) ),
			'last_verified_at' => $connected && isset( $token['last_verified_at'] ) ? sanitize_text_field( (string) $token['last_verified_at'] ) : '',
			'blockers' => self::connection_blockers( $credentials, $token ),
		);
	}

	public static function authorization_url() {
		if ( ! current_user_can( 'manage_options' ) ) return new WP_Error( 'mad4b_google_drive_admin_required', 'Administrator capability is required to connect Google Drive.' );
		$credentials = self::credentials();
		if ( is_wp_error( $credentials ) ) return $credentials;
		$state = wp_generate_password( 64, false, false );
		if ( '' === $state ) return new WP_Error( 'mad4b_google_drive_state_generation_failed', 'Unable to generate OAuth state.' );
		set_transient(
			self::state_key( get_current_user_id() ),
			array(
				'state' => hash( 'sha256', $state ),
				'redirect_uri' => self::redirect_uri(),
				'created_at' => time(),
			),
			10 * MINUTE_IN_SECONDS
		);
		return add_query_arg(
			array(
				'client_id' => $credentials['client_id'],
				'redirect_uri' => self::redirect_uri(),
				'response_type' => 'code',
				'scope' => self::DRIVE_SCOPE,
				'access_type' => 'offline',
				'include_granted_scopes' => 'true',
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
		$refresh = isset( $tokens['refresh_token'] ) ? (string) $tokens['refresh_token'] : ( is_array( $existing ) && isset( $existing['refresh_token'] ) ? (string) $existing['refresh_token'] : '' );
		if ( '' === $refresh ) return new WP_Error( 'mad4b_google_drive_refresh_token_missing', 'Google did not return a refresh token. Reconnect and grant offline access.' );
		$record = self::persist_tokens(
			(string) $tokens['access_token'],
			$refresh,
			isset( $tokens['expires_in'] ) ? absint( $tokens['expires_in'] ) : 3600,
			isset( $tokens['scope'] ) ? (string) $tokens['scope'] : self::DRIVE_SCOPE
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
		delete_option( self::TOKEN_OPTION );
		return self::connection_status();
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
			isset( $tokens['scope'] ) ? (string) $tokens['scope'] : ( isset( $record['scope'] ) ? (string) $record['scope'] : self::DRIVE_SCOPE ),
			$record
		);
		if ( is_wp_error( $persisted ) ) return $persisted;
		return (string) $persisted['access_token'];
	}

	private static function persist_tokens( $access_token, $refresh_token, $expires_in, $scope, $existing = array() ) {
		$record = is_array( $existing ) ? $existing : array();
		$record['contract'] = self::CONTRACT;
		$record['access_token'] = trim( (string) $access_token );
		$record['refresh_token'] = trim( (string) $refresh_token );
		$record['expires_at'] = time() + max( 60, absint( $expires_in ) );
		$record['scope'] = trim( (string) $scope );
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

	private static function credentials() {
		if ( defined( 'MAD4B_GOOGLE_DRIVE_CLIENT_ID' ) && defined( 'MAD4B_GOOGLE_DRIVE_CLIENT_SECRET' ) ) {
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
