<?php

if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Read-only admin evidence for MCP registration lifecycle/provenance. */
final class MAD4B_SCP_MCP_Registration_Diagnostics_Admin {
	private static $booted = false;

	public static function boot() {
		if ( self::$booted ) return;
		self::$booted = true;
		add_action( 'admin_notices', array( __CLASS__, 'render' ), 5 );
	}

	public static function render() {
		if ( ! current_user_can( 'manage_options' ) ) return;
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only diagnostics.
		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only diagnostics.
		if ( 'mad4b-control-plane-connection' !== $page || 'endpoints' !== $tab ) return;
		if ( ! class_exists( 'MAD4B_SCP_MCP_Registration_Bridge' ) ) return;

		// This notice is observational only. Deep REST materialization is owned by
		// the explicit nonce-bound Connection > Endpoints POST action and runs at
		// admin_init before this notice. Never prime REST from admin_notices.
		$deep_requested = class_exists( 'MAD4B_SCP_Provider_Diagnostic_Policy' )
			&& MAD4B_SCP_Provider_Diagnostic_Policy::explicit_rest_materialization_allowed();
		if ( ! $deep_requested ) return;

		$status = MAD4B_SCP_MCP_Registration_Bridge::status();
		$refresh = class_exists( 'MAD4B_SCP_MCP_MU_Bootstrap_Refresh' ) ? MAD4B_SCP_MCP_MU_Bootstrap_Refresh::status() : array();
		$conflict = class_exists( 'MAD4B_SCP_MCP_Runtime_Conflict_Guard' ) ? MAD4B_SCP_MCP_Runtime_Conflict_Guard::status() : array();
		$mu_runtime = isset( $GLOBALS['mad4b_scp_mcp_mu_bootstrap'] ) && is_array( $GLOBALS['mad4b_scp_mcp_mu_bootstrap'] ) ? $GLOBALS['mad4b_scp_mcp_mu_bootstrap'] : array();
		$build = self::disk_evidence();
		$errors = isset( $status['registration_errors'] ) && is_array( $status['registration_errors'] ) ? $status['registration_errors'] : array();
		$nonempty_errors = array_filter( $errors, static function ( $value ) { return '' !== (string) $value; } );
		$official = ! empty( $status['adapter_runtime_from_official_plugin'] );
		$missed = ! empty( $status['adapter_init_seen_before_bridge_boot'] );
		$hook_bound = ! empty( $status['server_hook_bound'] );
		$registered = empty( $nonempty_errors );
		foreach ( MAD4B_SCP_Servers::registration_status() as $entry ) {
			if ( empty( $entry['registered'] ) ) { $registered = false; break; }
		}
		$type = $registered && $official && ! $missed && $hook_bound && empty( $build['runtime_stale_vs_disk'] ) ? 'success' : 'warning';

		echo '<div class="notice notice-' . esc_attr( $type ) . '"><p><strong>' . esc_html__( 'MAD4B MCP registration diagnostics', 'mad4b-site-control-plane' ) . '</strong></p>';
		echo '<table class="widefat striped" style="max-width:1100px;margin:8px 0 12px"><tbody>';
		if ( class_exists( 'MAD4B_SCP_ChatGPT_Tool_Projection' ) ) {
			$projection = MAD4B_SCP_ChatGPT_Tool_Projection::registration_diagnostic_snapshot();
			self::row( 'Projection revision', $projection['revision'] );
			self::row( 'Projection stored / effective', $projection['stored_count'] . ' / ' . $projection['effective_count'] );
			self::row( 'Stale projection entries', count( array_filter( $projection['abilities'], static function ( $row ) { return ! empty( $row['stale'] ); } ) ) );
			self::row( 'Execution strategy', 'Governed dispatcher; optional direct hot set' );
			self::row( 'Catalog indexed bytes / capacity', $projection['storage']['indexed_bytes'] . ' / ' . $projection['storage']['capacity_bytes'] );
			self::row( 'Catalog indexed objects', $projection['storage']['indexed_objects'] );
			self::row( 'Next catalog GC', $projection['storage']['next_gc'] ? gmdate( 'c', $projection['storage']['next_gc'] ) : 'not scheduled' );
			self::row( 'Client refresh', $projection['catalog_refresh_action'] );
		}
		self::row( 'Control Plane runtime version', $build['runtime_version'] );
		self::row( 'Control Plane main file disk version', $build['disk_version'] );
		self::row( 'Control Plane runtime stale vs disk', ! empty( $build['runtime_stale_vs_disk'] ) ? 'yes' : 'no' );
		self::row( 'Control Plane main file SHA-256 prefix', $build['main_sha256_prefix'] );
		self::row( 'Control Plane build marker present', ! empty( $build['marker_present'] ) ? 'yes' : 'no' );
		self::row( 'Control Plane build marker release', $build['marker_release'] );
		self::row( 'Control Plane build marker matches disk', ! empty( $build['marker_matches_disk'] ) ? 'yes' : 'no' );
		self::row( 'Bridge hook bound', $hook_bound ? 'yes' : 'no' );
		self::row( 'Adapter init happened before bridge boot', $missed ? 'yes' : 'no' );
		self::row( 'REST API init happened before bridge boot', ! empty( $status['rest_init_seen_before_bridge_boot'] ) ? 'yes' : 'no' );
		self::row( 'Missed REST recovery scheduled', ! empty( $status['missed_rest_recovery_scheduled'] ) ? 'yes' : 'no' );
		self::row( 'Missed REST recovery attempted', ! empty( $status['missed_rest_recovery_attempted'] ) ? 'yes' : 'no' );
		self::row( 'Missed REST recovery succeeded', ! empty( $status['missed_rest_recovery_succeeded'] ) ? 'yes' : 'no' );
		self::row( 'Missed REST recovery route count', isset( $status['missed_rest_recovery_route_count'] ) ? (string) (int) $status['missed_rest_recovery_route_count'] : '0' );
		self::row( 'Missed REST recovery state', isset( $status['missed_rest_recovery_state'] ) ? sanitize_key( (string) $status['missed_rest_recovery_state'] ) : '' );
		self::row( 'Missed REST recovery blocker', isset( $status['missed_rest_recovery_blocker'] ) && '' !== (string) $status['missed_rest_recovery_blocker'] ? sanitize_key( (string) $status['missed_rest_recovery_blocker'] ) : 'none' );
		self::row( 'Adapter runtime from official plugin', $official ? 'yes' : 'no' );
		self::row( 'Adapter runtime source', isset( $status['adapter_runtime_source'] ) ? $status['adapter_runtime_source'] : '' );
		self::row( 'Adapter runtime version', isset( $status['adapter_runtime_version'] ) ? $status['adapter_runtime_version'] : '' );
		self::row( 'mcp_adapter_init count', isset( $status['mcp_adapter_init_count'] ) ? (string) (int) $status['mcp_adapter_init_count'] : '0' );
		self::row( 'REST API init count', isset( $status['rest_api_init_count'] ) ? (string) (int) $status['rest_api_init_count'] : '0' );
		self::row( 'Abilities init count', isset( $status['abilities_init_count'] ) ? (string) (int) $status['abilities_init_count'] : '0' );
		if ( ! empty( $refresh ) ) {
			self::row( 'MU bootstrap source refresh applied', ! empty( $refresh['refresh_applied'] ) ? 'yes' : 'no' );
			self::row( 'MU bootstrap refresh next request required', ! empty( $refresh['next_request_required'] ) ? 'yes' : 'no' );
			self::row( 'MU bootstrap refresh state', isset( $refresh['state'] ) ? sanitize_key( (string) $refresh['state'] ) : '' );
			self::row( 'MU bootstrap refresh blocker', isset( $refresh['blocker'] ) && '' !== (string) $refresh['blocker'] ? sanitize_key( (string) $refresh['blocker'] ) : 'none' );
		}
		if ( ! empty( $mu_runtime ) ) {
			self::row( 'MU adapter instance armed', ! empty( $mu_runtime['adapter_instance_armed'] ) ? 'yes' : 'no' );
			self::row( 'MU adapter init hook', isset( $mu_runtime['adapter_init_hook'] ) ? sanitize_key( (string) $mu_runtime['adapter_init_hook'] ) : '' );
			self::row( 'MU adapter init hook bound', ! empty( $mu_runtime['adapter_init_hook_bound'] ) ? 'yes' : 'no' );
		}
		if ( ! empty( $conflict ) ) {
			self::row( 'Runtime conflict guard eligible', ! empty( $conflict['eligible'] ) ? 'yes' : 'no' );
			self::row( 'Official MCP Adapter active', ! empty( $conflict['official_plugin_active'] ) ? 'yes' : 'no' );
			self::row( 'Hostinger bundled adapter active', ! empty( $conflict['hostinger_bundle_active'] ) ? 'yes' : 'no' );
			self::row( 'Official loads before Hostinger in active_plugins', ! empty( $conflict['official_loads_before_hostinger'] ) ? 'yes' : 'no' );
			self::row( 'Runtime provenance mismatch', ! empty( $conflict['runtime_provenance_mismatch'] ) ? 'yes' : 'no' );
			self::row( 'Runtime class provenance enforced', ! empty( $conflict['runtime_class_provenance_enforced'] ) ? 'yes' : 'no' );
			self::row( 'Runtime class provenance ready', null === ( $conflict['runtime_class_provenance_ready'] ?? null ) ? 'Not checked' : ( ! empty( $conflict['runtime_class_provenance_ready'] ) ? 'yes' : 'no' ) );
			self::row( 'Runtime class provenance state', isset( $conflict['runtime_class_provenance_state'] ) ? sanitize_key( (string) $conflict['runtime_class_provenance_state'] ) : '' );
			self::row( 'Runtime class provenance failure count', isset( $conflict['runtime_class_provenance_failure_count'] ) ? (string) max( 0, (int) $conflict['runtime_class_provenance_failure_count'] ) : '0' );
			self::row( 'Runtime from Hostinger bundle', ! empty( $conflict['runtime_from_hostinger_bundle'] ) ? 'yes' : 'no' );
			self::row( 'Collision risk detected', ! empty( $conflict['collision_risk_detected'] ) ? 'yes' : 'no' );
			self::row( 'Runtime repair applied', ! empty( $conflict['repair_applied'] ) ? 'yes' : 'no' );
			self::row( 'active_plugins order repair applied', ! empty( $conflict['load_order_repair_applied'] ) ? 'yes' : 'no' );
			self::row( 'MU bootstrap present', ! empty( $conflict['mu_bootstrap_present'] ) ? 'yes' : 'no' );
			self::row( 'MU bootstrap integrity', ! empty( $conflict['mu_bootstrap_integrity'] ) ? 'yes' : 'no' );
			self::row( 'MU bootstrap executed this request', ! empty( $conflict['mu_bootstrap_executed'] ) ? 'yes' : 'no' );
			self::row( 'MU bootstrap runtime state', isset( $conflict['mu_bootstrap_runtime_state'] ) ? sanitize_key( (string) $conflict['mu_bootstrap_runtime_state'] ) : '' );
			self::row( 'MU bootstrap runtime source', isset( $conflict['mu_bootstrap_runtime_source'] ) ? sanitize_text_field( (string) $conflict['mu_bootstrap_runtime_source'] ) : '' );
			self::row( 'Next request required', ! empty( $conflict['next_request_required'] ) ? 'yes' : 'no' );
			self::row( 'Runtime conflict guard state', isset( $conflict['state'] ) ? sanitize_key( (string) $conflict['state'] ) : '' );
			self::row( 'Runtime conflict guard blocker', isset( $conflict['blocker'] ) && '' !== (string) $conflict['blocker'] ? sanitize_key( (string) $conflict['blocker'] ) : 'none' );
		}
		$server_status = MAD4B_SCP_Servers::registration_status();
		foreach ( $errors as $server_id => $error ) self::row( 'Registration error: ' . sanitize_key( (string) $server_id ), '' === (string) $error ? 'none' : sanitize_key( (string) $error ) );

		$chatgpt = isset( $server_status['mad4b-chatgpt'] ) && is_array( $server_status['mad4b-chatgpt'] ) ? $server_status['mad4b-chatgpt'] : array();
		$preflight = isset( $chatgpt['preflight'] ) && is_array( $chatgpt['preflight'] ) ? $chatgpt['preflight'] : array();
		$catalog = isset( $chatgpt['catalog_evidence'] ) && is_array( $chatgpt['catalog_evidence'] ) ? $chatgpt['catalog_evidence'] : array();
		if ( $preflight ) {
			self::row( 'ChatGPT requested tool count', isset( $chatgpt['requested_tool_count'] ) ? (string) (int) $chatgpt['requested_tool_count'] : '0' );
			self::row( 'ChatGPT accepted preflight tool count', isset( $preflight['tools'] ) && is_array( $preflight['tools'] ) ? (string) count( $preflight['tools'] ) : '0' );
			self::row( 'ChatGPT materialized tool count', isset( $chatgpt['tool_count'] ) ? (string) (int) $chatgpt['tool_count'] : '0' );
			self::row( 'ChatGPT preflight ready', ! empty( $preflight['ready'] ) ? 'yes' : 'no' );
			self::row( 'ChatGPT preflight degraded', ! empty( $preflight['degraded'] ) ? 'yes' : 'no' );
			self::row( 'ChatGPT preflight blocker', isset( $preflight['blocker'] ) && '' !== (string) $preflight['blocker'] ? sanitize_key( (string) $preflight['blocker'] ) : 'none' );
			$provenance = isset( $preflight['runtime_class_provenance'] ) && is_array( $preflight['runtime_class_provenance'] ) ? $preflight['runtime_class_provenance'] : array();
			if ( $provenance ) {
				self::row( 'MCP class provenance enforced', ! empty( $provenance['enforced'] ) ? 'yes' : 'no' );
				self::row( 'MCP class provenance ready', ! empty( $provenance['ready'] ) ? 'yes' : 'no' );
				self::row( 'MCP class provenance state', isset( $provenance['state'] ) ? sanitize_key( (string) $provenance['state'] ) : '' );
				self::row( 'MCP class provenance blocker', isset( $provenance['blocker'] ) && '' !== (string) $provenance['blocker'] ? sanitize_key( (string) $provenance['blocker'] ) : 'none' );
				self::row( 'MCP class provenance failures', isset( $provenance['failure_count'] ) ? (string) max( 0, (int) $provenance['failure_count'] ) : '0' );
				$class_failures = isset( $provenance['failures'] ) && is_array( $provenance['failures'] ) ? array_slice( $provenance['failures'], 0, 12 ) : array();
				foreach ( $class_failures as $index => $failure ) {
					if ( ! is_array( $failure ) ) continue;
					self::row( 'MCP class provenance failure ' . ( (int) $index + 1 ), self::class_failure_summary( $failure ) );
				}
			}
			$failures = isset( $preflight['failures'] ) && is_array( $preflight['failures'] ) ? array_slice( $preflight['failures'], 0, 12 ) : array();
			self::row( 'ChatGPT preflight failure count', isset( $preflight['failures'] ) && is_array( $preflight['failures'] ) ? (string) count( $preflight['failures'] ) : '0' );
			foreach ( $failures as $index => $failure ) {
				if ( ! is_array( $failure ) ) continue;
				$bounded = self::failure_summary( $failure );
				self::row( 'ChatGPT preflight failure ' . ( (int) $index + 1 ), $bounded );
			}
		} else {
			self::row( 'ChatGPT preflight observed', 'no' );
		}
		if ( $catalog ) {
			self::row( 'ChatGPT catalog observed', ! empty( $catalog['observed'] ) ? 'yes' : 'no' );
			self::row( 'ChatGPT catalog ready', ! empty( $catalog['ready'] ) ? 'yes' : 'no' );
			self::row( 'ChatGPT catalog blocker', isset( $catalog['blocker'] ) && '' !== (string) $catalog['blocker'] ? sanitize_key( (string) $catalog['blocker'] ) : 'none' );
		}

		if ( class_exists( 'MAD4B_SCP_MCP_Peer_Governance' ) ) {
			$peer = MAD4B_SCP_MCP_Peer_Governance::status();
			$foreign = isset( $peer['foreign_transport_inventory'] ) && is_array( $peer['foreign_transport_inventory'] ) ? $peer['foreign_transport_inventory'] : array();
			self::row( 'Foreign MCP transport detected', ! empty( $peer['foreign_mcp_detected'] ) ? 'yes' : 'no' );
			self::row( 'Foreign MCP route count', isset( $foreign['foreign_route_count'] ) ? (string) (int) $foreign['foreign_route_count'] : '0' );
			self::row( 'Foreign MCP plugin count', isset( $foreign['foreign_plugin_count'] ) ? (string) (int) $foreign['foreign_plugin_count'] : '0' );
			$foreign_routes = isset( $foreign['foreign_routes'] ) && is_array( $foreign['foreign_routes'] ) ? array_slice( $foreign['foreign_routes'], 0, 20 ) : array();
			$foreign_plugins = isset( $foreign['foreign_plugins'] ) && is_array( $foreign['foreign_plugins'] ) ? array_slice( $foreign['foreign_plugins'], 0, 20 ) : array();
			foreach ( $foreign_routes as $index => $route ) self::row( 'Foreign MCP route ' . ( (int) $index + 1 ), self::bounded_text( $route, 256 ) );
			foreach ( $foreign_plugins as $index => $plugin ) self::row( 'Foreign MCP plugin ' . ( (int) $index + 1 ), self::bounded_text( $plugin, 256 ) );
		}
		echo '</tbody></table></div>';
	}

