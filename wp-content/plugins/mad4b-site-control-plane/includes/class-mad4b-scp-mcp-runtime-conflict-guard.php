<?php

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Staging-only compatibility guard for plugins that bundle wordpress/mcp-adapter.
 *
 * active_plugins order is not authoritative for runtime ownership: a hosting
 * bootstrap or MU loader may claim WP\MCP\Core\McpAdapter before normal plugins
 * are included. When the reviewed Hostinger bundle owns the class on the exact
 * Staging origin, install a fixed, integrity-checked MU bootstrap for the next
 * request. The bootstrap loads the canonical MCP Adapter before normal plugins.
 * No plugin is disabled and Production is never modified.
 */
final class MAD4B_SCP_MCP_Runtime_Conflict_Guard {
	const CONTRACT = 'mad4b.mcp-runtime-conflict-guard.v2';
	const STAGING_HOST = 'staging.egypttourgates.com';
	const OFFICIAL_PLUGIN = 'mcp-adapter/mcp-adapter.php';
	const HOSTINGER_PREFIX = 'hostinger-ai-assistant/';
	const MU_BOOTSTRAP_BASENAME = '000-mad4b-mcp-adapter-bootstrap.php';
	const MU_BOOTSTRAP_SOURCE = 'bootstrap/mad4b-mcp-adapter-mu-bootstrap.php';

	private static $status = array();

