<?php

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Deterministic dependency inventory and explicit local dependency installer.
 *
 * Unknown or incompatible dependencies never create authority. The Control Plane
 * may boot its admin/read diagnostics without MCP Adapter so an administrator can
 * see exactly what is missing. Installation/repair is an explicit wp-admin action
 * and only consumes the certified archive bundled into the release package after
 * verifying its SHA-256 digest against certified-providers.json.
 */
final class MAD4B_SCP_Dependency_Manager {
	const CONTRACT = 'mad4b.dependency-status.v1';
	const MCP_PLUGIN_FILE = 'mcp-adapter/mcp-adapter.php';
	const BUNDLED_ARCHIVE = 'dependencies/mcp-adapter.zip';
	const ACTION = 'mad4b_install_certified_mcp_adapter';
	const NONCE = 'mad4b_install_certified_mcp_adapter';

	private static $booted = false;

	public static function boot() {
		if ( self::$booted ) return;
		self::$booted = true;
		add_action( 'wp_abilities_api_init', array( __CLASS__, 'register_ability' ), 7 );
		add_action( 'admin_notices', array( __CLASS__, 'admin_notice' ) );
		add_action( 'admin_post_' . self::ACTION, array( __CLASS__, 'handle_install' ) );
	}

	public static function register_ability() {
		if ( ! function_exists( 'wp_register_ability' ) || ( function_exists( 'wp_has_ability' ) && wp_has_ability( 'mad4b/dependency-status' ) ) ) return;
		wp_register_ability( 'mad4b/dependency-status', array(
			'label' => 'MAD4B Dependency Status',
			'description' => 'Inspect mandatory and optional runtime dependencies for this Control Plane installation.',
			'category' => 'mad4b-read',
			'execute_callback' => array( __CLASS__, 'status' ),
			'permission_callback' => array( 'MAD4B_SCP_Policy', 'can_read' ),
			'output_schema' => array( 'type' => 'object', 'additionalProperties' => true ),
			'meta' => array(
				'public' => false,
				'show_in_rest' => false,
				'mcp' => array( 'public' => false, 'type' => 'tool', 'surface' => 'read' ),
				'annotations' => array( 'readonly' => true, 'destructive' => false, 'idempotent' => true ),
			),
		) );
	}

