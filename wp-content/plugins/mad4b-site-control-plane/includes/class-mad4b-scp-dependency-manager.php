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
		$mcp_plugin_identity = self::resolve_mcp_plugin_file( $plugins );
		$mcp_plugin_file = isset( $mcp_plugin_identity['plugin_file'] ) ? (string) $mcp_plugin_identity['plugin_file'] : '';
		$plugin_identity_ambiguous = ! empty( $mcp_plugin_identity['ambiguous'] );
		$installed = '' !== $mcp_plugin_file && isset( $plugins[ $mcp_plugin_file ] ) && is_array( $plugins[ $mcp_plugin_file ] );
		$installed_version = $installed && isset( $plugins[ $mcp_plugin_file ]['Version'] ) ? trim( (string) $plugins[ $mcp_plugin_file ]['Version'] ) : '';
		$multisite_enabled = function_exists( 'is_multisite' ) && is_multisite();
		$network_control_plane = $multisite_enabled && function_exists( 'is_plugin_active_for_network' ) && is_plugin_active_for_network( plugin_basename( MAD4B_SCP_FILE ) );
		$network_mcp_adapter = $multisite_enabled && '' !== $mcp_plugin_file && function_exists( 'is_plugin_active_for_network' ) && is_plugin_active_for_network( $mcp_plugin_file );
		$active = function_exists( 'is_plugin_active' ) && '' !== $mcp_plugin_file ? ( is_plugin_active( $mcp_plugin_file ) || $network_mcp_adapter ) : class_exists( 'WP\\MCP\\Core\\McpAdapter' );
		$runtime_loaded = class_exists( 'WP\\MCP\\Core\\McpAdapter' );
		$runtime_contract = class_exists( 'MAD4B_SCP_Provider_Contracts' ) && method_exists( 'MAD4B_SCP_Provider_Contracts', 'runtime_status' )
			? MAD4B_SCP_Provider_Contracts::runtime_status( 'mcp_adapter', $runtime_loaded )
			: array();
		$runtime_provenance = self::mcp_runtime_provenance( $mcp_plugin_file );
		$runtime_from_official_plugin = ! empty( $runtime_provenance['runtime_from_official_plugin'] );
		$runtime_contract_ok = ! empty( $runtime_contract['runtime_contract_ok'] );
		$runtime_certified = $runtime_contract_ok && $runtime_from_official_plugin;
		$version_match = '' !== $expected_version && '' !== $installed_version && hash_equals( $expected_version, $installed_version );
		$bundle = self::bundled_archive_status( $expected_sha );
		$installed_integrity = $installed ? self::installed_mcp_adapter_integrity( $certified, $mcp_plugin_file ) : array(
			'contract' => 'mad4b.mcp-adapter-installed-integrity.v1',
			'ready' => false,
			'state' => 'not_installed',
			'expected_count' => 0,
			'verified_count' => 0,
			'mismatch_count' => 0,
			'mismatches' => array(),
		);
		$installed_integrity_ready = ! empty( $installed_integrity['ready'] );

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
		if ( $network_control_plane || $network_mcp_adapter ) $hard_blockers[] = 'multisite_network_activation_unsupported';
		if ( $plugin_identity_ambiguous ) $hard_blockers[] = 'mcp_adapter_plugin_identity_ambiguous';
		elseif ( ! $installed ) $hard_blockers[] = 'mcp_adapter_missing';
		elseif ( ! $version_match ) $hard_blockers[] = 'mcp_adapter_version_drift';
		elseif ( ! $installed_integrity_ready ) $hard_blockers[] = 'mcp_adapter_integrity_mismatch';
		elseif ( ! $runtime_loaded ) $hard_blockers[] = 'mcp_adapter_runtime_unavailable';
		elseif ( ! $runtime_from_official_plugin ) $hard_blockers[] = 'mcp_adapter_runtime_provenance_mismatch';
		elseif ( ! $runtime_contract_ok ) $hard_blockers[] = 'mcp_adapter_runtime_not_certified';

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
				'plugin_file' => $mcp_plugin_file,
				'preferred_plugin_file' => self::MCP_PLUGIN_FILE,
				'plugin_identity_ambiguous' => $plugin_identity_ambiguous,
				'plugin_directory_renamed' => '' !== $mcp_plugin_file && ! hash_equals( self::MCP_PLUGIN_FILE, $mcp_plugin_file ),
				'expected_version' => $expected_version,
				'expected_archive_sha256' => $expected_sha,
				'installed' => $installed,
				'installed_version' => $installed_version,
				'version_match' => $version_match,
				'installed_critical_integrity' => $installed_integrity,
				'active' => (bool) $active,
				'runtime_loaded' => $runtime_loaded,
				'runtime_contract_ok' => $runtime_contract_ok,
				'runtime_certified' => $runtime_certified,
				'runtime_from_official_plugin' => $runtime_from_official_plugin,
				'runtime_source' => isset( $runtime_provenance['runtime_source'] ) ? (string) $runtime_provenance['runtime_source'] : '',
				'loading_mode' => $active ? 'active_plugin' : ( $runtime_certified ? 'certified_loaded_runtime' : 'inactive_plugin' ),
				'runtime_contract' => $runtime_contract,
				'runtime_provenance' => $runtime_provenance,
				'bundled_archive' => $bundle,
				'install_requires_explicit_admin_action' => true,
				'auto_downloads_remote_code' => false,
				'network_activation_matches_control_plane' => ! $network_control_plane || $network_mcp_adapter,
				'network_activation_supported' => false,
			),
			'optional' => $optional,
			'providers' => self::provider_dependency_summary(),
			'multisite' => array(
				'enabled' => $multisite_enabled,
				'network_activation_supported' => false,
				'site_scoped_activation_supported' => true,
				'network_control_plane_active' => $network_control_plane,
				'network_mcp_adapter_active' => $network_mcp_adapter,
				'authority_scope' => 'site',
				'per_site_profile_required' => true,
				'per_site_governance_schema' => true,
			),
		);
	}

	public static function admin_notice() {
		if ( ! is_admin() || ! current_user_can( 'manage_options' ) ) return;
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( (string) $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only notice routing.
		// Dependency inventory hashes archives and reads the plugin catalog. Never
		// perform that work while rendering WPML or any third-party wp-admin page.
		if ( 0 !== strpos( $page, 'mad4b-control-plane' ) ) return;
		// Connection and ChatGPT are latency-sensitive status surfaces. Their own
		// readiness projections report Adapter/transport blockers without re-running
		// deep dependency integrity, archive hashing or provider inventory in the
		// global admin-notice phase before the page emits its first byte.
		if ( class_exists( 'MAD4B_SCP_MCP_Request_Scope', false )
			&& MAD4B_SCP_MCP_Request_Scope::current_request_is_passive_admin_hotpath() ) return;
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
		$plugins_before = self::plugins( true );
		$mcp_plugin_identity = self::resolve_mcp_plugin_file( $plugins_before );
		$mcp_plugin_file = isset( $mcp_plugin_identity['plugin_file'] ) ? (string) $mcp_plugin_identity['plugin_file'] : '';
		if ( ! empty( $mcp_plugin_identity['ambiguous'] ) ) self::redirect_result( 'mcp_adapter_plugin_identity_ambiguous' );
		$network_control_plane = function_exists( 'is_multisite' ) && is_multisite() && function_exists( 'is_plugin_active_for_network' ) && is_plugin_active_for_network( plugin_basename( MAD4B_SCP_FILE ) );
		$was_network_active = '' !== $mcp_plugin_file && function_exists( 'is_plugin_active_for_network' ) && is_plugin_active_for_network( $mcp_plugin_file );
		$was_site_active = '' !== $mcp_plugin_file && is_plugin_active( $mcp_plugin_file );
		if ( '' !== $mcp_plugin_file && ! hash_equals( self::MCP_PLUGIN_FILE, $mcp_plugin_file ) ) {
			self::redirect_result( 'renamed_mcp_adapter_repair_requires_manual_normalization' );
		}
		// Network-wide mutation would affect sites whose Site Profile/authority was
		// not reviewed by this request. Keep repair site-scoped and fail closed.
		if ( $network_control_plane || $was_network_active ) self::redirect_result( 'multisite_network_activation_unsupported' );
		if ( $was_network_active || $was_site_active ) deactivate_plugins( $mcp_plugin_file, true, $was_network_active );

		$skin = new Automatic_Upgrader_Skin();
		$upgrader = new Plugin_Upgrader( $skin );
		$result = $upgrader->install( (string) $bundle['path'], array( 'overwrite_package' => true ) );
		if ( is_wp_error( $result ) || ! $result ) {
			if ( ( $was_network_active || $was_site_active ) && '' !== $mcp_plugin_file && file_exists( WP_PLUGIN_DIR . '/' . $mcp_plugin_file ) ) activate_plugin( $mcp_plugin_file, '', $was_network_active );
			self::redirect_result( 'install_failed' );
		}
		$activation = activate_plugin( self::MCP_PLUGIN_FILE, '', false );
		if ( is_wp_error( $activation ) ) self::redirect_result( 'activate_failed' );
		$plugins = self::plugins( true );
		$installed_identity = self::resolve_mcp_plugin_file( $plugins );
		$installed_plugin_file = isset( $installed_identity['plugin_file'] ) ? (string) $installed_identity['plugin_file'] : '';
		if ( ! empty( $installed_identity['ambiguous'] ) || '' === $installed_plugin_file ) self::redirect_result( 'post_install_plugin_identity_invalid' );
		$installed_version = isset( $plugins[ $installed_plugin_file ]['Version'] ) ? (string) $plugins[ $installed_plugin_file ]['Version'] : '';
		if ( '' === $expected_version || ! hash_equals( $expected_version, $installed_version ) ) self::redirect_result( 'version_mismatch' );
		$integrity = self::installed_mcp_adapter_integrity( $certified, $installed_plugin_file );
		if ( empty( $integrity['ready'] ) ) self::redirect_result( 'integrity_mismatch' );
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

	/** Canonical runtime/update identity for the installed MCP Adapter main file. */
	public static function mcp_adapter_plugin_identity( $refresh = false ) {
		return self::resolve_mcp_plugin_file( self::plugins( (bool) $refresh ) );
	}

	/** Disk-only certification for lifecycle recovery; does not claim PHP symbols. */
	public static function mcp_adapter_disk_integrity() {
		$plugins = self::plugins();
		$identity = self::resolve_mcp_plugin_file( $plugins );
		if ( ! empty( $identity['ambiguous'] ) ) return array(
			'contract' => 'mad4b.mcp-adapter-installed-integrity.v1',
			'ready' => false,
			'state' => 'plugin_identity_ambiguous',
			'expected_count' => 0,
			'verified_count' => 0,
			'mismatch_count' => 1,
			'mismatches' => array( array( 'file' => '', 'reason' => 'plugin_identity_ambiguous' ) ),
		);
		return self::installed_mcp_adapter_integrity( self::certified_mcp_adapter(), (string) ( $identity['plugin_file'] ?? '' ) );
	}

	private static function installed_mcp_adapter_integrity( array $certified, $plugin_file = '' ) {
		$out = array(
			'contract' => 'mad4b.mcp-adapter-installed-integrity.v1',
			'ready' => false,
			'state' => 'inspection',
			'expected_count' => 0,
			'verified_count' => 0,
			'mismatch_count' => 0,
			'mismatches' => array(),
		);
		$critical = isset( $certified['critical_files'] ) && is_array( $certified['critical_files'] ) ? $certified['critical_files'] : array();
		$plugin_file = trim( (string) $plugin_file );
		if ( '' === $plugin_file ) {
			$identity = self::resolve_mcp_plugin_file( self::plugins() );
			$plugin_file = ! empty( $identity['ambiguous'] ) ? '' : (string) ( $identity['plugin_file'] ?? '' );
		}
		$root = defined( 'WP_PLUGIN_DIR' ) && '' !== $plugin_file ? realpath( trailingslashit( WP_PLUGIN_DIR ) . dirname( $plugin_file ) ) : false;
		if ( empty( $critical ) ) {
			$out['state'] = 'baseline_missing';
			$out['mismatches'][] = array( 'file' => '', 'reason' => 'critical_file_baseline_missing' );
			$out['mismatch_count'] = 1;
			return $out;
		}
		if ( ! $root || ! is_dir( $root ) ) {
			$out['state'] = 'plugin_root_missing';
			$out['mismatches'][] = array( 'file' => '', 'reason' => 'installed_plugin_root_missing' );
			$out['mismatch_count'] = 1;
			return $out;
		}
		$root = rtrim( wp_normalize_path( $root ), '/' );
		foreach ( $critical as $relative => $expected_sha ) {
			$relative = ltrim( wp_normalize_path( (string) $relative ), '/' );
			$expected_sha = strtolower( trim( (string) $expected_sha ) );
			$out['expected_count']++;
			$reason = '';
			$actual_sha = '';
			if ( '' === $relative
				|| false !== strpos( $relative, '../' )
				|| 0 === strpos( $relative, '..' )
				|| 1 !== preg_match( '#^[A-Za-z0-9._/\-]+$#D', $relative )
				|| 1 !== preg_match( '/^[a-f0-9]{64}$/D', $expected_sha ) ) {
				$reason = 'invalid_certified_critical_file';
			} else {
				$file = realpath( $root . '/' . $relative );
				$normalized = $file ? wp_normalize_path( $file ) : '';
				if ( ! $file || ! is_file( $file ) || 0 !== strpos( $normalized, $root . '/' ) ) {
					$reason = 'critical_file_missing';
				} else {
					$hash = is_readable( $file ) ? hash_file( 'sha256', $file ) : false;
					$actual_sha = is_string( $hash ) ? strtolower( $hash ) : '';
					if ( 1 !== preg_match( '/^[a-f0-9]{64}$/D', $actual_sha ) ) {
						$reason = 'critical_file_hash_unavailable';
					} elseif ( ! hash_equals( $expected_sha, $actual_sha ) ) {
						$reason = 'critical_file_sha256_mismatch';
					}
				}
			}
			if ( '' === $reason ) {
				$out['verified_count']++;
				continue;
			}
			$out['mismatches'][] = array(
				'file' => substr( $relative, 0, 220 ),
				'reason' => $reason,
				'expected_sha256' => preg_match( '/^[a-f0-9]{64}$/D', $expected_sha ) ? $expected_sha : '',
				'actual_sha256' => preg_match( '/^[a-f0-9]{64}$/D', $actual_sha ) ? $actual_sha : '',
			);
		}
		$out['mismatch_count'] = count( $out['mismatches'] );
		$out['ready'] = $out['expected_count'] > 0 && 0 === $out['mismatch_count'] && $out['verified_count'] === $out['expected_count'];
		$out['state'] = $out['ready'] ? 'certified_disk_set' : 'integrity_mismatch';
		return $out;
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

	private static function mcp_runtime_provenance( $plugin_file = '' ) {
		$out = array(
			'runtime_class_loaded' => false,
			'runtime_source' => 'unavailable',
			'runtime_from_official_plugin' => false,
		);
		$class = 'WP\\MCP\\Core\\McpAdapter';
		if ( ! class_exists( $class, false ) ) return $out;
		$out['runtime_class_loaded'] = true;
		try {
			$reflection = new ReflectionClass( $class );
			$file = $reflection->getFileName();
			$resolved = $file ? realpath( $file ) : false;
			if ( '' === trim( (string) $plugin_file ) ) {
				$identity = self::resolve_mcp_plugin_file( self::plugins() );
				$plugin_file = ! empty( $identity['ambiguous'] ) ? '' : (string) ( $identity['plugin_file'] ?? '' );
			}
			$official_root = defined( 'WP_PLUGIN_DIR' ) && '' !== trim( (string) $plugin_file ) ? realpath( trailingslashit( WP_PLUGIN_DIR ) . dirname( (string) $plugin_file ) ) : false;
			if ( $resolved ) $out['runtime_source'] = wp_normalize_path( $resolved );
			if ( $resolved && $official_root ) {
				$runtime_file = wp_normalize_path( $resolved );
				$official = rtrim( wp_normalize_path( $official_root ), '/' ) . '/';
				$out['runtime_from_official_plugin'] = 0 === strpos( $runtime_file, $official );
			}
		} catch ( Throwable $e ) {
			$out['runtime_source'] = 'reflection-unavailable';
		}
		return $out;
	}

	private static function resolve_mcp_plugin_file( array $plugins ) {
		$matches = array();
		foreach ( array_keys( $plugins ) as $plugin_file ) {
			$plugin_file = function_exists( 'wp_normalize_path' ) ? wp_normalize_path( (string) $plugin_file ) : str_replace( '\\', '/', (string) $plugin_file );
			$plugin_file = ltrim( trim( $plugin_file ), '/' );
			if ( '' === $plugin_file || false !== strpos( $plugin_file, '../' ) || in_array( '..', explode( '/', $plugin_file ), true ) ) continue;
			if ( 'mcp-adapter.php' === basename( $plugin_file ) ) $matches[] = $plugin_file;
		}
		$matches = array_values( array_unique( $matches ) );
		return array(
			'plugin_file' => 1 === count( $matches ) ? $matches[0] : '',
			'ambiguous' => count( $matches ) > 1,
			'candidate_count' => count( $matches ),
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
