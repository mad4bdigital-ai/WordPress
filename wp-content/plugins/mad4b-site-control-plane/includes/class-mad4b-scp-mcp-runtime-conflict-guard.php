<?php

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Governed non-production compatibility guard for plugins that bundle wordpress/mcp-adapter.
 *
 * active_plugins order is not authoritative for runtime ownership: a hosting
 * bootstrap or MU loader may claim WP\MCP\Core\McpAdapter before normal plugins
 * are included. When the reviewed Hostinger bundle owns the class on the exact
 * enrolled non-production origin, install a fixed, integrity-checked MU bootstrap for the next
 * request. The bootstrap loads the canonical MCP Adapter before normal plugins.
 * Repair is lifecycle-only and runs after WordPress init/auth context is ready.
 * No plugin is disabled and Production is never modified.
 */
final class MAD4B_SCP_MCP_Runtime_Conflict_Guard {
	const CONTRACT = 'mad4b.mcp-runtime-conflict-guard.v2';
	const OFFICIAL_PLUGIN = 'mcp-adapter/mcp-adapter.php';
	const HOSTINGER_PREFIX = 'hostinger-ai-assistant/';
	const MU_BOOTSTRAP_BASENAME = '000-mad4b-mcp-adapter-bootstrap.php';
	const MU_BOOTSTRAP_SOURCE = 'bootstrap/mad4b-mcp-adapter-mu-bootstrap.php';

	private static $status = array();
	private static $mu_transaction_id = '';

