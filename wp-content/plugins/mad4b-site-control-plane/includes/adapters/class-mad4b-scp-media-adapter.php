<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
final class MAD4B_SCP_Media_Adapter extends MAD4B_SCP_Adapter_Base {
	const REMOTE_DISCOVER_ABILITY = 'media/remote-source-discover';
	const REMOTE_IMPORT_PLAN_ABILITY = 'media/remote-import-plan';
	const REMOTE_IMPORT_APPLY_ABILITY = 'media/remote-import-apply';
	const REMOTE_IMPORT_CONTRACT = 'mad4b.remote-media-import.v1';
	const REMOTE_PROVENANCE_CONTRACT = 'mad4b.remote-media-provenance.v1';
	const REMOTE_SOURCE_HASH_META = '_mad4b_remote_media_source_sha256';
	const REMOTE_CONTENT_HASH_META = '_mad4b_remote_media_content_sha256';
	const REMOTE_PROVENANCE_META = '_mad4b_remote_media_provenance';
	const MAX_REMOTE_PAGE_BYTES = 2097152;
	const MAX_REMOTE_IMAGE_BYTES = 15728640;
	const MAX_REMOTE_IMAGE_PIXELS = 40000000;
	const MAX_REMOTE_CANDIDATES = 50;
	public function id() { return 'media'; }
	public function label() { return 'Media'; }
	public function is_available() { return true; }

	public function ability_names() {
		return array(
			'read' => array( 'media/search', 'media/get', self::REMOTE_DISCOVER_ABILITY, self::REMOTE_IMPORT_PLAN_ABILITY ),
			'content' => array( 'media/update-metadata', 'media/set-featured', 'media/set-parent', self::REMOTE_IMPORT_APPLY_ABILITY ),
			'admin' => array(),
		);
	}

	public function reversible_contracts() {
		return array(
			'media/update-metadata' => 'mad4b.rollback.media-metadata.v1',
			'media/set-featured' => 'mad4b.rollback.featured-image.v1',
			'media/set-parent' => 'mad4b.rollback.media-parent.v1',
		);
	}

