<?php

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Proves the Local OAuth private key is outside both WordPress and the HTTP
 * document root. This matters when WordPress itself lives in a subdirectory:
 * dirname(ABSPATH) can still be web-addressable in that topology.
 */
final class MAD4B_SCP_Local_OAuth_Key_Path_Policy {
	const CONTRACT = 'mad4b.local-oauth-key-path-policy.v3';
	private static $booted = false;
	private static $error = null;
	private static $selected_path = '';

	public static function boot() {
		if ( self::$booted ) return;
		self::$booted = true;
		if ( ! self::enabled() ) return;

		if ( ! defined( 'MAD4B_MCP_LOCAL_OAUTH_PRIVATE_KEY_PATH' ) ) {
			$default = self::safe_default_path();
			if ( is_wp_error( $default ) ) {
				self::$error = $default;
			} else {
				define( 'MAD4B_MCP_LOCAL_OAUTH_PRIVATE_KEY_PATH', $default );
				self::$selected_path = $default;
			}
		} else {
			$path = trim( (string) constant( 'MAD4B_MCP_LOCAL_OAUTH_PRIVATE_KEY_PATH' ) );
			$valid = self::validate_path_against_roots( $path, ABSPATH, self::document_root() );
			if ( is_wp_error( $valid ) ) self::$error = $valid;
			else self::$selected_path = self::canonical_candidate_path( $path );
		}

		add_action( 'init', array( __CLASS__, 'enforce_runtime' ), 0 );
		add_action( 'parse_request', array( __CLASS__, 'block_unsafe_protocol' ), -20 );
		add_filter( 'pre_http_request', array( __CLASS__, 'block_unsafe_local_discovery' ), 0, 3 );
		if ( is_wp_error( self::$error ) ) add_action( 'admin_notices', array( __CLASS__, 'admin_notice' ) );
	}

	public static function enforce_runtime() {
		if ( ! is_wp_error( self::$error ) ) return;
		remove_action( 'init', array( 'MAD4B_SCP_Local_OAuth_Server', 'ensure_runtime' ), 1 );
	}

	public static function admin_notice() {
		if ( ! is_wp_error( self::$error ) || ! current_user_can( 'manage_options' ) ) return;
		$message = sprintf(
			/* translators: %s is a bounded internal error code, never a filesystem path. */
			__( 'MAD4B Local OAuth is blocked because the private signing-key path is not proven outside the WordPress/HTTP document roots. Configure a safe absolute path outside the web root. Blocker: %s', 'mad4b-site-control-plane' ),
			sanitize_key( self::$error->get_error_code() )
		);
		echo '<div class="notice notice-error"><p>' . esc_html( $message ) . '</p></div>';
	}

	public static function transport_ready() {
		if ( ! self::enabled() ) return true;
		$status = self::status();
		return ! empty( $status['effective'] );
	}

	public static function block_unsafe_protocol() {
		if ( ! is_wp_error( self::$error ) ) return;
		$uri = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '';
		$path = wp_parse_url( $uri, PHP_URL_PATH );
		if ( ! is_string( $path ) ) return;
		$path = rtrim( '/' . ltrim( $path, '/' ), '/' );
		$known = array( '/oauth/mcp/authorize', '/oauth/mcp/token', '/oauth/mcp/jwks', '/oauth/mcp/revoke' );
		if ( class_exists( 'MAD4B_SCP_Local_OAuth_Server' ) ) {
			$metadata_path = wp_parse_url( MAD4B_SCP_Local_OAuth_Server::metadata_url(), PHP_URL_PATH );
			if ( is_string( $metadata_path ) && '' !== $metadata_path ) $known[] = rtrim( '/' . ltrim( $metadata_path, '/' ), '/' );
		}
		if ( ! in_array( $path, array_values( array_unique( $known ) ), true ) ) return;
		nocache_headers();
		status_header( 503 );
		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Cache-Control: no-store' );
		echo wp_json_encode( array( 'error' => 'mad4b_local_oauth_private_key_path_unsafe', 'contract' => self::CONTRACT ) );
		exit;
	}

	public static function block_unsafe_local_discovery( $preempt, $args, $url ) {
		if ( ! is_wp_error( self::$error ) || ! class_exists( 'MAD4B_SCP_Local_OAuth_Server' ) ) return $preempt;
		$url = untrailingslashit( (string) $url );
		foreach ( array( MAD4B_SCP_Local_OAuth_Server::metadata_url(), MAD4B_SCP_Local_OAuth_Server::jwks_url() ) as $local_url ) {
			if ( hash_equals( untrailingslashit( $local_url ), $url ) ) return new WP_Error( 'mad4b_local_oauth_private_key_path_unsafe', 'Local OAuth private-key path is not proven outside the web document root.' );
		}
		return $preempt;
	}

	public static function status() {
		$document_root = self::document_root();
		return array(
			'contract' => self::CONTRACT,
			'configured' => self::enabled(),
			'effective' => self::enabled() && ! is_wp_error( self::$error ) && '' !== self::$selected_path,
			'path_selected' => '' !== self::$selected_path,
			'outside_wordpress_root' => '' !== self::$selected_path && ! self::path_within( self::$selected_path, ABSPATH ),
			'document_root_available' => '' !== $document_root,
			'outside_document_root' => '' === $document_root || ( '' !== self::$selected_path && ! self::path_within( self::$selected_path, $document_root ) ),
			'canonical_path_checks' => true,
			'symlink_ancestor_resolution' => true,
			'operator_blocker_visible' => is_wp_error( self::$error ),
			'error' => is_wp_error( self::$error ) ? self::$error->get_error_code() : '',
			'key_material_exposed' => false,
		);
	}

