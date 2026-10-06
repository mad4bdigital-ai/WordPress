<?php

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Canonical Media Library reference projections for Content Experience meta.
 *
 * Logical mutation input remains attachment IDs. This service projects those
 * IDs into provider-compatible stored shapes (ID, URL, ID+URL object, ordered
 * gallery forms) and can recover the attachment identity from stored values.
 */
final class MAD4B_SCP_Content_Experience_Media_Storage {
	const CONTRACT = 'mad4b.content-experience-media-storage.v1';

	public static function validate_storage( $kind, $storage ) {
		$kind = sanitize_key( (string) $kind );
		$storage = sanitize_key( (string) $storage );
		$is_gallery = false !== strpos( $kind, '_gallery' );
		$allowed = $is_gallery
			? array( 'ids', 'csv_ids', 'urls', 'csv_urls', 'id_url_items', 'json_id_url_items' )
			: array( 'id', 'url', 'id_url', 'json_id_url' );
		return in_array( $storage, $allowed, true )
			? true
			: new WP_Error( 'mad4b_content_experience_media_meta_storage_invalid', 'Media metadata field storage is unsupported for the configured field kind.', array( 'kind' => $kind, 'storage' => $storage ) );
	}

	public static function infer_spec( $value ) {
		if ( is_numeric( $value ) ) {
			$ids = self::single_id( $value );
			if ( ! is_wp_error( $ids ) && 'attachment' === get_post_type( $ids[0] ) && wp_attachment_is_image( $ids[0] ) ) {
				return array( 'supported' => true, 'schema_type' => 'integer', 'spec' => array( 'kind' => 'image_id', 'storage' => 'id', 'max_items' => 1 ) );
			}
		}
		if ( is_string( $value ) ) {
			$trimmed = trim( $value );
			if ( '' === $trimmed ) return array( 'supported' => false );
			if ( preg_match( '/^\s*\d+(?:\s*,\s*\d+)+\s*$/', $trimmed ) ) {
				$ids = self::id_list( preg_split( '/\s*,\s*/', $trimmed ) );
				if ( ! is_wp_error( $ids ) && self::all_image_attachments( $ids ) ) return array( 'supported' => true, 'schema_type' => 'string', 'spec' => array( 'kind' => 'image_gallery', 'storage' => 'csv_ids', 'max_items' => min( MAD4B_SCP_Content_Experience_Profiles::MAX_MEDIA_GALLERY_ITEMS, max( 1, count( $ids ) ) ) ) );
			}
			if ( 0 === strpos( $trimmed, '{' ) || 0 === strpos( $trimmed, '[' ) ) {
				$decoded = json_decode( $trimmed, true );
				if ( is_array( $decoded ) ) {
					$single = self::id_url_object_ids( $decoded, false );
					if ( ! is_wp_error( $single ) && self::all_image_attachments( $single ) ) return array( 'supported' => true, 'schema_type' => 'string', 'spec' => array( 'kind' => 'image_id', 'storage' => 'json_id_url', 'max_items' => 1 ) );
					$many = self::id_url_object_ids( $decoded, true );
					if ( ! is_wp_error( $many ) && $many && self::all_image_attachments( $many ) ) return array( 'supported' => true, 'schema_type' => 'string', 'spec' => array( 'kind' => 'image_gallery', 'storage' => 'json_id_url_items', 'max_items' => min( MAD4B_SCP_Content_Experience_Profiles::MAX_MEDIA_GALLERY_ITEMS, count( $many ) ) ) );
				}
			}
			if ( preg_match( '#^https?://#i', $trimmed ) ) {
				if ( false !== strpos( $trimmed, ',' ) ) {
					$urls = preg_split( '/\s*,\s*/', $trimmed );
					$ids = self::url_list_ids( $urls );
					if ( ! is_wp_error( $ids ) && $ids && self::all_image_attachments( $ids ) ) return array( 'supported' => true, 'schema_type' => 'string', 'spec' => array( 'kind' => 'image_gallery', 'storage' => 'csv_urls', 'max_items' => min( MAD4B_SCP_Content_Experience_Profiles::MAX_MEDIA_GALLERY_ITEMS, count( $ids ) ) ) );
				}
				$id = self::attachment_id_from_url( $trimmed );
				if ( $id > 0 && wp_attachment_is_image( $id ) ) return array( 'supported' => true, 'schema_type' => 'string', 'spec' => array( 'kind' => 'image_id', 'storage' => 'url', 'max_items' => 1 ) );
			}
		}
		if ( is_array( $value ) ) {
			if ( array_values( $value ) === $value ) {
				if ( self::looks_like_id_list( $value ) ) {
					$ids = self::id_list( $value );
					if ( ! is_wp_error( $ids ) && $ids && self::all_image_attachments( $ids ) ) return array( 'supported' => true, 'schema_type' => 'array', 'spec' => array( 'kind' => 'image_gallery', 'storage' => 'ids', 'max_items' => min( MAD4B_SCP_Content_Experience_Profiles::MAX_MEDIA_GALLERY_ITEMS, count( $ids ) ) ) );
				}
				$urls = self::url_list_ids( $value );
				if ( ! is_wp_error( $urls ) && $urls && self::all_image_attachments( $urls ) ) return array( 'supported' => true, 'schema_type' => 'array', 'spec' => array( 'kind' => 'image_gallery', 'storage' => 'urls', 'max_items' => min( MAD4B_SCP_Content_Experience_Profiles::MAX_MEDIA_GALLERY_ITEMS, count( $urls ) ) ) );
				$both = self::id_url_object_ids( $value, true );
				if ( ! is_wp_error( $both ) && $both && self::all_image_attachments( $both ) ) return array( 'supported' => true, 'schema_type' => 'array', 'spec' => array( 'kind' => 'image_gallery', 'storage' => 'id_url_items', 'max_items' => min( MAD4B_SCP_Content_Experience_Profiles::MAX_MEDIA_GALLERY_ITEMS, count( $both ) ) ) );
			} else {
				$both = self::id_url_object_ids( $value, false );
				if ( ! is_wp_error( $both ) && self::all_image_attachments( $both ) ) return array( 'supported' => true, 'schema_type' => 'array', 'spec' => array( 'kind' => 'image_id', 'storage' => 'id_url', 'max_items' => 1 ) );
			}
		}
		return array( 'supported' => false );
	}

