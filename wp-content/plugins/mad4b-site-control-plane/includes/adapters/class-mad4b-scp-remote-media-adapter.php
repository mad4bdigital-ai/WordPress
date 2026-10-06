<?php

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Provider-neutral remote image acquisition.
 *
 * This adapter owns outbound discovery/inspection/import only. The canonical
 * WordPress Media adapter remains responsible for Media Library read/write
 * semantics after an attachment exists.
 */
final class MAD4B_SCP_Remote_Media_Adapter extends MAD4B_SCP_Adapter_Base {
	const REMOTE_DISCOVER_ABILITY = 'media/remote-source-discover';
	const REMOTE_INSPECT_ABILITY = 'media/remote-image-inspect';
	const REMOTE_IMPORT_PLAN_ABILITY = 'media/remote-import-plan';
	const REMOTE_MANIFEST_PLAN_ABILITY = 'media/remote-import-manifest-plan';
	const REMOTE_IMPORT_APPLY_ABILITY = 'media/remote-import-apply';
	const REMOTE_PROVENANCE_GET_ABILITY = 'media/remote-provenance-get';
	const REMOTE_RECOVERY_STATUS_ABILITY = 'media/remote-recovery-status';
	const REMOTE_IMPORT_CONTRACT = 'mad4b.remote-media-import.v1';
	const REMOTE_PROVENANCE_CONTRACT = 'mad4b.remote-media-provenance.v1';
	const REMOTE_SOURCE_HASH_META = '_mad4b_remote_media_source_sha256';
	const REMOTE_CONTENT_HASH_META = '_mad4b_remote_media_content_sha256';
	const REMOTE_PROVENANCE_META = '_mad4b_remote_media_provenance';
	const MAX_REMOTE_PROVENANCE_EVENTS = 32;
	const MAX_REMOTE_PAGE_BYTES = 2097152;
	const MAX_REMOTE_IMAGE_BYTES = 15728640;
	const MAX_REMOTE_IMAGE_PIXELS = 40000000;
	const MAX_REMOTE_CANDIDATES = 50;

	public function id() { return 'remote-media'; }
	public function label() { return 'Remote Media'; }
	public function is_available() { return function_exists( 'wp_safe_remote_get' ) && class_exists( 'DOMDocument' ); }
	protected function certified_provider_key() { return 'media'; }
	protected function mutation_requires_certification() { return false; }

	public function ability_names() {
		return array(
			'read' => array( self::REMOTE_DISCOVER_ABILITY, self::REMOTE_INSPECT_ABILITY, self::REMOTE_IMPORT_PLAN_ABILITY, self::REMOTE_MANIFEST_PLAN_ABILITY, self::REMOTE_PROVENANCE_GET_ABILITY, self::REMOTE_RECOVERY_STATUS_ABILITY ),
			'content' => array( self::REMOTE_IMPORT_APPLY_ABILITY ),
			'admin' => array(),
		);
	}

