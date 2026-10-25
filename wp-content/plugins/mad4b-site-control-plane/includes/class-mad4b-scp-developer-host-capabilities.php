<?php

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Non-authorizing host capability probe for the isolated Developer Plane.
 *
 * Centralizes executable/backend discovery so status, linting and subprocess
 * execution reason about the same host facts. The public snapshot never grants
 * authority, starts a process, mutates WordPress state or returns absolute paths.
 */
final class MAD4B_SCP_Developer_Host_Capabilities {
	const CONTRACT = 'mad4b.developer-host-capabilities.v1';

	public static function snapshot() {
		$wp = self::wp_cli_binary();
		$shell = self::shell_binary();
		$prlimit = self::prlimit_binary();
		$sandbox = self::network_sandbox_binary();
		$sandbox_type = self::network_sandbox_type();
		$proc_open = function_exists( 'proc_open' );
		$root_state = self::root_state();
		$php_binary = defined( 'PHP_BINARY' ) && '' !== (string) PHP_BINARY && is_file( PHP_BINARY ) && is_executable( PHP_BINARY );
		$certified_network_backend = in_array( $sandbox_type, array( 'bubblewrap', 'unshare-net' ), true );

		$process_blockers = array();
		if ( ! $proc_open ) $process_blockers[] = 'proc_open_unavailable';
		if ( '' === $prlimit ) $process_blockers[] = 'resource_limiter_unavailable';
		if ( true === $root_state ) $process_blockers[] = 'root_execution_denied';
		if ( null === $root_state ) $process_blockers[] = 'non_root_identity_unverified';

		$no_network_blockers = $process_blockers;
		if ( '' === $sandbox ) {
			$no_network_blockers[] = 'network_isolation_unavailable';
		} elseif ( ! $certified_network_backend ) {
			$no_network_blockers[] = 'network_isolation_backend_uncertified';
		}

		$lint_blockers = $no_network_blockers;
		if ( ! $php_binary ) $lint_blockers[] = 'php_linter_unavailable';

		$process_blockers = array_values( array_unique( $process_blockers ) );
		$no_network_blockers = array_values( array_unique( $no_network_blockers ) );
		$lint_blockers = array_values( array_unique( $lint_blockers ) );
		sort( $process_blockers, SORT_STRING );
		sort( $no_network_blockers, SORT_STRING );
		sort( $lint_blockers, SORT_STRING );

		// External backends are discovered as candidates only. Neither a configured
		// endpoint nor SSH availability is execution certification.
		$external = self::external_backends();
		$fingerprint_payload = array(
			'proc_open_available' => $proc_open,
			'wp_cli_available' => '' !== $wp,
			'shell_available' => '' !== $shell,
			'resource_limiter_available' => '' !== $prlimit,
			'network_sandbox_available' => '' !== $sandbox,
			'network_isolation_backend' => $sandbox_type,
			'network_isolation_backend_certified' => $certified_network_backend,
			'non_root_verified' => null === $root_state ? null : ! $root_state,
			'php_binary_available' => $php_binary,
		);
		$encoded = function_exists( 'wp_json_encode' ) ? wp_json_encode( $fingerprint_payload ) : json_encode( $fingerprint_payload );

		return array(
			'contract' => self::CONTRACT,
			'read_only' => true,
			'authorizing' => false,
			'mutation_performed' => false,
			'proc_open_available' => $proc_open,
			'wp_cli_available' => '' !== $wp,
			'wp_cli_binary' => '' !== $wp ? basename( $wp ) : '',
			'shell_available' => '' !== $shell,
			'shell_binary' => '' !== $shell ? basename( $shell ) : '',
			'resource_limit_backend' => '' !== $prlimit ? 'prlimit' : '',
			'resource_limiter_binary_present' => '' !== $prlimit,
			'network_isolation_backend' => $sandbox_type,
			'network_sandbox_binary_present' => '' !== $sandbox,
			'network_isolation_backend_certified' => $certified_network_backend,
			'non_root_verified' => null === $root_state ? null : ! $root_state,
			'php_binary_available' => $php_binary,
			'external_backends' => $external,
			'available_execution_backends' => array_values( array_merge( array( 'local_host' ), array_keys( array_filter( $external, static function ( $row ) { return ! empty( $row['configured'] ); } ) ) ) ),
			'external_execution_certified' => false,
			'process_backend_ready' => empty( $process_blockers ),
			'process_backend_blockers' => $process_blockers,
			'normal_no_network_execution_ready' => empty( $no_network_blockers ),
			'normal_no_network_execution_blockers' => $no_network_blockers,
			'workspace_php_lint_ready' => empty( $lint_blockers ),
			'workspace_php_lint_blockers' => $lint_blockers,
			'capability_fingerprint' => hash( 'sha256', is_string( $encoded ) ? $encoded : '' ),
		);
	}