	public static function normalize_reference_value( $key, $value, array $spec, $image_only ) {
		$key = (string) $key;
		$kind = isset( $spec['kind'] ) ? sanitize_key( (string) $spec['kind'] ) : '';
		$storage = isset( $spec['storage'] ) ? sanitize_key( (string) $spec['storage'] ) : '';
		$is_gallery = false !== strpos( $kind, '_gallery' );
		$max_items = isset( $spec['max_items'] )
			? max( 1, min( MAD4B_SCP_Content_Experience_Profiles::MAX_MEDIA_GALLERY_ITEMS, absint( $spec['max_items'] ) ) )
			: 1;
		$guard = self::validate_storage( $kind, $storage );
		if ( is_wp_error( $guard ) ) return $guard;

		$ids = self::extract_ids( $value, $storage, $is_gallery );
		if ( is_wp_error( $ids ) ) return new WP_Error(
			$ids->get_error_code(),
			$ids->get_error_message(),
			array_merge( array( 'key' => $key ), (array) $ids->get_error_data() )
		);
		if ( $is_gallery && count( $ids ) > $max_items ) {
			return new WP_Error( 'mad4b_content_experience_media_gallery_limit', 'Media gallery exceeds the configured item limit.', array( 'key' => $key, 'max_items' => $max_items ) );
		}
		if ( count( array_unique( $ids ) ) !== count( $ids ) ) {
			return new WP_Error( 'mad4b_content_experience_media_gallery_duplicate', 'Media metadata contains duplicate attachment IDs.', array( 'key' => $key ) );
		}
		foreach ( $ids as $id ) {
			if ( 'attachment' !== get_post_type( $id ) ) return new WP_Error( 'mad4b_content_experience_media_attachment_missing', 'Media metadata references a missing or non-attachment object.', array( 'key' => $key, 'attachment_id' => $id ) );
			if ( $image_only && ! wp_attachment_is_image( $id ) ) return new WP_Error( 'mad4b_content_experience_media_image_required', 'Image metadata field references a non-image attachment.', array( 'key' => $key, 'attachment_id' => $id ) );
			if ( ! current_user_can( 'read_post', $id ) ) return new WP_Error( 'mad4b_content_experience_media_read_denied', 'Current user cannot read one referenced media attachment.', array( 'key' => $key, 'attachment_id' => $id ) );
		}
		return self::project_ids( $ids, $storage, $is_gallery );
	}

