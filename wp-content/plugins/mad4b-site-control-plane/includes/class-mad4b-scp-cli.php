<?php

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Canonical read-only WP-CLI frontend for MAD4B Control Plane services.
 *
 * This class is presentation/dispatch only. It does not create an independent
 * authority system and intentionally exposes no write, force, eval or shell path.
 */
final class MAD4B_SCP_CLI {
	const CONTRACT = 'mad4b.wp-cli.v1';

	private static $booted = false;

	public static function boot() {
		if ( self::$booted ) return;
		self::$booted = true;
		if ( ! defined( 'WP_CLI' ) || ! WP_CLI || ! class_exists( 'WP_CLI' ) ) return;

		WP_CLI::add_command( 'mad4b status', array( __CLASS__, 'status' ) );
		WP_CLI::add_command( 'mad4b diagnostics schema', array( __CLASS__, 'diagnostics_schema' ) );
		WP_CLI::add_command( 'mad4b diagnostics runtime', array( __CLASS__, 'diagnostics_runtime' ) );
		WP_CLI::add_command( 'mad4b diagnostics write', array( __CLASS__, 'diagnostics_write' ) );
		WP_CLI::add_command( 'mad4b package verify', array( __CLASS__, 'package_verify' ) );
		WP_CLI::add_command( 'mad4b operations', array( __CLASS__, 'operations' ) );
	}

