<?php

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Governed WordPress draft orchestration for Feature 007.
 *
 * This surface stops at post_status=draft. It does not publish, schedule or
 * create public authority. Exact artifact and target state are rebound at apply.
 */
final class MAD4B_SCP_Governed_Draft {
	const CONTRACT = 'mad4b.governed-draft.v1';
	const PLAN_CONTRACT = 'mad4b.governed-draft-plan.v1';

	private static $booted = false;

	public static function boot() {
		if ( self::$booted ) return;
		self::$booted = true;
		add_action( 'wp_abilities_api_init', array( __CLASS__, 'register_abilities' ), 42 );
	}

	public static function register_abilities() {
		if ( ! function_exists( 'wp_register_ability' ) ) return;
		self::register( 'mad4b/draft-plan', 'Plan Governed WordPress Draft', 'plan', true );
		self::register( 'mad4b/draft-apply', 'Apply Governed WordPress Draft', 'apply', false );
		self::register( 'mad4b/draft-verify', 'Verify Governed WordPress Draft', 'verify', true );
	}

	private static function register( $name, $label, $method, $readonly ) {
		if ( function_exists( 'wp_has_ability' ) && wp_has_ability( $name ) ) return;
		wp_register_ability(
			$name,
			array(
				'label' => $label,
				'description' => $label . '; this surface never publishes publicly.',
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

	public static function plan( $input ) {
		$job_id = self::uuid( $input['job_id'] ?? '' );
		$draft_id = self::uuid( $input['draft_artifact_id'] ?? '' );
		$qa_id = self::uuid( $input['final_qa_artifact_id'] ?? '' );
		if ( '' === $job_id || '' === $draft_id || '' === $qa_id ) return new WP_Error( 'mad4b_draft_identity_invalid', 'Job/draft/FinalQA identity is invalid.' );

		$draft = self::artifact( $draft_id, $job_id, 'draft' );
		if ( is_wp_error( $draft ) ) return $draft;
		$qa = self::artifact( $qa_id, $job_id, 'final_qa' );
		if ( is_wp_error( $qa ) ) return $qa;
		if ( empty( $qa['payload']['pass'] ) || ! empty( $qa['payload']['hard_blockers'] ) ) {
			return new WP_Error( 'mad4b_draft_final_qa_blocked', 'FinalQA hard blockers prevent WordPress draft mutation.' );
		}
		if ( ! isset( $qa['payload']['draft_artifact_id'] ) || ! hash_equals( $draft_id, (string) $qa['payload']['draft_artifact_id'] ) ) {
			return new WP_Error( 'mad4b_draft_final_qa_lineage_mismatch', 'FinalQA does not certify the selected ArticleDraft.' );
		}
		if ( ! empty( $qa['payload']['can_publish'] ) || ! empty( $qa['payload']['publication_authorized'] ) ) {
			return new WP_Error( 'mad4b_draft_qa_authority_invalid', 'QA artifact must not create publishing authority.' );
		}

		$post_type = sanitize_key( (string) ( $input['post_type'] ?? 'post' ) );
		$type = get_post_type_object( $post_type );
		if ( ! $type ) return new WP_Error( 'mad4b_draft_post_type_invalid', 'Target post type is unavailable.' );
		$title = trim( (string) ( $input['post_title'] ?? '' ) );
		$excerpt = (string) ( $input['post_excerpt'] ?? '' );
		if ( '' === $title ) return new WP_Error( 'mad4b_draft_title_required', 'Draft title is required.' );
		$content = isset( $draft['payload']['content'] ) ? (string) $draft['payload']['content'] : '';
		if ( '' === trim( $content ) ) return new WP_Error( 'mad4b_draft_artifact_content_missing', 'Draft artifact has no content.' );

		$post_id = isset( $input['post_id'] ) ? absint( $input['post_id'] ) : 0;
		$expected_modified = '';
		$current_fingerprint = 'ABSENT';
		if ( $post_id > 0 ) {
			$post = get_post( $post_id );
			if ( ! $post ) return new WP_Error( 'mad4b_draft_target_missing', 'Target post was not found.' );
			if ( (string) $post->post_type !== $post_type ) return new WP_Error( 'mad4b_draft_post_type_mismatch', 'Target post type changed.' );
			$expected_modified = (string) $post->post_modified_gmt;
			$current_fingerprint = self::post_fingerprint( $post );
		}

		$plan = array(
			'contract' => self::PLAN_CONTRACT,
			'job_id' => $job_id,
			'draft_artifact_id' => $draft_id,
			'draft_artifact_sha256' => (string) ( $draft['content_sha256'] ?? hash( 'sha256', self::stable_json( $draft['payload'] ) ) ),
			'final_qa_artifact_id' => $qa_id,
			'final_qa_artifact_sha256' => (string) ( $qa['content_sha256'] ?? hash( 'sha256', self::stable_json( $qa['payload'] ) ) ),
			'post_id' => $post_id,
			'post_type' => $post_type,
			'post_title' => $title,
			'post_excerpt' => $excerpt,
			'post_content_sha256' => hash( 'sha256', $content ),
			'expected_modified_gmt' => $expected_modified,
			'expected_current_fingerprint' => $current_fingerprint,
			'intended_status' => 'draft',
			'can_publish' => false,
			'publication_authorized' => false,
			'created_at' => gmdate( 'c' ),
		);
		$plan['plan_sha256'] = self::digest( $plan );
		$plan['mutation_performed'] = false;
		$plan['authorizing'] = false;
		return $plan;
	}

	public static function apply( $input ) {
		$plan = isset( $input['plan'] ) && is_array( $input['plan'] ) ? $input['plan'] : array();
		$check = self::validate_plan( $plan );
		if ( is_wp_error( $check ) ) return $check;

		$draft = self::artifact( (string) $plan['draft_artifact_id'], (string) $plan['job_id'], 'draft' );
		if ( is_wp_error( $draft ) ) return $draft;
		$qa = self::artifact( (string) $plan['final_qa_artifact_id'], (string) $plan['job_id'], 'final_qa' );
		if ( is_wp_error( $qa ) ) return $qa;
		if ( empty( $qa['payload']['pass'] ) || ! empty( $qa['payload']['hard_blockers'] ) ) return new WP_Error( 'mad4b_draft_final_qa_blocked', 'FinalQA changed or is blocked.' );
		if ( ! isset( $qa['payload']['draft_artifact_id'] ) || ! hash_equals( (string) $plan['draft_artifact_id'], (string) $qa['payload']['draft_artifact_id'] ) ) {
			return new WP_Error( 'mad4b_draft_final_qa_lineage_mismatch', 'FinalQA no longer certifies the planned ArticleDraft.' );
		}
		if ( ! hash_equals( (string) $plan['draft_artifact_sha256'], self::artifact_sha( $draft ) ) ) return new WP_Error( 'mad4b_draft_artifact_stale', 'Draft artifact changed since plan.' );
		if ( ! hash_equals( (string) $plan['final_qa_artifact_sha256'], self::artifact_sha( $qa ) ) ) return new WP_Error( 'mad4b_draft_qa_stale', 'FinalQA artifact changed since plan.' );

		$content = (string) $draft['payload']['content'];
		if ( ! hash_equals( (string) $plan['post_content_sha256'], hash( 'sha256', $content ) ) ) return new WP_Error( 'mad4b_draft_content_stale', 'Draft content changed since plan.' );

		$post_id = (int) $plan['post_id'];
		if ( $post_id > 0 ) {
			$current = get_post( $post_id );
			if ( ! $current ) return new WP_Error( 'mad4b_draft_target_missing', 'Target post disappeared.' );
			if ( ! hash_equals( (string) $plan['expected_modified_gmt'], (string) $current->post_modified_gmt )
				|| ! hash_equals( (string) $plan['expected_current_fingerprint'], self::post_fingerprint( $current ) ) ) {
				return new WP_Error( 'mad4b_draft_target_stale', 'Target post changed since plan.' );
			}
			if ( ! current_user_can( 'edit_post', $post_id ) ) return new WP_Error( 'mad4b_draft_edit_denied', 'Current subject cannot edit target post.' );
			$result = wp_update_post(
				wp_slash(
					array(
						'ID' => $post_id,
						'post_title' => (string) $plan['post_title'],
						'post_content' => $content,
						'post_excerpt' => (string) $plan['post_excerpt'],
						'post_status' => 'draft',
					)
				),
				true
			);
		} else {
			$type = get_post_type_object( (string) $plan['post_type'] );
			$cap = $type && isset( $type->cap->create_posts ) ? $type->cap->create_posts : 'edit_posts';
			if ( ! current_user_can( $cap ) ) return new WP_Error( 'mad4b_draft_create_denied', 'Current subject cannot create this draft post type.' );
			$result = wp_insert_post(
				wp_slash(
					array(
						'post_type' => (string) $plan['post_type'],
						'post_title' => (string) $plan['post_title'],
						'post_content' => $content,
						'post_excerpt' => (string) $plan['post_excerpt'],
						'post_status' => 'draft',
					)
				),
				true
			);
		}
		if ( is_wp_error( $result ) ) return $result;
		$post_id = (int) $result;
		$observed = get_post( $post_id );
		if ( ! $observed ) return new WP_Error( 'mad4b_draft_readback_missing', 'Draft write succeeded but readback failed.' );
		if ( 'draft' !== (string) $observed->post_status ) return new WP_Error( 'mad4b_draft_status_readback_failed', 'Draft write did not remain draft.' );
		$expected = self::intended_fingerprint( $plan, $content );
		$actual = self::post_fingerprint( $observed );
		if ( ! hash_equals( $expected, $actual ) ) return new WP_Error( 'mad4b_draft_fingerprint_mismatch', 'Draft readback does not match intended content.' );

		return array(
			'contract' => self::CONTRACT,
			'post_id' => $post_id,
			'post_status' => 'draft',
			'modified_gmt' => (string) $observed->post_modified_gmt,
			'post_fingerprint' => $actual,
			'plan_sha256' => (string) $plan['plan_sha256'],
			'draft_artifact_id' => (string) $plan['draft_artifact_id'],
			'final_qa_artifact_id' => (string) $plan['final_qa_artifact_id'],
			'origin_readback' => 'PASS',
			'public_edge_verdict' => 'NOT_CHECKED',
			'can_publish' => false,
			'publication_authorized' => false,
			'mutation_performed' => true,
		);
	}

	public static function verify( $input ) {
		$post_id = isset( $input['post_id'] ) ? absint( $input['post_id'] ) : 0;
		$expected = strtolower( trim( (string) ( $input['expected_post_fingerprint'] ?? '' ) ) );
		if ( $post_id < 1 || 1 !== preg_match( '/^[a-f0-9]{64}$/', $expected ) ) return new WP_Error( 'mad4b_draft_verify_input_invalid', 'Draft verification input is invalid.' );
		$post = get_post( $post_id );
		if ( ! $post ) return new WP_Error( 'mad4b_draft_target_missing', 'Draft target is missing.' );
		$actual = self::post_fingerprint( $post );
		return array(
			'contract' => 'mad4b.draft-verification.v1',
			'post_id' => $post_id,
			'post_status' => (string) $post->post_status,
			'expected_post_fingerprint' => $expected,
			'observed_post_fingerprint' => $actual,
			'origin_match' => hash_equals( $expected, $actual ),
			'origin_verdict' => hash_equals( $expected, $actual ) && 'draft' === (string) $post->post_status ? 'PASS' : 'FAIL',
			'public_edge_verdict' => 'NOT_CHECKED',
			'third_party_indexing_verdict' => 'NOT_INFERRED',
			'can_publish' => false,
			'publication_authorized' => false,
			'mutation_performed' => false,
		);
	}

	private static function validate_plan( array $plan ) {
		if ( self::PLAN_CONTRACT !== (string) ( $plan['contract'] ?? '' ) ) return new WP_Error( 'mad4b_draft_plan_contract_invalid', 'Draft plan contract is invalid.' );
		if ( 'draft' !== (string) ( $plan['intended_status'] ?? '' ) || ! empty( $plan['can_publish'] ) || ! empty( $plan['publication_authorized'] ) ) {
			return new WP_Error( 'mad4b_draft_plan_authority_invalid', 'Draft plan attempted to widen publishing authority.' );
		}
		$sha = strtolower( trim( (string) ( $plan['plan_sha256'] ?? '' ) ) );
		if ( 1 !== preg_match( '/^[a-f0-9]{64}$/', $sha ) || ! hash_equals( $sha, self::digest( $plan ) ) ) return new WP_Error( 'mad4b_draft_plan_digest_invalid', 'Draft plan digest mismatch.' );
		return true;
	}

	private static function artifact( $artifact_id, $job_id, $type ) {
		if ( ! class_exists( 'MAD4B_SCP_Artifacts' ) ) return new WP_Error( 'mad4b_artifact_registry_unavailable', 'Artifact Registry is unavailable.' );
		$result = MAD4B_SCP_Artifacts::get_artifact( array( 'artifact_id' => $artifact_id ) );
		if ( is_wp_error( $result ) ) return $result;
		$row = isset( $result['artifact'] ) && is_array( $result['artifact'] ) ? $result['artifact'] : array();
		if ( (string) ( $row['job_id'] ?? '' ) !== $job_id || (string) ( $row['artifact_type'] ?? '' ) !== $type ) return new WP_Error( 'mad4b_draft_artifact_mismatch', 'Artifact identity/type does not match plan.' );
		if ( 'active' !== (string) ( $row['status'] ?? '' ) ) return new WP_Error( 'mad4b_draft_artifact_stale', 'Artifact is not active/current.' );
		return $row;
	}

	private static function artifact_sha( array $artifact ) {
		$sha = strtolower( trim( (string) ( $artifact['content_sha256'] ?? '' ) ) );
		if ( 1 === preg_match( '/^[a-f0-9]{64}$/', $sha ) ) return $sha;
		return hash( 'sha256', self::stable_json( $artifact['payload'] ?? array() ) );
	}

	private static function intended_fingerprint( array $plan, $content ) {
		return hash(
			'sha256',
			self::stable_json(
				array(
					'post_type' => (string) $plan['post_type'],
					'post_status' => 'draft',
					'post_title' => (string) $plan['post_title'],
					'post_content' => (string) $content,
					'post_excerpt' => (string) $plan['post_excerpt'],
				)
			)
		);
	}

	private static function post_fingerprint( $post ) {
		return hash(
			'sha256',
			self::stable_json(
				array(
					'post_type' => (string) $post->post_type,
					'post_status' => (string) $post->post_status,
					'post_title' => (string) $post->post_title,
					'post_content' => (string) $post->post_content,
					'post_excerpt' => (string) $post->post_excerpt,
				)
			)
		);
	}

	private static function uuid( $value ) {
		$value = strtolower( trim( (string) $value ) );
		return 1 === preg_match( '/^[a-f0-9-]{36}$/', $value ) ? $value : '';
	}

	private static function stable_json( $value ) {
		$value = self::sort_value( $value );
		$json = wp_json_encode( $value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		return false === $json ? '' : $json;
	}

	private static function digest( array $value ) {
		unset( $value['plan_sha256'], $value['mutation_performed'], $value['authorizing'] );
		return hash( 'sha256', self::stable_json( $value ) );
	}

	private static function sort_value( $value ) {
		if ( ! is_array( $value ) ) return $value;
		$is_list = empty( $value ) || array_keys( $value ) === range( 0, count( $value ) - 1 );
		if ( ! $is_list ) ksort( $value, SORT_STRING );
		foreach ( $value as $key => $item ) $value[ $key ] = self::sort_value( $item );
		return $value;
	}
}

MAD4B_SCP_Governed_Draft::boot();