	public static function reference_ids( $value, array $spec ) {
		$kind = isset( $spec['kind'] ) ? sanitize_key( (string) $spec['kind'] ) : '';
		$storage = isset( $spec['storage'] ) ? sanitize_key( (string) $spec['storage'] ) : '';
		$is_gallery = false !== strpos( $kind, '_gallery' );
		$ids = self::extract_ids( $value, $storage, $is_gallery );
		return is_wp_error( $ids ) ? array() : array_values( array_map( 'absint', $ids ) );
	}

	private static function extract_ids( $value, $storage, $is_gallery ) {
		if ( $is_gallery ) {
			if ( null === $value || '' === $value || array() === $value ) return array();
		} elseif ( null === $value || '' === $value || 0 === $value || '0' === $value || array() === $value ) {
			return array();
		}

		switch ( $storage ) {
			case 'id':
				return self::single_id( $value );
			case 'ids':
				return self::id_list( $value );
			case 'csv_ids':
				if ( is_array( $value ) ) return self::id_list( $value );
				if ( ! is_string( $value ) ) return self::shape_error( 'Media gallery ID storage expects an ordered ID list or canonical CSV string.' );
				return self::id_list( '' === trim( $value ) ? array() : preg_split( '/\s*,\s*/', trim( $value ) ) );
			case 'url':
				if ( is_numeric( $value ) ) return self::single_id( $value );
				return self::single_url_id( $value );
			case 'urls':
				if ( self::looks_like_id_list( $value ) ) return self::id_list( $value );
				return self::url_list_ids( $value );
			case 'csv_urls':
				if ( self::looks_like_id_list( $value ) ) return self::id_list( $value );
				if ( ! is_string( $value ) ) return self::shape_error( 'Media gallery URL storage expects an ordered URL list or canonical CSV string.' );
				return self::url_list_ids( '' === trim( $value ) ? array() : preg_split( '/\s*,\s*/', trim( $value ) ) );
			case 'id_url':
				if ( is_numeric( $value ) ) return self::single_id( $value );
				return self::id_url_object_ids( $value, false );
			case 'id_url_items':
				if ( self::looks_like_id_list( $value ) ) return self::id_list( $value );
				return self::id_url_object_ids( $value, true );
			case 'json_id_url':
				if ( is_numeric( $value ) ) return self::single_id( $value );
				$decoded = self::decode_json( $value );
				if ( is_wp_error( $decoded ) ) return $decoded;
				return self::id_url_object_ids( $decoded, false );
			case 'json_id_url_items':
				if ( self::looks_like_id_list( $value ) ) return self::id_list( $value );
				$decoded = self::decode_json( $value );
				if ( is_wp_error( $decoded ) ) return $decoded;
				return self::id_url_object_ids( $decoded, true );
		}
		return self::shape_error( 'Unsupported media storage projection.' );
	}