	public static function status() {
		$certified = self::certified_mcp_adapter();
		$expected_version = isset( $certified['version'] ) ? (string) $certified['version'] : '';
		$expected_sha = isset( $certified['archive_sha256'] ) ? strtolower( (string) $certified['archive_sha256'] ) : '';
		$plugins = self::plugins();
		$installed = isset( $plugins[ self::MCP_PLUGIN_FILE ] ) && is_array( $plugins[ self::MCP_PLUGIN_FILE ] );
		$installed_version = $installed && isset( $plugins[ self::MCP_PLUGIN_FILE ]['Version'] ) ? trim( (string) $plugins[ self::MCP_PLUGIN_FILE ]['Version'] ) : '';
		$active = function_exists( 'is_plugin_active' ) ? ( is_plugin_active( self::MCP_PLUGIN_FILE ) || ( function_exists( 'is_plugin_active_for_network' ) && is_plugin_active_for_network( self::MCP_PLUGIN_FILE ) ) ) : class_exists( 'WP\\MCP\\Core\\McpAdapter' );
		$runtime_loaded = class_exists( 'WP\\MCP\\Core\\McpAdapter' );
		$version_match = '' !== $expected_version && '' !== $installed_version && hash_equals( $expected_version, $installed_version );
		$bundle = self::bundled_archive_status( $expected_sha );

		$wp_version = self::wordpress_version();
		$core = array(
			'wordpress' => array(
				'required' => '6.9',
				'observed' => $wp_version,
				'ready' => '' !== $wp_version && version_compare( $wp_version, '6.9', '>=' ),
			),
			'php' => array(
				'required' => '7.4',
				'observed' => PHP_VERSION,
				'ready' => version_compare( PHP_VERSION, '7.4', '>=' ),
			),
			'wordpress_abilities_api' => array(
				'ready' => function_exists( 'wp_register_ability' ),
			),
			'json' => array( 'ready' => function_exists( 'json_encode' ) && function_exists( 'json_decode' ) ),
			'hash_sha256' => array( 'ready' => function_exists( 'hash' ) && in_array( 'sha256', hash_algos(), true ) ),
			'openssl' => array( 'ready' => function_exists( 'openssl_pkey_new' ) && function_exists( 'openssl_sign' ) ),
		);

		$hard_blockers = array();
		foreach ( $core as $key => $check ) if ( empty( $check['ready'] ) ) $hard_blockers[] = 'dependency_' . sanitize_key( $key ) . '_unavailable';
		if ( ! $installed ) $hard_blockers[] = 'mcp_adapter_missing';
		elseif ( ! $version_match ) $hard_blockers[] = 'mcp_adapter_version_drift';
		elseif ( ! $active ) $hard_blockers[] = 'mcp_adapter_inactive';
		elseif ( ! $runtime_loaded ) $hard_blockers[] = 'mcp_adapter_runtime_unavailable';

		$oauth_enabled = class_exists( 'MAD4B_SCP_Site_Profile' ) && MAD4B_SCP_Site_Profile::oauth_enabled();
		$key_policy = class_exists( 'MAD4B_SCP_Local_OAuth_Key_Path_Policy' ) ? MAD4B_SCP_Local_OAuth_Key_Path_Policy::status() : array();
		$optional = array(
			'ziparchive_skill_export' => array(
				'ready' => class_exists( 'ZipArchive' ),
				'required_when' => 'portable_skill_export',
			),
			'local_oauth_private_key_path' => array(
				'ready' => ! $oauth_enabled || ( ! empty( $key_policy['effective'] ) || empty( $key_policy['configured'] ) ),
				'required_when' => 'local_oauth_enabled',
				'status' => $key_policy,
			),
			'https_remote_oauth' => array(
				'ready' => ! $oauth_enabled || 'local' === ( class_exists( 'MAD4B_SCP_Site_Profile' ) ? MAD4B_SCP_Site_Profile::current_environment() : '' ) || ( function_exists( 'is_ssl' ) && is_ssl() ),
				'required_when' => 'remote_oauth_enabled',
			),
		);
		$optional_blockers = array();
		foreach ( $optional as $key => $check ) if ( empty( $check['ready'] ) ) $optional_blockers[] = 'optional_' . sanitize_key( $key ) . '_unavailable';

		return array(
			'contract' => self::CONTRACT,
			'ready' => empty( $hard_blockers ),
			'state' => empty( $hard_blockers ) ? ( empty( $optional_blockers ) ? 'ready' : 'ready_with_optional_gaps' ) : 'blocked',
			'hard_blockers' => $hard_blockers,
			'optional_blockers' => $optional_blockers,
			'core' => $core,
			'mcp_adapter' => array(
				'plugin_file' => self::MCP_PLUGIN_FILE,
				'expected_version' => $expected_version,
				'expected_archive_sha256' => $expected_sha,
				'installed' => $installed,
				'installed_version' => $installed_version,
				'version_match' => $version_match,
				'active' => (bool) $active,
				'runtime_loaded' => $runtime_loaded,
				'bundled_archive' => $bundle,
				'install_requires_explicit_admin_action' => true,
				'auto_downloads_remote_code' => false,
				'network_activation_matches_control_plane' => ! ( function_exists( 'is_multisite' ) && is_multisite() ) || ! ( function_exists( 'is_plugin_active_for_network' ) && is_plugin_active_for_network( plugin_basename( MAD4B_SCP_FILE ) ) ) || ( function_exists( 'is_plugin_active_for_network' ) && is_plugin_active_for_network( self::MCP_PLUGIN_FILE ) ),
			),
			'optional' => $optional,
			'providers' => self::provider_dependency_summary(),
			'multisite' => array(
				'enabled' => function_exists( 'is_multisite' ) && is_multisite(),
				'network_activation_supported' => true,
				'per_site_profile_required' => true,
				'per_site_governance_schema' => true,
			),
		);
	}

