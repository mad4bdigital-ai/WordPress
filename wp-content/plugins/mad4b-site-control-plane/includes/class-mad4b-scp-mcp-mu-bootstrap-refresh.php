<?php

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Refresh a stale MAD4B-managed MCP MU bootstrap after a Control Plane update.
 *
 * A previously installed managed MU file executes before regular plugins, so a
 * new Control Plane source cannot change the already-running request. On the
 * exact governed Staging origin only, this reconciler atomically replaces a
 * recognized MAD4B v2/v3 MU bootstrap with the current source and records the
 * change in the append-only audit. The next request then executes the new file.
 */
final class MAD4B_SCP_MCP_MU_Bootstrap_Refresh {
	const CONTRACT = 'mad4b.mcp-mu-bootstrap-refresh.v1';
	const STAGING_HOST = 'staging.egypttourgates.com';
	const SOURCE = 'bootstrap/mad4b-mcp-adapter-mu-bootstrap.php';
	const DESTINATION = '000-mad4b-mcp-adapter-bootstrap.php';

	private static $status = array();

	public static function bootstrap() {
		$status = self::base_status();
		if ( ! $status['eligible'] ) { self::$status = $status; return $status; }
		if ( ! defined( 'MAD4B_SCP_DIR' ) || ! defined( 'WPMU_PLUGIN_DIR' ) ) {
			$status['blocker'] = 'mu_bootstrap_path_unavailable';
			self::$status = $status;
			return $status;
		}

		$source = trailingslashit( MAD4B_SCP_DIR ) . self::SOURCE;
		$destination = trailingslashit( WPMU_PLUGIN_DIR ) . self::DESTINATION;
		if ( ! is_readable( $source ) ) {
			$status['blocker'] = 'mu_bootstrap_source_unreadable';
			self::$status = $status;
			return $status;
		}
		if ( ! is_file( $destination ) ) {
			$status['state'] = 'managed_mu_absent';
			self::$status = $status;
			return $status;
		}

		$status['present'] = true;
		$source_hash = hash_file( 'sha256', $source );
		$destination_hash = is_readable( $destination ) ? hash_file( 'sha256', $destination ) : '';
		$status['source_sha256'] = is_string( $source_hash ) ? $source_hash : '';
		$status['destination_sha256_before'] = is_string( $destination_hash ) ? $destination_hash : '';
		if ( '' !== $status['source_sha256'] && '' !== $status['destination_sha256_before'] && hash_equals( $status['source_sha256'], $status['destination_sha256_before'] ) ) {
			$status['managed'] = true;
			$status['integrity_before'] = true;
			$status['state'] = 'managed_mu_current';
			self::$status = $status;
			return $status;
		}

		$before = @file_get_contents( $destination );
		if ( ! is_string( $before ) ) {
			$status['blocker'] = 'mu_bootstrap_destination_unreadable';
			self::$status = $status;
			return $status;
		}
		$status['managed'] = false !== strpos( $before, "mad4b.mcp-adapter-mu-bootstrap.v2" ) || false !== strpos( $before, "mad4b.mcp-adapter-mu-bootstrap.v3" );
		if ( ! $status['managed'] ) {
			$status['blocker'] = 'unmanaged_mu_bootstrap_path_conflict';
			self::$status = $status;
			return $status;
		}

		$audit = class_exists( 'MAD4B_SCP_Audit' ) ? MAD4B_SCP_Audit::storage_status() : array( 'ready' => false );
		if ( empty( $audit['ready'] ) ) {
			$status['blocker'] = 'audit_unavailable_for_mu_refresh';
			self::$status = $status;
			return $status;
		}
		if ( ! is_dir( WPMU_PLUGIN_DIR ) || ! is_writable( WPMU_PLUGIN_DIR ) ) {
			$status['blocker'] = 'mu_bootstrap_directory_not_writable';
			self::$status = $status;
			return $status;
		}

		$temp = $destination . '.refresh-' . (int) getmypid() . '-' . substr( hash( 'sha256', microtime( true ) . ':' . uniqid( '', true ) ), 0, 12 );
		if ( ! @copy( $source, $temp ) ) {
			$status['blocker'] = 'mu_bootstrap_refresh_temp_write_failed';
			self::$status = $status;
			return $status;
		}
		$temp_hash = is_readable( $temp ) ? hash_file( 'sha256', $temp ) : '';
		if ( '' === $status['source_sha256'] || ! is_string( $temp_hash ) || ! hash_equals( $status['source_sha256'], $temp_hash ) ) {
			@unlink( $temp );
			$status['blocker'] = 'mu_bootstrap_refresh_temp_integrity_failed';
			self::$status = $status;
			return $status;
		}
		if ( ! @rename( $temp, $destination ) ) {
			@unlink( $temp );
			$status['blocker'] = 'mu_bootstrap_refresh_atomic_replace_failed';
			self::$status = $status;
			return $status;
		}
		clearstatcache( true, $destination );
		$after_hash = is_readable( $destination ) ? hash_file( 'sha256', $destination ) : '';
		if ( ! is_string( $after_hash ) || ! hash_equals( $status['source_sha256'], $after_hash ) ) {
			self::restore_bytes( $destination, $before );
			$status['blocker'] = 'mu_bootstrap_refresh_post_replace_integrity_failed';
			self::$status = $status;
			return $status;
		}

		$event = MAD4B_SCP_Audit::record(
			'mad4b/mcp-mu-bootstrap-refreshed',
			array(
				'contract' => self::CONTRACT,
				'environment' => 'staging',
				'host' => self::STAGING_HOST,
				'previous_sha256' => $status['destination_sha256_before'],
				'current_sha256' => $status['source_sha256'],
				'next_request_required' => true,
				'production_mutation' => false,
			),
			'ok'
		);
		if ( is_wp_error( $event ) ) {
			$restored = self::restore_bytes( $destination, $before );
			$status['blocker'] = $restored ? 'audit_failed_mu_refresh_rolled_back' : 'audit_failed_mu_refresh_rollback_failed';
			self::$status = $status;
			return $status;
		}

		$status['refresh_applied'] = true;
		$status['next_request_required'] = true;
		$status['state'] = 'managed_mu_refreshed_for_next_request';
		$status['destination_sha256_after'] = $status['source_sha256'];
		self::$status = $status;
		return $status;
	}

