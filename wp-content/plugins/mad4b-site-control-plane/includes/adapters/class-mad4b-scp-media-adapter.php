<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
final class MAD4B_SCP_Media_Adapter extends MAD4B_SCP_Adapter_Base {
	public function id() { return 'media'; }
	public function label() { return 'Media'; }
	public function is_available() { return true; }
	public function ability_names() { return array( 'read' => array( 'media/search', 'media/get' ), 'content' => array( 'media/update-metadata', 'media/set-featured' ), 'admin' => array() ); }
	public function reversible_contracts() { return array( 'media/update-metadata' => 'mad4b.rollback.media-metadata.v1', 'media/set-featured' => 'mad4b.rollback.featured-image.v1' ); }
	public function register_abilities() {
		$this->add_ability( 'media/search', 'Search Media Library', 'search', array( 'MAD4B_SCP_Policy', 'can_read' ), $this->schema( array( 'search' => array( 'type' => 'string', 'default' => '' ), 'mime_type' => array( 'type' => 'string', 'default' => '' ), 'limit' => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 50, 'default' => 20 ) ) ) );
		$this->add_ability( 'media/get', 'Get Media Item', 'get_media', array( $this, 'can_read_attachment' ), $this->schema( array( 'attachment_id' => array( 'type' => 'integer', 'minimum' => 1 ) ), array( 'attachment_id' ) ) );
		$this->add_ability( 'media/update-metadata', 'Update Media Metadata', 'update_metadata', array( $this, 'can_edit_attachment' ), $this->schema( array(
			'attachment_id' => array( 'type' => 'integer', 'minimum' => 1 ), 'expected_sha256' => array( 'type' => 'string', 'minLength' => 64, 'maxLength' => 64 ), 'title' => array( 'type' => 'string' ), 'caption' => array( 'type' => 'string' ), 'description' => array( 'type' => 'string' ), 'alt' => array( 'type' => 'string' )
		), array( 'attachment_id', 'expected_sha256' ) ), 'content', false, false, true );
		$this->add_ability( 'media/set-featured', 'Set Featured Image', 'set_featured', array( $this, 'can_set_featured' ), $this->schema( array(
			'post_id' => array( 'type' => 'integer', 'minimum' => 1 ), 'attachment_id' => array( 'type' => 'integer', 'minimum' => 1 ), 'expected_thumbnail_id' => array( 'type' => 'integer', 'minimum' => 0 )
		), array( 'post_id', 'attachment_id', 'expected_thumbnail_id' ) ), 'content', false, false, true );
	}
	public function can_read_attachment( $input ) { $id = isset( $input['attachment_id'] ) ? absint( $input['attachment_id'] ) : 0; return $id > 0 && current_user_can( 'read_post', $id ); }
	public function can_edit_attachment( $input ) { $id = isset( $input['attachment_id'] ) ? absint( $input['attachment_id'] ) : 0; return $id > 0 && current_user_can( 'edit_post', $id ); }
	public function can_set_featured( $input ) { $post = isset( $input['post_id'] ) ? absint( $input['post_id'] ) : 0; $att = isset( $input['attachment_id'] ) ? absint( $input['attachment_id'] ) : 0; return $post > 0 && $att > 0 && current_user_can( 'edit_post', $post ) && current_user_can( 'read_post', $att ); }
	public function search( $input ) {
		$limit = isset( $input['limit'] ) ? max( 1, min( 50, absint( $input['limit'] ) ) ) : 20;
		$args = array( 'post_type' => 'attachment', 'post_status' => 'inherit', 'posts_per_page' => $limit, 'orderby' => 'date', 'order' => 'DESC' );
		if ( ! empty( $input['search'] ) ) $args['s'] = sanitize_text_field( $input['search'] );
		if ( ! empty( $input['mime_type'] ) ) $args['post_mime_type'] = sanitize_text_field( $input['mime_type'] );
		$posts = get_posts( $args ); $items = array();
		foreach ( $posts as $post ) { $payload = $this->media_payload( $post ); $items[] = array( 'media' => $payload, 'sha256' => $this->hash_value( $this->mutable_payload( $payload ) ) ); }
		return array( 'items' => $items, 'count' => count( $items ) );
	}
	public function get_media( $input ) {
		$post = get_post( absint( $input['attachment_id'] ) ); if ( ! $post || 'attachment' !== $post->post_type ) return new WP_Error( 'mad4b_media_missing', 'Attachment not found.' );
		$payload = $this->media_payload( $post );
		return array( 'media' => $payload, 'sha256' => $this->hash_value( $this->mutable_payload( $payload ) ) );
	}
	public function update_metadata( $input ) {
		$id = absint( $input['attachment_id'] ); $post = get_post( $id ); if ( ! $post || 'attachment' !== $post->post_type ) return new WP_Error( 'mad4b_media_missing', 'Attachment not found.' );
		$current = $this->get_media( array( 'attachment_id' => $id ) );
		if ( is_wp_error( $current ) ) return $current;
		if ( ! hash_equals( $current['sha256'], strtolower( trim( $input['expected_sha256'] ) ) ) ) return new WP_Error( 'mad4b_media_stale', 'Media metadata changed since it was read.', array( 'current_sha256' => $current['sha256'] ) );
		$update = array( 'ID' => $id );
		if ( array_key_exists( 'title', $input ) ) $update['post_title'] = sanitize_text_field( $input['title'] );
		if ( array_key_exists( 'caption', $input ) ) $update['post_excerpt'] = sanitize_textarea_field( $input['caption'] );
		if ( array_key_exists( 'description', $input ) ) $update['post_content'] = wp_kses_post( $input['description'] );
		if ( count( $update ) > 1 ) { $result = wp_update_post( wp_slash( $update ), true ); if ( is_wp_error( $result ) ) return $result; }
		if ( array_key_exists( 'alt', $input ) ) update_post_meta( $id, '_wp_attachment_image_alt', sanitize_text_field( $input['alt'] ) );
		$after = $this->get_media( array( 'attachment_id' => $id ) );
		MAD4B_SCP_Audit::record( 'media/update-metadata', array( 'attachment_id' => $id, 'fields' => implode( ',', array_keys( array_diff_key( $input, array( 'attachment_id' => true, 'expected_sha256' => true ) ) ) ), 'before_sha256' => $current['sha256'], 'after_sha256' => is_wp_error( $after ) ? '' : $after['sha256'] ) );
		return $after;
	}
	public function set_featured( $input ) {
		$post_id = absint( $input['post_id'] ); $attachment_id = absint( $input['attachment_id'] );
		$current = (int) get_post_thumbnail_id( $post_id );
		if ( $current !== (int) $input['expected_thumbnail_id'] ) return new WP_Error( 'mad4b_media_stale_thumbnail', 'Featured image changed since it was read.', array( 'current_thumbnail_id' => $current ) );
		if ( ! wp_attachment_is_image( $attachment_id ) ) return new WP_Error( 'mad4b_media_not_image', 'Attachment is not an image.' );
		$result = set_post_thumbnail( $post_id, $attachment_id ); if ( false === $result ) return new WP_Error( 'mad4b_media_featured_failed', 'Unable to set featured image.' );
		MAD4B_SCP_Audit::record( 'media/set-featured', array( 'post_id' => $post_id, 'before_thumbnail_id' => $current, 'attachment_id' => $attachment_id ) );
		return array( 'post_id' => $post_id, 'attachment_id' => $attachment_id, 'updated' => true );
	}
	public function capture_reversible_state( $ability_name, array $input ) {
		if ( 'media/update-metadata' === $ability_name ) {
			$id = isset( $input['attachment_id'] ) ? absint( $input['attachment_id'] ) : 0;
			$current = $this->get_media( array( 'attachment_id' => $id ) );
			if ( is_wp_error( $current ) ) return $current;
			$expected = isset( $input['expected_sha256'] ) ? strtolower( trim( (string) $input['expected_sha256'] ) ) : '';
			if ( '' === $expected || ! hash_equals( $current['sha256'], $expected ) ) return new WP_Error( 'mad4b_media_stale', 'Media metadata changed since it was read.', array( 'current_sha256' => $current['sha256'] ) );
			return array( 'target_type' => 'media', 'target_id' => (string) $id, 'target' => array( 'attachment_id' => $id ), 'state' => $this->restore_metadata_state( $current['media'] ) );
		}
		if ( 'media/set-featured' === $ability_name ) {
			$post_id = isset( $input['post_id'] ) ? absint( $input['post_id'] ) : 0;
			$current = (int) get_post_thumbnail_id( $post_id );
			if ( ! isset( $input['expected_thumbnail_id'] ) || $current !== (int) $input['expected_thumbnail_id'] ) return new WP_Error( 'mad4b_media_stale_thumbnail', 'Featured image changed since it was read.', array( 'current_thumbnail_id' => $current ) );
			return array( 'target_type' => 'post-featured-image', 'target_id' => (string) $post_id, 'target' => array( 'post_id' => $post_id ), 'state' => array( 'thumbnail_id' => $current ) );
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
		return parent::read_reversible_state( $ability_name, $target );
	}
	public function restore_reversible_state( $ability_name, array $target, array $state, array $record ) {
		if ( 'media/update-metadata' === $ability_name ) {
			$id = isset( $target['attachment_id'] ) ? absint( $target['attachment_id'] ) : 0;
			if ( ! $id || ! current_user_can( 'edit_post', $id ) ) return new WP_Error( 'mad4b_media_restore_denied', 'Current user cannot restore this attachment.' );
			foreach ( array( 'title', 'caption', 'description', 'alt' ) as $field ) if ( ! array_key_exists( $field, $state ) ) return new WP_Error( 'mad4b_media_restore_payload_invalid', 'Media rollback state is incomplete.' );
			$result = wp_update_post( wp_slash( array( 'ID' => $id, 'post_title' => $state['title'], 'post_excerpt' => $state['caption'], 'post_content' => $state['description'] ) ), true );
			if ( is_wp_error( $result ) ) return $result;
			update_post_meta( $id, '_wp_attachment_image_alt', sanitize_text_field( (string) $state['alt'] ) );
			return true;
		}
		if ( 'media/set-featured' === $ability_name ) {
			$post_id = isset( $target['post_id'] ) ? absint( $target['post_id'] ) : 0;
			if ( ! $post_id || ! current_user_can( 'edit_post', $post_id ) ) return new WP_Error( 'mad4b_media_restore_denied', 'Current user cannot restore this post featured image.' );
			if ( ! array_key_exists( 'thumbnail_id', $state ) ) return new WP_Error( 'mad4b_media_restore_payload_invalid', 'Featured-image rollback state is incomplete.' );
			$thumbnail_id = absint( $state['thumbnail_id'] );
			if ( 0 === $thumbnail_id ) return delete_post_thumbnail( $post_id ) || 0 === (int) get_post_thumbnail_id( $post_id ) ? true : new WP_Error( 'mad4b_media_restore_failed', 'Unable to clear featured image.' );
			return false !== set_post_thumbnail( $post_id, $thumbnail_id ) ? true : new WP_Error( 'mad4b_media_restore_failed', 'Unable to restore featured image.' );
		}
		return parent::restore_reversible_state( $ability_name, $target, $state, $record );
	}
	private function media_payload( $post ) {
		$file = get_attached_file( $post->ID, true );
		return array( 'id' => $post->ID, 'title' => $post->post_title, 'caption' => $post->post_excerpt, 'description' => $post->post_content, 'alt' => get_post_meta( $post->ID, '_wp_attachment_image_alt', true ), 'mime_type' => $post->post_mime_type, 'modified_gmt' => $post->post_modified_gmt, 'url' => wp_get_attachment_url( $post->ID ), 'file' => $file ? basename( $file ) : '', 'metadata' => wp_get_attachment_metadata( $post->ID ) );
	}
	private function mutable_payload( array $payload ) { return array_intersect_key( $payload, array( 'id' => true, 'title' => true, 'caption' => true, 'description' => true, 'alt' => true, 'modified_gmt' => true ) ); }
	private function restore_metadata_state( array $payload ) { return array( 'title' => isset( $payload['title'] ) ? (string) $payload['title'] : '', 'caption' => isset( $payload['caption'] ) ? (string) $payload['caption'] : '', 'description' => isset( $payload['description'] ) ? (string) $payload['description'] : '', 'alt' => isset( $payload['alt'] ) ? (string) $payload['alt'] : '' ); }
}