	public static function validate_path_against_roots( $path, $wordpress_root, $document_root = '' ) {
		$path = self::canonical_candidate_path( trim( (string) $path ) );
		$wordpress_root = self::canonical_candidate_path( trim( (string) $wordpress_root ) );
		$document_root = '' === trim( (string) $document_root ) ? '' : self::canonical_candidate_path( trim( (string) $document_root ) );
		if ( '' === $path || ! self::absolute_path( $path ) ) return new WP_Error( 'mad4b_local_oauth_key_path_invalid', 'Local OAuth private-key path must be absolute.' );
		if ( '' === $wordpress_root || self::path_within( $path, $wordpress_root ) ) return new WP_Error( 'mad4b_local_oauth_key_path_wordpress_exposed', 'Local OAuth private-key path must be outside the WordPress root.' );
		if ( '' !== $document_root && self::path_within( $path, $document_root ) ) return new WP_Error( 'mad4b_local_oauth_key_path_document_root_exposed', 'Local OAuth private-key path must be outside the HTTP document root.' );
		return true;
	}

	public static function safe_default_path_for_roots( $wordpress_root, $document_root = '' ) {
		$wordpress_root = self::canonical_candidate_path( rtrim( (string) $wordpress_root, '/\\' ) );
		$document_root = '' === trim( (string) $document_root ) ? '' : self::canonical_candidate_path( rtrim( (string) $document_root, '/\\' ) );
		if ( '' !== $document_root && self::absolute_path( $document_root ) ) {
			$base = dirname( $document_root );
		} elseif ( 'cli' === PHP_SAPI || 'phpdbg' === PHP_SAPI ) {
			// WP-CLI has no reliable HTTP document root; retain the historical
			// outside-ABSPATH location for non-web bootstrap only.
			$base = dirname( $wordpress_root );
		} else {
			return new WP_Error( 'mad4b_local_oauth_document_root_unknown', 'HTTP document root is unavailable; configure an explicit private-key path outside the web root.' );
		}
		$path = self::canonical_candidate_path( trailingslashit( $base ) . '.mad4b/oauth/wordpress-local-rs256-private.pem' );
		$valid = self::validate_path_against_roots( $path, $wordpress_root, $document_root );
		return is_wp_error( $valid ) ? $valid : $path;
	}

	private static function safe_default_path() {
		return self::safe_default_path_for_roots( ABSPATH, self::document_root() );
	}

	private static function document_root() {
		$root = isset( $_SERVER['DOCUMENT_ROOT'] ) ? trim( (string) wp_unslash( $_SERVER['DOCUMENT_ROOT'] ) ) : '';
		if ( '' === $root || ! self::absolute_path( $root ) ) return '';
		return self::canonical_candidate_path( $root );
	}

	private static function canonical_candidate_path( $path ) {
		$path = self::collapse_path( wp_normalize_path( trim( (string) $path ) ) );
		if ( '' === $path || ! self::absolute_path( $path ) ) return $path;
		if ( file_exists( $path ) ) {
			$real = realpath( $path );
			if ( false !== $real ) return self::collapse_path( wp_normalize_path( $real ) );
		}

		$basename = basename( $path );
		$cursor = dirname( $path );
		$suffix = array();
		while ( '' !== $cursor && ! file_exists( $cursor ) ) {
			$parent = dirname( $cursor );
			if ( $parent === $cursor ) break;
			array_unshift( $suffix, basename( $cursor ) );
			$cursor = $parent;
		}
		$real_base = '' !== $cursor ? realpath( $cursor ) : false;
		if ( false === $real_base ) return $path;
		$resolved = wp_normalize_path( $real_base );
		foreach ( $suffix as $segment ) $resolved = trailingslashit( $resolved ) . $segment;
		return self::collapse_path( trailingslashit( $resolved ) . $basename );
	}

	private static function collapse_path( $path ) {
		$path = wp_normalize_path( (string) $path );
		if ( '' === $path ) return '';
		$drive = '';
		$absolute = 0 === strpos( $path, '/' );
		if ( preg_match( '#^([A-Za-z]:)(/.*)?$#', $path, $matches ) ) {
			$drive = strtoupper( $matches[1] );
			$path = isset( $matches[2] ) ? $matches[2] : '/';
			$absolute = true;
		}
		$parts = array();
		foreach ( explode( '/', $path ) as $segment ) {
			if ( '' === $segment || '.' === $segment ) continue;
			if ( '..' === $segment ) {
				if ( ! empty( $parts ) ) array_pop( $parts );
				continue;
			}
			$parts[] = $segment;
		}
		$prefix = '' !== $drive ? $drive . '/' : ( $absolute ? '/' : '' );
		return $prefix . implode( '/', $parts );
	}

	private static function path_within( $path, $root ) {
		$path = self::canonical_candidate_path( $path );
		$root = trailingslashit( self::canonical_candidate_path( rtrim( (string) $root, '/\\' ) ) );
		if ( '' === $path || '/' === $root ) return '/' === $root && 0 === strpos( $path, '/' );
		if ( preg_match( '#^[A-Za-z]:/#', $path ) && preg_match( '#^[A-Za-z]:/#', $root ) ) {
			$path = strtolower( $path );
			$root = strtolower( $root );
		}
		return 0 === strpos( $path, $root );
	}

	private static function absolute_path( $path ) {
		return 1 === preg_match( '#^(?:[A-Za-z]:[\\\\/]|/)#', (string) $path );
	}

	private static function enabled() {
		return defined( 'MAD4B_MCP_LOCAL_OAUTH_ENABLED' ) && true === constant( 'MAD4B_MCP_LOCAL_OAUTH_ENABLED' );
	}
}
