<?php

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Durable ContentJob domain service.
 *
 * This service owns only the job aggregate and immutable transition events.
 * It does not call providers, publish content, mutate posts, or widen authority.
 */
final class MAD4B_SCP_Content_Jobs {
	const CONTRACT = 'mad4b.content-job.v1';
	const EVENT_CONTRACT = 'mad4b.content-job-event.v1';

	private static $booted = false;

	public static function boot() {
		if ( self::$booted ) return;
		self::$booted = true;
		add_action( 'wp_abilities_api_init', array( __CLASS__, 'register_abilities' ), 39 );
	}

	public static function states() {
		return array( 'NEW', 'QUEUED', 'RUNNING', 'WAITING_REVIEW', 'BLOCKED', 'FAILED', 'COMPLETED', 'CANCELLED' );
	}

	public static function stages() {
		return array(
			'INTAKE', 'SITE_DISCOVERY', 'KNOWLEDGE_DISPATCH', 'KEYWORD_RESEARCH',
			'SERP_RESEARCH', 'COMPETITOR_SELECTION', 'SCRAPING', 'COMPETITOR_ANALYSIS',
			'INFORMATION_GAIN', 'BLUEPRINT', 'BLUEPRINT_QA', 'WRITING', 'FACT_QA',
			'EDITORIAL_QA', 'MEDIA', 'SEO', 'FINAL_QA', 'DRAFT', 'SCHEDULING',
			'PUBLISHING', 'POST_PUBLISH',
		);
	}

	private static function transition_map() {
		return array(
			'NEW' => array( 'QUEUED', 'CANCELLED' ),
			'QUEUED' => array( 'RUNNING', 'BLOCKED', 'FAILED', 'CANCELLED' ),
			'RUNNING' => array( 'WAITING_REVIEW', 'BLOCKED', 'FAILED', 'COMPLETED', 'CANCELLED' ),
			'WAITING_REVIEW' => array( 'RUNNING', 'BLOCKED', 'FAILED', 'COMPLETED', 'CANCELLED' ),
			'BLOCKED' => array( 'QUEUED', 'RUNNING', 'FAILED', 'CANCELLED' ),
			'FAILED' => array( 'QUEUED', 'CANCELLED' ),
			'COMPLETED' => array(),
			'CANCELLED' => array(),
		);
	}