	public static function bootstrap() {
		$status = self::base_status();
		if ( ! $status['eligible'] ) { self::$status = $status; return $status; }

		$active = get_option( 'active_plugins', array() );
		if ( ! is_array( $active ) ) {
			$status['blocker'] = 'active_plugin_inventory_invalid';
			self::$status = $status;
			return $status;
		}
		$active = array_values( array_map( 'strval', $active ) );
		$official_index = array_search( self::OFFICIAL_PLUGIN, $active, true );
		$hostinger_index = self::hostinger_index( $active );
		$status['official_plugin_active'] = false !== $official_index;
		$status['hostinger_bundle_active'] = false !== $hostinger_index;
		$status['official_index'] = false === $official_index ? -1 : (int) $official_index;
		$status['hostinger_index'] = false === $hostinger_index ? -1 : (int) $hostinger_index;
		$status['official_loads_before_hostinger'] = false === $hostinger_index || ( false !== $official_index && $official_index < $hostinger_index );

		$status = array_merge( $status, self::runtime_provenance(), self::mu_bootstrap_status() );

		if ( false === $official_index ) {
			$status['blocker'] = 'official_mcp_adapter_not_active';
			self::$status = $status;
			return $status;
		}

		if ( ! empty( $status['runtime_from_official_plugin'] ) ) {
			$status['state'] = 'canonical_runtime';
			$status['blocker'] = '';
			self::$status = $status;
			return $status;
		}

		if ( empty( $status['runtime_class_loaded'] ) ) {
			$status['state'] = 'runtime_not_loaded_at_guard';
			$status['blocker'] = '';
			self::$status = $status;
			return $status;
		}

		if ( empty( $status['runtime_from_hostinger_bundle'] ) ) {
			$status['collision_risk_detected'] = true;
			$status['blocker'] = 'unreviewed_mcp_adapter_runtime_source';
			self::$status = $status;
			return $status;
		}

		// This is the exact live failure shape: the reviewed Hostinger copy owns
		// the already-declared class, even if active_plugins says official first.
		$status['collision_risk_detected'] = true;
		$status['runtime_provenance_mismatch'] = true;

		$audit = class_exists( 'MAD4B_SCP_Audit' ) ? MAD4B_SCP_Audit::storage_status() : array( 'ready' => false );
		if ( empty( $audit['ready'] ) ) {
			$status['blocker'] = 'audit_unavailable_for_runtime_repair';
			self::$status = $status;
			return $status;
		}

		$before = $active;
		$new = $active;
		$order_repair_applied = false;
		if ( false !== $hostinger_index && $official_index > $hostinger_index ) {
			array_splice( $new, (int) $official_index, 1 );
			$hostinger_index_after_remove = self::hostinger_index( $new );
			if ( false === $hostinger_index_after_remove ) {
				$status['blocker'] = 'hostinger_inventory_changed_during_repair';
				self::$status = $status;
				return $status;
			}
			array_splice( $new, (int) $hostinger_index_after_remove, 0, array( self::OFFICIAL_PLUGIN ) );
			$new = array_values( $new );
			$updated = update_option( 'active_plugins', $new );
			$stored = get_option( 'active_plugins', array() );
			if ( ! $updated && $stored !== $new ) {
				$status['blocker'] = 'load_order_repair_failed';
				self::$status = $status;
				return $status;
			}
			$order_repair_applied = true;
			$status['official_loads_before_hostinger'] = true;
		}

		$mu_before = self::mu_bootstrap_status();
		if ( ! empty( $mu_before['mu_bootstrap_present'] ) && ! empty( $mu_before['mu_bootstrap_integrity'] ) && empty( $mu_before['mu_bootstrap_executed'] ) ) {
			if ( $order_repair_applied ) update_option( 'active_plugins', $before );
			$status = array_merge( $status, $mu_before );
			$status['blocker'] = 'mu_bootstrap_present_but_not_executed';
			self::$status = $status;
			return $status;
		}
		if ( ! empty( $mu_before['mu_bootstrap_executed'] ) && 'runtime_preclaimed_before_mu_bootstrap' === $mu_before['mu_bootstrap_runtime_state'] ) {
			if ( $order_repair_applied ) update_option( 'active_plugins', $before );
			$status = array_merge( $status, $mu_before );
			$status['blocker'] = 'runtime_preclaimed_before_mu_bootstrap';
			self::$status = $status;
			return $status;
		}

		$mu = self::ensure_mu_bootstrap();
		if ( ! empty( $mu['blocker'] ) ) {
			if ( $order_repair_applied ) update_option( 'active_plugins', $before );
			$status = array_merge( $status, $mu );
			$status['blocker'] = $mu['blocker'];
			self::$status = $status;
			return $status;
		}

		$mu_installed = ! empty( $mu['mu_bootstrap_installed'] );
		if ( ! $mu_installed && ! $order_repair_applied ) {
			$status = array_merge( $status, $mu );
			$status['blocker'] = 'runtime_repair_did_not_change_bootstrap_state';
			self::$status = $status;
			return $status;
		}

		$event = MAD4B_SCP_Audit::record(
			'mad4b/mcp-runtime-bootstrap-repair',
			array(
				'contract' => self::CONTRACT,
				'environment' => 'staging',
				'host' => self::STAGING_HOST,
				'official_plugin' => self::OFFICIAL_PLUGIN,
				'reviewed_conflict_family' => 'hostinger-ai-assistant',
				'runtime_source' => isset( $status['runtime_source'] ) ? sanitize_text_field( (string) $status['runtime_source'] ) : '',
				'runtime_version' => isset( $status['runtime_version'] ) ? sanitize_text_field( (string) $status['runtime_version'] ) : '',
				'active_plugin_order_repaired' => $order_repair_applied,
				'mu_bootstrap_installed' => $mu_installed,
				'mu_bootstrap_sha256' => isset( $mu['mu_bootstrap_sha256'] ) ? sanitize_text_field( (string) $mu['mu_bootstrap_sha256'] ) : '',
				'current_request_runtime_replacement_attempted' => false,
				'next_request_required' => true,
			),
			'ok'
		);
		if ( is_wp_error( $event ) ) {
			if ( $mu_installed ) self::remove_managed_mu_bootstrap();
			if ( $order_repair_applied ) update_option( 'active_plugins', $before );
			$status = array_merge( $status, self::mu_bootstrap_status() );
			$status['blocker'] = 'audit_failed_runtime_repair_rolled_back';
			self::$status = $status;
			return $status;
		}

		$status = array_merge( $status, self::mu_bootstrap_status() );
		$status['state'] = $mu_installed ? 'mu_bootstrap_installed_for_next_request' : 'repaired_for_next_request';
		$status['repair_applied'] = true;
		$status['load_order_repair_applied'] = $order_repair_applied;
		$status['next_request_required'] = true;
		$status['blocker'] = '';
		self::$status = $status;
		return $status;
	}

	public static function status() {
		return ! empty( self::$status ) ? self::$status : array_merge( self::base_status(), self::runtime_provenance(), self::mu_bootstrap_status() );
	}

	private static function hostinger_index( array $active ) {
		foreach ( $active as $index => $plugin ) {
			if ( 0 === strpos( (string) $plugin, self::HOSTINGER_PREFIX ) ) return (int) $index;
		}
		return false;
	}

