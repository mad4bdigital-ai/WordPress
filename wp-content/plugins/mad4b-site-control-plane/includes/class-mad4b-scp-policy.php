<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class MAD4B_SCP_Policy {

	public static function can_read() {
		return current_user_can( 'read' );
	}

	public static function can_content() {
		return current_user_can( 'edit_posts' );
	}

	public static function can_admin() {
		return current_user_can( 'manage_options' );
	}

	public static function can_breakglass() {
		if ( ! defined( 'MAD4B_MCP_BREAKGLASS_ENABLED' ) || true !== MAD4B_MCP_BREAKGLASS_ENABLED ) {
			return false;
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			return false;
		}

		return (bool) apply_filters( 'mad4b_mcp_breakglass_permission', true, get_current_user_id() );
	}

	public static function roots() {
		$uploads = wp_upload_dir( null, false );

		$roots = array(
			'wordpress' => ABSPATH,
			'content'   => WP_CONTENT_DIR,
			'plugins'   => WP_PLUGIN_DIR,
			'themes'    => get_theme_root(),
			'uploads'   => isset( $uploads['basedir'] ) ? $uploads['basedir'] : WP_CONTENT_DIR . '/uploads',
		);

		return apply_filters( 'mad4b_scp_filesystem_roots', $roots );
	}

	public static function resolve_path( $root_key, $relative_path = '', $must_exist = true ) {
		$roots = self::roots();
		if ( ! isset( $roots[ $root_key ] ) ) {
			return new WP_Error( 'mad4b_invalid_root', 'Unknown filesystem root.' );
		}

		$relative_path = str_replace( '\\', '/', (string) $relative_path );
		if ( false !== strpos( $relative_path, "\0" ) ) {
			return new WP_Error( 'mad4b_invalid_path', 'NUL bytes are not allowed in paths.' );
		}
		$relative_path = ltrim( $relative_path, '/' );

		foreach ( explode( '/', $relative_path ) as $segment ) {
			if ( '..' === $segment ) {
				return new WP_Error( 'mad4b_path_escape', 'Parent directory traversal is not allowed.' );
			}
		}

		$root = realpath( $roots[ $root_key ] );
		if ( false === $root ) {
			return new WP_Error( 'mad4b_root_missing', 'Configured filesystem root does not exist.' );
		}

		$candidate = $root . ( '' === $relative_path ? '' : DIRECTORY_SEPARATOR . str_replace( '/', DIRECTORY_SEPARATOR, $relative_path ) );

		if ( $must_exist ) {
			$resolved = realpath( $candidate );
			if ( false === $resolved ) {
				return new WP_Error( 'mad4b_path_missing', 'Requested path does not exist.' );
			}
		} else {
			if ( file_exists( $candidate ) ) {
				$resolved = realpath( $candidate );
			} else {
				$parent = realpath( dirname( $candidate ) );
				if ( false === $parent ) {
					return new WP_Error( 'mad4b_parent_missing', 'Parent directory does not exist.' );
				}
				$resolved = $parent . DIRECTORY_SEPARATOR . basename( $candidate );
			}
		}

		$normalized_root = rtrim( str_replace( '\\', '/', $root ), '/' );
		$normalized_path = str_replace( '\\', '/', $resolved );

		if ( $normalized_path !== $normalized_root && 0 !== strpos( $normalized_path, $normalized_root . '/' ) ) {
			return new WP_Error( 'mad4b_path_escape', 'Resolved path escapes the allowed root.' );
		}

		return $resolved;
	}

	public static function validate_identifier( $identifier ) {
		return is_string( $identifier ) && 1 === preg_match( '/^[A-Za-z0-9_$]+$/', $identifier );
	}

	public static function table_exists( $table ) {
		global $wpdb;

		if ( ! self::validate_identifier( $table ) ) {
			return false;
		}

		$tables = $wpdb->get_col( 'SHOW TABLES' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		return in_array( $table, $tables, true );
	}
}
