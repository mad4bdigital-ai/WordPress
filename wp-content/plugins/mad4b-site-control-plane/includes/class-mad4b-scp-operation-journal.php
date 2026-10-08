<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
if ( ! class_exists( 'MAD4B_SCP_Database_Transaction_Guard' ) ) require_once __DIR__ . '/class-mad4b-scp-database-transaction-guard.php';
if ( ! class_exists( 'MAD4B_SCP_Identifiers' ) ) require_once __DIR__ . '/class-mad4b-scp-identifiers.php';

final class MAD4B_SCP_Operation_Journal {
	const CONTRACT = 'mad4b.dynamic-operation-journal.v1';
	const EVENT_CONTRACT = 'dynamic-operation-event:v1';
	const MAX_METADATA_BYTES = 16384;
	const MAX_METADATA_ITEMS = 100;
	const MAX_EVENTS_PER_OPERATION = 1000;
	const DEFAULT_STALE_SECONDS = 300;
	const REDACTED = '[redacted]';

	public static function begin( array $context, $lifecycle_state = 'planned', array $metadata = array() ) {
		global $wpdb;
		$valid = self::validate_context( $context );
		if ( is_wp_error( $valid ) ) return $valid;
		$transaction_preflight = MAD4B_SCP_Database_Transaction_Guard::preflight( array( 'operation_heads', 'operation_events' ), true );
		if ( is_wp_error( $transaction_preflight ) ) return $transaction_preflight;
		$t = MAD4B_SCP_Schema::tables();
		$now = gmdate( 'Y-m-d H:i:s' );
		$deadline = self::mysql_time( $context['hard_deadline_at'] );
		$inserted = $wpdb->query( $wpdb->prepare(
			"INSERT IGNORE INTO {$t['operation_heads']} (operation_id,operation_key,operation_binding_sha256,latest_sequence,latest_event_sha256,lifecycle_state,terminal_outcome,heartbeat_at,lock_expires_at,stale_after,hard_deadline_at,created_at,updated_at) VALUES (%s,%s,%s,0,%s,%s,'',%s,NULL,%s,%s,%s,%s)",
			$context['operation_id'], $context['operation_key'], $context['operation_binding_sha256'], str_repeat( '0', 64 ), sanitize_key( $lifecycle_state ), $now, gmdate( 'Y-m-d H:i:s', time() + self::DEFAULT_STALE_SECONDS ), $deadline, $now, $now
		) );
		if ( false === $inserted ) return MAD4B_SCP_Database_Failure_Semantics::error( 'mad4b_operation_journal_head_create_failed', 'Unable to initialize operation journal.', 'operation_journal_head_create', (string) $wpdb->last_error, null );
		// INSERT IGNORE returns zero when the head already exists. Never append
		// a second operation_started event or rewrite a competing owner identity.
		if ( 1 !== (int) $inserted ) {
			return new WP_Error( 'mad4b_operation_journal_head_already_exists',
				'Journal head already exists or creation is uncertain. Reconcile before retry.',
				array( 'reconciliation_required' => true, 'blind_retry_allowed' => false ) );
		}
		return self::append( $context, 'operation_started', array(
			'expected_sequence' => 0,
			'expected_event_sha256' => str_repeat( '0', 64 ),
			'checkpoint' => 'planned', 'lifecycle_state' => $lifecycle_state,
			'metadata' => $metadata ) );
	}