	private static function runtime_provenance() {
		$out = array(
			'runtime_class_loaded' => false,
			'runtime_source' => 'unavailable',
			'runtime_version' => '',
			'runtime_from_official_plugin' => false,
			'runtime_from_hostinger_bundle' => false,
			'runtime_provenance_mismatch' => false,
		);
		$class = '\\WP\\MCP\\Core\\McpAdapter';
		if ( ! class_exists( $class, false ) ) return $out;
		$out['runtime_class_loaded'] = true;
		if ( defined( 'WP\\MCP\\Core\\McpAdapter::VERSION' ) ) $out['runtime_version'] = (string) \WP\MCP\Core\McpAdapter::VERSION;
		try {
			$reflection = new ReflectionClass( $class );
			$file = $reflection->getFileName();
			$resolved = $file ? realpath( $file ) : false;
			$plugin_root = realpath( WP_PLUGIN_DIR );
			$official_root = realpath( trailingslashit( WP_PLUGIN_DIR ) . 'mcp-adapter' );
			$hostinger_root = realpath( trailingslashit( WP_PLUGIN_DIR ) . 'hostinger-ai-assistant' );
			if ( $resolved && $plugin_root ) {
				$normalized = wp_normalize_path( $resolved );
				$plugins = rtrim( wp_normalize_path( $plugin_root ), '/' ) . '/';
				$out['runtime_source'] = 0 === strpos( $normalized, $plugins ) ? ltrim( substr( $normalized, strlen( $plugins ) ), '/' ) : 'outside-wp-plugin-dir';
				if ( $official_root ) {
					$official = rtrim( wp_normalize_path( $official_root ), '/' ) . '/';
					$out['runtime_from_official_plugin'] = 0 === strpos( $normalized, $official );
				}
				if ( $hostinger_root ) {
					$hostinger = rtrim( wp_normalize_path( $hostinger_root ), '/' ) . '/';
					$out['runtime_from_hostinger_bundle'] = 0 === strpos( $normalized, $hostinger );
				}
			}
		} catch ( Throwable $e ) {
			$out['runtime_source'] = 'reflection-unavailable';
		}
		$out['runtime_provenance_mismatch'] = $out['runtime_class_loaded'] && ! $out['runtime_from_official_plugin'];
		return $out;
	}

	private static function mu_bootstrap_status() {
		$source = defined( 'MAD4B_SCP_DIR' ) ? trailingslashit( MAD4B_SCP_DIR ) . self::MU_BOOTSTRAP_SOURCE : '';
		$destination = defined( 'WPMU_PLUGIN_DIR' ) ? trailingslashit( WPMU_PLUGIN_DIR ) . self::MU_BOOTSTRAP_BASENAME : '';
		$source_hash = $source && is_readable( $source ) ? hash_file( 'sha256', $source ) : '';
		$present = $destination && is_file( $destination );
		$destination_hash = $present && is_readable( $destination ) ? hash_file( 'sha256', $destination ) : '';
		$integrity = $present && '' !== $source_hash && hash_equals( $source_hash, $destination_hash );
		$runtime = isset( $GLOBALS['mad4b_scp_mcp_mu_bootstrap'] ) && is_array( $GLOBALS['mad4b_scp_mcp_mu_bootstrap'] ) ? $GLOBALS['mad4b_scp_mcp_mu_bootstrap'] : array();
		return array(
			'mu_bootstrap_present' => (bool) $present,
			'mu_bootstrap_integrity' => (bool) $integrity,
			'mu_bootstrap_installed' => false,
			'mu_bootstrap_sha256' => $source_hash,
			'mu_bootstrap_executed' => ! empty( $runtime['executed'] ),
			'mu_bootstrap_runtime_state' => isset( $runtime['state'] ) ? sanitize_key( (string) $runtime['state'] ) : ( $present ? 'present_not_executed_this_request' : 'absent' ),
			'mu_bootstrap_runtime_source' => isset( $runtime['runtime_source'] ) ? sanitize_text_field( (string) $runtime['runtime_source'] ) : '',
			'mu_bootstrap_runtime_from_official_plugin' => ! empty( $runtime['runtime_from_official_plugin'] ),
		);
	}

