<?php

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Read-only planner that turns verified Media Library attachment IDs into the
 * exact Content Experience featured/media-meta fragment required by a profile.
 */
final class MAD4B_SCP_Content_Experience_Media_Binding {
	const CONTRACT = 'mad4b.content-experience-media-binding-plan.v1';
	const STATE_CONTRACT = 'mad4b.content-experience-media-binding-state.v1';

	public static function attachment_ids_from_state( $featured_media_id, array $field_specs, array $normalized_meta ) {
		$ids = array();
		$featured_media_id = absint( $featured_media_id );
		if ( $featured_media_id > 0 ) $ids[] = $featured_media_id;
		foreach ( $field_specs as $key => $spec ) {
			if ( ! array_key_exists( $key, $normalized_meta ) || ! is_array( $spec ) ) continue;
			$kind = isset( $spec['kind'] ) ? (string) $spec['kind'] : '';
			if ( false !== strpos( $kind, '_usage' ) ) continue;
			$ids = array_merge( $ids, MAD4B_SCP_Content_Experience_Media_Storage::reference_ids( $normalized_meta[ $key ], $spec ) );
		}
		$ids = array_values( array_unique( array_filter( array_map( 'absint', $ids ) ) ) );
		sort( $ids, SORT_NUMERIC );
		return $ids;
	}

