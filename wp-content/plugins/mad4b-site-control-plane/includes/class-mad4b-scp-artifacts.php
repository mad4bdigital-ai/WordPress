<?php

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Immutable Content Artifact registry and lineage graph.
 *
 * Artifacts are append-only versions. Dependency edges are typed and immutable;
 * invalidation marks dependent edges/artifacts stale without deleting evidence.
 */
final class MAD4B_SCP_Artifacts {
	const CONTRACT = 'mad4b.artifact-registry.v1';
	const EDGE_CONTRACT = 'mad4b.artifact-edge.v1';
	const MAX_INVALIDATION_NODES = 1000;

	private static $booted = false;

	public static function boot() {
		if ( self::$booted ) return;
		self::$booted = true;
		add_action( 'wp_abilities_api_init', array( __CLASS__, 'register_abilities' ), 39 );
	}

	public static function artifact_types() {
		return array(
			'context_pack', 'keyword_research', 'serp_research', 'competitor_set',
			'competitor_analysis', 'information_gain', 'blueprint', 'draft',
			'fact_qa', 'editorial_qa', 'seo_qa', 'media_plan', 'publication_candidate',
			'publication_verification',
		);
	}

	public static function relations() {
		return array( 'derived_from', 'uses', 'qa_of', 'publication_of', 'supersedes' );
	}

	public static function register_abilities() {
		if ( ! function_exists( 'wp_register_ability' ) ) return;
		self::register( 'mad4b/artifact-list', 'List Content Artifacts', 'list_artifacts', true );
		self::register( 'mad4b/artifact-get', 'Get Content Artifact', 'get_artifact', true );
		self::register( 'mad4b/artifact-edges', 'Get Artifact Lineage', 'get_edges', true );
		self::register( 'mad4b/artifact-append', 'Append Content Artifact', 'append_artifact', false );
		self::register( 'mad4b/artifact-link', 'Link Content Artifacts', 'link_artifacts', false );
		self::register( 'mad4b/artifact-invalidate', 'Invalidate Artifact Lineage', 'invalidate_artifact', false );
	}

	private static function register( $name, $label, $method, $readonly ) {
		if ( function_exists( 'wp_has_ability' ) && wp_has_ability( $name ) ) return;
		wp_register_ability(
			$name,
			array(
				'label' => $label,
				'description' => $label . ' through the immutable Feature 007 Artifact Registry.',
				'category' => $readonly ? 'mad4b-read' : 'mad4b-write',
				'execute_callback' => array( __CLASS__, $method ),
				'permission_callback' => $readonly ? array( 'MAD4B_SCP_Policy', 'can_read' ) : array( 'MAD4B_SCP_Policy', 'can_mutate' ),
				'input_schema' => array( 'type' => 'object', 'additionalProperties' => true ),
				'output_schema' => array( 'type' => 'object', 'additionalProperties' => true ),
				'meta' => array(
					'public' => false,
					'show_in_rest' => false,
					'mcp' => array( 'public' => false, 'type' => 'tool', 'surface' => $readonly ? 'read' : 'write' ),
					'annotations' => array(
						'readonly' => (bool) $readonly,
						'destructive' => false,
						'idempotent' => (bool) $readonly,
					),
				),
			)
		);
	}

	private static function schema_ready() {
		return class_exists( 'MAD4B_SCP_Schema' )
			&& MAD4B_SCP_Schema::critical_ready()
			&& MAD4B_SCP_Schema::VERSION >= 10;
	}

	private static function site_uuid() {
		$uuid = class_exists( 'MAD4B_SCP_Site_Profile' ) ? strtolower( trim( (string) MAD4B_SCP_Site_Profile::site_uuid() ) ) : '';
		return preg_match( '/^[a-f0-9-]{36}$/', $uuid ) ? $uuid : '';
	}

	private static function valid_uuid( $value ) {
		return is_string( $value ) && 1 === preg_match( '/^[a-f0-9-]{36}$/', strtolower( trim( $value ) ) );
	}

	private static function actor_ref() {
		if ( class_exists( 'MAD4B_SCP_Identity_Context' ) ) {
			$identity = MAD4B_SCP_Identity_Context::current();
			if ( is_array( $identity ) ) {
				$subject = isset( $identity['subject_fingerprint'] ) ? trim( (string) $identity['subject_fingerprint'] ) : '';
				if ( '' !== $subject ) return substr( $subject, 0, 191 );
			}
		}
		if ( function_exists( 'get_current_user_id' ) ) {
			$user_id = (int) get_current_user_id();
			if ( $user_id > 0 ) return 'wp-user:' . $user_id;
		}
		return 'system';
	}