	public static function register_abilities() {
		if ( ! function_exists( 'wp_register_ability' ) ) return;

		self::register(
			'mad4b/content-job-list',
			'List Content Jobs',
			'list_jobs',
			array(
				'state' => array( 'type' => 'string', 'enum' => array_merge( array( '' ), self::states() ), 'default' => '' ),
				'stage' => array( 'type' => 'string', 'enum' => array_merge( array( '' ), self::stages() ), 'default' => '' ),
				'limit' => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 200, 'default' => 50 ),
			),
			array(),
			true
		);
		self::register(
			'mad4b/content-job-get',
			'Get Content Job',
			'get_job',
			array( 'job_id' => array( 'type' => 'string', 'minLength' => 36, 'maxLength' => 36 ) ),
			array( 'job_id' ),
			true
		);
		self::register(
			'mad4b/content-job-events',
			'Get Content Job Events',
			'get_events',
			array(
				'job_id' => array( 'type' => 'string', 'minLength' => 36, 'maxLength' => 36 ),
				'limit' => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 500, 'default' => 100 ),
			),
			array( 'job_id' ),
			true
		);
		self::register(
			'mad4b/content-job-create',
			'Create Content Job',
			'create_job',
			array(
				'brand_id' => array( 'type' => 'string', 'minLength' => 1, 'maxLength' => 191 ),
				'subject' => array( 'type' => 'string', 'minLength' => 1, 'maxLength' => 5000 ),
				'primary_keyword' => array( 'type' => 'string', 'maxLength' => 191, 'default' => '' ),
				'language' => array( 'type' => 'string', 'minLength' => 2, 'maxLength' => 32 ),
				'country' => array( 'type' => 'string', 'minLength' => 2, 'maxLength' => 32 ),
				'content_type' => array( 'type' => 'string', 'minLength' => 1, 'maxLength' => 64 ),
				'writer_profile_id' => array( 'type' => 'string', 'pattern' => '^(|[a-f0-9]{64}) => array( 'type' => 'string', 'maxLength' => 32, 'default' => 'standard' ),
				'automation_level' => array( 'type' => 'string', 'maxLength' => 32, 'default' => 'review_gated' ),
				'target_post_type' => array( 'type' => 'string', 'maxLength' => 64, 'default' => '' ),
				'desired_publish_at' => array( 'type' => 'string', 'maxLength' => 32, 'default' => '' ),
				'reason' => array( 'type' => 'string', 'minLength' => 3, 'maxLength' => 500 ),
			),
			array( 'brand_id', 'subject', 'language', 'country', 'content_type', 'reason' ),
			false
		);
		self::register(
			'mad4b/content-job-transition',
			'Transition Content Job',
			'transition_job',
			array(
				'job_id' => array( 'type' => 'string', 'minLength' => 36, 'maxLength' => 36 ),
				'expected_revision' => array( 'type' => 'integer', 'minimum' => 1 ),
				'state' => array( 'type' => 'string', 'enum' => self::states() ),
				'stage' => array( 'type' => 'string', 'enum' => self::stages() ),
				'reason' => array( 'type' => 'string', 'minLength' => 3, 'maxLength' => 500 ),
				'plan_sha256' => array( 'type' => 'string', 'pattern' => '^[a-f0-9]{64}$', 'default' => '' ),
				'artifact_id' => array( 'type' => 'string', 'maxLength' => 191, 'default' => '' ),
			),
			array( 'job_id', 'expected_revision', 'state', 'stage', 'reason' ),
			false
		);
		self::register(
			'mad4b/content-job-cancel',
			'Cancel Content Job',
			'cancel_job',
			array(
				'job_id' => array( 'type' => 'string', 'minLength' => 36, 'maxLength' => 36 ),
				'expected_revision' => array( 'type' => 'integer', 'minimum' => 1 ),
				'reason' => array( 'type' => 'string', 'minLength' => 3, 'maxLength' => 500 ),
			),
			array( 'job_id', 'expected_revision', 'reason' ),
			false
		);
	}

	private static function register( $name, $label, $method, array $properties, array $required, $readonly ) {
		if ( function_exists( 'wp_has_ability' ) && wp_has_ability( $name ) ) return;
		wp_register_ability(
			$name,
			array(
				'label' => $label,
				'description' => $label . ' through the governed Feature 007 ContentJob aggregate.',
				'category' => $readonly ? 'mad4b-read' : 'mad4b-write',
				'execute_callback' => array( __CLASS__, $method ),
				'permission_callback' => $readonly ? array( 'MAD4B_SCP_Policy', 'can_read' ) : array( 'MAD4B_SCP_Policy', 'can_mutate' ),
				'input_schema' => array(
					'type' => 'object',
					'properties' => $properties,
					'required' => $required,
					'additionalProperties' => false,
				),
				'output_schema' => array( 'type' => 'object', 'additionalProperties' => true ),
				'meta' => array(
					'public' => false,
					'show_in_rest' => false,
					'mcp' => array( 'public' => false, 'type' => 'tool', 'surface' => $readonly ? 'read' : 'write' ),
					'annotations' => array(
						'readonly' => (bool) $readonly,
						'destructive' => ! $readonly,
						'idempotent' => $readonly,
					),
				),
			)
		);
	}

	private static function schema_ready() {
		return class_exists( 'MAD4B_SCP_Schema' ) && MAD4B_SCP_Schema::critical_ready();
	}

	private static function site_uuid() {
		$uuid = class_exists( 'MAD4B_SCP_Site_Profile' ) ? strtolower( trim( (string) MAD4B_SCP_Site_Profile::site_uuid() ) ) : '';
		return preg_match( '/^[a-f0-9-]{36}$/', $uuid ) ? $uuid : '';
	}

	private static function valid_uuid( $value ) {
		return is_string( $value ) && 1 === preg_match( '/^[a-f0-9-]{36}$/', strtolower( trim( $value ) ) );
	}

	private static function actor() {
		$actor = array( 'type' => 'wordpress_user', 'id' => (string) get_current_user_id(), 'nhi' => '' );
		if ( class_exists( 'MAD4B_SCP_Identity_Context' ) && class_exists( 'MAD4B_SCP_Agent_Registry' ) ) {
			$identity = MAD4B_SCP_Identity_Context::current();
			if ( ! is_wp_error( $identity ) ) {
				$agent = MAD4B_SCP_Agent_Registry::resolve_agent( $identity );
				if ( ! is_wp_error( $agent ) && ! empty( $agent['public_id'] ) ) {
					$actor['type'] = 'nhi';
					$actor['id'] = strtolower( (string) $agent['public_id'] );
					$actor['nhi'] = $actor['id'];
				}
			}
		}
		return $actor;
	}

	private static function normalize_datetime( $value ) {
		$value = trim( (string) $value );
		if ( '' === $value ) return null;
		$ts = strtotime( $value );
		if ( false === $ts ) return new WP_Error( 'mad4b_content_job_datetime_invalid', 'Invalid desired publish datetime.' );
		return gmdate( 'Y-m-d H:i:s', $ts );
	}

	private static function row( $row ) {
		if ( ! is_array( $row ) ) return null;
		foreach ( array( 'id', 'target_post_id', 'job_revision', 'created_by_user' ) as $key ) {
			if ( array_key_exists( $key, $row ) && null !== $row[ $key ] ) $row[ $key ] = (int) $row[ $key ];
		}
		return $row;
	}

	public static function list_jobs( $input ) {
		global $wpdb;
		if ( ! self::schema_ready() ) return new WP_Error( 'mad4b_content_job_schema_unavailable', 'ContentJob schema is not ready.' );
		$site_uuid = self::site_uuid();
		if ( '' === $site_uuid ) return new WP_Error( 'mad4b_content_job_site_identity_unavailable', 'Site Profile identity is unavailable.' );
		$t = MAD4B_SCP_Schema::tables();
		$where = array( 'site_uuid=%s' );
		$args = array( $site_uuid );
		$state = isset( $input['state'] ) ? strtoupper( sanitize_key( (string) $input['state'] ) ) : '';
		$stage = isset( $input['stage'] ) ? strtoupper( sanitize_key( (string) $input['stage'] ) ) : '';
		if ( '' !== $state ) { $where[] = 'state=%s'; $args[] = $state; }
		if ( '' !== $stage ) { $where[] = 'stage=%s'; $args[] = $stage; }
		$limit = isset( $input['limit'] ) ? max( 1, min( 200, absint( $input['limit'] ) ) ) : 50;
		$args[] = $limit;
		$sql = "SELECT * FROM {$t['content_jobs']} WHERE " . implode( ' AND ', $where ) . ' ORDER BY updated_at DESC,id DESC LIMIT %d';
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $args ), ARRAY_A );
		return array(
			'contract' => self::CONTRACT,
			'items' => array_values( array_filter( array_map( array( __CLASS__, 'row' ), is_array( $rows ) ? $rows : array() ) ) ),
			'count' => is_array( $rows ) ? count( $rows ) : 0,
			'mutation_performed' => false,
		);
	}

	public static function get_job( $input ) {
		$job_id = isset( $input['job_id'] ) ? strtolower( trim( (string) $input['job_id'] ) ) : '';
		$row = self::load_job( $job_id, false );
		if ( is_wp_error( $row ) ) return $row;
		return array( 'contract' => self::CONTRACT, 'job' => self::row( $row ), 'mutation_performed' => false );
	}

	public static function get_events( $input ) {
		global $wpdb;
		$job_id = isset( $input['job_id'] ) ? strtolower( trim( (string) $input['job_id'] ) ) : '';
		$job = self::load_job( $job_id, false );
		if ( is_wp_error( $job ) ) return $job;
		$limit = isset( $input['limit'] ) ? max( 1, min( 500, absint( $input['limit'] ) ) ) : 100;
		$t = MAD4B_SCP_Schema::tables();
		$rows = $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$t['content_job_events']} WHERE job_id=%s ORDER BY sequence ASC LIMIT %d", $job_id, $limit ),
			ARRAY_A
		);
		return array(
			'contract' => self::EVENT_CONTRACT,
			'job_id' => $job_id,
			'items' => is_array( $rows ) ? $rows : array(),
			'count' => is_array( $rows ) ? count( $rows ) : 0,
			'mutation_performed' => false,
		);
	}

	public static function create_job( $input ) {
		global $wpdb;
		if ( ! self::schema_ready() ) return new WP_Error( 'mad4b_content_job_schema_unavailable', 'ContentJob schema is not ready.' );
		$site_uuid = self::site_uuid();
		if ( '' === $site_uuid ) return new WP_Error( 'mad4b_content_job_site_identity_unavailable', 'Site Profile identity is unavailable.' );
		$subject = trim( wp_strip_all_tags( (string) $input['subject'] ) );
		if ( '' === $subject || strlen( $subject ) > 5000 ) return new WP_Error( 'mad4b_content_job_subject_invalid', 'ContentJob subject is missing or too long.' );
		$brand_id = sanitize_text_field( (string) $input['brand_id'] );
		$language = sanitize_key( (string) $input['language'] );
		$country = sanitize_key( (string) $input['country'] );
		$content_type = sanitize_key( (string) $input['content_type'] );
		if ( '' === $brand_id || '' === $language || '' === $country || '' === $content_type ) return new WP_Error( 'mad4b_content_job_identity_invalid', 'ContentJob brand/market/type identity is incomplete.' );
		$publish_at = self::normalize_datetime( isset( $input['desired_publish_at'] ) ? $input['desired_publish_at'] : '' );
		if ( is_wp_error( $publish_at ) ) return $publish_at;
		$writer_profile_id = strtolower( trim( (string) ( $input['writer_profile_id'] ?? '' ) ) );
		$writer_profile_version = strtolower( trim( (string) ( $input['writer_profile_version'] ?? '' ) ) );
		if ( ( '' === $writer_profile_id ) xor ( '' === $writer_profile_version ) ) {
			return new WP_Error( 'mad4b_writer_profile_binding_incomplete', 'WriterProfile identity and version must be supplied together.' );
		}
		if ( '' !== $writer_profile_id
			&& ( 1 !== preg_match( '/^[a-f0-9]{64}$/', $writer_profile_id )
				|| 1 !== preg_match( '/^[a-f0-9]{64}$/', $writer_profile_version ) ) ) {
			return new WP_Error( 'mad4b_writer_profile_identity_invalid', 'WriterProfile identity and version must be exact SHA-256 identities.' );
		}

		$job_id = strtolower( wp_generate_uuid4() );
		$now = gmdate( 'Y-m-d H:i:s' );
		$actor = self::actor();
		$t = MAD4B_SCP_Schema::tables();
		$data = array(
			'job_id' => $job_id,
			'tenant_id' => '',
			'site_uuid' => $site_uuid,
			'brand_id' => $brand_id,
			'subject' => $subject,
			'primary_keyword' => sanitize_text_field( isset( $input['primary_keyword'] ) ? (string) $input['primary_keyword'] : '' ),
			'language' => $language,
			'country' => $country,
			'content_type' => $content_type,
			'writer_profile_id' => $writer_profile_id,
			'writer_profile_version' => $writer_profile_version,
			'research_depth' => sanitize_key( isset( $input['research_depth'] ) ? (string) $input['research_depth'] : 'standard' ),
			'automation_level' => sanitize_key( isset( $input['automation_level'] ) ? (string) $input['automation_level'] : 'review_gated' ),
			'state' => 'NEW',
			'stage' => 'INTAKE',
			'target_post_type' => sanitize_key( isset( $input['target_post_type'] ) ? (string) $input['target_post_type'] : '' ),
			'target_post_id' => null,
			'desired_publish_at' => $publish_at,
			'current_artifact_id' => '',
			'quality_status' => 'unknown',
			'last_error_code' => '',
			'last_error_summary' => '',
			'job_revision' => 1,
			'created_by_nhi' => $actor['nhi'],
			'created_by_user' => get_current_user_id(),
			'created_at' => $now,
			'updated_at' => $now,
			'completed_at' => null,
			'cancelled_at' => null,
		);

		$wpdb->query( 'START TRANSACTION' );
		try {
			if ( false === $wpdb->insert( $t['content_jobs'], $data ) ) throw new RuntimeException( 'content_job_insert_failed:' . $wpdb->last_error );
			$event = self::append_event_locked( $job_id, 1, 'CREATED', '', 'NEW', '', 'INTAKE', (string) $input['reason'], $actor, '', '' );
			if ( is_wp_error( $event ) ) throw new RuntimeException( $event->get_error_code() );
			$audit = MAD4B_SCP_Audit::record( 'mad4b/content-job-create', array( 'job_id' => $job_id, 'site_uuid' => $site_uuid, 'revision' => 1 ), 'ok', true );
			if ( is_wp_error( $audit ) ) throw new RuntimeException( $audit->get_error_code() );
			if ( false === $wpdb->query( 'COMMIT' ) ) throw new RuntimeException( 'content_job_commit_failed' );
			MAD4B_SCP_Audit::transaction_committed();
		} catch ( Throwable $e ) {
			$wpdb->query( 'ROLLBACK' );
			MAD4B_SCP_Audit::transaction_rolled_back();
			return new WP_Error( 'mad4b_content_job_create_failed', 'Unable to create ContentJob.', array( 'cause' => $e->getMessage() ) );
		}
		return self::get_job( array( 'job_id' => $job_id ) );
	}

	public static function transition_job( $input ) {
		return self::transition(
			isset( $input['job_id'] ) ? $input['job_id'] : '',
			isset( $input['expected_revision'] ) ? $input['expected_revision'] : 0,
			isset( $input['state'] ) ? $input['state'] : '',
			isset( $input['stage'] ) ? $input['stage'] : '',
			isset( $input['reason'] ) ? $input['reason'] : '',
			isset( $input['plan_sha256'] ) ? $input['plan_sha256'] : '',
			isset( $input['artifact_id'] ) ? $input['artifact_id'] : ''
		);
	}

	public static function cancel_job( $input ) {
		$job = self::load_job( isset( $input['job_id'] ) ? $input['job_id'] : '', false );
		if ( is_wp_error( $job ) ) return $job;
		return self::transition(
			$job['job_id'],
			isset( $input['expected_revision'] ) ? $input['expected_revision'] : 0,
			'CANCELLED',
			$job['stage'],
			isset( $input['reason'] ) ? $input['reason'] : '',
			'',
			''
		);
	}

	private static function transition( $job_id, $expected_revision, $new_state, $new_stage, $reason, $plan_sha256, $artifact_id ) {
		global $wpdb;
		if ( ! self::schema_ready() ) return new WP_Error( 'mad4b_content_job_schema_unavailable', 'ContentJob schema is not ready.' );
		$job_id = strtolower( trim( (string) $job_id ) );
		if ( ! self::valid_uuid( $job_id ) ) return new WP_Error( 'mad4b_content_job_id_invalid', 'ContentJob ID is invalid.' );
		$expected_revision = absint( $expected_revision );
		$new_state = strtoupper( sanitize_key( (string) $new_state ) );
		$new_stage = strtoupper( sanitize_key( (string) $new_stage ) );
		$reason = trim( sanitize_text_field( (string) $reason ) );
		$plan_sha256 = strtolower( trim( (string) $plan_sha256 ) );
		$artifact_id = sanitize_text_field( (string) $artifact_id );
		if ( ! in_array( $new_state, self::states(), true ) || ! in_array( $new_stage, self::stages(), true ) ) return new WP_Error( 'mad4b_content_job_transition_invalid', 'Requested state/stage is invalid.' );
		if ( '' !== $plan_sha256 && ! preg_match( '/^[a-f0-9]{64}$/', $plan_sha256 ) ) return new WP_Error( 'mad4b_content_job_plan_invalid', 'Plan SHA is invalid.' );
		if ( strlen( $reason ) < 3 ) return new WP_Error( 'mad4b_content_job_reason_required', 'Transition reason is required.' );

		$t = MAD4B_SCP_Schema::tables();
		$wpdb->query( 'START TRANSACTION' );
		try {
			$row = self::load_job( $job_id, true );
			if ( is_wp_error( $row ) ) throw new RuntimeException( $row->get_error_code() );
			if ( $expected_revision !== (int) $row['job_revision'] ) throw new RuntimeException( 'content_job_revision_conflict' );
			if ( in_array( $row['state'], array( 'COMPLETED', 'CANCELLED' ), true ) ) throw new RuntimeException( 'content_job_terminal_immutable' );
			$same_state = hash_equals( (string) $row['state'], $new_state );
			$same_stage = hash_equals( (string) $row['stage'], $new_stage );
			if ( $same_state && $same_stage ) throw new RuntimeException( 'content_job_noop_transition' );
			$allowed = self::transition_map();
			if ( ! $same_state && ( ! isset( $allowed[ $row['state'] ] ) || ! in_array( $new_state, $allowed[ $row['state'] ], true ) ) ) {
				throw new RuntimeException( 'content_job_state_transition_denied' );
			}

			$revision = $expected_revision + 1;
			$now = gmdate( 'Y-m-d H:i:s' );
			$update = array(
				'state' => $new_state,
				'stage' => $new_stage,
				'current_artifact_id' => '' !== $artifact_id ? $artifact_id : (string) $row['current_artifact_id'],
				'job_revision' => $revision,
				'updated_at' => $now,
				'completed_at' => 'COMPLETED' === $new_state ? $now : $row['completed_at'],
				'cancelled_at' => 'CANCELLED' === $new_state ? $now : $row['cancelled_at'],
			);
			$changed = $wpdb->update(
				$t['content_jobs'],
				$update,
				array( 'id' => (int) $row['id'], 'job_revision' => $expected_revision ),
				null,
				array( '%d', '%d' )
			);
			if ( 1 !== (int) $changed ) throw new RuntimeException( 'content_job_transition_cas_failed' );
			$actor = self::actor();
			$event = self::append_event_locked(
				$job_id,
				$revision,
				'CANCELLED' === $new_state ? 'CANCELLED' : 'TRANSITIONED',
				(string) $row['state'],
				$new_state,
				(string) $row['stage'],
				$new_stage,
				$reason,
				$actor,
				$plan_sha256,
				$artifact_id
			);
			if ( is_wp_error( $event ) ) throw new RuntimeException( $event->get_error_code() );
			$audit = MAD4B_SCP_Audit::record( 'mad4b/content-job-transition', array( 'job_id' => $job_id, 'from_revision' => $expected_revision, 'to_revision' => $revision, 'state' => $new_state, 'stage' => $new_stage ), 'ok', true );
			if ( is_wp_error( $audit ) ) throw new RuntimeException( $audit->get_error_code() );
			if ( false === $wpdb->query( 'COMMIT' ) ) throw new RuntimeException( 'content_job_transition_commit_failed' );
			MAD4B_SCP_Audit::transaction_committed();
		} catch ( Throwable $e ) {
			$wpdb->query( 'ROLLBACK' );
			MAD4B_SCP_Audit::transaction_rolled_back();
			$code = $e->getMessage();
			if ( 'content_job_revision_conflict' === $code ) return new WP_Error( 'mad4b_content_job_revision_conflict', 'ContentJob revision changed since the requested transition.' );
			if ( 'content_job_terminal_immutable' === $code ) return new WP_Error( 'mad4b_content_job_terminal_immutable', 'Completed or cancelled ContentJob history is immutable.' );
			if ( 'content_job_state_transition_denied' === $code ) return new WP_Error( 'mad4b_content_job_state_transition_denied', 'Requested lifecycle transition is not allowed.' );
			if ( 'content_job_noop_transition' === $code ) return new WP_Error( 'mad4b_content_job_noop_transition', 'State and stage are unchanged.' );
			return new WP_Error( 'mad4b_content_job_transition_failed', 'Unable to transition ContentJob.', array( 'cause' => $code ) );
		}
		return self::get_job( array( 'job_id' => $job_id ) );
	}

	private static function load_job( $job_id, $for_update ) {
		global $wpdb;
		if ( ! self::valid_uuid( $job_id ) ) return new WP_Error( 'mad4b_content_job_id_invalid', 'ContentJob ID is invalid.' );
		$site_uuid = self::site_uuid();
		if ( '' === $site_uuid ) return new WP_Error( 'mad4b_content_job_site_identity_unavailable', 'Site Profile identity is unavailable.' );
		$t = MAD4B_SCP_Schema::tables();
		$sql = $wpdb->prepare( "SELECT * FROM {$t['content_jobs']} WHERE job_id=%s AND site_uuid=%s LIMIT 1" . ( $for_update ? ' FOR UPDATE' : '' ), $job_id, $site_uuid );
		$row = $wpdb->get_row( $sql, ARRAY_A );
		return is_array( $row ) ? $row : new WP_Error( 'mad4b_content_job_missing', 'ContentJob was not found for this site.' );
	}

	private static function append_event_locked( $job_id, $sequence, $event_type, $previous_state, $new_state, $previous_stage, $new_stage, $reason, array $actor, $plan_sha256, $artifact_id ) {
		global $wpdb;
		$t = MAD4B_SCP_Schema::tables();
		$previous_hash = (string) $wpdb->get_var(
			$wpdb->prepare( "SELECT entry_sha256 FROM {$t['content_job_events']} WHERE job_id=%s ORDER BY sequence DESC LIMIT 1", $job_id )
		);
		$event_id = strtolower( wp_generate_uuid4() );
		$now = gmdate( 'Y-m-d H:i:s' );
		$material = array(
			'contract' => self::EVENT_CONTRACT,
			'event_id' => $event_id,
			'job_id' => $job_id,
			'sequence' => (int) $sequence,
			'event_type' => sanitize_key( (string) $event_type ),
			'previous_state' => (string) $previous_state,
			'new_state' => (string) $new_state,
			'previous_stage' => (string) $previous_stage,
			'new_stage' => (string) $new_stage,
			'reason_code' => substr( sanitize_key( (string) $reason ), 0, 64 ),
			'actor_type' => sanitize_key( (string) $actor['type'] ),
			'actor_id' => substr( sanitize_text_field( (string) $actor['id'] ), 0, 191 ),
			'plan_sha256' => (string) $plan_sha256,
			'artifact_id' => substr( sanitize_text_field( (string) $artifact_id ), 0, 191 ),
			'provider_id' => '',
			'previous_entry_sha256' => $previous_hash,
			'created_at' => $now,
		);
		$entry_hash = hash( 'sha256', self::stable_json( $material ) );
		$ok = $wpdb->insert(
			$t['content_job_events'],
			array(
				'event_id' => $event_id,
				'job_id' => $job_id,
				'sequence' => (int) $sequence,
				'event_type' => $material['event_type'],
				'previous_state' => $previous_state,
				'new_state' => $new_state,
				'previous_stage' => $previous_stage,
				'new_stage' => $new_stage,
				'reason_code' => $material['reason_code'],
				'correlation_id' => '',
				'actor_type' => $material['actor_type'],
				'actor_id' => $material['actor_id'],
				'plan_sha256' => $plan_sha256,
				'artifact_id' => $artifact_id,
				'provider_id' => '',
				'metadata_json' => wp_json_encode( array( 'reason' => $reason ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ),
				'previous_entry_sha256' => $previous_hash,
				'entry_sha256' => $entry_hash,
				'created_at' => $now,
			)
		);
		if ( false === $ok ) return new WP_Error( 'mad4b_content_job_event_insert_failed', 'Unable to append ContentJob event.', array( 'db_error' => $wpdb->last_error ) );
		return array( 'event_id' => $event_id, 'entry_sha256' => $entry_hash, 'sequence' => (int) $sequence );
	}

	private static function stable_json( $value ) {
		$value = self::sort_value( $value );
		$json = wp_json_encode( $value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		return false === $json ? '' : $json;
	}

	private static function sort_value( $value ) {
		if ( ! is_array( $value ) ) return $value;
		$is_list = array_keys( $value ) === range( 0, count( $value ) - 1 );
		if ( ! $is_list ) ksort( $value, SORT_STRING );
		foreach ( $value as $key => $item ) $value[ $key ] = self::sort_value( $item );
		return $value;
	}
}

MAD4B_SCP_Content_Jobs::boot();
, 'maxLength' => 64, 'default' => '' ),
				'writer_profile_version' => array( 'type' => 'string', 'pattern' => '^(|[a-f0-9]{64})
				'research_depth' => array( 'type' => 'string', 'maxLength' => 32, 'default' => 'standard' ),
				'automation_level' => array( 'type' => 'string', 'maxLength' => 32, 'default' => 'review_gated' ),
				'target_post_type' => array( 'type' => 'string', 'maxLength' => 64, 'default' => '' ),
				'desired_publish_at' => array( 'type' => 'string', 'maxLength' => 32, 'default' => '' ),
				'reason' => array( 'type' => 'string', 'minLength' => 3, 'maxLength' => 500 ),
			),
			array( 'brand_id', 'subject', 'language', 'country', 'content_type', 'reason' ),
			false
		);
		self::register(
			'mad4b/content-job-transition',
			'Transition Content Job',
			'transition_job',
			array(
				'job_id' => array( 'type' => 'string', 'minLength' => 36, 'maxLength' => 36 ),
				'expected_revision' => array( 'type' => 'integer', 'minimum' => 1 ),
				'state' => array( 'type' => 'string', 'enum' => self::states() ),
				'stage' => array( 'type' => 'string', 'enum' => self::stages() ),
				'reason' => array( 'type' => 'string', 'minLength' => 3, 'maxLength' => 500 ),
				'plan_sha256' => array( 'type' => 'string', 'pattern' => '^[a-f0-9]{64}$', 'default' => '' ),
				'artifact_id' => array( 'type' => 'string', 'maxLength' => 191, 'default' => '' ),
			),
			array( 'job_id', 'expected_revision', 'state', 'stage', 'reason' ),
			false
		);
		self::register(
			'mad4b/content-job-cancel',
			'Cancel Content Job',
			'cancel_job',
			array(
				'job_id' => array( 'type' => 'string', 'minLength' => 36, 'maxLength' => 36 ),
				'expected_revision' => array( 'type' => 'integer', 'minimum' => 1 ),
				'reason' => array( 'type' => 'string', 'minLength' => 3, 'maxLength' => 500 ),
			),
			array( 'job_id', 'expected_revision', 'reason' ),
			false
		);
	}

	private static function register( $name, $label, $method, array $properties, array $required, $readonly ) {
		if ( function_exists( 'wp_has_ability' ) && wp_has_ability( $name ) ) return;
		wp_register_ability(
			$name,
			array(
				'label' => $label,
				'description' => $label . ' through the governed Feature 007 ContentJob aggregate.',
				'category' => $readonly ? 'mad4b-read' : 'mad4b-write',
				'execute_callback' => array( __CLASS__, $method ),
				'permission_callback' => $readonly ? array( 'MAD4B_SCP_Policy', 'can_read' ) : array( 'MAD4B_SCP_Policy', 'can_mutate' ),
				'input_schema' => array(
					'type' => 'object',
					'properties' => $properties,
					'required' => $required,
					'additionalProperties' => false,
				),
				'output_schema' => array( 'type' => 'object', 'additionalProperties' => true ),
				'meta' => array(
					'public' => false,
					'show_in_rest' => false,
					'mcp' => array( 'public' => false, 'type' => 'tool', 'surface' => $readonly ? 'read' : 'write' ),
					'annotations' => array(
						'readonly' => (bool) $readonly,
						'destructive' => ! $readonly,
						'idempotent' => $readonly,
					),
				),
			)
		);
	}

	private static function schema_ready() {
		return class_exists( 'MAD4B_SCP_Schema' ) && MAD4B_SCP_Schema::critical_ready();
	}

	private static function site_uuid() {
		$uuid = class_exists( 'MAD4B_SCP_Site_Profile' ) ? strtolower( trim( (string) MAD4B_SCP_Site_Profile::site_uuid() ) ) : '';
		return preg_match( '/^[a-f0-9-]{36}$/', $uuid ) ? $uuid : '';
	}

	private static function valid_uuid( $value ) {
		return is_string( $value ) && 1 === preg_match( '/^[a-f0-9-]{36}$/', strtolower( trim( $value ) ) );
	}

	private static function actor() {
		$actor = array( 'type' => 'wordpress_user', 'id' => (string) get_current_user_id(), 'nhi' => '' );
		if ( class_exists( 'MAD4B_SCP_Identity_Context' ) && class_exists( 'MAD4B_SCP_Agent_Registry' ) ) {
			$identity = MAD4B_SCP_Identity_Context::current();
			if ( ! is_wp_error( $identity ) ) {
				$agent = MAD4B_SCP_Agent_Registry::resolve_agent( $identity );
				if ( ! is_wp_error( $agent ) && ! empty( $agent['public_id'] ) ) {
					$actor['type'] = 'nhi';
					$actor['id'] = strtolower( (string) $agent['public_id'] );
					$actor['nhi'] = $actor['id'];
				}
			}
		}
		return $actor;
	}

	private static function normalize_datetime( $value ) {
		$value = trim( (string) $value );
		if ( '' === $value ) return null;
		$ts = strtotime( $value );
		if ( false === $ts ) return new WP_Error( 'mad4b_content_job_datetime_invalid', 'Invalid desired publish datetime.' );
		return gmdate( 'Y-m-d H:i:s', $ts );
	}

	private static function row( $row ) {
		if ( ! is_array( $row ) ) return null;
		foreach ( array( 'id', 'target_post_id', 'job_revision', 'created_by_user' ) as $key ) {
			if ( array_key_exists( $key, $row ) && null !== $row[ $key ] ) $row[ $key ] = (int) $row[ $key ];
		}
		return $row;
	}

	public static function list_jobs( $input ) {
		global $wpdb;
		if ( ! self::schema_ready() ) return new WP_Error( 'mad4b_content_job_schema_unavailable', 'ContentJob schema is not ready.' );
		$site_uuid = self::site_uuid();
		if ( '' === $site_uuid ) return new WP_Error( 'mad4b_content_job_site_identity_unavailable', 'Site Profile identity is unavailable.' );
		$t = MAD4B_SCP_Schema::tables();
		$where = array( 'site_uuid=%s' );
		$args = array( $site_uuid );
		$state = isset( $input['state'] ) ? strtoupper( sanitize_key( (string) $input['state'] ) ) : '';
		$stage = isset( $input['stage'] ) ? strtoupper( sanitize_key( (string) $input['stage'] ) ) : '';
		if ( '' !== $state ) { $where[] = 'state=%s'; $args[] = $state; }
		if ( '' !== $stage ) { $where[] = 'stage=%s'; $args[] = $stage; }
		$limit = isset( $input['limit'] ) ? max( 1, min( 200, absint( $input['limit'] ) ) ) : 50;
		$args[] = $limit;
		$sql = "SELECT * FROM {$t['content_jobs']} WHERE " . implode( ' AND ', $where ) . ' ORDER BY updated_at DESC,id DESC LIMIT %d';
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $args ), ARRAY_A );
		return array(
			'contract' => self::CONTRACT,
			'items' => array_values( array_filter( array_map( array( __CLASS__, 'row' ), is_array( $rows ) ? $rows : array() ) ) ),
			'count' => is_array( $rows ) ? count( $rows ) : 0,
			'mutation_performed' => false,
		);
	}

	public static function get_job( $input ) {
		$job_id = isset( $input['job_id'] ) ? strtolower( trim( (string) $input['job_id'] ) ) : '';
		$row = self::load_job( $job_id, false );
		if ( is_wp_error( $row ) ) return $row;
		return array( 'contract' => self::CONTRACT, 'job' => self::row( $row ), 'mutation_performed' => false );
	}

	public static function get_events( $input ) {
		global $wpdb;
		$job_id = isset( $input['job_id'] ) ? strtolower( trim( (string) $input['job_id'] ) ) : '';
		$job = self::load_job( $job_id, false );
		if ( is_wp_error( $job ) ) return $job;
		$limit = isset( $input['limit'] ) ? max( 1, min( 500, absint( $input['limit'] ) ) ) : 100;
		$t = MAD4B_SCP_Schema::tables();
		$rows = $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$t['content_job_events']} WHERE job_id=%s ORDER BY sequence ASC LIMIT %d", $job_id, $limit ),
			ARRAY_A
		);
		return array(
			'contract' => self::EVENT_CONTRACT,
			'job_id' => $job_id,
			'items' => is_array( $rows ) ? $rows : array(),
			'count' => is_array( $rows ) ? count( $rows ) : 0,
			'mutation_performed' => false,
		);
	}

	public static function create_job( $input ) {
		global $wpdb;
		if ( ! self::schema_ready() ) return new WP_Error( 'mad4b_content_job_schema_unavailable', 'ContentJob schema is not ready.' );
		$site_uuid = self::site_uuid();
		if ( '' === $site_uuid ) return new WP_Error( 'mad4b_content_job_site_identity_unavailable', 'Site Profile identity is unavailable.' );
		$subject = trim( wp_strip_all_tags( (string) $input['subject'] ) );
		if ( '' === $subject || strlen( $subject ) > 5000 ) return new WP_Error( 'mad4b_content_job_subject_invalid', 'ContentJob subject is missing or too long.' );
		$brand_id = sanitize_text_field( (string) $input['brand_id'] );
		$language = sanitize_key( (string) $input['language'] );
		$country = sanitize_key( (string) $input['country'] );
		$content_type = sanitize_key( (string) $input['content_type'] );
		if ( '' === $brand_id || '' === $language || '' === $country || '' === $content_type ) return new WP_Error( 'mad4b_content_job_identity_invalid', 'ContentJob brand/market/type identity is incomplete.' );
		$publish_at = self::normalize_datetime( isset( $input['desired_publish_at'] ) ? $input['desired_publish_at'] : '' );
		if ( is_wp_error( $publish_at ) ) return $publish_at;

		$job_id = strtolower( wp_generate_uuid4() );
		$now = gmdate( 'Y-m-d H:i:s' );
		$actor = self::actor();
		$t = MAD4B_SCP_Schema::tables();
		$data = array(
			'job_id' => $job_id,
			'tenant_id' => '',
			'site_uuid' => $site_uuid,
			'brand_id' => $brand_id,
			'subject' => $subject,
			'primary_keyword' => sanitize_text_field( isset( $input['primary_keyword'] ) ? (string) $input['primary_keyword'] : '' ),
			'language' => $language,
			'country' => $country,
			'content_type' => $content_type,
			'writer_profile_id' => sanitize_text_field( isset( $input['writer_profile_id'] ) ? (string) $input['writer_profile_id'] : '' ),
			'writer_profile_version' => sanitize_text_field( isset( $input['writer_profile_version'] ) ? (string) $input['writer_profile_version'] : '' ),
			'research_depth' => sanitize_key( isset( $input['research_depth'] ) ? (string) $input['research_depth'] : 'standard' ),
			'automation_level' => sanitize_key( isset( $input['automation_level'] ) ? (string) $input['automation_level'] : 'review_gated' ),
			'state' => 'NEW',
			'stage' => 'INTAKE',
			'target_post_type' => sanitize_key( isset( $input['target_post_type'] ) ? (string) $input['target_post_type'] : '' ),
			'target_post_id' => null,
			'desired_publish_at' => $publish_at,
			'current_artifact_id' => '',
			'quality_status' => 'unknown',
			'last_error_code' => '',
			'last_error_summary' => '',
			'job_revision' => 1,
			'created_by_nhi' => $actor['nhi'],
			'created_by_user' => get_current_user_id(),
			'created_at' => $now,
			'updated_at' => $now,
			'completed_at' => null,
			'cancelled_at' => null,
		);

		$wpdb->query( 'START TRANSACTION' );
		try {
			if ( false === $wpdb->insert( $t['content_jobs'], $data ) ) throw new RuntimeException( 'content_job_insert_failed:' . $wpdb->last_error );
			$event = self::append_event_locked( $job_id, 1, 'CREATED', '', 'NEW', '', 'INTAKE', (string) $input['reason'], $actor, '', '' );
			if ( is_wp_error( $event ) ) throw new RuntimeException( $event->get_error_code() );
			$audit = MAD4B_SCP_Audit::record( 'mad4b/content-job-create', array( 'job_id' => $job_id, 'site_uuid' => $site_uuid, 'revision' => 1 ), 'ok', true );
			if ( is_wp_error( $audit ) ) throw new RuntimeException( $audit->get_error_code() );
			if ( false === $wpdb->query( 'COMMIT' ) ) throw new RuntimeException( 'content_job_commit_failed' );
			MAD4B_SCP_Audit::transaction_committed();
		} catch ( Throwable $e ) {
			$wpdb->query( 'ROLLBACK' );
			MAD4B_SCP_Audit::transaction_rolled_back();
			return new WP_Error( 'mad4b_content_job_create_failed', 'Unable to create ContentJob.', array( 'cause' => $e->getMessage() ) );
		}
		return self::get_job( array( 'job_id' => $job_id ) );
	}

	public static function transition_job( $input ) {
		return self::transition(
			isset( $input['job_id'] ) ? $input['job_id'] : '',
			isset( $input['expected_revision'] ) ? $input['expected_revision'] : 0,
			isset( $input['state'] ) ? $input['state'] : '',
			isset( $input['stage'] ) ? $input['stage'] : '',
			isset( $input['reason'] ) ? $input['reason'] : '',
			isset( $input['plan_sha256'] ) ? $input['plan_sha256'] : '',
			isset( $input['artifact_id'] ) ? $input['artifact_id'] : ''
		);
	}

	public static function cancel_job( $input ) {
		$job = self::load_job( isset( $input['job_id'] ) ? $input['job_id'] : '', false );
		if ( is_wp_error( $job ) ) return $job;
		return self::transition(
			$job['job_id'],
			isset( $input['expected_revision'] ) ? $input['expected_revision'] : 0,
			'CANCELLED',
			$job['stage'],
			isset( $input['reason'] ) ? $input['reason'] : '',
			'',
			''
		);
	}

	private static function transition( $job_id, $expected_revision, $new_state, $new_stage, $reason, $plan_sha256, $artifact_id ) {
		global $wpdb;
		if ( ! self::schema_ready() ) return new WP_Error( 'mad4b_content_job_schema_unavailable', 'ContentJob schema is not ready.' );
		$job_id = strtolower( trim( (string) $job_id ) );
		if ( ! self::valid_uuid( $job_id ) ) return new WP_Error( 'mad4b_content_job_id_invalid', 'ContentJob ID is invalid.' );
		$expected_revision = absint( $expected_revision );
		$new_state = strtoupper( sanitize_key( (string) $new_state ) );
		$new_stage = strtoupper( sanitize_key( (string) $new_stage ) );
		$reason = trim( sanitize_text_field( (string) $reason ) );
		$plan_sha256 = strtolower( trim( (string) $plan_sha256 ) );
		$artifact_id = sanitize_text_field( (string) $artifact_id );
		if ( ! in_array( $new_state, self::states(), true ) || ! in_array( $new_stage, self::stages(), true ) ) return new WP_Error( 'mad4b_content_job_transition_invalid', 'Requested state/stage is invalid.' );
		if ( '' !== $plan_sha256 && ! preg_match( '/^[a-f0-9]{64}$/', $plan_sha256 ) ) return new WP_Error( 'mad4b_content_job_plan_invalid', 'Plan SHA is invalid.' );
		if ( strlen( $reason ) < 3 ) return new WP_Error( 'mad4b_content_job_reason_required', 'Transition reason is required.' );

		$t = MAD4B_SCP_Schema::tables();
		$wpdb->query( 'START TRANSACTION' );
		try {
			$row = self::load_job( $job_id, true );
			if ( is_wp_error( $row ) ) throw new RuntimeException( $row->get_error_code() );
			if ( $expected_revision !== (int) $row['job_revision'] ) throw new RuntimeException( 'content_job_revision_conflict' );
			if ( in_array( $row['state'], array( 'COMPLETED', 'CANCELLED' ), true ) ) throw new RuntimeException( 'content_job_terminal_immutable' );
			$same_state = hash_equals( (string) $row['state'], $new_state );
			$same_stage = hash_equals( (string) $row['stage'], $new_stage );
			if ( $same_state && $same_stage ) throw new RuntimeException( 'content_job_noop_transition' );
			$allowed = self::transition_map();
			if ( ! $same_state && ( ! isset( $allowed[ $row['state'] ] ) || ! in_array( $new_state, $allowed[ $row['state'] ], true ) ) ) {
				throw new RuntimeException( 'content_job_state_transition_denied' );
			}

			$revision = $expected_revision + 1;
			$now = gmdate( 'Y-m-d H:i:s' );
			$update = array(
				'state' => $new_state,
				'stage' => $new_stage,
				'current_artifact_id' => '' !== $artifact_id ? $artifact_id : (string) $row['current_artifact_id'],
				'job_revision' => $revision,
				'updated_at' => $now,
				'completed_at' => 'COMPLETED' === $new_state ? $now : $row['completed_at'],
				'cancelled_at' => 'CANCELLED' === $new_state ? $now : $row['cancelled_at'],
			);
			$changed = $wpdb->update(
				$t['content_jobs'],
				$update,
				array( 'id' => (int) $row['id'], 'job_revision' => $expected_revision ),
				null,
				array( '%d', '%d' )
			);
			if ( 1 !== (int) $changed ) throw new RuntimeException( 'content_job_transition_cas_failed' );
			$actor = self::actor();
			$event = self::append_event_locked(
				$job_id,
				$revision,
				'CANCELLED' === $new_state ? 'CANCELLED' : 'TRANSITIONED',
				(string) $row['state'],
				$new_state,
				(string) $row['stage'],
				$new_stage,
				$reason,
				$actor,
				$plan_sha256,
				$artifact_id
			);
			if ( is_wp_error( $event ) ) throw new RuntimeException( $event->get_error_code() );
			$audit = MAD4B_SCP_Audit::record( 'mad4b/content-job-transition', array( 'job_id' => $job_id, 'from_revision' => $expected_revision, 'to_revision' => $revision, 'state' => $new_state, 'stage' => $new_stage ), 'ok', true );
			if ( is_wp_error( $audit ) ) throw new RuntimeException( $audit->get_error_code() );
			if ( false === $wpdb->query( 'COMMIT' ) ) throw new RuntimeException( 'content_job_transition_commit_failed' );
			MAD4B_SCP_Audit::transaction_committed();
		} catch ( Throwable $e ) {
			$wpdb->query( 'ROLLBACK' );
			MAD4B_SCP_Audit::transaction_rolled_back();
			$code = $e->getMessage();
			if ( 'content_job_revision_conflict' === $code ) return new WP_Error( 'mad4b_content_job_revision_conflict', 'ContentJob revision changed since the requested transition.' );
			if ( 'content_job_terminal_immutable' === $code ) return new WP_Error( 'mad4b_content_job_terminal_immutable', 'Completed or cancelled ContentJob history is immutable.' );
			if ( 'content_job_state_transition_denied' === $code ) return new WP_Error( 'mad4b_content_job_state_transition_denied', 'Requested lifecycle transition is not allowed.' );
			if ( 'content_job_noop_transition' === $code ) return new WP_Error( 'mad4b_content_job_noop_transition', 'State and stage are unchanged.' );
			return new WP_Error( 'mad4b_content_job_transition_failed', 'Unable to transition ContentJob.', array( 'cause' => $code ) );
		}
		return self::get_job( array( 'job_id' => $job_id ) );
	}

	private static function load_job( $job_id, $for_update ) {
		global $wpdb;
		if ( ! self::valid_uuid( $job_id ) ) return new WP_Error( 'mad4b_content_job_id_invalid', 'ContentJob ID is invalid.' );
		$site_uuid = self::site_uuid();
		if ( '' === $site_uuid ) return new WP_Error( 'mad4b_content_job_site_identity_unavailable', 'Site Profile identity is unavailable.' );
		$t = MAD4B_SCP_Schema::tables();
		$sql = $wpdb->prepare( "SELECT * FROM {$t['content_jobs']} WHERE job_id=%s AND site_uuid=%s LIMIT 1" . ( $for_update ? ' FOR UPDATE' : '' ), $job_id, $site_uuid );
		$row = $wpdb->get_row( $sql, ARRAY_A );
		return is_array( $row ) ? $row : new WP_Error( 'mad4b_content_job_missing', 'ContentJob was not found for this site.' );
	}

	private static function append_event_locked( $job_id, $sequence, $event_type, $previous_state, $new_state, $previous_stage, $new_stage, $reason, array $actor, $plan_sha256, $artifact_id ) {
		global $wpdb;
		$t = MAD4B_SCP_Schema::tables();
		$previous_hash = (string) $wpdb->get_var(
			$wpdb->prepare( "SELECT entry_sha256 FROM {$t['content_job_events']} WHERE job_id=%s ORDER BY sequence DESC LIMIT 1", $job_id )
		);
		$event_id = strtolower( wp_generate_uuid4() );
		$now = gmdate( 'Y-m-d H:i:s' );
		$material = array(
			'contract' => self::EVENT_CONTRACT,
			'event_id' => $event_id,
			'job_id' => $job_id,
			'sequence' => (int) $sequence,
			'event_type' => sanitize_key( (string) $event_type ),
			'previous_state' => (string) $previous_state,
			'new_state' => (string) $new_state,
			'previous_stage' => (string) $previous_stage,
			'new_stage' => (string) $new_stage,
			'reason_code' => substr( sanitize_key( (string) $reason ), 0, 64 ),
			'actor_type' => sanitize_key( (string) $actor['type'] ),
			'actor_id' => substr( sanitize_text_field( (string) $actor['id'] ), 0, 191 ),
			'plan_sha256' => (string) $plan_sha256,
			'artifact_id' => substr( sanitize_text_field( (string) $artifact_id ), 0, 191 ),
			'provider_id' => '',
			'previous_entry_sha256' => $previous_hash,
			'created_at' => $now,
		);
		$entry_hash = hash( 'sha256', self::stable_json( $material ) );
		$ok = $wpdb->insert(
			$t['content_job_events'],
			array(
				'event_id' => $event_id,
				'job_id' => $job_id,
				'sequence' => (int) $sequence,
				'event_type' => $material['event_type'],
				'previous_state' => $previous_state,
				'new_state' => $new_state,
				'previous_stage' => $previous_stage,
				'new_stage' => $new_stage,
				'reason_code' => $material['reason_code'],
				'correlation_id' => '',
				'actor_type' => $material['actor_type'],
				'actor_id' => $material['actor_id'],
				'plan_sha256' => $plan_sha256,
				'artifact_id' => $artifact_id,
				'provider_id' => '',
				'metadata_json' => wp_json_encode( array( 'reason' => $reason ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ),
				'previous_entry_sha256' => $previous_hash,
				'entry_sha256' => $entry_hash,
				'created_at' => $now,
			)
		);
		if ( false === $ok ) return new WP_Error( 'mad4b_content_job_event_insert_failed', 'Unable to append ContentJob event.', array( 'db_error' => $wpdb->last_error ) );
		return array( 'event_id' => $event_id, 'entry_sha256' => $entry_hash, 'sequence' => (int) $sequence );
	}

	private static function stable_json( $value ) {
		$value = self::sort_value( $value );
		$json = wp_json_encode( $value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		return false === $json ? '' : $json;
	}

	private static function sort_value( $value ) {
		if ( ! is_array( $value ) ) return $value;
		$is_list = array_keys( $value ) === range( 0, count( $value ) - 1 );
		if ( ! $is_list ) ksort( $value, SORT_STRING );
		foreach ( $value as $key => $item ) $value[ $key ] = self::sort_value( $item );
		return $value;
	}
}

MAD4B_SCP_Content_Jobs::boot();
, 'maxLength' => 64, 'default' => '' ),
				'research_depth' => array( 'type' => 'string', 'maxLength' => 32, 'default' => 'standard' ),
				'automation_level' => array( 'type' => 'string', 'maxLength' => 32, 'default' => 'review_gated' ),
				'target_post_type' => array( 'type' => 'string', 'maxLength' => 64, 'default' => '' ),
				'desired_publish_at' => array( 'type' => 'string', 'maxLength' => 32, 'default' => '' ),
				'reason' => array( 'type' => 'string', 'minLength' => 3, 'maxLength' => 500 ),
			),
			array( 'brand_id', 'subject', 'language', 'country', 'content_type', 'reason' ),
			false
		);
		self::register(
			'mad4b/content-job-transition',
			'Transition Content Job',
			'transition_job',
			array(
				'job_id' => array( 'type' => 'string', 'minLength' => 36, 'maxLength' => 36 ),
				'expected_revision' => array( 'type' => 'integer', 'minimum' => 1 ),
				'state' => array( 'type' => 'string', 'enum' => self::states() ),
				'stage' => array( 'type' => 'string', 'enum' => self::stages() ),
				'reason' => array( 'type' => 'string', 'minLength' => 3, 'maxLength' => 500 ),
				'plan_sha256' => array( 'type' => 'string', 'pattern' => '^[a-f0-9]{64}$', 'default' => '' ),
				'artifact_id' => array( 'type' => 'string', 'maxLength' => 191, 'default' => '' ),
			),
			array( 'job_id', 'expected_revision', 'state', 'stage', 'reason' ),
			false
		);
		self::register(
			'mad4b/content-job-cancel',
			'Cancel Content Job',
			'cancel_job',
			array(
				'job_id' => array( 'type' => 'string', 'minLength' => 36, 'maxLength' => 36 ),
				'expected_revision' => array( 'type' => 'integer', 'minimum' => 1 ),
				'reason' => array( 'type' => 'string', 'minLength' => 3, 'maxLength' => 500 ),
			),
			array( 'job_id', 'expected_revision', 'reason' ),
			false
		);
	}

	private static function register( $name, $label, $method, array $properties, array $required, $readonly ) {
		if ( function_exists( 'wp_has_ability' ) && wp_has_ability( $name ) ) return;
		wp_register_ability(
			$name,
			array(
				'label' => $label,
				'description' => $label . ' through the governed Feature 007 ContentJob aggregate.',
				'category' => $readonly ? 'mad4b-read' : 'mad4b-write',
				'execute_callback' => array( __CLASS__, $method ),
				'permission_callback' => $readonly ? array( 'MAD4B_SCP_Policy', 'can_read' ) : array( 'MAD4B_SCP_Policy', 'can_mutate' ),
				'input_schema' => array(
					'type' => 'object',
					'properties' => $properties,
					'required' => $required,
					'additionalProperties' => false,
				),
				'output_schema' => array( 'type' => 'object', 'additionalProperties' => true ),
				'meta' => array(
					'public' => false,
					'show_in_rest' => false,
					'mcp' => array( 'public' => false, 'type' => 'tool', 'surface' => $readonly ? 'read' : 'write' ),
					'annotations' => array(
						'readonly' => (bool) $readonly,
						'destructive' => ! $readonly,
						'idempotent' => $readonly,
					),
				),
			)
		);
	}

	private static function schema_ready() {
		return class_exists( 'MAD4B_SCP_Schema' ) && MAD4B_SCP_Schema::critical_ready();
	}

	private static function site_uuid() {
		$uuid = class_exists( 'MAD4B_SCP_Site_Profile' ) ? strtolower( trim( (string) MAD4B_SCP_Site_Profile::site_uuid() ) ) : '';
		return preg_match( '/^[a-f0-9-]{36}$/', $uuid ) ? $uuid : '';
	}

	private static function valid_uuid( $value ) {
		return is_string( $value ) && 1 === preg_match( '/^[a-f0-9-]{36}$/', strtolower( trim( $value ) ) );
	}

	private static function actor() {
		$actor = array( 'type' => 'wordpress_user', 'id' => (string) get_current_user_id(), 'nhi' => '' );
		if ( class_exists( 'MAD4B_SCP_Identity_Context' ) && class_exists( 'MAD4B_SCP_Agent_Registry' ) ) {
			$identity = MAD4B_SCP_Identity_Context::current();
			if ( ! is_wp_error( $identity ) ) {
				$agent = MAD4B_SCP_Agent_Registry::resolve_agent( $identity );
				if ( ! is_wp_error( $agent ) && ! empty( $agent['public_id'] ) ) {
					$actor['type'] = 'nhi';
					$actor['id'] = strtolower( (string) $agent['public_id'] );
					$actor['nhi'] = $actor['id'];
				}
			}
		}
		return $actor;
	}

	private static function normalize_datetime( $value ) {
		$value = trim( (string) $value );
		if ( '' === $value ) return null;
		$ts = strtotime( $value );
		if ( false === $ts ) return new WP_Error( 'mad4b_content_job_datetime_invalid', 'Invalid desired publish datetime.' );
		return gmdate( 'Y-m-d H:i:s', $ts );
	}

	private static function row( $row ) {
		if ( ! is_array( $row ) ) return null;
		foreach ( array( 'id', 'target_post_id', 'job_revision', 'created_by_user' ) as $key ) {
			if ( array_key_exists( $key, $row ) && null !== $row[ $key ] ) $row[ $key ] = (int) $row[ $key ];
		}
		return $row;
	}

	public static function list_jobs( $input ) {
		global $wpdb;
		if ( ! self::schema_ready() ) return new WP_Error( 'mad4b_content_job_schema_unavailable', 'ContentJob schema is not ready.' );
		$site_uuid = self::site_uuid();
		if ( '' === $site_uuid ) return new WP_Error( 'mad4b_content_job_site_identity_unavailable', 'Site Profile identity is unavailable.' );
		$t = MAD4B_SCP_Schema::tables();
		$where = array( 'site_uuid=%s' );
		$args = array( $site_uuid );
		$state = isset( $input['state'] ) ? strtoupper( sanitize_key( (string) $input['state'] ) ) : '';
		$stage = isset( $input['stage'] ) ? strtoupper( sanitize_key( (string) $input['stage'] ) ) : '';
		if ( '' !== $state ) { $where[] = 'state=%s'; $args[] = $state; }
		if ( '' !== $stage ) { $where[] = 'stage=%s'; $args[] = $stage; }
		$limit = isset( $input['limit'] ) ? max( 1, min( 200, absint( $input['limit'] ) ) ) : 50;
		$args[] = $limit;
		$sql = "SELECT * FROM {$t['content_jobs']} WHERE " . implode( ' AND ', $where ) . ' ORDER BY updated_at DESC,id DESC LIMIT %d';
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $args ), ARRAY_A );
		return array(
			'contract' => self::CONTRACT,
			'items' => array_values( array_filter( array_map( array( __CLASS__, 'row' ), is_array( $rows ) ? $rows : array() ) ) ),
			'count' => is_array( $rows ) ? count( $rows ) : 0,
			'mutation_performed' => false,
		);
	}

	public static function get_job( $input ) {
		$job_id = isset( $input['job_id'] ) ? strtolower( trim( (string) $input['job_id'] ) ) : '';
		$row = self::load_job( $job_id, false );
		if ( is_wp_error( $row ) ) return $row;
		return array( 'contract' => self::CONTRACT, 'job' => self::row( $row ), 'mutation_performed' => false );
	}

	public static function get_events( $input ) {
		global $wpdb;
		$job_id = isset( $input['job_id'] ) ? strtolower( trim( (string) $input['job_id'] ) ) : '';
		$job = self::load_job( $job_id, false );
		if ( is_wp_error( $job ) ) return $job;
		$limit = isset( $input['limit'] ) ? max( 1, min( 500, absint( $input['limit'] ) ) ) : 100;
		$t = MAD4B_SCP_Schema::tables();
		$rows = $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$t['content_job_events']} WHERE job_id=%s ORDER BY sequence ASC LIMIT %d", $job_id, $limit ),
			ARRAY_A
		);
		return array(
			'contract' => self::EVENT_CONTRACT,
			'job_id' => $job_id,
			'items' => is_array( $rows ) ? $rows : array(),
			'count' => is_array( $rows ) ? count( $rows ) : 0,
			'mutation_performed' => false,
		);
	}

	public static function create_job( $input ) {
		global $wpdb;
		if ( ! self::schema_ready() ) return new WP_Error( 'mad4b_content_job_schema_unavailable', 'ContentJob schema is not ready.' );
		$site_uuid = self::site_uuid();
		if ( '' === $site_uuid ) return new WP_Error( 'mad4b_content_job_site_identity_unavailable', 'Site Profile identity is unavailable.' );
		$subject = trim( wp_strip_all_tags( (string) $input['subject'] ) );
		if ( '' === $subject || strlen( $subject ) > 5000 ) return new WP_Error( 'mad4b_content_job_subject_invalid', 'ContentJob subject is missing or too long.' );
		$brand_id = sanitize_text_field( (string) $input['brand_id'] );
		$language = sanitize_key( (string) $input['language'] );
		$country = sanitize_key( (string) $input['country'] );
		$content_type = sanitize_key( (string) $input['content_type'] );
		if ( '' === $brand_id || '' === $language || '' === $country || '' === $content_type ) return new WP_Error( 'mad4b_content_job_identity_invalid', 'ContentJob brand/market/type identity is incomplete.' );
		$publish_at = self::normalize_datetime( isset( $input['desired_publish_at'] ) ? $input['desired_publish_at'] : '' );
		if ( is_wp_error( $publish_at ) ) return $publish_at;

		$job_id = strtolower( wp_generate_uuid4() );
		$now = gmdate( 'Y-m-d H:i:s' );
		$actor = self::actor();
		$t = MAD4B_SCP_Schema::tables();
		$data = array(
			'job_id' => $job_id,
			'tenant_id' => '',
			'site_uuid' => $site_uuid,
			'brand_id' => $brand_id,
			'subject' => $subject,
			'primary_keyword' => sanitize_text_field( isset( $input['primary_keyword'] ) ? (string) $input['primary_keyword'] : '' ),
			'language' => $language,
			'country' => $country,
			'content_type' => $content_type,
			'writer_profile_id' => sanitize_text_field( isset( $input['writer_profile_id'] ) ? (string) $input['writer_profile_id'] : '' ),
			'writer_profile_version' => sanitize_text_field( isset( $input['writer_profile_version'] ) ? (string) $input['writer_profile_version'] : '' ),
			'research_depth' => sanitize_key( isset( $input['research_depth'] ) ? (string) $input['research_depth'] : 'standard' ),
			'automation_level' => sanitize_key( isset( $input['automation_level'] ) ? (string) $input['automation_level'] : 'review_gated' ),
			'state' => 'NEW',
			'stage' => 'INTAKE',
			'target_post_type' => sanitize_key( isset( $input['target_post_type'] ) ? (string) $input['target_post_type'] : '' ),
			'target_post_id' => null,
			'desired_publish_at' => $publish_at,
			'current_artifact_id' => '',
			'quality_status' => 'unknown',
			'last_error_code' => '',
			'last_error_summary' => '',
			'job_revision' => 1,
			'created_by_nhi' => $actor['nhi'],
			'created_by_user' => get_current_user_id(),
			'created_at' => $now,
			'updated_at' => $now,
			'completed_at' => null,
			'cancelled_at' => null,
		);

		$wpdb->query( 'START TRANSACTION' );
		try {
			if ( false === $wpdb->insert( $t['content_jobs'], $data ) ) throw new RuntimeException( 'content_job_insert_failed:' . $wpdb->last_error );
			$event = self::append_event_locked( $job_id, 1, 'CREATED', '', 'NEW', '', 'INTAKE', (string) $input['reason'], $actor, '', '' );
			if ( is_wp_error( $event ) ) throw new RuntimeException( $event->get_error_code() );
			$audit = MAD4B_SCP_Audit::record( 'mad4b/content-job-create', array( 'job_id' => $job_id, 'site_uuid' => $site_uuid, 'revision' => 1 ), 'ok', true );
			if ( is_wp_error( $audit ) ) throw new RuntimeException( $audit->get_error_code() );
			if ( false === $wpdb->query( 'COMMIT' ) ) throw new RuntimeException( 'content_job_commit_failed' );
			MAD4B_SCP_Audit::transaction_committed();
		} catch ( Throwable $e ) {
			$wpdb->query( 'ROLLBACK' );
			MAD4B_SCP_Audit::transaction_rolled_back();
			return new WP_Error( 'mad4b_content_job_create_failed', 'Unable to create ContentJob.', array( 'cause' => $e->getMessage() ) );
		}
		return self::get_job( array( 'job_id' => $job_id ) );
	}

	public static function transition_job( $input ) {
		return self::transition(
			isset( $input['job_id'] ) ? $input['job_id'] : '',
			isset( $input['expected_revision'] ) ? $input['expected_revision'] : 0,
			isset( $input['state'] ) ? $input['state'] : '',
			isset( $input['stage'] ) ? $input['stage'] : '',
			isset( $input['reason'] ) ? $input['reason'] : '',
			isset( $input['plan_sha256'] ) ? $input['plan_sha256'] : '',
			isset( $input['artifact_id'] ) ? $input['artifact_id'] : ''
		);
	}

	public static function cancel_job( $input ) {
		$job = self::load_job( isset( $input['job_id'] ) ? $input['job_id'] : '', false );
		if ( is_wp_error( $job ) ) return $job;
		return self::transition(
			$job['job_id'],
			isset( $input['expected_revision'] ) ? $input['expected_revision'] : 0,
			'CANCELLED',
			$job['stage'],
			isset( $input['reason'] ) ? $input['reason'] : '',
			'',
			''
		);
	}

	private static function transition( $job_id, $expected_revision, $new_state, $new_stage, $reason, $plan_sha256, $artifact_id ) {
		global $wpdb;
		if ( ! self::schema_ready() ) return new WP_Error( 'mad4b_content_job_schema_unavailable', 'ContentJob schema is not ready.' );
		$job_id = strtolower( trim( (string) $job_id ) );
		if ( ! self::valid_uuid( $job_id ) ) return new WP_Error( 'mad4b_content_job_id_invalid', 'ContentJob ID is invalid.' );
		$expected_revision = absint( $expected_revision );
		$new_state = strtoupper( sanitize_key( (string) $new_state ) );
		$new_stage = strtoupper( sanitize_key( (string) $new_stage ) );
		$reason = trim( sanitize_text_field( (string) $reason ) );
		$plan_sha256 = strtolower( trim( (string) $plan_sha256 ) );
		$artifact_id = sanitize_text_field( (string) $artifact_id );
		if ( ! in_array( $new_state, self::states(), true ) || ! in_array( $new_stage, self::stages(), true ) ) return new WP_Error( 'mad4b_content_job_transition_invalid', 'Requested state/stage is invalid.' );
		if ( '' !== $plan_sha256 && ! preg_match( '/^[a-f0-9]{64}$/', $plan_sha256 ) ) return new WP_Error( 'mad4b_content_job_plan_invalid', 'Plan SHA is invalid.' );
		if ( strlen( $reason ) < 3 ) return new WP_Error( 'mad4b_content_job_reason_required', 'Transition reason is required.' );

		$t = MAD4B_SCP_Schema::tables();
		$wpdb->query( 'START TRANSACTION' );
		try {
			$row = self::load_job( $job_id, true );
			if ( is_wp_error( $row ) ) throw new RuntimeException( $row->get_error_code() );
			if ( $expected_revision !== (int) $row['job_revision'] ) throw new RuntimeException( 'content_job_revision_conflict' );
			if ( in_array( $row['state'], array( 'COMPLETED', 'CANCELLED' ), true ) ) throw new RuntimeException( 'content_job_terminal_immutable' );
			$same_state = hash_equals( (string) $row['state'], $new_state );
			$same_stage = hash_equals( (string) $row['stage'], $new_stage );
			if ( $same_state && $same_stage ) throw new RuntimeException( 'content_job_noop_transition' );
			$allowed = self::transition_map();
			if ( ! $same_state && ( ! isset( $allowed[ $row['state'] ] ) || ! in_array( $new_state, $allowed[ $row['state'] ], true ) ) ) {
				throw new RuntimeException( 'content_job_state_transition_denied' );
			}

			$revision = $expected_revision + 1;
			$now = gmdate( 'Y-m-d H:i:s' );
			$update = array(
				'state' => $new_state,
				'stage' => $new_stage,
				'current_artifact_id' => '' !== $artifact_id ? $artifact_id : (string) $row['current_artifact_id'],
				'job_revision' => $revision,
				'updated_at' => $now,
				'completed_at' => 'COMPLETED' === $new_state ? $now : $row['completed_at'],
				'cancelled_at' => 'CANCELLED' === $new_state ? $now : $row['cancelled_at'],
			);
			$changed = $wpdb->update(
				$t['content_jobs'],
				$update,
				array( 'id' => (int) $row['id'], 'job_revision' => $expected_revision ),
				null,
				array( '%d', '%d' )
			);
			if ( 1 !== (int) $changed ) throw new RuntimeException( 'content_job_transition_cas_failed' );
			$actor = self::actor();
			$event = self::append_event_locked(
				$job_id,
				$revision,
				'CANCELLED' === $new_state ? 'CANCELLED' : 'TRANSITIONED',
				(string) $row['state'],
				$new_state,
				(string) $row['stage'],
				$new_stage,
				$reason,
				$actor,
				$plan_sha256,
				$artifact_id
			);
			if ( is_wp_error( $event ) ) throw new RuntimeException( $event->get_error_code() );
			$audit = MAD4B_SCP_Audit::record( 'mad4b/content-job-transition', array( 'job_id' => $job_id, 'from_revision' => $expected_revision, 'to_revision' => $revision, 'state' => $new_state, 'stage' => $new_stage ), 'ok', true );
			if ( is_wp_error( $audit ) ) throw new RuntimeException( $audit->get_error_code() );
			if ( false === $wpdb->query( 'COMMIT' ) ) throw new RuntimeException( 'content_job_transition_commit_failed' );
			MAD4B_SCP_Audit::transaction_committed();
		} catch ( Throwable $e ) {
			$wpdb->query( 'ROLLBACK' );
			MAD4B_SCP_Audit::transaction_rolled_back();
			$code = $e->getMessage();
			if ( 'content_job_revision_conflict' === $code ) return new WP_Error( 'mad4b_content_job_revision_conflict', 'ContentJob revision changed since the requested transition.' );
			if ( 'content_job_terminal_immutable' === $code ) return new WP_Error( 'mad4b_content_job_terminal_immutable', 'Completed or cancelled ContentJob history is immutable.' );
			if ( 'content_job_state_transition_denied' === $code ) return new WP_Error( 'mad4b_content_job_state_transition_denied', 'Requested lifecycle transition is not allowed.' );
			if ( 'content_job_noop_transition' === $code ) return new WP_Error( 'mad4b_content_job_noop_transition', 'State and stage are unchanged.' );
			return new WP_Error( 'mad4b_content_job_transition_failed', 'Unable to transition ContentJob.', array( 'cause' => $code ) );
		}
		return self::get_job( array( 'job_id' => $job_id ) );
	}

	private static function load_job( $job_id, $for_update ) {
		global $wpdb;
		if ( ! self::valid_uuid( $job_id ) ) return new WP_Error( 'mad4b_content_job_id_invalid', 'ContentJob ID is invalid.' );
		$site_uuid = self::site_uuid();
		if ( '' === $site_uuid ) return new WP_Error( 'mad4b_content_job_site_identity_unavailable', 'Site Profile identity is unavailable.' );
		$t = MAD4B_SCP_Schema::tables();
		$sql = $wpdb->prepare( "SELECT * FROM {$t['content_jobs']} WHERE job_id=%s AND site_uuid=%s LIMIT 1" . ( $for_update ? ' FOR UPDATE' : '' ), $job_id, $site_uuid );
		$row = $wpdb->get_row( $sql, ARRAY_A );
		return is_array( $row ) ? $row : new WP_Error( 'mad4b_content_job_missing', 'ContentJob was not found for this site.' );
	}

	private static function append_event_locked( $job_id, $sequence, $event_type, $previous_state, $new_state, $previous_stage, $new_stage, $reason, array $actor, $plan_sha256, $artifact_id ) {
		global $wpdb;
		$t = MAD4B_SCP_Schema::tables();
		$previous_hash = (string) $wpdb->get_var(
			$wpdb->prepare( "SELECT entry_sha256 FROM {$t['content_job_events']} WHERE job_id=%s ORDER BY sequence DESC LIMIT 1", $job_id )
		);
		$event_id = strtolower( wp_generate_uuid4() );
		$now = gmdate( 'Y-m-d H:i:s' );
		$material = array(
			'contract' => self::EVENT_CONTRACT,
			'event_id' => $event_id,
			'job_id' => $job_id,
			'sequence' => (int) $sequence,
			'event_type' => sanitize_key( (string) $event_type ),
			'previous_state' => (string) $previous_state,
			'new_state' => (string) $new_state,
			'previous_stage' => (string) $previous_stage,
			'new_stage' => (string) $new_stage,
			'reason_code' => substr( sanitize_key( (string) $reason ), 0, 64 ),
			'actor_type' => sanitize_key( (string) $actor['type'] ),
			'actor_id' => substr( sanitize_text_field( (string) $actor['id'] ), 0, 191 ),
			'plan_sha256' => (string) $plan_sha256,
			'artifact_id' => substr( sanitize_text_field( (string) $artifact_id ), 0, 191 ),
			'provider_id' => '',
			'previous_entry_sha256' => $previous_hash,
			'created_at' => $now,
		);
		$entry_hash = hash( 'sha256', self::stable_json( $material ) );
		$ok = $wpdb->insert(
			$t['content_job_events'],
			array(
				'event_id' => $event_id,
				'job_id' => $job_id,
				'sequence' => (int) $sequence,
				'event_type' => $material['event_type'],
				'previous_state' => $previous_state,
				'new_state' => $new_state,
				'previous_stage' => $previous_stage,
				'new_stage' => $new_stage,
				'reason_code' => $material['reason_code'],
				'correlation_id' => '',
				'actor_type' => $material['actor_type'],
				'actor_id' => $material['actor_id'],
				'plan_sha256' => $plan_sha256,
				'artifact_id' => $artifact_id,
				'provider_id' => '',
				'metadata_json' => wp_json_encode( array( 'reason' => $reason ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ),
				'previous_entry_sha256' => $previous_hash,
				'entry_sha256' => $entry_hash,
				'created_at' => $now,
			)
		);
		if ( false === $ok ) return new WP_Error( 'mad4b_content_job_event_insert_failed', 'Unable to append ContentJob event.', array( 'db_error' => $wpdb->last_error ) );
		return array( 'event_id' => $event_id, 'entry_sha256' => $entry_hash, 'sequence' => (int) $sequence );
	}

	private static function stable_json( $value ) {
		$value = self::sort_value( $value );
		$json = wp_json_encode( $value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		return false === $json ? '' : $json;
	}

	private static function sort_value( $value ) {
		if ( ! is_array( $value ) ) return $value;
		$is_list = array_keys( $value ) === range( 0, count( $value ) - 1 );
		if ( ! $is_list ) ksort( $value, SORT_STRING );
		foreach ( $value as $key => $item ) $value[ $key ] = self::sort_value( $item );
		return $value;
	}
}

MAD4B_SCP_Content_Jobs::boot();
, 'default' => '' ),
				'writer_profile_version' => array( 'type' => 'string', 'pattern' => '^(|[a-f0-9]{64}) => array( 'type' => 'string', 'maxLength' => 32, 'default' => 'standard' ),
				'automation_level' => array( 'type' => 'string', 'maxLength' => 32, 'default' => 'review_gated' ),
				'target_post_type' => array( 'type' => 'string', 'maxLength' => 64, 'default' => '' ),
				'desired_publish_at' => array( 'type' => 'string', 'maxLength' => 32, 'default' => '' ),
				'reason' => array( 'type' => 'string', 'minLength' => 3, 'maxLength' => 500 ),
			),
			array( 'brand_id', 'subject', 'language', 'country', 'content_type', 'reason' ),
			false
		);
		self::register(
			'mad4b/content-job-transition',
			'Transition Content Job',
			'transition_job',
			array(
				'job_id' => array( 'type' => 'string', 'minLength' => 36, 'maxLength' => 36 ),
				'expected_revision' => array( 'type' => 'integer', 'minimum' => 1 ),
				'state' => array( 'type' => 'string', 'enum' => self::states() ),
				'stage' => array( 'type' => 'string', 'enum' => self::stages() ),
				'reason' => array( 'type' => 'string', 'minLength' => 3, 'maxLength' => 500 ),
				'plan_sha256' => array( 'type' => 'string', 'pattern' => '^[a-f0-9]{64}$', 'default' => '' ),
				'artifact_id' => array( 'type' => 'string', 'maxLength' => 191, 'default' => '' ),
			),
			array( 'job_id', 'expected_revision', 'state', 'stage', 'reason' ),
			false
		);
		self::register(
			'mad4b/content-job-cancel',
			'Cancel Content Job',
			'cancel_job',
			array(
				'job_id' => array( 'type' => 'string', 'minLength' => 36, 'maxLength' => 36 ),
				'expected_revision' => array( 'type' => 'integer', 'minimum' => 1 ),
				'reason' => array( 'type' => 'string', 'minLength' => 3, 'maxLength' => 500 ),
			),
			array( 'job_id', 'expected_revision', 'reason' ),
			false
		);
	}

	private static function register( $name, $label, $method, array $properties, array $required, $readonly ) {
		if ( function_exists( 'wp_has_ability' ) && wp_has_ability( $name ) ) return;
		wp_register_ability(
			$name,
			array(
				'label' => $label,
				'description' => $label . ' through the governed Feature 007 ContentJob aggregate.',
				'category' => $readonly ? 'mad4b-read' : 'mad4b-write',
				'execute_callback' => array( __CLASS__, $method ),
				'permission_callback' => $readonly ? array( 'MAD4B_SCP_Policy', 'can_read' ) : array( 'MAD4B_SCP_Policy', 'can_mutate' ),
				'input_schema' => array(
					'type' => 'object',
					'properties' => $properties,
					'required' => $required,
					'additionalProperties' => false,
				),
				'output_schema' => array( 'type' => 'object', 'additionalProperties' => true ),
				'meta' => array(
					'public' => false,
					'show_in_rest' => false,
					'mcp' => array( 'public' => false, 'type' => 'tool', 'surface' => $readonly ? 'read' : 'write' ),
					'annotations' => array(
						'readonly' => (bool) $readonly,
						'destructive' => ! $readonly,
						'idempotent' => $readonly,
					),
				),
			)
		);
	}

	private static function schema_ready() {
		return class_exists( 'MAD4B_SCP_Schema' ) && MAD4B_SCP_Schema::critical_ready();
	}

	private static function site_uuid() {
		$uuid = class_exists( 'MAD4B_SCP_Site_Profile' ) ? strtolower( trim( (string) MAD4B_SCP_Site_Profile::site_uuid() ) ) : '';
		return preg_match( '/^[a-f0-9-]{36}$/', $uuid ) ? $uuid : '';
	}

	private static function valid_uuid( $value ) {
		return is_string( $value ) && 1 === preg_match( '/^[a-f0-9-]{36}$/', strtolower( trim( $value ) ) );
	}

	private static function actor() {
		$actor = array( 'type' => 'wordpress_user', 'id' => (string) get_current_user_id(), 'nhi' => '' );
		if ( class_exists( 'MAD4B_SCP_Identity_Context' ) && class_exists( 'MAD4B_SCP_Agent_Registry' ) ) {
			$identity = MAD4B_SCP_Identity_Context::current();
			if ( ! is_wp_error( $identity ) ) {
				$agent = MAD4B_SCP_Agent_Registry::resolve_agent( $identity );
				if ( ! is_wp_error( $agent ) && ! empty( $agent['public_id'] ) ) {
					$actor['type'] = 'nhi';
					$actor['id'] = strtolower( (string) $agent['public_id'] );
					$actor['nhi'] = $actor['id'];
				}
			}
		}
		return $actor;
	}

	private static function normalize_datetime( $value ) {
		$value = trim( (string) $value );
		if ( '' === $value ) return null;
		$ts = strtotime( $value );
		if ( false === $ts ) return new WP_Error( 'mad4b_content_job_datetime_invalid', 'Invalid desired publish datetime.' );
		return gmdate( 'Y-m-d H:i:s', $ts );
	}

	private static function row( $row ) {
		if ( ! is_array( $row ) ) return null;
		foreach ( array( 'id', 'target_post_id', 'job_revision', 'created_by_user' ) as $key ) {
			if ( array_key_exists( $key, $row ) && null !== $row[ $key ] ) $row[ $key ] = (int) $row[ $key ];
		}
		return $row;
	}

	public static function list_jobs( $input ) {
		global $wpdb;
		if ( ! self::schema_ready() ) return new WP_Error( 'mad4b_content_job_schema_unavailable', 'ContentJob schema is not ready.' );
		$site_uuid = self::site_uuid();
		if ( '' === $site_uuid ) return new WP_Error( 'mad4b_content_job_site_identity_unavailable', 'Site Profile identity is unavailable.' );
		$t = MAD4B_SCP_Schema::tables();
		$where = array( 'site_uuid=%s' );
		$args = array( $site_uuid );
		$state = isset( $input['state'] ) ? strtoupper( sanitize_key( (string) $input['state'] ) ) : '';
		$stage = isset( $input['stage'] ) ? strtoupper( sanitize_key( (string) $input['stage'] ) ) : '';
		if ( '' !== $state ) { $where[] = 'state=%s'; $args[] = $state; }
		if ( '' !== $stage ) { $where[] = 'stage=%s'; $args[] = $stage; }
		$limit = isset( $input['limit'] ) ? max( 1, min( 200, absint( $input['limit'] ) ) ) : 50;
		$args[] = $limit;
		$sql = "SELECT * FROM {$t['content_jobs']} WHERE " . implode( ' AND ', $where ) . ' ORDER BY updated_at DESC,id DESC LIMIT %d';
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $args ), ARRAY_A );
		return array(
			'contract' => self::CONTRACT,
			'items' => array_values( array_filter( array_map( array( __CLASS__, 'row' ), is_array( $rows ) ? $rows : array() ) ) ),
			'count' => is_array( $rows ) ? count( $rows ) : 0,
			'mutation_performed' => false,
		);
	}

	public static function get_job( $input ) {
		$job_id = isset( $input['job_id'] ) ? strtolower( trim( (string) $input['job_id'] ) ) : '';
		$row = self::load_job( $job_id, false );
		if ( is_wp_error( $row ) ) return $row;
		return array( 'contract' => self::CONTRACT, 'job' => self::row( $row ), 'mutation_performed' => false );
	}

	public static function get_events( $input ) {
		global $wpdb;
		$job_id = isset( $input['job_id'] ) ? strtolower( trim( (string) $input['job_id'] ) ) : '';
		$job = self::load_job( $job_id, false );
		if ( is_wp_error( $job ) ) return $job;
		$limit = isset( $input['limit'] ) ? max( 1, min( 500, absint( $input['limit'] ) ) ) : 100;
		$t = MAD4B_SCP_Schema::tables();
		$rows = $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$t['content_job_events']} WHERE job_id=%s ORDER BY sequence ASC LIMIT %d", $job_id, $limit ),
			ARRAY_A
		);
		return array(
			'contract' => self::EVENT_CONTRACT,
			'job_id' => $job_id,
			'items' => is_array( $rows ) ? $rows : array(),
			'count' => is_array( $rows ) ? count( $rows ) : 0,
			'mutation_performed' => false,
		);
	}

	public static function create_job( $input ) {
		global $wpdb;
		if ( ! self::schema_ready() ) return new WP_Error( 'mad4b_content_job_schema_unavailable', 'ContentJob schema is not ready.' );
		$site_uuid = self::site_uuid();
		if ( '' === $site_uuid ) return new WP_Error( 'mad4b_content_job_site_identity_unavailable', 'Site Profile identity is unavailable.' );
		$subject = trim( wp_strip_all_tags( (string) $input['subject'] ) );
		if ( '' === $subject || strlen( $subject ) > 5000 ) return new WP_Error( 'mad4b_content_job_subject_invalid', 'ContentJob subject is missing or too long.' );
		$brand_id = sanitize_text_field( (string) $input['brand_id'] );
		$language = sanitize_key( (string) $input['language'] );
		$country = sanitize_key( (string) $input['country'] );
		$content_type = sanitize_key( (string) $input['content_type'] );
		if ( '' === $brand_id || '' === $language || '' === $country || '' === $content_type ) return new WP_Error( 'mad4b_content_job_identity_invalid', 'ContentJob brand/market/type identity is incomplete.' );
		$publish_at = self::normalize_datetime( isset( $input['desired_publish_at'] ) ? $input['desired_publish_at'] : '' );
		if ( is_wp_error( $publish_at ) ) return $publish_at;
		$writer_profile_id = strtolower( trim( (string) ( $input['writer_profile_id'] ?? '' ) ) );
		$writer_profile_version = strtolower( trim( (string) ( $input['writer_profile_version'] ?? '' ) ) );
		if ( ( '' === $writer_profile_id ) xor ( '' === $writer_profile_version ) ) {
			return new WP_Error( 'mad4b_writer_profile_binding_incomplete', 'WriterProfile identity and version must be supplied together.' );
		}
		if ( '' !== $writer_profile_id
			&& ( 1 !== preg_match( '/^[a-f0-9]{64}$/', $writer_profile_id )
				|| 1 !== preg_match( '/^[a-f0-9]{64}$/', $writer_profile_version ) ) ) {
			return new WP_Error( 'mad4b_writer_profile_identity_invalid', 'WriterProfile identity and version must be exact SHA-256 identities.' );
		}

		$job_id = strtolower( wp_generate_uuid4() );
		$now = gmdate( 'Y-m-d H:i:s' );
		$actor = self::actor();
		$t = MAD4B_SCP_Schema::tables();
		$data = array(
			'job_id' => $job_id,
			'tenant_id' => '',
			'site_uuid' => $site_uuid,
			'brand_id' => $brand_id,
			'subject' => $subject,
			'primary_keyword' => sanitize_text_field( isset( $input['primary_keyword'] ) ? (string) $input['primary_keyword'] : '' ),
			'language' => $language,
			'country' => $country,
			'content_type' => $content_type,
			'writer_profile_id' => $writer_profile_id,
			'writer_profile_version' => $writer_profile_version,
			'research_depth' => sanitize_key( isset( $input['research_depth'] ) ? (string) $input['research_depth'] : 'standard' ),
			'automation_level' => sanitize_key( isset( $input['automation_level'] ) ? (string) $input['automation_level'] : 'review_gated' ),
			'state' => 'NEW',
			'stage' => 'INTAKE',
			'target_post_type' => sanitize_key( isset( $input['target_post_type'] ) ? (string) $input['target_post_type'] : '' ),
			'target_post_id' => null,
			'desired_publish_at' => $publish_at,
			'current_artifact_id' => '',
			'quality_status' => 'unknown',
			'last_error_code' => '',
			'last_error_summary' => '',
			'job_revision' => 1,
			'created_by_nhi' => $actor['nhi'],
			'created_by_user' => get_current_user_id(),
			'created_at' => $now,
			'updated_at' => $now,
			'completed_at' => null,
			'cancelled_at' => null,
		);

		$wpdb->query( 'START TRANSACTION' );
		try {
			if ( false === $wpdb->insert( $t['content_jobs'], $data ) ) throw new RuntimeException( 'content_job_insert_failed:' . $wpdb->last_error );
			$event = self::append_event_locked( $job_id, 1, 'CREATED', '', 'NEW', '', 'INTAKE', (string) $input['reason'], $actor, '', '' );
			if ( is_wp_error( $event ) ) throw new RuntimeException( $event->get_error_code() );
			$audit = MAD4B_SCP_Audit::record( 'mad4b/content-job-create', array( 'job_id' => $job_id, 'site_uuid' => $site_uuid, 'revision' => 1 ), 'ok', true );
			if ( is_wp_error( $audit ) ) throw new RuntimeException( $audit->get_error_code() );
			if ( false === $wpdb->query( 'COMMIT' ) ) throw new RuntimeException( 'content_job_commit_failed' );
			MAD4B_SCP_Audit::transaction_committed();
		} catch ( Throwable $e ) {
			$wpdb->query( 'ROLLBACK' );
			MAD4B_SCP_Audit::transaction_rolled_back();
			return new WP_Error( 'mad4b_content_job_create_failed', 'Unable to create ContentJob.', array( 'cause' => $e->getMessage() ) );
		}
		return self::get_job( array( 'job_id' => $job_id ) );
	}

	public static function transition_job( $input ) {
		return self::transition(
			isset( $input['job_id'] ) ? $input['job_id'] : '',
			isset( $input['expected_revision'] ) ? $input['expected_revision'] : 0,
			isset( $input['state'] ) ? $input['state'] : '',
			isset( $input['stage'] ) ? $input['stage'] : '',
			isset( $input['reason'] ) ? $input['reason'] : '',
			isset( $input['plan_sha256'] ) ? $input['plan_sha256'] : '',
			isset( $input['artifact_id'] ) ? $input['artifact_id'] : ''
		);
	}

	public static function cancel_job( $input ) {
		$job = self::load_job( isset( $input['job_id'] ) ? $input['job_id'] : '', false );
		if ( is_wp_error( $job ) ) return $job;
		return self::transition(
			$job['job_id'],
			isset( $input['expected_revision'] ) ? $input['expected_revision'] : 0,
			'CANCELLED',
			$job['stage'],
			isset( $input['reason'] ) ? $input['reason'] : '',
			'',
			''
		);
	}

	private static function transition( $job_id, $expected_revision, $new_state, $new_stage, $reason, $plan_sha256, $artifact_id ) {
		global $wpdb;
		if ( ! self::schema_ready() ) return new WP_Error( 'mad4b_content_job_schema_unavailable', 'ContentJob schema is not ready.' );
		$job_id = strtolower( trim( (string) $job_id ) );
		if ( ! self::valid_uuid( $job_id ) ) return new WP_Error( 'mad4b_content_job_id_invalid', 'ContentJob ID is invalid.' );
		$expected_revision = absint( $expected_revision );
		$new_state = strtoupper( sanitize_key( (string) $new_state ) );
		$new_stage = strtoupper( sanitize_key( (string) $new_stage ) );
		$reason = trim( sanitize_text_field( (string) $reason ) );
		$plan_sha256 = strtolower( trim( (string) $plan_sha256 ) );
		$artifact_id = sanitize_text_field( (string) $artifact_id );
		if ( ! in_array( $new_state, self::states(), true ) || ! in_array( $new_stage, self::stages(), true ) ) return new WP_Error( 'mad4b_content_job_transition_invalid', 'Requested state/stage is invalid.' );
		if ( '' !== $plan_sha256 && ! preg_match( '/^[a-f0-9]{64}$/', $plan_sha256 ) ) return new WP_Error( 'mad4b_content_job_plan_invalid', 'Plan SHA is invalid.' );
		if ( strlen( $reason ) < 3 ) return new WP_Error( 'mad4b_content_job_reason_required', 'Transition reason is required.' );

		$t = MAD4B_SCP_Schema::tables();
		$wpdb->query( 'START TRANSACTION' );
		try {
			$row = self::load_job( $job_id, true );
			if ( is_wp_error( $row ) ) throw new RuntimeException( $row->get_error_code() );
			if ( $expected_revision !== (int) $row['job_revision'] ) throw new RuntimeException( 'content_job_revision_conflict' );
			if ( in_array( $row['state'], array( 'COMPLETED', 'CANCELLED' ), true ) ) throw new RuntimeException( 'content_job_terminal_immutable' );
			$same_state = hash_equals( (string) $row['state'], $new_state );
			$same_stage = hash_equals( (string) $row['stage'], $new_stage );
			if ( $same_state && $same_stage ) throw new RuntimeException( 'content_job_noop_transition' );
			$allowed = self::transition_map();
			if ( ! $same_state && ( ! isset( $allowed[ $row['state'] ] ) || ! in_array( $new_state, $allowed[ $row['state'] ], true ) ) ) {
				throw new RuntimeException( 'content_job_state_transition_denied' );
			}

			$revision = $expected_revision + 1;
			$now = gmdate( 'Y-m-d H:i:s' );
			$update = array(
				'state' => $new_state,
				'stage' => $new_stage,
				'current_artifact_id' => '' !== $artifact_id ? $artifact_id : (string) $row['current_artifact_id'],
				'job_revision' => $revision,
				'updated_at' => $now,
				'completed_at' => 'COMPLETED' === $new_state ? $now : $row['completed_at'],
				'cancelled_at' => 'CANCELLED' === $new_state ? $now : $row['cancelled_at'],
			);
			$changed = $wpdb->update(
				$t['content_jobs'],
				$update,
				array( 'id' => (int) $row['id'], 'job_revision' => $expected_revision ),
				null,
				array( '%d', '%d' )
			);
			if ( 1 !== (int) $changed ) throw new RuntimeException( 'content_job_transition_cas_failed' );
			$actor = self::actor();
			$event = self::append_event_locked(
				$job_id,
				$revision,
				'CANCELLED' === $new_state ? 'CANCELLED' : 'TRANSITIONED',
				(string) $row['state'],
				$new_state,
				(string) $row['stage'],
				$new_stage,
				$reason,
				$actor,
				$plan_sha256,
				$artifact_id
			);
			if ( is_wp_error( $event ) ) throw new RuntimeException( $event->get_error_code() );
			$audit = MAD4B_SCP_Audit::record( 'mad4b/content-job-transition', array( 'job_id' => $job_id, 'from_revision' => $expected_revision, 'to_revision' => $revision, 'state' => $new_state, 'stage' => $new_stage ), 'ok', true );
			if ( is_wp_error( $audit ) ) throw new RuntimeException( $audit->get_error_code() );
			if ( false === $wpdb->query( 'COMMIT' ) ) throw new RuntimeException( 'content_job_transition_commit_failed' );
			MAD4B_SCP_Audit::transaction_committed();
		} catch ( Throwable $e ) {
			$wpdb->query( 'ROLLBACK' );
			MAD4B_SCP_Audit::transaction_rolled_back();
			$code = $e->getMessage();
			if ( 'content_job_revision_conflict' === $code ) return new WP_Error( 'mad4b_content_job_revision_conflict', 'ContentJob revision changed since the requested transition.' );
			if ( 'content_job_terminal_immutable' === $code ) return new WP_Error( 'mad4b_content_job_terminal_immutable', 'Completed or cancelled ContentJob history is immutable.' );
			if ( 'content_job_state_transition_denied' === $code ) return new WP_Error( 'mad4b_content_job_state_transition_denied', 'Requested lifecycle transition is not allowed.' );
			if ( 'content_job_noop_transition' === $code ) return new WP_Error( 'mad4b_content_job_noop_transition', 'State and stage are unchanged.' );
			return new WP_Error( 'mad4b_content_job_transition_failed', 'Unable to transition ContentJob.', array( 'cause' => $code ) );
		}
		return self::get_job( array( 'job_id' => $job_id ) );
	}

	private static function load_job( $job_id, $for_update ) {
		global $wpdb;
		if ( ! self::valid_uuid( $job_id ) ) return new WP_Error( 'mad4b_content_job_id_invalid', 'ContentJob ID is invalid.' );
		$site_uuid = self::site_uuid();
		if ( '' === $site_uuid ) return new WP_Error( 'mad4b_content_job_site_identity_unavailable', 'Site Profile identity is unavailable.' );
		$t = MAD4B_SCP_Schema::tables();
		$sql = $wpdb->prepare( "SELECT * FROM {$t['content_jobs']} WHERE job_id=%s AND site_uuid=%s LIMIT 1" . ( $for_update ? ' FOR UPDATE' : '' ), $job_id, $site_uuid );
		$row = $wpdb->get_row( $sql, ARRAY_A );
		return is_array( $row ) ? $row : new WP_Error( 'mad4b_content_job_missing', 'ContentJob was not found for this site.' );
	}

	private static function append_event_locked( $job_id, $sequence, $event_type, $previous_state, $new_state, $previous_stage, $new_stage, $reason, array $actor, $plan_sha256, $artifact_id ) {
		global $wpdb;
		$t = MAD4B_SCP_Schema::tables();
		$previous_hash = (string) $wpdb->get_var(
			$wpdb->prepare( "SELECT entry_sha256 FROM {$t['content_job_events']} WHERE job_id=%s ORDER BY sequence DESC LIMIT 1", $job_id )
		);
		$event_id = strtolower( wp_generate_uuid4() );
		$now = gmdate( 'Y-m-d H:i:s' );
		$material = array(
			'contract' => self::EVENT_CONTRACT,
			'event_id' => $event_id,
			'job_id' => $job_id,
			'sequence' => (int) $sequence,
			'event_type' => sanitize_key( (string) $event_type ),
			'previous_state' => (string) $previous_state,
			'new_state' => (string) $new_state,
			'previous_stage' => (string) $previous_stage,
			'new_stage' => (string) $new_stage,
			'reason_code' => substr( sanitize_key( (string) $reason ), 0, 64 ),
			'actor_type' => sanitize_key( (string) $actor['type'] ),
			'actor_id' => substr( sanitize_text_field( (string) $actor['id'] ), 0, 191 ),
			'plan_sha256' => (string) $plan_sha256,
			'artifact_id' => substr( sanitize_text_field( (string) $artifact_id ), 0, 191 ),
			'provider_id' => '',
			'previous_entry_sha256' => $previous_hash,
			'created_at' => $now,
		);
		$entry_hash = hash( 'sha256', self::stable_json( $material ) );
		$ok = $wpdb->insert(
			$t['content_job_events'],
			array(
				'event_id' => $event_id,
				'job_id' => $job_id,
				'sequence' => (int) $sequence,
				'event_type' => $material['event_type'],
				'previous_state' => $previous_state,
				'new_state' => $new_state,
				'previous_stage' => $previous_stage,
				'new_stage' => $new_stage,
				'reason_code' => $material['reason_code'],
				'correlation_id' => '',
				'actor_type' => $material['actor_type'],
				'actor_id' => $material['actor_id'],
				'plan_sha256' => $plan_sha256,
				'artifact_id' => $artifact_id,
				'provider_id' => '',
				'metadata_json' => wp_json_encode( array( 'reason' => $reason ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ),
				'previous_entry_sha256' => $previous_hash,
				'entry_sha256' => $entry_hash,
				'created_at' => $now,
			)
		);
		if ( false === $ok ) return new WP_Error( 'mad4b_content_job_event_insert_failed', 'Unable to append ContentJob event.', array( 'db_error' => $wpdb->last_error ) );
		return array( 'event_id' => $event_id, 'entry_sha256' => $entry_hash, 'sequence' => (int) $sequence );
	}

	private static function stable_json( $value ) {
		$value = self::sort_value( $value );
		$json = wp_json_encode( $value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		return false === $json ? '' : $json;
	}

	private static function sort_value( $value ) {
		if ( ! is_array( $value ) ) return $value;
		$is_list = array_keys( $value ) === range( 0, count( $value ) - 1 );
		if ( ! $is_list ) ksort( $value, SORT_STRING );
		foreach ( $value as $key => $item ) $value[ $key ] = self::sort_value( $item );
		return $value;
	}
}

MAD4B_SCP_Content_Jobs::boot();
, 'maxLength' => 64, 'default' => '' ),
				'writer_profile_version' => array( 'type' => 'string', 'pattern' => '^(|[a-f0-9]{64})
				'research_depth' => array( 'type' => 'string', 'maxLength' => 32, 'default' => 'standard' ),
				'automation_level' => array( 'type' => 'string', 'maxLength' => 32, 'default' => 'review_gated' ),
				'target_post_type' => array( 'type' => 'string', 'maxLength' => 64, 'default' => '' ),
				'desired_publish_at' => array( 'type' => 'string', 'maxLength' => 32, 'default' => '' ),
				'reason' => array( 'type' => 'string', 'minLength' => 3, 'maxLength' => 500 ),
			),
			array( 'brand_id', 'subject', 'language', 'country', 'content_type', 'reason' ),
			false
		);
		self::register(
			'mad4b/content-job-transition',
			'Transition Content Job',
			'transition_job',
			array(
				'job_id' => array( 'type' => 'string', 'minLength' => 36, 'maxLength' => 36 ),
				'expected_revision' => array( 'type' => 'integer', 'minimum' => 1 ),
				'state' => array( 'type' => 'string', 'enum' => self::states() ),
				'stage' => array( 'type' => 'string', 'enum' => self::stages() ),
				'reason' => array( 'type' => 'string', 'minLength' => 3, 'maxLength' => 500 ),
				'plan_sha256' => array( 'type' => 'string', 'pattern' => '^[a-f0-9]{64}$', 'default' => '' ),
				'artifact_id' => array( 'type' => 'string', 'maxLength' => 191, 'default' => '' ),
			),
			array( 'job_id', 'expected_revision', 'state', 'stage', 'reason' ),
			false
		);
		self::register(
			'mad4b/content-job-cancel',
			'Cancel Content Job',
			'cancel_job',
			array(
				'job_id' => array( 'type' => 'string', 'minLength' => 36, 'maxLength' => 36 ),
				'expected_revision' => array( 'type' => 'integer', 'minimum' => 1 ),
				'reason' => array( 'type' => 'string', 'minLength' => 3, 'maxLength' => 500 ),
			),
			array( 'job_id', 'expected_revision', 'reason' ),
			false
		);
	}

	private static function register( $name, $label, $method, array $properties, array $required, $readonly ) {
		if ( function_exists( 'wp_has_ability' ) && wp_has_ability( $name ) ) return;
		wp_register_ability(
			$name,
			array(
				'label' => $label,
				'description' => $label . ' through the governed Feature 007 ContentJob aggregate.',
				'category' => $readonly ? 'mad4b-read' : 'mad4b-write',
				'execute_callback' => array( __CLASS__, $method ),
				'permission_callback' => $readonly ? array( 'MAD4B_SCP_Policy', 'can_read' ) : array( 'MAD4B_SCP_Policy', 'can_mutate' ),
				'input_schema' => array(
					'type' => 'object',
					'properties' => $properties,
					'required' => $required,
					'additionalProperties' => false,
				),
				'output_schema' => array( 'type' => 'object', 'additionalProperties' => true ),
				'meta' => array(
					'public' => false,
					'show_in_rest' => false,
					'mcp' => array( 'public' => false, 'type' => 'tool', 'surface' => $readonly ? 'read' : 'write' ),
					'annotations' => array(
						'readonly' => (bool) $readonly,
						'destructive' => ! $readonly,
						'idempotent' => $readonly,
					),
				),
			)
		);
	}

	private static function schema_ready() {
		return class_exists( 'MAD4B_SCP_Schema' ) && MAD4B_SCP_Schema::critical_ready();
	}

	private static function site_uuid() {
		$uuid = class_exists( 'MAD4B_SCP_Site_Profile' ) ? strtolower( trim( (string) MAD4B_SCP_Site_Profile::site_uuid() ) ) : '';
		return preg_match( '/^[a-f0-9-]{36}$/', $uuid ) ? $uuid : '';
	}

	private static function valid_uuid( $value ) {
		return is_string( $value ) && 1 === preg_match( '/^[a-f0-9-]{36}$/', strtolower( trim( $value ) ) );
	}

	private static function actor() {
		$actor = array( 'type' => 'wordpress_user', 'id' => (string) get_current_user_id(), 'nhi' => '' );
		if ( class_exists( 'MAD4B_SCP_Identity_Context' ) && class_exists( 'MAD4B_SCP_Agent_Registry' ) ) {
			$identity = MAD4B_SCP_Identity_Context::current();
			if ( ! is_wp_error( $identity ) ) {
				$agent = MAD4B_SCP_Agent_Registry::resolve_agent( $identity );
				if ( ! is_wp_error( $agent ) && ! empty( $agent['public_id'] ) ) {
					$actor['type'] = 'nhi';
					$actor['id'] = strtolower( (string) $agent['public_id'] );
					$actor['nhi'] = $actor['id'];
				}
			}
		}
		return $actor;
	}

	private static function normalize_datetime( $value ) {
		$value = trim( (string) $value );
		if ( '' === $value ) return null;
		$ts = strtotime( $value );
		if ( false === $ts ) return new WP_Error( 'mad4b_content_job_datetime_invalid', 'Invalid desired publish datetime.' );
		return gmdate( 'Y-m-d H:i:s', $ts );
	}

	private static function row( $row ) {
		if ( ! is_array( $row ) ) return null;
		foreach ( array( 'id', 'target_post_id', 'job_revision', 'created_by_user' ) as $key ) {
			if ( array_key_exists( $key, $row ) && null !== $row[ $key ] ) $row[ $key ] = (int) $row[ $key ];
		}
		return $row;
	}

	public static function list_jobs( $input ) {
		global $wpdb;
		if ( ! self::schema_ready() ) return new WP_Error( 'mad4b_content_job_schema_unavailable', 'ContentJob schema is not ready.' );
		$site_uuid = self::site_uuid();
		if ( '' === $site_uuid ) return new WP_Error( 'mad4b_content_job_site_identity_unavailable', 'Site Profile identity is unavailable.' );
		$t = MAD4B_SCP_Schema::tables();
		$where = array( 'site_uuid=%s' );
		$args = array( $site_uuid );
		$state = isset( $input['state'] ) ? strtoupper( sanitize_key( (string) $input['state'] ) ) : '';
		$stage = isset( $input['stage'] ) ? strtoupper( sanitize_key( (string) $input['stage'] ) ) : '';
		if ( '' !== $state ) { $where[] = 'state=%s'; $args[] = $state; }
		if ( '' !== $stage ) { $where[] = 'stage=%s'; $args[] = $stage; }
		$limit = isset( $input['limit'] ) ? max( 1, min( 200, absint( $input['limit'] ) ) ) : 50;
		$args[] = $limit;
		$sql = "SELECT * FROM {$t['content_jobs']} WHERE " . implode( ' AND ', $where ) . ' ORDER BY updated_at DESC,id DESC LIMIT %d';
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $args ), ARRAY_A );
		return array(
			'contract' => self::CONTRACT,
			'items' => array_values( array_filter( array_map( array( __CLASS__, 'row' ), is_array( $rows ) ? $rows : array() ) ) ),
			'count' => is_array( $rows ) ? count( $rows ) : 0,
			'mutation_performed' => false,
		);
	}

	public static function get_job( $input ) {
		$job_id = isset( $input['job_id'] ) ? strtolower( trim( (string) $input['job_id'] ) ) : '';
		$row = self::load_job( $job_id, false );
		if ( is_wp_error( $row ) ) return $row;
		return array( 'contract' => self::CONTRACT, 'job' => self::row( $row ), 'mutation_performed' => false );
	}

	public static function get_events( $input ) {
		global $wpdb;
		$job_id = isset( $input['job_id'] ) ? strtolower( trim( (string) $input['job_id'] ) ) : '';
		$job = self::load_job( $job_id, false );
		if ( is_wp_error( $job ) ) return $job;
		$limit = isset( $input['limit'] ) ? max( 1, min( 500, absint( $input['limit'] ) ) ) : 100;
		$t = MAD4B_SCP_Schema::tables();
		$rows = $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$t['content_job_events']} WHERE job_id=%s ORDER BY sequence ASC LIMIT %d", $job_id, $limit ),
			ARRAY_A
		);
		return array(
			'contract' => self::EVENT_CONTRACT,
			'job_id' => $job_id,
			'items' => is_array( $rows ) ? $rows : array(),
			'count' => is_array( $rows ) ? count( $rows ) : 0,
			'mutation_performed' => false,
		);
	}

	public static function create_job( $input ) {
		global $wpdb;
		if ( ! self::schema_ready() ) return new WP_Error( 'mad4b_content_job_schema_unavailable', 'ContentJob schema is not ready.' );
		$site_uuid = self::site_uuid();
		if ( '' === $site_uuid ) return new WP_Error( 'mad4b_content_job_site_identity_unavailable', 'Site Profile identity is unavailable.' );
		$subject = trim( wp_strip_all_tags( (string) $input['subject'] ) );
		if ( '' === $subject || strlen( $subject ) > 5000 ) return new WP_Error( 'mad4b_content_job_subject_invalid', 'ContentJob subject is missing or too long.' );
		$brand_id = sanitize_text_field( (string) $input['brand_id'] );
		$language = sanitize_key( (string) $input['language'] );
		$country = sanitize_key( (string) $input['country'] );
		$content_type = sanitize_key( (string) $input['content_type'] );
		if ( '' === $brand_id || '' === $language || '' === $country || '' === $content_type ) return new WP_Error( 'mad4b_content_job_identity_invalid', 'ContentJob brand/market/type identity is incomplete.' );
		$publish_at = self::normalize_datetime( isset( $input['desired_publish_at'] ) ? $input['desired_publish_at'] : '' );
		if ( is_wp_error( $publish_at ) ) return $publish_at;

		$job_id = strtolower( wp_generate_uuid4() );
		$now = gmdate( 'Y-m-d H:i:s' );
		$actor = self::actor();
		$t = MAD4B_SCP_Schema::tables();
		$data = array(
			'job_id' => $job_id,
			'tenant_id' => '',
			'site_uuid' => $site_uuid,
			'brand_id' => $brand_id,
			'subject' => $subject,
			'primary_keyword' => sanitize_text_field( isset( $input['primary_keyword'] ) ? (string) $input['primary_keyword'] : '' ),
			'language' => $language,
			'country' => $country,
			'content_type' => $content_type,
			'writer_profile_id' => sanitize_text_field( isset( $input['writer_profile_id'] ) ? (string) $input['writer_profile_id'] : '' ),
			'writer_profile_version' => sanitize_text_field( isset( $input['writer_profile_version'] ) ? (string) $input['writer_profile_version'] : '' ),
			'research_depth' => sanitize_key( isset( $input['research_depth'] ) ? (string) $input['research_depth'] : 'standard' ),
			'automation_level' => sanitize_key( isset( $input['automation_level'] ) ? (string) $input['automation_level'] : 'review_gated' ),
			'state' => 'NEW',
			'stage' => 'INTAKE',
			'target_post_type' => sanitize_key( isset( $input['target_post_type'] ) ? (string) $input['target_post_type'] : '' ),
			'target_post_id' => null,
			'desired_publish_at' => $publish_at,
			'current_artifact_id' => '',
			'quality_status' => 'unknown',
			'last_error_code' => '',
			'last_error_summary' => '',
			'job_revision' => 1,
			'created_by_nhi' => $actor['nhi'],
			'created_by_user' => get_current_user_id(),
			'created_at' => $now,
			'updated_at' => $now,
			'completed_at' => null,
			'cancelled_at' => null,
		);

		$wpdb->query( 'START TRANSACTION' );
		try {
			if ( false === $wpdb->insert( $t['content_jobs'], $data ) ) throw new RuntimeException( 'content_job_insert_failed:' . $wpdb->last_error );
			$event = self::append_event_locked( $job_id, 1, 'CREATED', '', 'NEW', '', 'INTAKE', (string) $input['reason'], $actor, '', '' );
			if ( is_wp_error( $event ) ) throw new RuntimeException( $event->get_error_code() );
			$audit = MAD4B_SCP_Audit::record( 'mad4b/content-job-create', array( 'job_id' => $job_id, 'site_uuid' => $site_uuid, 'revision' => 1 ), 'ok', true );
			if ( is_wp_error( $audit ) ) throw new RuntimeException( $audit->get_error_code() );
			if ( false === $wpdb->query( 'COMMIT' ) ) throw new RuntimeException( 'content_job_commit_failed' );
			MAD4B_SCP_Audit::transaction_committed();
		} catch ( Throwable $e ) {
			$wpdb->query( 'ROLLBACK' );
			MAD4B_SCP_Audit::transaction_rolled_back();
			return new WP_Error( 'mad4b_content_job_create_failed', 'Unable to create ContentJob.', array( 'cause' => $e->getMessage() ) );
		}
		return self::get_job( array( 'job_id' => $job_id ) );
	}

	public static function transition_job( $input ) {
		return self::transition(
			isset( $input['job_id'] ) ? $input['job_id'] : '',
			isset( $input['expected_revision'] ) ? $input['expected_revision'] : 0,
			isset( $input['state'] ) ? $input['state'] : '',
			isset( $input['stage'] ) ? $input['stage'] : '',
			isset( $input['reason'] ) ? $input['reason'] : '',
			isset( $input['plan_sha256'] ) ? $input['plan_sha256'] : '',
			isset( $input['artifact_id'] ) ? $input['artifact_id'] : ''
		);
	}

	public static function cancel_job( $input ) {
		$job = self::load_job( isset( $input['job_id'] ) ? $input['job_id'] : '', false );
		if ( is_wp_error( $job ) ) return $job;
		return self::transition(
			$job['job_id'],
			isset( $input['expected_revision'] ) ? $input['expected_revision'] : 0,
			'CANCELLED',
			$job['stage'],
			isset( $input['reason'] ) ? $input['reason'] : '',
			'',
			''
		);
	}

	private static function transition( $job_id, $expected_revision, $new_state, $new_stage, $reason, $plan_sha256, $artifact_id ) {
		global $wpdb;
		if ( ! self::schema_ready() ) return new WP_Error( 'mad4b_content_job_schema_unavailable', 'ContentJob schema is not ready.' );
		$job_id = strtolower( trim( (string) $job_id ) );
		if ( ! self::valid_uuid( $job_id ) ) return new WP_Error( 'mad4b_content_job_id_invalid', 'ContentJob ID is invalid.' );
		$expected_revision = absint( $expected_revision );
		$new_state = strtoupper( sanitize_key( (string) $new_state ) );
		$new_stage = strtoupper( sanitize_key( (string) $new_stage ) );
		$reason = trim( sanitize_text_field( (string) $reason ) );
		$plan_sha256 = strtolower( trim( (string) $plan_sha256 ) );
		$artifact_id = sanitize_text_field( (string) $artifact_id );
		if ( ! in_array( $new_state, self::states(), true ) || ! in_array( $new_stage, self::stages(), true ) ) return new WP_Error( 'mad4b_content_job_transition_invalid', 'Requested state/stage is invalid.' );
		if ( '' !== $plan_sha256 && ! preg_match( '/^[a-f0-9]{64}$/', $plan_sha256 ) ) return new WP_Error( 'mad4b_content_job_plan_invalid', 'Plan SHA is invalid.' );
		if ( strlen( $reason ) < 3 ) return new WP_Error( 'mad4b_content_job_reason_required', 'Transition reason is required.' );

		$t = MAD4B_SCP_Schema::tables();
		$wpdb->query( 'START TRANSACTION' );
		try {
			$row = self::load_job( $job_id, true );
			if ( is_wp_error( $row ) ) throw new RuntimeException( $row->get_error_code() );
			if ( $expected_revision !== (int) $row['job_revision'] ) throw new RuntimeException( 'content_job_revision_conflict' );
			if ( in_array( $row['state'], array( 'COMPLETED', 'CANCELLED' ), true ) ) throw new RuntimeException( 'content_job_terminal_immutable' );
			$same_state = hash_equals( (string) $row['state'], $new_state );
			$same_stage = hash_equals( (string) $row['stage'], $new_stage );
			if ( $same_state && $same_stage ) throw new RuntimeException( 'content_job_noop_transition' );
			$allowed = self::transition_map();
			if ( ! $same_state && ( ! isset( $allowed[ $row['state'] ] ) || ! in_array( $new_state, $allowed[ $row['state'] ], true ) ) ) {
				throw new RuntimeException( 'content_job_state_transition_denied' );
			}

			$revision = $expected_revision + 1;
			$now = gmdate( 'Y-m-d H:i:s' );
			$update = array(
				'state' => $new_state,
				'stage' => $new_stage,
				'current_artifact_id' => '' !== $artifact_id ? $artifact_id : (string) $row['current_artifact_id'],
				'job_revision' => $revision,
				'updated_at' => $now,
				'completed_at' => 'COMPLETED' === $new_state ? $now : $row['completed_at'],
				'cancelled_at' => 'CANCELLED' === $new_state ? $now : $row['cancelled_at'],
			);
			$changed = $wpdb->update(
				$t['content_jobs'],
				$update,
				array( 'id' => (int) $row['id'], 'job_revision' => $expected_revision ),
				null,
				array( '%d', '%d' )
			);
			if ( 1 !== (int) $changed ) throw new RuntimeException( 'content_job_transition_cas_failed' );
			$actor = self::actor();
			$event = self::append_event_locked(
				$job_id,
				$revision,
				'CANCELLED' === $new_state ? 'CANCELLED' : 'TRANSITIONED',
				(string) $row['state'],
				$new_state,
				(string) $row['stage'],
				$new_stage,
				$reason,
				$actor,
				$plan_sha256,
				$artifact_id
			);
			if ( is_wp_error( $event ) ) throw new RuntimeException( $event->get_error_code() );
			$audit = MAD4B_SCP_Audit::record( 'mad4b/content-job-transition', array( 'job_id' => $job_id, 'from_revision' => $expected_revision, 'to_revision' => $revision, 'state' => $new_state, 'stage' => $new_stage ), 'ok', true );
			if ( is_wp_error( $audit ) ) throw new RuntimeException( $audit->get_error_code() );
			if ( false === $wpdb->query( 'COMMIT' ) ) throw new RuntimeException( 'content_job_transition_commit_failed' );
			MAD4B_SCP_Audit::transaction_committed();
		} catch ( Throwable $e ) {
			$wpdb->query( 'ROLLBACK' );
			MAD4B_SCP_Audit::transaction_rolled_back();
			$code = $e->getMessage();
			if ( 'content_job_revision_conflict' === $code ) return new WP_Error( 'mad4b_content_job_revision_conflict', 'ContentJob revision changed since the requested transition.' );
			if ( 'content_job_terminal_immutable' === $code ) return new WP_Error( 'mad4b_content_job_terminal_immutable', 'Completed or cancelled ContentJob history is immutable.' );
			if ( 'content_job_state_transition_denied' === $code ) return new WP_Error( 'mad4b_content_job_state_transition_denied', 'Requested lifecycle transition is not allowed.' );
			if ( 'content_job_noop_transition' === $code ) return new WP_Error( 'mad4b_content_job_noop_transition', 'State and stage are unchanged.' );
			return new WP_Error( 'mad4b_content_job_transition_failed', 'Unable to transition ContentJob.', array( 'cause' => $code ) );
		}
		return self::get_job( array( 'job_id' => $job_id ) );
	}

	private static function load_job( $job_id, $for_update ) {
		global $wpdb;
		if ( ! self::valid_uuid( $job_id ) ) return new WP_Error( 'mad4b_content_job_id_invalid', 'ContentJob ID is invalid.' );
		$site_uuid = self::site_uuid();
		if ( '' === $site_uuid ) return new WP_Error( 'mad4b_content_job_site_identity_unavailable', 'Site Profile identity is unavailable.' );
		$t = MAD4B_SCP_Schema::tables();
		$sql = $wpdb->prepare( "SELECT * FROM {$t['content_jobs']} WHERE job_id=%s AND site_uuid=%s LIMIT 1" . ( $for_update ? ' FOR UPDATE' : '' ), $job_id, $site_uuid );
		$row = $wpdb->get_row( $sql, ARRAY_A );
		return is_array( $row ) ? $row : new WP_Error( 'mad4b_content_job_missing', 'ContentJob was not found for this site.' );
	}

	private static function append_event_locked( $job_id, $sequence, $event_type, $previous_state, $new_state, $previous_stage, $new_stage, $reason, array $actor, $plan_sha256, $artifact_id ) {
		global $wpdb;
		$t = MAD4B_SCP_Schema::tables();
		$previous_hash = (string) $wpdb->get_var(
			$wpdb->prepare( "SELECT entry_sha256 FROM {$t['content_job_events']} WHERE job_id=%s ORDER BY sequence DESC LIMIT 1", $job_id )
		);
		$event_id = strtolower( wp_generate_uuid4() );
		$now = gmdate( 'Y-m-d H:i:s' );
		$material = array(
			'contract' => self::EVENT_CONTRACT,
			'event_id' => $event_id,
			'job_id' => $job_id,
			'sequence' => (int) $sequence,
			'event_type' => sanitize_key( (string) $event_type ),
			'previous_state' => (string) $previous_state,
			'new_state' => (string) $new_state,
			'previous_stage' => (string) $previous_stage,
			'new_stage' => (string) $new_stage,
			'reason_code' => substr( sanitize_key( (string) $reason ), 0, 64 ),
			'actor_type' => sanitize_key( (string) $actor['type'] ),
			'actor_id' => substr( sanitize_text_field( (string) $actor['id'] ), 0, 191 ),
			'plan_sha256' => (string) $plan_sha256,
			'artifact_id' => substr( sanitize_text_field( (string) $artifact_id ), 0, 191 ),
			'provider_id' => '',
			'previous_entry_sha256' => $previous_hash,
			'created_at' => $now,
		);
		$entry_hash = hash( 'sha256', self::stable_json( $material ) );
		$ok = $wpdb->insert(
			$t['content_job_events'],
			array(
				'event_id' => $event_id,
				'job_id' => $job_id,
				'sequence' => (int) $sequence,
				'event_type' => $material['event_type'],
				'previous_state' => $previous_state,
				'new_state' => $new_state,
				'previous_stage' => $previous_stage,
				'new_stage' => $new_stage,
				'reason_code' => $material['reason_code'],
				'correlation_id' => '',
				'actor_type' => $material['actor_type'],
				'actor_id' => $material['actor_id'],
				'plan_sha256' => $plan_sha256,
				'artifact_id' => $artifact_id,
				'provider_id' => '',
				'metadata_json' => wp_json_encode( array( 'reason' => $reason ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ),
				'previous_entry_sha256' => $previous_hash,
				'entry_sha256' => $entry_hash,
				'created_at' => $now,
			)
		);
		if ( false === $ok ) return new WP_Error( 'mad4b_content_job_event_insert_failed', 'Unable to append ContentJob event.', array( 'db_error' => $wpdb->last_error ) );
		return array( 'event_id' => $event_id, 'entry_sha256' => $entry_hash, 'sequence' => (int) $sequence );
	}

	private static function stable_json( $value ) {
		$value = self::sort_value( $value );
		$json = wp_json_encode( $value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		return false === $json ? '' : $json;
	}

	private static function sort_value( $value ) {
		if ( ! is_array( $value ) ) return $value;
		$is_list = array_keys( $value ) === range( 0, count( $value ) - 1 );
		if ( ! $is_list ) ksort( $value, SORT_STRING );
		foreach ( $value as $key => $item ) $value[ $key ] = self::sort_value( $item );
		return $value;
	}
}

MAD4B_SCP_Content_Jobs::boot();
, 'maxLength' => 64, 'default' => '' ),
				'research_depth' => array( 'type' => 'string', 'maxLength' => 32, 'default' => 'standard' ),
				'automation_level' => array( 'type' => 'string', 'maxLength' => 32, 'default' => 'review_gated' ),
				'target_post_type' => array( 'type' => 'string', 'maxLength' => 64, 'default' => '' ),
				'desired_publish_at' => array( 'type' => 'string', 'maxLength' => 32, 'default' => '' ),
				'reason' => array( 'type' => 'string', 'minLength' => 3, 'maxLength' => 500 ),
			),
			array( 'brand_id', 'subject', 'language', 'country', 'content_type', 'reason' ),
			false
		);
		self::register(
			'mad4b/content-job-transition',
			'Transition Content Job',
			'transition_job',
			array(
				'job_id' => array( 'type' => 'string', 'minLength' => 36, 'maxLength' => 36 ),
				'expected_revision' => array( 'type' => 'integer', 'minimum' => 1 ),
				'state' => array( 'type' => 'string', 'enum' => self::states() ),
				'stage' => array( 'type' => 'string', 'enum' => self::stages() ),
				'reason' => array( 'type' => 'string', 'minLength' => 3, 'maxLength' => 500 ),
				'plan_sha256' => array( 'type' => 'string', 'pattern' => '^[a-f0-9]{64}$', 'default' => '' ),
				'artifact_id' => array( 'type' => 'string', 'maxLength' => 191, 'default' => '' ),
			),
			array( 'job_id', 'expected_revision', 'state', 'stage', 'reason' ),
			false
		);
		self::register(
			'mad4b/content-job-cancel',
			'Cancel Content Job',
			'cancel_job',
			array(
				'job_id' => array( 'type' => 'string', 'minLength' => 36, 'maxLength' => 36 ),
				'expected_revision' => array( 'type' => 'integer', 'minimum' => 1 ),
				'reason' => array( 'type' => 'string', 'minLength' => 3, 'maxLength' => 500 ),
			),
			array( 'job_id', 'expected_revision', 'reason' ),
			false
		);
	}

	private static function register( $name, $label, $method, array $properties, array $required, $readonly ) {
		if ( function_exists( 'wp_has_ability' ) && wp_has_ability( $name ) ) return;
		wp_register_ability(
			$name,
			array(
				'label' => $label,
				'description' => $label . ' through the governed Feature 007 ContentJob aggregate.',
				'category' => $readonly ? 'mad4b-read' : 'mad4b-write',
				'execute_callback' => array( __CLASS__, $method ),
				'permission_callback' => $readonly ? array( 'MAD4B_SCP_Policy', 'can_read' ) : array( 'MAD4B_SCP_Policy', 'can_mutate' ),
				'input_schema' => array(
					'type' => 'object',
					'properties' => $properties,
					'required' => $required,
					'additionalProperties' => false,
				),
				'output_schema' => array( 'type' => 'object', 'additionalProperties' => true ),
				'meta' => array(
					'public' => false,
					'show_in_rest' => false,
					'mcp' => array( 'public' => false, 'type' => 'tool', 'surface' => $readonly ? 'read' : 'write' ),
					'annotations' => array(
						'readonly' => (bool) $readonly,
						'destructive' => ! $readonly,
						'idempotent' => $readonly,
					),
				),
			)
		);
	}

	private static function schema_ready() {
		return class_exists( 'MAD4B_SCP_Schema' ) && MAD4B_SCP_Schema::critical_ready();
	}

	private static function site_uuid() {
		$uuid = class_exists( 'MAD4B_SCP_Site_Profile' ) ? strtolower( trim( (string) MAD4B_SCP_Site_Profile::site_uuid() ) ) : '';
		return preg_match( '/^[a-f0-9-]{36}$/', $uuid ) ? $uuid : '';
	}

	private static function valid_uuid( $value ) {
		return is_string( $value ) && 1 === preg_match( '/^[a-f0-9-]{36}$/', strtolower( trim( $value ) ) );
	}

	private static function actor() {
		$actor = array( 'type' => 'wordpress_user', 'id' => (string) get_current_user_id(), 'nhi' => '' );
		if ( class_exists( 'MAD4B_SCP_Identity_Context' ) && class_exists( 'MAD4B_SCP_Agent_Registry' ) ) {
			$identity = MAD4B_SCP_Identity_Context::current();
			if ( ! is_wp_error( $identity ) ) {
				$agent = MAD4B_SCP_Agent_Registry::resolve_agent( $identity );
				if ( ! is_wp_error( $agent ) && ! empty( $agent['public_id'] ) ) {
					$actor['type'] = 'nhi';
					$actor['id'] = strtolower( (string) $agent['public_id'] );
					$actor['nhi'] = $actor['id'];
				}
			}
		}
		return $actor;
	}

	private static function normalize_datetime( $value ) {
		$value = trim( (string) $value );
		if ( '' === $value ) return null;
		$ts = strtotime( $value );
		if ( false === $ts ) return new WP_Error( 'mad4b_content_job_datetime_invalid', 'Invalid desired publish datetime.' );
		return gmdate( 'Y-m-d H:i:s', $ts );
	}

	private static function row( $row ) {
		if ( ! is_array( $row ) ) return null;
		foreach ( array( 'id', 'target_post_id', 'job_revision', 'created_by_user' ) as $key ) {
			if ( array_key_exists( $key, $row ) && null !== $row[ $key ] ) $row[ $key ] = (int) $row[ $key ];
		}
		return $row;
	}

	public static function list_jobs( $input ) {
		global $wpdb;
		if ( ! self::schema_ready() ) return new WP_Error( 'mad4b_content_job_schema_unavailable', 'ContentJob schema is not ready.' );
		$site_uuid = self::site_uuid();
		if ( '' === $site_uuid ) return new WP_Error( 'mad4b_content_job_site_identity_unavailable', 'Site Profile identity is unavailable.' );
		$t = MAD4B_SCP_Schema::tables();
		$where = array( 'site_uuid=%s' );
		$args = array( $site_uuid );
		$state = isset( $input['state'] ) ? strtoupper( sanitize_key( (string) $input['state'] ) ) : '';
		$stage = isset( $input['stage'] ) ? strtoupper( sanitize_key( (string) $input['stage'] ) ) : '';
		if ( '' !== $state ) { $where[] = 'state=%s'; $args[] = $state; }
		if ( '' !== $stage ) { $where[] = 'stage=%s'; $args[] = $stage; }
		$limit = isset( $input['limit'] ) ? max( 1, min( 200, absint( $input['limit'] ) ) ) : 50;
		$args[] = $limit;
		$sql = "SELECT * FROM {$t['content_jobs']} WHERE " . implode( ' AND ', $where ) . ' ORDER BY updated_at DESC,id DESC LIMIT %d';
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $args ), ARRAY_A );
		return array(
			'contract' => self::CONTRACT,
			'items' => array_values( array_filter( array_map( array( __CLASS__, 'row' ), is_array( $rows ) ? $rows : array() ) ) ),
			'count' => is_array( $rows ) ? count( $rows ) : 0,
			'mutation_performed' => false,
		);
	}

	public static function get_job( $input ) {
		$job_id = isset( $input['job_id'] ) ? strtolower( trim( (string) $input['job_id'] ) ) : '';
		$row = self::load_job( $job_id, false );
		if ( is_wp_error( $row ) ) return $row;
		return array( 'contract' => self::CONTRACT, 'job' => self::row( $row ), 'mutation_performed' => false );
	}

	public static function get_events( $input ) {
		global $wpdb;
		$job_id = isset( $input['job_id'] ) ? strtolower( trim( (string) $input['job_id'] ) ) : '';
		$job = self::load_job( $job_id, false );
		if ( is_wp_error( $job ) ) return $job;
		$limit = isset( $input['limit'] ) ? max( 1, min( 500, absint( $input['limit'] ) ) ) : 100;
		$t = MAD4B_SCP_Schema::tables();
		$rows = $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$t['content_job_events']} WHERE job_id=%s ORDER BY sequence ASC LIMIT %d", $job_id, $limit ),
			ARRAY_A
		);
		return array(
			'contract' => self::EVENT_CONTRACT,
			'job_id' => $job_id,
			'items' => is_array( $rows ) ? $rows : array(),
			'count' => is_array( $rows ) ? count( $rows ) : 0,
			'mutation_performed' => false,
		);
	}

	public static function create_job( $input ) {
		global $wpdb;
		if ( ! self::schema_ready() ) return new WP_Error( 'mad4b_content_job_schema_unavailable', 'ContentJob schema is not ready.' );
		$site_uuid = self::site_uuid();
		if ( '' === $site_uuid ) return new WP_Error( 'mad4b_content_job_site_identity_unavailable', 'Site Profile identity is unavailable.' );
		$subject = trim( wp_strip_all_tags( (string) $input['subject'] ) );
		if ( '' === $subject || strlen( $subject ) > 5000 ) return new WP_Error( 'mad4b_content_job_subject_invalid', 'ContentJob subject is missing or too long.' );
		$brand_id = sanitize_text_field( (string) $input['brand_id'] );
		$language = sanitize_key( (string) $input['language'] );
		$country = sanitize_key( (string) $input['country'] );
		$content_type = sanitize_key( (string) $input['content_type'] );
		if ( '' === $brand_id || '' === $language || '' === $country || '' === $content_type ) return new WP_Error( 'mad4b_content_job_identity_invalid', 'ContentJob brand/market/type identity is incomplete.' );
		$publish_at = self::normalize_datetime( isset( $input['desired_publish_at'] ) ? $input['desired_publish_at'] : '' );
		if ( is_wp_error( $publish_at ) ) return $publish_at;

		$job_id = strtolower( wp_generate_uuid4() );
		$now = gmdate( 'Y-m-d H:i:s' );
		$actor = self::actor();
		$t = MAD4B_SCP_Schema::tables();
		$data = array(
			'job_id' => $job_id,
			'tenant_id' => '',
			'site_uuid' => $site_uuid,
			'brand_id' => $brand_id,
			'subject' => $subject,
			'primary_keyword' => sanitize_text_field( isset( $input['primary_keyword'] ) ? (string) $input['primary_keyword'] : '' ),
			'language' => $language,
			'country' => $country,
			'content_type' => $content_type,
			'writer_profile_id' => sanitize_text_field( isset( $input['writer_profile_id'] ) ? (string) $input['writer_profile_id'] : '' ),
			'writer_profile_version' => sanitize_text_field( isset( $input['writer_profile_version'] ) ? (string) $input['writer_profile_version'] : '' ),
			'research_depth' => sanitize_key( isset( $input['research_depth'] ) ? (string) $input['research_depth'] : 'standard' ),
			'automation_level' => sanitize_key( isset( $input['automation_level'] ) ? (string) $input['automation_level'] : 'review_gated' ),
			'state' => 'NEW',
			'stage' => 'INTAKE',
			'target_post_type' => sanitize_key( isset( $input['target_post_type'] ) ? (string) $input['target_post_type'] : '' ),
			'target_post_id' => null,
			'desired_publish_at' => $publish_at,
			'current_artifact_id' => '',
			'quality_status' => 'unknown',
			'last_error_code' => '',
			'last_error_summary' => '',
			'job_revision' => 1,
			'created_by_nhi' => $actor['nhi'],
			'created_by_user' => get_current_user_id(),
			'created_at' => $now,
			'updated_at' => $now,
			'completed_at' => null,
			'cancelled_at' => null,
		);

		$wpdb->query( 'START TRANSACTION' );
		try {
			if ( false === $wpdb->insert( $t['content_jobs'], $data ) ) throw new RuntimeException( 'content_job_insert_failed:' . $wpdb->last_error );
			$event = self::append_event_locked( $job_id, 1, 'CREATED', '', 'NEW', '', 'INTAKE', (string) $input['reason'], $actor, '', '' );
			if ( is_wp_error( $event ) ) throw new RuntimeException( $event->get_error_code() );
			$audit = MAD4B_SCP_Audit::record( 'mad4b/content-job-create', array( 'job_id' => $job_id, 'site_uuid' => $site_uuid, 'revision' => 1 ), 'ok', true );
			if ( is_wp_error( $audit ) ) throw new RuntimeException( $audit->get_error_code() );
			if ( false === $wpdb->query( 'COMMIT' ) ) throw new RuntimeException( 'content_job_commit_failed' );
			MAD4B_SCP_Audit::transaction_committed();
		} catch ( Throwable $e ) {
			$wpdb->query( 'ROLLBACK' );
			MAD4B_SCP_Audit::transaction_rolled_back();
			return new WP_Error( 'mad4b_content_job_create_failed', 'Unable to create ContentJob.', array( 'cause' => $e->getMessage() ) );
		}
		return self::get_job( array( 'job_id' => $job_id ) );
	}

	public static function transition_job( $input ) {
		return self::transition(
			isset( $input['job_id'] ) ? $input['job_id'] : '',
			isset( $input['expected_revision'] ) ? $input['expected_revision'] : 0,
			isset( $input['state'] ) ? $input['state'] : '',
			isset( $input['stage'] ) ? $input['stage'] : '',
			isset( $input['reason'] ) ? $input['reason'] : '',
			isset( $input['plan_sha256'] ) ? $input['plan_sha256'] : '',
			isset( $input['artifact_id'] ) ? $input['artifact_id'] : ''
		);
	}

	public static function cancel_job( $input ) {
		$job = self::load_job( isset( $input['job_id'] ) ? $input['job_id'] : '', false );
		if ( is_wp_error( $job ) ) return $job;
		return self::transition(
			$job['job_id'],
			isset( $input['expected_revision'] ) ? $input['expected_revision'] : 0,
			'CANCELLED',
			$job['stage'],
			isset( $input['reason'] ) ? $input['reason'] : '',
			'',
			''
		);
	}

	private static function transition( $job_id, $expected_revision, $new_state, $new_stage, $reason, $plan_sha256, $artifact_id ) {
		global $wpdb;
		if ( ! self::schema_ready() ) return new WP_Error( 'mad4b_content_job_schema_unavailable', 'ContentJob schema is not ready.' );
		$job_id = strtolower( trim( (string) $job_id ) );
		if ( ! self::valid_uuid( $job_id ) ) return new WP_Error( 'mad4b_content_job_id_invalid', 'ContentJob ID is invalid.' );
		$expected_revision = absint( $expected_revision );
		$new_state = strtoupper( sanitize_key( (string) $new_state ) );
		$new_stage = strtoupper( sanitize_key( (string) $new_stage ) );
		$reason = trim( sanitize_text_field( (string) $reason ) );
		$plan_sha256 = strtolower( trim( (string) $plan_sha256 ) );
		$artifact_id = sanitize_text_field( (string) $artifact_id );
		if ( ! in_array( $new_state, self::states(), true ) || ! in_array( $new_stage, self::stages(), true ) ) return new WP_Error( 'mad4b_content_job_transition_invalid', 'Requested state/stage is invalid.' );
		if ( '' !== $plan_sha256 && ! preg_match( '/^[a-f0-9]{64}$/', $plan_sha256 ) ) return new WP_Error( 'mad4b_content_job_plan_invalid', 'Plan SHA is invalid.' );
		if ( strlen( $reason ) < 3 ) return new WP_Error( 'mad4b_content_job_reason_required', 'Transition reason is required.' );

		$t = MAD4B_SCP_Schema::tables();
		$wpdb->query( 'START TRANSACTION' );
		try {
			$row = self::load_job( $job_id, true );
			if ( is_wp_error( $row ) ) throw new RuntimeException( $row->get_error_code() );
			if ( $expected_revision !== (int) $row['job_revision'] ) throw new RuntimeException( 'content_job_revision_conflict' );
			if ( in_array( $row['state'], array( 'COMPLETED', 'CANCELLED' ), true ) ) throw new RuntimeException( 'content_job_terminal_immutable' );
			$same_state = hash_equals( (string) $row['state'], $new_state );
			$same_stage = hash_equals( (string) $row['stage'], $new_stage );
			if ( $same_state && $same_stage ) throw new RuntimeException( 'content_job_noop_transition' );
			$allowed = self::transition_map();
			if ( ! $same_state && ( ! isset( $allowed[ $row['state'] ] ) || ! in_array( $new_state, $allowed[ $row['state'] ], true ) ) ) {
				throw new RuntimeException( 'content_job_state_transition_denied' );
			}

			$revision = $expected_revision + 1;
			$now = gmdate( 'Y-m-d H:i:s' );
			$update = array(
				'state' => $new_state,
				'stage' => $new_stage,
				'current_artifact_id' => '' !== $artifact_id ? $artifact_id : (string) $row['current_artifact_id'],
				'job_revision' => $revision,
				'updated_at' => $now,
				'completed_at' => 'COMPLETED' === $new_state ? $now : $row['completed_at'],
				'cancelled_at' => 'CANCELLED' === $new_state ? $now : $row['cancelled_at'],
			);
			$changed = $wpdb->update(
				$t['content_jobs'],
				$update,
				array( 'id' => (int) $row['id'], 'job_revision' => $expected_revision ),
				null,
				array( '%d', '%d' )
			);
			if ( 1 !== (int) $changed ) throw new RuntimeException( 'content_job_transition_cas_failed' );
			$actor = self::actor();
			$event = self::append_event_locked(
				$job_id,
				$revision,
				'CANCELLED' === $new_state ? 'CANCELLED' : 'TRANSITIONED',
				(string) $row['state'],
				$new_state,
				(string) $row['stage'],
				$new_stage,
				$reason,
				$actor,
				$plan_sha256,
				$artifact_id
			);
			if ( is_wp_error( $event ) ) throw new RuntimeException( $event->get_error_code() );
			$audit = MAD4B_SCP_Audit::record( 'mad4b/content-job-transition', array( 'job_id' => $job_id, 'from_revision' => $expected_revision, 'to_revision' => $revision, 'state' => $new_state, 'stage' => $new_stage ), 'ok', true );
			if ( is_wp_error( $audit ) ) throw new RuntimeException( $audit->get_error_code() );
			if ( false === $wpdb->query( 'COMMIT' ) ) throw new RuntimeException( 'content_job_transition_commit_failed' );
			MAD4B_SCP_Audit::transaction_committed();
		} catch ( Throwable $e ) {
			$wpdb->query( 'ROLLBACK' );
			MAD4B_SCP_Audit::transaction_rolled_back();
			$code = $e->getMessage();
			if ( 'content_job_revision_conflict' === $code ) return new WP_Error( 'mad4b_content_job_revision_conflict', 'ContentJob revision changed since the requested transition.' );
			if ( 'content_job_terminal_immutable' === $code ) return new WP_Error( 'mad4b_content_job_terminal_immutable', 'Completed or cancelled ContentJob history is immutable.' );
			if ( 'content_job_state_transition_denied' === $code ) return new WP_Error( 'mad4b_content_job_state_transition_denied', 'Requested lifecycle transition is not allowed.' );
			if ( 'content_job_noop_transition' === $code ) return new WP_Error( 'mad4b_content_job_noop_transition', 'State and stage are unchanged.' );
			return new WP_Error( 'mad4b_content_job_transition_failed', 'Unable to transition ContentJob.', array( 'cause' => $code ) );
		}
		return self::get_job( array( 'job_id' => $job_id ) );
	}

	private static function load_job( $job_id, $for_update ) {
		global $wpdb;
		if ( ! self::valid_uuid( $job_id ) ) return new WP_Error( 'mad4b_content_job_id_invalid', 'ContentJob ID is invalid.' );
		$site_uuid = self::site_uuid();
		if ( '' === $site_uuid ) return new WP_Error( 'mad4b_content_job_site_identity_unavailable', 'Site Profile identity is unavailable.' );
		$t = MAD4B_SCP_Schema::tables();
		$sql = $wpdb->prepare( "SELECT * FROM {$t['content_jobs']} WHERE job_id=%s AND site_uuid=%s LIMIT 1" . ( $for_update ? ' FOR UPDATE' : '' ), $job_id, $site_uuid );
		$row = $wpdb->get_row( $sql, ARRAY_A );
		return is_array( $row ) ? $row : new WP_Error( 'mad4b_content_job_missing', 'ContentJob was not found for this site.' );
	}

	private static function append_event_locked( $job_id, $sequence, $event_type, $previous_state, $new_state, $previous_stage, $new_stage, $reason, array $actor, $plan_sha256, $artifact_id ) {
		global $wpdb;
		$t = MAD4B_SCP_Schema::tables();
		$previous_hash = (string) $wpdb->get_var(
			$wpdb->prepare( "SELECT entry_sha256 FROM {$t['content_job_events']} WHERE job_id=%s ORDER BY sequence DESC LIMIT 1", $job_id )
		);
		$event_id = strtolower( wp_generate_uuid4() );
		$now = gmdate( 'Y-m-d H:i:s' );
		$material = array(
			'contract' => self::EVENT_CONTRACT,
			'event_id' => $event_id,
			'job_id' => $job_id,
			'sequence' => (int) $sequence,
			'event_type' => sanitize_key( (string) $event_type ),
			'previous_state' => (string) $previous_state,
			'new_state' => (string) $new_state,
			'previous_stage' => (string) $previous_stage,
			'new_stage' => (string) $new_stage,
			'reason_code' => substr( sanitize_key( (string) $reason ), 0, 64 ),
			'actor_type' => sanitize_key( (string) $actor['type'] ),
			'actor_id' => substr( sanitize_text_field( (string) $actor['id'] ), 0, 191 ),
			'plan_sha256' => (string) $plan_sha256,
			'artifact_id' => substr( sanitize_text_field( (string) $artifact_id ), 0, 191 ),
			'provider_id' => '',
			'previous_entry_sha256' => $previous_hash,
			'created_at' => $now,
		);
		$entry_hash = hash( 'sha256', self::stable_json( $material ) );
		$ok = $wpdb->insert(
			$t['content_job_events'],
			array(
				'event_id' => $event_id,
				'job_id' => $job_id,
				'sequence' => (int) $sequence,
				'event_type' => $material['event_type'],
				'previous_state' => $previous_state,
				'new_state' => $new_state,
				'previous_stage' => $previous_stage,
				'new_stage' => $new_stage,
				'reason_code' => $material['reason_code'],
				'correlation_id' => '',
				'actor_type' => $material['actor_type'],
				'actor_id' => $material['actor_id'],
				'plan_sha256' => $plan_sha256,
				'artifact_id' => $artifact_id,
				'provider_id' => '',
				'metadata_json' => wp_json_encode( array( 'reason' => $reason ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ),
				'previous_entry_sha256' => $previous_hash,
				'entry_sha256' => $entry_hash,
				'created_at' => $now,
			)
		);
		if ( false === $ok ) return new WP_Error( 'mad4b_content_job_event_insert_failed', 'Unable to append ContentJob event.', array( 'db_error' => $wpdb->last_error ) );
		return array( 'event_id' => $event_id, 'entry_sha256' => $entry_hash, 'sequence' => (int) $sequence );
	}

	private static function stable_json( $value ) {
		$value = self::sort_value( $value );
		$json = wp_json_encode( $value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		return false === $json ? '' : $json;
	}

	private static function sort_value( $value ) {
		if ( ! is_array( $value ) ) return $value;
		$is_list = array_keys( $value ) === range( 0, count( $value ) - 1 );
		if ( ! $is_list ) ksort( $value, SORT_STRING );
		foreach ( $value as $key => $item ) $value[ $key ] = self::sort_value( $item );
		return $value;
	}
}

MAD4B_SCP_Content_Jobs::boot();
, 'default' => '' ),
				'research_depth' => array( 'type' => 'string', 'maxLength' => 32, 'default' => 'standard' ),
				'automation_level' => array( 'type' => 'string', 'maxLength' => 32, 'default' => 'review_gated' ),
				'target_post_type' => array( 'type' => 'string', 'maxLength' => 64, 'default' => '' ),
				'desired_publish_at' => array( 'type' => 'string', 'maxLength' => 32, 'default' => '' ),
				'reason' => array( 'type' => 'string', 'minLength' => 3, 'maxLength' => 500 ),
			),
			array( 'brand_id', 'subject', 'language', 'country', 'content_type', 'reason' ),
			false
		);
		self::register(
			'mad4b/content-job-transition',
			'Transition Content Job',
			'transition_job',
			array(
				'job_id' => array( 'type' => 'string', 'minLength' => 36, 'maxLength' => 36 ),
				'expected_revision' => array( 'type' => 'integer', 'minimum' => 1 ),
				'state' => array( 'type' => 'string', 'enum' => self::states() ),
				'stage' => array( 'type' => 'string', 'enum' => self::stages() ),
				'reason' => array( 'type' => 'string', 'minLength' => 3, 'maxLength' => 500 ),
				'plan_sha256' => array( 'type' => 'string', 'pattern' => '^[a-f0-9]{64}$', 'default' => '' ),
				'artifact_id' => array( 'type' => 'string', 'maxLength' => 191, 'default' => '' ),
			),
			array( 'job_id', 'expected_revision', 'state', 'stage', 'reason' ),
			false
		);
		self::register(
			'mad4b/content-job-cancel',
			'Cancel Content Job',
			'cancel_job',
			array(
				'job_id' => array( 'type' => 'string', 'minLength' => 36, 'maxLength' => 36 ),
				'expected_revision' => array( 'type' => 'integer', 'minimum' => 1 ),
				'reason' => array( 'type' => 'string', 'minLength' => 3, 'maxLength' => 500 ),
			),
			array( 'job_id', 'expected_revision', 'reason' ),
			false
		);
	}

	private static function register( $name, $label, $method, array $properties, array $required, $readonly ) {
		if ( function_exists( 'wp_has_ability' ) && wp_has_ability( $name ) ) return;
		wp_register_ability(
			$name,
			array(
				'label' => $label,
				'description' => $label . ' through the governed Feature 007 ContentJob aggregate.',
				'category' => $readonly ? 'mad4b-read' : 'mad4b-write',
				'execute_callback' => array( __CLASS__, $method ),
				'permission_callback' => $readonly ? array( 'MAD4B_SCP_Policy', 'can_read' ) : array( 'MAD4B_SCP_Policy', 'can_mutate' ),
				'input_schema' => array(
					'type' => 'object',
					'properties' => $properties,
					'required' => $required,
					'additionalProperties' => false,
				),
				'output_schema' => array( 'type' => 'object', 'additionalProperties' => true ),
				'meta' => array(
					'public' => false,
					'show_in_rest' => false,
					'mcp' => array( 'public' => false, 'type' => 'tool', 'surface' => $readonly ? 'read' : 'write' ),
					'annotations' => array(
						'readonly' => (bool) $readonly,
						'destructive' => ! $readonly,
						'idempotent' => $readonly,
					),
				),
			)
		);
	}

	private static function schema_ready() {
		return class_exists( 'MAD4B_SCP_Schema' ) && MAD4B_SCP_Schema::critical_ready();
	}

	private static function site_uuid() {
		$uuid = class_exists( 'MAD4B_SCP_Site_Profile' ) ? strtolower( trim( (string) MAD4B_SCP_Site_Profile::site_uuid() ) ) : '';
		return preg_match( '/^[a-f0-9-]{36}$/', $uuid ) ? $uuid : '';
	}

	private static function valid_uuid( $value ) {
		return is_string( $value ) && 1 === preg_match( '/^[a-f0-9-]{36}$/', strtolower( trim( $value ) ) );
	}

	private static function actor() {
		$actor = array( 'type' => 'wordpress_user', 'id' => (string) get_current_user_id(), 'nhi' => '' );
		if ( class_exists( 'MAD4B_SCP_Identity_Context' ) && class_exists( 'MAD4B_SCP_Agent_Registry' ) ) {
			$identity = MAD4B_SCP_Identity_Context::current();
			if ( ! is_wp_error( $identity ) ) {
				$agent = MAD4B_SCP_Agent_Registry::resolve_agent( $identity );
				if ( ! is_wp_error( $agent ) && ! empty( $agent['public_id'] ) ) {
					$actor['type'] = 'nhi';
					$actor['id'] = strtolower( (string) $agent['public_id'] );
					$actor['nhi'] = $actor['id'];
				}
			}
		}
		return $actor;
	}

	private static function normalize_datetime( $value ) {
		$value = trim( (string) $value );
		if ( '' === $value ) return null;
		$ts = strtotime( $value );
		if ( false === $ts ) return new WP_Error( 'mad4b_content_job_datetime_invalid', 'Invalid desired publish datetime.' );
		return gmdate( 'Y-m-d H:i:s', $ts );
	}

	private static function row( $row ) {
		if ( ! is_array( $row ) ) return null;
		foreach ( array( 'id', 'target_post_id', 'job_revision', 'created_by_user' ) as $key ) {
			if ( array_key_exists( $key, $row ) && null !== $row[ $key ] ) $row[ $key ] = (int) $row[ $key ];
		}
		return $row;
	}

	public static function list_jobs( $input ) {
		global $wpdb;
		if ( ! self::schema_ready() ) return new WP_Error( 'mad4b_content_job_schema_unavailable', 'ContentJob schema is not ready.' );
		$site_uuid = self::site_uuid();
		if ( '' === $site_uuid ) return new WP_Error( 'mad4b_content_job_site_identity_unavailable', 'Site Profile identity is unavailable.' );
		$t = MAD4B_SCP_Schema::tables();
		$where = array( 'site_uuid=%s' );
		$args = array( $site_uuid );
		$state = isset( $input['state'] ) ? strtoupper( sanitize_key( (string) $input['state'] ) ) : '';
		$stage = isset( $input['stage'] ) ? strtoupper( sanitize_key( (string) $input['stage'] ) ) : '';
		if ( '' !== $state ) { $where[] = 'state=%s'; $args[] = $state; }
		if ( '' !== $stage ) { $where[] = 'stage=%s'; $args[] = $stage; }
		$limit = isset( $input['limit'] ) ? max( 1, min( 200, absint( $input['limit'] ) ) ) : 50;
		$args[] = $limit;
		$sql = "SELECT * FROM {$t['content_jobs']} WHERE " . implode( ' AND ', $where ) . ' ORDER BY updated_at DESC,id DESC LIMIT %d';
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $args ), ARRAY_A );
		return array(
			'contract' => self::CONTRACT,
			'items' => array_values( array_filter( array_map( array( __CLASS__, 'row' ), is_array( $rows ) ? $rows : array() ) ) ),
			'count' => is_array( $rows ) ? count( $rows ) : 0,
			'mutation_performed' => false,
		);
	}

	public static function get_job( $input ) {
		$job_id = isset( $input['job_id'] ) ? strtolower( trim( (string) $input['job_id'] ) ) : '';
		$row = self::load_job( $job_id, false );
		if ( is_wp_error( $row ) ) return $row;
		return array( 'contract' => self::CONTRACT, 'job' => self::row( $row ), 'mutation_performed' => false );
	}

	public static function get_events( $input ) {
		global $wpdb;
		$job_id = isset( $input['job_id'] ) ? strtolower( trim( (string) $input['job_id'] ) ) : '';
		$job = self::load_job( $job_id, false );
		if ( is_wp_error( $job ) ) return $job;
		$limit = isset( $input['limit'] ) ? max( 1, min( 500, absint( $input['limit'] ) ) ) : 100;
		$t = MAD4B_SCP_Schema::tables();
		$rows = $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$t['content_job_events']} WHERE job_id=%s ORDER BY sequence ASC LIMIT %d", $job_id, $limit ),
			ARRAY_A
		);
		return array(
			'contract' => self::EVENT_CONTRACT,
			'job_id' => $job_id,
			'items' => is_array( $rows ) ? $rows : array(),
			'count' => is_array( $rows ) ? count( $rows ) : 0,
			'mutation_performed' => false,
		);
	}

	public static function create_job( $input ) {
		global $wpdb;
		if ( ! self::schema_ready() ) return new WP_Error( 'mad4b_content_job_schema_unavailable', 'ContentJob schema is not ready.' );
		$site_uuid = self::site_uuid();
		if ( '' === $site_uuid ) return new WP_Error( 'mad4b_content_job_site_identity_unavailable', 'Site Profile identity is unavailable.' );
		$subject = trim( wp_strip_all_tags( (string) $input['subject'] ) );
		if ( '' === $subject || strlen( $subject ) > 5000 ) return new WP_Error( 'mad4b_content_job_subject_invalid', 'ContentJob subject is missing or too long.' );
		$brand_id = sanitize_text_field( (string) $input['brand_id'] );
		$language = sanitize_key( (string) $input['language'] );
		$country = sanitize_key( (string) $input['country'] );
		$content_type = sanitize_key( (string) $input['content_type'] );
		if ( '' === $brand_id || '' === $language || '' === $country || '' === $content_type ) return new WP_Error( 'mad4b_content_job_identity_invalid', 'ContentJob brand/market/type identity is incomplete.' );
		$publish_at = self::normalize_datetime( isset( $input['desired_publish_at'] ) ? $input['desired_publish_at'] : '' );
		if ( is_wp_error( $publish_at ) ) return $publish_at;
		$writer_profile_id = strtolower( trim( (string) ( $input['writer_profile_id'] ?? '' ) ) );
		$writer_profile_version = strtolower( trim( (string) ( $input['writer_profile_version'] ?? '' ) ) );
		if ( ( '' === $writer_profile_id ) xor ( '' === $writer_profile_version ) ) {
			return new WP_Error( 'mad4b_writer_profile_binding_incomplete', 'WriterProfile identity and version must be supplied together.' );
		}
		if ( '' !== $writer_profile_id
			&& ( 1 !== preg_match( '/^[a-f0-9]{64}$/', $writer_profile_id )
				|| 1 !== preg_match( '/^[a-f0-9]{64}$/', $writer_profile_version ) ) ) {
			return new WP_Error( 'mad4b_writer_profile_identity_invalid', 'WriterProfile identity and version must be exact SHA-256 identities.' );
		}

		$job_id = strtolower( wp_generate_uuid4() );
		$now = gmdate( 'Y-m-d H:i:s' );
		$actor = self::actor();
		$t = MAD4B_SCP_Schema::tables();
		$data = array(
			'job_id' => $job_id,
			'tenant_id' => '',
			'site_uuid' => $site_uuid,
			'brand_id' => $brand_id,
			'subject' => $subject,
			'primary_keyword' => sanitize_text_field( isset( $input['primary_keyword'] ) ? (string) $input['primary_keyword'] : '' ),
			'language' => $language,
			'country' => $country,
			'content_type' => $content_type,
			'writer_profile_id' => $writer_profile_id,
			'writer_profile_version' => $writer_profile_version,
			'research_depth' => sanitize_key( isset( $input['research_depth'] ) ? (string) $input['research_depth'] : 'standard' ),
			'automation_level' => sanitize_key( isset( $input['automation_level'] ) ? (string) $input['automation_level'] : 'review_gated' ),
			'state' => 'NEW',
			'stage' => 'INTAKE',
			'target_post_type' => sanitize_key( isset( $input['target_post_type'] ) ? (string) $input['target_post_type'] : '' ),
			'target_post_id' => null,
			'desired_publish_at' => $publish_at,
			'current_artifact_id' => '',
			'quality_status' => 'unknown',
			'last_error_code' => '',
			'last_error_summary' => '',
			'job_revision' => 1,
			'created_by_nhi' => $actor['nhi'],
			'created_by_user' => get_current_user_id(),
			'created_at' => $now,
			'updated_at' => $now,
			'completed_at' => null,
			'cancelled_at' => null,
		);

		$wpdb->query( 'START TRANSACTION' );
		try {
			if ( false === $wpdb->insert( $t['content_jobs'], $data ) ) throw new RuntimeException( 'content_job_insert_failed:' . $wpdb->last_error );
			$event = self::append_event_locked( $job_id, 1, 'CREATED', '', 'NEW', '', 'INTAKE', (string) $input['reason'], $actor, '', '' );
			if ( is_wp_error( $event ) ) throw new RuntimeException( $event->get_error_code() );
			$audit = MAD4B_SCP_Audit::record( 'mad4b/content-job-create', array( 'job_id' => $job_id, 'site_uuid' => $site_uuid, 'revision' => 1 ), 'ok', true );
			if ( is_wp_error( $audit ) ) throw new RuntimeException( $audit->get_error_code() );
			if ( false === $wpdb->query( 'COMMIT' ) ) throw new RuntimeException( 'content_job_commit_failed' );
			MAD4B_SCP_Audit::transaction_committed();
		} catch ( Throwable $e ) {
			$wpdb->query( 'ROLLBACK' );
			MAD4B_SCP_Audit::transaction_rolled_back();
			return new WP_Error( 'mad4b_content_job_create_failed', 'Unable to create ContentJob.', array( 'cause' => $e->getMessage() ) );
		}
		return self::get_job( array( 'job_id' => $job_id ) );
	}

	public static function transition_job( $input ) {
		return self::transition(
			isset( $input['job_id'] ) ? $input['job_id'] : '',
			isset( $input['expected_revision'] ) ? $input['expected_revision'] : 0,
			isset( $input['state'] ) ? $input['state'] : '',
			isset( $input['stage'] ) ? $input['stage'] : '',
			isset( $input['reason'] ) ? $input['reason'] : '',
			isset( $input['plan_sha256'] ) ? $input['plan_sha256'] : '',
			isset( $input['artifact_id'] ) ? $input['artifact_id'] : ''
		);
	}

	public static function cancel_job( $input ) {
		$job = self::load_job( isset( $input['job_id'] ) ? $input['job_id'] : '', false );
		if ( is_wp_error( $job ) ) return $job;
		return self::transition(
			$job['job_id'],
			isset( $input['expected_revision'] ) ? $input['expected_revision'] : 0,
			'CANCELLED',
			$job['stage'],
			isset( $input['reason'] ) ? $input['reason'] : '',
			'',
			''
		);
	}

	private static function transition( $job_id, $expected_revision, $new_state, $new_stage, $reason, $plan_sha256, $artifact_id ) {
		global $wpdb;
		if ( ! self::schema_ready() ) return new WP_Error( 'mad4b_content_job_schema_unavailable', 'ContentJob schema is not ready.' );
		$job_id = strtolower( trim( (string) $job_id ) );
		if ( ! self::valid_uuid( $job_id ) ) return new WP_Error( 'mad4b_content_job_id_invalid', 'ContentJob ID is invalid.' );
		$expected_revision = absint( $expected_revision );
		$new_state = strtoupper( sanitize_key( (string) $new_state ) );
		$new_stage = strtoupper( sanitize_key( (string) $new_stage ) );
		$reason = trim( sanitize_text_field( (string) $reason ) );
		$plan_sha256 = strtolower( trim( (string) $plan_sha256 ) );
		$artifact_id = sanitize_text_field( (string) $artifact_id );
		if ( ! in_array( $new_state, self::states(), true ) || ! in_array( $new_stage, self::stages(), true ) ) return new WP_Error( 'mad4b_content_job_transition_invalid', 'Requested state/stage is invalid.' );
		if ( '' !== $plan_sha256 && ! preg_match( '/^[a-f0-9]{64}$/', $plan_sha256 ) ) return new WP_Error( 'mad4b_content_job_plan_invalid', 'Plan SHA is invalid.' );
		if ( strlen( $reason ) < 3 ) return new WP_Error( 'mad4b_content_job_reason_required', 'Transition reason is required.' );

		$t = MAD4B_SCP_Schema::tables();
		$wpdb->query( 'START TRANSACTION' );
		try {
			$row = self::load_job( $job_id, true );
			if ( is_wp_error( $row ) ) throw new RuntimeException( $row->get_error_code() );
			if ( $expected_revision !== (int) $row['job_revision'] ) throw new RuntimeException( 'content_job_revision_conflict' );
			if ( in_array( $row['state'], array( 'COMPLETED', 'CANCELLED' ), true ) ) throw new RuntimeException( 'content_job_terminal_immutable' );
			$same_state = hash_equals( (string) $row['state'], $new_state );
			$same_stage = hash_equals( (string) $row['stage'], $new_stage );
			if ( $same_state && $same_stage ) throw new RuntimeException( 'content_job_noop_transition' );
			$allowed = self::transition_map();
			if ( ! $same_state && ( ! isset( $allowed[ $row['state'] ] ) || ! in_array( $new_state, $allowed[ $row['state'] ], true ) ) ) {
				throw new RuntimeException( 'content_job_state_transition_denied' );
			}

			$revision = $expected_revision + 1;
			$now = gmdate( 'Y-m-d H:i:s' );
			$update = array(
				'state' => $new_state,
				'stage' => $new_stage,
				'current_artifact_id' => '' !== $artifact_id ? $artifact_id : (string) $row['current_artifact_id'],
				'job_revision' => $revision,
				'updated_at' => $now,
				'completed_at' => 'COMPLETED' === $new_state ? $now : $row['completed_at'],
				'cancelled_at' => 'CANCELLED' === $new_state ? $now : $row['cancelled_at'],
			);
			$changed = $wpdb->update(
				$t['content_jobs'],
				$update,
				array( 'id' => (int) $row['id'], 'job_revision' => $expected_revision ),
				null,
				array( '%d', '%d' )
			);
			if ( 1 !== (int) $changed ) throw new RuntimeException( 'content_job_transition_cas_failed' );
			$actor = self::actor();
			$event = self::append_event_locked(
				$job_id,
				$revision,
				'CANCELLED' === $new_state ? 'CANCELLED' : 'TRANSITIONED',
				(string) $row['state'],
				$new_state,
				(string) $row['stage'],
				$new_stage,
				$reason,
				$actor,
				$plan_sha256,
				$artifact_id
			);
			if ( is_wp_error( $event ) ) throw new RuntimeException( $event->get_error_code() );
			$audit = MAD4B_SCP_Audit::record( 'mad4b/content-job-transition', array( 'job_id' => $job_id, 'from_revision' => $expected_revision, 'to_revision' => $revision, 'state' => $new_state, 'stage' => $new_stage ), 'ok', true );
			if ( is_wp_error( $audit ) ) throw new RuntimeException( $audit->get_error_code() );
			if ( false === $wpdb->query( 'COMMIT' ) ) throw new RuntimeException( 'content_job_transition_commit_failed' );
			MAD4B_SCP_Audit::transaction_committed();
		} catch ( Throwable $e ) {
			$wpdb->query( 'ROLLBACK' );
			MAD4B_SCP_Audit::transaction_rolled_back();
			$code = $e->getMessage();
			if ( 'content_job_revision_conflict' === $code ) return new WP_Error( 'mad4b_content_job_revision_conflict', 'ContentJob revision changed since the requested transition.' );
			if ( 'content_job_terminal_immutable' === $code ) return new WP_Error( 'mad4b_content_job_terminal_immutable', 'Completed or cancelled ContentJob history is immutable.' );
			if ( 'content_job_state_transition_denied' === $code ) return new WP_Error( 'mad4b_content_job_state_transition_denied', 'Requested lifecycle transition is not allowed.' );
			if ( 'content_job_noop_transition' === $code ) return new WP_Error( 'mad4b_content_job_noop_transition', 'State and stage are unchanged.' );
			return new WP_Error( 'mad4b_content_job_transition_failed', 'Unable to transition ContentJob.', array( 'cause' => $code ) );
		}
		return self::get_job( array( 'job_id' => $job_id ) );
	}

	private static function load_job( $job_id, $for_update ) {
		global $wpdb;
		if ( ! self::valid_uuid( $job_id ) ) return new WP_Error( 'mad4b_content_job_id_invalid', 'ContentJob ID is invalid.' );
		$site_uuid = self::site_uuid();
		if ( '' === $site_uuid ) return new WP_Error( 'mad4b_content_job_site_identity_unavailable', 'Site Profile identity is unavailable.' );
		$t = MAD4B_SCP_Schema::tables();
		$sql = $wpdb->prepare( "SELECT * FROM {$t['content_jobs']} WHERE job_id=%s AND site_uuid=%s LIMIT 1" . ( $for_update ? ' FOR UPDATE' : '' ), $job_id, $site_uuid );
		$row = $wpdb->get_row( $sql, ARRAY_A );
		return is_array( $row ) ? $row : new WP_Error( 'mad4b_content_job_missing', 'ContentJob was not found for this site.' );
	}

	private static function append_event_locked( $job_id, $sequence, $event_type, $previous_state, $new_state, $previous_stage, $new_stage, $reason, array $actor, $plan_sha256, $artifact_id ) {
		global $wpdb;
		$t = MAD4B_SCP_Schema::tables();
		$previous_hash = (string) $wpdb->get_var(
			$wpdb->prepare( "SELECT entry_sha256 FROM {$t['content_job_events']} WHERE job_id=%s ORDER BY sequence DESC LIMIT 1", $job_id )
		);
		$event_id = strtolower( wp_generate_uuid4() );
		$now = gmdate( 'Y-m-d H:i:s' );
		$material = array(
			'contract' => self::EVENT_CONTRACT,
			'event_id' => $event_id,
			'job_id' => $job_id,
			'sequence' => (int) $sequence,
			'event_type' => sanitize_key( (string) $event_type ),
			'previous_state' => (string) $previous_state,
			'new_state' => (string) $new_state,
			'previous_stage' => (string) $previous_stage,
			'new_stage' => (string) $new_stage,
			'reason_code' => substr( sanitize_key( (string) $reason ), 0, 64 ),
			'actor_type' => sanitize_key( (string) $actor['type'] ),
			'actor_id' => substr( sanitize_text_field( (string) $actor['id'] ), 0, 191 ),
			'plan_sha256' => (string) $plan_sha256,
			'artifact_id' => substr( sanitize_text_field( (string) $artifact_id ), 0, 191 ),
			'provider_id' => '',
			'previous_entry_sha256' => $previous_hash,
			'created_at' => $now,
		);
		$entry_hash = hash( 'sha256', self::stable_json( $material ) );
		$ok = $wpdb->insert(
			$t['content_job_events'],
			array(
				'event_id' => $event_id,
				'job_id' => $job_id,
				'sequence' => (int) $sequence,
				'event_type' => $material['event_type'],
				'previous_state' => $previous_state,
				'new_state' => $new_state,
				'previous_stage' => $previous_stage,
				'new_stage' => $new_stage,
				'reason_code' => $material['reason_code'],
				'correlation_id' => '',
				'actor_type' => $material['actor_type'],
				'actor_id' => $material['actor_id'],
				'plan_sha256' => $plan_sha256,
				'artifact_id' => $artifact_id,
				'provider_id' => '',
				'metadata_json' => wp_json_encode( array( 'reason' => $reason ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ),
				'previous_entry_sha256' => $previous_hash,
				'entry_sha256' => $entry_hash,
				'created_at' => $now,
			)
		);
		if ( false === $ok ) return new WP_Error( 'mad4b_content_job_event_insert_failed', 'Unable to append ContentJob event.', array( 'db_error' => $wpdb->last_error ) );
		return array( 'event_id' => $event_id, 'entry_sha256' => $entry_hash, 'sequence' => (int) $sequence );
	}

	private static function stable_json( $value ) {
		$value = self::sort_value( $value );
		$json = wp_json_encode( $value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		return false === $json ? '' : $json;
	}

	private static function sort_value( $value ) {
		if ( ! is_array( $value ) ) return $value;
		$is_list = array_keys( $value ) === range( 0, count( $value ) - 1 );
		if ( ! $is_list ) ksort( $value, SORT_STRING );
		foreach ( $value as $key => $item ) $value[ $key ] = self::sort_value( $item );
		return $value;
	}
}

MAD4B_SCP_Content_Jobs::boot();
, 'maxLength' => 64, 'default' => '' ),
				'writer_profile_version' => array( 'type' => 'string', 'pattern' => '^(|[a-f0-9]{64})
				'research_depth' => array( 'type' => 'string', 'maxLength' => 32, 'default' => 'standard' ),
				'automation_level' => array( 'type' => 'string', 'maxLength' => 32, 'default' => 'review_gated' ),
				'target_post_type' => array( 'type' => 'string', 'maxLength' => 64, 'default' => '' ),
				'desired_publish_at' => array( 'type' => 'string', 'maxLength' => 32, 'default' => '' ),
				'reason' => array( 'type' => 'string', 'minLength' => 3, 'maxLength' => 500 ),
			),
			array( 'brand_id', 'subject', 'language', 'country', 'content_type', 'reason' ),
			false
		);
		self::register(
			'mad4b/content-job-transition',
			'Transition Content Job',
			'transition_job',
			array(
				'job_id' => array( 'type' => 'string', 'minLength' => 36, 'maxLength' => 36 ),
				'expected_revision' => array( 'type' => 'integer', 'minimum' => 1 ),
				'state' => array( 'type' => 'string', 'enum' => self::states() ),
				'stage' => array( 'type' => 'string', 'enum' => self::stages() ),
				'reason' => array( 'type' => 'string', 'minLength' => 3, 'maxLength' => 500 ),
				'plan_sha256' => array( 'type' => 'string', 'pattern' => '^[a-f0-9]{64}$', 'default' => '' ),
				'artifact_id' => array( 'type' => 'string', 'maxLength' => 191, 'default' => '' ),
			),
			array( 'job_id', 'expected_revision', 'state', 'stage', 'reason' ),
			false
		);
		self::register(
			'mad4b/content-job-cancel',
			'Cancel Content Job',
			'cancel_job',
			array(
				'job_id' => array( 'type' => 'string', 'minLength' => 36, 'maxLength' => 36 ),
				'expected_revision' => array( 'type' => 'integer', 'minimum' => 1 ),
				'reason' => array( 'type' => 'string', 'minLength' => 3, 'maxLength' => 500 ),
			),
			array( 'job_id', 'expected_revision', 'reason' ),
			false
		);
	}

	private static function register( $name, $label, $method, array $properties, array $required, $readonly ) {
		if ( function_exists( 'wp_has_ability' ) && wp_has_ability( $name ) ) return;
		wp_register_ability(
			$name,
			array(
				'label' => $label,
				'description' => $label . ' through the governed Feature 007 ContentJob aggregate.',
				'category' => $readonly ? 'mad4b-read' : 'mad4b-write',
				'execute_callback' => array( __CLASS__, $method ),
				'permission_callback' => $readonly ? array( 'MAD4B_SCP_Policy', 'can_read' ) : array( 'MAD4B_SCP_Policy', 'can_mutate' ),
				'input_schema' => array(
					'type' => 'object',
					'properties' => $properties,
					'required' => $required,
					'additionalProperties' => false,
				),
				'output_schema' => array( 'type' => 'object', 'additionalProperties' => true ),
				'meta' => array(
					'public' => false,
					'show_in_rest' => false,
					'mcp' => array( 'public' => false, 'type' => 'tool', 'surface' => $readonly ? 'read' : 'write' ),
					'annotations' => array(
						'readonly' => (bool) $readonly,
						'destructive' => ! $readonly,
						'idempotent' => $readonly,
					),
				),
			)
		);
	}

	private static function schema_ready() {
		return class_exists( 'MAD4B_SCP_Schema' ) && MAD4B_SCP_Schema::critical_ready();
	}

	private static function site_uuid() {
		$uuid = class_exists( 'MAD4B_SCP_Site_Profile' ) ? strtolower( trim( (string) MAD4B_SCP_Site_Profile::site_uuid() ) ) : '';
		return preg_match( '/^[a-f0-9-]{36}$/', $uuid ) ? $uuid : '';
	}

	private static function valid_uuid( $value ) {
		return is_string( $value ) && 1 === preg_match( '/^[a-f0-9-]{36}$/', strtolower( trim( $value ) ) );
	}

	private static function actor() {
		$actor = array( 'type' => 'wordpress_user', 'id' => (string) get_current_user_id(), 'nhi' => '' );
		if ( class_exists( 'MAD4B_SCP_Identity_Context' ) && class_exists( 'MAD4B_SCP_Agent_Registry' ) ) {
			$identity = MAD4B_SCP_Identity_Context::current();
			if ( ! is_wp_error( $identity ) ) {
				$agent = MAD4B_SCP_Agent_Registry::resolve_agent( $identity );
				if ( ! is_wp_error( $agent ) && ! empty( $agent['public_id'] ) ) {
					$actor['type'] = 'nhi';
					$actor['id'] = strtolower( (string) $agent['public_id'] );
					$actor['nhi'] = $actor['id'];
				}
			}
		}
		return $actor;
	}

	private static function normalize_datetime( $value ) {
		$value = trim( (string) $value );
		if ( '' === $value ) return null;
		$ts = strtotime( $value );
		if ( false === $ts ) return new WP_Error( 'mad4b_content_job_datetime_invalid', 'Invalid desired publish datetime.' );
		return gmdate( 'Y-m-d H:i:s', $ts );
	}

	private static function row( $row ) {
		if ( ! is_array( $row ) ) return null;
		foreach ( array( 'id', 'target_post_id', 'job_revision', 'created_by_user' ) as $key ) {
			if ( array_key_exists( $key, $row ) && null !== $row[ $key ] ) $row[ $key ] = (int) $row[ $key ];
		}
		return $row;
	}

	public static function list_jobs( $input ) {
		global $wpdb;
		if ( ! self::schema_ready() ) return new WP_Error( 'mad4b_content_job_schema_unavailable', 'ContentJob schema is not ready.' );
		$site_uuid = self::site_uuid();
		if ( '' === $site_uuid ) return new WP_Error( 'mad4b_content_job_site_identity_unavailable', 'Site Profile identity is unavailable.' );
		$t = MAD4B_SCP_Schema::tables();
		$where = array( 'site_uuid=%s' );
		$args = array( $site_uuid );
		$state = isset( $input['state'] ) ? strtoupper( sanitize_key( (string) $input['state'] ) ) : '';
		$stage = isset( $input['stage'] ) ? strtoupper( sanitize_key( (string) $input['stage'] ) ) : '';
		if ( '' !== $state ) { $where[] = 'state=%s'; $args[] = $state; }
		if ( '' !== $stage ) { $where[] = 'stage=%s'; $args[] = $stage; }
		$limit = isset( $input['limit'] ) ? max( 1, min( 200, absint( $input['limit'] ) ) ) : 50;
		$args[] = $limit;
		$sql = "SELECT * FROM {$t['content_jobs']} WHERE " . implode( ' AND ', $where ) . ' ORDER BY updated_at DESC,id DESC LIMIT %d';
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $args ), ARRAY_A );
		return array(
			'contract' => self::CONTRACT,
			'items' => array_values( array_filter( array_map( array( __CLASS__, 'row' ), is_array( $rows ) ? $rows : array() ) ) ),
			'count' => is_array( $rows ) ? count( $rows ) : 0,
			'mutation_performed' => false,
		);
	}

	public static function get_job( $input ) {
		$job_id = isset( $input['job_id'] ) ? strtolower( trim( (string) $input['job_id'] ) ) : '';
		$row = self::load_job( $job_id, false );
		if ( is_wp_error( $row ) ) return $row;
		return array( 'contract' => self::CONTRACT, 'job' => self::row( $row ), 'mutation_performed' => false );
	}

	public static function get_events( $input ) {
		global $wpdb;
		$job_id = isset( $input['job_id'] ) ? strtolower( trim( (string) $input['job_id'] ) ) : '';
		$job = self::load_job( $job_id, false );
		if ( is_wp_error( $job ) ) return $job;
		$limit = isset( $input['limit'] ) ? max( 1, min( 500, absint( $input['limit'] ) ) ) : 100;
		$t = MAD4B_SCP_Schema::tables();
		$rows = $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$t['content_job_events']} WHERE job_id=%s ORDER BY sequence ASC LIMIT %d", $job_id, $limit ),
			ARRAY_A
		);
		return array(
			'contract' => self::EVENT_CONTRACT,
			'job_id' => $job_id,
			'items' => is_array( $rows ) ? $rows : array(),
			'count' => is_array( $rows ) ? count( $rows ) : 0,
			'mutation_performed' => false,
		);
	}

	public static function create_job( $input ) {
		global $wpdb;
		if ( ! self::schema_ready() ) return new WP_Error( 'mad4b_content_job_schema_unavailable', 'ContentJob schema is not ready.' );
		$site_uuid = self::site_uuid();
		if ( '' === $site_uuid ) return new WP_Error( 'mad4b_content_job_site_identity_unavailable', 'Site Profile identity is unavailable.' );
		$subject = trim( wp_strip_all_tags( (string) $input['subject'] ) );
		if ( '' === $subject || strlen( $subject ) > 5000 ) return new WP_Error( 'mad4b_content_job_subject_invalid', 'ContentJob subject is missing or too long.' );
		$brand_id = sanitize_text_field( (string) $input['brand_id'] );
		$language = sanitize_key( (string) $input['language'] );
		$country = sanitize_key( (string) $input['country'] );
		$content_type = sanitize_key( (string) $input['content_type'] );
		if ( '' === $brand_id || '' === $language || '' === $country || '' === $content_type ) return new WP_Error( 'mad4b_content_job_identity_invalid', 'ContentJob brand/market/type identity is incomplete.' );
		$publish_at = self::normalize_datetime( isset( $input['desired_publish_at'] ) ? $input['desired_publish_at'] : '' );
		if ( is_wp_error( $publish_at ) ) return $publish_at;

		$job_id = strtolower( wp_generate_uuid4() );
		$now = gmdate( 'Y-m-d H:i:s' );
		$actor = self::actor();
		$t = MAD4B_SCP_Schema::tables();
		$data = array(
			'job_id' => $job_id,
			'tenant_id' => '',
			'site_uuid' => $site_uuid,
			'brand_id' => $brand_id,
			'subject' => $subject,
			'primary_keyword' => sanitize_text_field( isset( $input['primary_keyword'] ) ? (string) $input['primary_keyword'] : '' ),
			'language' => $language,
			'country' => $country,
			'content_type' => $content_type,
			'writer_profile_id' => sanitize_text_field( isset( $input['writer_profile_id'] ) ? (string) $input['writer_profile_id'] : '' ),
			'writer_profile_version' => sanitize_text_field( isset( $input['writer_profile_version'] ) ? (string) $input['writer_profile_version'] : '' ),
			'research_depth' => sanitize_key( isset( $input['research_depth'] ) ? (string) $input['research_depth'] : 'standard' ),
			'automation_level' => sanitize_key( isset( $input['automation_level'] ) ? (string) $input['automation_level'] : 'review_gated' ),
			'state' => 'NEW',
			'stage' => 'INTAKE',
			'target_post_type' => sanitize_key( isset( $input['target_post_type'] ) ? (string) $input['target_post_type'] : '' ),
			'target_post_id' => null,
			'desired_publish_at' => $publish_at,
			'current_artifact_id' => '',
			'quality_status' => 'unknown',
			'last_error_code' => '',
			'last_error_summary' => '',
			'job_revision' => 1,
			'created_by_nhi' => $actor['nhi'],
			'created_by_user' => get_current_user_id(),
			'created_at' => $now,
			'updated_at' => $now,
			'completed_at' => null,
			'cancelled_at' => null,
		);

		$wpdb->query( 'START TRANSACTION' );
		try {
			if ( false === $wpdb->insert( $t['content_jobs'], $data ) ) throw new RuntimeException( 'content_job_insert_failed:' . $wpdb->last_error );
			$event = self::append_event_locked( $job_id, 1, 'CREATED', '', 'NEW', '', 'INTAKE', (string) $input['reason'], $actor, '', '' );
			if ( is_wp_error( $event ) ) throw new RuntimeException( $event->get_error_code() );
			$audit = MAD4B_SCP_Audit::record( 'mad4b/content-job-create', array( 'job_id' => $job_id, 'site_uuid' => $site_uuid, 'revision' => 1 ), 'ok', true );
			if ( is_wp_error( $audit ) ) throw new RuntimeException( $audit->get_error_code() );
			if ( false === $wpdb->query( 'COMMIT' ) ) throw new RuntimeException( 'content_job_commit_failed' );
			MAD4B_SCP_Audit::transaction_committed();
		} catch ( Throwable $e ) {
			$wpdb->query( 'ROLLBACK' );
			MAD4B_SCP_Audit::transaction_rolled_back();
			return new WP_Error( 'mad4b_content_job_create_failed', 'Unable to create ContentJob.', array( 'cause' => $e->getMessage() ) );
		}
		return self::get_job( array( 'job_id' => $job_id ) );
	}

	public static function transition_job( $input ) {
		return self::transition(
			isset( $input['job_id'] ) ? $input['job_id'] : '',
			isset( $input['expected_revision'] ) ? $input['expected_revision'] : 0,
			isset( $input['state'] ) ? $input['state'] : '',
			isset( $input['stage'] ) ? $input['stage'] : '',
			isset( $input['reason'] ) ? $input['reason'] : '',
			isset( $input['plan_sha256'] ) ? $input['plan_sha256'] : '',
			isset( $input['artifact_id'] ) ? $input['artifact_id'] : ''
		);
	}

	public static function cancel_job( $input ) {
		$job = self::load_job( isset( $input['job_id'] ) ? $input['job_id'] : '', false );
		if ( is_wp_error( $job ) ) return $job;
		return self::transition(
			$job['job_id'],
			isset( $input['expected_revision'] ) ? $input['expected_revision'] : 0,
			'CANCELLED',
			$job['stage'],
			isset( $input['reason'] ) ? $input['reason'] : '',
			'',
			''
		);
	}

	private static function transition( $job_id, $expected_revision, $new_state, $new_stage, $reason, $plan_sha256, $artifact_id ) {
		global $wpdb;
		if ( ! self::schema_ready() ) return new WP_Error( 'mad4b_content_job_schema_unavailable', 'ContentJob schema is not ready.' );
		$job_id = strtolower( trim( (string) $job_id ) );
		if ( ! self::valid_uuid( $job_id ) ) return new WP_Error( 'mad4b_content_job_id_invalid', 'ContentJob ID is invalid.' );
		$expected_revision = absint( $expected_revision );
		$new_state = strtoupper( sanitize_key( (string) $new_state ) );
		$new_stage = strtoupper( sanitize_key( (string) $new_stage ) );
		$reason = trim( sanitize_text_field( (string) $reason ) );
		$plan_sha256 = strtolower( trim( (string) $plan_sha256 ) );
		$artifact_id = sanitize_text_field( (string) $artifact_id );
		if ( ! in_array( $new_state, self::states(), true ) || ! in_array( $new_stage, self::stages(), true ) ) return new WP_Error( 'mad4b_content_job_transition_invalid', 'Requested state/stage is invalid.' );
		if ( '' !== $plan_sha256 && ! preg_match( '/^[a-f0-9]{64}$/', $plan_sha256 ) ) return new WP_Error( 'mad4b_content_job_plan_invalid', 'Plan SHA is invalid.' );
		if ( strlen( $reason ) < 3 ) return new WP_Error( 'mad4b_content_job_reason_required', 'Transition reason is required.' );

		$t = MAD4B_SCP_Schema::tables();
		$wpdb->query( 'START TRANSACTION' );
		try {
			$row = self::load_job( $job_id, true );
			if ( is_wp_error( $row ) ) throw new RuntimeException( $row->get_error_code() );
			if ( $expected_revision !== (int) $row['job_revision'] ) throw new RuntimeException( 'content_job_revision_conflict' );
			if ( in_array( $row['state'], array( 'COMPLETED', 'CANCELLED' ), true ) ) throw new RuntimeException( 'content_job_terminal_immutable' );
			$same_state = hash_equals( (string) $row['state'], $new_state );
			$same_stage = hash_equals( (string) $row['stage'], $new_stage );
			if ( $same_state && $same_stage ) throw new RuntimeException( 'content_job_noop_transition' );
			$allowed = self::transition_map();
			if ( ! $same_state && ( ! isset( $allowed[ $row['state'] ] ) || ! in_array( $new_state, $allowed[ $row['state'] ], true ) ) ) {
				throw new RuntimeException( 'content_job_state_transition_denied' );
			}

			$revision = $expected_revision + 1;
			$now = gmdate( 'Y-m-d H:i:s' );
			$update = array(
				'state' => $new_state,
				'stage' => $new_stage,
				'current_artifact_id' => '' !== $artifact_id ? $artifact_id : (string) $row['current_artifact_id'],
				'job_revision' => $revision,
				'updated_at' => $now,
				'completed_at' => 'COMPLETED' === $new_state ? $now : $row['completed_at'],
				'cancelled_at' => 'CANCELLED' === $new_state ? $now : $row['cancelled_at'],
			);
			$changed = $wpdb->update(
				$t['content_jobs'],
				$update,
				array( 'id' => (int) $row['id'], 'job_revision' => $expected_revision ),
				null,
				array( '%d', '%d' )
			);
			if ( 1 !== (int) $changed ) throw new RuntimeException( 'content_job_transition_cas_failed' );
			$actor = self::actor();
			$event = self::append_event_locked(
				$job_id,
				$revision,
				'CANCELLED' === $new_state ? 'CANCELLED' : 'TRANSITIONED',
				(string) $row['state'],
				$new_state,
				(string) $row['stage'],
				$new_stage,
				$reason,
				$actor,
				$plan_sha256,
				$artifact_id
			);
			if ( is_wp_error( $event ) ) throw new RuntimeException( $event->get_error_code() );
			$audit = MAD4B_SCP_Audit::record( 'mad4b/content-job-transition', array( 'job_id' => $job_id, 'from_revision' => $expected_revision, 'to_revision' => $revision, 'state' => $new_state, 'stage' => $new_stage ), 'ok', true );
			if ( is_wp_error( $audit ) ) throw new RuntimeException( $audit->get_error_code() );
			if ( false === $wpdb->query( 'COMMIT' ) ) throw new RuntimeException( 'content_job_transition_commit_failed' );
			MAD4B_SCP_Audit::transaction_committed();
		} catch ( Throwable $e ) {
			$wpdb->query( 'ROLLBACK' );
			MAD4B_SCP_Audit::transaction_rolled_back();
			$code = $e->getMessage();
			if ( 'content_job_revision_conflict' === $code ) return new WP_Error( 'mad4b_content_job_revision_conflict', 'ContentJob revision changed since the requested transition.' );
			if ( 'content_job_terminal_immutable' === $code ) return new WP_Error( 'mad4b_content_job_terminal_immutable', 'Completed or cancelled ContentJob history is immutable.' );
			if ( 'content_job_state_transition_denied' === $code ) return new WP_Error( 'mad4b_content_job_state_transition_denied', 'Requested lifecycle transition is not allowed.' );
			if ( 'content_job_noop_transition' === $code ) return new WP_Error( 'mad4b_content_job_noop_transition', 'State and stage are unchanged.' );
			return new WP_Error( 'mad4b_content_job_transition_failed', 'Unable to transition ContentJob.', array( 'cause' => $code ) );
		}
		return self::get_job( array( 'job_id' => $job_id ) );
	}

	private static function load_job( $job_id, $for_update ) {
		global $wpdb;
		if ( ! self::valid_uuid( $job_id ) ) return new WP_Error( 'mad4b_content_job_id_invalid', 'ContentJob ID is invalid.' );
		$site_uuid = self::site_uuid();
		if ( '' === $site_uuid ) return new WP_Error( 'mad4b_content_job_site_identity_unavailable', 'Site Profile identity is unavailable.' );
		$t = MAD4B_SCP_Schema::tables();
		$sql = $wpdb->prepare( "SELECT * FROM {$t['content_jobs']} WHERE job_id=%s AND site_uuid=%s LIMIT 1" . ( $for_update ? ' FOR UPDATE' : '' ), $job_id, $site_uuid );
		$row = $wpdb->get_row( $sql, ARRAY_A );
		return is_array( $row ) ? $row : new WP_Error( 'mad4b_content_job_missing', 'ContentJob was not found for this site.' );
	}

	private static function append_event_locked( $job_id, $sequence, $event_type, $previous_state, $new_state, $previous_stage, $new_stage, $reason, array $actor, $plan_sha256, $artifact_id ) {
		global $wpdb;
		$t = MAD4B_SCP_Schema::tables();
		$previous_hash = (string) $wpdb->get_var(
			$wpdb->prepare( "SELECT entry_sha256 FROM {$t['content_job_events']} WHERE job_id=%s ORDER BY sequence DESC LIMIT 1", $job_id )
		);
		$event_id = strtolower( wp_generate_uuid4() );
		$now = gmdate( 'Y-m-d H:i:s' );
		$material = array(
			'contract' => self::EVENT_CONTRACT,
			'event_id' => $event_id,
			'job_id' => $job_id,
			'sequence' => (int) $sequence,
			'event_type' => sanitize_key( (string) $event_type ),
			'previous_state' => (string) $previous_state,
			'new_state' => (string) $new_state,
			'previous_stage' => (string) $previous_stage,
			'new_stage' => (string) $new_stage,
			'reason_code' => substr( sanitize_key( (string) $reason ), 0, 64 ),
			'actor_type' => sanitize_key( (string) $actor['type'] ),
			'actor_id' => substr( sanitize_text_field( (string) $actor['id'] ), 0, 191 ),
			'plan_sha256' => (string) $plan_sha256,
			'artifact_id' => substr( sanitize_text_field( (string) $artifact_id ), 0, 191 ),
			'provider_id' => '',
			'previous_entry_sha256' => $previous_hash,
			'created_at' => $now,
		);
		$entry_hash = hash( 'sha256', self::stable_json( $material ) );
		$ok = $wpdb->insert(
			$t['content_job_events'],
			array(
				'event_id' => $event_id,
				'job_id' => $job_id,
				'sequence' => (int) $sequence,
				'event_type' => $material['event_type'],
				'previous_state' => $previous_state,
				'new_state' => $new_state,
				'previous_stage' => $previous_stage,
				'new_stage' => $new_stage,
				'reason_code' => $material['reason_code'],
				'correlation_id' => '',
				'actor_type' => $material['actor_type'],
				'actor_id' => $material['actor_id'],
				'plan_sha256' => $plan_sha256,
				'artifact_id' => $artifact_id,
				'provider_id' => '',
				'metadata_json' => wp_json_encode( array( 'reason' => $reason ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ),
				'previous_entry_sha256' => $previous_hash,
				'entry_sha256' => $entry_hash,
				'created_at' => $now,
			)
		);
		if ( false === $ok ) return new WP_Error( 'mad4b_content_job_event_insert_failed', 'Unable to append ContentJob event.', array( 'db_error' => $wpdb->last_error ) );
		return array( 'event_id' => $event_id, 'entry_sha256' => $entry_hash, 'sequence' => (int) $sequence );
	}

	private static function stable_json( $value ) {
		$value = self::sort_value( $value );
		$json = wp_json_encode( $value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		return false === $json ? '' : $json;
	}

	private static function sort_value( $value ) {
		if ( ! is_array( $value ) ) return $value;
		$is_list = array_keys( $value ) === range( 0, count( $value ) - 1 );
		if ( ! $is_list ) ksort( $value, SORT_STRING );
		foreach ( $value as $key => $item ) $value[ $key ] = self::sort_value( $item );
		return $value;
	}
}

MAD4B_SCP_Content_Jobs::boot();
, 'maxLength' => 64, 'default' => '' ),
				'research_depth' => array( 'type' => 'string', 'maxLength' => 32, 'default' => 'standard' ),
				'automation_level' => array( 'type' => 'string', 'maxLength' => 32, 'default' => 'review_gated' ),
				'target_post_type' => array( 'type' => 'string', 'maxLength' => 64, 'default' => '' ),
				'desired_publish_at' => array( 'type' => 'string', 'maxLength' => 32, 'default' => '' ),
				'reason' => array( 'type' => 'string', 'minLength' => 3, 'maxLength' => 500 ),
			),
			array( 'brand_id', 'subject', 'language', 'country', 'content_type', 'reason' ),
			false
		);
		self::register(
			'mad4b/content-job-transition',
			'Transition Content Job',
			'transition_job',
			array(
				'job_id' => array( 'type' => 'string', 'minLength' => 36, 'maxLength' => 36 ),
				'expected_revision' => array( 'type' => 'integer', 'minimum' => 1 ),
				'state' => array( 'type' => 'string', 'enum' => self::states() ),
				'stage' => array( 'type' => 'string', 'enum' => self::stages() ),
				'reason' => array( 'type' => 'string', 'minLength' => 3, 'maxLength' => 500 ),
				'plan_sha256' => array( 'type' => 'string', 'pattern' => '^[a-f0-9]{64}$', 'default' => '' ),
				'artifact_id' => array( 'type' => 'string', 'maxLength' => 191, 'default' => '' ),
			),
			array( 'job_id', 'expected_revision', 'state', 'stage', 'reason' ),
			false
		);
		self::register(
			'mad4b/content-job-cancel',
			'Cancel Content Job',
			'cancel_job',
			array(
				'job_id' => array( 'type' => 'string', 'minLength' => 36, 'maxLength' => 36 ),
				'expected_revision' => array( 'type' => 'integer', 'minimum' => 1 ),
				'reason' => array( 'type' => 'string', 'minLength' => 3, 'maxLength' => 500 ),
			),
			array( 'job_id', 'expected_revision', 'reason' ),
			false
		);
	}

	private static function register( $name, $label, $method, array $properties, array $required, $readonly ) {
		if ( function_exists( 'wp_has_ability' ) && wp_has_ability( $name ) ) return;
		wp_register_ability(
			$name,
			array(
				'label' => $label,
				'description' => $label . ' through the governed Feature 007 ContentJob aggregate.',
				'category' => $readonly ? 'mad4b-read' : 'mad4b-write',
				'execute_callback' => array( __CLASS__, $method ),
				'permission_callback' => $readonly ? array( 'MAD4B_SCP_Policy', 'can_read' ) : array( 'MAD4B_SCP_Policy', 'can_mutate' ),
				'input_schema' => array(
					'type' => 'object',
					'properties' => $properties,
					'required' => $required,
					'additionalProperties' => false,
				),
				'output_schema' => array( 'type' => 'object', 'additionalProperties' => true ),
				'meta' => array(
					'public' => false,
					'show_in_rest' => false,
					'mcp' => array( 'public' => false, 'type' => 'tool', 'surface' => $readonly ? 'read' : 'write' ),
					'annotations' => array(
						'readonly' => (bool) $readonly,
						'destructive' => ! $readonly,
						'idempotent' => $readonly,
					),
				),
			)
		);
	}

	private static function schema_ready() {
		return class_exists( 'MAD4B_SCP_Schema' ) && MAD4B_SCP_Schema::critical_ready();
	}

	private static function site_uuid() {
		$uuid = class_exists( 'MAD4B_SCP_Site_Profile' ) ? strtolower( trim( (string) MAD4B_SCP_Site_Profile::site_uuid() ) ) : '';
		return preg_match( '/^[a-f0-9-]{36}$/', $uuid ) ? $uuid : '';
	}

	private static function valid_uuid( $value ) {
		return is_string( $value ) && 1 === preg_match( '/^[a-f0-9-]{36}$/', strtolower( trim( $value ) ) );
	}

	private static function actor() {
		$actor = array( 'type' => 'wordpress_user', 'id' => (string) get_current_user_id(), 'nhi' => '' );
		if ( class_exists( 'MAD4B_SCP_Identity_Context' ) && class_exists( 'MAD4B_SCP_Agent_Registry' ) ) {
			$identity = MAD4B_SCP_Identity_Context::current();
			if ( ! is_wp_error( $identity ) ) {
				$agent = MAD4B_SCP_Agent_Registry::resolve_agent( $identity );
				if ( ! is_wp_error( $agent ) && ! empty( $agent['public_id'] ) ) {
					$actor['type'] = 'nhi';
					$actor['id'] = strtolower( (string) $agent['public_id'] );
					$actor['nhi'] = $actor['id'];
				}
			}
		}
		return $actor;
	}

	private static function normalize_datetime( $value ) {
		$value = trim( (string) $value );
		if ( '' === $value ) return null;
		$ts = strtotime( $value );
		if ( false === $ts ) return new WP_Error( 'mad4b_content_job_datetime_invalid', 'Invalid desired publish datetime.' );
		return gmdate( 'Y-m-d H:i:s', $ts );
	}

	private static function row( $row ) {
		if ( ! is_array( $row ) ) return null;
		foreach ( array( 'id', 'target_post_id', 'job_revision', 'created_by_user' ) as $key ) {
			if ( array_key_exists( $key, $row ) && null !== $row[ $key ] ) $row[ $key ] = (int) $row[ $key ];
		}
		return $row;
	}

	public static function list_jobs( $input ) {
		global $wpdb;
		if ( ! self::schema_ready() ) return new WP_Error( 'mad4b_content_job_schema_unavailable', 'ContentJob schema is not ready.' );
		$site_uuid = self::site_uuid();
		if ( '' === $site_uuid ) return new WP_Error( 'mad4b_content_job_site_identity_unavailable', 'Site Profile identity is unavailable.' );
		$t = MAD4B_SCP_Schema::tables();
		$where = array( 'site_uuid=%s' );
		$args = array( $site_uuid );
		$state = isset( $input['state'] ) ? strtoupper( sanitize_key( (string) $input['state'] ) ) : '';
		$stage = isset( $input['stage'] ) ? strtoupper( sanitize_key( (string) $input['stage'] ) ) : '';
		if ( '' !== $state ) { $where[] = 'state=%s'; $args[] = $state; }
		if ( '' !== $stage ) { $where[] = 'stage=%s'; $args[] = $stage; }
		$limit = isset( $input['limit'] ) ? max( 1, min( 200, absint( $input['limit'] ) ) ) : 50;
		$args[] = $limit;
		$sql = "SELECT * FROM {$t['content_jobs']} WHERE " . implode( ' AND ', $where ) . ' ORDER BY updated_at DESC,id DESC LIMIT %d';
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $args ), ARRAY_A );
		return array(
			'contract' => self::CONTRACT,
			'items' => array_values( array_filter( array_map( array( __CLASS__, 'row' ), is_array( $rows ) ? $rows : array() ) ) ),
			'count' => is_array( $rows ) ? count( $rows ) : 0,
			'mutation_performed' => false,
		);
	}

	public static function get_job( $input ) {
		$job_id = isset( $input['job_id'] ) ? strtolower( trim( (string) $input['job_id'] ) ) : '';
		$row = self::load_job( $job_id, false );
		if ( is_wp_error( $row ) ) return $row;
		return array( 'contract' => self::CONTRACT, 'job' => self::row( $row ), 'mutation_performed' => false );
	}

	public static function get_events( $input ) {
		global $wpdb;
		$job_id = isset( $input['job_id'] ) ? strtolower( trim( (string) $input['job_id'] ) ) : '';
		$job = self::load_job( $job_id, false );
		if ( is_wp_error( $job ) ) return $job;
		$limit = isset( $input['limit'] ) ? max( 1, min( 500, absint( $input['limit'] ) ) ) : 100;
		$t = MAD4B_SCP_Schema::tables();
		$rows = $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$t['content_job_events']} WHERE job_id=%s ORDER BY sequence ASC LIMIT %d", $job_id, $limit ),
			ARRAY_A
		);
		return array(
			'contract' => self::EVENT_CONTRACT,
			'job_id' => $job_id,
			'items' => is_array( $rows ) ? $rows : array(),
			'count' => is_array( $rows ) ? count( $rows ) : 0,
			'mutation_performed' => false,
		);
	}

	public static function create_job( $input ) {
		global $wpdb;
		if ( ! self::schema_ready() ) return new WP_Error( 'mad4b_content_job_schema_unavailable', 'ContentJob schema is not ready.' );
		$site_uuid = self::site_uuid();
		if ( '' === $site_uuid ) return new WP_Error( 'mad4b_content_job_site_identity_unavailable', 'Site Profile identity is unavailable.' );
		$subject = trim( wp_strip_all_tags( (string) $input['subject'] ) );
		if ( '' === $subject || strlen( $subject ) > 5000 ) return new WP_Error( 'mad4b_content_job_subject_invalid', 'ContentJob subject is missing or too long.' );
		$brand_id = sanitize_text_field( (string) $input['brand_id'] );
		$language = sanitize_key( (string) $input['language'] );
		$country = sanitize_key( (string) $input['country'] );
		$content_type = sanitize_key( (string) $input['content_type'] );
		if ( '' === $brand_id || '' === $language || '' === $country || '' === $content_type ) return new WP_Error( 'mad4b_content_job_identity_invalid', 'ContentJob brand/market/type identity is incomplete.' );
		$publish_at = self::normalize_datetime( isset( $input['desired_publish_at'] ) ? $input['desired_publish_at'] : '' );
		if ( is_wp_error( $publish_at ) ) return $publish_at;

		$job_id = strtolower( wp_generate_uuid4() );
		$now = gmdate( 'Y-m-d H:i:s' );
		$actor = self::actor();
		$t = MAD4B_SCP_Schema::tables();
		$data = array(
			'job_id' => $job_id,
			'tenant_id' => '',
			'site_uuid' => $site_uuid,
			'brand_id' => $brand_id,
			'subject' => $subject,
			'primary_keyword' => sanitize_text_field( isset( $input['primary_keyword'] ) ? (string) $input['primary_keyword'] : '' ),
			'language' => $language,
			'country' => $country,
			'content_type' => $content_type,
			'writer_profile_id' => sanitize_text_field( isset( $input['writer_profile_id'] ) ? (string) $input['writer_profile_id'] : '' ),
			'writer_profile_version' => sanitize_text_field( isset( $input['writer_profile_version'] ) ? (string) $input['writer_profile_version'] : '' ),
			'research_depth' => sanitize_key( isset( $input['research_depth'] ) ? (string) $input['research_depth'] : 'standard' ),
			'automation_level' => sanitize_key( isset( $input['automation_level'] ) ? (string) $input['automation_level'] : 'review_gated' ),
			'state' => 'NEW',
			'stage' => 'INTAKE',
			'target_post_type' => sanitize_key( isset( $input['target_post_type'] ) ? (string) $input['target_post_type'] : '' ),
			'target_post_id' => null,
			'desired_publish_at' => $publish_at,
			'current_artifact_id' => '',
			'quality_status' => 'unknown',
			'last_error_code' => '',
			'last_error_summary' => '',
			'job_revision' => 1,
			'created_by_nhi' => $actor['nhi'],
			'created_by_user' => get_current_user_id(),
			'created_at' => $now,
			'updated_at' => $now,
			'completed_at' => null,
			'cancelled_at' => null,
		);

		$wpdb->query( 'START TRANSACTION' );
		try {
			if ( false === $wpdb->insert( $t['content_jobs'], $data ) ) throw new RuntimeException( 'content_job_insert_failed:' . $wpdb->last_error );
			$event = self::append_event_locked( $job_id, 1, 'CREATED', '', 'NEW', '', 'INTAKE', (string) $input['reason'], $actor, '', '' );
			if ( is_wp_error( $event ) ) throw new RuntimeException( $event->get_error_code() );
			$audit = MAD4B_SCP_Audit::record( 'mad4b/content-job-create', array( 'job_id' => $job_id, 'site_uuid' => $site_uuid, 'revision' => 1 ), 'ok', true );
			if ( is_wp_error( $audit ) ) throw new RuntimeException( $audit->get_error_code() );
			if ( false === $wpdb->query( 'COMMIT' ) ) throw new RuntimeException( 'content_job_commit_failed' );
			MAD4B_SCP_Audit::transaction_committed();
		} catch ( Throwable $e ) {
			$wpdb->query( 'ROLLBACK' );
			MAD4B_SCP_Audit::transaction_rolled_back();
			return new WP_Error( 'mad4b_content_job_create_failed', 'Unable to create ContentJob.', array( 'cause' => $e->getMessage() ) );
		}
		return self::get_job( array( 'job_id' => $job_id ) );
	}

	public static function transition_job( $input ) {
		return self::transition(
			isset( $input['job_id'] ) ? $input['job_id'] : '',
			isset( $input['expected_revision'] ) ? $input['expected_revision'] : 0,
			isset( $input['state'] ) ? $input['state'] : '',
			isset( $input['stage'] ) ? $input['stage'] : '',
			isset( $input['reason'] ) ? $input['reason'] : '',
			isset( $input['plan_sha256'] ) ? $input['plan_sha256'] : '',
			isset( $input['artifact_id'] ) ? $input['artifact_id'] : ''
		);
	}

	public static function cancel_job( $input ) {
		$job = self::load_job( isset( $input['job_id'] ) ? $input['job_id'] : '', false );
		if ( is_wp_error( $job ) ) return $job;
		return self::transition(
			$job['job_id'],
			isset( $input['expected_revision'] ) ? $input['expected_revision'] : 0,
			'CANCELLED',
			$job['stage'],
			isset( $input['reason'] ) ? $input['reason'] : '',
			'',
			''
		);
	}

	private static function transition( $job_id, $expected_revision, $new_state, $new_stage, $reason, $plan_sha256, $artifact_id ) {
		global $wpdb;
		if ( ! self::schema_ready() ) return new WP_Error( 'mad4b_content_job_schema_unavailable', 'ContentJob schema is not ready.' );
		$job_id = strtolower( trim( (string) $job_id ) );
		if ( ! self::valid_uuid( $job_id ) ) return new WP_Error( 'mad4b_content_job_id_invalid', 'ContentJob ID is invalid.' );
		$expected_revision = absint( $expected_revision );
		$new_state = strtoupper( sanitize_key( (string) $new_state ) );
		$new_stage = strtoupper( sanitize_key( (string) $new_stage ) );
		$reason = trim( sanitize_text_field( (string) $reason ) );
		$plan_sha256 = strtolower( trim( (string) $plan_sha256 ) );
		$artifact_id = sanitize_text_field( (string) $artifact_id );
		if ( ! in_array( $new_state, self::states(), true ) || ! in_array( $new_stage, self::stages(), true ) ) return new WP_Error( 'mad4b_content_job_transition_invalid', 'Requested state/stage is invalid.' );
		if ( '' !== $plan_sha256 && ! preg_match( '/^[a-f0-9]{64}$/', $plan_sha256 ) ) return new WP_Error( 'mad4b_content_job_plan_invalid', 'Plan SHA is invalid.' );
		if ( strlen( $reason ) < 3 ) return new WP_Error( 'mad4b_content_job_reason_required', 'Transition reason is required.' );

		$t = MAD4B_SCP_Schema::tables();
		$wpdb->query( 'START TRANSACTION' );
		try {
			$row = self::load_job( $job_id, true );
			if ( is_wp_error( $row ) ) throw new RuntimeException( $row->get_error_code() );
			if ( $expected_revision !== (int) $row['job_revision'] ) throw new RuntimeException( 'content_job_revision_conflict' );
			if ( in_array( $row['state'], array( 'COMPLETED', 'CANCELLED' ), true ) ) throw new RuntimeException( 'content_job_terminal_immutable' );
			$same_state = hash_equals( (string) $row['state'], $new_state );
			$same_stage = hash_equals( (string) $row['stage'], $new_stage );
			if ( $same_state && $same_stage ) throw new RuntimeException( 'content_job_noop_transition' );
			$allowed = self::transition_map();
			if ( ! $same_state && ( ! isset( $allowed[ $row['state'] ] ) || ! in_array( $new_state, $allowed[ $row['state'] ], true ) ) ) {
				throw new RuntimeException( 'content_job_state_transition_denied' );
			}

			$revision = $expected_revision + 1;
			$now = gmdate( 'Y-m-d H:i:s' );
			$update = array(
				'state' => $new_state,
				'stage' => $new_stage,
				'current_artifact_id' => '' !== $artifact_id ? $artifact_id : (string) $row['current_artifact_id'],
				'job_revision' => $revision,
				'updated_at' => $now,
				'completed_at' => 'COMPLETED' === $new_state ? $now : $row['completed_at'],
				'cancelled_at' => 'CANCELLED' === $new_state ? $now : $row['cancelled_at'],
			);
			$changed = $wpdb->update(
				$t['content_jobs'],
				$update,
				array( 'id' => (int) $row['id'], 'job_revision' => $expected_revision ),
				null,
				array( '%d', '%d' )
			);
			if ( 1 !== (int) $changed ) throw new RuntimeException( 'content_job_transition_cas_failed' );
			$actor = self::actor();
			$event = self::append_event_locked(
				$job_id,
				$revision,
				'CANCELLED' === $new_state ? 'CANCELLED' : 'TRANSITIONED',
				(string) $row['state'],
				$new_state,
				(string) $row['stage'],
				$new_stage,
				$reason,
				$actor,
				$plan_sha256,
				$artifact_id
			);
			if ( is_wp_error( $event ) ) throw new RuntimeException( $event->get_error_code() );
			$audit = MAD4B_SCP_Audit::record( 'mad4b/content-job-transition', array( 'job_id' => $job_id, 'from_revision' => $expected_revision, 'to_revision' => $revision, 'state' => $new_state, 'stage' => $new_stage ), 'ok', true );
			if ( is_wp_error( $audit ) ) throw new RuntimeException( $audit->get_error_code() );
			if ( false === $wpdb->query( 'COMMIT' ) ) throw new RuntimeException( 'content_job_transition_commit_failed' );
			MAD4B_SCP_Audit::transaction_committed();
		} catch ( Throwable $e ) {
			$wpdb->query( 'ROLLBACK' );
			MAD4B_SCP_Audit::transaction_rolled_back();
			$code = $e->getMessage();
			if ( 'content_job_revision_conflict' === $code ) return new WP_Error( 'mad4b_content_job_revision_conflict', 'ContentJob revision changed since the requested transition.' );
			if ( 'content_job_terminal_immutable' === $code ) return new WP_Error( 'mad4b_content_job_terminal_immutable', 'Completed or cancelled ContentJob history is immutable.' );
			if ( 'content_job_state_transition_denied' === $code ) return new WP_Error( 'mad4b_content_job_state_transition_denied', 'Requested lifecycle transition is not allowed.' );
			if ( 'content_job_noop_transition' === $code ) return new WP_Error( 'mad4b_content_job_noop_transition', 'State and stage are unchanged.' );
			return new WP_Error( 'mad4b_content_job_transition_failed', 'Unable to transition ContentJob.', array( 'cause' => $code ) );
		}
		return self::get_job( array( 'job_id' => $job_id ) );
	}

	private static function load_job( $job_id, $for_update ) {
		global $wpdb;
		if ( ! self::valid_uuid( $job_id ) ) return new WP_Error( 'mad4b_content_job_id_invalid', 'ContentJob ID is invalid.' );
		$site_uuid = self::site_uuid();
		if ( '' === $site_uuid ) return new WP_Error( 'mad4b_content_job_site_identity_unavailable', 'Site Profile identity is unavailable.' );
		$t = MAD4B_SCP_Schema::tables();
		$sql = $wpdb->prepare( "SELECT * FROM {$t['content_jobs']} WHERE job_id=%s AND site_uuid=%s LIMIT 1" . ( $for_update ? ' FOR UPDATE' : '' ), $job_id, $site_uuid );
		$row = $wpdb->get_row( $sql, ARRAY_A );
		return is_array( $row ) ? $row : new WP_Error( 'mad4b_content_job_missing', 'ContentJob was not found for this site.' );
	}

	private static function append_event_locked( $job_id, $sequence, $event_type, $previous_state, $new_state, $previous_stage, $new_stage, $reason, array $actor, $plan_sha256, $artifact_id ) {
		global $wpdb;
		$t = MAD4B_SCP_Schema::tables();
		$previous_hash = (string) $wpdb->get_var(
			$wpdb->prepare( "SELECT entry_sha256 FROM {$t['content_job_events']} WHERE job_id=%s ORDER BY sequence DESC LIMIT 1", $job_id )
		);
		$event_id = strtolower( wp_generate_uuid4() );
		$now = gmdate( 'Y-m-d H:i:s' );
		$material = array(
			'contract' => self::EVENT_CONTRACT,
			'event_id' => $event_id,
			'job_id' => $job_id,
			'sequence' => (int) $sequence,
			'event_type' => sanitize_key( (string) $event_type ),
			'previous_state' => (string) $previous_state,
			'new_state' => (string) $new_state,
			'previous_stage' => (string) $previous_stage,
			'new_stage' => (string) $new_stage,
			'reason_code' => substr( sanitize_key( (string) $reason ), 0, 64 ),
			'actor_type' => sanitize_key( (string) $actor['type'] ),
			'actor_id' => substr( sanitize_text_field( (string) $actor['id'] ), 0, 191 ),
			'plan_sha256' => (string) $plan_sha256,
			'artifact_id' => substr( sanitize_text_field( (string) $artifact_id ), 0, 191 ),
			'provider_id' => '',
			'previous_entry_sha256' => $previous_hash,
			'created_at' => $now,
		);
		$entry_hash = hash( 'sha256', self::stable_json( $material ) );
		$ok = $wpdb->insert(
			$t['content_job_events'],
			array(
				'event_id' => $event_id,
				'job_id' => $job_id,
				'sequence' => (int) $sequence,
				'event_type' => $material['event_type'],
				'previous_state' => $previous_state,
				'new_state' => $new_state,
				'previous_stage' => $previous_stage,
				'new_stage' => $new_stage,
				'reason_code' => $material['reason_code'],
				'correlation_id' => '',
				'actor_type' => $material['actor_type'],
				'actor_id' => $material['actor_id'],
				'plan_sha256' => $plan_sha256,
				'artifact_id' => $artifact_id,
				'provider_id' => '',
				'metadata_json' => wp_json_encode( array( 'reason' => $reason ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ),
				'previous_entry_sha256' => $previous_hash,
				'entry_sha256' => $entry_hash,
				'created_at' => $now,
			)
		);
		if ( false === $ok ) return new WP_Error( 'mad4b_content_job_event_insert_failed', 'Unable to append ContentJob event.', array( 'db_error' => $wpdb->last_error ) );
		return array( 'event_id' => $event_id, 'entry_sha256' => $entry_hash, 'sequence' => (int) $sequence );
	}

	private static function stable_json( $value ) {
		$value = self::sort_value( $value );
		$json = wp_json_encode( $value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		return false === $json ? '' : $json;
	}

	private static function sort_value( $value ) {
		if ( ! is_array( $value ) ) return $value;
		$is_list = array_keys( $value ) === range( 0, count( $value ) - 1 );
		if ( ! $is_list ) ksort( $value, SORT_STRING );
		foreach ( $value as $key => $item ) $value[ $key ] = self::sort_value( $item );
		return $value;
	}
}

MAD4B_SCP_Content_Jobs::boot();
