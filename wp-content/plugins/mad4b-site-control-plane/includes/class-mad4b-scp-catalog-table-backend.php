<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
if ( ! class_exists( 'MAD4B_SCP_Database_Transaction_Guard' ) ) require_once __DIR__ . '/class-mad4b-scp-database-transaction-guard.php';

/**
 * Immutable table-backed catalog object store.
 *
 * A generation is an immutable directory over content-addressed object rows.
 * Publication advances one scope head through a storage-level fencing token/CAS.
 * This class grants no capability authority; it only persists catalog bytes.
 */
final class MAD4B_SCP_Catalog_Table_Backend {
	const CONTRACT = 'mad4b.catalog-table-backend.v1';
	const OBJECT_DOMAIN = 'mad4b.catalog-table-object.v1';
	const DIRECTORY_DOMAIN = 'mad4b.catalog-table-directory.v1';
	private $scope;
	private $pending = array();
	private $replace = false;
	private $metrics = array( 'reads'=>0, 'writes'=>0, 'bytes_read'=>0, 'generations_published'=>0 );

	public function __construct( $storage_scope_sha256 ) {
		$scope = strtolower( trim( (string) $storage_scope_sha256 ) );
		if ( 1 !== preg_match( '/^[a-f0-9]{64}$/D', $scope ) ) $scope = hash( 'sha256', 'mad4b.catalog-table.default-scope.v1' );
		$this->scope = $scope;
	}

	public static function ready() {
		if ( ! class_exists( 'MAD4B_SCP_Schema' ) || ! MAD4B_SCP_Schema::is_ready() ) return false;
		$t = MAD4B_SCP_Schema::tables();
		return isset( $t['catalog_objects'], $t['catalog_generations'], $t['catalog_heads'] );
	}

	public function put( $key, $value, $ttl, $minimum_expires = 0 ) {
		$ttl = max( 1, (int) $ttl );
		$expires = max( time() + $ttl, max( 0, (int) $minimum_expires ) );
		$this->pending[ (string) $key ] = array( 'value'=>$value, 'expires'=>$expires );
	}

	public function replace_directory( array $entries ) {
		$this->replace = true;
		$this->pending = array();
		foreach ( $entries as $key => $entry ) {
			if ( ! is_array( $entry ) || ! array_key_exists( 'value', $entry ) ) continue;
			$expires = isset( $entry['expires'] ) ? max( time() + 1, (int) $entry['expires'] ) : time() + 3600;
			$this->pending[ (string) $key ] = array( 'value'=>$entry['value'], 'expires'=>$expires );
		}
	}

	public function get( $key ) {
		global $wpdb;
		if ( ! self::ready() ) return false;
		$t = MAD4B_SCP_Schema::tables();
		$row = $wpdb->get_row( $wpdb->prepare(
			"SELECT g.object_sha256,g.object_expires_at,o.payload_blob,o.payload_sha256,o.wire_generation
			 FROM {$t['catalog_heads']} h
			 INNER JOIN {$t['catalog_generations']} g ON g.generation_id=h.generation_id AND g.storage_scope_sha256=h.storage_scope_sha256
			 INNER JOIN {$t['catalog_objects']} o ON o.object_sha256=g.object_sha256
			 WHERE BINARY h.storage_scope_sha256=BINARY %s
			   AND BINARY g.object_key_sha256=BINARY %s
			   AND h.expires_at>UTC_TIMESTAMP()
			   AND g.object_expires_at>UTC_TIMESTAMP()
			 LIMIT 1",
			$this->scope, self::key_sha( $key )
		), ARRAY_A );
		if ( ! is_array( $row ) ) return false;
		$payload = (string) $row['payload_blob'];
		if ( ! hash_equals( (string) $row['payload_sha256'], hash( 'sha256', $payload ) ) ) return false;
		$expected_object = self::object_sha( $payload, (string) $row['wire_generation'] );
		if ( ! hash_equals( (string) $row['object_sha256'], $expected_object ) ) return false;
		++$this->metrics['reads'];
		$this->metrics['bytes_read'] += strlen( $payload );
		return maybe_unserialize( $payload );
	}