	private static function emit( array $payload ) {
		$payload['cli_contract'] = self::CONTRACT;
		$payload['mutation_performed'] = false;
		$payload['authorizing'] = false;
		$json = wp_json_encode( $payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		if ( ! is_string( $json ) ) {
			WP_CLI::error( 'MAD4B CLI response could not be encoded.' );
			return;
		}
		WP_CLI::line( $json );
	}

	public static function status( $args = array(), $assoc_args = array() ) {
		unset( $args, $assoc_args );
		$site = class_exists( 'MAD4B_SCP_Site_Profile' ) ? MAD4B_SCP_Site_Profile::status() : array( 'configured' => false );
		$schema = class_exists( 'MAD4B_SCP_Schema' ) ? MAD4B_SCP_Schema::status( true ) : array( 'ready' => false );
		$runtime = self::runtime_provenance();
		self::emit( array(
			'contract' => 'mad4b.cli-status.v1',
			'operation_id' => 'runtime.status.read',
			'semantic_result' => self::runtime_status_semantic_result(),
			'site_profile' => $site,
			'schema' => $schema,
			'runtime' => $runtime,
			'production_authorized' => false,
		) );
	}

	public static function diagnostics_schema( $args = array(), $assoc_args = array() ) {
		unset( $args, $assoc_args );
		$status = class_exists( 'MAD4B_SCP_Schema' )
			? MAD4B_SCP_Schema::status( true )
			: array( 'ready' => false, 'reason' => 'schema_service_unavailable' );
		self::emit( array(
			'contract' => 'mad4b.cli-schema-diagnostic.v1',
			'diagnostic' => $status,
		) );
	}

	public static function diagnostics_runtime( $args = array(), $assoc_args = array() ) {
		unset( $args, $assoc_args );
		self::emit( array(
			'contract' => 'mad4b.cli-runtime-diagnostic.v1',
			'runtime' => self::runtime_provenance(),
			'wordpress_version' => isset( $GLOBALS['wp_version'] ) ? (string) $GLOBALS['wp_version'] : '',
			'php_version' => PHP_VERSION,
			'environment' => function_exists( 'wp_get_environment_type' ) ? wp_get_environment_type() : '',
		) );
	}

	/**
	 * Expose fresh, non-authorizing runtime truth. The stored authority checkpoint
	 * can be ready while a provider is offline and five old allow grants remain.
	 * Only bounded ability names/counts are returned; grant identities and tokens
	 * never leave this diagnostic.
	 */
	public static function diagnostics_write( $args = array(), $assoc_args = array() ) {
		unset( $args, $assoc_args );
		$runtime = class_exists( 'MAD4B_SCP_Staging_Write_Authority' );
		$checkpoint = $runtime ? MAD4B_SCP_Staging_Write_Authority::persisted_status() : array();
		$current = $runtime ? MAD4B_SCP_Staging_Write_Authority::current_execution_readiness() : array();
		$plan = $runtime ? MAD4B_SCP_Staging_Write_Authority::reconciliation_plan() : array();
		$stale = array();
		foreach ( (array) ( $plan['stale_allow_grants'] ?? array() ) as $row ) {
			if ( is_array( $row ) && isset( $row['ability'] ) && is_string( $row['ability'] ) ) {
				$stale[] = $row['ability'];
			}
		}
		$stale = array_values( array_unique( $stale ) );
		sort( $stale, SORT_STRING );
		self::emit( array(
			'contract' => 'mad4b.cli-write-diagnostic.v1',
			'environment' => function_exists( 'wp_get_environment_type' ) ? wp_get_environment_type() : '',
			'checkpoint_ready' => ! empty( $checkpoint['ready'] ),
			'current_execution_ready' => ! empty( $current['ready'] ),
			'current_readiness_blockers' => array_values( (array) ( $current['blockers'] ?? array( 'write_authority_unavailable' ) ) ),
			'write_inventory_count' => isset( $plan['write_tool_count'] ) ? (int) $plan['write_tool_count'] : 0,
			'exact_grants_missing_count' => isset( $plan['exact_grants_missing_count'] ) ? (int) $plan['exact_grants_missing_count'] : 0,
			'stale_allow_grants_count' => isset( $plan['stale_allow_grants_count'] ) ? (int) $plan['stale_allow_grants_count'] : 0,
			'stale_ability_names' => $stale,
			'candidate_binding_match' => ! empty( $current['candidate_binding_match'] ),
			'next_reconciliation' => isset( $current['next_reconciliation'] ) ? (string) $current['next_reconciliation'] : 'review_current_authority',
			'read_only' => true,
			'production_authorized' => false,
		) );
	}

	public static function package_verify( $args = array(), $assoc_args = array() ) {
		unset( $args, $assoc_args );
		$runtime = self::runtime_provenance();
		$verified = ! empty( $runtime['manifest_present'] )
			&& ! empty( $runtime['manifest_valid'] )
			&& ! empty( $runtime['runtime_manifest_match'] )
			&& empty( $runtime['stale'] )
			&& empty( $runtime['provenance_mismatch'] );
		self::emit( array(
			'contract' => 'mad4b.cli-package-verify.v1',
			'verified' => (bool) $verified,
			'runtime' => $runtime,
			'verification_scope' => 'runtime_readback_only',
			'external_release_root_required_for_release_authorization' => true,
		) );
	}

	public static function operations( $args = array(), $assoc_args = array() ) {
		unset( $args, $assoc_args );
		self::emit( array(
			'contract' => 'mad4b.cli-operation-catalog.v1',
			'commands' => array(
				array( 'command' => 'wp mad4b status', 'operation' => 'runtime.status.read', 'readonly' => true ),
				array( 'command' => 'wp mad4b diagnostics schema', 'operation' => 'schema.diagnostics.read', 'readonly' => true ),
				array( 'command' => 'wp mad4b diagnostics runtime', 'operation' => 'runtime.provenance.verify', 'readonly' => true ),
				array( 'command' => 'wp mad4b diagnostics write', 'operation' => 'authority.current-write-readiness.read', 'readonly' => true ),
				array( 'command' => 'wp mad4b package verify', 'operation' => 'package.integrity.verify', 'readonly' => true ),
			),
			'write_commands_available' => false,
			'generic_shell_available' => false,
			'raw_sql_available' => false,
		) );
	}

	private static function runtime_status_semantic_result() {
		$root = defined( 'ABSPATH' ) ? (string) ABSPATH : '';
		$real_root = '' !== $root ? realpath( $root ) : false;
		if ( false !== $real_root ) $root = rtrim( (string) $real_root, '/\\' );
		$config = '' !== $root ? $root . DIRECTORY_SEPARATOR . 'wp-config.php' : '';
		$plugin_root = defined( 'WP_PLUGIN_DIR' )
			? rtrim( (string) WP_PLUGIN_DIR, '/\\' ) . DIRECTORY_SEPARATOR . 'mad4b-site-control-plane'
			: ( '' !== $root ? $root . DIRECTORY_SEPARATOR . 'wp-content' . DIRECTORY_SEPARATOR . 'plugins' . DIRECTORY_SEPARATOR . 'mad4b-site-control-plane' : '' );
		$config_present = '' !== $config && is_file( $config );
		return array(
			'contract' => 'mad4b.runtime-status-read.v1',
			'operation_id' => 'runtime.status.read',
			'wordpress_root' => $root,
			'wp_config_present' => $config_present,
			'wp_config_sha256' => $config_present ? (string) hash_file( 'sha256', $config ) : '',
			'plugin_present' => '' !== $plugin_root && is_dir( $plugin_root ),
			'environment' => function_exists( 'wp_get_environment_type' ) ? wp_get_environment_type() : '',
			'readonly' => true,
			'authorizing' => false,
			'authority_class' => 'read_only_non_authorizing',
			'production_authorized' => false,
			'mutation_performed' => false,
		);
	}

	private static function runtime_provenance() {
		if ( ! class_exists( 'MAD4B_SCP_Live_Acceptance_Observer' )
			|| ! method_exists( 'MAD4B_SCP_Live_Acceptance_Observer', 'build_provenance_status' ) ) {
			return array(
				'available' => false,
				'reason' => 'runtime_provenance_service_unavailable',
			);
		}
		$value = MAD4B_SCP_Live_Acceptance_Observer::build_provenance_status();
		return is_array( $value ) ? $value : array(
			'available' => false,
			'reason' => 'runtime_provenance_invalid',
		);
	}
}

MAD4B_SCP_CLI::boot();
