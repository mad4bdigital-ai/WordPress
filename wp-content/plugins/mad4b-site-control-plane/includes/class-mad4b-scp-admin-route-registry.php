<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Definition-only admin routes. WordPress still owns authentication and page permissions. */
final class MAD4B_SCP_Admin_Route_Registry {
	const CONTRACT = 'mad4b.admin-route-registry.v1';
	const PARENT_MENU_PRIORITY = 10;
	const SUBMENU_PRIORITY = 20;
	private static $routes = array();
	private static $aliases = array();
	private static $booted = false;

	/** Queue child menus after the parent, regardless of include/boot order. */
	public static function schedule_submenu( $callback, $priority = self::SUBMENU_PRIORITY ) {
		add_action( 'admin_menu', $callback, max( self::SUBMENU_PRIORITY, (int) $priority ) );
	}

	public static function register( $slug, $required_capability, $legacy_aliases = array(), $query_keys = array( 'tab', 'section', 'profile_id' ) ) {
		if ( ! is_string( $slug ) || ! preg_match( '/^mad4b-[a-z0-9-]{1,100}$/D', $slug )
			|| ! is_string( $required_capability ) || ! preg_match( '/^[a-z][a-z0-9_]{0,100}$/D', $required_capability ) ) return false;
		$aliases = array_merge( array( $slug ), is_array( $legacy_aliases ) ? $legacy_aliases : array() );
		foreach ( $aliases as $alias ) {
			if ( ! is_string( $alias ) || ! preg_match( '/^mad4b-[a-z0-9-]{1,100}$/D', $alias )
				|| isset( self::$aliases[ $alias ] ) && self::$aliases[ $alias ] !== $slug ) return false;
		}
		// A second declaration cannot silently change the required capability.
		if ( isset( self::$routes[ $slug ] ) && self::$routes[ $slug ]['required_capability'] !== $required_capability ) return false;
		$keys = array();
		foreach ( is_array( $query_keys ) ? array_slice( $query_keys, 0, 16 ) : array() as $key ) {
			if ( is_string( $key ) && preg_match( '/^[a-z][a-z0-9_]{0,63}$/D', $key )
				&& ! in_array( $key, array( 'page', 'action', 'redirect_to' ), true ) ) $keys[] = $key;
		}
		self::$routes[ $slug ] = array( 'slug' => $slug, 'required_capability' => $required_capability, 'legacy_aliases' => array_values( array_unique( $aliases ) ), 'query_keys' => array_values( array_unique( $keys ) ) );
		foreach ( $aliases as $alias ) self::$aliases[ $alias ] = $slug;
		return true;
	}

	public static function routes() {
		$result = self::$routes;
		foreach ( $result as $slug => &$row ) $row['canonical_admin_url'] = self::url( $slug );
		unset( $row );
		return $result;
	}

	public static function url( $slug ) {
		return isset( self::$routes[ $slug ] ) ? add_query_arg( 'page', $slug, admin_url( 'admin.php' ) ) : '';
	}

	/** A pure resolver: no menu materialization, provider discovery or persistent writes. */
	public static function resolve( $request_uri, $method ) {
		if ( ! in_array( strtoupper( (string) $method ), array( 'GET', 'HEAD' ), true ) || ! is_string( $request_uri )
			|| strlen( $request_uri ) > 4096 || '/' !== substr( $request_uri, 0, 1 ) || '//' === substr( $request_uri, 0, 2 ) ) return '';
		$parts = wp_parse_url( $request_uri );
		if ( ! is_array( $parts ) || isset( $parts['host'] ) || isset( $parts['scheme'] ) || isset( $parts['fragment'] ) ) return '';
		$path = isset( $parts['path'] ) ? $parts['path'] : '';
		$admin_path = wp_parse_url( admin_url(), PHP_URL_PATH );
		if ( ! is_string( $admin_path ) || '' === $admin_path || rawurldecode( $path ) !== $path || false !== strpos( $path, '\\' ) ) return '';
		$prefix = rtrim( $admin_path, '/' ) . '/';
		if ( 0 !== strpos( $path, $prefix ) ) return '';
		$alias = rtrim( substr( $path, strlen( $prefix ) ), '/' );
		if ( ! isset( self::$aliases[ $alias ] ) || ! in_array( $path, array( $prefix . $alias, $prefix . $alias . '/' ), true ) ) return '';
		$query = array();
		if ( isset( $parts['query'] ) ) parse_str( $parts['query'], $query );
		if ( isset( $query['action'] ) || isset( $query['_wpnonce'] ) || isset( $query['page'] ) || count( $query ) > 20 ) return '';
		$slug = self::$aliases[ $alias ];
		$keep = array();
		foreach ( self::$routes[ $slug ]['query_keys'] as $key ) {
			if ( isset( $query[ $key ] ) && is_string( $query[ $key ] ) && strlen( $query[ $key ] ) <= 256 ) $keep[ $key ] = $query[ $key ];
		}
		return $keep ? add_query_arg( $keep, self::url( $slug ) ) : self::url( $slug );
	}

	public static function boot() {
		if ( self::$booted ) return;
		self::$booted = true;
		add_action( 'template_redirect', array( __CLASS__, 'redirect_legacy_route' ), -1000 );
	}

	public static function redirect_legacy_route() {
		$uri = isset( $_SERVER['REQUEST_URI'] ) ? (string) $_SERVER['REQUEST_URI'] : '';
		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? (string) $_SERVER['REQUEST_METHOD'] : '';
		$url = self::resolve( $uri, $method );
		if ( '' !== $url && wp_safe_redirect( $url, 302, 'MAD4B Admin Routes' ) ) exit;
	}
}
