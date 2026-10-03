<?php

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Refresh a stale MAD4B-managed MCP MU bootstrap after a Control Plane update.
 *
 * A previously installed managed MU file executes before regular plugins, so a
 * new Control Plane source cannot change the already-running request. On the
 * explicitly enrolled governed non-production site only, this reconciler atomically replaces a
 * recognized MAD4B v2/v3/v4 MU bootstrap with the current source and records the
 * change in the append-only audit. Reconciliation is lifecycle-only and is
 * deferred until init so ordinary request-serving GET/HEAD pages never perform
 * filesystem mutation. The next request then executes the new file.
 */
final class MAD4B_SCP_MCP_MU_Bootstrap_Refresh {
	const CONTRACT = 'mad4b.mcp-mu-bootstrap-refresh.v1';
	const SOURCE = 'bootstrap/mad4b-mcp-adapter-mu-bootstrap.php';
	const DESTINATION = '000-mad4b-mcp-adapter-bootstrap.php';
	const TRANSACTION_OPTION = 'mad4b_scp_mcp_mu_refresh_transaction_v1';
	const TRANSACTION_CONTRACT = 'mad4b.mcp-mu-filesystem-transaction.v1';

	private static $status = array();

	/** Exact historical artifacts that MAD4B is allowed to replace in-place. */
	public static function historical_managed_sha256( $hash ) {
		$hash = strtolower( trim( (string) $hash ) );
		return in_array( $hash, array(
			'53a5744144211fef792f5c0f27d59af04860e8343e1b24674531d4ffd767514b',
			'6519bc8cbbdd27e4016d69576d309f9acc045faf02cf4ebde826d4e1d7d29848',
			'876d9ef3634a9ef72187965cb08f53bad28b1aca3e10faa216df92bcbf0ef958',
			'd0f0291f0d82c7030afbed0ac3bc3799550d7438197433d4fee3adad145271e8',
			'8f4a35bfc4f8544c85683f4198b1378ad78c7d53ed528d5b13f2e8fa834ac57d',
			'a00886848e3988af1e9cf9e9f580b6a145b097e8d33a99b84a67f68b2dc1274d',
			'ed0b4616db0242b8a6419229c0d014010f0cbbf28f41be5d2c9cb832c54c08e4',
			'5cb213798516e7de9caecd212124ee14ca10c9571bca328e94883c82f3a9722c',
			'049ae5e77ba6da9068ca0316092d3a2a76143912846b491413b755900c95dd07',
			'86a15a9cdd57c1aea854bc131d8ad636bc5cfc4d6488931be0e633d76a3f7417',
			'a4870dbf8851320047fe69a6a9f583d0c54c8861fcb164d9afd2a971e4b19874',
			'48e331291a5375ec73b41bd9a95e460c4609cb67f84ce59ec9a8798f784ecdee',
		), true );
	}

	public static function begin_transaction( $operation, $previous_sha256, $target_sha256 ) {
		$existing = get_option( self::TRANSACTION_OPTION, array() );
		if ( is_array( $existing ) && ! empty( $existing ) ) return new WP_Error( 'mu_bootstrap_transaction_already_pending', 'A prior MU filesystem transaction must be reconciled first.' );
		$record = array(
			'contract' => self::TRANSACTION_CONTRACT,
			'state' => 'prepared',
			'operation' => sanitize_key( (string) $operation ),
			'previous_sha256' => strtolower( (string) $previous_sha256 ),
			'target_sha256' => strtolower( (string) $target_sha256 ),
			'created_at' => gmdate( 'c' ),
		);
		update_option( self::TRANSACTION_OPTION, $record, false );
		return $record === get_option( self::TRANSACTION_OPTION, array() )
			? true
			: new WP_Error( 'mu_bootstrap_transaction_persist_failed', 'MU filesystem transaction marker could not be persisted.' );
	}

	public static function mark_transaction_replaced() {
		$record = get_option( self::TRANSACTION_OPTION, array() );
		if ( ! is_array( $record ) || self::TRANSACTION_CONTRACT !== ( $record['contract'] ?? '' ) ) return new WP_Error( 'mu_bootstrap_transaction_missing', 'MU filesystem transaction marker is missing.' );
		$record['state'] = 'replaced_pending_audit';
		$record['replaced_at'] = gmdate( 'c' );
		update_option( self::TRANSACTION_OPTION, $record, false );
		return $record === get_option( self::TRANSACTION_OPTION, array() )
			? true
			: new WP_Error( 'mu_bootstrap_transaction_state_persist_failed', 'MU filesystem transaction replacement state could not be persisted.' );
	}