	private static function ensure_mu_bootstrap() {
		$status = self::mu_bootstrap_status();
		$status['blocker'] = '';
		if ( ! defined( 'WPMU_PLUGIN_DIR' ) || ! defined( 'MAD4B_SCP_DIR' ) ) {
			$status['blocker'] = 'mu_bootstrap_directory_unavailable';
			return $status;
		}
		$source = trailingslashit( MAD4B_SCP_DIR ) . self::MU_BOOTSTRAP_SOURCE;
		$destination = trailingslashit( WPMU_PLUGIN_DIR ) . self::MU_BOOTSTRAP_BASENAME;
		if ( ! is_readable( $source ) ) {
			$status['blocker'] = 'mu_bootstrap_source_unreadable';
			return $status;
		}
		if ( ! empty( $status['mu_bootstrap_present'] ) ) {
			if ( empty( $status['mu_bootstrap_integrity'] ) ) $status['blocker'] = 'mu_bootstrap_path_conflict';
			return $status;
		}
		if ( ! is_dir( WPMU_PLUGIN_DIR ) && ! wp_mkdir_p( WPMU_PLUGIN_DIR ) ) {
			$status['blocker'] = 'mu_bootstrap_directory_create_failed';
			return $status;
		}
		if ( ! is_writable( WPMU_PLUGIN_DIR ) ) {
			$status['blocker'] = 'mu_bootstrap_directory_not_writable';
			return $status;
		}

		$temp = $destination . '.tmp-' . (int) getmypid() . '-' . substr( hash( 'sha256', microtime( true ) . ':' . wp_rand() ), 0, 12 );
		if ( ! copy( $source, $temp ) ) {
			$status['blocker'] = 'mu_bootstrap_temp_write_failed';
			return $status;
		}
		$source_hash = hash_file( 'sha256', $source );
		$temp_hash = is_readable( $temp ) ? hash_file( 'sha256', $temp ) : '';
		if ( '' === $source_hash || ! hash_equals( $source_hash, $temp_hash ) ) {
			@unlink( $temp );
			$status['blocker'] = 'mu_bootstrap_temp_integrity_failed';
			return $status;
		}
		if ( ! @rename( $temp, $destination ) ) {
			@unlink( $temp );
			$race = self::mu_bootstrap_status();
			if ( ! empty( $race['mu_bootstrap_present'] ) && ! empty( $race['mu_bootstrap_integrity'] ) ) return array_merge( $race, array( 'blocker' => '' ) );
			$status['blocker'] = 'mu_bootstrap_atomic_install_failed';
			return $status;
		}
		clearstatcache( true, $destination );
		$status = self::mu_bootstrap_status();
		$status['mu_bootstrap_installed'] = ! empty( $status['mu_bootstrap_present'] ) && ! empty( $status['mu_bootstrap_integrity'] );
		$status['blocker'] = $status['mu_bootstrap_installed'] ? '' : 'mu_bootstrap_post_install_integrity_failed';
		return $status;
	}

	private static function remove_managed_mu_bootstrap() {
		$status = self::mu_bootstrap_status();
		if ( empty( $status['mu_bootstrap_present'] ) || empty( $status['mu_bootstrap_integrity'] ) || ! defined( 'WPMU_PLUGIN_DIR' ) ) return false;
		$destination = trailingslashit( WPMU_PLUGIN_DIR ) . self::MU_BOOTSTRAP_BASENAME;
		return @unlink( $destination );
	}

	private static function base_status() {
		$environment = function_exists( 'wp_get_environment_type' ) ? sanitize_key( (string) wp_get_environment_type() ) : 'unknown';
		$host = self::home_host();
		$eligible = 'staging' === $environment && self::STAGING_HOST === $host;
		return array(
			'contract' => self::CONTRACT,
			'environment' => $environment,
			'host' => $host,
			'eligible' => $eligible,
			'state' => $eligible ? 'inspection_pending' : 'ineligible',
			'blocker' => $eligible ? '' : ( 'staging' !== $environment ? 'environment_not_staging' : 'origin_not_governed_staging' ),
			'official_plugin_active' => false,
			'hostinger_bundle_active' => false,
			'official_loads_before_hostinger' => false,
			'collision_risk_detected' => false,
			'runtime_provenance_mismatch' => false,
			'repair_applied' => false,
			'load_order_repair_applied' => false,
			'next_request_required' => false,
		);
	}

	private static function home_host() {
		$host = wp_parse_url( home_url( '/' ), PHP_URL_HOST );
		return is_string( $host ) ? strtolower( rtrim( trim( $host ), '.' ) ) : '';
	}
}