	public static function append( array $context, $event_type, array $args = array() ) {
		global $wpdb;
		$valid = self::validate_context( $context );
		if ( is_wp_error( $valid ) ) return $valid;
		// Opt-in exact-head CAS for governed assistant transitions. Existing
		// journal producers that do not request CAS retain their old contract.
		$has_expected_seq = array_key_exists( 'expected_sequence', $args );
		$has_expected_sha = array_key_exists( 'expected_event_sha256', $args );
		if ( $has_expected_seq !== $has_expected_sha ||
			( $has_expected_seq && ( ! is_int( $args['expected_sequence'] ) || $args['expected_sequence'] < 0
			|| ! is_string( $args['expected_event_sha256'] )
			|| 1 !== preg_match( '/^[a-f0-9]{64}$/D', $args['expected_event_sha256'] ) ) ) ) {
			return new WP_Error( 'mad4b_operation_journal_cas_invalid', 'The exact journal CAS precondition is malformed or incomplete.' );
		}
		$event_type = sanitize_key( (string) $event_type );
		if ( '' === $event_type ) return new WP_Error( 'mad4b_operation_event_type_invalid', 'Operation event_type is required.' );
		// Assistant task events are never permitted through the legacy unbound
		// append lane. This is a mandatory journal CAS, not a caller preference.
		if ( 0 === strpos( $event_type, 'assistant_task_' ) && ! $has_expected_seq ) {
			return new WP_Error( 'mad4b_operation_journal_cas_required',
				'Assistant task journal writes require both exact-head CAS preconditions.' );
		}
		$lifecycle = isset( $args['lifecycle_state'] ) ? sanitize_key( (string) $args['lifecycle_state'] ) : 'running';
		$checkpoint = isset( $args['checkpoint'] ) ? sanitize_key( (string) $args['checkpoint'] ) : '';
		$outcome = isset( $args['terminal_outcome'] ) ? sanitize_key( (string) $args['terminal_outcome'] ) : '';
		$metadata = self::safe_metadata( isset( $args['metadata'] ) && is_array( $args['metadata'] ) ? $args['metadata'] : array() );
		if ( is_wp_error( $metadata ) ) return $metadata;
		$metadata_json = wp_json_encode( $metadata, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		if ( ! is_string( $metadata_json ) ) return new WP_Error( 'mad4b_operation_metadata_encode_failed', 'Operation metadata could not be encoded.' );
		$t = MAD4B_SCP_Schema::tables();
		$transaction = MAD4B_SCP_Database_Transaction_Guard::begin( 'operation_journal_append', array( 'operation_heads', 'operation_events' ), false );
		if ( is_wp_error( $transaction ) ) return $transaction;
		try {
			$head = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t['operation_heads']} WHERE BINARY operation_id=BINARY %s FOR UPDATE", $context['operation_id'] ), ARRAY_A );
			if ( ! is_array( $head ) ) throw new RuntimeException( 'operation_head_missing' );
			if ( ! hash_equals( (string) $head['operation_binding_sha256'], (string) $context['operation_binding_sha256'] ) || ! hash_equals( (string) $head['operation_key'], (string) $context['operation_key'] ) ) throw new RuntimeException( 'operation_identity_conflict' );
			if ( $has_expected_seq && ( (int) $head['latest_sequence'] !== $args['expected_sequence']
				|| ! hash_equals( (string) $head['latest_event_sha256'], $args['expected_event_sha256'] ) ) ) {
				throw new RuntimeException( 'operation_journal_cas_stale' );
			}
			if ( (int) $head['latest_sequence'] >= self::MAX_EVENTS_PER_OPERATION ) throw new RuntimeException( 'operation_event_limit_exceeded' );
			$sequence = (int) $head['latest_sequence'] + 1;
			$previous = (string) $head['latest_event_sha256'];
			$basis = array(
				'operation_id' => (string) $context['operation_id'],
				'operation_key' => (string) $context['operation_key'],
				'operation_binding_sha256' => (string) $context['operation_binding_sha256'],
				'sequence' => $sequence,
				'event_type' => $event_type,
				'checkpoint' => $checkpoint,
				'lifecycle_state' => $lifecycle,
				'terminal_outcome' => $outcome,
				'safe_metadata' => $metadata,
				'previous_event_sha256' => $previous,
			);
			$event_sha = MAD4B_SCP_Canonicalization::digest( self::EVENT_CONTRACT, $basis );
			if ( is_wp_error( $event_sha ) ) throw new RuntimeException( 'operation_event_hash_failed' );
			$now = gmdate( 'Y-m-d H:i:s' );
			$ok = $wpdb->query( $wpdb->prepare(
				"INSERT INTO {$t['operation_events']} (operation_id,operation_key,operation_binding_sha256,sequence,event_type,checkpoint,lifecycle_state,terminal_outcome,safe_metadata_json,previous_event_sha256,event_sha256,created_at) VALUES (%s,%s,%s,%d,%s,%s,%s,%s,%s,%s,%s,%s)",
				$context['operation_id'], $context['operation_key'], $context['operation_binding_sha256'], $sequence, $event_type, $checkpoint, $lifecycle, $outcome, $metadata_json, $previous, $event_sha, $now
			) );
			if ( 1 !== (int) $ok ) throw new RuntimeException( 'operation_event_insert_failed' );
			$updated = $wpdb->query( $wpdb->prepare(
				"UPDATE {$t['operation_heads']} SET latest_sequence=%d,latest_event_sha256=%s,lifecycle_state=%s,terminal_outcome=%s,heartbeat_at=%s,stale_after=%s,updated_at=%s WHERE BINARY operation_id=BINARY %s AND latest_sequence=%d AND BINARY latest_event_sha256=BINARY %s",
				$sequence, $event_sha, $lifecycle, $outcome, $now, gmdate( 'Y-m-d H:i:s', time() + self::DEFAULT_STALE_SECONDS ), $now, $context['operation_id'], $sequence - 1, $previous
			) );
			if ( 1 !== (int) $updated ) throw new RuntimeException( 'operation_head_cas_failed' );
			$committed = MAD4B_SCP_Database_Transaction_Guard::commit( $transaction );
			if ( is_wp_error( $committed ) ) {
				return new WP_Error( 'mad4b_operation_journal_commit_uncertain', 'Operation journal commit could not be verified; reconciliation is required.', array( 'cause' => $committed->get_error_code(), 'reconciliation_required' => true ) );
			}
			return array( 'contract' => self::CONTRACT, 'operation_id' => $context['operation_id'], 'sequence' => $sequence, 'event_sha256' => $event_sha, 'journal_head_sha256' => $event_sha, 'lifecycle_state' => $lifecycle, 'terminal_outcome' => $outcome );
		} catch ( Throwable $e ) {
			$db_error = isset( $wpdb->last_error ) ? (string) $wpdb->last_error : '';
			$rolled_back = MAD4B_SCP_Database_Transaction_Guard::rollback( $transaction );
			$rollback_verified = true === $rolled_back;
			$semantics = MAD4B_SCP_Database_Failure_Semantics::classify( 'operation_journal_append', $db_error . ' ' . $e->getMessage(), $rollback_verified );
			$code = ! empty( $semantics['reconciliation_required'] ) ? 'mad4b_operation_journal_persistence_uncertain'
				: ( 'operation_journal_cas_stale' === $e->getMessage() ? 'mad4b_operation_journal_cas_stale' : 'mad4b_operation_journal_append_failed' );
			return new WP_Error( $code, 'Unable to append operation journal event.', array_merge( $semantics, array(
				'reason' => substr( $e->getMessage(), 0, 100 ),
				'db_error' => substr( $db_error, 0, 191 ),
				'rollback_error' => is_wp_error( $rolled_back ) ? $rolled_back->get_error_code() : '',
			) ) );
		}
	}