	public static function block_transaction( $blocker ) {
		$record = get_option( self::TRANSACTION_OPTION, array() );
		if ( ! is_array( $record ) || empty( $record ) ) $record = array( 'contract' => self::TRANSACTION_CONTRACT );
		$record['state'] = 'blocked';
		$record['blocker'] = sanitize_key( (string) $blocker );
		$record['blocked_at'] = gmdate( 'c' );
		update_option( self::TRANSACTION_OPTION, $record, false );
	}

	public static function complete_transaction() {
		delete_option( self::TRANSACTION_OPTION );
		return false === get_option( self::TRANSACTION_OPTION, false );
	}

	public static function reconcile_transaction( $destination ) {
		$record = get_option( self::TRANSACTION_OPTION, array() );
		if ( ! is_array( $record ) || empty( $record ) ) return true;
		if ( self::TRANSACTION_CONTRACT !== ( $record['contract'] ?? '' ) ) return new WP_Error( 'mu_bootstrap_transaction_invalid', 'Unknown MU filesystem transaction state blocks runtime loading.' );
		$previous = isset( $record['previous_sha256'] ) ? strtolower( (string) $record['previous_sha256'] ) : '';
		$target = isset( $record['target_sha256'] ) ? strtolower( (string) $record['target_sha256'] ) : '';
		$current = is_file( $destination ) && is_readable( $destination ) ? hash_file( 'sha256', $destination ) : '';
		$current = is_string( $current ) ? strtolower( $current ) : '';
		if ( hash_equals( $previous, $current ) ) return self::complete_transaction() ? true : new WP_Error( 'mu_bootstrap_transaction_clear_failed', 'Rolled-back MU transaction marker could not be cleared.' );
		if ( '' !== $target && hash_equals( $target, $current ) ) {
			$audit = class_exists( 'MAD4B_SCP_Audit' ) ? MAD4B_SCP_Audit::storage_status() : array( 'ready' => false );
			if ( empty( $audit['ready'] ) ) return new WP_Error( 'mu_bootstrap_transaction_audit_unavailable', 'MU transaction reached target bytes but audit storage is unavailable.' );
			$event = MAD4B_SCP_Audit::record( 'mad4b/mcp-mu-filesystem-transaction-recovered', array(
				'contract' => self::TRANSACTION_CONTRACT,
				'operation' => sanitize_key( (string) ( $record['operation'] ?? '' ) ),
				'previous_sha256' => $previous,
				'target_sha256' => $target,
			), 'ok' );
			if ( is_wp_error( $event ) ) return new WP_Error( 'mu_bootstrap_transaction_recovery_audit_failed', 'MU transaction target bytes require successful recovery audit before use.' );
			return self::complete_transaction() ? true : new WP_Error( 'mu_bootstrap_transaction_clear_failed', 'Recovered MU transaction marker could not be cleared.' );
		}
		return new WP_Error( 'mu_bootstrap_transaction_bytes_ambiguous', 'MU filesystem bytes do not match either side of the pending transaction.' );
	}