	public static function admin_notice() {
		if ( ! is_admin() || ! current_user_can( 'manage_options' ) ) return;
		$status = self::status();
		if ( ! empty( $status['ready'] ) ) return;
		$mcp = isset( $status['mcp_adapter'] ) && is_array( $status['mcp_adapter'] ) ? $status['mcp_adapter'] : array();
		echo '<div class="notice notice-error"><p><strong>' . esc_html__( 'MAD4B Site Control Plane dependency check is blocked.', 'mad4b-site-control-plane' ) . '</strong> ' . esc_html( implode( ', ', isset( $status['hard_blockers'] ) ? $status['hard_blockers'] : array() ) ) . '</p>';
		if ( ! empty( $mcp['bundled_archive']['integrity_match'] ) ) {
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="margin:0 0 10px">';
			wp_nonce_field( self::NONCE );
			echo '<input type="hidden" name="action" value="' . esc_attr( self::ACTION ) . '">';
			echo '<button class="button button-primary" type="submit">' . esc_html__( 'Install/repair certified MCP Adapter', 'mad4b-site-control-plane' ) . '</button>';
			echo '</form>';
		} else {
			echo '<p>' . esc_html__( 'Install the exact MCP Adapter archive shipped with the MAD4B distribution bundle, then reload this page.', 'mad4b-site-control-plane' ) . '</p>';
		}
		echo '</div>';
	}

	public static function handle_install() {
		if ( ! current_user_can( 'manage_options' ) ) wp_die( esc_html__( 'Administrator capability is required to install dependencies.', 'mad4b-site-control-plane' ), '', array( 'response' => 403 ) );
		check_admin_referer( self::NONCE );
		$certified = self::certified_mcp_adapter();
		$expected_sha = isset( $certified['archive_sha256'] ) ? strtolower( (string) $certified['archive_sha256'] ) : '';
		$expected_version = isset( $certified['version'] ) ? (string) $certified['version'] : '';
		$bundle = self::bundled_archive_status( $expected_sha );
		if ( empty( $bundle['integrity_match'] ) ) self::redirect_result( 'bundle_invalid' );

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
		$network_control_plane = function_exists( 'is_multisite' ) && is_multisite() && function_exists( 'is_plugin_active_for_network' ) && is_plugin_active_for_network( plugin_basename( MAD4B_SCP_FILE ) );
		$was_network_active = function_exists( 'is_plugin_active_for_network' ) && is_plugin_active_for_network( self::MCP_PLUGIN_FILE );
		$was_site_active = is_plugin_active( self::MCP_PLUGIN_FILE );
		if ( $was_network_active || $was_site_active ) deactivate_plugins( self::MCP_PLUGIN_FILE, true, $was_network_active );

		$skin = new Automatic_Upgrader_Skin();
		$upgrader = new Plugin_Upgrader( $skin );
		$result = $upgrader->install( (string) $bundle['path'], array( 'overwrite_package' => true ) );
		if ( is_wp_error( $result ) || ! $result ) {
			if ( ( $was_network_active || $was_site_active ) && file_exists( WP_PLUGIN_DIR . '/' . self::MCP_PLUGIN_FILE ) ) activate_plugin( self::MCP_PLUGIN_FILE, '', $was_network_active );
			self::redirect_result( 'install_failed' );
		}
		$activation = activate_plugin( self::MCP_PLUGIN_FILE, '', $network_control_plane || $was_network_active );
		if ( is_wp_error( $activation ) ) self::redirect_result( 'activate_failed' );
		$plugins = self::plugins( true );
		$installed_version = isset( $plugins[ self::MCP_PLUGIN_FILE ]['Version'] ) ? (string) $plugins[ self::MCP_PLUGIN_FILE ]['Version'] : '';
		if ( '' === $expected_version || ! hash_equals( $expected_version, $installed_version ) ) self::redirect_result( 'version_mismatch' );
		self::redirect_result( 'success' );
	}

