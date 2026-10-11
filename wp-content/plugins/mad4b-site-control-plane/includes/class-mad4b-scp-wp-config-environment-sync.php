<?php
/**
 * Staging-only WordPress config correction on a verified admin Site Profile save.
 *
 * No arbitrary PHP, path, environment value, shell, remote caller, or WordPress
 * option can be written through this class. The existing explicit local
 * non-Production attestation and administrator nonce are its authority.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class MAD4B_SCP_WP_Config_Environment_Sync {
	const MAX_CONFIG_BYTES = 262144;
	const INSERT = "\n/* MAD4B Site Profile: verified Staging bootstrap. */\ndefine( 'WP_ENVIRONMENT_TYPE', 'staging' );\n";

	private static function verdict( $state, $written = false, $fresh = false ) {
		return array(
			'state' => (string) $state,
			'file_modified' => (bool) $written,
			'file_readback_verified' => (bool) $written,
			'fresh_request_verification_required' => (bool) $fresh,
			'production_authorized' => false,
		);
	}

	/** Transform only WordPress's standard, unconfigured bootstrap. */
	public static function insert_staging_bootstrap( $original ) {
		if ( ! is_string( $original ) || strlen( $original ) < 20 ||
			strlen( $original ) > self::MAX_CONFIG_BYTES - strlen( self::INSERT ) ||
			0 !== strpos( $original, '<?php' ) ) {
			return new WP_Error( 'mad4b_wp_config_format_unsupported', 'WordPress configuration format or size is unsupported.' );
		}
		// Reject even a commented environment declaration. A second definition
		// could silently disagree with a host-managed constant.
		if ( false !== strpos( $original, 'WP_ENVIRONMENT_TYPE' ) ) {
			return new WP_Error( 'mad4b_wp_config_environment_present', 'The WordPress configuration already refers to WP_ENVIRONMENT_TYPE. No automatic rewrite is allowed.' );
		}
		$matches = array();
		$count = preg_match_all( '/^[ \t]*require_once[ \t]+ABSPATH[ \t]*\.[ \t]*[\'"]wp-settings\.php[\'"][ \t]*;/m', $original, $matches, PREG_OFFSET_CAPTURE );
		if ( 1 !== $count ) {
			return new WP_Error( 'mad4b_wp_config_bootstrap_ambiguous', 'An exact, unique standard WordPress settings bootstrap is required.' );
		}
		$offset = (int) $matches[0][0][1];
		return substr( $original, 0, $offset ) . self::INSERT . substr( $original, $offset );
	}

	/** Locate exactly the wp-config.php WordPress itself would bootstrap. */
	public static function installed_config_path() {
		$root = realpath( ABSPATH );
		if ( false === $root || ! is_dir( $root ) ) return new WP_Error( 'mad4b_wp_config_root_missing', 'WordPress root unavailable.' );
		$root = rtrim( $root, '/\\' );
		$local = $root . '/wp-config.php';
		if ( is_link( $local ) ) return new WP_Error( 'mad4b_wp_config_symlink_denied', 'A linked WordPress config is not eligible.' );
		if ( is_file( $local ) ) return $local;
		if ( file_exists( $local ) ) return new WP_Error( 'mad4b_wp_config_local_invalid', 'Local WordPress config is not a regular file.' );
		$parent = dirname( $root );
		if ( ! is_dir( $parent ) || is_link( $parent ) ||
			file_exists( $parent . '/wp-settings.php' ) || is_link( $parent . '/wp-settings.php' ) )
			return new WP_Error( 'mad4b_wp_config_parent_denied', 'Parent folder cannot be used as a WordPress configuration root.' );
		$candidate = $parent . '/wp-config.php';
		if ( is_link( $candidate ) || ! is_file( $candidate ) )
			return new WP_Error( 'mad4b_wp_config_missing', 'A supported, regular WordPress configuration file was not found.' );
		return $candidate;
	}

	/** Bounded file eligibility check for admin/assistant diagnostics. Never return bytes or paths. */
	public static function preflight_readonly( array $status ) {
		if ( 'staging' !== (string) ( $status['configured_environment'] ?? '' ) ||
			'host_managed' !== (string) ( $status['environment_sync_mode'] ?? '' ) )
			return self::verdict( 'not_requested' );
		if ( ! empty( $status['wordpress_environment_explicit'] ) )
			return self::verdict( 'blocked_explicit_host_conflict' );
		if ( 'production' !== (string) ( $status['wordpress_environment'] ?? '' ) )
			return self::verdict( 'blocked_unknown_host_environment' );
		if ( empty( $status['authority_ready'] ) || empty( $status['origin_match'] ) ||
			empty( $status['environment_match'] ) || empty( $status['profile_environment_authoritative'] ) ||
			empty( $status['implicit_nonproduction_override_confirmed'] ) ||
			! empty( $status['mutation_pending_audit'] ) )
			return self::verdict( 'blocked_unverified_site_profile' );
		$path = self::installed_config_path();
		if ( is_wp_error( $path ) ) return self::verdict( $path->get_error_code() );
		if ( ! is_writable( $path ) || ! is_writable( dirname( $path ) ) )
			return self::verdict( 'blocked_wp_config_not_writable' );
		$stat = @stat( $path );
		if ( ! is_array( $stat ) || ! isset( $stat['uid'] ) )
			return self::verdict( 'blocked_wp_config_stat_missing' );
		if ( function_exists( 'posix_geteuid' ) && posix_geteuid() !== (int) $stat['uid'] )
			return self::verdict( 'blocked_wp_config_owner_mismatch' );
		$fh = @fopen( $path, 'rb' );
		if ( false === $fh ) return self::verdict( 'blocked_wp_config_unreadable' );
		$bytes = stream_get_contents( $fh, self::MAX_CONFIG_BYTES + 1 );
		fclose( $fh );
		if ( ! is_string( $bytes ) || strlen( $bytes ) > self::MAX_CONFIG_BYTES )
			return self::verdict( 'blocked_wp_config_bytes_invalid' );
		$expected = self::insert_staging_bootstrap( $bytes );
		if ( is_wp_error( $expected ) ) return self::verdict( $expected->get_error_code() );
		return self::verdict( 'eligible_for_admin_save' );
	}

	/**
	 * Save already passed capability + nonce + explicit non-Production
	 * confirmation + persisted profile audit. No generic mutation endpoint.
	 */
	public static function apply_from_verified_admin_save( array $status ) {
		if ( ! current_user_can( 'manage_options' ) ) return self::verdict( 'blocked_administrator_required' );
		if ( 'staging' !== (string) ( $status['configured_environment'] ?? '' ) ||
			'host_managed' !== (string) ( $status['environment_sync_mode'] ?? '' ) )
			return self::verdict( 'not_requested' );
		if ( 'staging' === (string) ( $status['wordpress_environment'] ?? '' ) &&
			! empty( $status['wordpress_environment_explicit'] ) )
			return self::verdict( 'already_aligned' );
		if ( ! empty( $status['wordpress_environment_explicit'] ) ||
			'production' !== (string) ( $status['wordpress_environment'] ?? '' ) )
			return self::verdict( 'blocked_explicit_or_unknown_host_environment' );
		if ( empty( $status['configured'] ) || empty( $status['origin_match'] ) ||
			empty( $status['environment_match'] ) || empty( $status['profile_environment_authoritative'] ) ||
			empty( $status['implicit_nonproduction_override_confirmed'] ) ||
			! empty( $status['mutation_pending_audit'] ) )
			return self::verdict( 'blocked_unverified_site_profile' );
		if ( ! class_exists( 'MAD4B_SCP_Site_Profile' ) ||
			! method_exists( 'MAD4B_SCP_Site_Profile', 'wordpress_environment_explicit' ) ||
			MAD4B_SCP_Site_Profile::wordpress_environment_explicit() )
			return self::verdict( 'blocked_host_environment_changed' );
		if ( ! function_exists( 'wp_get_environment_type' ) || 'production' !== wp_get_environment_type() )
			return self::verdict( 'blocked_host_environment_changed' );

		$path = self::installed_config_path();
		if ( is_wp_error( $path ) ) return self::verdict( $path->get_error_code() );
		if ( ! is_writable( $path ) || ! is_writable( dirname( $path ) ) )
			return self::verdict( 'blocked_wp_config_not_writable' );
		$original_stat = @stat( $path );
		if ( ! is_array( $original_stat ) || ! isset( $original_stat['ino'], $original_stat['uid'], $original_stat['mode'] ) )
			return self::verdict( 'blocked_wp_config_stat_missing' );
		if ( function_exists( 'posix_geteuid' ) && posix_geteuid() !== (int) $original_stat['uid'] )
			return self::verdict( 'blocked_wp_config_owner_mismatch' );
		$handle = @fopen( $path, 'rb' );
		if ( false === $handle ) return self::verdict( 'blocked_wp_config_unreadable' );
		if ( ! @flock( $handle, LOCK_EX | LOCK_NB ) ) {
			fclose( $handle );
			return self::verdict( 'blocked_wp_config_lock_busy' );
		}
		$original = stream_get_contents( $handle, self::MAX_CONFIG_BYTES + 1 );
		if ( ! is_string( $original ) || strlen( $original ) > self::MAX_CONFIG_BYTES ) {
			@flock( $handle, LOCK_UN ); fclose( $handle );
			return self::verdict( 'blocked_wp_config_bytes_invalid' );
		}
		$target = self::insert_staging_bootstrap( $original );
		if ( is_wp_error( $target ) ) {
			@flock( $handle, LOCK_UN ); fclose( $handle );
			return self::verdict( $target->get_error_code() );
		}
		$before = hash( 'sha256', $original );
		$after = hash( 'sha256', $target );
		$mode = (int) $original_stat['mode'] & 0777;
		$written = self::replace_exact( $path, $before, (int) $original_stat['ino'], $target, $mode );
		if ( ! $written ) {
			@flock( $handle, LOCK_UN ); fclose( $handle );
			return self::verdict( 'blocked_wp_config_changed_or_replace_failed' );
		}
		clearstatcache( true, $path );
		$verified = is_file( $path ) && ! is_link( $path ) &&
			hash_equals( $after, (string) @hash_file( 'sha256', $path ) );
		if ( $verified && class_exists( 'MAD4B_SCP_Audit' ) ) {
			$record = MAD4B_SCP_Audit::record( 'mad4b/wp-config-staging-environment-synced', array(
				'site_uuid' => (string) ( $status['site_uuid'] ?? '' ),
				'profile_digest' => (string) ( $status['profile_digest'] ?? '' ),
				'old_config_sha256' => $before, 'new_config_sha256' => $after,
				'fresh_request_pending' => true,
				'production_authorized' => false,
			), 'ok' );
			$verified = ! is_wp_error( $record );
		}
		if ( ! $verified ) {
			// Compensating rollback is allowed only if the file still equals our
			// exact write; never overwrite any concurrent host configuration.
			$current_stat = @stat( $path );
			$restored = is_array( $current_stat ) && self::replace_exact(
				$path, $after, (int) $current_stat['ino'], $original, $mode
			);
			@flock( $handle, LOCK_UN ); fclose( $handle );
			return self::verdict( $restored ? 'rolled_back_after_readback_or_audit_failure' : 'recovery_required_config_mismatch' );
		}
		if ( function_exists( 'opcache_invalidate' ) ) @opcache_invalidate( $path, true );
		@flock( $handle, LOCK_UN );
		fclose( $handle );
		return self::verdict( 'config_written_verified_new_request_required', true, true );
	}

	/** Atomic replacement; PHP-compatible temp extension avoids serving config as text. */
	private static function replace_exact( $path, $expected_sha, $expected_inode, $data, $mode ) {
		if ( is_link( $path ) || ! is_file( $path ) ) return false;
		try { $random = bin2hex( random_bytes( 16 ) ); } catch ( Exception $e ) { return false; }
		$tmp = dirname( $path ) . '/.mad4b-config-' . $random . '.php';
		$fh = @fopen( $tmp, 'x+b' );
		if ( false === $fh ) return false;
		$ok = @chmod( $tmp, 0600 );
		if ( $ok ) {
			$len = strlen( $data ); $offset = 0;
			while ( $offset < $len ) {
				$n = @fwrite( $fh, substr( $data, $offset ) );
				if ( false === $n || 0 === $n ) { $ok = false; break; }
				$offset += $n;
			}
			if ( $ok ) $ok = @fflush( $fh );
			if ( $ok && function_exists( 'fsync' ) ) $ok = @fsync( $fh );
		}
		fclose( $fh );
		if ( $ok ) $ok = @chmod( $tmp, $mode );
		clearstatcache( true, $path );
		$stat = @stat( $path );
		if ( ! $ok || ! is_array( $stat ) || (int) $stat['ino'] !== $expected_inode ||
			is_link( $path ) || ! hash_equals( $expected_sha, (string) @hash_file( 'sha256', $path ) ) ) $ok = false;
		if ( $ok ) $ok = @rename( $tmp, $path );
		if ( file_exists( $tmp ) ) @unlink( $tmp );
		return (bool) $ok;
	}
}
