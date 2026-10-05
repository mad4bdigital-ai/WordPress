<?php

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Typed media-reference and per-content media-usage validation.
 *
 * Global attachment metadata (title/caption/description/alt and generated image
 * metadata) remains owned by the Media adapter. Content Experience meta stores
 * only references and explicitly profile-bound contextual usage overrides.
 */
final class MAD4B_SCP_Content_Experience_Media {
	const MAX_META_VALUE_BYTES = 262144;
	const MAX_USAGE_TEXT_BYTES = 65535;
	const MAX_USAGE_URL_BYTES = 8192;

	private static function normalize_string_list( $items, $limit, $pattern = '/^[A-Za-z0-9_.:-]+$/' ) {
		$out = array();
		foreach ( (array) $items as $value ) {
			$value = trim( (string) $value );
			if ( '' === $value || strlen( $value ) > 191 || ! preg_match( $pattern, $value ) ) continue;
			$out[] = $value;
			if ( count( $out ) >= $limit ) break;
		}
		$out = array_values( array_unique( $out ) );
		sort( $out, SORT_STRING );
		return $out;
	}

	public static function normalize_field_specs( $raw, array $meta_keys, array $protected_meta_keys ) {
		$raw = is_array( $raw ) ? $raw : array();
		if ( count( $raw ) > MAD4B_SCP_Content_Experience_Profiles::MAX_MEDIA_META_FIELDS ) {
			return new WP_Error( 'mad4b_content_experience_media_meta_field_limit', 'Media metadata field configuration exceeds the profile limit.' );
		}
		$result = array();
		$reference_kinds = array( 'image_id', 'attachment_id', 'image_gallery', 'attachment_gallery' );
		$usage_kinds = array( 'image_gallery_usage', 'attachment_gallery_usage' );
		$allowed_usage_fields = array(
			'role', 'alt_override', 'caption_override', 'title_override', 'description_override',
			'credit', 'copyright', 'license', 'license_expires_on', 'source_url',
			'focal_point', 'aria_label', 'decorative', 'link_url', 'link_target',
		);

		foreach ( $raw as $key => $spec ) {
			$key = (string) $key;
			if ( '' === $key || strlen( $key ) > 191 || ! preg_match( '/^[A-Za-z0-9_-]+$/', $key ) ) {
				return new WP_Error( 'mad4b_content_experience_media_meta_key_invalid', 'Media metadata field contains an invalid key.' );
			}
			if ( ! in_array( $key, $meta_keys, true ) && ! in_array( $key, $protected_meta_keys, true ) ) {
				return new WP_Error( 'mad4b_content_experience_media_meta_not_allowlisted', 'Media metadata field must also be explicitly enabled by the profile meta allowlist.', array( 'key' => $key ) );
			}
			if ( ! is_array( $spec ) ) $spec = array( 'kind' => (string) $spec );
			$kind = isset( $spec['kind'] ) ? sanitize_key( (string) $spec['kind'] ) : '';
			if ( ! in_array( $kind, array_merge( $reference_kinds, $usage_kinds ), true ) ) {
				return new WP_Error( 'mad4b_content_experience_media_meta_kind_invalid', 'Media metadata field kind is unsupported.', array( 'key' => $key ) );
			}
			$is_usage = in_array( $kind, $usage_kinds, true );
			$is_gallery = false !== strpos( $kind, '_gallery' );
			$storage = isset( $spec['storage'] ) ? sanitize_key( (string) $spec['storage'] ) : ( $is_usage ? 'items' : ( $is_gallery ? 'ids' : 'id' ) );
			if ( $is_usage ) {
				if ( 'items' !== $storage ) return new WP_Error( 'mad4b_content_experience_media_meta_storage_invalid', 'Media usage metadata must use canonical items storage.', array( 'key' => $key ) );
			} elseif ( ! in_array( $storage, array( 'id', 'ids', 'csv_ids' ), true ) ) {
				return new WP_Error( 'mad4b_content_experience_media_meta_storage_invalid', 'Media metadata field storage is unsupported.', array( 'key' => $key ) );
			}
			if ( ! $is_usage && $is_gallery && 'id' === $storage ) {
				return new WP_Error( 'mad4b_content_experience_media_meta_storage_invalid', 'Gallery metadata cannot use single-id storage.', array( 'key' => $key ) );
			}
			if ( ! $is_usage && ! $is_gallery && 'id' !== $storage ) {
				return new WP_Error( 'mad4b_content_experience_media_meta_storage_invalid', 'Single media metadata must use id storage.', array( 'key' => $key ) );
			}

			$max_items = $is_gallery
				? ( isset( $spec['max_items'] ) ? max( 1, min( MAD4B_SCP_Content_Experience_Profiles::MAX_MEDIA_GALLERY_ITEMS, absint( $spec['max_items'] ) ) ) : 50 )
				: 1;
			$row = array( 'kind' => $kind, 'storage' => $storage, 'max_items' => $max_items );

			if ( $is_usage ) {
				$reference = isset( $spec['references_field'] ) ? (string) $spec['references_field'] : '';
				if ( '' === $reference || strlen( $reference ) > 191 || ! preg_match( '/^[A-Za-z0-9_-]+$/', $reference ) || $reference === $key ) {
					return new WP_Error( 'mad4b_content_experience_media_usage_reference_invalid', 'Media usage field must reference one distinct gallery field.', array( 'key' => $key ) );
				}
				$usage_fields = self::normalize_string_list( isset( $spec['usage_fields'] ) ? $spec['usage_fields'] : array(), count( $allowed_usage_fields ), '/^[a-z_]+$/' );
				foreach ( $usage_fields as $field ) {
					if ( ! in_array( $field, $allowed_usage_fields, true ) ) {
						return new WP_Error( 'mad4b_content_experience_media_usage_field_invalid', 'Media usage field contains an unsupported contextual property.', array( 'key' => $key, 'field' => $field ) );
					}
				}
				$roles = self::normalize_string_list( isset( $spec['roles'] ) ? $spec['roles'] : array(), 32, '/^[a-z0-9][a-z0-9_-]*$/' );
				if ( in_array( 'role', $usage_fields, true ) && empty( $roles ) ) {
					return new WP_Error( 'mad4b_content_experience_media_usage_roles_required', 'Media usage role is enabled but no exact role allowlist is configured.', array( 'key' => $key ) );
				}
				$licenses = self::normalize_string_list( isset( $spec['licenses'] ) ? $spec['licenses'] : array(), 32, '/^[a-z0-9][a-z0-9._-]*$/' );
				if ( in_array( 'license', $usage_fields, true ) && empty( $licenses ) ) {
					return new WP_Error( 'mad4b_content_experience_media_usage_licenses_required', 'Media usage license is enabled but no exact license allowlist is configured.', array( 'key' => $key ) );
				}
				$row['references_field'] = $reference;
				$row['usage_fields'] = $usage_fields;
				$row['roles'] = $roles;
				$row['licenses'] = $licenses;
			}
			$result[ $key ] = $row;
		}

		foreach ( $result as $key => $spec ) {
			if ( false === strpos( (string) $spec['kind'], '_usage' ) ) continue;
			$reference = (string) $spec['references_field'];
			if ( ! isset( $result[ $reference ] ) ) {
				return new WP_Error( 'mad4b_content_experience_media_usage_reference_missing', 'Media usage field references a media field that is not configured in the profile.', array( 'key' => $key, 'references_field' => $reference ) );
			}
			$expected_kind = 'image_gallery_usage' === $spec['kind'] ? 'image_gallery' : 'attachment_gallery';
			if ( $expected_kind !== (string) $result[ $reference ]['kind'] ) {
				return new WP_Error( 'mad4b_content_experience_media_usage_reference_kind_mismatch', 'Media usage field does not reference the matching gallery kind.', array( 'key' => $key, 'references_field' => $reference ) );
			}
			if ( (int) $spec['max_items'] > (int) $result[ $reference ]['max_items'] ) {
				$result[ $key ]['max_items'] = (int) $result[ $reference ]['max_items'];
			}
		}
		ksort( $result, SORT_STRING );
		return $result;
	}

