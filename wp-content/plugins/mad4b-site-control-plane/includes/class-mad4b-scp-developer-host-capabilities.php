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
			'process_backend_ready' => empty( $process_blockers ),
			'process_backend_blockers' => $process_blockers,
			'normal_no_network_execution_ready' => empty( $no_network_blockers ),
			'normal_no_network_execution_blockers' => $no_network_blockers,
			'workspace_php_lint_ready' => empty( $lint_blockers ),
			'workspace_php_lint_blockers' => $lint_blockers,
			'capability_fingerprint' => hash( 'sha256', is_string( $encoded ) ? $encoded : '' ),
		);
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