	public static function append_artifact( $input ) {
		global $wpdb;
		if ( ! self::schema_ready() ) return new WP_Error( 'mad4b_artifact_schema_unavailable', 'Artifact schema is not ready.' );
		$site_uuid = self::site_uuid();
		if ( '' === $site_uuid ) return new WP_Error( 'mad4b_artifact_site_identity_unavailable', 'Site Profile identity is unavailable.' );

		$job_id = isset( $input['job_id'] ) ? strtolower( trim( (string) $input['job_id'] ) ) : '';
		$type = isset( $input['artifact_type'] ) ? sanitize_key( (string) $input['artifact_type'] ) : '';
		$payload = isset( $input['payload'] ) && is_array( $input['payload'] ) ? $input['payload'] : null;
		$metadata = isset( $input['metadata'] ) && is_array( $input['metadata'] ) ? $input['metadata'] : array();
		$producer_stage = isset( $input['producer_stage'] ) ? strtoupper( sanitize_key( (string) $input['producer_stage'] ) ) : '';
		$producer_ref = isset( $input['producer_ref'] ) ? substr( sanitize_text_field( (string) $input['producer_ref'] ), 0, 191 ) : '';
		$reason = isset( $input['reason'] ) ? trim( sanitize_text_field( (string) $input['reason'] ) ) : '';

		if ( ! self::valid_uuid( $job_id ) ) return new WP_Error( 'mad4b_artifact_job_id_invalid', 'ContentJob ID is invalid.' );
		if ( ! in_array( $type, self::artifact_types(), true ) ) return new WP_Error( 'mad4b_artifact_type_invalid', 'Artifact type is not registered.' );
		if ( null === $payload ) return new WP_Error( 'mad4b_artifact_payload_invalid', 'Artifact payload must be an object/array.' );
		if ( strlen( $reason ) < 3 ) return new WP_Error( 'mad4b_artifact_reason_required', 'Artifact append reason is required.' );
		if ( '' !== $producer_stage && class_exists( 'MAD4B_SCP_Content_Jobs' ) && ! in_array( $producer_stage, MAD4B_SCP_Content_Jobs::stages(), true ) ) {
			return new WP_Error( 'mad4b_artifact_stage_invalid', 'Artifact producer stage is invalid.' );
		}

		$payload_json = self::stable_json( $payload );
		$metadata_json = self::stable_json( $metadata );
		if ( '' === $payload_json || '' === $metadata_json ) return new WP_Error( 'mad4b_artifact_json_invalid', 'Artifact payload/metadata could not be canonicalized.' );
		$content_sha = hash( 'sha256', $payload_json );
		$t = MAD4B_SCP_Schema::tables();

		$wpdb->query( 'START TRANSACTION' );
		try {
			$job = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT id,job_id FROM {$t['content_jobs']} WHERE job_id=%s AND site_uuid=%s LIMIT 1 FOR UPDATE",
					$job_id,
					$site_uuid
				),
				ARRAY_A
			);
			if ( ! is_array( $job ) ) throw new RuntimeException( 'artifact_job_missing' );

