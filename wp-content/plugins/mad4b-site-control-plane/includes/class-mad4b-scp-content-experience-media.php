<?php

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Typed media-reference validation for Content Experience meta fields.
 *
 * Provider-neutral: profiles declare storage semantics while this service
 * validates attachment identity, media type, visibility, order and bounds.
 */
final class MAD4B_SCP_Content_Experience_Media {
	const MAX_META_VALUE_BYTES = 262144;

	public static function normalize_meta_value( $key, $value, array $spec ) {
		$key = (string) $key;
		$kind = isset( $spec['kind'] ) ? (string) $spec['kind'] : '';
		$storage = isset( $spec['storage'] ) ? (string) $spec['storage'] : '';
		$is_gallery = false !== strpos( $kind, '_gallery' );
		$image_only = 0 === strpos( $kind, 'image_' ) || 'image_id' === $kind;
		$max_items = isset( $spec['max_items'] )
			? max( 1, min( MAD4B_SCP_Content_Experience_Profiles::MAX_MEDIA_GALLERY_ITEMS, absint( $spec['max_items'] ) ) )
			: 1;
		$ids = array();

		if ( $is_gallery ) {
			if ( ! is_array( $value ) || array_values( $value ) !== $value ) {
				return new WP_Error( 'mad4b_content_experience_media_gallery_shape_invalid', 'Media gallery metadata must be a list of attachment IDs.', array( 'key' => $key ) );
			}
			if ( count( $value ) > $max_items ) {
				return new WP_Error( 'mad4b_content_experience_media_gallery_limit', 'Media gallery exceeds the configured item limit.', array( 'key' => $key, 'max_items' => $max_items ) );
			}
			foreach ( $value as $candidate ) {
				if ( ! is_int( $candidate ) && ! ( is_string( $candidate ) && ctype_digit( $candidate ) ) ) {
					return new WP_Error( 'mad4b_content_experience_media_id_invalid', 'Media gallery contains a non-integer attachment ID.', array( 'key' => $key ) );
				}
				$id = absint( $candidate );
				if ( $id < 1 ) return new WP_Error( 'mad4b_content_experience_media_id_invalid', 'Media gallery attachment IDs must be positive.', array( 'key' => $key ) );
				$ids[] = $id;
			}
			if ( count( array_unique( $ids ) ) !== count( $ids ) ) {
				return new WP_Error( 'mad4b_content_experience_media_gallery_duplicate', 'Media gallery contains duplicate attachment IDs.', array( 'key' => $key ) );
			}
		} else {
			if ( null === $value || '' === $value || 0 === $value || '0' === $value ) return 0;
			if ( ! is_int( $value ) && ! ( is_string( $value ) && ctype_digit( $value ) ) ) {
				return new WP_Error( 'mad4b_content_experience_media_id_invalid', 'Media metadata must reference one attachment ID.', array( 'key' => $key ) );
			}
			$id = absint( $value );
			if ( $id < 1 ) return new WP_Error( 'mad4b_content_experience_media_id_invalid', 'Media attachment ID must be positive.', array( 'key' => $key ) );
			$ids[] = $id;
		}

		foreach ( $ids as $id ) {
			if ( 'attachment' !== get_post_type( $id ) ) {
				return new WP_Error( 'mad4b_content_experience_media_attachment_missing', 'Media metadata references a missing or non-attachment object.', array( 'key' => $key, 'attachment_id' => $id ) );
			}
			if ( $image_only && ! wp_attachment_is_image( $id ) ) {
				return new WP_Error( 'mad4b_content_experience_media_image_required', 'Image metadata field references a non-image attachment.', array( 'key' => $key, 'attachment_id' => $id ) );
			}
			if ( ! current_user_can( 'read_post', $id ) ) {
				return new WP_Error( 'mad4b_content_experience_media_read_denied', 'Current user cannot read one referenced media attachment.', array( 'key' => $key, 'attachment_id' => $id ) );
			}
		}

		if ( ! $is_gallery ) return isset( $ids[0] ) ? (int) $ids[0] : 0;
		return 'csv_ids' === $storage ? implode( ',', $ids ) : $ids;
	}

	public static function value_within_budget( $value ) {
		$encoded = wp_json_encode( $value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		return false !== $encoded && strlen( $encoded ) <= self::MAX_META_VALUE_BYTES;
	}
}