	public function register_abilities() {
		$this->add_ability(
			self::REMOTE_DISCOVER_ABILITY, 'Discover Remote Image Candidates', 'remote_source_discover',
			array( 'MAD4B_SCP_Policy', 'can_read' ),
			$this->schema( array(
				'source_page_url' => array( 'type' => 'string', 'minLength' => 8, 'maxLength' => 8192 ),
				'limit' => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => self::MAX_REMOTE_CANDIDATES, 'default' => 20 ),
				'same_origin_only' => array( 'type' => 'boolean', 'default' => false ),
			), array( 'source_page_url' ) )
		);
		$this->add_ability(
			self::REMOTE_INSPECT_ABILITY, 'Inspect Remote Image Bytes', 'remote_image_inspect',
			array( 'MAD4B_SCP_Policy', 'can_read' ),
			$this->schema( array(
				'source_url' => array( 'type' => 'string', 'minLength' => 8, 'maxLength' => 8192 ),
				'filename' => array( 'type' => 'string', 'maxLength' => 180, 'default' => '' ),
			), array( 'source_url' ) )
		);
		$this->add_ability(
			self::REMOTE_IMPORT_PLAN_ABILITY, 'Plan Remote Image Import', 'remote_import_plan',
			array( 'MAD4B_SCP_Policy', 'can_read' ), $this->remote_import_schema( false )
		);
		$this->add_ability(
			self::REMOTE_MANIFEST_PLAN_ABILITY, 'Plan Ordered Remote Image Import Manifest', 'remote_import_manifest_plan',
			array( 'MAD4B_SCP_Policy', 'can_read' ), $this->remote_manifest_schema()
		);
		$this->add_ability(
			self::REMOTE_PROVENANCE_GET_ABILITY, 'Get Remote Media Provenance', 'remote_provenance_get',
			array( 'MAD4B_SCP_Policy', 'can_read' ),
			$this->schema( array( 'attachment_id' => array( 'type' => 'integer', 'minimum' => 1 ) ), array( 'attachment_id' ) )
		);
		$this->add_ability(
			self::REMOTE_RECOVERY_STATUS_ABILITY, 'Get Remote Media Recovery Status', 'remote_recovery_status',
			array( 'MAD4B_SCP_Policy', 'can_read' ),
			$this->schema( array( 'manifest_sha256' => array( 'type' => 'string', 'maxLength' => 64, 'pattern' => '^(?:|[a-fA-F0-9]{64})$', 'default' => '' ) ) )
		);
		$this->add_ability(
			self::REMOTE_IMPORT_APPLY_ABILITY, 'Import Remote Image to Media Library', 'remote_import_apply',
			array( $this, 'can_import_remote' ), $this->remote_import_schema( true ),
			'content', false, true, true
		);
	}

	private function remote_import_schema( $require_plan ) {
		$properties = array(
			'source_url' => array( 'type' => 'string', 'minLength' => 8, 'maxLength' => 8192 ),
			'source_page_url' => array( 'type' => 'string', 'maxLength' => 8192, 'default' => '' ),
			'source_page_sha256' => array( 'type' => 'string', 'maxLength' => 64, 'pattern' => '^(?:|[a-fA-F0-9]{64})$' ),
			'rights_basis' => array( 'type' => 'string', 'enum' => array( 'unknown', 'owned', 'licensed', 'permission', 'public_domain', 'creative_commons' ), 'default' => 'unknown' ),
			'rights_note' => array( 'type' => 'string', 'maxLength' => 2000, 'default' => '' ),
			'rights_reference' => array( 'type' => 'string', 'maxLength' => 8192, 'default' => '' ),
			'license_expires_on' => array( 'type' => 'string', 'maxLength' => 10, 'default' => '' ),
			'duplicate_policy' => array( 'type' => 'string', 'enum' => array( 'reuse', 'fail' ), 'default' => 'reuse' ),
			'filename' => array( 'type' => 'string', 'maxLength' => 180, 'default' => '' ),
			'title' => array( 'type' => 'string', 'maxLength' => 1000, 'default' => '' ),
			'caption' => array( 'type' => 'string', 'maxLength' => 65535, 'default' => '' ),
			'description' => array( 'type' => 'string', 'maxLength' => 262144, 'default' => '' ),
			'alt' => array( 'type' => 'string', 'maxLength' => 2048, 'default' => '' ),
			'expected_content_sha256' => array( 'type' => 'string', 'maxLength' => 64, 'pattern' => '^(?:|[a-fA-F0-9]{64})$' ),
			'expected_content_bytes' => array( 'type' => 'integer', 'minimum' => 0, 'maximum' => self::MAX_REMOTE_IMAGE_BYTES ),
			'expected_mime_type' => array( 'type' => 'string', 'maxLength' => 64, 'default' => '' ),
			'expected_width' => array( 'type' => 'integer', 'minimum' => 0, 'maximum' => 12000 ),
			'expected_height' => array( 'type' => 'integer', 'minimum' => 0, 'maximum' => 12000 ),
			'manifest_sha256' => array( 'type' => 'string', 'maxLength' => 64, 'pattern' => '^(?:|[a-fA-F0-9]{64})$', 'default' => '' ),
			'manifest_index' => array( 'type' => 'integer', 'minimum' => 0, 'maximum' => self::MAX_REMOTE_CANDIDATES - 1 ),
			'manifest_item_sha256' => array( 'type' => 'string', 'maxLength' => 64, 'pattern' => '^(?:|[a-fA-F0-9]{64})$', 'default' => '' ),
			'manifest_binding_role' => array( 'type' => 'string', 'enum' => array( 'featured', 'gallery', 'content', 'field', 'shared' ), 'default' => 'gallery' ),
		);
		$required = array( 'source_url' );
		if ( $require_plan ) {
			$properties['plan_sha256'] = array( 'type' => 'string', 'minLength' => 64, 'maxLength' => 64, 'pattern' => '^[a-fA-F0-9]{64}$' );
			$required[] = 'plan_sha256';
		}
		return $this->schema( $properties, $required );
	}

	private function remote_manifest_schema() {
		$import_schema = $this->remote_import_schema( false );
		return $this->schema( array(
			'items' => array(
				'type' => 'array', 'minItems' => 1, 'maxItems' => self::MAX_REMOTE_CANDIDATES,
				'items' => array(
					'type' => 'object',
					'properties' => array(
						'binding_role' => array( 'type' => 'string', 'enum' => array( 'featured', 'gallery', 'content', 'field', 'shared' ), 'default' => 'gallery' ),
						'import' => $import_schema,
					),
					'required' => array( 'import' ),
					'additionalProperties' => false,
				),
			),
		), array( 'items' ) );
	}

	public function can_import_remote( $input = array() ) {
		return current_user_can( 'upload_files' ) ? true : new WP_Error( 'mad4b_remote_media_upload_denied', 'Current user cannot upload media.' );
	}

	public function remote_recovery_status( $input = array() ) {
		$input = is_array( $input ) ? $input : array();
		return MAD4B_SCP_Remote_Media_Recovery::status( isset( $input['manifest_sha256'] ) ? $input['manifest_sha256'] : '' );
	}

	public function remote_provenance_get( $input ) {
		$id = isset( $input['attachment_id'] ) ? absint( $input['attachment_id'] ) : 0;
		if ( $id < 1 || 'attachment' !== get_post_type( $id ) || ! current_user_can( 'read_post', $id ) ) {
			return new WP_Error( 'mad4b_remote_media_provenance_read_denied', 'Remote media provenance target is unavailable or unreadable.' );
		}
		$source_hashes = array_values( array_filter( array_map( 'strval', (array) get_post_meta( $id, self::REMOTE_SOURCE_HASH_META, false ) ) ) );
		$content_hash = strtolower( trim( (string) get_post_meta( $id, self::REMOTE_CONTENT_HASH_META, true ) ) );
		$history = array_values( array_filter( (array) get_post_meta( $id, self::REMOTE_PROVENANCE_META, false ), static function ( $row ) {
			return is_array( $row ) && self::REMOTE_PROVENANCE_CONTRACT === ( isset( $row['contract'] ) ? (string) $row['contract'] : '' );
		} ) );
		return array(
			'contract' => 'mad4b.remote-media-provenance-read.v1',
			'attachment_id' => $id,
			'is_remote' => ! empty( $source_hashes ) || '' !== $content_hash || ! empty( $history ),
			'source_url_sha256' => $source_hashes,
			'content_sha256' => $content_hash,
			'event_count' => count( $history ),
			'latest' => $history ? end( $history ) : null,
			'history' => array_slice( $history, -self::MAX_REMOTE_PROVENANCE_EVENTS ),
			'mutation_performed' => false,
		);
	}

	public function remote_source_discover( $input ) {
		$input = is_array( $input ) ? $input : array();
		$page_url = $this->normalize_https_url( isset( $input['source_page_url'] ) ? $input['source_page_url'] : '' );
		if ( is_wp_error( $page_url ) ) return $page_url;
		$limit = isset( $input['limit'] ) ? max( 1, min( self::MAX_REMOTE_CANDIDATES, absint( $input['limit'] ) ) ) : 20;
		$same_origin_only = ! empty( $input['same_origin_only'] );
		if ( ! class_exists( 'MAD4B_SCP_Egress_Policy' ) ) return new WP_Error( 'mad4b_remote_media_egress_unavailable', 'Remote media discovery requires the governed egress policy.' );

		$args = array(
			'timeout' => 15,
			'redirection' => 0,
			'reject_unsafe_urls' => true,
			'limit_response_size' => self::MAX_REMOTE_PAGE_BYTES + 1,
			'headers' => array( 'Accept' => 'text/html,application/xhtml+xml;q=0.9' ),
		);
		$args = MAD4B_SCP_Egress_Policy::mark_request( 'remote_media_discovery', $page_url, $this->url_origin( $page_url ), $args );
		if ( is_wp_error( $args ) ) return $args;
		$response = wp_safe_remote_get( $page_url, $args );
		if ( is_wp_error( $response ) ) return MAD4B_SCP_Egress_Policy::classify_transport_error( $response, 'remote_media_discovery' );
		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( 200 !== $code ) return new WP_Error( 'mad4b_remote_media_source_http_status', 'Remote source page did not return HTTP 200.', array( 'status' => $code ) );
		$content_type = strtolower( trim( (string) wp_remote_retrieve_header( $response, 'content-type' ) ) );
		if ( false === strpos( $content_type, 'text/html' ) && false === strpos( $content_type, 'application/xhtml+xml' ) ) return new WP_Error( 'mad4b_remote_media_source_content_type', 'Remote media discovery accepts HTML source pages only.' );
		$body = (string) wp_remote_retrieve_body( $response );
		if ( '' === $body || strlen( $body ) > self::MAX_REMOTE_PAGE_BYTES ) return new WP_Error( 'mad4b_remote_media_source_size', 'Remote source page is empty or exceeds the bounded discovery size.' );
		$candidates = $this->extract_remote_image_candidates( $body, $page_url, $limit, $same_origin_only );
		if ( is_wp_error( $candidates ) ) return $candidates;
		$source_page_sha256 = hash( 'sha256', $body );
		foreach ( $candidates as &$candidate ) {
			$candidate['source_page_sha256'] = $source_page_sha256;
			$candidate['inspect_input'] = array( 'source_url' => $candidate['source_url'] );
			$candidate['import_input_template'] = array(
				'source_url' => $candidate['source_url'],
				'source_page_url' => $page_url,
				'source_page_sha256' => $source_page_sha256,
				'rights_basis' => 'unknown',
				'alt' => isset( $candidate['alt'] ) ? (string) $candidate['alt'] : '',
				'title' => isset( $candidate['title'] ) ? (string) $candidate['title'] : '',
			);
			$candidate['candidate_sha256'] = hash( 'sha256', wp_json_encode( $candidate, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
		}
		unset( $candidate );
		return array(
			'contract' => 'mad4b.remote-media-discovery.v1',
			'source_page_url' => $page_url,
			'source_page_sha256' => $source_page_sha256,
			'candidate_count' => count( $candidates ),
			'candidates' => $candidates,
			'inspect_ability' => self::REMOTE_INSPECT_ABILITY,
			'import_plan_ability' => self::REMOTE_IMPORT_PLAN_ABILITY,
			'auto_select' => false,
			'semantic_review_required' => ! empty( $candidates ),
			'mutation_performed' => false,
		);
	}

	public function remote_image_inspect( $input ) {
		$normalized = $this->normalize_remote_import_input( is_array( $input ) ? $input : array() );
		if ( is_wp_error( $normalized ) ) return $normalized;
		$download = $this->download_remote_image( $normalized, 'remote_media_inspect' );
		if ( is_wp_error( $download ) ) return $download;
		$evidence = array(
			'contract' => 'mad4b.remote-media-inspection.v1',
			'source_url' => $normalized['source_url'],
			'source_url_sha256' => $normalized['source_url_sha256'],
			'content_sha256' => $download['content_sha256'],
			'bytes' => $download['bytes'],
			'width' => $download['width'],
			'height' => $download['height'],
			'mime_type' => $download['mime_type'],
			'mutation_performed' => false,
		);
		$evidence['evidence_sha256'] = hash( 'sha256', wp_json_encode( $evidence, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
		if ( is_file( $download['tmp_name'] ) ) @unlink( $download['tmp_name'] );
		return $evidence;
	}

	public function remote_import_plan( $input ) {
		$normalized = $this->normalize_remote_import_input( is_array( $input ) ? $input : array() );
		if ( is_wp_error( $normalized ) ) return $normalized;
		$source_state = $this->remote_state_for_source_hash( $normalized['source_url_sha256'] );
		if ( is_wp_error( $source_state ) ) return $source_state;
		$content_state = '' !== $normalized['expected_content_sha256']
			? $this->remote_state_for_content_hash( $normalized['expected_content_sha256'] )
			: array( 'exists' => false, 'content_sha256' => '' );
		if ( is_wp_error( $content_state ) ) return $content_state;

		$blockers = array();
		if ( 'unknown' === $normalized['rights_basis'] ) $blockers[] = 'rights_confirmation_required';
		elseif ( 'owned' !== $normalized['rights_basis'] && '' === $normalized['rights_note'] && '' === $normalized['rights_reference'] ) $blockers[] = 'rights_evidence_required';
		$reuse_available = ! empty( $source_state['exists'] ) || ! empty( $content_state['exists'] );
		if ( ! $reuse_available && '' === $normalized['expected_content_sha256'] ) $blockers[] = 'content_inspection_required';
		if ( $reuse_available && 'fail' === $normalized['duplicate_policy'] ) $blockers[] = ! empty( $source_state['exists'] ) ? 'source_already_imported' : 'content_already_imported';
		if ( ! empty( $source_state['exists'] ) && '' !== $normalized['expected_content_sha256'] && ! empty( $source_state['content_sha256'] )
			&& ! hash_equals( $normalized['expected_content_sha256'], (string) $source_state['content_sha256'] ) ) {
			$blockers[] = 'source_identity_content_conflict';
		}

		$plan = array(
			'contract' => self::REMOTE_IMPORT_CONTRACT,
			'ready' => empty( $blockers ),
			'blockers' => array_values( array_unique( $blockers ) ),
			'normalized_input' => $normalized,
			'existing_source_state' => $source_state,
			'existing_content_state' => $content_state,
			'exact_content_locked' => '' !== $normalized['expected_content_sha256'] || ! empty( $source_state['exists'] ),
			'library_first' => true,
			'post_binding_after_import_only' => true,
			'mutation_performed' => false,
		);
		$plan['plan_sha256'] = hash( 'sha256', wp_json_encode( $plan, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
		return $plan;
	}

	public function remote_import_manifest_plan( $input ) {
		$items = isset( $input['items'] ) && is_array( $input['items'] ) ? array_values( $input['items'] ) : array();
		if ( empty( $items ) || count( $items ) > self::MAX_REMOTE_CANDIDATES ) return new WP_Error( 'mad4b_remote_media_manifest_items_invalid', 'Remote media manifest requires a bounded non-empty ordered item list.' );
		$out = array(); $blockers = array(); $featured = 0;
		foreach ( $items as $index => $item ) {
			if ( ! is_array( $item ) || empty( $item['import'] ) || ! is_array( $item['import'] ) ) {
				$blockers[] = 'item_' . $index . '_invalid';
				$out[] = array( 'index' => $index, 'ready' => false, 'blockers' => array( 'invalid_item' ) );
				continue;
			}
			$role = isset( $item['binding_role'] ) ? sanitize_key( (string) $item['binding_role'] ) : 'gallery';
			if ( ! in_array( $role, array( 'featured', 'gallery', 'content' ), true ) ) $role = 'gallery';
			if ( 'featured' === $role ) ++$featured;
			$plan = $this->remote_import_plan( $item['import'] );
			if ( is_wp_error( $plan ) ) {
				$blockers[] = 'item_' . $index . '_' . sanitize_key( $plan->get_error_code() );
				$out[] = array( 'index' => $index, 'binding_role' => $role, 'ready' => false, 'blockers' => array( sanitize_key( $plan->get_error_code() ) ) );
				continue;
			}
			foreach ( (array) $plan['blockers'] as $reason ) $blockers[] = 'item_' . $index . '_' . sanitize_key( (string) $reason );
			$item_identity = array( 'index' => $index, 'binding_role' => $role, 'plan_sha256' => $plan['plan_sha256'] );
			$out[] = array(
				'index' => $index, 'binding_role' => $role,
				'item_sha256' => hash( 'sha256', wp_json_encode( $item_identity, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) ),
				'ready' => ! empty( $plan['ready'] ), 'blockers' => array_values( (array) $plan['blockers'] ),
				'plan_sha256' => $plan['plan_sha256'],
				'apply_input' => array_merge( $plan['normalized_input'], array( 'plan_sha256' => $plan['plan_sha256'] ) ),
			);
		}
		if ( $featured > 1 ) $blockers[] = 'multiple_featured_candidates';
		$manifest = array(
			'contract' => 'mad4b.remote-media-import-manifest.v1',
			'ready' => empty( $blockers ),
			'blockers' => array_values( array_unique( $blockers ) ),
			'item_count' => count( $out ),
			'items' => $out,
			'execution_model' => 'ordered_checkpointed_per_item_apply',
			'partial_success_semantics' => 'verified_media_library_assets_survive_later_item_or_post_failure',
			'mutation_performed' => false,
		);
		$manifest['manifest_sha256'] = hash( 'sha256', wp_json_encode( $manifest, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
		foreach ( $manifest['items'] as $index => &$manifest_item ) {
			if ( empty( $manifest_item['apply_input'] ) || ! is_array( $manifest_item['apply_input'] ) ) continue;
			$manifest_item['apply_input']['manifest_sha256'] = $manifest['manifest_sha256'];
			$manifest_item['apply_input']['manifest_index'] = (int) $index;
			$manifest_item['apply_input']['manifest_item_sha256'] = isset( $manifest_item['item_sha256'] ) ? $manifest_item['item_sha256'] : '';
			$manifest_item['apply_input']['manifest_binding_role'] = isset( $manifest_item['binding_role'] ) ? $manifest_item['binding_role'] : 'gallery';
		}
		unset( $manifest_item );
		$manifest['manifest_hash_scope'] = 'canonical_manifest_before_execution_correlation';
		return $manifest;
	}

	public function remote_import_apply( $input ) {
		$input = is_array( $input ) ? $input : array();
		$expected = isset( $input['plan_sha256'] ) ? strtolower( trim( (string) $input['plan_sha256'] ) ) : '';
		$manifest_sha256 = isset( $input['manifest_sha256'] ) ? strtolower( trim( (string) $input['manifest_sha256'] ) ) : '';
		$manifest_index = isset( $input['manifest_index'] ) ? (int) $input['manifest_index'] : -1;
		$manifest_item_sha256 = isset( $input['manifest_item_sha256'] ) ? strtolower( trim( (string) $input['manifest_item_sha256'] ) ) : '';
		$manifest_binding_role = isset( $input['manifest_binding_role'] ) ? sanitize_key( (string) $input['manifest_binding_role'] ) : '';
		$plan_input = $input;
		unset( $plan_input['plan_sha256'], $plan_input['manifest_sha256'], $plan_input['manifest_index'], $plan_input['manifest_item_sha256'], $plan_input['manifest_binding_role'], $plan_input['_mad4b_approval_ticket_id'], $plan_input['_mad4b_context_receipt'] );
		$plan = $this->remote_import_plan( $plan_input );
		if ( is_wp_error( $plan ) ) return $plan;
		if ( '' === $expected || ! hash_equals( (string) $plan['plan_sha256'], $expected ) ) return new WP_Error( 'mad4b_remote_media_import_plan_drift', 'Remote media import no longer matches the exact reviewed plan.' );
		if ( empty( $plan['ready'] ) ) return new WP_Error( 'mad4b_remote_media_import_blocked', 'Remote media import is blocked until its plan blockers are resolved.', array( 'blockers' => $plan['blockers'] ) );

		$normalized = $plan['normalized_input'];
		$lock_names = array();
		if ( class_exists( 'MAD4B_SCP_Distributed_Lock' ) ) {
			$lock_names[] = MAD4B_SCP_Distributed_Lock::catalog_name( 'remote-media-source-' . $normalized['source_url_sha256'] );
			if ( '' !== $normalized['expected_content_sha256'] ) $lock_names[] = MAD4B_SCP_Distributed_Lock::catalog_name( 'remote-media-content-' . $normalized['expected_content_sha256'] );
			$lock_names = array_values( array_unique( array_filter( $lock_names ) ) );
			sort( $lock_names, SORT_STRING );
		}
		$held_locks = array();
		foreach ( $lock_names as $lock_name ) {
			$locked = MAD4B_SCP_Distributed_Lock::acquire( $lock_name );
			if ( is_wp_error( $locked ) ) {
				foreach ( array_reverse( $held_locks ) as $held_lock ) MAD4B_SCP_Distributed_Lock::release( $held_lock );
				return new WP_Error( 'mad4b_remote_media_import_in_progress', 'This remote media source or exact content identity is already being imported.', array( 'cause' => $locked->get_error_code() ) );
			}
			$held_locks[] = $lock_name;
		}
		try {
			$source_state = $this->remote_state_for_source_hash( $normalized['source_url_sha256'] );
			if ( is_wp_error( $source_state ) ) return $source_state;
			if ( ! empty( $source_state['exists'] ) ) {
				$source_content_hash = ! empty( $source_state['content_sha256'] ) ? (string) $source_state['content_sha256'] : (string) get_post_meta( (int) $source_state['attachment_id'], self::REMOTE_CONTENT_HASH_META, true );
				$event = $this->append_remote_provenance( (int) $source_state['attachment_id'], $normalized, $source_content_hash, $plan['plan_sha256'], 'source_url_reuse' );
				if ( is_wp_error( $event ) ) return $event;
				return $this->remote_reuse_result( (int) $source_state['attachment_id'], $normalized, $plan, 'source_url', $event, $manifest_sha256, $manifest_index, $manifest_item_sha256, $manifest_binding_role );
			}

			$content_state = '' !== $normalized['expected_content_sha256']
				? $this->remote_state_for_content_hash( $normalized['expected_content_sha256'] )
				: array( 'exists' => false );
			if ( is_wp_error( $content_state ) ) return $content_state;
			if ( ! empty( $content_state['exists'] ) ) {
				$bound = $this->append_remote_provenance( (int) $content_state['attachment_id'], $normalized, $normalized['expected_content_sha256'], $plan['plan_sha256'], 'content_sha256_reuse' );
				if ( is_wp_error( $bound ) ) return $bound;
				return $this->remote_reuse_result( (int) $content_state['attachment_id'], $normalized, $plan, 'content_sha256', $bound, $manifest_sha256, $manifest_index, $manifest_item_sha256, $manifest_binding_role );
			}

			$download = $this->download_remote_image( $normalized, 'remote_media_import' );
			if ( is_wp_error( $download ) ) return $download;
			$tmp = $download['tmp_name'];
			$evidence_guard = $this->verify_download_against_plan( $download, $normalized );
			if ( is_wp_error( $evidence_guard ) ) { if ( is_file( $tmp ) ) @unlink( $tmp ); return $evidence_guard; }

			// A different URL may resolve to bytes already present in the Media Library.
			$content_state = $this->remote_state_for_content_hash( $download['content_sha256'] );
			if ( is_wp_error( $content_state ) ) { if ( is_file( $tmp ) ) @unlink( $tmp ); return $content_state; }
			if ( ! empty( $content_state['exists'] ) ) {
				if ( is_file( $tmp ) ) @unlink( $tmp );
				if ( 'fail' === $normalized['duplicate_policy'] ) return new WP_Error( 'mad4b_remote_media_content_duplicate', 'Downloaded remote image bytes already exist in the Media Library.' );
				$bound = $this->append_remote_provenance( (int) $content_state['attachment_id'], $normalized, $download['content_sha256'], $plan['plan_sha256'], 'content_sha256_reuse' );
				if ( is_wp_error( $bound ) ) return $bound;
				return $this->remote_reuse_result( (int) $content_state['attachment_id'], $normalized, $plan, 'content_sha256', $bound, $manifest_sha256, $manifest_index );
			}

			if ( ! function_exists( 'media_handle_sideload' ) ) {
				require_once ABSPATH . 'wp-admin/includes/file.php';
				require_once ABSPATH . 'wp-admin/includes/media.php';
				require_once ABSPATH . 'wp-admin/includes/image.php';
			}
			$file = array( 'name' => $download['filename'], 'tmp_name' => $tmp, 'type' => $download['mime_type'], 'error' => 0, 'size' => $download['bytes'] );
			$attachment_id = media_handle_sideload( $file, 0, '' );
			if ( is_wp_error( $attachment_id ) ) { if ( is_file( $tmp ) ) @unlink( $tmp ); return $attachment_id; }
			$attachment_id = absint( $attachment_id );
			if ( $attachment_id < 1 || 'attachment' !== get_post_type( $attachment_id ) || ! wp_attachment_is_image( $attachment_id ) ) {
				if ( $attachment_id > 0 ) wp_delete_attachment( $attachment_id, true );
				return new WP_Error( 'mad4b_remote_media_import_attachment_invalid', 'WordPress did not create a valid image attachment.' );
			}

			update_post_meta( $attachment_id, self::REMOTE_CONTENT_HASH_META, $download['content_sha256'] );
			$bound = $this->append_remote_provenance( $attachment_id, $normalized, $download['content_sha256'], $plan['plan_sha256'], 'created' );
			if ( is_wp_error( $bound ) ) { wp_delete_attachment( $attachment_id, true ); return $bound; }

			$current = $this->media_get( array( 'attachment_id' => $attachment_id ) );
			if ( is_wp_error( $current ) ) { wp_delete_attachment( $attachment_id, true ); return $current; }
			$metadata_input = array( 'attachment_id' => $attachment_id, 'expected_sha256' => $current['sha256'] );
			foreach ( array( 'title', 'caption', 'description', 'alt' ) as $field ) if ( '' !== (string) $normalized[ $field ] ) $metadata_input[ $field ] = $normalized[ $field ];
			if ( count( $metadata_input ) > 2 ) {
				$metadata_result = $this->media_update_metadata( $metadata_input );
				if ( is_wp_error( $metadata_result ) ) { wp_delete_attachment( $attachment_id, true ); return $metadata_result; }
			}

			$attached_file = get_attached_file( $attachment_id, true );
			if ( ! $attached_file || ! is_file( $attached_file ) || ! hash_equals( $download['content_sha256'], hash_file( 'sha256', $attached_file ) ) ) {
				wp_delete_attachment( $attachment_id, true );
				return new WP_Error( 'mad4b_remote_media_import_content_readback_failed', 'Imported image bytes failed exact content-hash readback.' );
			}
			$readback = $this->media_get( array( 'attachment_id' => $attachment_id ) );
			if ( is_wp_error( $readback ) ) { wp_delete_attachment( $attachment_id, true ); return $readback; }
			MAD4B_SCP_Audit::record( self::REMOTE_IMPORT_APPLY_ABILITY, array(
				'attachment_id' => $attachment_id,
				'source_url_sha256' => $normalized['source_url_sha256'],
				'content_sha256' => $download['content_sha256'],
				'bytes' => $download['bytes'],
				'rights_basis' => $normalized['rights_basis'],
				'dedupe_basis' => 'created',
			) );
			return MAD4B_SCP_Remote_Media_Recovery::stage_import_result( array(
				'contract' => self::REMOTE_IMPORT_CONTRACT, 'attachment_id' => $attachment_id,
				'created' => true, 'reused' => false, 'dedupe_basis' => 'created', 'verified' => true,
				'media' => $readback['media'], 'provenance_event' => $bound,
				'binding_template' => $this->post_binding_template( $attachment_id, $normalized, $bound ),
				'plan_sha256' => $plan['plan_sha256'],
			), $manifest_sha256, $manifest_index, true, $manifest_item_sha256, $manifest_binding_role );
		} finally {
			foreach ( array_reverse( $held_locks ) as $held_lock ) MAD4B_SCP_Distributed_Lock::release( $held_lock );
		}
	}

	private function media_adapter() {
		if ( ! class_exists( 'MAD4B_SCP_Adapter_Registry' ) ) return null;
		$adapter = MAD4B_SCP_Adapter_Registry::instance()->get( 'media' );
		return $adapter instanceof MAD4B_SCP_Media_Adapter ? $adapter : null;
	}

	private function media_get( $input ) {
		$adapter = $this->media_adapter();
		return $adapter ? $adapter->get_media( $input ) : new WP_Error( 'mad4b_remote_media_library_adapter_unavailable', 'Canonical Media Library adapter is unavailable.' );
	}

	private function media_update_metadata( $input ) {
		$adapter = $this->media_adapter();
		return $adapter ? $adapter->update_metadata( $input ) : new WP_Error( 'mad4b_remote_media_library_adapter_unavailable', 'Canonical Media Library adapter is unavailable.' );
	}

	private function normalize_remote_import_input( array $input ) {
		$source_url = $this->normalize_https_url( isset( $input['source_url'] ) ? $input['source_url'] : '' );
		if ( is_wp_error( $source_url ) ) return $source_url;
		$source_page_url = '';
		if ( isset( $input['source_page_url'] ) && '' !== trim( (string) $input['source_page_url'] ) ) {
			$source_page_url = $this->normalize_https_url( $input['source_page_url'] );
			if ( is_wp_error( $source_page_url ) ) return $source_page_url;
		}
		$source_page_sha256 = isset( $input['source_page_sha256'] ) ? strtolower( trim( (string) $input['source_page_sha256'] ) ) : '';
		if ( '' !== $source_page_sha256 && ! preg_match( '/^[a-f0-9]{64}$/', $source_page_sha256 ) ) return new WP_Error( 'mad4b_remote_media_source_page_hash_invalid', 'Source page SHA-256 is invalid.' );
		$rights_reference = '';
		if ( isset( $input['rights_reference'] ) && '' !== trim( (string) $input['rights_reference'] ) ) {
			$rights_reference = $this->normalize_https_url( $input['rights_reference'] );
			if ( is_wp_error( $rights_reference ) ) return new WP_Error( 'mad4b_remote_media_rights_reference_invalid', 'Rights reference must be an explicit HTTPS URL without credentials.' );
		}
		$rights_basis = isset( $input['rights_basis'] ) ? sanitize_key( (string) $input['rights_basis'] ) : 'unknown';
		if ( ! in_array( $rights_basis, array( 'unknown', 'owned', 'licensed', 'permission', 'public_domain', 'creative_commons' ), true ) ) return new WP_Error( 'mad4b_remote_media_rights_basis_invalid', 'Unsupported remote media rights basis.' );
		$duplicate_policy = isset( $input['duplicate_policy'] ) ? sanitize_key( (string) $input['duplicate_policy'] ) : 'reuse';
		if ( ! in_array( $duplicate_policy, array( 'reuse', 'fail' ), true ) ) return new WP_Error( 'mad4b_remote_media_duplicate_policy_invalid', 'duplicate_policy must be reuse or fail.' );
		$license_expires_on = isset( $input['license_expires_on'] ) ? trim( (string) $input['license_expires_on'] ) : '';
		if ( '' !== $license_expires_on ) {
			if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $license_expires_on ) ) return new WP_Error( 'mad4b_remote_media_license_date_invalid', 'Remote media license expiry must use YYYY-MM-DD.' );
			$parts = array_map( 'intval', explode( '-', $license_expires_on ) );
			if ( 3 !== count( $parts ) || ! checkdate( $parts[1], $parts[2], $parts[0] ) ) return new WP_Error( 'mad4b_remote_media_license_date_invalid', 'Remote media license expiry is not a valid date.' );
		}
		$filename = isset( $input['filename'] ) ? sanitize_file_name( (string) $input['filename'] ) : '';
		if ( '' === $filename ) {
			$path = (string) wp_parse_url( $source_url, PHP_URL_PATH );
			$filename = sanitize_file_name( basename( $path ) );
		}
		if ( '' === $filename || strlen( $filename ) > 180 ) $filename = 'remote-image';
		$expected_content_sha256 = isset( $input['expected_content_sha256'] ) ? strtolower( trim( (string) $input['expected_content_sha256'] ) ) : '';
		if ( '' !== $expected_content_sha256 && ! preg_match( '/^[a-f0-9]{64}$/', $expected_content_sha256 ) ) return new WP_Error( 'mad4b_remote_media_expected_hash_invalid', 'Expected remote media content SHA-256 is invalid.' );
		$expected_mime_type = isset( $input['expected_mime_type'] ) ? strtolower( trim( (string) $input['expected_mime_type'] ) ) : '';
		if ( '' !== $expected_mime_type && ! in_array( $expected_mime_type, array( 'image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/avif' ), true ) ) return new WP_Error( 'mad4b_remote_media_expected_mime_invalid', 'Expected remote media MIME type is outside the supported image allowlist.' );

		return array(
			'source_url' => $source_url,
			'source_url_sha256' => hash( 'sha256', $source_url ),
			'source_page_url' => $source_page_url,
			'source_page_sha256' => $source_page_sha256,
			'rights_basis' => $rights_basis,
			'rights_note' => sanitize_textarea_field( isset( $input['rights_note'] ) ? (string) $input['rights_note'] : '' ),
			'rights_reference' => $rights_reference,
			'license_expires_on' => $license_expires_on,
			'duplicate_policy' => $duplicate_policy,
			'filename' => $filename,
			'title' => sanitize_text_field( isset( $input['title'] ) ? (string) $input['title'] : '' ),
			'caption' => sanitize_textarea_field( isset( $input['caption'] ) ? (string) $input['caption'] : '' ),
			'description' => wp_kses_post( isset( $input['description'] ) ? (string) $input['description'] : '' ),
			'alt' => sanitize_text_field( isset( $input['alt'] ) ? (string) $input['alt'] : '' ),
			'expected_content_sha256' => $expected_content_sha256,
			'expected_content_bytes' => isset( $input['expected_content_bytes'] ) ? max( 0, min( self::MAX_REMOTE_IMAGE_BYTES, absint( $input['expected_content_bytes'] ) ) ) : 0,
			'expected_mime_type' => $expected_mime_type,
			'expected_width' => isset( $input['expected_width'] ) ? max( 0, min( 12000, absint( $input['expected_width'] ) ) ) : 0,
			'expected_height' => isset( $input['expected_height'] ) ? max( 0, min( 12000, absint( $input['expected_height'] ) ) ) : 0,
		);
	}

	private function normalize_https_url( $value, $base_url = '' ) {
		$value = html_entity_decode( trim( (string) $value ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		if ( '' === $value || strlen( $value ) > 8192 ) return new WP_Error( 'mad4b_remote_media_url_invalid', 'Remote media URL must be a bounded explicit HTTPS URL.' );
		if ( ! preg_match( '#^https://#i', $value ) ) {
			if ( '' === $base_url ) return new WP_Error( 'mad4b_remote_media_url_https_required', 'Remote media URL must use explicit HTTPS.' );
			$base = wp_parse_url( $base_url );
			if ( ! is_array( $base ) || empty( $base['host'] ) ) return new WP_Error( 'mad4b_remote_media_base_url_invalid', 'Remote media base URL is invalid.' );
			$origin = 'https://' . strtolower( (string) $base['host'] );
			if ( 0 === strpos( $value, '//' ) ) $value = 'https:' . $value;
			elseif ( 0 === strpos( $value, '/' ) ) $value = $origin . $value;
			else {
				$base_path = isset( $base['path'] ) ? (string) $base['path'] : '/';
				$dir = preg_replace( '#/[^/]*$#', '/', $base_path );
				$value = $origin . $dir . $value;
			}
		}
		$parts = wp_parse_url( $value );
		if ( ! is_array( $parts ) || 'https' !== strtolower( isset( $parts['scheme'] ) ? (string) $parts['scheme'] : '' ) || empty( $parts['host'] )
			|| isset( $parts['user'] ) || isset( $parts['pass'] ) || ( isset( $parts['port'] ) && 443 !== (int) $parts['port'] ) ) {
			return new WP_Error( 'mad4b_remote_media_url_invalid', 'Remote media URL must be public HTTPS without credentials or nonstandard ports.' );
		}
		$host = strtolower( rtrim( (string) $parts['host'], '.' ) );
		$path = isset( $parts['path'] ) ? $this->normalize_url_path( (string) $parts['path'] ) : '/';
		$query = isset( $parts['query'] ) && '' !== (string) $parts['query'] ? '?' . (string) $parts['query'] : '';
		$normalized = esc_url_raw( 'https://' . $host . $path . $query, array( 'https' ) );
		return '' !== $normalized ? $normalized : new WP_Error( 'mad4b_remote_media_url_invalid', 'Remote media URL could not be normalized safely.' );
	}

	private function normalize_url_path( $path ) {
		$segments = explode( '/', '/' . ltrim( (string) $path, '/' ) );
		$out = array();
		foreach ( $segments as $segment ) {
			if ( '' === $segment || '.' === $segment ) continue;
			if ( '..' === $segment ) { array_pop( $out ); continue; }
			$out[] = $segment;
		}
		return '/' . implode( '/', $out );
	}

	private function url_origin( $url ) {
		$parts = wp_parse_url( (string) $url );
		return is_array( $parts ) && ! empty( $parts['host'] ) ? 'https://' . strtolower( rtrim( (string) $parts['host'], '.' ) ) : '';
	}

	private function same_origin( $left, $right ) {
		return '' !== $this->url_origin( $left ) && hash_equals( $this->url_origin( $left ), $this->url_origin( $right ) );
	}

	private function best_srcset_url( $srcset ) {
		$srcset = trim( (string) $srcset );
		if ( '' === $srcset ) return '';
		$best = ''; $best_score = -1.0; $matches = array();
		// Candidate separators commonly contain optional whitespace, while CDN
		// transformation URLs such as Cloudinary legitimately contain commas.
		// Anchor on the required width/density descriptor instead of explode(',').
		if ( preg_match_all( '/(?:^|,\s*)(\S+)\s+(\d+(?:\.\d+)?)([wx])(?=\s*(?:,|$))/', $srcset, $matches, PREG_SET_ORDER ) ) {
			foreach ( $matches as $match ) {
				$score = 'x' === $match[3] ? (float) $match[2] * 100000.0 : (float) $match[2];
				if ( $score > $best_score ) { $best = (string) $match[1]; $best_score = $score; }
			}
			if ( '' !== $best ) return $best;
		}
		// A descriptor-less single candidate is valid; multiple ambiguous
		// descriptor-less values are intentionally not guessed.
		return false === strpos( $srcset, ',' ) ? preg_split( '/\s+/', $srcset )[0] : '';
	}

	private function extract_remote_image_candidates( $html, $page_url, $limit, $same_origin_only ) {
		if ( ! class_exists( 'DOMDocument' ) ) return new WP_Error( 'mad4b_remote_media_dom_unavailable', 'Remote image discovery requires the PHP DOM extension.' );
		$limit = max( 1, min( self::MAX_REMOTE_CANDIDATES, absint( $limit ) ) );
		$collection_limit = min( self::MAX_REMOTE_CANDIDATES * 4, max( $limit, $limit * 4 ) );
		$previous = libxml_use_internal_errors( true );
		$dom = new DOMDocument();
		$loaded = $dom->loadHTML( (string) $html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING );
		libxml_clear_errors();
		libxml_use_internal_errors( $previous );
		if ( ! $loaded ) return new WP_Error( 'mad4b_remote_media_html_invalid', 'Remote source HTML could not be parsed.' );
		$xpath = new DOMXPath( $dom );
		$out = array(); $seen = array(); $source_order = 0;
		$append = function ( $raw_url, $kind, $alt = '', $title = '', $width = 0, $height = 0 ) use ( &$out, &$seen, &$source_order, $page_url, $collection_limit, $same_origin_only ) {
			if ( count( $out ) >= $collection_limit || '' === trim( (string) $raw_url ) ) return;
			$url = $this->normalize_https_url( $raw_url, $page_url );
			if ( is_wp_error( $url ) || ( $same_origin_only && ! $this->same_origin( $page_url, $url ) ) ) return;
			$digest = hash( 'sha256', $url );
			if ( isset( $seen[ $digest ] ) ) return;
			$seen[ $digest ] = true; ++$source_order;
			$score = $this->remote_candidate_score( $kind, $url, $alt, $title, $width, $height );
			$out[] = array(
				'source_url' => $url,
				'source_url_sha256' => $digest,
				'kind' => sanitize_key( (string) $kind ),
				'alt' => sanitize_text_field( (string) $alt ),
				'title' => sanitize_text_field( (string) $title ),
				'width_hint' => max( 0, (int) $width ),
				'height_hint' => max( 0, (int) $height ),
				'source_order' => $source_order,
				'selection_score' => $score['score'],
				'selection_signals' => $score['signals'],
				'likely_role' => $score['role'],
			);
		};
		foreach ( $xpath->query( '//meta[@content]' ) as $node ) {
			$key = strtolower( trim( (string) ( $node->getAttribute( 'property' ) ?: $node->getAttribute( 'name' ) ) ) );
			if ( in_array( $key, array( 'og:image', 'og:image:url', 'twitter:image', 'twitter:image:src' ), true ) ) $append( $node->getAttribute( 'content' ), str_replace( ':', '_', $key ) );
		}
		foreach ( $xpath->query( '//link[@href]' ) as $node ) {
			$rel = strtolower( trim( (string) $node->getAttribute( 'rel' ) ) );
			if ( in_array( $rel, array( 'image_src', 'preload' ), true ) && ( 'image_src' === $rel || 'image' === strtolower( trim( (string) $node->getAttribute( 'as' ) ) ) ) ) $append( $node->getAttribute( 'href' ), 'link_' . $rel );
		}
		foreach ( $xpath->query( '//script[@type="application/ld+json"]' ) as $node ) {
			$raw = trim( (string) $node->textContent );
			if ( '' === $raw || strlen( $raw ) > 524288 ) continue;
			$decoded = json_decode( $raw, true );
			if ( ! is_array( $decoded ) ) continue;
			$json_urls = array(); $json_nodes = 0;
			$this->collect_jsonld_image_urls( $decoded, $json_urls, 0, $json_nodes );
			foreach ( array_slice( array_values( array_unique( $json_urls ) ), 0, $collection_limit ) as $json_url ) $append( $json_url, 'jsonld_image' );
		}
		foreach ( $xpath->query( '//img' ) as $node ) {
			$alt = $node->getAttribute( 'alt' ); $title = $node->getAttribute( 'title' );
			$width = absint( $node->getAttribute( 'width' ) ); $height = absint( $node->getAttribute( 'height' ) );
			foreach ( array( 'src', 'data-src', 'data-lazy-src', 'data-original' ) as $attribute ) if ( $node->hasAttribute( $attribute ) ) $append( $node->getAttribute( $attribute ), 'img_' . str_replace( '-', '_', $attribute ), $alt, $title, $width, $height );
			foreach ( array( 'srcset', 'data-srcset' ) as $attribute ) if ( $node->hasAttribute( $attribute ) ) $append( $this->best_srcset_url( $node->getAttribute( $attribute ) ), 'img_' . str_replace( '-', '_', $attribute ), $alt, $title, $width, $height );
		}
		foreach ( $xpath->query( '//source[@srcset]' ) as $node ) $append( $this->best_srcset_url( $node->getAttribute( 'srcset' ) ), 'source_srcset' );
		usort( $out, static function ( $a, $b ) {
			$score = (int) $b['selection_score'] <=> (int) $a['selection_score'];
			return 0 !== $score ? $score : ( (int) $a['source_order'] <=> (int) $b['source_order'] );
		} );
		$out = array_slice( $out, 0, $limit );
		foreach ( $out as $index => &$row ) $row['selection_rank'] = $index + 1;
		unset( $row );
		return $out;
	}

	private function remote_candidate_score( $kind, $url, $alt, $title, $width, $height ) {
		$kind = sanitize_key( (string) $kind );
		$haystack = strtolower( (string) $url . ' ' . (string) $alt . ' ' . (string) $title );
		$score = 20; $signals = array();
		if ( 0 === strpos( $kind, 'og_image' ) || 0 === strpos( $kind, 'twitter_image' ) ) { $score += 25; $signals[] = 'social_primary_image'; }
		elseif ( 'jsonld_image' === $kind || 'link_image_src' === $kind ) { $score += 20; $signals[] = 'structured_image'; }
		elseif ( false !== strpos( $kind, 'srcset' ) ) { $score += 15; $signals[] = 'responsive_high_resolution_candidate'; }
		if ( '' !== trim( (string) $alt ) ) { $score += 10; $signals[] = 'alt_present'; }
		if ( '' !== trim( (string) $title ) ) { $score += 5; $signals[] = 'title_present'; }
		$width = max( 0, (int) $width ); $height = max( 0, (int) $height );
		if ( $width >= 1200 || $height >= 800 ) { $score += 20; $signals[] = 'large_dimension_hint'; }
		elseif ( $width >= 600 || $height >= 400 ) { $score += 10; $signals[] = 'medium_dimension_hint'; }
		if ( $width > 0 && $height > 0 && $width <= 64 && $height <= 64 ) { $score -= 55; $signals[] = 'tiny_asset'; }
		if ( preg_match( '/(?:logo|icon|sprite|avatar|flag|payment|badge|rating|star|loader|placeholder|tracking|pixel|favicon)/i', $haystack ) ) { $score -= 40; $signals[] = 'utility_asset_pattern'; }
		$score = max( 0, min( 100, $score ) );
		$role = $score >= 65 ? 'primary_candidate' : ( $score >= 35 ? 'gallery_candidate' : 'deprioritized' );
		return array( 'score' => $score, 'signals' => array_values( array_unique( $signals ) ), 'role' => $role );
	}


	private function collect_jsonld_image_urls( $value, array &$urls, $depth, &$nodes ) {
		if ( $depth > 8 || $nodes > 2000 || count( $urls ) >= self::MAX_REMOTE_CANDIDATES * 4 ) return;
		++$nodes;
		if ( is_string( $value ) ) return;
		if ( ! is_array( $value ) ) return;
		foreach ( $value as $key => $child ) {
			$key_lower = is_string( $key ) ? strtolower( $key ) : '';
			if ( in_array( $key_lower, array( 'image', 'thumbnailurl', 'contenturl' ), true ) ) {
				if ( is_string( $child ) ) $urls[] = $child;
				elseif ( is_array( $child ) ) {
					foreach ( $child as $nested ) {
						if ( is_string( $nested ) ) $urls[] = $nested;
						elseif ( is_array( $nested ) ) {
							foreach ( array( 'url', 'contentUrl', 'thumbnailUrl' ) as $url_key ) if ( isset( $nested[ $url_key ] ) && is_string( $nested[ $url_key ] ) ) $urls[] = $nested[ $url_key ];
						}
					}
				}
			}
			if ( is_array( $child ) ) $this->collect_jsonld_image_urls( $child, $urls, $depth + 1, $nodes );
			if ( $nodes > 2000 || count( $urls ) >= self::MAX_REMOTE_CANDIDATES * 4 ) break;
		}
	}

	private function remote_state_for_source_hash( $source_hash ) {
		$source_hash = strtolower( trim( (string) $source_hash ) );
		if ( ! preg_match( '/^[a-f0-9]{64}$/', $source_hash ) ) return new WP_Error( 'mad4b_remote_media_source_hash_invalid', 'Remote media source identity is invalid.' );
		$ids = get_posts( array(
			'post_type' => 'attachment', 'post_status' => 'any', 'posts_per_page' => 2, 'fields' => 'ids',
			'meta_key' => self::REMOTE_SOURCE_HASH_META, 'meta_value' => $source_hash,
			'no_found_rows' => true, 'suppress_filters' => true,
		) );
		$ids = array_values( array_map( 'absint', (array) $ids ) );
		if ( count( $ids ) > 1 ) return new WP_Error( 'mad4b_remote_media_source_collision', 'Remote media source identity resolves to more than one attachment.' );
		if ( empty( $ids ) ) return array( 'exists' => false, 'source_url_sha256' => $source_hash, 'content_sha256' => '' );
		return array(
			'exists' => true,
			'source_url_sha256' => $source_hash,
			'attachment_id' => (int) $ids[0],
			'content_sha256' => strtolower( (string) get_post_meta( (int) $ids[0], self::REMOTE_CONTENT_HASH_META, true ) ),
		);
	}

	private function remote_state_for_content_hash( $content_hash ) {
		$content_hash = strtolower( trim( (string) $content_hash ) );
		if ( ! preg_match( '/^[a-f0-9]{64}$/', $content_hash ) ) return new WP_Error( 'mad4b_remote_media_content_hash_invalid', 'Remote media content identity is invalid.' );
		$ids = get_posts( array(
			'post_type' => 'attachment', 'post_status' => 'any', 'posts_per_page' => 2, 'fields' => 'ids',
			'meta_key' => self::REMOTE_CONTENT_HASH_META, 'meta_value' => $content_hash,
			'no_found_rows' => true, 'suppress_filters' => true,
		) );
		$ids = array_values( array_map( 'absint', (array) $ids ) );
		if ( count( $ids ) > 1 ) return new WP_Error( 'mad4b_remote_media_content_collision', 'Remote media content identity resolves to more than one attachment.' );
		return empty( $ids )
			? array( 'exists' => false, 'content_sha256' => $content_hash )
			: array( 'exists' => true, 'content_sha256' => $content_hash, 'attachment_id' => (int) $ids[0] );
	}

	private function verify_download_against_plan( array $download, array $normalized ) {
		if ( '' === $normalized['expected_content_sha256'] ) return new WP_Error( 'mad4b_remote_media_content_inspection_missing', 'Exact remote image content evidence is required before import.' );
		if ( ! hash_equals( $normalized['expected_content_sha256'], (string) $download['content_sha256'] ) ) return new WP_Error( 'mad4b_remote_media_content_drift', 'Remote image bytes changed after inspection.' );
		if ( $normalized['expected_content_bytes'] > 0 && $normalized['expected_content_bytes'] !== (int) $download['bytes'] ) return new WP_Error( 'mad4b_remote_media_size_drift', 'Remote image byte size changed after inspection.' );
		if ( '' !== $normalized['expected_mime_type'] && ! hash_equals( $normalized['expected_mime_type'], (string) $download['mime_type'] ) ) return new WP_Error( 'mad4b_remote_media_mime_drift', 'Remote image MIME type changed after inspection.' );
		if ( $normalized['expected_width'] > 0 && $normalized['expected_width'] !== (int) $download['width'] ) return new WP_Error( 'mad4b_remote_media_width_drift', 'Remote image width changed after inspection.' );
		if ( $normalized['expected_height'] > 0 && $normalized['expected_height'] !== (int) $download['height'] ) return new WP_Error( 'mad4b_remote_media_height_drift', 'Remote image height changed after inspection.' );
		return true;
	}

	private function append_remote_provenance( $attachment_id, array $normalized, $content_hash, $plan_sha256, $state ) {
		$attachment_id = absint( $attachment_id );
		if ( $attachment_id < 1 || 'attachment' !== get_post_type( $attachment_id ) ) return new WP_Error( 'mad4b_remote_media_provenance_target_invalid', 'Remote media provenance target is not an attachment.' );
		$source_hashes = array_values( array_filter( array_map( 'strval', (array) get_post_meta( $attachment_id, self::REMOTE_SOURCE_HASH_META, false ) ) ) );
		$history = array_values( array_filter( (array) get_post_meta( $attachment_id, self::REMOTE_PROVENANCE_META, false ), 'is_array' ) );

		$event = array(
			'contract' => self::REMOTE_PROVENANCE_CONTRACT,
			'source_url' => $normalized['source_url'],
			'source_url_sha256' => $normalized['source_url_sha256'],
			'source_page_url' => $normalized['source_page_url'],
			'source_page_sha256' => $normalized['source_page_sha256'],
			'rights_basis' => $normalized['rights_basis'],
			'rights_note' => $normalized['rights_note'],
			'rights_reference' => $normalized['rights_reference'],
			'license_expires_on' => $normalized['license_expires_on'],
			'content_sha256' => (string) $content_hash,
			'imported_at_gmt' => gmdate( 'Y-m-d H:i:s' ),
			'imported_by_user_id' => get_current_user_id(),
			'plan_sha256' => (string) $plan_sha256,
			'state' => sanitize_key( (string) $state ),
		);
		$identity = array(
			'contract' => self::REMOTE_PROVENANCE_CONTRACT,
			'source_url_sha256' => $event['source_url_sha256'],
			'source_page_sha256' => $event['source_page_sha256'],
			'rights_basis' => $event['rights_basis'],
			'rights_note' => $event['rights_note'],
			'rights_reference' => $event['rights_reference'],
			'license_expires_on' => $event['license_expires_on'],
			'content_sha256' => $event['content_sha256'],
		);
		$event['provenance_event_sha256'] = hash( 'sha256', wp_json_encode( $identity, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );

		$source_added = false;
		if ( ! in_array( $normalized['source_url_sha256'], $source_hashes, true ) ) {
			$source_added = false !== add_post_meta( $attachment_id, self::REMOTE_SOURCE_HASH_META, $normalized['source_url_sha256'], false );
			if ( ! $source_added ) return new WP_Error( 'mad4b_remote_media_source_alias_failed', 'Remote media source identity could not be persisted.' );
		}
		foreach ( $history as $existing_event ) {
			if ( is_array( $existing_event ) && isset( $existing_event['provenance_event_sha256'] ) && hash_equals( (string) $existing_event['provenance_event_sha256'], $event['provenance_event_sha256'] ) ) return $existing_event;
		}
		if ( count( $history ) >= self::MAX_REMOTE_PROVENANCE_EVENTS ) {
			if ( $source_added ) delete_post_meta( $attachment_id, self::REMOTE_SOURCE_HASH_META, $normalized['source_url_sha256'] );
			return new WP_Error( 'mad4b_remote_media_provenance_history_full', 'Remote media provenance history reached its bounded event limit.' );
		}
		$event_added = false !== add_post_meta( $attachment_id, self::REMOTE_PROVENANCE_META, $event, false );
		if ( ! $event_added ) {
			if ( $source_added ) delete_post_meta( $attachment_id, self::REMOTE_SOURCE_HASH_META, $normalized['source_url_sha256'] );
			return new WP_Error( 'mad4b_remote_media_provenance_failed', 'Remote media provenance event could not be persisted.' );
		}

		$source_hashes_after = array_values( array_filter( array_map( 'strval', (array) get_post_meta( $attachment_id, self::REMOTE_SOURCE_HASH_META, false ) ) ) );
		$history_after = array_values( array_filter( (array) get_post_meta( $attachment_id, self::REMOTE_PROVENANCE_META, false ), 'is_array' ) );
		if ( ! in_array( $normalized['source_url_sha256'], $source_hashes_after, true ) || count( $history_after ) !== count( $history ) + 1 ) {
			delete_post_meta( $attachment_id, self::REMOTE_PROVENANCE_META, $event );
			if ( $source_added ) delete_post_meta( $attachment_id, self::REMOTE_SOURCE_HASH_META, $normalized['source_url_sha256'] );
			return new WP_Error( 'mad4b_remote_media_provenance_failed', 'Remote media provenance failed exact readback.' );
		}
		return $event;
	}

	private function remote_reuse_result( $attachment_id, array $normalized, array $plan, $basis, array $provenance_event = array(), $manifest_sha256 = '', $manifest_index = -1, $manifest_item_sha256 = '', $manifest_binding_role = '' ) {
		$readback = $this->media_get( array( 'attachment_id' => absint( $attachment_id ) ) );
		if ( is_wp_error( $readback ) ) return $readback;
		MAD4B_SCP_Audit::record( self::REMOTE_IMPORT_APPLY_ABILITY, array(
			'attachment_id' => absint( $attachment_id ),
			'source_url_sha256' => $normalized['source_url_sha256'],
			'content_sha256' => strtolower( (string) get_post_meta( absint( $attachment_id ), self::REMOTE_CONTENT_HASH_META, true ) ),
			'rights_basis' => $normalized['rights_basis'],
			'dedupe_basis' => sanitize_key( (string) $basis ),
		) );
		return MAD4B_SCP_Remote_Media_Recovery::stage_import_result( array(
			'contract' => self::REMOTE_IMPORT_CONTRACT, 'attachment_id' => absint( $attachment_id ),
			'created' => false, 'reused' => true, 'dedupe_basis' => sanitize_key( (string) $basis ), 'verified' => true,
			'media' => $readback['media'], 'provenance_event' => $provenance_event,
			'binding_template' => $this->post_binding_template( absint( $attachment_id ), $normalized, $provenance_event ),
			'plan_sha256' => $plan['plan_sha256'],
		), $manifest_sha256, $manifest_index, false, $manifest_item_sha256, $manifest_binding_role );
	}

	private function download_remote_image( array $normalized, $purpose = 'remote_media_import' ) {
		$purpose = sanitize_key( (string) $purpose );
		if ( ! in_array( $purpose, array( 'remote_media_import', 'remote_media_inspect' ), true ) ) return new WP_Error( 'mad4b_remote_media_purpose_invalid', 'Remote media transport purpose is invalid.' );
		if ( ! class_exists( 'MAD4B_SCP_Egress_Policy' ) ) return new WP_Error( 'mad4b_remote_media_egress_unavailable', 'Remote media import requires the governed egress policy.' );
		if ( ! function_exists( 'wp_tempnam' ) ) require_once ABSPATH . 'wp-admin/includes/file.php';
		$tmp = wp_tempnam( $normalized['filename'] );
		if ( ! $tmp ) return new WP_Error( 'mad4b_remote_media_tempfile_failed', 'Unable to allocate a temporary file for remote media import.' );
		$args = array(
			'timeout' => 25, 'redirection' => 0, 'reject_unsafe_urls' => true,
			'stream' => true, 'filename' => $tmp, 'limit_response_size' => self::MAX_REMOTE_IMAGE_BYTES + 1,
			'headers' => array( 'Accept' => 'image/avif,image/webp,image/png,image/jpeg,image/gif;q=0.9' ),
		);
		$args = MAD4B_SCP_Egress_Policy::mark_request( $purpose, $normalized['source_url'], $this->url_origin( $normalized['source_url'] ), $args );
		if ( is_wp_error( $args ) ) { @unlink( $tmp ); return $args; }
		$response = wp_safe_remote_get( $normalized['source_url'], $args );
		if ( is_wp_error( $response ) ) { @unlink( $tmp ); return MAD4B_SCP_Egress_Policy::classify_transport_error( $response, $purpose ); }
		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( 200 !== $code ) { @unlink( $tmp ); return new WP_Error( 'mad4b_remote_media_http_status', 'Remote image did not return HTTP 200.', array( 'status' => $code ) ); }
		$header_mime = strtolower( trim( (string) wp_remote_retrieve_header( $response, 'content-type' ) ) );
		if ( false !== strpos( $header_mime, ';' ) ) $header_mime = trim( strtok( $header_mime, ';' ) );
		if ( 0 !== strpos( $header_mime, 'image/' ) ) { @unlink( $tmp ); return new WP_Error( 'mad4b_remote_media_content_type', 'Remote resource is not declared as an image.' ); }
		$bytes = is_file( $tmp ) ? (int) filesize( $tmp ) : 0;
		if ( $bytes < 1 || $bytes > self::MAX_REMOTE_IMAGE_BYTES ) { @unlink( $tmp ); return new WP_Error( 'mad4b_remote_media_size', 'Remote image is empty or exceeds the bounded import size.' ); }
		$image_info = function_exists( 'wp_getimagesize' ) ? wp_getimagesize( $tmp ) : @getimagesize( $tmp );
		if ( ! is_array( $image_info ) || empty( $image_info[0] ) || empty( $image_info[1] ) || empty( $image_info['mime'] ) ) { @unlink( $tmp ); return new WP_Error( 'mad4b_remote_media_image_invalid', 'Downloaded remote resource is not a valid raster image.' ); }
		$width = (int) $image_info[0]; $height = (int) $image_info[1]; $mime = strtolower( (string) $image_info['mime'] );
		if ( $width < 1 || $height < 1 || $width > 12000 || $height > 12000 || ( $width * $height ) > self::MAX_REMOTE_IMAGE_PIXELS ) { @unlink( $tmp ); return new WP_Error( 'mad4b_remote_media_dimensions', 'Remote image dimensions exceed the bounded import budget.' ); }
		$extensions = array( 'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif', 'image/webp' => 'webp', 'image/avif' => 'avif' );
		if ( ! isset( $extensions[ $mime ] ) ) { @unlink( $tmp ); return new WP_Error( 'mad4b_remote_media_mime_unsupported', 'Remote image MIME type is outside the supported raster allowlist.' ); }
		$allowed = function_exists( 'get_allowed_mime_types' ) ? get_allowed_mime_types() : array();
		if ( $allowed && ! in_array( $mime, array_values( $allowed ), true ) ) { @unlink( $tmp ); return new WP_Error( 'mad4b_remote_media_mime_not_allowed', 'WordPress does not allow the downloaded image MIME type.' ); }
		$base = preg_replace( '/\.[A-Za-z0-9]{1,8}$/', '', $normalized['filename'] );
		$base = sanitize_file_name( $base );
		if ( '' === $base ) $base = 'remote-image';
		return array(
			'tmp_name' => $tmp,
			'filename' => substr( $base, 0, 150 ) . '.' . $extensions[ $mime ],
			'bytes' => $bytes, 'width' => $width, 'height' => $height, 'mime_type' => $mime,
			'content_sha256' => hash_file( 'sha256', $tmp ),
		);
	}

	private function post_binding_template( $attachment_id, array $normalized, array $provenance_event = array() ) {
		return array(
			'ordering' => 'media_library_first_then_post_binding',
			'attachment_id' => (int) $attachment_id,
			'featured_media_id' => (int) $attachment_id,
			'single_media_meta_value' => (int) $attachment_id,
			'gallery_append_value' => (int) $attachment_id,
			'contextual_source_url' => $normalized['source_url'],
			'source_url_sha256' => $normalized['source_url_sha256'],
			'content_sha256' => isset( $provenance_event['content_sha256'] ) ? (string) $provenance_event['content_sha256'] : '',
			'remote_provenance_sha256' => isset( $provenance_event['provenance_event_sha256'] ) ? (string) $provenance_event['provenance_event_sha256'] : '',
			'rights_basis' => $normalized['rights_basis'],
			'license_expires_on' => $normalized['license_expires_on'],
			'content_experience_requirement' => 'Bind only through a configured media_meta_fields profile entry or featured_media_id.',
		);
	}

}