	public function register_abilities() {
		$this->add_ability(
			'media/search',
			'Search Media Library',
			'search',
			array( 'MAD4B_SCP_Policy', 'can_read' ),
			$this->schema(
				array(
					'search' => array( 'type' => 'string', 'default' => '' ),
					'mime_type' => array( 'type' => 'string', 'default' => '' ),
					'parent_post_id' => array( 'type' => 'integer', 'minimum' => 1 ),
					'unattached_only' => array( 'type' => 'boolean', 'default' => false ),
					'image_only' => array( 'type' => 'boolean', 'default' => false ),
					'page' => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 10000, 'default' => 1 ),
					'limit' => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 50, 'default' => 20 ),
				)
			)
		);

		$this->add_ability(
			'media/get',
			'Get Media Item',
			'get_media',
			array( $this, 'can_read_attachment' ),
			$this->schema(
				array( 'attachment_id' => array( 'type' => 'integer', 'minimum' => 1 ) ),
				array( 'attachment_id' )
			)
		);

		$this->add_ability(
			self::REMOTE_DISCOVER_ABILITY,
			'Discover Remote Image Candidates',
			'remote_source_discover',
			array( 'MAD4B_SCP_Policy', 'can_read' ),
			$this->schema(
				array(
					'source_page_url' => array( 'type' => 'string', 'minLength' => 8, 'maxLength' => 8192 ),
					'limit' => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => self::MAX_REMOTE_CANDIDATES, 'default' => 20 ),
					'same_origin_only' => array( 'type' => 'boolean', 'default' => false ),
				),
				array( 'source_page_url' )
			)
		);

		$this->add_ability(
			self::REMOTE_IMPORT_PLAN_ABILITY,
			'Plan Remote Image Import',
			'remote_import_plan',
			array( 'MAD4B_SCP_Policy', 'can_read' ),
			$this->remote_import_schema( false )
		);

		$this->add_ability(
			'media/update-metadata',
			'Update Media Metadata',
			'update_metadata',
			array( $this, 'can_edit_attachment' ),
			$this->schema(
				array(
					'attachment_id' => array( 'type' => 'integer', 'minimum' => 1 ),
					'expected_sha256' => array(
						'type' => 'string',
						'minLength' => 64,
						'maxLength' => 64,
						'pattern' => '^[a-fA-F0-9]{64}$',
					),
					'title' => array( 'type' => 'string', 'maxLength' => 1000 ),
					'caption' => array( 'type' => 'string', 'maxLength' => 65535 ),
					'description' => array( 'type' => 'string', 'maxLength' => 262144 ),
					'alt' => array( 'type' => 'string', 'maxLength' => 2048 ),
				),
				array( 'attachment_id', 'expected_sha256' )
			),
			'content',
			false,
			false,
			true
		);

		$this->add_ability(
			'media/set-featured',
			'Set Featured Image',
			'set_featured',
			array( $this, 'can_set_featured' ),
			$this->schema(
				array(
					'post_id' => array( 'type' => 'integer', 'minimum' => 1 ),
					'attachment_id' => array( 'type' => 'integer', 'minimum' => 1 ),
					'expected_thumbnail_id' => array( 'type' => 'integer', 'minimum' => 0 ),
				),
				array( 'post_id', 'attachment_id', 'expected_thumbnail_id' )
			),
			'content',
			false,
			false,
			true
		);

		$this->add_ability(
			'media/set-parent',
			'Attach Media to Post',
			'set_parent',
			array( $this, 'can_set_parent' ),
			$this->schema(
				array(
					'attachment_id' => array( 'type' => 'integer', 'minimum' => 1 ),
					'parent_post_id' => array( 'type' => 'integer', 'minimum' => 0 ),
					'expected_parent_id' => array( 'type' => 'integer', 'minimum' => 0 ),
				),
				array( 'attachment_id', 'parent_post_id', 'expected_parent_id' )
			),
			'content',
			false,
			false,
			true
		);

		$this->add_ability(
			self::REMOTE_IMPORT_APPLY_ABILITY,
			'Import Remote Image to Media Library',
			'remote_import_apply',
			array( $this, 'can_import_remote' ),
			$this->remote_import_schema( true ),
			'content',
			false,
			true,
			true
		);
	}

	private function remote_import_schema( $require_plan ) {
		$properties = array(
			'source_url' => array( 'type' => 'string', 'minLength' => 8, 'maxLength' => 8192 ),
			'source_page_url' => array( 'type' => 'string', 'maxLength' => 8192, 'default' => '' ),
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
		);
		$required = array( 'source_url' );
		if ( $require_plan ) {
			$properties['plan_sha256'] = array( 'type' => 'string', 'minLength' => 64, 'maxLength' => 64, 'pattern' => '^[a-fA-F0-9]{64}$' );
			$required[] = 'plan_sha256';
		}
		return $this->schema( $properties, $required );
	}

	public function can_import_remote( $input = array() ) {
		return current_user_can( 'upload_files' ) ? true : new WP_Error( 'mad4b_remote_media_upload_denied', 'Current user cannot upload media.' );
	}

	public function can_read_attachment( $input ) {
		$id = isset( $input['attachment_id'] ) ? absint( $input['attachment_id'] ) : 0;
		return $id > 0 && current_user_can( 'read_post', $id );
	}

	public function can_edit_attachment( $input ) {
		$id = isset( $input['attachment_id'] ) ? absint( $input['attachment_id'] ) : 0;
		return $id > 0 && current_user_can( 'edit_post', $id );
	}

	public function can_set_featured( $input ) {
		$post = isset( $input['post_id'] ) ? absint( $input['post_id'] ) : 0;
		$att = isset( $input['attachment_id'] ) ? absint( $input['attachment_id'] ) : 0;
		return $post > 0 && $att > 0 && current_user_can( 'edit_post', $post ) && current_user_can( 'read_post', $att );
	}

	public function can_set_parent( $input ) {
		$att = isset( $input['attachment_id'] ) ? absint( $input['attachment_id'] ) : 0;
		$parent = isset( $input['parent_post_id'] ) ? absint( $input['parent_post_id'] ) : 0;
		if ( $att < 1 || 'attachment' !== get_post_type( $att ) || ! current_user_can( 'edit_post', $att ) ) return false;
		if ( 0 === $parent ) return true;
		$post = get_post( $parent );
		return $post
			&& ! in_array( $post->post_type, array( 'attachment', 'revision', 'nav_menu_item' ), true )
			&& current_user_can( 'edit_post', $parent );
	}

	public function search( $input ) {
		$limit = isset( $input['limit'] ) ? max( 1, min( 50, absint( $input['limit'] ) ) ) : 20;
		$page = isset( $input['page'] ) ? max( 1, min( 10000, absint( $input['page'] ) ) ) : 1;
		$parent_post_id = isset( $input['parent_post_id'] ) ? absint( $input['parent_post_id'] ) : 0;
		$unattached_only = ! empty( $input['unattached_only'] );
		$image_only = ! empty( $input['image_only'] );
		$mime_type = isset( $input['mime_type'] ) ? sanitize_text_field( (string) $input['mime_type'] ) : '';
		if ( $parent_post_id > 0 && $unattached_only ) {
			return new WP_Error( 'mad4b_media_search_parent_conflict', 'parent_post_id and unattached_only cannot be combined.' );
		}
		if ( $parent_post_id > 0 && ! get_post( $parent_post_id ) ) {
			return new WP_Error( 'mad4b_media_search_parent_missing', 'Requested media parent post does not exist.' );
		}
		if ( $image_only && '' !== $mime_type && 'image' !== $mime_type && 0 !== strpos( $mime_type, 'image/' ) ) {
			return new WP_Error( 'mad4b_media_search_mime_conflict', 'image_only cannot be combined with a non-image MIME filter.' );
		}

		$args = array(
			'post_type' => 'attachment',
			'post_status' => 'inherit',
			'posts_per_page' => $limit,
			'paged' => $page,
			'orderby' => 'date',
			'order' => 'DESC',
		);
		if ( ! empty( $input['search'] ) ) $args['s'] = sanitize_text_field( $input['search'] );
		if ( $parent_post_id > 0 ) $args['post_parent'] = $parent_post_id;
		elseif ( $unattached_only ) $args['post_parent'] = 0;
		if ( '' !== $mime_type ) $args['post_mime_type'] = $mime_type;
		elseif ( $image_only ) $args['post_mime_type'] = 'image';

		$posts = get_posts( $args );
		$items = array();
		foreach ( $posts as $post ) {
			if ( ! current_user_can( 'read_post', $post->ID ) ) continue;
			$payload = $this->media_payload( $post, false );
			$items[] = array(
				'media' => $payload,
				'sha256' => $this->hash_value( $this->mutable_state_from_post( $post ) ),
			);
		}
		return array(
			'items' => $items,
			'count' => count( $items ),
			'page' => $page,
			'limit' => $limit,
			'has_more_candidate' => count( $posts ) === $limit,
			'detail_level' => 'summary',
		);
	}

	public function get_media( $input ) {
		$post = get_post( absint( $input['attachment_id'] ) );
		if ( ! $post || 'attachment' !== $post->post_type ) return new WP_Error( 'mad4b_media_missing', 'Attachment not found.' );
		$payload = $this->media_payload( $post, true );
		return array(
			'media' => $payload,
			'sha256' => $this->hash_value( $this->mutable_state_from_post( $post ) ),
			'detail_level' => 'full',
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
		return array(
			'contract' => 'mad4b.remote-media-discovery.v1',
			'source_page_url' => $page_url,
			'source_page_sha256' => hash( 'sha256', $body ),
			'candidate_count' => count( $candidates ),
			'candidates' => $candidates,
			'import_plan_ability' => self::REMOTE_IMPORT_PLAN_ABILITY,
			'mutation_performed' => false,
		);
	}

	public function remote_import_plan( $input ) {
		$normalized = $this->normalize_remote_import_input( is_array( $input ) ? $input : array() );
		if ( is_wp_error( $normalized ) ) return $normalized;
		$state = $this->remote_state_for_source_hash( $normalized['source_url_sha256'] );
		if ( is_wp_error( $state ) ) return $state;
		$blockers = array();
		if ( 'unknown' === $normalized['rights_basis'] ) $blockers[] = 'rights_confirmation_required';
		if ( ! empty( $state['exists'] ) && 'fail' === $normalized['duplicate_policy'] ) $blockers[] = 'source_already_imported';
		$plan = array(
			'contract' => self::REMOTE_IMPORT_CONTRACT,
			'ready' => empty( $blockers ),
			'blockers' => $blockers,
			'normalized_input' => $normalized,
			'existing_source_state' => $state,
			'library_first' => true,
			'post_binding_after_import_only' => true,
			'mutation_performed' => false,
		);
		$plan['plan_sha256'] = hash( 'sha256', wp_json_encode( $plan, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
		return $plan;
	}

	public function remote_import_apply( $input ) {
		$input = is_array( $input ) ? $input : array();
		$expected = isset( $input['plan_sha256'] ) ? strtolower( trim( (string) $input['plan_sha256'] ) ) : '';
		$plan_input = $input;
		unset( $plan_input['plan_sha256'], $plan_input['_mad4b_approval_ticket_id'], $plan_input['_mad4b_context_receipt'] );
		$plan = $this->remote_import_plan( $plan_input );
		if ( is_wp_error( $plan ) ) return $plan;
		if ( '' === $expected || ! hash_equals( (string) $plan['plan_sha256'], $expected ) ) return new WP_Error( 'mad4b_remote_media_import_plan_drift', 'Remote media import no longer matches the exact reviewed plan.' );
		if ( empty( $plan['ready'] ) ) return new WP_Error( 'mad4b_remote_media_import_blocked', 'Remote media import is blocked until its plan blockers are resolved.', array( 'blockers' => $plan['blockers'] ) );

		$normalized = $plan['normalized_input'];
		$existing = $plan['existing_source_state'];
		if ( ! empty( $existing['exists'] ) ) {
			$readback = $this->get_media( array( 'attachment_id' => (int) $existing['attachment_id'] ) );
			if ( is_wp_error( $readback ) ) return $readback;
			return array(
				'contract' => self::REMOTE_IMPORT_CONTRACT,
				'attachment_id' => (int) $existing['attachment_id'],
				'created' => false,
				'reused' => true,
				'verified' => true,
				'media' => $readback['media'],
				'binding_template' => $this->post_binding_template( (int) $existing['attachment_id'], $normalized ),
				'plan_sha256' => $plan['plan_sha256'],
			);
		}

		$download = $this->download_remote_image( $normalized );
		if ( is_wp_error( $download ) ) return $download;
		$tmp = $download['tmp_name'];
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

		$provenance = array(
			'contract' => self::REMOTE_PROVENANCE_CONTRACT,
			'source_url' => $normalized['source_url'],
			'source_url_sha256' => $normalized['source_url_sha256'],
			'source_page_url' => $normalized['source_page_url'],
			'source_page_url_sha256' => '' !== $normalized['source_page_url'] ? hash( 'sha256', $normalized['source_page_url'] ) : '',
			'rights_basis' => $normalized['rights_basis'],
			'rights_note' => $normalized['rights_note'],
			'rights_reference' => $normalized['rights_reference'],
			'license_expires_on' => $normalized['license_expires_on'],
			'content_sha256' => $download['content_sha256'],
			'bytes' => $download['bytes'],
			'mime_type' => $download['mime_type'],
			'imported_at_gmt' => gmdate( 'Y-m-d H:i:s' ),
			'plan_sha256' => $plan['plan_sha256'],
			'state' => 'media_library',
		);
		update_post_meta( $attachment_id, self::REMOTE_SOURCE_HASH_META, $normalized['source_url_sha256'] );
		update_post_meta( $attachment_id, self::REMOTE_CONTENT_HASH_META, $download['content_sha256'] );
		update_post_meta( $attachment_id, self::REMOTE_PROVENANCE_META, $provenance );
		if ( (string) get_post_meta( $attachment_id, self::REMOTE_SOURCE_HASH_META, true ) !== (string) $normalized['source_url_sha256']
			|| (string) get_post_meta( $attachment_id, self::REMOTE_CONTENT_HASH_META, true ) !== (string) $download['content_sha256'] ) {
			wp_delete_attachment( $attachment_id, true );
			return new WP_Error( 'mad4b_remote_media_import_provenance_failed', 'Imported media provenance failed exact readback.' );
		}

		$current = $this->get_media( array( 'attachment_id' => $attachment_id ) );
		if ( is_wp_error( $current ) ) { wp_delete_attachment( $attachment_id, true ); return $current; }
		$metadata_input = array( 'attachment_id' => $attachment_id, 'expected_sha256' => $current['sha256'] );
		foreach ( array( 'title', 'caption', 'description', 'alt' ) as $field ) if ( '' !== (string) $normalized[ $field ] ) $metadata_input[ $field ] = $normalized[ $field ];
		if ( count( $metadata_input ) > 2 ) {
			$metadata_result = $this->update_metadata( $metadata_input );
			if ( is_wp_error( $metadata_result ) ) { wp_delete_attachment( $attachment_id, true ); return $metadata_result; }
		}

		$attached_file = get_attached_file( $attachment_id, true );
		if ( ! $attached_file || ! is_file( $attached_file ) || ! hash_equals( $download['content_sha256'], hash_file( 'sha256', $attached_file ) ) ) {
			wp_delete_attachment( $attachment_id, true );
			return new WP_Error( 'mad4b_remote_media_import_content_readback_failed', 'Imported image bytes failed exact content-hash readback.' );
		}
		$readback = $this->get_media( array( 'attachment_id' => $attachment_id ) );
		if ( is_wp_error( $readback ) ) { wp_delete_attachment( $attachment_id, true ); return $readback; }
		MAD4B_SCP_Audit::record( self::REMOTE_IMPORT_APPLY_ABILITY, array(
			'attachment_id' => $attachment_id,
			'source_url_sha256' => $normalized['source_url_sha256'],
			'content_sha256' => $download['content_sha256'],
			'bytes' => $download['bytes'],
			'rights_basis' => $normalized['rights_basis'],
		) );
		return array(
			'contract' => self::REMOTE_IMPORT_CONTRACT,
			'attachment_id' => $attachment_id,
			'created' => true,
			'reused' => false,
			'verified' => true,
			'media' => $readback['media'],
			'provenance' => $provenance,
			'binding_template' => $this->post_binding_template( $attachment_id, $normalized ),
			'plan_sha256' => $plan['plan_sha256'],
		);
	}

	public function update_metadata( $input ) {
		$id = absint( $input['attachment_id'] );
		$post = get_post( $id );
		if ( ! $post || 'attachment' !== $post->post_type ) return new WP_Error( 'mad4b_media_missing', 'Attachment not found.' );

		$current = $this->get_media( array( 'attachment_id' => $id ) );
		if ( is_wp_error( $current ) ) return $current;
		if ( ! hash_equals( $current['sha256'], strtolower( trim( (string) $input['expected_sha256'] ) ) ) ) {
			return new WP_Error( 'mad4b_media_stale', 'Media metadata changed since it was read.', array( 'current_sha256' => $current['sha256'] ) );
		}

		$requested = array();
		$update = array( 'ID' => $id );

		if ( array_key_exists( 'title', $input ) ) {
			$requested['title'] = sanitize_text_field( (string) $input['title'] );
			$update['post_title'] = $requested['title'];
		}
		if ( array_key_exists( 'caption', $input ) ) {
			$requested['caption'] = sanitize_textarea_field( (string) $input['caption'] );
			$update['post_excerpt'] = $requested['caption'];
		}
		if ( array_key_exists( 'description', $input ) ) {
			$requested['description'] = wp_kses_post( (string) $input['description'] );
			$update['post_content'] = $requested['description'];
		}
		if ( array_key_exists( 'alt', $input ) ) {
			if ( ! wp_attachment_is_image( $id ) ) {
				return new WP_Error( 'mad4b_media_alt_requires_image', 'Alternative text can only be written to image attachments.' );
			}
			$requested['alt'] = sanitize_text_field( (string) $input['alt'] );
		}

		if ( count( $update ) > 1 ) {
			$result = wp_update_post( wp_slash( $update ), true );
			if ( is_wp_error( $result ) ) return $result;
		}
		if ( array_key_exists( 'alt', $requested ) ) {
			update_post_meta( $id, '_wp_attachment_image_alt', $requested['alt'] );
		}

		$after = $this->get_media( array( 'attachment_id' => $id ) );
		if ( is_wp_error( $after ) ) return $after;
		foreach ( $requested as $field => $value ) {
			if ( ! array_key_exists( $field, $after['media'] ) || (string) $after['media'][ $field ] !== (string) $value ) {
				return new WP_Error(
					'mad4b_media_metadata_readback_mismatch',
					'Media metadata write completed but exact readback did not match.',
					array( 'field' => $field )
				);
			}
		}

		MAD4B_SCP_Audit::record(
			'media/update-metadata',
			array(
				'attachment_id' => $id,
				'fields' => implode( ',', array_keys( $requested ) ),
				'before_sha256' => $current['sha256'],
				'after_sha256' => $after['sha256'],
			)
		);
		return $after;
	}

	public function set_featured( $input ) {
		$post_id = absint( $input['post_id'] );
		$attachment_id = absint( $input['attachment_id'] );
		$current = (int) get_post_thumbnail_id( $post_id );
		if ( $current !== (int) $input['expected_thumbnail_id'] ) {
			return new WP_Error( 'mad4b_media_stale_thumbnail', 'Featured image changed since it was read.', array( 'current_thumbnail_id' => $current ) );
		}
		if ( ! wp_attachment_is_image( $attachment_id ) ) return new WP_Error( 'mad4b_media_not_image', 'Attachment is not an image.' );
		if ( $current === $attachment_id ) {
			return array( 'post_id' => $post_id, 'attachment_id' => $attachment_id, 'updated' => false, 'verified' => true );
		}
		$result = set_post_thumbnail( $post_id, $attachment_id );
		if ( false === $result ) return new WP_Error( 'mad4b_media_featured_failed', 'Unable to set featured image.' );
		if ( (int) get_post_thumbnail_id( $post_id ) !== $attachment_id ) {
			return new WP_Error( 'mad4b_media_featured_readback_mismatch', 'Featured-image write completed but exact readback did not match.' );
		}
		MAD4B_SCP_Audit::record(
			'media/set-featured',
			array(
				'post_id' => $post_id,
				'before_thumbnail_id' => $current,
				'attachment_id' => $attachment_id,
			)
		);
		return array( 'post_id' => $post_id, 'attachment_id' => $attachment_id, 'updated' => true, 'verified' => true );
	}

	public function set_parent( $input ) {
		$id = absint( $input['attachment_id'] );
		$parent_id = absint( $input['parent_post_id'] );
		$post = get_post( $id );
		if ( ! $post || 'attachment' !== $post->post_type ) return new WP_Error( 'mad4b_media_missing', 'Attachment not found.' );

		$current = (int) $post->post_parent;
		if ( $current !== (int) $input['expected_parent_id'] ) {
			return new WP_Error( 'mad4b_media_stale_parent', 'Attachment parent changed since it was read.', array( 'current_parent_id' => $current ) );
		}
		if ( $parent_id > 0 ) {
			$parent = get_post( $parent_id );
			if ( ! $parent || in_array( $parent->post_type, array( 'attachment', 'revision', 'nav_menu_item' ), true ) ) {
				return new WP_Error( 'mad4b_media_parent_invalid', 'Requested attachment parent is not a content post.' );
			}
		}

		if ( $current === $parent_id ) {
			return array( 'attachment_id' => $id, 'parent_post_id' => $parent_id, 'updated' => false, 'verified' => true );
		}
		$result = wp_update_post( array( 'ID' => $id, 'post_parent' => $parent_id ), true );
		if ( is_wp_error( $result ) ) return $result;
		$after = get_post( $id );
		if ( ! $after || (int) $after->post_parent !== $parent_id ) {
			return new WP_Error( 'mad4b_media_parent_readback_mismatch', 'Attachment parent write failed exact readback.' );
		}
		MAD4B_SCP_Audit::record(
			'media/set-parent',
			array(
				'attachment_id' => $id,
				'before_parent_id' => $current,
				'parent_post_id' => $parent_id,
			)
		);
		return array( 'attachment_id' => $id, 'parent_post_id' => $parent_id, 'updated' => true, 'verified' => true );
	}

	public function capture_reversible_state( $ability_name, array $input ) {
		if ( 'media/update-metadata' === $ability_name ) {
			$id = isset( $input['attachment_id'] ) ? absint( $input['attachment_id'] ) : 0;
			$current = $this->get_media( array( 'attachment_id' => $id ) );
			if ( is_wp_error( $current ) ) return $current;
			$expected = isset( $input['expected_sha256'] ) ? strtolower( trim( (string) $input['expected_sha256'] ) ) : '';
			if ( '' === $expected || ! hash_equals( $current['sha256'], $expected ) ) {
				return new WP_Error( 'mad4b_media_stale', 'Media metadata changed since it was read.', array( 'current_sha256' => $current['sha256'] ) );
			}
			return array(
				'target_type' => 'media',
				'target_id' => (string) $id,
				'target' => array( 'attachment_id' => $id ),
				'state' => $this->restore_metadata_state( $current['media'] ),
			);
		}

		if ( 'media/set-featured' === $ability_name ) {
			$post_id = isset( $input['post_id'] ) ? absint( $input['post_id'] ) : 0;
			$current = (int) get_post_thumbnail_id( $post_id );
			if ( ! isset( $input['expected_thumbnail_id'] ) || $current !== (int) $input['expected_thumbnail_id'] ) {
				return new WP_Error( 'mad4b_media_stale_thumbnail', 'Featured image changed since it was read.', array( 'current_thumbnail_id' => $current ) );
			}
			return array(
				'target_type' => 'post-featured-image',
				'target_id' => (string) $post_id,
				'target' => array( 'post_id' => $post_id ),
				'state' => array( 'thumbnail_id' => $current ),
			);
		}

		if ( 'media/set-parent' === $ability_name ) {
			$id = isset( $input['attachment_id'] ) ? absint( $input['attachment_id'] ) : 0;
			$post = get_post( $id );
			if ( ! $post || 'attachment' !== $post->post_type ) return new WP_Error( 'mad4b_media_missing', 'Attachment not found.' );
			$current = (int) $post->post_parent;
			if ( ! isset( $input['expected_parent_id'] ) || $current !== (int) $input['expected_parent_id'] ) {
				return new WP_Error( 'mad4b_media_stale_parent', 'Attachment parent changed since it was read.', array( 'current_parent_id' => $current ) );
			}
			return array(
				'target_type' => 'media-parent',
				'target_id' => (string) $id,
				'target' => array( 'attachment_id' => $id ),
				'state' => array( 'parent_id' => $current ),
			);
		}

		return parent::capture_reversible_state( $ability_name, $input );
	}

	public function read_reversible_state( $ability_name, array $target ) {
		if ( 'media/update-metadata' === $ability_name ) {
			$id = isset( $target['attachment_id'] ) ? absint( $target['attachment_id'] ) : 0;
			$current = $this->get_media( array( 'attachment_id' => $id ) );
			return is_wp_error( $current ) ? $current : $this->restore_metadata_state( $current['media'] );
		}
		if ( 'media/set-featured' === $ability_name ) {
			$post_id = isset( $target['post_id'] ) ? absint( $target['post_id'] ) : 0;
			if ( ! $post_id || ! get_post( $post_id ) ) return new WP_Error( 'mad4b_post_missing', 'Post not found for featured-image readback.' );
			return array( 'thumbnail_id' => (int) get_post_thumbnail_id( $post_id ) );
		}
		if ( 'media/set-parent' === $ability_name ) {
			$id = isset( $target['attachment_id'] ) ? absint( $target['attachment_id'] ) : 0;
			$post = get_post( $id );
			if ( ! $post || 'attachment' !== $post->post_type ) return new WP_Error( 'mad4b_media_missing', 'Attachment not found for parent readback.' );
			return array( 'parent_id' => (int) $post->post_parent );
		}
		return parent::read_reversible_state( $ability_name, $target );
	}

	public function restore_reversible_state( $ability_name, array $target, array $state, array $record ) {
		if ( 'media/update-metadata' === $ability_name ) {
			$id = isset( $target['attachment_id'] ) ? absint( $target['attachment_id'] ) : 0;
			if ( ! $id || ! current_user_can( 'edit_post', $id ) ) return new WP_Error( 'mad4b_media_restore_denied', 'Current user cannot restore this attachment.' );
			foreach ( array( 'title', 'caption', 'description', 'alt' ) as $field ) {
				if ( ! array_key_exists( $field, $state ) ) return new WP_Error( 'mad4b_media_restore_payload_invalid', 'Media rollback state is incomplete.' );
			}
			$result = wp_update_post(
				wp_slash(
					array(
						'ID' => $id,
						'post_title' => $state['title'],
						'post_excerpt' => $state['caption'],
						'post_content' => $state['description'],
					)
				),
				true
			);
			if ( is_wp_error( $result ) ) return $result;
			update_post_meta( $id, '_wp_attachment_image_alt', sanitize_text_field( (string) $state['alt'] ) );
			$after = $this->get_media( array( 'attachment_id' => $id ) );
			if ( is_wp_error( $after ) ) return $after;
			foreach ( $state as $field => $value ) {
				if ( ! array_key_exists( $field, $after['media'] ) || (string) $after['media'][ $field ] !== (string) $value ) {
					return new WP_Error( 'mad4b_media_restore_readback_mismatch', 'Media metadata rollback failed exact readback.', array( 'field' => $field ) );
				}
			}
			return true;
		}

		if ( 'media/set-featured' === $ability_name ) {
			$post_id = isset( $target['post_id'] ) ? absint( $target['post_id'] ) : 0;
			if ( ! $post_id || ! current_user_can( 'edit_post', $post_id ) ) return new WP_Error( 'mad4b_media_restore_denied', 'Current user cannot restore this post featured image.' );
			if ( ! array_key_exists( 'thumbnail_id', $state ) ) return new WP_Error( 'mad4b_media_restore_payload_invalid', 'Featured-image rollback state is incomplete.' );
			$thumbnail_id = absint( $state['thumbnail_id'] );
			if ( 0 === $thumbnail_id ) {
				delete_post_thumbnail( $post_id );
				return 0 === (int) get_post_thumbnail_id( $post_id )
					? true
					: new WP_Error( 'mad4b_media_restore_failed', 'Unable to clear featured image.' );
			}
			set_post_thumbnail( $post_id, $thumbnail_id );
			return $thumbnail_id === (int) get_post_thumbnail_id( $post_id )
				? true
				: new WP_Error( 'mad4b_media_restore_failed', 'Unable to restore featured image with exact readback.' );
		}

		if ( 'media/set-parent' === $ability_name ) {
			$id = isset( $target['attachment_id'] ) ? absint( $target['attachment_id'] ) : 0;
			if ( ! $id || ! current_user_can( 'edit_post', $id ) ) return new WP_Error( 'mad4b_media_restore_denied', 'Current user cannot restore this attachment parent.' );
			if ( ! array_key_exists( 'parent_id', $state ) ) return new WP_Error( 'mad4b_media_restore_payload_invalid', 'Media-parent rollback state is incomplete.' );
			$parent_id = absint( $state['parent_id'] );
			$result = wp_update_post( array( 'ID' => $id, 'post_parent' => $parent_id ), true );
			if ( is_wp_error( $result ) ) return $result;
			$after = get_post( $id );
			return $after && (int) $after->post_parent === $parent_id
				? true
				: new WP_Error( 'mad4b_media_restore_readback_mismatch', 'Media-parent rollback failed exact readback.' );
		}

		return parent::restore_reversible_state( $ability_name, $target, $state, $record );
	}

	private function media_payload( $post, $include_details = true ) {
		$file = get_attached_file( $post->ID, true );
		$metadata = wp_get_attachment_metadata( $post->ID );
		$metadata = is_array( $metadata ) ? $metadata : array();
		$encoded = wp_json_encode( $metadata, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		$filesize = $file && is_file( $file )
			? (int) filesize( $file )
			: ( isset( $metadata['filesize'] ) ? absint( $metadata['filesize'] ) : 0 );

		$width = isset( $metadata['width'] ) ? absint( $metadata['width'] ) : 0;
		$height = isset( $metadata['height'] ) ? absint( $metadata['height'] ) : 0;
		$payload = array(
			'id' => (int) $post->ID,
			'parent_id' => (int) $post->post_parent,
			'author_id' => (int) $post->post_author,
			'status' => (string) $post->post_status,
			'date_gmt' => (string) $post->post_date_gmt,
			'modified_gmt' => (string) $post->post_modified_gmt,
			'title' => (string) $post->post_title,
			'caption' => (string) $post->post_excerpt,
			'alt' => (string) get_post_meta( $post->ID, '_wp_attachment_image_alt', true ),
			'mime_type' => (string) $post->post_mime_type,
			'is_image' => (bool) wp_attachment_is_image( $post->ID ),
			'url' => (string) wp_get_attachment_url( $post->ID ),
			'file' => $file ? basename( $file ) : '',
			'file_extension' => $file ? strtolower( (string) pathinfo( $file, PATHINFO_EXTENSION ) ) : '',
			'filesize' => $filesize,
			'width' => $width,
			'height' => $height,
			'aspect_ratio' => $width > 0 && $height > 0 ? round( $width / $height, 6 ) : 0,
			'generated_size_count' => isset( $metadata['sizes'] ) && is_array( $metadata['sizes'] ) ? count( $metadata['sizes'] ) : 0,
			'embedded_metadata_present' => ! empty( $metadata['image_meta'] ) && is_array( $metadata['image_meta'] ),
			'metadata_sha256' => hash( 'sha256', false === $encoded ? '' : $encoded ),
			'detail_level' => $include_details ? 'full' : 'summary',
		);
		if ( $include_details ) {
			$provenance = get_post_meta( $post->ID, self::REMOTE_PROVENANCE_META, true );
			if ( is_array( $provenance ) && self::REMOTE_PROVENANCE_CONTRACT === ( isset( $provenance['contract'] ) ? (string) $provenance['contract'] : '' ) ) $payload['remote_provenance'] = $provenance;
			$payload['description'] = (string) $post->post_content;
			$payload['sizes'] = isset( $metadata['sizes'] ) && is_array( $metadata['sizes'] ) ? $metadata['sizes'] : array();
			$payload['image_meta'] = isset( $metadata['image_meta'] ) && is_array( $metadata['image_meta'] ) ? $metadata['image_meta'] : array();
			$payload['metadata'] = $metadata;
		}
		return $payload;
	}

	private function normalize_remote_import_input( array $input ) {
		$source_url = $this->normalize_https_url( isset( $input['source_url'] ) ? $input['source_url'] : '' );
		if ( is_wp_error( $source_url ) ) return $source_url;
		$source_page_url = '';
		if ( isset( $input['source_page_url'] ) && '' !== trim( (string) $input['source_page_url'] ) ) {
			$source_page_url = $this->normalize_https_url( $input['source_page_url'] );
			if ( is_wp_error( $source_page_url ) ) return $source_page_url;
		}
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
		return array(
			'source_url' => $source_url,
			'source_url_sha256' => hash( 'sha256', $source_url ),
			'source_page_url' => $source_page_url,
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
		$best = ''; $best_score = -1.0;
		foreach ( explode( ',', (string) $srcset ) as $candidate ) {
			$candidate = trim( $candidate );
			if ( '' === $candidate ) continue;
			$parts = preg_split( '/\s+/', $candidate );
			$url = isset( $parts[0] ) ? trim( (string) $parts[0] ) : '';
			$descriptor = isset( $parts[1] ) ? trim( (string) $parts[1] ) : '';
			$score = 1.0;
			if ( preg_match( '/^(\d+(?:\.\d+)?)w$/', $descriptor, $match ) ) $score = (float) $match[1];
			elseif ( preg_match( '/^(\d+(?:\.\d+)?)x$/', $descriptor, $match ) ) $score = (float) $match[1] * 10000;
			if ( '' !== $url && $score > $best_score ) { $best = $url; $best_score = $score; }
		}
		return $best;
	}

	private function extract_remote_image_candidates( $html, $page_url, $limit, $same_origin_only ) {
		if ( ! class_exists( 'DOMDocument' ) ) return new WP_Error( 'mad4b_remote_media_dom_unavailable', 'Remote image discovery requires the PHP DOM extension.' );
		$limit = max( 1, min( self::MAX_REMOTE_CANDIDATES, absint( $limit ) ) );
		$previous = libxml_use_internal_errors( true );
		$dom = new DOMDocument();
		$loaded = $dom->loadHTML( (string) $html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING );
		libxml_clear_errors();
		libxml_use_internal_errors( $previous );
		if ( ! $loaded ) return new WP_Error( 'mad4b_remote_media_html_invalid', 'Remote source HTML could not be parsed.' );
		$xpath = new DOMXPath( $dom );
		$out = array(); $seen = array();
		$append = function ( $raw_url, $kind, $alt = '', $title = '', $width = 0, $height = 0 ) use ( &$out, &$seen, $page_url, $limit, $same_origin_only ) {
			if ( count( $out ) >= $limit || '' === trim( (string) $raw_url ) ) return;
			$url = $this->normalize_https_url( $raw_url, $page_url );
			if ( is_wp_error( $url ) || ( $same_origin_only && ! $this->same_origin( $page_url, $url ) ) ) return;
			$digest = hash( 'sha256', $url );
			if ( isset( $seen[ $digest ] ) ) return;
			$seen[ $digest ] = true;
			$out[] = array(
				'source_url' => $url,
				'source_url_sha256' => $digest,
				'kind' => sanitize_key( (string) $kind ),
				'alt' => sanitize_text_field( (string) $alt ),
				'title' => sanitize_text_field( (string) $title ),
				'width_hint' => max( 0, (int) $width ),
				'height_hint' => max( 0, (int) $height ),
			);
		};
		foreach ( $xpath->query( '//meta[@content]' ) as $node ) {
			$key = strtolower( trim( (string) ( $node->getAttribute( 'property' ) ?: $node->getAttribute( 'name' ) ) ) );
			if ( in_array( $key, array( 'og:image', 'og:image:url', 'twitter:image', 'twitter:image:src' ), true ) ) $append( $node->getAttribute( 'content' ), str_replace( ':', '_', $key ) );
		}
		foreach ( $xpath->query( '//img' ) as $node ) {
			$alt = $node->getAttribute( 'alt' ); $title = $node->getAttribute( 'title' );
			$width = absint( $node->getAttribute( 'width' ) ); $height = absint( $node->getAttribute( 'height' ) );
			foreach ( array( 'src', 'data-src', 'data-lazy-src', 'data-original' ) as $attribute ) if ( $node->hasAttribute( $attribute ) ) $append( $node->getAttribute( $attribute ), 'img_' . str_replace( '-', '_', $attribute ), $alt, $title, $width, $height );
			foreach ( array( 'srcset', 'data-srcset' ) as $attribute ) if ( $node->hasAttribute( $attribute ) ) $append( $this->best_srcset_url( $node->getAttribute( $attribute ) ), 'img_' . str_replace( '-', '_', $attribute ), $alt, $title, $width, $height );
		}
		foreach ( $xpath->query( '//source[@srcset]' ) as $node ) $append( $this->best_srcset_url( $node->getAttribute( 'srcset' ) ), 'source_srcset' );
		return $out;
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
		if ( empty( $ids ) ) return array( 'exists' => false, 'source_url_sha256' => $source_hash );
		return array( 'exists' => true, 'source_url_sha256' => $source_hash, 'attachment_id' => (int) $ids[0] );
	}

	private function download_remote_image( array $normalized ) {
		if ( ! class_exists( 'MAD4B_SCP_Egress_Policy' ) ) return new WP_Error( 'mad4b_remote_media_egress_unavailable', 'Remote media import requires the governed egress policy.' );
		if ( ! function_exists( 'wp_tempnam' ) ) require_once ABSPATH . 'wp-admin/includes/file.php';
		$tmp = wp_tempnam( $normalized['filename'] );
		if ( ! $tmp ) return new WP_Error( 'mad4b_remote_media_tempfile_failed', 'Unable to allocate a temporary file for remote media import.' );
		$args = array(
			'timeout' => 25, 'redirection' => 0, 'reject_unsafe_urls' => true,
			'stream' => true, 'filename' => $tmp, 'limit_response_size' => self::MAX_REMOTE_IMAGE_BYTES + 1,
			'headers' => array( 'Accept' => 'image/avif,image/webp,image/png,image/jpeg,image/gif;q=0.9' ),
		);
		$args = MAD4B_SCP_Egress_Policy::mark_request( 'remote_media_import', $normalized['source_url'], $this->url_origin( $normalized['source_url'] ), $args );
		if ( is_wp_error( $args ) ) { @unlink( $tmp ); return $args; }
		$response = wp_safe_remote_get( $normalized['source_url'], $args );
		if ( is_wp_error( $response ) ) { @unlink( $tmp ); return MAD4B_SCP_Egress_Policy::classify_transport_error( $response, 'remote_media_import' ); }
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

	private function post_binding_template( $attachment_id, array $normalized ) {
		return array(
			'ordering' => 'media_library_first_then_post_binding',
			'attachment_id' => (int) $attachment_id,
			'featured_media_id' => (int) $attachment_id,
			'single_media_meta_value' => (int) $attachment_id,
			'gallery_append_value' => (int) $attachment_id,
			'contextual_source_url' => $normalized['source_url'],
			'rights_basis' => $normalized['rights_basis'],
			'license_expires_on' => $normalized['license_expires_on'],
			'content_experience_requirement' => 'Bind only through a configured media_meta_fields profile entry or featured_media_id.',
		);
	}

	private function mutable_state_from_post( $post ) {
		return array(
			'id' => (int) $post->ID,
			'title' => (string) $post->post_title,
			'caption' => (string) $post->post_excerpt,
			'description' => (string) $post->post_content,
			'alt' => (string) get_post_meta( $post->ID, '_wp_attachment_image_alt', true ),
		);
	}

	private function mutable_payload( array $payload ) {
		// Optimistic concurrency and reversible verification cover only state owned
		// by the reversible metadata contract. Generated technical metadata and
		// post_modified_gmt remain observable but are not part of the reversible hash.
		return array_intersect_key(
			$payload,
			array(
				'id' => true,
				'title' => true,
				'caption' => true,
				'description' => true,
				'alt' => true,
			)
		);
	}

	private function restore_metadata_state( array $payload ) {
		return array(
			'title' => isset( $payload['title'] ) ? (string) $payload['title'] : '',
			'caption' => isset( $payload['caption'] ) ? (string) $payload['caption'] : '',
			'description' => isset( $payload['description'] ) ? (string) $payload['description'] : '',
			'alt' => isset( $payload['alt'] ) ? (string) $payload['alt'] : '',
		);
	}
}