	/** Allowlisted evidence only; malformed provider values must not break the notice. */
	private static function bounded_text( $value, $limit = 128 ) {
		return is_string( $value ) ? sanitize_text_field( substr( $value, 0, $limit ) ) : '';
	}

	private static function failure_summary( array $failure ) {
		$fields = array();
		foreach ( array( 'failing_ability' => 'ability', 'stage' => 'stage', 'error_class' => 'class', 'error_code' => 'error', 'validator_reason' => 'validator_reason' ) as $key => $label ) {
			$value = self::bounded_text( $failure[ $key ] ?? '', 160 );
			if ( '' !== $value || 'validator_reason' !== $key ) $fields[] = $label . '=' . $value;
		}
		foreach ( array( 'source_schema_fingerprint' => 'source_schema', 'schema_fingerprint' => 'dto_schema' ) as $key => $label ) {
			$value = $failure[ $key ] ?? '';
			if ( is_string( $value ) && preg_match( '/^[a-f0-9]{64}$/iD', $value ) ) $fields[] = $label . '=' . strtolower( substr( $value, 0, 16 ) );
		}
		foreach ( array( 'tool_bytes', 'catalog_bytes_before', 'catalog_bytes_limit' ) as $key ) {
			if ( isset( $failure[ $key ] ) && is_int( $failure[ $key ] ) && $failure[ $key ] >= 0 ) $fields[] = $key . '=' . $failure[ $key ];
		}
		return implode( ' | ', $fields );
	}

