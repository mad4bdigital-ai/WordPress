<?php

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Staging-only compatibility guard for plugins that bundle wordpress/mcp-adapter.
 *
 * A legacy bundled copy can claim WP\MCP\Core\McpAdapter before the canonical
 * MCP Adapter plugin loads. PHP cannot replace an already-declared class, so the
 * only safe in-process remediation is to repair the active-plugin load order for
 * the next request. No plugin is disabled and Production is never modified.
 */
final class MAD4B_SCP_MCP_Runtime_Conflict_Guard {
	const CONTRACT = 'mad4b.mcp-runtime-conflict-guard.v1';
	const STAGING_HOST = 'staging.egypttourgates.com';
	const OFFICIAL_PLUGIN = 'mcp-adapter/mcp-adapter.php';
	const HOSTINGER_PREFIX = 'hostinger-ai-assistant/';

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

		if ( false === $official_index ) {
			$status['blocker'] = 'official_mcp_adapter_not_active';
			self::$status = $status;
			return $status;
		}
		if ( false === $hostinger_index ) {
			$status['state'] = 'no_reviewed_bundled_conflict';
			$status['official_loads_before_hostinger'] = true;
			self::$status = $status;
			return $status;
		}
		if ( $official_index < $hostinger_index ) {
			$status['state'] = 'canonical_order';
			$status['official_loads_before_hostinger'] = true;
			self::$status = $status;
			return $status;
		}

		$status['collision_risk_detected'] = true;
		$audit = class_exists( 'MAD4B_SCP_Audit' ) ? MAD4B_SCP_Audit::storage_status() : array( 'ready' => false );
		if ( empty( $audit['ready'] ) ) {
			$status['blocker'] = 'audit_unavailable_for_load_order_repair';
			self::$status = $status;
			return $status;
		}

		$before = $active;
		$new = $active;
		array_splice( $new, (int) $official_index, 1 );
		$hostinger_index_after_remove = self::hostinger_index( $new );
		if ( false === $hostinger_index_after_remove ) {
			$status['blocker'] = 'hostinger_inventory_changed_during_repair';
			self::$status = $status;
			return $status;
		}
		array_splice( $new, (int) $hostinger_index_after_remove, 0, array( self::OFFICIAL_PLUGIN ) );
		$new = array_values( $new );
		if ( $new === $before ) {
			$status['blocker'] = 'load_order_repair_noop';
			self::$status = $status;
			return $status;
		}

		$updated = update_option( 'active_plugins', $new );
		$stored = get_option( 'active_plugins', array() );
		if ( ! $updated && $stored !== $new ) {
			$status['blocker'] = 'load_order_repair_failed';
			self::$status = $status;
			return $status;
		}

		$event = MAD4B_SCP_Audit::record(
			'mad4b/mcp-runtime-load-order-repair',
			array(
				'contract' => self::CONTRACT,
				'environment' => 'staging',
				'host' => self::STAGING_HOST,
				'official_plugin' => self::OFFICIAL_PLUGIN,
				'reviewed_conflict_family' => 'hostinger-ai-assistant',
				'before_sha256' => hash( 'sha256', wp_json_encode( $before ) ),
				'after_sha256' => hash( 'sha256', wp_json_encode( $new ) ),
				'current_request_runtime_replacement_attempted' => false,
				'next_request_required' => true,
			),
			'ok'
		);
		if ( is_wp_error( $event ) ) {
			$rolled_back = update_option( 'active_plugins', $before );
			$rollback_stored = get_option( 'active_plugins', array() );
			$status['blocker'] = ( $rolled_back || $rollback_stored === $before ) ? 'audit_failed_load_order_repair_rolled_back' : 'audit_failed_load_order_repair_rollback_failed';
			self::$status = $status;
			return $status;
		}

		$status['state'] = 'repaired_for_next_request';
		$status['repair_applied'] = true;
		$status['next_request_required'] = true;
		$status['official_loads_before_hostinger'] = true;
		$status['official_index_after_repair'] = (int) array_search( self::OFFICIAL_PLUGIN, $new, true );
		$status['hostinger_index_after_repair'] = (int) self::hostinger_index( $new );
		$status['blocker'] = '';
		self::$status = $status;
		return $status;
	}

	public static function status() {
		return ! empty( self::$status ) ? self::$status : self::base_status();
	}

	private static function hostinger_index( array $active ) {
		foreach ( $active as $index => $plugin ) {
			if ( 0 === strpos( (string) $plugin, self::HOSTINGER_PREFIX ) ) return (int) $index;
		}
		return false;
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
			'repair_applied' => false,
			'next_request_required' => false,
		);
	}

	private static function home_host() {
		$host = wp_parse_url( home_url( '/' ), PHP_URL_HOST );
		return is_string( $host ) ? strtolower( rtrim( trim( $host ), '.' ) ) : '';
	}
}
