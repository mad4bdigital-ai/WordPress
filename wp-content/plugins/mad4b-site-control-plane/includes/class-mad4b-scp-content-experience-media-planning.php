<?php

if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Media-specific planning guards extracted from the generic content runtime. */
final class MAD4B_SCP_Content_Experience_Media_Planning {
	const CONTRACT = 'mad4b.content-experience-media-planning.v1';

	public static function guard( $operation, $post, array $profile, array $meta, array $input ) {
		$media_fields = isset( $profile['media_meta_fields'] ) && is_array( $profile['media_meta_fields'] ) ? $profile['media_meta_fields'] : array();
		$effective_media_state = MAD4B_SCP_Content_Experience_Media::effective_meta_state( $post ? (int) $post->ID : 0, $media_fields, $meta );
		if ( is_wp_error( $effective_media_state ) ) return $effective_media_state;

		$media_publish_rights = null;
		if ( 'publish' === $operation ) {
			$rights_enforced = false;
			foreach ( $media_fields as $spec ) if ( is_array( $spec ) && 'require_valid' === ( isset( $spec['publish_rights_policy'] ) ? (string) $spec['publish_rights_policy'] : 'none' ) ) { $rights_enforced = true; break; }
			if ( $rights_enforced ) {
				$media_publish_rights = MAD4B_SCP_Content_Experience_Media_Rights::publish_guard( $media_fields, $effective_media_state );
				if ( is_wp_error( $media_publish_rights ) ) return $media_publish_rights;
			}
		}

		$featured_media_id = array_key_exists( 'featured_media_id', $input ) ? absint( $input['featured_media_id'] ) : null;
		if ( null !== $featured_media_id ) {
			if ( empty( $profile['featured_media'] ) ) return new WP_Error( 'mad4b_content_experience_featured_media_disabled', 'Featured media is disabled for this experience profile.' );
			if ( $featured_media_id > 0 && 'attachment' !== get_post_type( $featured_media_id ) ) return new WP_Error( 'mad4b_content_experience_featured_media_invalid', 'featured_media_id must reference an attachment.' );
			if ( $featured_media_id > 0 && ! current_user_can( 'read_post', $featured_media_id ) ) return new WP_Error( 'mad4b_content_experience_featured_media_read_denied', 'Current user cannot read the requested featured media attachment.' );
		}
		$effective_featured_media_id = null !== $featured_media_id ? (int) $featured_media_id : ( $post ? (int) get_post_thumbnail_id( $post->ID ) : 0 );

		$remote_media_state = MAD4B_SCP_Remote_Media_Rights::state_evidence( $effective_featured_media_id, $media_fields, $effective_media_state );
		if ( is_wp_error( $remote_media_state ) ) return $remote_media_state;
		$expected_remote_media_state = isset( $input['expected_remote_media_state_sha256'] ) ? strtolower( trim( (string) $input['expected_remote_media_state_sha256'] ) ) : '';
		if ( '' !== $expected_remote_media_state && ( ! preg_match( '/^[a-f0-9]{64}$/', $expected_remote_media_state ) || ! hash_equals( (string) $remote_media_state['remote_state_sha256'], $expected_remote_media_state ) ) ) {
			return new WP_Error( 'mad4b_content_experience_remote_media_state_drift', 'Remote media attachment/provenance state changed after the reviewed Media Library binding plan.', array( 'current_remote_media_state_sha256' => (string) $remote_media_state['remote_state_sha256'] ) );
		}

		$manifest_guard = MAD4B_SCP_Content_Experience_Media_Manifest::plan_guard( $profile, $featured_media_id, $media_fields, $meta, $input );
		if ( is_wp_error( $manifest_guard ) ) return $manifest_guard;
		$remote_rights = null;
		if ( 'publish' === $operation ) {
			$remote_rights = MAD4B_SCP_Remote_Media_Rights::publish_guard( $effective_featured_media_id, $media_fields, $effective_media_state );
			if ( is_wp_error( $remote_rights ) ) return $remote_rights;
		}
		return array(
			'contract' => self::CONTRACT, 'media_fields' => $media_fields,
			'effective_media_state' => $effective_media_state,
			'effective_media_state_sha256' => MAD4B_SCP_Content_Experience_Media::effective_state_sha256( $effective_media_state ),
			'featured_media_id' => $featured_media_id, 'effective_featured_media_id' => $effective_featured_media_id,
			'expected_remote_media_state_sha256' => $expected_remote_media_state, 'remote_media_state' => $remote_media_state,
			'media_publish_rights' => $media_publish_rights, 'manifest_guard' => $manifest_guard,
			'remote_media_provenance_rights' => $remote_rights,
		);
	}
}
