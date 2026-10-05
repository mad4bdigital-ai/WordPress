<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class MAD4B_SCP_Media_Adapter extends MAD4B_SCP_Adapter_Base {
	public function id() { return 'media'; }
	public function label() { return 'Media'; }
	public function is_available() { return true; }

	public function ability_names() {
		return array(
			'read' => array( 'media/search', 'media/get' ),
			'content' => array( 'media/update-metadata', 'media/set-featured', 'media/set-parent' ),
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
			$payload['description'] = (string) $post->post_content;
			$payload['sizes'] = isset( $metadata['sizes'] ) && is_array( $metadata['sizes'] ) ? $metadata['sizes'] : array();
			$payload['image_meta'] = isset( $metadata['image_meta'] ) && is_array( $metadata['image_meta'] ) ? $metadata['image_meta'] : array();
			$payload['metadata'] = $metadata;
		}
		return $payload;
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