	/**
	 * Report explicitly configured remote backend candidates without claiming
	 * transport reachability or execution authority. Never expose credentials,
	 * paths, tokens, usernames or hosts to an MCP status response.
	 */
	public static function external_backends() {
		$definitions = array(
			'isolated_vps_runner' => 'MAD4B_MCP_DEVELOPER_VPS_RUNNER_ID',
			'ssh_host_runner' => 'MAD4B_MCP_DEVELOPER_SSH_RUNNER_ID',
		);
		$results = array();
		$cached = function_exists( 'get_transient' ) ? get_transient( 'mad4b_developer_runner_discovery' ) : false;
		foreach ( $definitions as $backend => $constant ) {
			$configured = defined( $constant ) && is_string( constant( $constant ) )
				&& 1 === preg_match( '/^[a-zA-Z0-9._:-]{8,128}$/D', constant( $constant ) );
			$results[ $backend ] = array(
				'configured' => (bool) $configured,
				'discovered' => (bool) $configured || ( is_array( $cached ) && ! empty( $cached['backends'][$backend] ) ),
				'live_available_observed' => is_array( $cached ) && ! empty( $cached['backends'][$backend] ),
				'identity_verified' => false,
				'isolation_verified' => false,
				'capability_attested' => false,
				'execution_certified' => false,
				'execution_allowed' => false,
				'next_action' => $configured ? 'verify_runner_identity_isolation_and_signed_capability_attestation' : 'configure_reviewed_runner_identity',
			);
		}
		return $results;
	}

	/** A bounded, passive discovery refresh. Never grants execution authority. */
	public static function lifecycle_refresh() {
		if ( ! function_exists( 'wp_get_environment_type' ) || ! in_array( wp_get_environment_type(), array( 'staging', 'development', 'local' ), true ) ) return false;
		if ( ! function_exists( 'get_transient' ) || ! function_exists( 'set_transient' ) || ! function_exists( 'wp_safe_remote_get' ) ) return false;
		if ( ! defined( 'MAD4B_MCP_DEVELOPER_RUNNER_DISCOVERY_URL' ) || ! defined( 'MAD4B_MCP_DEVELOPER_RUNNER_DISCOVERY_ORIGIN' ) ) return false;
		$url = (string) MAD4B_MCP_DEVELOPER_RUNNER_DISCOVERY_URL;
		$origin = (string) MAD4B_MCP_DEVELOPER_RUNNER_DISCOVERY_ORIGIN;
		$u = function_exists( 'wp_parse_url' ) ? wp_parse_url( $url ) : parse_url( $url );
		$o = function_exists( 'wp_parse_url' ) ? wp_parse_url( $origin ) : parse_url( $origin );
		if ( ! is_array( $u ) || ! is_array( $o ) || ( $u['scheme'] ?? '' ) !== 'https' || ( $o['scheme'] ?? '' ) !== 'https'
			|| empty( $u['host'] ) || ! hash_equals( strtolower( (string)( $o['host'] ?? '' ) ), strtolower( (string)$u['host'] ) )
			|| isset( $u['user'] ) || isset( $u['pass'] ) || isset( $u['fragment'] ) || isset( $u['port'] ) ) return false;
		if ( get_transient( 'mad4b_developer_runner_probe_cooldown' ) ) return false;
		set_transient( 'mad4b_developer_runner_probe_cooldown', 1, 300 );
		$response = wp_safe_remote_get( $url, array( 'timeout' => 3, 'redirection' => 0, 'limit_response_size' => 2048, 'headers' => array( 'Accept' => 'application/json' ) ) );
		if ( function_exists( 'is_wp_error' ) && is_wp_error( $response ) ) return false;
		if ( ! function_exists( 'wp_remote_retrieve_response_code' ) || 200 !== wp_remote_retrieve_response_code( $response ) ) return false;
		$body = function_exists( 'wp_remote_retrieve_body' ) ? wp_remote_retrieve_body( $response ) : '';
		$data = json_decode( (string)$body, true );
		if ( ! is_array( $data ) || ( $data['contract'] ?? '' ) !== 'mad4b.runner-discovery.v1' || ! is_array( $data['backends'] ?? null ) ) return false;
		$observed = array();
		foreach ( array( 'isolated_vps_runner', 'ssh_host_runner' ) as $backend ) {
			$row = $data['backends'][$backend] ?? null;
			$observed[$backend] = is_array( $row ) && true === ( $row['available'] ?? null );
		}
		set_transient( 'mad4b_developer_runner_discovery', array( 'backends' => $observed, 'observed_at' => time() ), 600 );
		return true;
	}