	private static function redirect_result( $state ) {
		$url = add_query_arg( array( 'page' => 'mad4b-control-plane', 'mad4b_dependency_action' => sanitize_key( (string) $state ) ), admin_url( 'admin.php' ) );
		wp_safe_redirect( $url );
		exit;
	}

	private static function certified_mcp_adapter() {
		$path = defined( 'MAD4B_SCP_DIR' ) ? MAD4B_SCP_DIR . 'config/certified-providers.json' : '';
		if ( '' === $path || ! is_readable( $path ) ) return array();
		$decoded = json_decode( (string) file_get_contents( $path ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		return is_array( $decoded ) && ! empty( $decoded['providers']['mcp_adapter'] ) && is_array( $decoded['providers']['mcp_adapter'] ) ? $decoded['providers']['mcp_adapter'] : array();
	}

	private static function bundled_archive_status( $expected_sha ) {
		$path = defined( 'MAD4B_SCP_DIR' ) ? MAD4B_SCP_DIR . self::BUNDLED_ARCHIVE : '';
		$present = '' !== $path && is_file( $path ) && is_readable( $path );
		$sha = $present ? strtolower( (string) hash_file( 'sha256', $path ) ) : '';
		return array(
			'path' => $present ? $path : '',
			'present' => $present,
			'sha256' => $sha,
			'integrity_match' => $present && 1 === preg_match( '/^[a-f0-9]{64}$/', $expected_sha ) && hash_equals( $expected_sha, $sha ),
		);
	}

	private static function plugins( $refresh = false ) {
		if ( ! function_exists( 'get_plugins' ) ) {
			$file = ABSPATH . 'wp-admin/includes/plugin.php';
			if ( is_readable( $file ) ) require_once $file;
		}
		if ( ! function_exists( 'get_plugins' ) ) return array();
		if ( $refresh && function_exists( 'wp_clean_plugins_cache' ) ) wp_clean_plugins_cache( true );
		$plugins = get_plugins();
		return is_array( $plugins ) ? $plugins : array();
	}

	private static function wordpress_version() {
		global $wp_version;
		if ( is_string( $wp_version ) && '' !== $wp_version ) return $wp_version;
		return function_exists( 'get_bloginfo' ) ? (string) get_bloginfo( 'version' ) : '';
	}

	private static function provider_dependency_summary() {
		$path = defined( 'MAD4B_SCP_DIR' ) ? MAD4B_SCP_DIR . 'config/certified-providers.json' : '';
		if ( '' === $path || ! is_readable( $path ) ) return array();
		$decoded = json_decode( (string) file_get_contents( $path ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$providers = is_array( $decoded ) && isset( $decoded['providers'] ) && is_array( $decoded['providers'] ) ? $decoded['providers'] : array();
		$plugins = self::plugins();
		$out = array();
		foreach ( $providers as $id => $provider ) {
			if ( 'mcp_adapter' === $id || ! is_array( $provider ) || empty( $provider['plugin_file'] ) ) continue;
			$file = (string) $provider['plugin_file'];
			$installed = isset( $plugins[ $file ] );
			$observed = $installed && isset( $plugins[ $file ]['Version'] ) ? (string) $plugins[ $file ]['Version'] : '';
			$expected = isset( $provider['version'] ) ? (string) $provider['version'] : '';
			$out[ sanitize_key( (string) $id ) ] = array(
				'required' => false,
				'installed' => $installed,
				'observed_version' => $observed,
				'certified_version' => $expected,
				'exact_version_match' => $installed && '' !== $expected && hash_equals( $expected, $observed ),
				'capability_level_recertification_supported' => true,
			);
		}
		return $out;
	}
}
