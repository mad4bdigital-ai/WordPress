<?php

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Durable, site-local OAuth state for the standalone WordPress authorization server.
 *
 * Authorization codes and refresh tokens are never stored in plaintext. Only
 * SHA-256 token hashes and bounded binding metadata are persisted.
 */
final class MAD4B_SCP_Local_OAuth_Store {
	const VERSION = 1;
	const OPTION = 'mad4b_scp_local_oauth_store_version';

	public static function tables() {
		global $wpdb;
		return array(
			'codes' => $wpdb->prefix . 'mad4b_scp_oauth_codes',
			'refresh_tokens' => $wpdb->prefix . 'mad4b_scp_oauth_refresh_tokens',
		);
	}

	public static function install_or_upgrade() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$charset = $wpdb->get_charset_collate();
		$t = self::tables();

		$sql = array();
		$sql[] = "CREATE TABLE {$t['codes']} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			code_hash char(64) NOT NULL,
			client_id varchar(191) NOT NULL,
			wp_user_id bigint(20) unsigned NOT NULL,
			redirect_uri text NOT NULL,
			resource text NOT NULL,
			scope text NOT NULL,
			code_challenge varchar(128) NOT NULL,
			expires_at datetime NOT NULL,
			used_at datetime NULL,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY code_hash (code_hash),
			KEY client_created (client_id,created_at),
			KEY expiry (expires_at)
		) $charset;";

		$sql[] = "CREATE TABLE {$t['refresh_tokens']} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			token_hash char(64) NOT NULL,
			family_id char(36) NOT NULL,
			client_id varchar(191) NOT NULL,
			wp_user_id bigint(20) unsigned NOT NULL,
			resource text NOT NULL,
			scope text NOT NULL,
			expires_at datetime NOT NULL,
			used_at datetime NULL,
			revoked_at datetime NULL,
			replacement_hash char(64) NOT NULL DEFAULT '',
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY token_hash (token_hash),
			KEY family_id (family_id),
			KEY client_created (client_id,created_at),
			KEY expiry (expires_at)
		) $charset;";

		foreach ( $sql as $statement ) dbDelta( $statement );
		if ( ! self::is_ready() ) {
			return new WP_Error( 'mad4b_local_oauth_store_unavailable', 'Local OAuth store is incomplete after migration.' );
		}
		update_option( self::OPTION, self::VERSION, false );
		return true;
	}

	public static function is_ready() {
		global $wpdb;
		foreach ( self::tables() as $table ) {
			$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			if ( $found !== $table ) return false;
		}
		return true;
	}

	public static function status() {
		return array(
			'expected_version' => self::VERSION,
			'installed_version' => (int) get_option( self::OPTION, 0 ),
			'ready' => self::is_ready(),
			'tables' => self::tables(),
			'plaintext_authorization_codes_stored' => false,
			'plaintext_refresh_tokens_stored' => false,
		);
	}

	public static function insert_code( array $record ) {
		global $wpdb;
		$t = self::tables();
		$inserted = $wpdb->insert(
			$t['codes'],
			array(
				'code_hash' => (string) $record['code_hash'],
				'client_id' => (string) $record['client_id'],
				'wp_user_id' => (int) $record['wp_user_id'],
				'redirect_uri' => (string) $record['redirect_uri'],
				'resource' => (string) $record['resource'],
				'scope' => (string) $record['scope'],
				'code_challenge' => (string) $record['code_challenge'],
				'expires_at' => (string) $record['expires_at'],
				'created_at' => (string) $record['created_at'],
			),
			array( '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s' )
		); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		return false !== $inserted;
	}

	public static function get_code( $code_hash ) {
		global $wpdb;
		$t = self::tables();
		return $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$t['codes']} WHERE code_hash = %s LIMIT 1", (string) $code_hash ),
			ARRAY_A
		); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	public static function mark_code_used( $id, $used_at ) {
		global $wpdb;
		$t = self::tables();
		$updated = $wpdb->query(
			$wpdb->prepare( "UPDATE {$t['codes']} SET used_at = %s WHERE id = %d AND used_at IS NULL", (string) $used_at, (int) $id )
		); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return 1 === (int) $updated;
	}

	public static function insert_refresh_token( array $record ) {
		global $wpdb;
		$t = self::tables();
		$inserted = $wpdb->insert(
			$t['refresh_tokens'],
			array(
				'token_hash' => (string) $record['token_hash'],
				'family_id' => (string) $record['family_id'],
				'client_id' => (string) $record['client_id'],
				'wp_user_id' => (int) $record['wp_user_id'],
				'resource' => (string) $record['resource'],
				'scope' => (string) $record['scope'],
				'expires_at' => (string) $record['expires_at'],
				'created_at' => (string) $record['created_at'],
			),
			array( '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s' )
		); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		return false !== $inserted;
	}

	public static function get_refresh_token( $token_hash ) {
		global $wpdb;
		$t = self::tables();
		return $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$t['refresh_tokens']} WHERE token_hash = %s LIMIT 1", (string) $token_hash ),
			ARRAY_A
		); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	public static function rotate_refresh_token( $id, $used_at, $replacement_hash ) {
		global $wpdb;
		$t = self::tables();
		$updated = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$t['refresh_tokens']} SET used_at = %s, replacement_hash = %s WHERE id = %d AND used_at IS NULL AND revoked_at IS NULL",
				(string) $used_at,
				(string) $replacement_hash,
				(int) $id
			)
		); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return 1 === (int) $updated;
	}

	public static function revoke_family( $family_id, $revoked_at ) {
		global $wpdb;
		$t = self::tables();
		return $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$t['refresh_tokens']} SET revoked_at = %s WHERE family_id = %s AND revoked_at IS NULL",
				(string) $revoked_at,
				(string) $family_id
			)
		); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}
}