	public static function discovery_boot() {
		if ( function_exists( 'add_action' ) ) {
			add_action( 'mad4b_developer_runner_refresh', array( __CLASS__, 'lifecycle_refresh' ) );
			add_action( 'admin_init', array( __CLASS__, 'maybe_schedule_discovery' ), 99 );
		}
	}

	public static function maybe_schedule_discovery() {
		if ( ! defined( 'MAD4B_MCP_DEVELOPER_RUNNER_DISCOVERY_URL' ) || ! defined( 'MAD4B_MCP_DEVELOPER_RUNNER_DISCOVERY_ORIGIN' ) ) return;
		if ( ! function_exists( 'current_user_can' ) || ! current_user_can( 'manage_options' )
			|| ! function_exists( 'wp_next_scheduled' ) || ! function_exists( 'wp_schedule_event' ) ) return;
		if ( ! wp_next_scheduled( 'mad4b_developer_runner_refresh' ) ) wp_schedule_event( time() + 60, 'hourly', 'mad4b_developer_runner_refresh' );
	}

	public static function wp_cli_binary() {
		$candidates = array();
		if ( defined( 'MAD4B_MCP_WP_CLI_BIN' ) ) $candidates[] = (string) constant( 'MAD4B_MCP_WP_CLI_BIN' );
		return self::first_executable( array_merge( $candidates, array( '/usr/local/bin/wp', '/usr/bin/wp', '/bin/wp' ) ) );
	}

	public static function shell_binary() {
		return self::first_executable( array( '/bin/bash', '/bin/sh' ) );
	}

	public static function prlimit_binary() {
		$candidates = array();
		if ( defined( 'MAD4B_MCP_DEVELOPER_PRLIMIT_BIN' ) ) $candidates[] = (string) constant( 'MAD4B_MCP_DEVELOPER_PRLIMIT_BIN' );
		return self::first_executable( array_merge( $candidates, array( '/usr/bin/prlimit', '/bin/prlimit' ) ) );
	}

	public static function network_sandbox_binary() {
		$candidates = array();
		if ( defined( 'MAD4B_MCP_DEVELOPER_NETWORK_SANDBOX_BIN' ) ) $candidates[] = (string) constant( 'MAD4B_MCP_DEVELOPER_NETWORK_SANDBOX_BIN' );
		return self::first_executable( array_merge( $candidates, array( '/usr/bin/bwrap', '/bin/bwrap', '/usr/bin/unshare', '/bin/unshare' ) ) );
	}

	public static function network_sandbox_type() {
		$binary = self::network_sandbox_binary();
		if ( '' === $binary ) return '';
		$name = strtolower( basename( $binary ) );
		if ( 'bwrap' === $name ) return 'bubblewrap';
		if ( 'unshare' === $name ) return 'unshare-net';
		return 'configured';
	}

	private static function root_state() {
		if ( ! function_exists( 'posix_geteuid' ) ) return null;
		return 0 === (int) posix_geteuid();
	}

	private static function first_executable( array $candidates ) {
		foreach ( $candidates as $candidate ) {
			$candidate = trim( (string) $candidate );
			if ( '' !== $candidate && is_file( $candidate ) && is_executable( $candidate ) ) return $candidate;
		}
		return '';
	}
}