			$previous = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT artifact_id,version,status FROM {$t['artifacts']} WHERE job_id=%s AND site_uuid=%s AND artifact_type=%s ORDER BY version DESC LIMIT 1 FOR UPDATE",
					$job_id,
					$site_uuid,
					$type
				),
				ARRAY_A
			);
			$version = is_array( $previous ) ? ( (int) $previous['version'] + 1 ) : 1;
			$artifact_id = strtolower( wp_generate_uuid4() );
			$now = gmdate( 'Y-m-d H:i:s' );
			$previous_id = is_array( $previous ) ? (string) $previous['artifact_id'] : '';

			if ( '' !== $previous_id ) {
				$wpdb->update(
					$t['artifacts'],
					array( 'status' => 'superseded' ),
					array( 'artifact_id' => $previous_id, 'site_uuid' => $site_uuid ),
					array( '%s' ),
					array( '%s', '%s' )
				);
				self::invalidate_descendants_locked( $previous_id, $site_uuid, $job_id, 'source_superseded' );
			}

			$ok = $wpdb->insert(
				$t['artifacts'],
				array(
					'artifact_id' => $artifact_id,
					'site_uuid' => $site_uuid,
					'job_id' => $job_id,
					'artifact_type' => $type,
					'version' => $version,
					'status' => 'active',
					'content_sha256' => $content_sha,
					'payload_json' => $payload_json,
					'metadata_json' => $metadata_json,
					'producer_stage' => $producer_stage,
					'producer_ref' => $producer_ref,
					'supersedes_artifact_id' => $previous_id,
					'created_by_actor' => self::actor_ref(),
					'created_at' => $now,
				),
				array( '%s','%s','%s','%s','%d','%s','%s','%s','%s','%s','%s','%s','%s','%s' )
			);
			if ( false === $ok ) throw new RuntimeException( 'artifact_insert_failed:' . (string) $wpdb->last_error );

			if ( '' !== $previous_id ) {
				$edge = self::insert_edge_locked( $site_uuid, $job_id, $previous_id, $artifact_id, 'supersedes', '' );
				if ( is_wp_error( $edge ) ) throw new RuntimeException( $edge->get_error_code() );
			}

			if ( false === $wpdb->query( 'COMMIT' ) ) throw new RuntimeException( 'artifact_commit_failed' );
		} catch ( Throwable $e ) {
			$wpdb->query( 'ROLLBACK' );
			$code = $e->getMessage();
			if ( 'artifact_job_missing' === $code ) return new WP_Error( 'mad4b_artifact_job_missing', 'ContentJob was not found for this site.' );
			return new WP_Error( 'mad4b_artifact_append_failed', 'Unable to append Content Artifact.', array( 'cause' => $code ) );
		}
		return self::get_artifact( array( 'artifact_id' => $artifact_id ) );
	}

	public static function link_artifacts( $input ) {
		global $wpdb;
		if ( ! self::schema_ready() ) return new WP_Error( 'mad4b_artifact_schema_unavailable', 'Artifact schema is not ready.' );
		$site_uuid = self::site_uuid();
		$from = isset( $input['from_artifact_id'] ) ? strtolower( trim( (string) $input['from_artifact_id'] ) ) : '';
		$to = isset( $input['to_artifact_id'] ) ? strtolower( trim( (string) $input['to_artifact_id'] ) ) : '';
		$relation = isset( $input['relation'] ) ? sanitize_key( (string) $input['relation'] ) : '';
		if ( ! self::valid_uuid( $from ) || ! self::valid_uuid( $to ) || hash_equals( $from, $to ) ) return new WP_Error( 'mad4b_artifact_edge_invalid', 'Artifact edge endpoints are invalid.' );
		if ( ! in_array( $relation, self::relations(), true ) || 'supersedes' === $relation ) return new WP_Error( 'mad4b_artifact_relation_invalid', 'Artifact relation is not caller-linkable.' );
		$t = MAD4B_SCP_Schema::tables();
		$wpdb->query( 'START TRANSACTION' );
		try {
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT artifact_id,job_id FROM {$t['artifacts']} WHERE site_uuid=%s AND artifact_id IN (%s,%s) FOR UPDATE",
					$site_uuid,
					$from,
					$to
				),
				ARRAY_A
			);
			if ( ! is_array( $rows ) || 2 !== count( $rows ) ) throw new RuntimeException( 'artifact_edge_endpoint_missing' );
			$by_id = array();
			foreach ( $rows as $row ) $by_id[ $row['artifact_id'] ] = $row;
			if ( $by_id[ $from ]['job_id'] !== $by_id[ $to ]['job_id'] ) throw new RuntimeException( 'artifact_edge_cross_job' );
			$edge = self::insert_edge_locked( $site_uuid, (string) $by_id[ $from ]['job_id'], $from, $to, $relation, '' );
			if ( is_wp_error( $edge ) ) throw new RuntimeException( $edge->get_error_code() );
			if ( false === $wpdb->query( 'COMMIT' ) ) throw new RuntimeException( 'artifact_edge_commit_failed' );
		} catch ( Throwable $e ) {
			$wpdb->query( 'ROLLBACK' );
			return new WP_Error( 'mad4b_artifact_link_failed', 'Unable to link Content Artifacts.', array( 'cause' => $e->getMessage() ) );
		}
		return array( 'contract' => self::EDGE_CONTRACT, 'edge' => $edge, 'mutation_performed' => true );
	}

	public static function invalidate_artifact( $input ) {
		global $wpdb;
		if ( ! self::schema_ready() ) return new WP_Error( 'mad4b_artifact_schema_unavailable', 'Artifact schema is not ready.' );
		$site_uuid = self::site_uuid();
		$artifact_id = isset( $input['artifact_id'] ) ? strtolower( trim( (string) $input['artifact_id'] ) ) : '';
		$reason = isset( $input['reason_code'] ) ? sanitize_key( (string) $input['reason_code'] ) : 'source_invalidated';
		if ( ! self::valid_uuid( $artifact_id ) ) return new WP_Error( 'mad4b_artifact_id_invalid', 'Artifact ID is invalid.' );
		$t = MAD4B_SCP_Schema::tables();
		$wpdb->query( 'START TRANSACTION' );
		try {
			$row = $wpdb->get_row(
				$wpdb->prepare( "SELECT artifact_id,job_id FROM {$t['artifacts']} WHERE artifact_id=%s AND site_uuid=%s LIMIT 1 FOR UPDATE", $artifact_id, $site_uuid ),
				ARRAY_A
			);
			if ( ! is_array( $row ) ) throw new RuntimeException( 'artifact_missing' );
			$wpdb->update( $t['artifacts'], array( 'status' => 'stale' ), array( 'artifact_id' => $artifact_id, 'site_uuid' => $site_uuid ), array( '%s' ), array( '%s','%s' ) );
			$affected = self::invalidate_descendants_locked( $artifact_id, $site_uuid, (string) $row['job_id'], $reason );
			if ( false === $wpdb->query( 'COMMIT' ) ) throw new RuntimeException( 'artifact_invalidation_commit_failed' );
		} catch ( Throwable $e ) {
			$wpdb->query( 'ROLLBACK' );
			return new WP_Error( 'mad4b_artifact_invalidation_failed', 'Unable to invalidate Artifact lineage.', array( 'cause' => $e->getMessage() ) );
		}
		return array(
			'contract' => 'mad4b.artifact-invalidation.v1',
			'artifact_id' => $artifact_id,
			'reason_code' => $reason,
			'descendant_count' => count( $affected ),
			'descendant_artifact_ids' => array_values( $affected ),
			'mutation_performed' => true,
		);
	}

	public static function get_artifact( $input ) {
		global $wpdb;
		$site_uuid = self::site_uuid();
		$id = isset( $input['artifact_id'] ) ? strtolower( trim( (string) $input['artifact_id'] ) ) : '';
		if ( ! self::valid_uuid( $id ) ) return new WP_Error( 'mad4b_artifact_id_invalid', 'Artifact ID is invalid.' );
		$t = MAD4B_SCP_Schema::tables();
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t['artifacts']} WHERE artifact_id=%s AND site_uuid=%s LIMIT 1", $id, $site_uuid ), ARRAY_A );
		if ( ! is_array( $row ) ) return new WP_Error( 'mad4b_artifact_missing', 'Artifact was not found for this site.' );
		return array( 'contract' => self::CONTRACT, 'artifact' => self::normalize_artifact( $row ), 'mutation_performed' => false );
	}

	public static function list_artifacts( $input ) {
		global $wpdb;
		$site_uuid = self::site_uuid();
		$job_id = isset( $input['job_id'] ) ? strtolower( trim( (string) $input['job_id'] ) ) : '';
		if ( ! self::valid_uuid( $job_id ) ) return new WP_Error( 'mad4b_artifact_job_id_invalid', 'ContentJob ID is invalid.' );
		$type = isset( $input['artifact_type'] ) ? sanitize_key( (string) $input['artifact_type'] ) : '';
		$t = MAD4B_SCP_Schema::tables();
		if ( '' !== $type && ! in_array( $type, self::artifact_types(), true ) ) return new WP_Error( 'mad4b_artifact_type_invalid', 'Artifact type is not registered.' );
		$sql = "SELECT * FROM {$t['artifacts']} WHERE job_id=%s AND site_uuid=%s";
		$args = array( $job_id, $site_uuid );
		if ( '' !== $type ) { $sql .= ' AND artifact_type=%s'; $args[] = $type; }
		$sql .= ' ORDER BY artifact_type ASC,version ASC,id ASC';
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, ...$args ), ARRAY_A );
		$items = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) $items[] = self::normalize_artifact( $row );
		return array( 'contract' => self::CONTRACT, 'job_id' => $job_id, 'count' => count( $items ), 'items' => $items, 'mutation_performed' => false );
	}

	public static function get_edges( $input ) {
		global $wpdb;
		$site_uuid = self::site_uuid();
		$job_id = isset( $input['job_id'] ) ? strtolower( trim( (string) $input['job_id'] ) ) : '';
		if ( ! self::valid_uuid( $job_id ) ) return new WP_Error( 'mad4b_artifact_job_id_invalid', 'ContentJob ID is invalid.' );
		$t = MAD4B_SCP_Schema::tables();
		$rows = $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$t['artifact_edges']} WHERE job_id=%s AND site_uuid=%s ORDER BY id ASC", $job_id, $site_uuid ),
			ARRAY_A
		);
		return array( 'contract' => self::EDGE_CONTRACT, 'job_id' => $job_id, 'count' => is_array( $rows ) ? count( $rows ) : 0, 'items' => is_array( $rows ) ? $rows : array(), 'mutation_performed' => false );
	}

	private static function insert_edge_locked( $site_uuid, $job_id, $from, $to, $relation, $reason ) {
		global $wpdb;
		$t = MAD4B_SCP_Schema::tables();
		$edge_id = strtolower( wp_generate_uuid4() );
		$ok = $wpdb->insert(
			$t['artifact_edges'],
			array(
				'edge_id' => $edge_id,
				'site_uuid' => $site_uuid,
				'job_id' => $job_id,
				'from_artifact_id' => $from,
				'to_artifact_id' => $to,
				'relation' => $relation,
				'invalidated' => 0,
				'reason_code' => $reason,
				'created_at' => gmdate( 'Y-m-d H:i:s' ),
			),
			array( '%s','%s','%s','%s','%s','%s','%d','%s','%s' )
		);
		if ( false === $ok ) return new WP_Error( 'mad4b_artifact_edge_insert_failed', 'Unable to append Artifact lineage edge.', array( 'db_error' => $wpdb->last_error ) );
		return array( 'edge_id' => $edge_id, 'from_artifact_id' => $from, 'to_artifact_id' => $to, 'relation' => $relation );
	}

	private static function invalidate_descendants_locked( $source_id, $site_uuid, $job_id, $reason ) {
		global $wpdb;
		$t = MAD4B_SCP_Schema::tables();
		$queue = array( $source_id );
		$seen = array( $source_id => true );
		$affected = array();
		while ( $queue ) {
			$current = array_shift( $queue );
			$edges = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT edge_id,to_artifact_id,relation FROM {$t['artifact_edges']} WHERE from_artifact_id=%s AND site_uuid=%s AND job_id=%s AND invalidated=0 ORDER BY id ASC",
					$current,
					$site_uuid,
					$job_id
				),
				ARRAY_A
			);
			foreach ( is_array( $edges ) ? $edges : array() as $edge ) {
				if ( 'supersedes' === (string) $edge['relation'] ) continue;
				$to = (string) $edge['to_artifact_id'];
				$wpdb->update(
					$t['artifact_edges'],
					array( 'invalidated' => 1, 'reason_code' => $reason ),
					array( 'edge_id' => (string) $edge['edge_id'], 'site_uuid' => $site_uuid ),
					array( '%d','%s' ),
					array( '%s','%s' )
				);
				$wpdb->update(
					$t['artifacts'],
					array( 'status' => 'stale' ),
					array( 'artifact_id' => $to, 'site_uuid' => $site_uuid ),
					array( '%s' ),
					array( '%s','%s' )
				);
				if ( ! isset( $seen[ $to ] ) ) {
					$seen[ $to ] = true;
					$affected[ $to ] = $to;
					$queue[] = $to;
					if ( count( $seen ) > self::MAX_INVALIDATION_NODES ) throw new RuntimeException( 'artifact_invalidation_limit_exceeded' );
				}
			}
		}
		return $affected;
	}

	private static function normalize_artifact( array $row ) {
		$row['version'] = (int) $row['version'];
		$row['payload'] = json_decode( (string) $row['payload_json'], true );
		$row['metadata'] = '' === (string) $row['metadata_json'] ? array() : json_decode( (string) $row['metadata_json'], true );
		unset( $row['payload_json'], $row['metadata_json'] );
		return $row;
	}

	private static function stable_json( $value ) {
		$value = self::sort_value( $value );
		$json = wp_json_encode( $value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		return false === $json ? '' : $json;
	}

	private static function sort_value( $value ) {
		if ( ! is_array( $value ) ) return $value;
		$is_list = empty( $value ) || array_keys( $value ) === range( 0, count( $value ) - 1 );
		if ( ! $is_list ) ksort( $value, SORT_STRING );
		foreach ( $value as $key => $item ) $value[ $key ] = self::sort_value( $item );
		return $value;
	}
}

MAD4B_SCP_Artifacts::boot();