	private static function class_failure_summary( array $failure ) {
		$fields = array();
		foreach ( array( 'alias' => 'alias', 'class' => 'class', 'expected_source' => 'expected', 'observed_source' => 'observed', 'reason' => 'reason' ) as $key => $label ) {
			$fields[] = $label . '=' . self::bounded_text( $failure[ $key ] ?? '', 220 );
		}
		foreach ( array( 'expected_sha256' => 'expected_sha', 'actual_sha256' => 'actual_sha' ) as $key => $label ) {
			$value = isset( $failure[ $key ] ) ? strtolower( (string) $failure[ $key ] ) : '';
			if ( preg_match( '/^[a-f0-9]{64}$/D', $value ) ) $fields[] = $label . '=' . substr( $value, 0, 16 );
		}
		return implode( ' | ', $fields );
	}

	/**
	 * Read-only deployment evidence. This intentionally reports only bounded
	 * version/hash values and never exposes an absolute filesystem path.
	 */
	private static function disk_evidence() {
		$runtime_version = defined( 'MAD4B_SCP_VERSION' ) ? sanitize_text_field( (string) MAD4B_SCP_VERSION ) : '';
		$main = defined( 'MAD4B_SCP_FILE' ) ? MAD4B_SCP_FILE : '';
		$disk_version = '';
		$main_sha = '';
		if ( $main && is_readable( $main ) ) {
			$header = file_get_contents( $main, false, null, 0, 4096 );
			if ( is_string( $header ) && preg_match( '/^[ \t]*\*[ \t]*Version:\s*([^\r\n]+)/mi', $header, $match ) ) {
				$disk_version = sanitize_text_field( trim( (string) $match[1] ) );
			}
			$hash = hash_file( 'sha256', $main );
			if ( is_string( $hash ) ) $main_sha = substr( strtolower( $hash ), 0, 16 );
		}

		$marker = defined( 'MAD4B_SCP_DIR' ) ? trailingslashit( MAD4B_SCP_DIR ) . 'MAD4B-RUNTIME-BUILD.txt' : '';
		$marker_present = $marker && is_readable( $marker );
		$marker_release = '';
		if ( $marker_present ) {
			$text = file_get_contents( $marker, false, null, 0, 2048 );
			if ( is_string( $text ) && preg_match( '/^release=([^\r\n]+)$/mi', $text, $match ) ) {
				$marker_release = sanitize_text_field( trim( (string) $match[1] ) );
			}
		}

		return array(
			'runtime_version' => $runtime_version,
			'disk_version' => $disk_version,
			'runtime_stale_vs_disk' => '' !== $runtime_version && '' !== $disk_version && ! hash_equals( $runtime_version, $disk_version ),
			'main_sha256_prefix' => $main_sha,
			'marker_present' => (bool) $marker_present,
			'marker_release' => $marker_release,
			'marker_matches_disk' => '' !== $marker_release && '' !== $disk_version && hash_equals( $marker_release, $disk_version ),
		);
	}

	private static function row( $label, $value ) {
		echo '<tr><th style="width:330px">' . esc_html( $label ) . '</th><td><code>' . esc_html( (string) $value ) . '</code></td></tr>';
	}
}