	private static function project_ids( array $ids, $storage, $is_gallery ) {
		if ( ! $is_gallery && empty( $ids ) ) {
			if ( 'id' === $storage ) return 0;
			if ( 'id_url' === $storage ) return array();
			return '';
		}
		if ( $is_gallery && empty( $ids ) ) {
			return in_array( $storage, array( 'csv_ids', 'csv_urls' ), true ) ? '' : ( 'json_id_url_items' === $storage ? '[]' : array() );
		}

		$urls = array();
		if ( in_array( $storage, array( 'url', 'urls', 'csv_urls', 'id_url', 'id_url_items', 'json_id_url', 'json_id_url_items' ), true ) ) {
			foreach ( $ids as $id ) {
				$url = wp_get_attachment_url( $id );
				if ( ! is_string( $url ) || '' === $url ) return new WP_Error( 'mad4b_content_experience_media_attachment_url_missing', 'Media Library attachment has no canonical URL.', array( 'attachment_id' => $id ) );
				$urls[] = esc_url_raw( $url );
			}
		}
		switch ( $storage ) {
			case 'id': return (int) $ids[0];
			case 'ids': return array_values( array_map( 'intval', $ids ) );
			case 'csv_ids': return implode( ',', array_map( 'intval', $ids ) );
			case 'url': return (string) $urls[0];
			case 'urls': return array_values( $urls );
			case 'csv_urls': return implode( ',', $urls );
			case 'id_url': return array( 'id' => (int) $ids[0], 'url' => (string) $urls[0] );
			case 'id_url_items':
				$out = array();
				foreach ( $ids as $index => $id ) $out[] = array( 'id' => (int) $id, 'url' => (string) $urls[ $index ] );
				return $out;
			case 'json_id_url':
				return wp_json_encode( array( 'id' => (int) $ids[0], 'url' => (string) $urls[0] ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
			case 'json_id_url_items':
				$out = array();
				foreach ( $ids as $index => $id ) $out[] = array( 'id' => (int) $id, 'url' => (string) $urls[ $index ] );
				return wp_json_encode( $out, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		}
		return new WP_Error( 'mad4b_content_experience_media_meta_storage_invalid', 'Unsupported media storage projection.' );
	}

	private static function single_id( $value ) {
		if ( ! is_int( $value ) && ! ( is_string( $value ) && ctype_digit( $value ) ) ) return self::shape_error( 'Media metadata must reference one attachment ID.' );
		$id = absint( $value );
		return $id > 0 ? array( $id ) : self::shape_error( 'Media attachment ID must be positive.' );
	}

	private static function id_list( $value ) {
		if ( ! is_array( $value ) || array_values( $value ) !== $value ) return self::shape_error( 'Media gallery metadata must be an ordered attachment-ID list.' );
		$ids = array();
		foreach ( $value as $candidate ) {
			if ( ! is_int( $candidate ) && ! ( is_string( $candidate ) && ctype_digit( $candidate ) ) ) return self::shape_error( 'Media gallery contains a non-integer attachment ID.' );
			$id = absint( $candidate );
			if ( $id < 1 ) return self::shape_error( 'Media gallery attachment IDs must be positive.' );
			$ids[] = $id;
		}
		return $ids;
	}

	private static function looks_like_id_list( $value ) {
		if ( ! is_array( $value ) || array_values( $value ) !== $value ) return false;
		foreach ( $value as $candidate ) if ( ! is_int( $candidate ) && ! ( is_string( $candidate ) && ctype_digit( $candidate ) ) ) return false;
		return true;
	}

	private static function single_url_id( $value ) {
		if ( ! is_string( $value ) ) return self::shape_error( 'Media URL storage expects one canonical Media Library URL.' );
		$id = self::attachment_id_from_url( $value );
		return $id > 0 ? array( $id ) : self::shape_error( 'Media URL does not resolve to one local Media Library attachment.' );
	}

	private static function url_list_ids( $value ) {
		if ( ! is_array( $value ) || array_values( $value ) !== $value ) return self::shape_error( 'Media gallery URL storage expects an ordered URL list.' );
		$ids = array();
		foreach ( $value as $url ) {
			if ( ! is_string( $url ) ) return self::shape_error( 'Media gallery URL list contains a non-string value.' );
			$id = self::attachment_id_from_url( $url );
			if ( $id < 1 ) return self::shape_error( 'Media gallery URL does not resolve to one local Media Library attachment.' );
			$ids[] = $id;
		}
		return $ids;
	}

	private static function id_url_object_ids( $value, $multiple ) {
		$items = $multiple ? $value : array( $value );
		if ( ! is_array( $items ) || ( $multiple && array_values( $items ) !== $items ) ) return self::shape_error( 'Media ID+URL storage has an invalid shape.' );
		$ids = array();
		foreach ( $items as $item ) {
			if ( ! is_array( $item ) || ! isset( $item['id'], $item['url'] ) ) return self::shape_error( 'Media ID+URL item must contain id and url.' );
			$id = absint( $item['id'] );
			if ( $id < 1 || ! is_string( $item['url'] ) ) return self::shape_error( 'Media ID+URL item is invalid.' );
			$expected = wp_get_attachment_url( $id );
			if ( ! is_string( $expected ) || '' === $expected || ! hash_equals( esc_url_raw( $expected ), esc_url_raw( $item['url'] ) ) ) {
				return self::shape_error( 'Media ID+URL item URL does not match its Media Library attachment identity.' );
			}
			$ids[] = $id;
		}
		return $ids;
	}

	private static function decode_json( $value ) {
		if ( is_array( $value ) ) return $value;
		if ( ! is_string( $value ) || strlen( $value ) > MAD4B_SCP_Content_Experience_Media::MAX_META_VALUE_BYTES ) return self::shape_error( 'Media JSON storage is invalid or oversized.' );
		$decoded = json_decode( $value, true );
		return is_array( $decoded ) ? $decoded : self::shape_error( 'Media JSON storage is not valid JSON.' );
	}

	private static function attachment_id_from_url( $url ) {
		$url = is_string( $url ) ? esc_url_raw( trim( $url ) ) : '';
		if ( '' === $url ) return 0;
		$id = function_exists( 'attachment_url_to_postid' ) ? absint( attachment_url_to_postid( $url ) ) : 0;
		if ( $id > 0 ) return $id;

		$uploads = wp_upload_dir();
		$base_path = isset( $uploads['baseurl'] ) ? wp_parse_url( (string) $uploads['baseurl'], PHP_URL_PATH ) : '';
		$url_path = wp_parse_url( $url, PHP_URL_PATH );
		if ( ! is_string( $base_path ) || ! is_string( $url_path ) || '' === $base_path || '' === $url_path ) return 0;
		$base_path = rtrim( $base_path, '/' ) . '/';
		$position = strpos( $url_path, $base_path );
		if ( false === $position ) return 0;
		$relative = ltrim( substr( $url_path, $position + strlen( $base_path ) ), '/' );
		if ( '' === $relative || false !== strpos( $relative, '..' ) ) return 0;
		$ids = get_posts( array(
			'post_type' => 'attachment', 'post_status' => 'any', 'posts_per_page' => 2, 'fields' => 'ids',
			'meta_key' => '_wp_attached_file', 'meta_value' => $relative,
			'no_found_rows' => true, 'suppress_filters' => true,
		) );
		return 1 === count( (array) $ids ) ? absint( $ids[0] ) : 0;
	}

	private static function all_image_attachments( array $ids ) {
		if ( empty( $ids ) ) return false;
		foreach ( $ids as $id ) if ( 'attachment' !== get_post_type( absint( $id ) ) || ! wp_attachment_is_image( absint( $id ) ) ) return false;
		return true;
	}

	private static function shape_error( $message ) {
		return new WP_Error( 'mad4b_content_experience_media_storage_shape_invalid', (string) $message );
	}
}