	public static function status() {
		return ! empty( self::$status ) ? self::$status : self::base_status();
	}

	private static function restore_bytes( $destination, $bytes ) {
		$temp = $destination . '.rollback-' . (int) getmypid() . '-' . substr( hash( 'sha256', microtime( true ) . ':' . uniqid( '', true ) ), 0, 12 );
		$written = @file_put_contents( $temp, $bytes, LOCK_EX );
		if ( false === $written ) return false;
		if ( ! @rename( $temp, $destination ) ) { @unlink( $temp ); return false; }
		clearstatcache( true, $destination );
		return true;
	}

	private static function base_status() {
		$environment = function_exists( 'wp_get_environment_type' ) ? sanitize_key( (string) wp_get_environment_type() ) : 'unknown';
		$host = '';
		if ( function_exists( 'home_url' ) && function_exists( 'wp_parse_url' ) ) {
			$value = wp_parse_url( home_url( '/' ), PHP_URL_HOST );
			$host = is_string( $value ) ? strtolower( rtrim( trim( $value ), '.' ) ) : '';
		}
		$eligible = 'staging' === $environment && self::STAGING_HOST === $host;
		return array(
			'contract' => self::CONTRACT,
			'environment' => $environment,
			'host' => $host,
			'eligible' => $eligible,
			'present' => false,
			'managed' => false,
			'integrity_before' => false,
			'refresh_applied' => false,
			'next_request_required' => false,
			'state' => $eligible ? 'inspection_pending' : 'ineligible',
			'blocker' => $eligible ? '' : ( 'staging' !== $environment ? 'environment_not_staging' : 'origin_not_governed_staging' ),
			'source_sha256' => '',
			'destination_sha256_before' => '',
			'destination_sha256_after' => '',
		);
	}
}