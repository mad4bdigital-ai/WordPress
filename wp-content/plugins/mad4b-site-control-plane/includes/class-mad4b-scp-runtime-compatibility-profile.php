<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Non-authorizing infrastructure compatibility profile for governed writes.
 *
 * Compatibility evidence can only constrain execution. It never creates a
 * grant, approval, OAuth scope, Production authority, or Breakglass authority.
 */
final class MAD4B_SCP_Runtime_Compatibility_Profile {
	const CONTRACT = 'mad4b.runtime-compatibility-profile.v1';
	private static $cache = null;

	private static $security_plugin_prefixes = array(
		'wordfence/',
		'sucuri-scanner/',
		'better-wp-security/',
		'solid-security/',
		'all-in-one-wp-security-and-firewall/',
		'ninjafirewall/',
	);

	public static function reset_request_cache() {
		self::$cache = null;
		return true;
	}

	public static function status( $refresh = false ) {
		if ( ! $refresh && is_array( self::$cache ) ) return self::$cache;

		$context = self::execution_context();
		$transport = class_exists( 'MAD4B_SCP_Transport_Context' ) ? MAD4B_SCP_Transport_Context::current_server_id() : '';
		$topology = class_exists( 'MAD4B_SCP_Database_Topology' )
			? MAD4B_SCP_Database_Topology::status( (bool) $refresh )
			: array( 'ready' => false, 'blockers' => array( 'database_topology_unavailable' ) );
		$object_cache = self::object_cache_status();
		$security = self::security_firewall_status();
		$maintenance = self::maintenance_status();
		$blockers = array();

		if ( ! is_array( $topology ) || empty( $topology['ready'] ) || empty( $topology['read_your_writes'] ) ) {
			$blockers[] = ! empty( $topology['database_dropin_present'] )
				? 'database_router_uncertified'
				: 'database_topology_not_write_safe';
		}
		if ( empty( $object_cache['ready'] ) ) $blockers[] = 'persistent_object_cache_contract_unverified';
		if ( ! empty( $maintenance['active'] ) ) $blockers[] = 'wordpress_maintenance_mode_active';
		if ( ! empty( $security['requires_certification'] ) ) $blockers[] = 'security_firewall_plugin_requires_certification';
		if ( 'wp_cli' === $context && '' !== $transport ) $blockers[] = 'remote_transport_wp_cli_context_conflict';
		if ( 'cron' === $context && '' !== $transport ) $blockers[] = 'remote_transport_cron_context_conflict';

		$stable = array(
			'execution_context' => $context,
			'remote_transport_server_id' => $transport,
			'object_cache' => array(
				'external' => ! empty( $object_cache['external'] ),
				'dropin_present' => ! empty( $object_cache['dropin_present'] ),
				'contract_verified' => ! empty( $object_cache['contract_verified'] ),
			),
			'database' => array(
				'mode' => isset( $topology['mode'] ) ? (string) $topology['mode'] : 'unknown',
				'database_dropin_present' => ! empty( $topology['database_dropin_present'] ),
				'read_your_writes' => ! empty( $topology['read_your_writes'] ),
				'server_fingerprint' => isset( $topology['server_fingerprint'] ) ? (string) $topology['server_fingerprint'] : '',
			),
			'security_firewall_plugins' => isset( $security['plugins'] ) ? $security['plugins'] : array(),
			'maintenance_active' => ! empty( $maintenance['active'] ),
		);
		$profile_sha = self::digest( $stable );
		$blockers = array_values( array_unique( $blockers ) );
		sort( $blockers, SORT_STRING );

		$status = array(
			'contract' => self::CONTRACT,
			'profile_sha256' => $profile_sha,
			'execution_context' => $context,
			'context_compatibility' => array(
				'web' => 'supported',
				'wp_cli' => '' === $transport ? 'supported_local_process' : 'conflict',
				'cron' => '' === $transport ? 'supported_internal_background' : 'conflict',
			),
			'remote_transport_server_id' => $transport,
			'object_cache' => $object_cache,
			'database_topology' => $topology,
			'security_firewall' => $security,
			'maintenance' => $maintenance,
			'blockers' => $blockers,
			'ready' => empty( $blockers ),
			'authorizing' => false,
			'mutation_performed' => false,
		);
		self::$cache = $status;
		return $status;
	}

