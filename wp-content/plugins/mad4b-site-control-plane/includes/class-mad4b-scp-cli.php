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
				array( 'command' => 'wp mad4b package verify', 'operation' => 'package.integrity.verify', 'readonly' => true ),
			),
			'write_commands_available' => false,
			'generic_shell_available' => false,
			'raw_sql_available' => false,
		) );
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
