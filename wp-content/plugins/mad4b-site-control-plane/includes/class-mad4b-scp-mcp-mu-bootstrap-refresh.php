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
	// Never reclaim a journal generation before the shared maintenance hard fence can expire.
	const TRANSACTION_STALE_AFTER = 1200;

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

	private static function valid_transaction_hash( $value, $allow_empty = false ) {
		$value = strtolower( trim( (string) $value ) );
		if ( $allow_empty && '' === $value ) return true;
		return 1 === preg_match( '/^[a-f0-9]{64}$/D', $value );
	}

	private static function new_transaction_id() {
		return substr( hash( 'sha256', microtime( true ) . ':' . uniqid( '', true ) . ':' . (int) getmypid() ), 0, 32 );
	}

	private static function read_transaction_option() {
		// The database option row is the authority. Persistent object caches
		// (Redis/Memcached or custom backends) are acceleration only. Evict the
		// per-option and negative-cache entries before every ownership decision so
		// stale cache state can only add latency, never grant filesystem authority.
		if ( function_exists( 'wp_cache_delete' ) ) {
			wp_cache_delete( self::TRANSACTION_OPTION, 'options' );
			wp_cache_delete( 'notoptions', 'options' );
		}
		return get_option( self::TRANSACTION_OPTION, null );
	}

	private static function compare_and_swap_transaction_record( array $expected, $replacement = null ) {
		self::read_transaction_option(); // evict persistent caches before the DB CAS.
		global $wpdb;
		$database_cas = is_object( $wpdb )
			&& isset( $wpdb->options )
			&& method_exists( $wpdb, 'prepare' )
			&& method_exists( $wpdb, 'query' )
			&& function_exists( 'maybe_serialize' );
		if ( $database_cas ) {
			if ( null === $replacement ) {
				$sql = $wpdb->prepare(
					"DELETE FROM {$wpdb->options} WHERE option_name = %s AND BINARY option_value = BINARY %s",
					self::TRANSACTION_OPTION,
					maybe_serialize( $expected )
				);
			} else {
				$sql = $wpdb->prepare(
					"UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND BINARY option_value = BINARY %s",
					maybe_serialize( $replacement ),
					self::TRANSACTION_OPTION,
					maybe_serialize( $expected )
				);
			}
			$changed = $wpdb->query( $sql ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.NotPrepared
			if ( 1 !== (int) $changed ) return false;
		} else {
			$current = self::read_transaction_option();
			if ( serialize( $current ) !== serialize( $expected ) ) return false;
			if ( null === $replacement ) {
				if ( false === delete_option( self::TRANSACTION_OPTION ) ) return false;
			} elseif ( false === update_option( self::TRANSACTION_OPTION, $replacement, false ) ) return false;
		}
		if ( function_exists( 'wp_cache_delete' ) ) {
			wp_cache_delete( self::TRANSACTION_OPTION, 'options' );
			wp_cache_delete( 'notoptions', 'options' );
		}
		$readback = get_option( self::TRANSACTION_OPTION, null );
		return null === $replacement
			? null === $readback
			: serialize( $readback ) === serialize( $replacement );
	}

	public static function runtime_transaction_gate() {
		$record = self::read_transaction_option();
		if ( null === $record ) return array( 'ready' => true, 'state' => 'absent', 'blocker' => '' );
		if ( ! is_array( $record ) || empty( $record ) ) {
			return array( 'ready' => false, 'state' => 'invalid', 'blocker' => 'mu_bootstrap_transaction_invalid' );
		}
		$transaction_id = isset( $record['transaction_id'] ) && is_string( $record['transaction_id'] ) ? strtolower( trim( $record['transaction_id'] ) ) : '';
		$state = isset( $record['state'] ) ? sanitize_key( (string) $record['state'] ) : '';
		$previous = isset( $record['previous_sha256'] ) ? strtolower( trim( (string) $record['previous_sha256'] ) ) : '';
		$target = isset( $record['target_sha256'] ) ? strtolower( trim( (string) $record['target_sha256'] ) ) : '';
		$valid = self::TRANSACTION_CONTRACT === ( $record['contract'] ?? '' )
			&& 1 === preg_match( '/^[a-f0-9]{32}$/D', $transaction_id )
			&& in_array( $state, array( 'prepared', 'replaced_pending_audit', 'blocked' ), true )
			&& self::valid_transaction_hash( $previous, true )
			&& self::valid_transaction_hash( $target );
		return $valid
			? array( 'ready' => false, 'state' => 'pending', 'transaction_state' => $state, 'blocker' => 'mu_bootstrap_transaction_pending' )
			: array( 'ready' => false, 'state' => 'invalid', 'blocker' => 'mu_bootstrap_transaction_invalid' );
	}

	private static function transaction_record_for_owner( $transaction_id ) {
		$transaction_id = strtolower( trim( (string) $transaction_id ) );
		$record = self::read_transaction_option();
		if ( ! is_array( $record ) || self::TRANSACTION_CONTRACT !== ( $record['contract'] ?? '' ) ) {
			return new WP_Error( 'mu_bootstrap_transaction_missing', 'MU filesystem transaction marker is missing.' );
		}
		$stored_id = isset( $record['transaction_id'] ) && is_string( $record['transaction_id'] ) ? strtolower( $record['transaction_id'] ) : '';
		if ( '' === $transaction_id || '' === $stored_id || ! hash_equals( $stored_id, $transaction_id ) ) {
			return new WP_Error( 'mu_bootstrap_transaction_not_owner', 'Another worker owns the MU filesystem transaction.' );
		}
		return $record;
	}

	public static function begin_transaction( $operation, $previous_sha256, $target_sha256 ) {
		$operation = sanitize_key( (string) $operation );
		$previous_sha256 = strtolower( trim( (string) $previous_sha256 ) );
		$target_sha256 = strtolower( trim( (string) $target_sha256 ) );
		if ( ! in_array( $operation, array( 'install', 'refresh' ), true )
			|| ! self::valid_transaction_hash( $previous_sha256, true )
			|| ! self::valid_transaction_hash( $target_sha256 ) ) {
			return new WP_Error( 'mu_bootstrap_transaction_invalid_input', 'MU filesystem transaction input is invalid.' );
		}
		$transaction_id = self::new_transaction_id();
		$record = array(
			'contract' => self::TRANSACTION_CONTRACT,
			'transaction_id' => $transaction_id,
			'state' => 'prepared',
			'operation' => $operation,
			'previous_sha256' => $previous_sha256,
			'target_sha256' => $target_sha256,
			'created_at' => time(),
		);
		// wp_options.option_name is unique; add_option is the cross-worker compare-
		// and-set. A second worker cannot acquire the same filesystem transaction.
		if ( ! add_option( self::TRANSACTION_OPTION, $record, '', false ) ) {
			return new WP_Error( 'mu_bootstrap_transaction_already_pending', 'A prior MU filesystem transaction must be reconciled first.' );
		}
		$readback = self::read_transaction_option();
		if ( ! is_array( $readback ) || ! isset( $readback['transaction_id'] ) || ! hash_equals( $transaction_id, (string) $readback['transaction_id'] ) ) {
			return new WP_Error( 'mu_bootstrap_transaction_persist_failed', 'MU filesystem transaction marker could not be persisted.' );
		}
		return $transaction_id;
	}

	public static function mark_transaction_replaced( $transaction_id ) {
		$record = self::transaction_record_for_owner( $transaction_id );
		if ( is_wp_error( $record ) ) return $record;
		if ( 'prepared' !== ( $record['state'] ?? '' ) ) return new WP_Error( 'mu_bootstrap_transaction_state_invalid', 'MU filesystem transaction is not in the prepared state.' );
		$replacement = $record;
		$replacement['state'] = 'replaced_pending_audit';
		$replacement['replaced_at'] = time();
		return self::compare_and_swap_transaction_record( $record, $replacement )
			? true
			: new WP_Error( 'mu_bootstrap_transaction_state_persist_failed', 'MU filesystem transaction ownership changed before replacement state could be committed.' );
	}

	public static function block_transaction( $blocker, $transaction_id ) {
		$record = self::transaction_record_for_owner( $transaction_id );
		if ( is_wp_error( $record ) ) return $record;
		$replacement = $record;
		$replacement['state'] = 'blocked';
		$replacement['blocker'] = sanitize_key( (string) $blocker );
		$replacement['blocked_at'] = time();
		return self::compare_and_swap_transaction_record( $record, $replacement )
			? true
			: new WP_Error( 'mu_bootstrap_transaction_block_persist_failed', 'MU filesystem transaction ownership changed before blocker state could be committed.' );
	}

	public static function complete_transaction( $transaction_id ) {
		$record = self::transaction_record_for_owner( $transaction_id );
		if ( is_wp_error( $record ) ) return false;
		return self::compare_and_swap_transaction_record( $record, null );
	}

	private static function transaction_is_stale( array $record ) {
		$created_at = isset( $record['created_at'] ) ? (int) $record['created_at'] : 0;
		return $created_at > 0 && ( time() - $created_at ) >= self::TRANSACTION_STALE_AFTER;
	}

	public static function reconcile_transaction( $destination ) {
		$record = self::read_transaction_option();
		if ( null === $record ) return true;
		if ( ! is_array( $record ) || empty( $record ) ) return new WP_Error( 'mu_bootstrap_transaction_invalid', 'Malformed MU filesystem transaction state blocks runtime loading.' );
		if ( self::TRANSACTION_CONTRACT !== ( $record['contract'] ?? '' ) ) return new WP_Error( 'mu_bootstrap_transaction_invalid', 'Unknown MU filesystem transaction state blocks runtime loading.' );
		$transaction_id = isset( $record['transaction_id'] ) && is_string( $record['transaction_id'] ) ? strtolower( trim( $record['transaction_id'] ) ) : '';
		$state = isset( $record['state'] ) ? sanitize_key( (string) $record['state'] ) : '';
		if ( 1 !== preg_match( '/^[a-f0-9]{32}$/D', $transaction_id )
			|| ! in_array( $state, array( 'prepared', 'replaced_pending_audit', 'blocked' ), true ) ) {
			return new WP_Error( 'mu_bootstrap_transaction_invalid', 'Malformed MU filesystem transaction state blocks runtime loading.' );
		}
		if ( 'blocked' !== $state && ! self::transaction_is_stale( $record ) ) {
			return new WP_Error( 'mu_bootstrap_transaction_in_progress', 'Another worker is still inside the MU filesystem transaction.' );
		}
		$previous = isset( $record['previous_sha256'] ) ? strtolower( (string) $record['previous_sha256'] ) : '';
		$target = isset( $record['target_sha256'] ) ? strtolower( (string) $record['target_sha256'] ) : '';
		if ( ! self::valid_transaction_hash( $previous, true ) || ! self::valid_transaction_hash( $target ) ) {
			return new WP_Error( 'mu_bootstrap_transaction_invalid', 'Malformed MU filesystem hashes block runtime loading.' );
		}
		$current = is_file( $destination ) && is_readable( $destination ) ? hash_file( 'sha256', $destination ) : '';
		$current = is_string( $current ) ? strtolower( $current ) : '';
		if ( hash_equals( $previous, $current ) ) return self::complete_transaction( $transaction_id ) ? true : new WP_Error( 'mu_bootstrap_transaction_clear_failed', 'Rolled-back MU transaction marker could not be cleared.' );
		if ( hash_equals( $target, $current ) ) {
			$audit = class_exists( 'MAD4B_SCP_Audit' ) ? MAD4B_SCP_Audit::storage_status() : array( 'ready' => false );
			if ( empty( $audit['ready'] ) ) return new WP_Error( 'mu_bootstrap_transaction_audit_unavailable', 'MU transaction reached target bytes but audit storage is unavailable.' );
			$event = MAD4B_SCP_Audit::record( 'mad4b/mcp-mu-filesystem-transaction-recovered', array(
				'contract' => self::TRANSACTION_CONTRACT,
				'operation' => sanitize_key( (string) ( $record['operation'] ?? '' ) ),
				'previous_sha256' => $previous,
				'target_sha256' => $target,
			), 'ok' );
			if ( is_wp_error( $event ) ) return new WP_Error( 'mu_bootstrap_transaction_recovery_audit_failed', 'MU transaction target bytes require successful recovery audit before use.' );
			return self::complete_transaction( $transaction_id ) ? true : new WP_Error( 'mu_bootstrap_transaction_clear_failed', 'Recovered MU transaction marker could not be cleared.' );
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
		$transaction_id = self::begin_transaction( 'refresh', $status['destination_sha256_before'], $status['source_sha256'] );
		if ( is_wp_error( $transaction_id ) ) {
			@unlink( $temp );
			$status['blocker'] = $transaction_id->get_error_code();
			self::$status = $status;
			return $status;
		}
		$owner_before_replace = self::transaction_record_for_owner( $transaction_id );
		if ( is_wp_error( $owner_before_replace ) || 'prepared' !== ( $owner_before_replace['state'] ?? '' ) ) {
			@unlink( $temp );
			$status['blocker'] = is_wp_error( $owner_before_replace ) ? $owner_before_replace->get_error_code() : 'mu_bootstrap_transaction_state_invalid';
			self::$status = $status;
			return $status;
		}
		if ( ! @rename( $temp, $destination ) ) {
			@unlink( $temp );
			$current_hash = is_readable( $destination ) ? hash_file( 'sha256', $destination ) : '';
			if ( is_string( $current_hash ) && hash_equals( $status['destination_sha256_before'], $current_hash ) ) {
				self::complete_transaction( $transaction_id );
				$status['blocker'] = 'mu_bootstrap_refresh_atomic_replace_failed';
			} else {
				self::block_transaction( 'mu_bootstrap_refresh_atomic_replace_bytes_changed', $transaction_id );
				$status['blocker'] = 'mu_bootstrap_refresh_atomic_replace_bytes_changed';
			}
			self::$status = $status;
			return $status;
		}
		clearstatcache( true, $destination );
		$status['opcache_invalidation'] = self::invalidate_managed_opcode( $destination );
		$status['runtime_restart_required'] = ! $status['opcache_invalidation']['verified'];
		$after_hash = is_readable( $destination ) ? hash_file( 'sha256', $destination ) : '';
		if ( ! is_string( $after_hash ) || ! hash_equals( $status['source_sha256'], $after_hash ) ) {
			$rollback_owner = self::transaction_record_for_owner( $transaction_id );
			$restored = ! is_wp_error( $rollback_owner ) && self::restore_bytes( $destination, $before );
			if ( $restored ) self::complete_transaction( $transaction_id ); else self::block_transaction( 'mu_bootstrap_refresh_post_replace_rollback_failed', $transaction_id );
			$status['blocker'] = $restored ? 'mu_bootstrap_refresh_post_replace_integrity_failed' : 'mu_bootstrap_refresh_post_replace_rollback_failed';
			self::$status = $status;
			return $status;
		}
		$marked = self::mark_transaction_replaced( $transaction_id );
		if ( is_wp_error( $marked ) ) {
			self::block_transaction( $marked->get_error_code(), $transaction_id );
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
			$rollback_owner = self::transaction_record_for_owner( $transaction_id );
			$restored = ! is_wp_error( $rollback_owner ) && self::restore_bytes( $destination, $before );
			if ( $restored ) self::complete_transaction( $transaction_id ); else self::block_transaction( 'audit_failed_mu_refresh_rollback_failed', $transaction_id );
			$status['blocker'] = $restored ? 'audit_failed_mu_refresh_rolled_back' : 'audit_failed_mu_refresh_rollback_failed';
			self::$status = $status;
			return $status;
		}

		if ( ! self::complete_transaction( $transaction_id ) ) {
			self::block_transaction( 'mu_bootstrap_refresh_transaction_finalize_failed', $transaction_id );
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

	private static function invalidate_managed_opcode( $path ) {
		$result = array( 'available' => false, 'verified' => false );
		if ( function_exists( 'wp_opcache_invalidate' ) ) {
			$result['available'] = true;
			$result['verified'] = false !== wp_opcache_invalidate( $path, true );
		} elseif ( function_exists( 'opcache_invalidate' ) ) {
			$result['available'] = true;
			$result['verified'] = false !== @opcache_invalidate( $path, true );
		}
		return $result;
	}

	private static function restore_bytes( $destination, $bytes ) {
		$temp = $destination . '.rollback-' . (int) getmypid() . '-' . substr( hash( 'sha256', microtime( true ) . ':' . uniqid( '', true ) ), 0, 12 );
		$written = @file_put_contents( $temp, $bytes, LOCK_EX );
		if ( false === $written ) return false;
		if ( ! @rename( $temp, $destination ) ) { @unlink( $temp ); return false; }
		clearstatcache( true, $destination );
		self::invalidate_managed_opcode( $destination );
		$expected = hash( 'sha256', $bytes );
		$actual = is_readable( $destination ) ? hash_file( 'sha256', $destination ) : '';
		return is_string( $actual ) && hash_equals( $expected, $actual );
	}

	private static function repair_lifecycle_allowed() {
		if ( class_exists( 'MAD4B_SCP_MCP_Runtime_Recovery', false ) && MAD4B_SCP_MCP_Runtime_Recovery::active() ) return true;
		if ( defined( 'WP_CLI' ) && constant( 'WP_CLI' ) ) {
			// Generic WP-CLI commands are not MCP repair lifecycles. Explicit MCP
			// CLI work opts in before WordPress/MU bootstrap; authorized recovery
			// remains covered by the active() branch above.
			return defined( 'MAD4B_SCP_MCP_CLI_REQUEST' ) && true === constant( 'MAD4B_SCP_MCP_CLI_REQUEST' );
		}
		// Generic wp-cron.php is also infrastructure, not mutation authority. The
		// dedicated recovery hook and Runtime Convergence both set Recovery::active()
		// before calling bootstrap(), so they are admitted by the first branch.
		if ( function_exists( 'wp_doing_cron' ) && wp_doing_cron() ) return false;
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
			'transaction_store' => 'wp_options_unique_option',
			'transaction_option_autoload' => false,
			'persistent_object_cache_authoritative' => false,
			'filesystem_replace_strategy' => 'same_directory_atomic_rename',
			'filesystem_replace_atomicity_required' => true,
			'non_atomic_replace_fallback' => false,
			'shared_filesystem_certified' => false,
			'next_request_required' => false,
			'state' => $eligible ? 'inspection_pending' : 'ineligible',
			'blocker' => $blocker,
			'source_sha256' => '',
			'destination_sha256_before' => '',
			'destination_sha256_after' => '',
		);
	}
}