	public static function bootstrap() {
		$status = self::base_status();
		if ( ! $status['eligible'] ) { self::$status = $status; return $status; }

		// Never reconcile managed MU bytes on MCP/OAuth protocol hot paths.
		// A post-update request may observe source/destination drift, but hashing,
		// copying, atomic replacement and audit persistence belong to a normal
		// lifecycle request, not to initialize/tools-list/execute latency.
		if ( class_exists( 'MAD4B_SCP_MCP_Request_Scope', false )
			&& MAD4B_SCP_MCP_Request_Scope::current_request_is_protocol_hotpath() ) {
			$status['state'] = 'deferred_protocol_hotpath';
			$status['refresh_deferred'] = true;
			$status['next_request_required'] = true;
			$status['blocker'] = '';
			self::$status = $status;
			return $status;
		}

		if ( ! self::repair_lifecycle_allowed() ) {
			$status['state'] = 'deferred_request_hotpath';
			$status['refresh_deferred'] = true;
			$status['next_request_required'] = false;
			$status['next_lifecycle_required'] = true;
			$status['blocker'] = '';
			self::$status = $status;
			return $status;
		}
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
		$transaction = self::reconcile_transaction( $destination );
		if ( is_wp_error( $transaction ) ) {
			$status['blocker'] = $transaction->get_error_code();
			$status['transaction_pending'] = true;
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
		$status['managed'] = self::historical_managed_sha256( $status['destination_sha256_before'] );
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
		$transaction = self::begin_transaction( 'refresh', $status['destination_sha256_before'], $status['source_sha256'] );
		if ( is_wp_error( $transaction ) ) {
			@unlink( $temp );
			$status['blocker'] = $transaction->get_error_code();
			self::$status = $status;
			return $status;
		}
		if ( ! @rename( $temp, $destination ) ) {
			@unlink( $temp );
			$reconciled = self::reconcile_transaction( $destination );
			$status['blocker'] = is_wp_error( $reconciled ) ? $reconciled->get_error_code() : 'mu_bootstrap_refresh_atomic_replace_failed';
			self::$status = $status;
			return $status;
		}
		clearstatcache( true, $destination );
		$after_hash = is_readable( $destination ) ? hash_file( 'sha256', $destination ) : '';
		if ( ! is_string( $after_hash ) || ! hash_equals( $status['source_sha256'], $after_hash ) ) {
			$restored = self::restore_bytes( $destination, $before );
			if ( $restored ) self::complete_transaction(); else self::block_transaction( 'mu_bootstrap_refresh_post_replace_rollback_failed' );
			$status['blocker'] = $restored ? 'mu_bootstrap_refresh_post_replace_integrity_failed' : 'mu_bootstrap_refresh_post_replace_rollback_failed';
			self::$status = $status;
			return $status;
		}
		$marked = self::mark_transaction_replaced();
		if ( is_wp_error( $marked ) ) {
			self::block_transaction( $marked->get_error_code() );
			$status['blocker'] = $marked->get_error_code();
			self::$status = $status;
			return $status;
		}

		$event = MAD4B_SCP_Audit::record(
			'mad4b/mcp-mu-bootstrap-refreshed',
			array(
				'contract' => self::CONTRACT,
				'environment' => isset( $status['environment'] ) ? $status['environment'] : 'unknown',
				'host' => isset( $status['host'] ) ? $status['host'] : '',
				'previous_sha256' => $status['destination_sha256_before'],
				'current_sha256' => $status['source_sha256'],
				'next_request_required' => true,
				'production_mutation' => false,
			),
			'ok'
		);
		if ( is_wp_error( $event ) ) {
			$restored = self::restore_bytes( $destination, $before );
			if ( $restored ) self::complete_transaction(); else self::block_transaction( 'audit_failed_mu_refresh_rollback_failed' );
			$status['blocker'] = $restored ? 'audit_failed_mu_refresh_rolled_back' : 'audit_failed_mu_refresh_rollback_failed';
			self::$status = $status;
			return $status;
		}

		if ( ! self::complete_transaction() ) {
			self::block_transaction( 'mu_bootstrap_refresh_transaction_finalize_failed' );
			$status['blocker'] = 'mu_bootstrap_refresh_transaction_finalize_failed';
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
		$expected = hash( 'sha256', $bytes );
		$actual = is_readable( $destination ) ? hash_file( 'sha256', $destination ) : '';
		return is_string( $actual ) && hash_equals( $expected, $actual );
	}

	private static function repair_lifecycle_allowed() {
		if ( class_exists( 'MAD4B_SCP_MCP_Runtime_Recovery', false ) && MAD4B_SCP_MCP_Runtime_Recovery::active() ) return true;
		if ( defined( 'WP_CLI' ) && constant( 'WP_CLI' ) ) return true;
		if ( function_exists( 'wp_doing_cron' ) && wp_doing_cron() ) return true;
		if ( is_admin() ) {

			global $pagenow;
			$screen = isset( $pagenow ) ? sanitize_key( (string) $pagenow ) : '';
			$lifecycle_screen = in_array( $screen, array( 'update.php', 'update-core.php', 'plugin-install.php', 'plugins.php' ), true );
			if ( $lifecycle_screen
				&& function_exists( 'current_user_can' )
				&& current_user_can( 'update_plugins' ) ) return true;
		}
		return false;
	}

	private static function base_status() {
		$environment = class_exists( 'MAD4B_SCP_Site_Profile' ) ? MAD4B_SCP_Site_Profile::current_environment() : 'unknown';
		$host = class_exists( 'MAD4B_SCP_Site_Profile' ) ? MAD4B_SCP_Site_Profile::current_host() : '';
		$eligible = class_exists( 'MAD4B_SCP_Site_Profile' ) && MAD4B_SCP_Site_Profile::nonproduction_governed( 'managed_runtime' );
		$blocker = '';
		if ( ! $eligible ) {
			if ( ! class_exists( 'MAD4B_SCP_Site_Profile' ) || ! MAD4B_SCP_Site_Profile::origin_enrolled() ) $blocker = 'site_profile_not_enrolled';
			elseif ( ! MAD4B_SCP_Site_Profile::managed_runtime_enabled() ) $blocker = 'site_profile_managed_runtime_disabled';
			else $blocker = 'managed_runtime_repair_nonproduction_only';
		}
		return array(
			'contract' => self::CONTRACT,
			'environment' => $environment,
			'host' => $host,
			'eligible' => $eligible,
			'present' => false,
			'managed' => false,
			'integrity_before' => false,
			'refresh_applied' => false,
			'transaction_pending' => false,
			'next_request_required' => false,
			'state' => $eligible ? 'inspection_pending' : 'ineligible',
			'blocker' => $blocker,
			'source_sha256' => '',
			'destination_sha256_before' => '',
			'destination_sha256_after' => '',
		);
	}
}