	private static function normalize_url( $value ) {
		$error = new WP_Error( 'mad4b_content_experience_media_usage_url_invalid', 'Media usage URLs must be bounded, explicit absolute HTTP(S) URLs without credentials.' );
		if ( ! is_string( $value ) || strlen( $value ) > self::MAX_USAGE_URL_BYTES ) return $error;
		$value = trim( $value );
		if ( '' === $value ) return '';
		// esc_url_raw() preserves relative references and may infer http:// for
		// bare domains. Neither is a portable, exact contextual source/link URL.
		if ( ! preg_match( '#^https?://#i', $value ) ) return $error;
		$parts = parse_url( $value );
		if ( ! is_array( $parts ) || empty( $parts['host'] ) || isset( $parts['user'] ) || isset( $parts['pass'] ) ) return $error;
		$url = esc_url_raw( $value, array( 'http', 'https' ) );
		if ( '' === $url ) {
			return $error;
		}
		return $url;
	}

	private static function normalize_iso_date( $value ) {
		$value = trim( (string) $value );
		if ( '' === $value ) return '';
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) ) {
			return new WP_Error( 'mad4b_content_experience_media_usage_date_invalid', 'Media rights expiry must use YYYY-MM-DD.' );
		}
		$parts = array_map( 'intval', explode( '-', $value ) );
		if ( 3 !== count( $parts ) || ! checkdate( $parts[1], $parts[2], $parts[0] ) ) {
			return new WP_Error( 'mad4b_content_experience_media_usage_date_invalid', 'Media rights expiry is not a valid calendar date.' );
		}
		return $value;
	}

	private static function normalize_usage_item( $key, $item, array $spec, $image_only ) {
		if ( ! is_array( $item ) || array_values( $item ) === $item ) {
			return new WP_Error( 'mad4b_content_experience_media_usage_item_invalid', 'Media usage items must be keyed objects.', array( 'key' => $key ) );
		}
		$allowed = array_merge( array( 'attachment_id' ), (array) $spec['usage_fields'] );
		foreach ( array_keys( $item ) as $field ) {
			if ( ! in_array( (string) $field, $allowed, true ) ) {
				return new WP_Error( 'mad4b_content_experience_media_usage_property_denied', 'Media usage item contains a contextual property not enabled by the profile.', array( 'key' => $key, 'field' => (string) $field ) );
			}
		}
		$id = isset( $item['attachment_id'] ) ? absint( $item['attachment_id'] ) : 0;
		if ( $id < 1 || 'attachment' !== get_post_type( $id ) ) {
			return new WP_Error( 'mad4b_content_experience_media_attachment_missing', 'Media usage item references a missing or non-attachment object.', array( 'key' => $key, 'attachment_id' => $id ) );
		}
		if ( $image_only && ! wp_attachment_is_image( $id ) ) {
			return new WP_Error( 'mad4b_content_experience_media_image_required', 'Image usage item references a non-image attachment.', array( 'key' => $key, 'attachment_id' => $id ) );
		}
		if ( ! current_user_can( 'read_post', $id ) ) {
			return new WP_Error( 'mad4b_content_experience_media_read_denied', 'Current user cannot read one referenced media attachment.', array( 'key' => $key, 'attachment_id' => $id ) );
		}
		$out = array( 'attachment_id' => $id );
		foreach ( (array) $spec['usage_fields'] as $field ) {
			if ( ! array_key_exists( $field, $item ) ) continue;
			switch ( $field ) {
				case 'role':
					$role = sanitize_key( (string) $item[ $field ] );
					if ( '' === $role || ! in_array( $role, (array) $spec['roles'], true ) ) {
						return new WP_Error( 'mad4b_content_experience_media_usage_role_denied', 'Media usage role is outside the profile role allowlist.', array( 'key' => $key, 'role' => $role ) );
					}
					$out[ $field ] = $role;
					break;
				case 'caption_override':
					$value = sanitize_textarea_field( (string) $item[ $field ] );
					if ( strlen( $value ) > self::MAX_USAGE_TEXT_BYTES ) return new WP_Error( 'mad4b_content_experience_media_usage_text_too_large', 'Media usage caption exceeds the bounded size.', array( 'key' => $key ) );
					$out[ $field ] = $value;
					break;
				case 'description_override':
					$value = wp_kses_post( (string) $item[ $field ] );
					if ( strlen( $value ) > self::MAX_USAGE_TEXT_BYTES ) return new WP_Error( 'mad4b_content_experience_media_usage_text_too_large', 'Media usage description exceeds the bounded size.', array( 'key' => $key ) );
					$out[ $field ] = $value;
					break;
				case 'source_url':
				case 'link_url':
					$value = self::normalize_url( $item[ $field ] );
					if ( is_wp_error( $value ) ) return $value;
					$out[ $field ] = $value;
					break;
				case 'license':
					$value = sanitize_key( (string) $item[ $field ] );
					if ( '' === $value || ! in_array( $value, (array) $spec['licenses'], true ) ) return new WP_Error( 'mad4b_content_experience_media_usage_license_denied', 'Media usage license is outside the profile license allowlist.', array( 'key' => $key, 'license' => $value ) );
					$out[ $field ] = $value;
					break;
				case 'license_expires_on':
					$value = self::normalize_iso_date( $item[ $field ] );
					if ( is_wp_error( $value ) ) return $value;
					$out[ $field ] = $value;
					break;
				case 'link_target':
					$value = (string) $item[ $field ];
					if ( ! in_array( $value, array( '_self', '_blank' ), true ) ) return new WP_Error( 'mad4b_content_experience_media_usage_link_target_invalid', 'Media usage link_target must be _self or _blank.', array( 'key' => $key ) );
					$out[ $field ] = $value;
					break;
				case 'decorative':
					if ( ! is_bool( $item[ $field ] ) ) return new WP_Error( 'mad4b_content_experience_media_usage_decorative_invalid', 'Media usage decorative must be a boolean.', array( 'key' => $key ) );
					$out[ $field ] = (bool) $item[ $field ];
					break;
				case 'focal_point':
					$point = $item[ $field ];
					if ( ! is_array( $point ) || ! isset( $point['x'], $point['y'] ) || ! is_numeric( $point['x'] ) || ! is_numeric( $point['y'] ) ) {
						return new WP_Error( 'mad4b_content_experience_media_usage_focal_point_invalid', 'Media usage focal_point requires numeric x/y values.', array( 'key' => $key ) );
					}
					$x = (float) $point['x']; $y = (float) $point['y'];
					if ( ! is_finite( $x ) || ! is_finite( $y ) || $x < 0 || $x > 1 || $y < 0 || $y > 1 ) return new WP_Error( 'mad4b_content_experience_media_usage_focal_point_invalid', 'Media usage focal_point coordinates must be finite and between 0 and 1.', array( 'key' => $key ) );
					$out[ $field ] = array( 'x' => $x, 'y' => $y );
					break;
				default:
					$value = sanitize_text_field( (string) $item[ $field ] );
					if ( strlen( $value ) > 2048 ) return new WP_Error( 'mad4b_content_experience_media_usage_text_too_large', 'Media usage text exceeds the bounded size.', array( 'key' => $key, 'field' => $field ) );
					$out[ $field ] = $value;
					break;
			}
		}
		if ( ! empty( $out['decorative'] ) ) {
			if ( isset( $out['alt_override'] ) && '' !== trim( (string) $out['alt_override'] ) ) {
				return new WP_Error( 'mad4b_content_experience_media_usage_decorative_alt_conflict', 'Decorative media must not carry a non-empty contextual ALT.', array( 'key' => $key ) );
			}
			if ( isset( $out['aria_label'] ) && '' !== trim( (string) $out['aria_label'] ) ) {
				return new WP_Error( 'mad4b_content_experience_media_usage_decorative_aria_conflict', 'Decorative media must not carry an ARIA label.', array( 'key' => $key ) );
			}
		}
		if ( isset( $out['link_target'] ) && empty( $out['link_url'] ) ) {
			return new WP_Error( 'mad4b_content_experience_media_usage_link_target_without_url', 'Media usage link_target requires link_url in the same item.', array( 'key' => $key ) );
		}
		return $out;
	}

	public static function normalize_meta_value( $key, $value, array $spec ) {
		$key = (string) $key;
		$kind = isset( $spec['kind'] ) ? (string) $spec['kind'] : '';
		$storage = isset( $spec['storage'] ) ? (string) $spec['storage'] : '';
		$is_usage = false !== strpos( $kind, '_usage' );
		$is_gallery = false !== strpos( $kind, '_gallery' );
		$image_only = 0 === strpos( $kind, 'image_' ) || 'image_id' === $kind;
		$max_items = isset( $spec['max_items'] )
			? max( 1, min( MAD4B_SCP_Content_Experience_Profiles::MAX_MEDIA_GALLERY_ITEMS, absint( $spec['max_items'] ) ) )
			: 1;

		if ( $is_usage ) {
			if ( ! is_array( $value ) || array_values( $value ) !== $value ) {
				return new WP_Error( 'mad4b_content_experience_media_usage_shape_invalid', 'Media usage metadata must be an ordered list of contextual media items.', array( 'key' => $key ) );
			}
			if ( count( $value ) > $max_items ) return new WP_Error( 'mad4b_content_experience_media_gallery_limit', 'Media usage list exceeds the configured item limit.', array( 'key' => $key, 'max_items' => $max_items ) );
			$out = array();
			foreach ( $value as $item ) {
				$normalized = self::normalize_usage_item( $key, $item, $spec, $image_only );
				if ( is_wp_error( $normalized ) ) return $normalized;
				$out[] = $normalized;
			}
			$ids = array_column( $out, 'attachment_id' );
			if ( count( array_unique( $ids ) ) !== count( $ids ) ) return new WP_Error( 'mad4b_content_experience_media_gallery_duplicate', 'Media usage list contains duplicate attachment IDs.', array( 'key' => $key ) );
			return $out;
		}

		$ids = array();
		if ( $is_gallery ) {
			if ( ! is_array( $value ) || array_values( $value ) !== $value ) return new WP_Error( 'mad4b_content_experience_media_gallery_shape_invalid', 'Media gallery metadata must be a list of attachment IDs.', array( 'key' => $key ) );
			if ( count( $value ) > $max_items ) return new WP_Error( 'mad4b_content_experience_media_gallery_limit', 'Media gallery exceeds the configured item limit.', array( 'key' => $key, 'max_items' => $max_items ) );
			foreach ( $value as $candidate ) {
				if ( ! is_int( $candidate ) && ! ( is_string( $candidate ) && ctype_digit( $candidate ) ) ) return new WP_Error( 'mad4b_content_experience_media_id_invalid', 'Media gallery contains a non-integer attachment ID.', array( 'key' => $key ) );
				$id = absint( $candidate );
				if ( $id < 1 ) return new WP_Error( 'mad4b_content_experience_media_id_invalid', 'Media gallery attachment IDs must be positive.', array( 'key' => $key ) );
				$ids[] = $id;
			}
			if ( count( array_unique( $ids ) ) !== count( $ids ) ) return new WP_Error( 'mad4b_content_experience_media_gallery_duplicate', 'Media gallery contains duplicate attachment IDs.', array( 'key' => $key ) );
		} else {
			if ( null === $value || '' === $value || 0 === $value || '0' === $value ) return 0;
			if ( ! is_int( $value ) && ! ( is_string( $value ) && ctype_digit( $value ) ) ) return new WP_Error( 'mad4b_content_experience_media_id_invalid', 'Media metadata must reference one attachment ID.', array( 'key' => $key ) );
			$id = absint( $value );
			if ( $id < 1 ) return new WP_Error( 'mad4b_content_experience_media_id_invalid', 'Media attachment ID must be positive.', array( 'key' => $key ) );
			$ids[] = $id;
		}
		foreach ( $ids as $id ) {
			if ( 'attachment' !== get_post_type( $id ) ) return new WP_Error( 'mad4b_content_experience_media_attachment_missing', 'Media metadata references a missing or non-attachment object.', array( 'key' => $key, 'attachment_id' => $id ) );
			if ( $image_only && ! wp_attachment_is_image( $id ) ) return new WP_Error( 'mad4b_content_experience_media_image_required', 'Image metadata field references a non-image attachment.', array( 'key' => $key, 'attachment_id' => $id ) );
			if ( ! current_user_can( 'read_post', $id ) ) return new WP_Error( 'mad4b_content_experience_media_read_denied', 'Current user cannot read one referenced media attachment.', array( 'key' => $key, 'attachment_id' => $id ) );
		}
		if ( ! $is_gallery ) return isset( $ids[0] ) ? (int) $ids[0] : 0;
		return 'csv_ids' === $storage ? implode( ',', $ids ) : $ids;
	}

	private static function reference_ids( $value ) {
		if ( is_array( $value ) ) return array_values( array_map( 'absint', $value ) );
		if ( is_string( $value ) && '' !== $value ) return array_values( array_filter( array_map( 'absint', explode( ',', $value ) ) ) );
		if ( is_numeric( $value ) && (int) $value > 0 ) return array( (int) $value );
		return array();
	}

	private static function verification_projection( $value, array $spec ) {
		$result = array(
			'storage' => isset( $spec['storage'] ) ? (string) $spec['storage'] : '',
			'max_items' => isset( $spec['max_items'] ) ? (int) $spec['max_items'] : 1,
		);
		$is_usage = false !== strpos( isset( $spec['kind'] ) ? (string) $spec['kind'] : '', '_usage' );
		if ( ! $is_usage ) {
			$result['attachment_ids'] = self::reference_ids( $value );
			return $result;
		}
		$items = is_array( $value ) ? $value : array();
		$result['attachment_ids'] = array_values( array_map( 'absint', array_column( $items, 'attachment_id' ) ) );
		$result['roles'] = array_values( array_filter( array_map( static function ( $item ) { return isset( $item['role'] ) ? (string) $item['role'] : ''; }, $items ) ) );
		$result['licenses'] = array_values( array_filter( array_map( static function ( $item ) { return isset( $item['license'] ) ? (string) $item['license'] : ''; }, $items ) ) );
		$result['license_expiries'] = array_values( array_filter( array_map( static function ( $item ) { return isset( $item['license_expires_on'] ) ? (string) $item['license_expires_on'] : ''; }, $items ) ) );
		$result['decorative_count'] = count( array_filter( $items, static function ( $item ) { return ! empty( $item['decorative'] ); } ) );
		$result['linked_count'] = count( array_filter( $items, static function ( $item ) { return ! empty( $item['link_url'] ); } ) );
		$result['focal_point_count'] = count( array_filter( $items, static function ( $item ) { return isset( $item['focal_point'] ) && is_array( $item['focal_point'] ); } ) );
		foreach ( array( 'alt_override', 'caption_override', 'title_override', 'description_override', 'aria_label', 'credit', 'copyright', 'source_url' ) as $field ) {
			$result[ $field . '_count' ] = count( array_filter( $items, static function ( $item ) use ( $field ) { return array_key_exists( $field, $item ) && '' !== trim( (string) $item[ $field ] ); } ) );
		}
		return $result;
	}

	public static function validate_usage_bindings( array $field_specs, array $normalized_meta ) {
		foreach ( $field_specs as $key => $spec ) {
			if ( false === strpos( (string) $spec['kind'], '_usage' ) || ! array_key_exists( $key, $normalized_meta ) ) continue;
			$reference = (string) $spec['references_field'];
			if ( ! array_key_exists( $reference, $normalized_meta ) ) {
				return new WP_Error(
					'mad4b_content_experience_media_usage_reference_required',
					'Media usage mutation must include its referenced gallery field in the same atomic meta payload.',
					array( 'key' => $key, 'references_field' => $reference )
				);
			}
			$expected = self::reference_ids( $normalized_meta[ $reference ] );
			$actual = array_values( array_map( 'absint', array_column( (array) $normalized_meta[ $key ], 'attachment_id' ) ) );
			if ( $expected !== $actual ) {
				return new WP_Error(
					'mad4b_content_experience_media_usage_reference_drift',
					'Media usage attachment IDs/order do not exactly match the referenced gallery.',
					array( 'key' => $key, 'references_field' => $reference )
				);
			}
		}
		return true;
	}

	public static function effective_meta_state( $post_id, array $field_specs, array $overrides = array() ) {
		$post_id = absint( $post_id );
		$effective = array();
		foreach ( $field_specs as $key => $spec ) {
			$key = (string) $key;
			if ( array_key_exists( $key, $overrides ) ) {
				$effective[ $key ] = $overrides[ $key ];
				continue;
			}
			if ( $post_id < 1 || ! metadata_exists( 'post', $post_id, $key ) ) continue;
			$stored = get_post_meta( $post_id, $key, true );
			$input = $stored;
			if ( 'csv_ids' === ( isset( $spec['storage'] ) ? (string) $spec['storage'] : '' ) ) {
				$input = '' === (string) $stored ? array() : array_values( array_filter( array_map( 'absint', explode( ',', (string) $stored ) ) ) );
			}
			$value = self::normalize_meta_value( $key, $input, $spec );
			if ( is_wp_error( $value ) ) {
				return new WP_Error(
					'mad4b_content_experience_media_effective_state_invalid',
					'Stored media metadata cannot participate in a governed content plan.',
					array( 'key' => $key, 'cause' => $value->get_error_code() )
				);
			}
			$effective[ $key ] = $value;
		}
		$usage_guard = self::validate_usage_bindings( $field_specs, $effective );
		if ( is_wp_error( $usage_guard ) ) return $usage_guard;
		ksort( $effective, SORT_STRING );
		return $effective;
	}

	public static function effective_state_sha256( array $effective ) {
		ksort( $effective, SORT_STRING );
		$encoded = wp_json_encode( $effective, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		return hash( 'sha256', false === $encoded ? '' : $encoded );
	}

	public static function verify_post_meta( $post_id, array $field_specs ) {
		$post_id = absint( $post_id );
		if ( $post_id < 1 || ! get_post( $post_id ) ) return new WP_Error( 'mad4b_content_experience_media_verify_post_missing', 'Media metadata verification target is missing.' );
		$normalized = array();
		$summary = array();
		foreach ( $field_specs as $key => $spec ) {
			$key = (string) $key;
			$exists = metadata_exists( 'post', $post_id, $key );
			if ( ! $exists ) {
				$summary[ $key ] = array_merge(
					array( 'exists' => false, 'kind' => (string) $spec['kind'], 'count' => 0, 'value_sha256' => hash( 'sha256', 'null' ) ),
					self::verification_projection( array(), $spec )
				);
				continue;
			}
			$stored = get_post_meta( $post_id, $key, true );
			$input = $stored;
			if ( 'csv_ids' === ( isset( $spec['storage'] ) ? (string) $spec['storage'] : '' ) ) {
				$input = '' === (string) $stored ? array() : array_values( array_filter( array_map( 'absint', explode( ',', (string) $stored ) ) ) );
			}
			$value = self::normalize_meta_value( $key, $input, $spec );
			if ( is_wp_error( $value ) ) return new WP_Error(
				'mad4b_content_experience_media_verify_invalid',
				'Stored media metadata no longer satisfies the profile contract.',
				array( 'key' => $key, 'cause' => $value->get_error_code() )
			);
			if ( $value !== $stored ) return new WP_Error(
				'mad4b_content_experience_media_verify_normalization_drift',
				'Stored media metadata is not in the canonical profile representation.',
				array( 'key' => $key )
			);
			$normalized[ $key ] = $value;
			$count = 0;
			if ( is_array( $value ) ) $count = count( $value );
			elseif ( 'csv_ids' === (string) $spec['storage'] && '' !== (string) $value ) $count = count( explode( ',', (string) $value ) );
			elseif ( is_numeric( $value ) && (int) $value > 0 ) $count = 1;
			$encoded = wp_json_encode( $value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
			$summary[ $key ] = array_merge(
				array(
					'exists' => true,
					'kind' => (string) $spec['kind'],
					'count' => $count,
					'value_sha256' => hash( 'sha256', false === $encoded ? '' : $encoded ),
				),
				self::verification_projection( $value, $spec )
			);
		}
		$usage_guard = self::validate_usage_bindings( $field_specs, $normalized );
		if ( is_wp_error( $usage_guard ) ) return new WP_Error(
			'mad4b_content_experience_media_verify_usage_drift',
			'Stored media usage metadata is no longer bound to its referenced gallery.',
			array( 'cause' => $usage_guard->get_error_code() )
		);
		ksort( $summary, SORT_STRING );
		$encoded = wp_json_encode( $summary, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		return array(
			'contract' => 'mad4b.content-experience-media-state.v1',
			'field_count' => count( $summary ),
			'fields' => $summary,
			'media_state_sha256' => hash( 'sha256', false === $encoded ? '' : $encoded ),
			'valid' => true,
		);
	}

	public static function value_within_budget( $value ) {
		$encoded = wp_json_encode( $value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		return false !== $encoded && strlen( $encoded ) <= self::MAX_META_VALUE_BYTES;
	}
}
