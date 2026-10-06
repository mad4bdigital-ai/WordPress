<?php

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Read-only planner that turns verified Media Library attachment IDs into the
 * exact Content Experience featured/media-meta fragment required by a profile.
 */
final class MAD4B_SCP_Content_Experience_Media_Binding {
	const CONTRACT = 'mad4b.content-experience-media-binding-plan.v1';

	public static function plan( $input = array() ) {
		$input = is_array( $input ) ? $input : array();
		$slug = isset( $input['profile_slug'] ) ? (string) $input['profile_slug'] : '';
		$profile = MAD4B_SCP_Content_Experience_Profiles::profile( $slug );
		if ( is_wp_error( $profile ) ) return $profile;
		if ( empty( $profile['enabled'] ) ) return new WP_Error( 'mad4b_content_experience_media_binding_profile_disabled', 'Media binding requires an enabled Content Experience profile.' );

		$items = isset( $input['items'] ) && is_array( $input['items'] ) ? array_values( $input['items'] ) : array();
		if ( empty( $items ) || count( $items ) > MAD4B_SCP_Content_Experience_Profiles::MAX_MEDIA_GALLERY_ITEMS ) {
			return new WP_Error( 'mad4b_content_experience_media_binding_items_invalid', 'Media binding requires a bounded non-empty ordered item list.' );
		}
		$specs = isset( $profile['media_meta_fields'] ) && is_array( $profile['media_meta_fields'] ) ? $profile['media_meta_fields'] : array();
		$gallery_fields = array(); $single_fields = array(); $usage_by_reference = array();
		foreach ( $specs as $key => $spec ) {
			$kind = isset( $spec['kind'] ) ? (string) $spec['kind'] : '';
			if ( false !== strpos( $kind, '_usage' ) ) {
				$reference = isset( $spec['references_field'] ) ? (string) $spec['references_field'] : '';
				if ( '' !== $reference ) $usage_by_reference[ $reference ][] = (string) $key;
				continue;
			}
			if ( false !== strpos( $kind, '_gallery' ) ) $gallery_fields[] = (string) $key;
			else $single_fields[] = (string) $key;
		}

		$featured_id = 0; $meta = array(); $logical_groups = array(); $usage_groups = array(); $resolved = array();
		foreach ( $items as $index => $item ) {
			if ( ! is_array( $item ) ) return new WP_Error( 'mad4b_content_experience_media_binding_item_invalid', 'Media binding item must be an object.', array( 'index' => $index ) );
			$id = isset( $item['attachment_id'] ) ? absint( $item['attachment_id'] ) : 0;
			if ( $id < 1 || 'attachment' !== get_post_type( $id ) || ! current_user_can( 'read_post', $id ) ) {
				return new WP_Error( 'mad4b_content_experience_media_binding_attachment_invalid', 'Media binding item references an unreadable or missing attachment.', array( 'index' => $index, 'attachment_id' => $id ) );
			}
			$role = isset( $item['binding_role'] ) ? sanitize_key( (string) $item['binding_role'] ) : 'gallery';
			if ( ! in_array( $role, array( 'featured', 'gallery', 'field' ), true ) ) return new WP_Error( 'mad4b_content_experience_media_binding_role_invalid', 'binding_role must be featured, gallery or field.', array( 'index' => $index ) );
			$target = isset( $item['target_field'] ) ? (string) $item['target_field'] : '';

			if ( 'featured' === $role ) {
				if ( empty( $profile['featured_media'] ) ) return new WP_Error( 'mad4b_content_experience_media_binding_featured_disabled', 'Profile does not permit featured media.' );
				if ( $featured_id > 0 ) return new WP_Error( 'mad4b_content_experience_media_binding_featured_multiple', 'Media binding plan may contain only one featured attachment.' );
				if ( ! wp_attachment_is_image( $id ) ) return new WP_Error( 'mad4b_content_experience_media_binding_featured_image_required', 'Featured media must be an image attachment.' );
				$featured_id = $id;
				$resolved[] = array( 'index' => $index, 'attachment_id' => $id, 'binding_role' => 'featured', 'target_field' => '' );
				continue;
			}

			$candidates = 'gallery' === $role ? $gallery_fields : $single_fields;
			if ( '' === $target ) {
				if ( 1 !== count( $candidates ) ) return new WP_Error(
					'mad4b_content_experience_media_binding_target_ambiguous',
					'Media binding target field must be explicit when the profile has zero or multiple compatible fields.',
					array( 'index' => $index, 'binding_role' => $role, 'candidate_fields' => $candidates )
				);
				$target = $candidates[0];
			}
			if ( ! isset( $specs[ $target ] ) || false !== strpos( (string) $specs[ $target ]['kind'], '_usage' ) ) {
				return new WP_Error( 'mad4b_content_experience_media_binding_target_invalid', 'Media binding target is not a configured media reference field.', array( 'index' => $index, 'target_field' => $target ) );
			}
			$is_gallery = false !== strpos( (string) $specs[ $target ]['kind'], '_gallery' );
			if ( ( 'gallery' === $role ) !== $is_gallery ) return new WP_Error( 'mad4b_content_experience_media_binding_target_kind_mismatch', 'Media binding role does not match the configured target field kind.', array( 'index' => $index, 'target_field' => $target ) );

			if ( $is_gallery ) $logical_groups[ $target ][] = $id;
			elseif ( isset( $logical_groups[ $target ] ) ) return new WP_Error( 'mad4b_content_experience_media_binding_single_multiple', 'Single-media target field cannot receive more than one attachment.', array( 'target_field' => $target ) );
			else $logical_groups[ $target ] = $id;

			$usage = isset( $item['usage'] ) && is_array( $item['usage'] ) ? $item['usage'] : array();
			$usage_field = isset( $item['usage_field'] ) ? (string) $item['usage_field'] : '';
			if ( $usage || '' !== $usage_field ) {
				if ( ! $is_gallery ) return new WP_Error( 'mad4b_content_experience_media_binding_usage_requires_gallery', 'Contextual media usage is supported only for configured gallery fields.', array( 'index' => $index ) );
				$usage_candidates = isset( $usage_by_reference[ $target ] ) ? array_values( $usage_by_reference[ $target ] ) : array();
				if ( '' === $usage_field ) {
					if ( 1 !== count( $usage_candidates ) ) return new WP_Error( 'mad4b_content_experience_media_binding_usage_ambiguous', 'usage_field must be explicit when zero or multiple usage fields reference the gallery.', array( 'index' => $index, 'target_field' => $target, 'candidate_fields' => $usage_candidates ) );
					$usage_field = $usage_candidates[0];
				}
				if ( ! in_array( $usage_field, $usage_candidates, true ) ) return new WP_Error( 'mad4b_content_experience_media_binding_usage_invalid', 'usage_field does not reference the selected gallery field.', array( 'index' => $index, 'usage_field' => $usage_field, 'target_field' => $target ) );
				unset( $usage['attachment_id'] );
				$usage_groups[ $usage_field ][] = array_merge( array( 'attachment_id' => $id ), $usage );
			}
			$resolved[] = array( 'index' => $index, 'attachment_id' => $id, 'binding_role' => $role, 'target_field' => $target, 'usage_field' => $usage_field );
		}

		foreach ( $logical_groups as $key => $value ) $meta[ $key ] = $value;
		foreach ( $usage_groups as $key => $value ) $meta[ $key ] = $value;

		$storage_projection = array();
		foreach ( $meta as $key => $value ) {
			if ( ! isset( $specs[ $key ] ) ) return new WP_Error( 'mad4b_content_experience_media_binding_spec_missing', 'Resolved media binding field is absent from the exact profile snapshot.', array( 'key' => $key ) );
			$normalized = MAD4B_SCP_Content_Experience_Media::normalize_meta_value( $key, $value, $specs[ $key ] );
			if ( is_wp_error( $normalized ) ) return $normalized;
			$storage_projection[ $key ] = $normalized;
		}
		$usage_guard = MAD4B_SCP_Content_Experience_Media::validate_usage_bindings( $specs, $storage_projection );
		if ( is_wp_error( $usage_guard ) ) return $usage_guard;

		$fragment = array();
		if ( $featured_id > 0 ) $fragment['featured_media_id'] = $featured_id;
		if ( $meta ) $fragment['meta'] = $meta;
		$result = array(
			'contract' => self::CONTRACT,
			'profile_slug' => (string) $profile['slug'],
			'profile_revision' => isset( $profile['revision'] ) ? (int) $profile['revision'] : 0,
			'profile_authority_sha256' => isset( $profile['authority_sha256'] ) ? (string) $profile['authority_sha256'] : '',
			'resolved_items' => $resolved,
			'content_input_fragment' => $fragment,
			'storage_projection' => $storage_projection,
			'item_count' => count( $resolved ),
			'featured_attachment_id' => $featured_id,
			'mutation_performed' => false,
		);
		$result['binding_plan_sha256'] = hash( 'sha256', wp_json_encode( $result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
		return $result;
	}
}