	public static function state_sha256( array $profile, $featured_media_id, array $normalized_meta, $manifest_sha256 = '' ) {
		$fields = array();
		$specs = isset( $profile['media_meta_fields'] ) && is_array( $profile['media_meta_fields'] ) ? $profile['media_meta_fields'] : array();
		foreach ( $specs as $key => $spec ) {
			if ( ! array_key_exists( $key, $normalized_meta ) ) continue;
			$value = $normalized_meta[ $key ];
			$encoded = wp_json_encode( $value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
			$fields[ $key ] = array(
				'kind' => isset( $spec['kind'] ) ? (string) $spec['kind'] : '',
				'storage' => isset( $spec['storage'] ) ? (string) $spec['storage'] : '',
				'value_sha256' => hash( 'sha256', false === $encoded ? '' : $encoded ),
				'attachment_ids' => false !== strpos( isset( $spec['kind'] ) ? (string) $spec['kind'] : '', '_usage' )
					? array_values( array_map( 'absint', array_column( (array) $value, 'attachment_id' ) ) )
					: MAD4B_SCP_Content_Experience_Media_Storage::reference_ids( $value, $spec ),
			);
		}
		ksort( $fields, SORT_STRING );
		$identity = array(
			'contract' => self::STATE_CONTRACT,
			'profile_slug' => isset( $profile['slug'] ) ? (string) $profile['slug'] : '',
			'profile_revision' => isset( $profile['revision'] ) ? (int) $profile['revision'] : 0,
			'profile_authority_sha256' => isset( $profile['authority_sha256'] ) ? (string) $profile['authority_sha256'] : '',
			'manifest_sha256' => strtolower( trim( (string) $manifest_sha256 ) ),
			'featured_media_id' => absint( $featured_media_id ),
			'fields' => $fields,
		);
		return hash( 'sha256', wp_json_encode( $identity, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
	}

	public static function plan( $input = array() ) {
		$input = is_array( $input ) ? $input : array();
		$slug = isset( $input['profile_slug'] ) ? (string) $input['profile_slug'] : '';
		$profile = MAD4B_SCP_Content_Experience_Profiles::profile( $slug );
		if ( is_wp_error( $profile ) ) return $profile;
		if ( empty( $profile['enabled'] ) ) return new WP_Error( 'mad4b_content_experience_media_binding_profile_disabled', 'Media binding requires an enabled Content Experience profile.' );
		$manifest_sha256 = isset( $input['manifest_sha256'] ) ? strtolower( trim( (string) $input['manifest_sha256'] ) ) : '';
		$manifest_item_count = isset( $input['manifest_item_count'] ) ? (int) $input['manifest_item_count'] : 0;
		$manifest_receipt = null;
		if ( '' !== $manifest_sha256 ) {
			if ( ! preg_match( '/^[a-f0-9]{64}$/', $manifest_sha256 ) ) return new WP_Error( 'mad4b_content_experience_media_binding_manifest_invalid', 'Media binding manifest identity is invalid.' );
			$manifest_receipt = MAD4B_SCP_Remote_Media_Recovery::manifest_receipt( $manifest_sha256, $manifest_item_count );
			if ( is_wp_error( $manifest_receipt ) ) return $manifest_receipt;
		}

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

		$featured_id = 0; $meta = array(); $logical_groups = array(); $usage_groups = array(); $resolved = array(); $seen_manifest_indices = array();
		foreach ( $items as $index => $item ) {
			if ( ! is_array( $item ) ) return new WP_Error( 'mad4b_content_experience_media_binding_item_invalid', 'Media binding item must be an object.', array( 'index' => $index ) );
			$id = isset( $item['attachment_id'] ) ? absint( $item['attachment_id'] ) : 0;
			if ( $id < 1 || 'attachment' !== get_post_type( $id ) || ! current_user_can( 'read_post', $id ) ) {
				return new WP_Error( 'mad4b_content_experience_media_binding_attachment_invalid', 'Media binding item references an unreadable or missing attachment.', array( 'index' => $index, 'attachment_id' => $id ) );
			}
			if ( is_array( $manifest_receipt ) ) {
				$manifest_index = isset( $item['manifest_index'] ) ? (int) $item['manifest_index'] : -1;
				if ( $manifest_index < 0 || $manifest_index >= $manifest_item_count ) return new WP_Error( 'mad4b_content_experience_media_binding_manifest_index_invalid', 'Each manifest-bound media item requires a valid manifest_index.', array( 'index' => $index, 'manifest_index' => $manifest_index ) );
				$receipt_item = $manifest_receipt['items'][ $manifest_index ];
				if ( (int) $receipt_item['attachment_id'] !== $id ) return new WP_Error( 'mad4b_content_experience_media_binding_manifest_attachment_drift', 'Binding attachment does not match the staged import at this manifest index.', array( 'manifest_index' => $manifest_index, 'attachment_id' => $id ) );
				if ( isset( $item['import_plan_sha256'] ) && '' !== (string) $item['import_plan_sha256'] && ! hash_equals( (string) $receipt_item['import_plan_sha256'], strtolower( trim( (string) $item['import_plan_sha256'] ) ) ) ) return new WP_Error( 'mad4b_content_experience_media_binding_manifest_plan_drift', 'Binding item import plan does not match the staged manifest receipt.', array( 'manifest_index' => $manifest_index ) );
				if ( isset( $seen_manifest_indices[ $manifest_index ] ) && (int) $seen_manifest_indices[ $manifest_index ] !== $id ) return new WP_Error( 'mad4b_content_experience_media_binding_manifest_index_conflict', 'One manifest index cannot resolve to two different attachment IDs.', array( 'manifest_index' => $manifest_index ) );
				$seen_manifest_indices[ $manifest_index ] = $id;
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

		if ( is_array( $manifest_receipt ) && count( $seen_manifest_indices ) !== $manifest_item_count ) return new WP_Error( 'mad4b_content_experience_media_binding_manifest_incomplete', 'Binding plan must consume every staged manifest item exactly once.' );
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
		$remote_state = MAD4B_SCP_Remote_Media_Rights::state_evidence( $featured_id, $specs, $storage_projection );
		if ( is_wp_error( $remote_state ) ) return $remote_state;

		$binding_state_sha256 = self::state_sha256( $profile, $featured_id, $storage_projection, $manifest_sha256 );
		$fragment = array();
		if ( $featured_id > 0 ) $fragment['featured_media_id'] = $featured_id;
		if ( $meta ) $fragment['meta'] = $meta;
		$fragment['expected_remote_media_state_sha256'] = (string) $remote_state['remote_state_sha256'];
		if ( is_array( $manifest_receipt ) ) {
			$fragment['expected_media_manifest_sha256'] = $manifest_sha256;
			$fragment['expected_media_manifest_item_count'] = $manifest_item_count;
			$fragment['expected_media_recovery_receipt_sha256'] = (string) $manifest_receipt['recovery_receipt_sha256'];
			$fragment['expected_media_binding_state_sha256'] = $binding_state_sha256;
		}
		$result = array(
			'contract' => self::CONTRACT,
			'profile_slug' => (string) $profile['slug'],
			'profile_revision' => isset( $profile['revision'] ) ? (int) $profile['revision'] : 0,
			'profile_authority_sha256' => isset( $profile['authority_sha256'] ) ? (string) $profile['authority_sha256'] : '',
			'resolved_items' => $resolved,
			'content_input_fragment' => $fragment,
			'storage_projection' => $storage_projection,
			'remote_media_state' => $remote_state,
			'remote_media_recovery_receipt' => $manifest_receipt,
			'binding_state_sha256' => $binding_state_sha256,
			'item_count' => count( $resolved ),
			'featured_attachment_id' => $featured_id,
			'mutation_performed' => false,
		);
		$result['binding_plan_sha256'] = hash( 'sha256', wp_json_encode( $result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
		return $result;
	}
}