	public static function bootstrap( $preventive = false ) {
		$status = self::base_status();
		if ( ! $status['eligible'] ) { self::$status = $status; return $status; }

		// Runtime repair may rewrite active_plugins, install/replace the managed
		// MU bootstrap and append audit state. None of that belongs on an
		// initialize/tools-list/execute/OAuth protocol request.
		if ( class_exists( 'MAD4B_SCP_MCP_Request_Scope', false )
			&& MAD4B_SCP_MCP_Request_Scope::current_request_is_protocol_hotpath() ) {
			$status['state'] = 'repair_deferred_protocol_hotpath';
			$status['repair_deferred'] = true;
			$status['next_request_required'] = true;
			$status['blocker'] = '';
			self::$status = $status;
			return $status;
		}

		if ( ! self::repair_lifecycle_allowed() ) {
			$status['state'] = 'repair_deferred_request_hotpath';
			$status['repair_deferred'] = true;
			$status['next_request_required'] = false;
			$status['next_lifecycle_required'] = true;
			$status['blocker'] = '';
			self::$status = $status;
			return $status;
		}

		$active = get_option( 'active_plugins', array() );
		if ( ! is_array( $active ) ) {
			$status['blocker'] = 'active_plugin_inventory_invalid';
			self::$status = $status;
			return $status;
		}
		$active = array_values( array_map( 'strval', $active ) );
		$official_identity = self::official_plugin_identity( $active );
		$official_plugin = isset( $official_identity['plugin_file'] ) ? (string) $official_identity['plugin_file'] : '';
		$official_index = '' !== $official_plugin ? array_search( $official_plugin, $active, true ) : false;
		$hostinger_index = self::hostinger_index( $active );
		$status['official_plugin_file'] = $official_plugin;
		$status['official_plugin_identity_ambiguous'] = ! empty( $official_identity['ambiguous'] );
		$status['official_plugin_active'] = false !== $official_index;
		$status['hostinger_bundle_active'] = false !== $hostinger_index;
		$status['official_index'] = false === $official_index ? -1 : (int) $official_index;
		$status['hostinger_index'] = false === $hostinger_index ? -1 : (int) $hostinger_index;
		$status['official_loads_before_hostinger'] = false === $hostinger_index || ( false !== $official_index && $official_index < $hostinger_index );

		// Observe only classes that are already loaded. Runtime inspection must
		// never perturb autoloader ownership; a preclaimed/mixed class set is
		// repaired for the next request, while unseen classes remain unmeasured.
		$status = array_merge( $status, self::runtime_provenance(), self::mu_bootstrap_status() );

		if ( ! empty( $official_identity['ambiguous'] ) ) {
			$status['blocker'] = 'official_mcp_adapter_identity_ambiguous';
			self::$status = $status;
			return $status;
		}
		if ( false === $official_index ) {
			$status['blocker'] = 'official_mcp_adapter_not_active';
			self::$status = $status;
			return $status;
		}

		$mixed = ! empty( $status['runtime_class_provenance_enforced'] ) && ! empty( $status['runtime_class_provenance_failure_count'] );
		$preventive = $preventive && class_exists( 'MAD4B_SCP_MCP_Runtime_Recovery', false ) && MAD4B_SCP_MCP_Runtime_Recovery::active();
		if ( ! empty( $status['runtime_from_official_plugin'] ) || ( $preventive && empty( $status['runtime_class_loaded'] ) ) ) {
			if ( $mixed || $preventive ) {
				// PHP classes cannot be safely replaced after declaration. Keep the
				// current request fail-closed, but arm the governed MU bootstrap so the
				// next request pins every certified builder/validator/DTO class before
				// normal plugins can register competing Jetpack packages.
				$status['state'] = $mixed ? 'mixed_runtime_class_set' : 'class_set_repair_requested';
				$status['collision_risk_detected'] = $mixed;
				$status['runtime_provenance_mismatch'] = $mixed;
				$status['blocker'] = 'mcp_adapter_class_provenance_mismatch';
				$status['next_request_required'] = true;

				$audit = class_exists( 'MAD4B_SCP_Audit' ) ? MAD4B_SCP_Audit::storage_status() : array( 'ready' => false );
				if ( empty( $audit['ready'] ) ) {
					$status['blocker'] = 'audit_unavailable_for_runtime_class_repair';
					self::$status = $status;
					return $status;
				}
				$mu = self::ensure_mu_bootstrap();
				$status = array_merge( $status, $mu );
				if ( ! empty( $mu['blocker'] ) ) {
					$status['state'] = 'mixed_runtime_class_set';
					$status['blocker'] = $mu['blocker'];
					self::$status = $status;
					return $status;
				}
				$mu_installed = ! empty( $mu['mu_bootstrap_installed'] );
				$event = MAD4B_SCP_Audit::record(
					'mad4b/mcp-runtime-class-set-repair-armed',
					array(
						'contract' => self::CONTRACT,
						'environment' => isset( $status['environment'] ) ? $status['environment'] : 'unknown',
						'host' => isset( $status['host'] ) ? $status['host'] : '',
						'runtime_source' => isset( $status['runtime_source'] ) ? sanitize_text_field( (string) $status['runtime_source'] ) : '',
						'class_provenance_state' => isset( $status['runtime_class_provenance_state'] ) ? sanitize_key( (string) $status['runtime_class_provenance_state'] ) : '',
						'preventive' => (bool) $preventive,
						'class_provenance_failure_count' => isset( $status['runtime_class_provenance_failure_count'] ) ? max( 0, (int) $status['runtime_class_provenance_failure_count'] ) : 0,
						'mu_bootstrap_installed' => $mu_installed,
						'current_request_runtime_replacement_attempted' => false,
						'next_request_required' => true,
					),
					'ok'
				);
				if ( is_wp_error( $event ) ) {
					$rollback_owner = ! empty( $mu['mu_bootstrap_transaction_pending'] ) && '' !== self::$mu_transaction_id
						? MAD4B_SCP_MCP_MU_Bootstrap_Refresh::verify_transaction_owner( self::$mu_transaction_id, 'replaced_pending_audit' )
						: true;
					$rolled_back = ! $mu_installed || ( true === $rollback_owner && self::remove_managed_mu_bootstrap() );
					if ( ! empty( $mu['mu_bootstrap_transaction_pending'] ) && '' !== self::$mu_transaction_id ) {
						if ( $rolled_back ) MAD4B_SCP_MCP_MU_Bootstrap_Refresh::complete_transaction( self::$mu_transaction_id );
						else MAD4B_SCP_MCP_MU_Bootstrap_Refresh::block_transaction( 'audit_failed_runtime_class_repair_rollback_failed', self::$mu_transaction_id );
						self::$mu_transaction_id = '';
					}
					$status = array_merge( $status, self::mu_bootstrap_status() );
					$status['state'] = 'mixed_runtime_class_set';
					$status['blocker'] = $rolled_back ? 'audit_failed_runtime_class_repair_rolled_back' : 'audit_failed_runtime_class_repair_rollback_failed';
					self::$status = $status;
					return $status;
				}
				if ( ! empty( $mu['mu_bootstrap_transaction_pending'] ) && '' !== self::$mu_transaction_id ) {
					$transaction_id = self::$mu_transaction_id;
					self::$mu_transaction_id = '';
					if ( ! MAD4B_SCP_MCP_MU_Bootstrap_Refresh::complete_transaction( $transaction_id ) ) {
						MAD4B_SCP_MCP_MU_Bootstrap_Refresh::block_transaction( 'mu_bootstrap_install_transaction_finalize_failed', $transaction_id );
						$status['blocker'] = 'mu_bootstrap_install_transaction_finalize_failed';
						self::$status = $status;
						return $status;
					}
				}
				$status = array_merge( $status, self::mu_bootstrap_status() );
				$status['state'] = $mu_installed ? 'mu_bootstrap_installed_for_class_set_next_request' : 'class_set_repair_armed_for_next_request';
				$status['repair_applied'] = $mu_installed;
				$status['next_request_required'] = true;
				$status['blocker'] = $mixed ? 'mcp_adapter_class_provenance_mismatch' : '';
				self::$status = $status;
				return $status;
			}
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
			array_splice( $new, (int) $hostinger_index_after_remove, 0, array( $official_plugin ) );
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
				'environment' => isset( $status['environment'] ) ? $status['environment'] : 'unknown',
				'host' => isset( $status['host'] ) ? $status['host'] : '',
				'official_plugin' => $official_plugin,
				'preferred_official_plugin' => self::OFFICIAL_PLUGIN,
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
			$rollback_owner = ! empty( $mu['mu_bootstrap_transaction_pending'] ) && '' !== self::$mu_transaction_id
				? MAD4B_SCP_MCP_MU_Bootstrap_Refresh::verify_transaction_owner( self::$mu_transaction_id, 'replaced_pending_audit' )
				: true;
			$mu_rolled_back = ! $mu_installed || ( true === $rollback_owner && self::remove_managed_mu_bootstrap() );
			if ( ! empty( $mu['mu_bootstrap_transaction_pending'] ) && '' !== self::$mu_transaction_id ) {
				if ( $mu_rolled_back ) MAD4B_SCP_MCP_MU_Bootstrap_Refresh::complete_transaction( self::$mu_transaction_id );
				else MAD4B_SCP_MCP_MU_Bootstrap_Refresh::block_transaction( 'audit_failed_runtime_repair_rollback_failed', self::$mu_transaction_id );
				self::$mu_transaction_id = '';
			}
			if ( $order_repair_applied ) update_option( 'active_plugins', $before );
			$status = array_merge( $status, self::mu_bootstrap_status() );
			$status['blocker'] = $mu_rolled_back ? 'audit_failed_runtime_repair_rolled_back' : 'audit_failed_runtime_repair_rollback_failed';
			self::$status = $status;
			return $status;
		}

		if ( ! empty( $mu['mu_bootstrap_transaction_pending'] ) && '' !== self::$mu_transaction_id ) {
			$transaction_id = self::$mu_transaction_id;
			self::$mu_transaction_id = '';
			if ( ! MAD4B_SCP_MCP_MU_Bootstrap_Refresh::complete_transaction( $transaction_id ) ) {
				MAD4B_SCP_MCP_MU_Bootstrap_Refresh::block_transaction( 'mu_bootstrap_install_transaction_finalize_failed', $transaction_id );
				$status['blocker'] = 'mu_bootstrap_install_transaction_finalize_failed';
				self::$status = $status;
				return $status;
			}
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
		return ! empty( self::$status ) ? self::$status : self::inspect_status();
	}

	/**
	 * Observe runtime ownership without acquiring repair authority or mutating
	 * plugin/filesystem state. This keeps diagnostics truthful after repair was
	 * armed in a previous request: a clean next request can prove convergence
	 * without calling the privileged Recovery coordinator again.
	 */
	private static function inspect_status() {
		$status = self::base_status();
		if ( empty( $status['eligible'] ) ) return $status;

		$active = get_option( 'active_plugins', array() );
		if ( ! is_array( $active ) ) {
			$status['blocker'] = 'active_plugin_inventory_invalid';
			return $status;
		}
		$active = array_values( array_map( 'strval', $active ) );
		$official_identity = self::official_plugin_identity( $active );
		$official_plugin = isset( $official_identity['plugin_file'] ) ? (string) $official_identity['plugin_file'] : '';
		$official_index = '' !== $official_plugin ? array_search( $official_plugin, $active, true ) : false;
		$hostinger_index = self::hostinger_index( $active );
		$status['official_plugin_file'] = $official_plugin;
		$status['official_plugin_identity_ambiguous'] = ! empty( $official_identity['ambiguous'] );
		$status['official_plugin_active'] = false !== $official_index;
		$status['hostinger_bundle_active'] = false !== $hostinger_index;
		$status['official_index'] = false === $official_index ? -1 : (int) $official_index;
		$status['hostinger_index'] = false === $hostinger_index ? -1 : (int) $hostinger_index;
		$status['official_loads_before_hostinger'] = false === $hostinger_index || ( false !== $official_index && $official_index < $hostinger_index );
		$status = array_merge( $status, self::runtime_provenance(), self::mu_bootstrap_status() );

		if ( ! empty( $official_identity['ambiguous'] ) ) {
			$status['blocker'] = 'official_mcp_adapter_identity_ambiguous';
			return $status;
		}
		if ( false === $official_index ) {
			$status['blocker'] = 'official_mcp_adapter_not_active';
			return $status;
		}

		$mixed = ! empty( $status['runtime_class_provenance_enforced'] ) && ! empty( $status['runtime_class_provenance_failure_count'] );
		if ( ! empty( $status['runtime_from_official_plugin'] ) ) {
			if ( $mixed ) {
				$status['state'] = 'mixed_runtime_class_set';
				$status['collision_risk_detected'] = true;
				$status['runtime_provenance_mismatch'] = true;
				$status['next_request_required'] = true;
				$status['blocker'] = 'mcp_adapter_class_provenance_mismatch';
				return $status;
			}
			$status['state'] = 'canonical_runtime';
			$status['blocker'] = '';
			return $status;
		}

		if ( empty( $status['runtime_class_loaded'] ) ) {
			$status['state'] = 'runtime_not_loaded_at_guard';
			$status['blocker'] = '';
			return $status;
		}

		$status['collision_risk_detected'] = true;
		$status['next_request_required'] = true;
		if ( ! empty( $status['runtime_from_hostinger_bundle'] ) ) {
			$status['state'] = 'runtime_provenance_mismatch';
			$status['runtime_provenance_mismatch'] = true;
			$status['blocker'] = 'mcp_adapter_runtime_provenance_mismatch';
		} else {
			$status['state'] = 'runtime_provenance_unreviewed';
			$status['blocker'] = 'unreviewed_mcp_adapter_runtime_source';
		}
		return $status;
	}

	private static function official_plugin_identity( array $active ) {
		$matches = array();
		foreach ( $active as $plugin_file ) {
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
			'runtime_class_provenance_enforced' => false,
			'runtime_class_provenance_ready' => null,
			'runtime_class_provenance_complete' => false,
			'runtime_class_provenance_state' => '',
			'runtime_class_provenance_failure_count' => 0,
			'runtime_class_provenance_unobserved_count' => 0,
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
			$active = get_option( 'active_plugins', array() );
			$active = is_array( $active ) ? array_values( array_map( 'strval', $active ) ) : array();
			$official_identity = self::official_plugin_identity( $active );
			$official_plugin = isset( $official_identity['plugin_file'] ) ? (string) $official_identity['plugin_file'] : '';
			$official_root = '' !== $official_plugin ? realpath( trailingslashit( WP_PLUGIN_DIR ) . dirname( $official_plugin ) ) : false;
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
		$class_provenance = class_exists( 'MAD4B_SCP_MCP_Class_Provenance', false )
			? MAD4B_SCP_MCP_Class_Provenance::status( false, false )
			: array();
		if ( is_array( $class_provenance ) && $class_provenance ) {
			$out['runtime_class_provenance_enforced'] = ! empty( $class_provenance['enforced'] );
			$out['runtime_class_provenance_complete'] = ! empty( $class_provenance['complete'] );
			$out['runtime_class_provenance_failure_count'] = isset( $class_provenance['failure_count'] ) ? max( 0, (int) $class_provenance['failure_count'] ) : 0;
			$out['runtime_class_provenance_unobserved_count'] = isset( $class_provenance['unobserved_count'] ) ? max( 0, (int) $class_provenance['unobserved_count'] ) : 0;
			if ( ! $out['runtime_class_provenance_enforced'] ) {
				$out['runtime_class_provenance_ready'] = null;
			} elseif ( $out['runtime_class_provenance_failure_count'] > 0 ) {
				$out['runtime_class_provenance_ready'] = false;
			} elseif ( $out['runtime_class_provenance_complete'] ) {
				$out['runtime_class_provenance_ready'] = ! empty( $class_provenance['ready'] );
			} else {
				$out['runtime_class_provenance_ready'] = null;
			}
			$out['runtime_class_provenance_state'] = isset( $class_provenance['state'] ) ? sanitize_key( (string) $class_provenance['state'] ) : '';
		}
		$out['runtime_provenance_mismatch'] = ( $out['runtime_class_loaded'] && ! $out['runtime_from_official_plugin'] )
			|| ( ! empty( $out['runtime_class_provenance_enforced'] ) && ! empty( $out['runtime_class_provenance_failure_count'] ) );
		return $out;
	}

	private static function repair_lifecycle_allowed() {
		return class_exists( 'MAD4B_SCP_MCP_Runtime_Recovery', false )
			&& MAD4B_SCP_MCP_Runtime_Recovery::active();
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
		$integrity = class_exists( 'MAD4B_SCP_Dependency_Manager', false ) ? MAD4B_SCP_Dependency_Manager::mcp_adapter_disk_integrity() : array();
		if ( empty( $integrity['ready'] ) ) {
			$status['blocker'] = 'mcp_adapter_integrity_mismatch';
			return $status;
		}
		$source = trailingslashit( MAD4B_SCP_DIR ) . self::MU_BOOTSTRAP_SOURCE;
		$destination = trailingslashit( WPMU_PLUGIN_DIR ) . self::MU_BOOTSTRAP_BASENAME;
		if ( ! is_readable( $source ) ) {
			$status['blocker'] = 'mu_bootstrap_source_unreadable';
			return $status;
		}
		$transaction = class_exists( 'MAD4B_SCP_MCP_MU_Bootstrap_Refresh', false )
			? MAD4B_SCP_MCP_MU_Bootstrap_Refresh::reconcile_transaction( $destination )
			: true;
		if ( is_wp_error( $transaction ) ) {
			$status['blocker'] = $transaction->get_error_code();
			$status['mu_bootstrap_transaction_pending'] = true;
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

		$filesystem_lock = MAD4B_SCP_MCP_MU_Bootstrap_Refresh::acquire_managed_filesystem_lock( $destination );
		if ( is_wp_error( $filesystem_lock ) ) {
			$status['blocker'] = $filesystem_lock->get_error_code();
			return $status;
		}
		try {
		$temp = $destination . '.tmp-' . (int) getmypid() . '-' . substr( hash( 'sha256', microtime( true ) . ':' . uniqid( '', true ) ), 0, 12 );
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
		$transaction_id = MAD4B_SCP_MCP_MU_Bootstrap_Refresh::begin_transaction( 'install', '', $source_hash );
		if ( is_wp_error( $transaction_id ) ) {
			@unlink( $temp );
			$status['blocker'] = $transaction_id->get_error_code();
			return $status;
		}
		self::$mu_transaction_id = $transaction_id;
		$owner_before_install = MAD4B_SCP_MCP_MU_Bootstrap_Refresh::verify_transaction_owner( $transaction_id, 'prepared' );
		if ( is_wp_error( $owner_before_install ) ) {
			@unlink( $temp );
			self::$mu_transaction_id = '';
			$status['blocker'] = $owner_before_install->get_error_code();
			return $status;
		}
		if ( ! @rename( $temp, $destination ) ) {
			@unlink( $temp );
			$race = self::mu_bootstrap_status();
			if ( ! empty( $race['mu_bootstrap_present'] ) && ! empty( $race['mu_bootstrap_integrity'] ) ) {
				MAD4B_SCP_MCP_MU_Bootstrap_Refresh::complete_transaction( $transaction_id );
				self::$mu_transaction_id = '';
				return array_merge( $race, array( 'blocker' => '' ) );
			}
			MAD4B_SCP_MCP_MU_Bootstrap_Refresh::block_transaction( 'mu_bootstrap_atomic_install_failed', $transaction_id );
			self::$mu_transaction_id = '';
			$status['blocker'] = 'mu_bootstrap_atomic_install_failed';
			return $status;
		}
		clearstatcache( true, $destination );
		$opcode = MAD4B_SCP_MCP_MU_Bootstrap_Refresh::invalidate_managed_opcode_for_lifecycle( $destination );
		$status = self::mu_bootstrap_status();
		$status['opcache_invalidation'] = $opcode;
		$status['runtime_restart_required'] = empty( $opcode['verified'] );
		$status['mu_bootstrap_installed'] = ! empty( $status['mu_bootstrap_present'] ) && ! empty( $status['mu_bootstrap_integrity'] );
		if ( $status['mu_bootstrap_installed'] ) {
			$marked = MAD4B_SCP_MCP_MU_Bootstrap_Refresh::mark_transaction_replaced( $transaction_id );
			if ( is_wp_error( $marked ) ) {
				MAD4B_SCP_MCP_MU_Bootstrap_Refresh::block_transaction( $marked->get_error_code(), $transaction_id );
				self::$mu_transaction_id = '';
				$status['blocker'] = $marked->get_error_code();
				return $status;
			}
			$status['mu_bootstrap_transaction_pending'] = true;
		}
		$status['blocker'] = $status['mu_bootstrap_installed'] ? '' : 'mu_bootstrap_post_install_integrity_failed';
		if ( ! $status['mu_bootstrap_installed'] ) {
			MAD4B_SCP_MCP_MU_Bootstrap_Refresh::block_transaction( $status['blocker'], $transaction_id );
			self::$mu_transaction_id = '';
		}
		return $status;
		} finally {
			MAD4B_SCP_MCP_MU_Bootstrap_Refresh::release_managed_filesystem_lock( $filesystem_lock );
		}
	}

	private static function remove_managed_mu_bootstrap() {
		$status = self::mu_bootstrap_status();
		if ( empty( $status['mu_bootstrap_present'] ) || empty( $status['mu_bootstrap_integrity'] ) || ! defined( 'WPMU_PLUGIN_DIR' ) ) return false;
		$destination = trailingslashit( WPMU_PLUGIN_DIR ) . self::MU_BOOTSTRAP_BASENAME;
		if ( ! @unlink( $destination ) ) return false;
		clearstatcache( true, $destination );
		MAD4B_SCP_MCP_MU_Bootstrap_Refresh::invalidate_managed_opcode_for_lifecycle( $destination );
		return ! is_file( $destination );
	}

	private static function base_status() {
		$environment = class_exists( 'MAD4B_SCP_Site_Profile' ) ? MAD4B_SCP_Site_Profile::current_environment() : 'unknown';
		$host = self::home_host();
		$eligible = class_exists( 'MAD4B_SCP_Site_Profile' ) && MAD4B_SCP_Site_Profile::nonproduction_governed( 'managed_runtime' );
		$blocker = '';
		if ( ! $eligible ) {
			if ( ! class_exists( 'MAD4B_SCP_Site_Profile' ) || ! MAD4B_SCP_Site_Profile::origin_enrolled() ) $blocker = 'site_profile_not_enrolled';
			elseif ( ! MAD4B_SCP_Site_Profile::managed_runtime_enabled() ) $blocker = 'site_profile_managed_runtime_disabled';
			else $blocker = 'runtime_repair_nonproduction_only';
		}
		return array(
			'contract' => self::CONTRACT,
			'environment' => $environment,
			'host' => $host,
			'eligible' => $eligible,
			'state' => $eligible ? 'inspection_pending' : 'ineligible',
			'blocker' => $blocker,
			'official_plugin_active' => false,
			'official_plugin_file' => '',
			'official_plugin_identity_ambiguous' => false,
			'hostinger_bundle_active' => false,
			'official_loads_before_hostinger' => false,
			'collision_risk_detected' => false,
			'runtime_provenance_mismatch' => false,
			'runtime_class_provenance_enforced' => false,
			'runtime_class_provenance_ready' => null,
			'runtime_class_provenance_complete' => false,
			'runtime_class_provenance_state' => '',
			'runtime_class_provenance_failure_count' => 0,
			'runtime_class_provenance_unobserved_count' => 0,
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
