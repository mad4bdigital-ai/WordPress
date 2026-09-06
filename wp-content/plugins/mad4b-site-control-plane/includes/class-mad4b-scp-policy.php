<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class MAD4B_SCP_Policy {

	public static function can_read() {
		$capability = apply_filters( 'mad4b_scp_read_capability', 'manage_options' );
		return is_string( $capability ) && '' !== $capability && current_user_can( $capability );
	}

	public static function can_content() {
		return current_user_can( 'edit_posts' );
	}

	public static function can_admin() {
		return current_user_can( 'manage_options' );
	}

	public static function can_breakglass() {
		if ( ! defined( 'MAD4B_MCP_BREAKGLASS_ENABLED' ) || true !== MAD4B_MCP_BREAKGLASS_ENABLED ) return false;
		if ( ! current_user_can( 'manage_options' ) ) return false;
		// This is an independent approval gate. Enabling the constant alone is intentionally insufficient.
		return (bool) apply_filters( 'mad4b_mcp_breakglass_permission', false, get_current_user_id() );
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
		if ( ! isset( $roots[ $root_key ] ) ) return new WP_Error( 'mad4b_invalid_root', 'Unknown filesystem root.' );

		$relative_path = str_replace( '\\', '/', (string) $relative_path );
		if ( false !== strpos( $relative_path, "\0" ) ) return new WP_Error( 'mad4b_invalid_path', 'NUL bytes are not allowed in paths.' );
		$relative_path = ltrim( $relative_path, '/' );
		foreach ( explode( '/', $relative_path ) as $segment ) if ( '..' === $segment ) return new WP_Error( 'mad4b_path_escape', 'Parent directory traversal is not allowed.' );

		$root = realpath( $roots[ $root_key ] );
		if ( false === $root ) return new WP_Error( 'mad4b_root_missing', 'Configured filesystem root does not exist.' );
		$candidate = $root . ( '' === $relative_path ? '' : DIRECTORY_SEPARATOR . str_replace( '/', DIRECTORY_SEPARATOR, $relative_path ) );

		if ( $must_exist ) {
			$resolved = realpath( $candidate );
			if ( false === $resolved ) return new WP_Error( 'mad4b_path_missing', 'Requested path does not exist.' );
		} elseif ( file_exists( $candidate ) ) {
			$resolved = realpath( $candidate );
		} else {
			$parent = realpath( dirname( $candidate ) );
			if ( false === $parent ) return new WP_Error( 'mad4b_parent_missing', 'Parent directory does not exist.' );
			$resolved = $parent . DIRECTORY_SEPARATOR . basename( $candidate );
		}

		$normalized_root = rtrim( str_replace( '\\', '/', $root ), '/' );
		$normalized_path = str_replace( '\\', '/', $resolved );
		if ( $normalized_path !== $normalized_root && 0 !== strpos( $normalized_path, $normalized_root . '/' ) ) return new WP_Error( 'mad4b_path_escape', 'Resolved path escapes the allowed root.' );

		if ( self::is_sensitive_path( $normalized_path ) && ! apply_filters( 'mad4b_scp_allow_sensitive_file_access', false, $root_key, $normalized_path, get_current_user_id() ) ) {
			return new WP_Error( 'mad4b_sensitive_path_denied', 'Sensitive credential/configuration files are denied by default.' );
		}
		return $resolved;
	}

	public static function is_sensitive_path( $path ) {
		$normalized = strtolower( str_replace( '\\', '/', (string) $path ) );
		$basename = strtolower( basename( $normalized ) );
		$sensitive = false;

		if ( preg_match( '/^wp-config(?:\.php)?(?:[._~\-].*)?$/i', $basename ) ) $sensitive = true;
		if ( preg_match( '/^\.env(?:[._~\-].*)?$/i', $basename ) ) $sensitive = true;
		if ( false !== strpos( $normalized, '/.ssh/' ) || '/.ssh' === substr( $normalized, -5 ) ) $sensitive = true;
		if ( in_array( $basename, array( '.htpasswd', 'id_rsa', 'id_ed25519', 'credentials.json', 'service-account.json', 'auth.json', 'secrets.json' ), true ) ) $sensitive = true;
		if ( preg_match( '/\.(?:pem|key|p12|pfx|jks|keystore)$/i', $basename ) ) $sensitive = true;

		return (bool) apply_filters( 'mad4b_scp_sensitive_path', $sensitive, $normalized );
	}

	public static function is_code_or_server_config_path( $path ) {
		$normalized = strtolower( str_replace( '\\', '/', (string) $path ) );
		$basename = strtolower( basename( $normalized ) );
		if ( in_array( $basename, array( '.htaccess', '.user.ini', 'php.ini', 'web.config' ), true ) ) return true;
		return 1 === preg_match( '/\.(?:php\d*|phtml|phar|inc|cgi|pl|py|sh|bash|zsh|fish|js|mjs|cjs|html?|xhtml|shtml|svg)$/i', $basename );
	}

	public static function can_mutate_file( $root_key, $resolved_path ) {
		$normalized = str_replace( '\\', '/', (string) $resolved_path );
		if ( self::is_code_or_server_config_path( $normalized ) ) {
			return new WP_Error( 'mad4b_executable_file_mutation_denied', 'Executable code, browser-executable content, and server configuration cannot be mutated through the WordPress MCP control plane.' );
		}
		if ( self::is_sensitive_path( $normalized ) ) {
			return new WP_Error( 'mad4b_sensitive_file_mutation_denied', 'Sensitive credential/configuration files cannot be mutated through the normal WordPress MCP control plane.' );
		}

		$allowed_roots = apply_filters( 'mad4b_scp_mutable_data_roots', array( 'uploads' ) );
		if ( ! is_array( $allowed_roots ) || ! in_array( $root_key, $allowed_roots, true ) ) {
			return new WP_Error( 'mad4b_filesystem_mutation_root_denied', 'Filesystem mutation is limited to explicitly allowlisted non-code data roots. Source-code changes must use the governed repository/deployment path.' );
		}

		$extension = strtolower( pathinfo( $normalized, PATHINFO_EXTENSION ) );
		$allowed_extensions = apply_filters( 'mad4b_scp_mutable_data_extensions', array( 'txt', 'csv', 'json', 'xml', 'md', 'markdown', 'yaml', 'yml', 'po', 'pot' ) );
		if ( '' === $extension || ! is_array( $allowed_extensions ) || ! in_array( $extension, $allowed_extensions, true ) ) {
			return new WP_Error( 'mad4b_filesystem_mutation_type_denied', 'Filesystem mutation is limited to explicitly allowlisted non-executable data file types.' );
		}

		return true;
	}

	public static function plugin_lifecycle_allowed( $plugin, $operation ) {
		if ( ! defined( 'MAD4B_MCP_PLUGIN_LIFECYCLE_ENABLED' ) || true !== MAD4B_MCP_PLUGIN_LIFECYCLE_ENABLED ) return false;
		$allowed = apply_filters( 'mad4b_scp_plugin_lifecycle_allowlist', array(), $operation, get_current_user_id() );
		return is_array( $allowed ) && in_array( $plugin, $allowed, true );
	}

	public static function plugin_lifecycle_protected( $plugin ) {
		$protected = array( plugin_basename( MAD4B_SCP_FILE ), 'mcp-adapter/mcp-adapter.php' );
		$protected = apply_filters( 'mad4b_scp_plugin_lifecycle_protected_plugins', $protected, get_current_user_id() );
		return is_array( $protected ) && in_array( $plugin, $protected, true );
	}

	public static function backup_root() {
		$path = trailingslashit( get_temp_dir() ) . 'mad4b-scp-backups';
		return (string) apply_filters( 'mad4b_scp_backup_root', $path );
	}

	public static function prepare_backup_root() {
		$root = self::backup_root();
		if ( '' === $root ) return new WP_Error( 'mad4b_backup_root_missing', 'Backup root is not configured.' );
		if ( ! file_exists( $root ) && ! wp_mkdir_p( $root ) ) return new WP_Error( 'mad4b_backup_root_create_failed', 'Unable to create the protected backup directory.' );
		$resolved = realpath( $root );
		if ( false === $resolved || ! is_dir( $resolved ) || ! is_writable( $resolved ) ) return new WP_Error( 'mad4b_backup_root_unusable', 'Protected backup directory is not writable.' );

		$web_roots = array( ABSPATH, WP_CONTENT_DIR );
		foreach ( $web_roots as $web_root ) {
			$web = realpath( $web_root );
			if ( false === $web ) continue;
			$web = rtrim( str_replace( '\\', '/', $web ), '/' );
			$check = str_replace( '\\', '/', $resolved );
			if ( $check === $web || 0 === strpos( $check, $web . '/' ) ) return new WP_Error( 'mad4b_backup_root_web_exposed', 'Backup directory must be outside the WordPress web roots.' );
		}
		@chmod( $resolved, 0700 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		return $resolved;
	}

	public static function validate_identifier( $identifier ) {
		return is_string( $identifier ) && 1 === preg_match( '/^[A-Za-z0-9_$]+$/', $identifier );
	}

	public static function table_exists( $table ) {
		global $wpdb;
		if ( ! self::validate_identifier( $table ) ) return false;
		$tables = $wpdb->get_col( 'SHOW TABLES' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		return in_array( $table, $tables, true );
	}

	public static function is_sensitive_database_table( $table ) {
		global $wpdb;
		$sensitive = array_filter( array(
			isset( $wpdb->users ) ? $wpdb->users : null,
			isset( $wpdb->usermeta ) ? $wpdb->usermeta : null,
			isset( $wpdb->options ) ? $wpdb->options : null,
			isset( $wpdb->sitemeta ) ? $wpdb->sitemeta : null,
		) );
		$is_sensitive = in_array( (string) $table, $sensitive, true );
		if ( defined( 'MAD4B_SCP_Audit::OPTION' ) && isset( $wpdb->options ) && $table === $wpdb->options ) $is_sensitive = true;
		return (bool) apply_filters( 'mad4b_scp_sensitive_database_table', $is_sensitive, $table );
	}

	public static function is_sensitive_database_column( $column ) {
		$column = strtolower( (string) $column );
		$sensitive = (bool) preg_match( '/(?:pass(?:word)?|secret|token|api[_-]?key|consumer[_-]?key|access[_-]?key|client[_-]?key|license[_-]?key|auth|credential|private[_-]?key|activation[_-]?key|session|oauth|refresh[_-]?token|jwt|cookie|salt|nonce|verification[_-]?code)/i', $column );
		return (bool) apply_filters( 'mad4b_scp_sensitive_database_column', $sensitive, $column );
	}

	public static function can_structured_database_read( $table ) {
		if ( ! self::table_exists( $table ) ) return false;
		if ( self::is_sensitive_database_table( $table ) && ! apply_filters( 'mad4b_scp_allow_sensitive_database_read', false, $table, get_current_user_id() ) ) return false;
		return true;
	}

	public static function can_structured_database_write( $table ) {
		if ( ! self::table_exists( $table ) ) return false;
		if ( self::is_sensitive_database_table( $table ) && ! apply_filters( 'mad4b_scp_allow_sensitive_database_write', false, $table, get_current_user_id() ) ) return false;
		return true;
	}
}