	public static function assert_governed_write_ready( $refresh = true ) {
		$status = self::status( $refresh );
		if ( ! is_array( $status ) || empty( $status['ready'] ) ) {
			return new WP_Error(
				'mad4b_runtime_compatibility_not_ready',
				'Governed mutation is blocked by an uncertified or conflicting runtime infrastructure profile.',
				array(
					'blockers' => is_array( $status ) && isset( $status['blockers'] ) ? $status['blockers'] : array( 'runtime_compatibility_unavailable' ),
					'profile_sha256' => is_array( $status ) && isset( $status['profile_sha256'] ) ? (string) $status['profile_sha256'] : '',
					'blind_retry_allowed' => false,
					'authorizing' => false,
				)
			);
		}
		return $status;
	}

	private static function execution_context() {
		if ( defined( 'WP_CLI' ) && WP_CLI ) return 'wp_cli';
		if ( ( function_exists( 'wp_doing_cron' ) && wp_doing_cron() ) || ( defined( 'DOING_CRON' ) && DOING_CRON ) ) return 'cron';
		return 'web';
	}

	private static function object_cache_status() {
		$external = function_exists( 'wp_using_ext_object_cache' ) && wp_using_ext_object_cache();
		$dropin = defined( 'WP_CONTENT_DIR' ) && is_file( trailingslashit( WP_CONTENT_DIR ) . 'object-cache.php' );
		$verified = true;
		$probe_performed = false;
		if ( $external ) {
			$probe_performed = true;
			$verified = self::probe_object_cache();
		}
		return array(
			'external' => (bool) $external,
			'dropin_present' => (bool) $dropin,
			'contract_verified' => (bool) $verified,
			'probe_performed' => (bool) $probe_performed,
			'policy' => $external ? 'wordpress_object_cache_contract_roundtrip_required' : 'core_request_cache',
			'ready' => (bool) $verified,
		);
	}

	private static function probe_object_cache() {
		foreach ( array( 'wp_cache_set', 'wp_cache_get', 'wp_cache_delete' ) as $fn ) if ( ! function_exists( $fn ) ) return false;
		$key = 'probe_' . hash( 'sha256', self::CONTRACT . ':' . ( function_exists( 'getmypid' ) ? (string) getmypid() : '0' ) . ':' . microtime( true ) );
		$group = 'mad4b-runtime-compatibility';
		$value = hash( 'sha256', $key . ':value' );
		$set = wp_cache_set( $key, $value, $group, 30 );
		$found = null;
		$read = wp_cache_get( $key, $group, false, $found );
		$deleted = wp_cache_delete( $key, $group );
		return false !== $set && true === $found && is_string( $read ) && hash_equals( $value, $read ) && false !== $deleted;
	}

	private static function security_firewall_status() {
		$active = function_exists( 'get_option' ) ? get_option( 'active_plugins', array() ) : array();
		$active = is_array( $active ) ? array_values( array_map( 'strval', $active ) ) : array();
		if ( function_exists( 'is_multisite' ) && is_multisite() && function_exists( 'get_site_option' ) ) {
			$network = get_site_option( 'active_sitewide_plugins', array() );
			if ( is_array( $network ) ) $active = array_merge( $active, array_map( 'strval', array_keys( $network ) ) );
		}
		$matches = array();
		foreach ( array_values( array_unique( $active ) ) as $plugin ) {
			$normalized = strtolower( ltrim( str_replace( '\\', '/', (string) $plugin ), '/' ) );
			foreach ( self::$security_plugin_prefixes as $prefix ) {
				if ( 0 === strpos( $normalized, $prefix ) ) { $matches[] = $normalized; break; }
			}
		}
		$matches = array_values( array_unique( $matches ) );
		sort( $matches, SORT_STRING );
		return array(
			'plugins' => $matches,
			'requires_certification' => ! empty( $matches ),
			'policy' => 'known_security_or_firewall_plugin_requires_explicit_runtime_certification',
		);
	}

	private static function maintenance_status() {
		$file = defined( 'ABSPATH' ) ? trailingslashit( ABSPATH ) . '.maintenance' : '';
		$file_active = '' !== $file && is_file( $file );
		$wp_active = false;
		if ( function_exists( 'wp_is_maintenance_mode' ) ) {
			try { $wp_active = (bool) wp_is_maintenance_mode(); }
			catch ( Throwable $error ) { $wp_active = true; }
		}
		$installing = defined( 'WP_INSTALLING' ) && WP_INSTALLING;
		return array(
			'active' => $file_active || $wp_active || $installing,
			'file_present' => $file_active,
			'wordpress_reports_active' => $wp_active,
			'wp_installing' => $installing,
		);
	}

	private static function digest( $value ) {
		$json = function_exists( 'wp_json_encode' )
			? wp_json_encode( $value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE )
			: json_encode( $value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		return is_string( $json ) ? hash( 'sha256', $json ) : '';
	}
}