	public function expires( $key ) {
		global $wpdb;
		if ( ! self::ready() ) return 0;
		$t = MAD4B_SCP_Schema::tables();
		$value = $wpdb->get_var( $wpdb->prepare(
			"SELECT UNIX_TIMESTAMP(g.object_expires_at)
			 FROM {$t['catalog_heads']} h
			 INNER JOIN {$t['catalog_generations']} g ON g.generation_id=h.generation_id AND g.storage_scope_sha256=h.storage_scope_sha256
			 WHERE BINARY h.storage_scope_sha256=BINARY %s AND BINARY g.object_key_sha256=BINARY %s LIMIT 1",
			$this->scope, self::key_sha( $key )
		) );
		return is_numeric( $value ) ? (int) $value : 0;
	}

	public function metrics() { return $this->metrics; }

	public function logical_digest_from_head() {
		global $wpdb;
		$head = $this->head();
		if ( is_wp_error( $head ) ) return $head;
		if ( empty( $head['generation_id'] ) ) return self::logical_digest( array() );
		$t = MAD4B_SCP_Schema::tables();
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT g.object_key_sha256,o.payload_sha256,UNIX_TIMESTAMP(g.object_expires_at) AS object_expires
			 FROM {$t['catalog_generations']} g
			 INNER JOIN {$t['catalog_objects']} o ON o.object_sha256=g.object_sha256
			 WHERE BINARY g.storage_scope_sha256=BINARY %s AND BINARY g.generation_id=BINARY %s",
			$this->scope, (string)$head['generation_id']
		), ARRAY_A );
		$logical = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$logical[ (string)$row['object_key_sha256'] ] = array(
				'payload_sha256'=>(string)$row['payload_sha256'],
				'expires'=>(int)$row['object_expires'],
			);
		}
		ksort( $logical, SORT_STRING );
		return self::logical_digest_rows( $logical );
	}

	public function head() {
		global $wpdb;
		if ( ! self::ready() ) return new WP_Error( 'mad4b_catalog_table_backend_unready', 'Catalog table backend schema is unavailable.' );
		$t = MAD4B_SCP_Schema::tables();
		$row = $wpdb->get_row( $wpdb->prepare(
			"SELECT storage_scope_sha256,generation_id,directory_sha256,fencing_token,previous_generation_id,published_at,expires_at,updated_at
			 FROM {$t['catalog_heads']} WHERE BINARY storage_scope_sha256=BINARY %s LIMIT 1",
			$this->scope
		), ARRAY_A );
		return is_array( $row ) ? $row : array(
			'storage_scope_sha256'=>$this->scope,'generation_id'=>'','directory_sha256'=>'','fencing_token'=>0,
			'previous_generation_id'=>'','published_at'=>'','expires_at'=>'','updated_at'=>''
		);
	}

	public function flush() {
		global $wpdb;
		if ( ! self::ready() ) return new WP_Error( 'mad4b_catalog_table_backend_unready', 'Catalog table backend schema is unavailable.' );
		$transaction = MAD4B_SCP_Database_Transaction_Guard::begin( 'catalog_table_publish', array( 'catalog_objects','catalog_generations','catalog_heads' ), false );
		if ( is_wp_error( $transaction ) ) return $transaction;
		$t = MAD4B_SCP_Schema::tables();
		try {
			$physical_bytes = $wpdb->get_var( "SELECT COALESCE(SUM(payload_bytes),0) FROM {$t['catalog_objects']}" );
			if ( null === $physical_bytes || ! is_numeric( $physical_bytes ) ) throw new RuntimeException( 'catalog_table_capacity_measurement_failed' );
			$physical_bytes = (int) $physical_bytes;
			$head = $wpdb->get_row( $wpdb->prepare(
				"SELECT * FROM {$t['catalog_heads']} WHERE BINARY storage_scope_sha256=BINARY %s FOR UPDATE",
				$this->scope
			), ARRAY_A );
			$expected_fence = is_array( $head ) ? (int) $head['fencing_token'] : 0;
			$previous_generation = is_array( $head ) ? (string) $head['generation_id'] : '';
			$directory = array();
			if ( ! $this->replace && '' !== $previous_generation ) {
				$rows = $wpdb->get_results( $wpdb->prepare(
					"SELECT object_key_sha256,object_sha256,UNIX_TIMESTAMP(object_expires_at) AS object_expires
					 FROM {$t['catalog_generations']}
					 WHERE BINARY storage_scope_sha256=BINARY %s AND BINARY generation_id=BINARY %s",
					$this->scope, $previous_generation
				), ARRAY_A );
				foreach ( is_array( $rows ) ? $rows : array() as $row ) {
					if ( (int) $row['object_expires'] <= time() ) continue;
					$directory[ (string) $row['object_key_sha256'] ] = array(
						'object_sha256'=>(string)$row['object_sha256'],
						'expires'=>(int)$row['object_expires'],
					);
				}
			}
			$wire_generation = class_exists( 'MAD4B_SCP_Ability_Catalog_Transport' ) ? MAD4B_SCP_Ability_Catalog_Transport::wire_generation() : 'catalog-table-v1';
			foreach ( $this->pending as $key => $entry ) {
				$payload = maybe_serialize( $entry['value'] );
				$payload_sha = hash( 'sha256', $payload );
				$object_sha = self::object_sha( $payload, $wire_generation );
				$bytes = strlen( $payload );
				$expires = (int) $entry['expires'];
				$retain = max( $expires, time() + 3600 );
				$existing = $wpdb->get_row( $wpdb->prepare(
					"SELECT payload_sha256,wire_generation,payload_bytes FROM {$t['catalog_objects']} WHERE BINARY object_sha256=BINARY %s LIMIT 1",
					$object_sha
				), ARRAY_A );
				if ( ! is_array( $existing ) ) {
					if ( $physical_bytes + $bytes > self::capacity() ) throw new RuntimeException( 'catalog_table_capacity_exhausted' );
					$insert = $wpdb->query( $wpdb->prepare(
						"INSERT INTO {$t['catalog_objects']}
						 (object_sha256,object_kind,wire_generation,payload_blob,payload_sha256,payload_bytes,expires_at,retain_until,created_at)
						 VALUES (%s,'catalog_object',%s,%s,%s,%d,FROM_UNIXTIME(%d),FROM_UNIXTIME(%d),UTC_TIMESTAMP())",
						$object_sha, $wire_generation, $payload, $payload_sha, $bytes, $expires, $retain
					) );
					if ( 1 !== (int) $insert ) throw new RuntimeException( 'catalog_table_object_write_failed' );
					$physical_bytes += $bytes;
					$existing = array( 'payload_sha256'=>$payload_sha, 'wire_generation'=>$wire_generation, 'payload_bytes'=>$bytes );
				}
				if ( ! hash_equals( $payload_sha, (string)$existing['payload_sha256'] ) || ! hash_equals( $wire_generation, (string)$existing['wire_generation'] ) || $bytes !== (int)$existing['payload_bytes'] ) {
					throw new RuntimeException( 'catalog_table_object_collision' );
				}
				$directory[ self::key_sha( $key ) ] = array( 'object_sha256'=>$object_sha, 'expires'=>$expires );
				++$this->metrics['writes'];
			}
			ksort( $directory, SORT_STRING );
			$directory_sha = self::directory_sha( $directory );
			if ( is_array( $head ) && '' !== (string)$head['directory_sha256'] && hash_equals( (string)$head['directory_sha256'], $directory_sha ) ) {
				$committed = MAD4B_SCP_Database_Transaction_Guard::commit( $transaction );
				if ( is_wp_error( $committed ) ) return $committed;
				$this->pending = array(); $this->replace = false;
				return $head;
			}
			$next_fence = $expected_fence + 1;
			$generation_id = hash( 'sha256', self::DIRECTORY_DOMAIN . '|' . $this->scope . '|' . $directory_sha . '|' . $next_fence );
			foreach ( $directory as $object_key_sha => $entry ) {
				$ok = $wpdb->query( $wpdb->prepare(
					"INSERT INTO {$t['catalog_generations']}
					 (generation_id,storage_scope_sha256,object_key_sha256,object_sha256,object_expires_at,created_at)
					 VALUES (%s,%s,%s,%s,FROM_UNIXTIME(%d),UTC_TIMESTAMP())",
					$generation_id, $this->scope, $object_key_sha, $entry['object_sha256'], (int)$entry['expires']
				) );
				if ( 1 !== (int) $ok ) throw new RuntimeException( 'catalog_table_generation_write_failed' );
			}
			$head_expiry = empty( $directory ) ? time() + 60 : max( array_map( static function( $entry ){ return (int)$entry['expires']; }, $directory ) );
			if ( is_array( $head ) ) {
				$ok = $wpdb->query( $wpdb->prepare(
					"UPDATE {$t['catalog_heads']}
					 SET generation_id=%s,directory_sha256=%s,fencing_token=%d,previous_generation_id=%s,published_at=UTC_TIMESTAMP(),expires_at=FROM_UNIXTIME(%d),updated_at=UTC_TIMESTAMP()
					 WHERE BINARY storage_scope_sha256=BINARY %s AND fencing_token=%d AND BINARY generation_id=BINARY %s",
					$generation_id, $directory_sha, $next_fence, $previous_generation, $head_expiry, $this->scope, $expected_fence, $previous_generation
				) );
				if ( 1 !== (int) $ok ) throw new RuntimeException( 'catalog_table_head_cas_conflict' );
			} else {
				$ok = $wpdb->query( $wpdb->prepare(
					"INSERT INTO {$t['catalog_heads']}
					 (storage_scope_sha256,generation_id,directory_sha256,fencing_token,previous_generation_id,published_at,expires_at,updated_at)
					 VALUES (%s,%s,%s,%d,'',UTC_TIMESTAMP(),FROM_UNIXTIME(%d),UTC_TIMESTAMP())",
					$this->scope, $generation_id, $directory_sha, $next_fence, $head_expiry
				) );
				if ( 1 !== (int) $ok ) throw new RuntimeException( 'catalog_table_head_create_conflict' );
			}
			$committed = MAD4B_SCP_Database_Transaction_Guard::commit( $transaction );
			if ( is_wp_error( $committed ) ) return $committed;
			$this->pending = array(); $this->replace = false; ++$this->metrics['generations_published'];
			return array(
				'contract'=>self::CONTRACT,'storage_scope_sha256'=>$this->scope,'generation_id'=>$generation_id,
				'directory_sha256'=>$directory_sha,'fencing_token'=>$next_fence,'previous_generation_id'=>$previous_generation,
				'authorizing'=>false
			);
		} catch ( Throwable $error ) {
			MAD4B_SCP_Database_Transaction_Guard::rollback( $transaction );
			return new WP_Error( 'mad4b_catalog_table_publish_failed', 'Catalog table generation publication failed.', array(
				'reason_code'=>substr( sanitize_key( $error->getMessage() ), 0, 96 ),
				'storage_scope_sha256'=>$this->scope,
				'authorizing'=>false,
			) );
		}
	}

	public static function logical_digest( array $entries ) {
		$rows = array();
		foreach ( $entries as $key => $entry ) {
			if ( ! is_array( $entry ) || ! array_key_exists( 'value', $entry ) ) continue;
			$payload = maybe_serialize( $entry['value'] );
			$rows[ self::key_sha( $key ) ] = array(
				'payload_sha256'=>hash( 'sha256', $payload ),
				'expires'=>(int)( isset( $entry['expires'] ) ? $entry['expires'] : 0 ),
			);
		}
		ksort( $rows, SORT_STRING );
		return self::logical_digest_rows( $rows );
	}
	private static function logical_digest_rows( array $rows ) {
		return hash( 'sha256', self::DIRECTORY_DOMAIN . '|' . wp_json_encode( $rows, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
	}

	private static function capacity() {
		return max( 1048576, (int) apply_filters( 'mad4b_scp_catalog_storage_capacity_bytes', 134217728 ) );
	}

	public static function status( $scope = '' ) {
		global $wpdb;
		$scope = 1 === preg_match( '/^[a-f0-9]{64}$/D', (string)$scope ) ? (string)$scope : hash( 'sha256', 'mad4b.catalog-table.default-scope.v1' );
		if ( ! self::ready() ) return array( 'contract'=>self::CONTRACT,'ready'=>false,'storage_scope_sha256'=>$scope,'authorizing'=>false );
		$t = MAD4B_SCP_Schema::tables();
		$bytes = $wpdb->get_var( "SELECT COALESCE(SUM(payload_bytes),0) FROM {$t['catalog_objects']}" );
		$objects = $wpdb->get_var( "SELECT COUNT(*) FROM {$t['catalog_objects']}" );
		$referenced_bytes = $wpdb->get_var(
			"SELECT COALESCE(SUM(o.payload_bytes),0) FROM {$t['catalog_objects']} o
			 WHERE EXISTS (SELECT 1 FROM {$t['catalog_generations']} g WHERE BINARY g.object_sha256=BINARY o.object_sha256)"
		);
		$head_reachable_bytes = $wpdb->get_var(
			"SELECT COALESCE(SUM(o.payload_bytes),0) FROM {$t['catalog_objects']} o
			 WHERE EXISTS (
				SELECT 1 FROM {$t['catalog_generations']} g
				INNER JOIN {$t['catalog_heads']} h
					ON h.generation_id=g.generation_id AND h.storage_scope_sha256=g.storage_scope_sha256
				WHERE BINARY g.object_sha256=BINARY o.object_sha256
			)"
		);
		$generations = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(DISTINCT generation_id) FROM {$t['catalog_generations']} WHERE BINARY storage_scope_sha256=BINARY %s", $scope ) );
		$head = ( new self( $scope ) )->head();
		$physical = is_numeric( $bytes ) ? (int) $bytes : 0;
		$referenced = is_numeric( $referenced_bytes ) ? (int) $referenced_bytes : 0;
		$head_reachable = is_numeric( $head_reachable_bytes ) ? (int) $head_reachable_bytes : 0;
		return array(
			'contract'=>self::CONTRACT,'ready'=>true,'storage_scope_sha256'=>$scope,
			'object_count'=>is_numeric($objects)?(int)$objects:0,'physical_bytes'=>$physical,
			'referenced_bytes'=>$referenced,'head_reachable_bytes'=>$head_reachable,
			'retained_generation_bytes'=>max(0,$referenced-$head_reachable),'orphan_bytes'=>max(0,$physical-$referenced),
			'capacity_bytes'=>self::capacity(),'capacity_remaining_bytes'=>max(0,self::capacity()-$physical),
			'generation_count'=>is_numeric($generations)?(int)$generations:0,'head'=>is_wp_error($head)?array():$head,'authorizing'=>false
		);
	}

	public function retire_scope( $expected_generation_id, $expected_fencing_token, $reader_drain_verified = false ) {
		global $wpdb;
		if ( true !== $reader_drain_verified ) return new WP_Error( 'mad4b_catalog_retirement_reader_drain_required', 'Catalog table retirement requires explicit reader-drain verification.' );
		if ( ! self::ready() ) return new WP_Error( 'mad4b_catalog_table_backend_unready', 'Catalog table backend schema is unavailable.' );
		$expected_generation_id = strtolower( trim( (string) $expected_generation_id ) );
		$expected_fencing_token = (int) $expected_fencing_token;
		if ( 1 !== preg_match( '/^[a-f0-9]{64}$/D', $expected_generation_id ) || $expected_fencing_token < 1 ) {
			return new WP_Error( 'mad4b_catalog_retirement_expectation_invalid', 'Catalog table retirement requires exact generation/fencing expectations.' );
		}
		$t = MAD4B_SCP_Schema::tables();
		$transaction = MAD4B_SCP_Database_Transaction_Guard::begin( 'catalog_table_retire', array( 'catalog_objects','catalog_generations','catalog_heads' ), false );
		if ( is_wp_error( $transaction ) ) return $transaction;
		try {
			$head = $wpdb->get_row( $wpdb->prepare(
				"SELECT generation_id,fencing_token FROM {$t['catalog_heads']} WHERE BINARY storage_scope_sha256=BINARY %s FOR UPDATE",
				$this->scope
			), ARRAY_A );
			$scope_rows = (int) $wpdb->get_var( $wpdb->prepare(
				"SELECT COUNT(*) FROM {$t['catalog_generations']} WHERE BINARY storage_scope_sha256=BINARY %s",
				$this->scope
			) );
			$scope_object_shas = $wpdb->get_col( $wpdb->prepare(
				"SELECT DISTINCT object_sha256 FROM {$t['catalog_generations']} WHERE BINARY storage_scope_sha256=BINARY %s ORDER BY object_sha256",
				$this->scope
			) );
			$scope_object_shas = is_array( $scope_object_shas ) ? array_values( array_filter( array_map( 'strval', $scope_object_shas ) ) ) : array();
			if ( is_array( $head ) ) {
				if ( ! hash_equals( $expected_generation_id, (string)$head['generation_id'] ) || $expected_fencing_token !== (int)$head['fencing_token'] ) {
					throw new RuntimeException( 'catalog_table_retirement_fence_mismatch' );
				}
				$deleted_head = $wpdb->query( $wpdb->prepare(
					"DELETE FROM {$t['catalog_heads']} WHERE BINARY storage_scope_sha256=BINARY %s AND BINARY generation_id=BINARY %s AND fencing_token=%d",
					$this->scope, $expected_generation_id, $expected_fencing_token
				) );
				if ( 1 !== (int)$deleted_head ) throw new RuntimeException( 'catalog_table_retirement_head_cas_conflict' );
			} elseif ( 0 === $scope_rows ) {
				$committed = MAD4B_SCP_Database_Transaction_Guard::commit( $transaction );
				if ( is_wp_error( $committed ) ) return $committed;
				$status = self::status( $this->scope );
				$status['retirement_idempotent'] = true;
				$status['purged_unreferenced_objects'] = 0;
				return $status;
			} else {
				throw new RuntimeException( 'catalog_table_retirement_head_missing' );
			}
			$deleted_generations = $wpdb->query( $wpdb->prepare(
				"DELETE FROM {$t['catalog_generations']} WHERE BINARY storage_scope_sha256=BINARY %s",
				$this->scope
			) );
			if ( false === $deleted_generations ) throw new RuntimeException( 'catalog_table_retirement_generation_delete_failed' );
			$committed = MAD4B_SCP_Database_Transaction_Guard::commit( $transaction );
			if ( is_wp_error( $committed ) ) return $committed;
		} catch ( Throwable $error ) {
			MAD4B_SCP_Database_Transaction_Guard::rollback( $transaction );
			return new WP_Error( 'mad4b_catalog_table_retirement_failed', 'Catalog table scope retirement failed closed.', array(
				'reason_code'=>substr( sanitize_key( $error->getMessage() ), 0, 96 ),
				'storage_scope_sha256'=>$this->scope,'authorizing'=>false
			) );
		}
		$purged = self::purge_unreferenced_objects( $scope_object_shas );
		if ( is_wp_error( $purged ) ) return $purged;
		$status = self::status( $this->scope );
		$head_after = isset( $status['head'] ) && is_array( $status['head'] ) ? $status['head'] : array();
		if ( 0 !== (int)$status['generation_count'] || ! empty( $head_after['generation_id'] ) ) {
			return new WP_Error( 'mad4b_catalog_table_retirement_readback_failed', 'Catalog table retirement left scope rows after readback.' );
		}
		$status['retired_generation_id'] = $expected_generation_id;
		$status['retired_fencing_token'] = $expected_fencing_token;
		$status['purged_unreferenced_objects'] = (int)$purged;
		$status['retirement_idempotent'] = false;
		return $status;
	}

	private static function purge_unreferenced_objects( array $candidate_shas ) {
		global $wpdb;
		$t = MAD4B_SCP_Schema::tables();
		$candidate_shas = array_values( array_unique( array_filter( array_map( static function( $sha ) {
			$sha = strtolower( trim( (string)$sha ) );
			return 1 === preg_match( '/^[a-f0-9]{64}$/D', $sha ) ? $sha : '';
		}, $candidate_shas ) ) ) );
		if ( empty( $candidate_shas ) ) return 0;
		$deleted = 0;
		foreach ( array_chunk( $candidate_shas, 200 ) as $batch ) {
			$placeholders = implode( ',', array_fill( 0, count( $batch ), '%s' ) );
			$sql = "DELETE FROM {$t['catalog_objects']}
				WHERE object_sha256 IN ({$placeholders})
				AND NOT EXISTS (
					SELECT 1 FROM {$t['catalog_generations']} g
					WHERE BINARY g.object_sha256=BINARY {$t['catalog_objects']}.object_sha256
				)";
			$count = $wpdb->query( $wpdb->prepare( $sql, $batch ) );
			if ( false === $count ) return new WP_Error( 'mad4b_catalog_table_retirement_gc_failed', 'Catalog table retirement could not purge its own unreferenced objects.' );
			$deleted += (int)$count;
		}
		$remaining = 0;
		foreach ( array_chunk( $candidate_shas, 200 ) as $batch ) {
			$placeholders = implode( ',', array_fill( 0, count( $batch ), '%s' ) );
			$count = $wpdb->get_var( $wpdb->prepare(
				"SELECT COUNT(*) FROM {$t['catalog_objects']} o
				 WHERE o.object_sha256 IN ({$placeholders})
				 AND NOT EXISTS (SELECT 1 FROM {$t['catalog_generations']} g WHERE BINARY g.object_sha256=BINARY o.object_sha256)",
				$batch
			) );
			if ( is_numeric( $count ) ) $remaining += (int)$count;
		}
		if ( $remaining > 0 ) return new WP_Error( 'mad4b_catalog_table_retirement_gc_incomplete', 'Catalog table retirement left its own unreferenced objects after bounded purge.' );
		return $deleted;
	}

	public static function collect_expired() {
		global $wpdb;
		if ( ! self::ready() ) return;
		$t = MAD4B_SCP_Schema::tables();
		// Never delete the generation referenced by any current head.
		$wpdb->query(
			"DELETE FROM {$t['catalog_generations']}
			 WHERE id IN (
				SELECT id FROM (
					SELECT g.id FROM {$t['catalog_generations']} g
					LEFT JOIN {$t['catalog_heads']} h ON h.generation_id=g.generation_id AND h.storage_scope_sha256=g.storage_scope_sha256
					WHERE h.storage_scope_sha256 IS NULL AND g.object_expires_at<UTC_TIMESTAMP()
					ORDER BY g.id LIMIT 500
				) expired_generation_rows
			)"
		);
		$wpdb->query(
			"DELETE FROM {$t['catalog_objects']}
			 WHERE object_sha256 IN (
				SELECT object_sha256 FROM (
					SELECT o.object_sha256 FROM {$t['catalog_objects']} o
					LEFT JOIN {$t['catalog_generations']} g ON g.object_sha256=o.object_sha256
					WHERE g.object_sha256 IS NULL AND o.retain_until<UTC_TIMESTAMP()
					ORDER BY o.object_sha256 LIMIT 500
				) expired_object_rows
			)"
		);
	}

	private static function key_sha( $key ) { return hash( 'sha256', 'mad4b.catalog-key.v1|' . (string)$key ); }
	private static function object_sha( $payload, $wire_generation ) {
		return hash( 'sha256', self::OBJECT_DOMAIN . '|' . (string)$wire_generation . '|' . hash( 'sha256', (string)$payload ) );
	}
	private static function directory_sha( array $directory ) {
		return hash( 'sha256', self::DIRECTORY_DOMAIN . '|' . wp_json_encode( $directory, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
	}
}