	public static function head( $operation_id ) {
		global $wpdb;
		$identity = MAD4B_SCP_Identifiers::operation_lookup( $operation_id );
		if ( is_wp_error( $identity ) ) return $identity;
		$operation_id = (string) $identity['value'];
		$t = MAD4B_SCP_Schema::tables();
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t['operation_heads']} WHERE BINARY operation_id=BINARY %s LIMIT 1", $operation_id ), ARRAY_A );
		return is_array( $row ) ? $row : new WP_Error( 'mad4b_operation_not_found', 'Operation journal head was not found.' );
	}


	public static function heartbeat( array $context, $lock_expires_at = '' ) {
		global $wpdb;
		$valid = self::validate_context( $context );
		if ( is_wp_error( $valid ) ) return $valid;
		$t = MAD4B_SCP_Schema::tables();
		$now = gmdate( 'Y-m-d H:i:s' );
		$stale = gmdate( 'Y-m-d H:i:s', time() + self::DEFAULT_STALE_SECONDS );
		$lock = '';
		if ( '' !== (string) $lock_expires_at ) {
			$ts = strtotime( (string) $lock_expires_at );
			if ( false === $ts ) return new WP_Error( 'mad4b_operation_lock_expiry_invalid', 'Lock expiry timestamp is invalid.' );
			$lock = gmdate( 'Y-m-d H:i:s', $ts );
		}
		if ( '' === $lock ) {
			$ok = $wpdb->query( $wpdb->prepare( "UPDATE {$t['operation_heads']} SET heartbeat_at=%s,stale_after=%s,updated_at=%s WHERE BINARY operation_id=BINARY %s AND BINARY operation_binding_sha256=BINARY %s", $now, $stale, $now, $context['operation_id'], $context['operation_binding_sha256'] ) );
		} else {
			$ok = $wpdb->query( $wpdb->prepare( "UPDATE {$t['operation_heads']} SET heartbeat_at=%s,lock_expires_at=%s,stale_after=%s,updated_at=%s WHERE BINARY operation_id=BINARY %s AND BINARY operation_binding_sha256=BINARY %s", $now, $lock, $stale, $now, $context['operation_id'], $context['operation_binding_sha256'] ) );
		}
		return false === $ok ? new WP_Error( 'mad4b_operation_heartbeat_failed', 'Operation heartbeat update failed.' ) : true;
	}

	public static function trace( $operation_id, $limit = 200 ) {
		global $wpdb;
		$identity = MAD4B_SCP_Identifiers::operation_lookup( $operation_id );
		if ( is_wp_error( $identity ) ) return $identity;
		$operation_id = (string) $identity['value'];
		$limit = max( 1, min( 1000, absint( $limit ) ) );
		$t = MAD4B_SCP_Schema::tables();
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$t['operation_events']} WHERE BINARY operation_id=BINARY %s ORDER BY sequence ASC LIMIT %d", $operation_id, $limit ), ARRAY_A );
		if ( ! is_array( $rows ) ) return new WP_Error( 'mad4b_operation_trace_read_failed', 'Unable to read operation trace.' );
		$valid = true;
		$previous = str_repeat( '0', 64 );
		$events = array();
		foreach ( $rows as $row ) {
			$metadata = json_decode( (string) $row['safe_metadata_json'], true );
			if ( ! is_array( $metadata ) ) { $valid = false; $metadata = array(); }
			$basis = array(
				'operation_id'=>(string)$row['operation_id'],
				'operation_key'=>(string)$row['operation_key'],
				'operation_binding_sha256'=>(string)$row['operation_binding_sha256'],
				'sequence'=>(int)$row['sequence'],
				'event_type'=>(string)$row['event_type'],
				'checkpoint'=>(string)$row['checkpoint'],
				'lifecycle_state'=>(string)$row['lifecycle_state'],
				'terminal_outcome'=>(string)$row['terminal_outcome'],
				'safe_metadata'=>$metadata,
				'previous_event_sha256'=>(string)$row['previous_event_sha256'],
			);
			$sha = MAD4B_SCP_Canonicalization::digest( self::EVENT_CONTRACT, $basis );
			if ( is_wp_error( $sha ) || ! hash_equals( $previous, (string)$row['previous_event_sha256'] ) || ! hash_equals( (string)$row['event_sha256'], (string)$sha ) ) $valid = false;
			$previous = (string)$row['event_sha256'];
			$events[] = array(
				'sequence'=>(int)$row['sequence'],'event_type'=>(string)$row['event_type'],'checkpoint'=>(string)$row['checkpoint'],
				'lifecycle_state'=>(string)$row['lifecycle_state'],'terminal_outcome'=>(string)$row['terminal_outcome'],
				'safe_metadata'=>$metadata,'previous_event_sha256'=>(string)$row['previous_event_sha256'],'event_sha256'=>(string)$row['event_sha256'],'created_at'=>(string)$row['created_at']
			);
		}
		$head = self::head( $operation_id );
		$complete = ! is_wp_error( $head ) && (int)$head['latest_sequence'] === count( $events );
		if ( $complete && ! empty( $events ) && ! hash_equals( (string)$head['latest_event_sha256'], (string)$previous ) ) $valid = false;
		return array( 'contract'=>'mad4b.dynamic-operation-trace.v1','operation_id'=>$operation_id,'operation_identity_class'=>(string)$identity['identity_class'],'historical_identity_preserved'=>!empty($identity['historical_identity_preserved']),'rewrite_allowed'=>!empty($identity['rewrite_allowed']),'chain_valid'=>$valid,'complete'=>$complete,'count'=>count($events),'events'=>$events,'read_only'=>true,'mutation_performed'=>false );
	}

	public static function status( $operation_id ) {
		$head = self::head( $operation_id );
		if ( is_wp_error( $head ) ) return $head;
		$heartbeat = ! empty( $head['heartbeat_at'] ) ? strtotime( $head['heartbeat_at'] . ' UTC' ) : false;
		$deadline = ! empty( $head['hard_deadline_at'] ) ? strtotime( $head['hard_deadline_at'] . ' UTC' ) : false;
		$now = time();
		$terminal = in_array( (string)$head['lifecycle_state'], array('completed','terminal_failed'), true );
		$stale_after = ! empty( $head['stale_after'] ) ? strtotime( $head['stale_after'] . ' UTC' ) : false;
		$lock_expires = ! empty( $head['lock_expires_at'] ) ? strtotime( $head['lock_expires_at'] . ' UTC' ) : false;
		return array(
			'contract'=>'mad4b.dynamic-operation-status.v1',
			'operation_id'=>(string)$head['operation_id'],
			'operation_key'=>(string)$head['operation_key'],
			'operation_binding_sha256'=>(string)$head['operation_binding_sha256'],
			'latest_sequence'=>(int)$head['latest_sequence'],
			'journal_head_sha256'=>(string)$head['latest_event_sha256'],
			'lifecycle_state'=>(string)$head['lifecycle_state'],
			'terminal_outcome'=>(string)$head['terminal_outcome'],
			'heartbeat_at'=>(string)$head['heartbeat_at'],
			'hard_deadline_at'=>(string)$head['hard_deadline_at'],
			'lock_expires_at'=>isset($head['lock_expires_at'])?(string)$head['lock_expires_at']:'',
			'stale_after'=>isset($head['stale_after'])?(string)$head['stale_after']:'',
			'stale_heartbeat'=>!$terminal && false!==$stale_after && $stale_after<=$now,
			'lock_expired'=>!$terminal && false!==$lock_expires && $lock_expires<=$now,
			'hard_deadline_exceeded'=>!$terminal && false!==$deadline && $deadline<=$now,
			'orphan_candidate'=>!$terminal && ((false!==$stale_after&&$stale_after<=$now)||(false!==$lock_expires&&$lock_expires<=$now)||(false!==$deadline&&$deadline<=$now)),
			'read_only'=>true,'mutation_performed'=>false
		);
	}

	private static function validate_context( array $context ) {
		foreach ( array( 'operation_id', 'operation_key', 'operation_binding_sha256', 'hard_deadline_at' ) as $key ) if ( empty( $context[ $key ] ) ) return new WP_Error( 'mad4b_operation_context_incomplete', 'Operation context is incomplete.', array( 'missing' => $key ) );
		$canonical_operation_id = MAD4B_SCP_Identifiers::operation_id_for_write( $context['operation_id'] );
		if ( '' === $canonical_operation_id || ! hash_equals( $canonical_operation_id, (string) $context['operation_id'] ) ) return new WP_Error( 'mad4b_operation_id_invalid', 'New operation journal writes require a canonical lowercase UUIDv4 identity.' );
		if ( 1 !== preg_match( '/^[a-f0-9]{64}$/', strtolower( (string) $context['operation_binding_sha256'] ) ) ) return new WP_Error( 'mad4b_operation_binding_invalid', 'Operation binding digest is invalid.' );
		return true;
	}

	private static function mysql_time( $iso ) {
		$ts = strtotime( (string) $iso );
		return false === $ts ? gmdate( 'Y-m-d H:i:s', time() + 1800 ) : gmdate( 'Y-m-d H:i:s', $ts );
	}

	private static function safe_metadata( array $metadata ) {
		$out = array();
		$count = 0;
		foreach ( $metadata as $key => $value ) {
			if ( $count >= self::MAX_METADATA_ITEMS ) break;
			$key = substr( sanitize_key( (string) $key ), 0, 64 );
			if ( '' === $key ) continue;
			if ( preg_match( '/(?:secret|token|password|authorization|cookie|api[_-]?key|nonce|credential)/i', $key ) ) {
				$out[ $key ] = self::REDACTED;
			} elseif ( is_scalar( $value ) || is_null( $value ) ) {
				$text = is_string( $value ) ? $value : $value;
				$out[ $key ] = is_string( $text ) && strlen( $text ) > 500 ? array( 'sha256' => hash( 'sha256', $text ), 'length' => strlen( $text ), 'preview' => substr( $text, 0, 120 ) ) : $text;
			} else {
				$json = wp_json_encode( $value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
				$out[ $key ] = is_string( $json ) ? array( 'sha256' => hash( 'sha256', $json ), 'length' => strlen( $json ) ) : '[unavailable]';
			}
			$count++;
		}
		$json = wp_json_encode( $out, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		if ( ! is_string( $json ) || strlen( $json ) > self::MAX_METADATA_BYTES ) return new WP_Error( 'mad4b_operation_metadata_too_large', 'Safe operation metadata exceeds bounded storage.' );
		return $out;
	}
}
