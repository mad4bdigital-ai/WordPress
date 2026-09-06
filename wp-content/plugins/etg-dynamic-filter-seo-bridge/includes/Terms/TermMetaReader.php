<?php
namespace ETG\DynamicFilterSEOBridge\Terms;

use WP_Term;

final class TermMetaReader {
	private const FIELD_MAP = array(
		'seo_title' => array( 'rank_math_title', 'seo_title', '_title' ),
		'meta_description' => array( 'rank_math_description', 'seo_description', '_description' ),
		'focus_keyword' => array( 'rank_math_focus_keyword', 'focus_keyword' ),
		'short_description' => array( 'short_description', 'short_desc', 'intro' ),
		'image' => array( 'thumbnail_id', 'image', 'hero_image' ),
		'gallery' => array( 'gallery', 'hero_gallery', 'term_gallery' ),
		'location_level' => array( 'location_level', 'level' ),
	);

	public function read( WP_Term $term, array $profileFieldMap = array() ): array {
		$map = $this->mergedFieldMap( $profileFieldMap, $term );
		$data = array(
			'term_id' => (int) $term->term_id,
			'taxonomy' => (string) $term->taxonomy,
			'name' => (string) $term->name,
			'slug' => (string) $term->slug,
			'description' => (string) $term->description,
			'count' => isset( $term->count ) ? (int) $term->count : 0,
			'seo_title' => '', 'meta_description' => '', 'focus_keyword' => '', 'short_description' => '',
			'image_id' => 0, 'gallery_ids' => array(), 'location_level' => '', 'parent_chain' => array(),
		);

		foreach ( array( 'seo_title', 'meta_description', 'focus_keyword', 'short_description', 'location_level' ) as $key ) {
			$data[ $key ] = $this->firstValue( $term, isset( $map[ $key ] ) ? (array) $map[ $key ] : array() );
		}

		$imageIds = $this->normalizeAttachmentIds( $this->firstRawValue( $term, isset( $map['image'] ) ? (array) $map['image'] : array() ) );
		if ( $imageIds ) { $data['image_id'] = (int) reset( $imageIds ); }
		$galleryValues = $this->allRawValues( $term, isset( $map['gallery'] ) ? (array) $map['gallery'] : array() );
		$galleryIds = array();
		foreach ( $galleryValues as $galleryValue ) { $galleryIds = array_merge( $galleryIds, $this->normalizeAttachmentIds( $galleryValue ) ); }
		$data['gallery_ids'] = array_values( array_unique( array_filter( array_map( 'absint', $galleryIds ) ) ) );
		if ( $data['image_id'] && ! in_array( $data['image_id'], $data['gallery_ids'], true ) ) { array_unshift( $data['gallery_ids'], $data['image_id'] ); }
		$data['parent_chain'] = $this->parentChain( $term );
		return $data;
	}

	private function mergedFieldMap( array $profileFieldMap, WP_Term $term ): array {
		$map = self::FIELD_MAP;
		foreach ( $profileFieldMap as $canonical => $fields ) {
			$canonical = sanitize_key( (string) $canonical );
			if ( ! array_key_exists( $canonical, self::FIELD_MAP ) ) { continue; }
			$custom = array_values( array_filter( array_map( 'sanitize_key', (array) $fields ) ) );
			if ( $custom ) { $map[ $canonical ] = array_values( array_unique( array_merge( $custom, (array) $map[ $canonical ] ) ) ); }
		}
		if ( function_exists( 'apply_filters' ) ) { $map = (array) apply_filters( 'etg_filter_seo_term_field_map', $map, $term, $profileFieldMap ); }
		return $map;
	}

	private function firstValue( WP_Term $term, array $fields ): string {
		$value = $this->firstRawValue( $term, $fields );
		return is_scalar( $value ) ? trim( (string) $value ) : '';
	}

	private function firstRawValue( WP_Term $term, array $fields ) {
		$values = $this->allRawValues( $term, $fields, true );
		return $values ? reset( $values ) : '';
	}

	private function allRawValues( WP_Term $term, array $fields, bool $stopAtFirst = false ): array {
		$out = array();
		foreach ( $fields as $field ) {
			$field = sanitize_key( (string) $field );
			if ( '' === $field ) { continue; }
			$value = get_term_meta( $term->term_id, $field, true );
			if ( ! $this->isEmpty( $value ) ) { $out[] = $value; if ( $stopAtFirst ) { break; } continue; }
			if ( function_exists( 'get_field' ) ) {
				$value = get_field( $field, $term->taxonomy . '_' . $term->term_id );
				if ( ! $this->isEmpty( $value ) ) { $out[] = $value; if ( $stopAtFirst ) { break; } }
			}
		}
		return $out;
	}

	private function isEmpty( $value ): bool { return null === $value || false === $value || '' === $value || array() === $value; }

	private function normalizeAttachmentIds( $value ): array {
		$ids = array(); $this->collectAttachmentIds( $value, $ids );
		return array_values( array_unique( array_filter( array_map( 'absint', $ids ) ) ) );
	}

	private function collectAttachmentIds( $value, array &$ids ): void {
		if ( is_numeric( $value ) ) { $ids[] = (int) $value; return; }
		if ( is_object( $value ) && isset( $value->ID ) && is_numeric( $value->ID ) ) { $ids[] = (int) $value->ID; return; }
		if ( is_array( $value ) ) {
			foreach ( array( 'ID', 'id', 'attachment_id' ) as $key ) { if ( isset( $value[ $key ] ) && is_numeric( $value[ $key ] ) ) { $ids[] = (int) $value[ $key ]; return; } }
			foreach ( $value as $item ) { $this->collectAttachmentIds( $item, $ids ); }
			return;
		}
		if ( ! is_string( $value ) ) { return; }
		$value = trim( $value ); if ( '' === $value ) { return; }
		$decoded = json_decode( $value, true );
		if ( JSON_ERROR_NONE === json_last_error() && is_array( $decoded ) ) { $this->collectAttachmentIds( $decoded, $ids ); return; }
		if ( false !== strpos( $value, ',' ) ) { foreach ( explode( ',', $value ) as $item ) { $this->collectAttachmentIds( trim( $item ), $ids ); } return; }
		if ( filter_var( $value, FILTER_VALIDATE_URL ) && function_exists( 'attachment_url_to_postid' ) ) { $id = attachment_url_to_postid( $value ); if ( $id ) { $ids[] = (int) $id; } }
	}

	private function parentChain( WP_Term $term ): array {
		if ( empty( $term->parent ) ) { return array(); }
		$chain = array();
		foreach ( array_reverse( get_ancestors( $term->term_id, $term->taxonomy, 'taxonomy' ) ) as $id ) {
			$ancestor = get_term( (int) $id, $term->taxonomy );
			if ( $ancestor instanceof WP_Term ) { $chain[] = array( 'term_id'=>(int)$ancestor->term_id, 'name'=>(string)$ancestor->name, 'slug'=>(string)$ancestor->slug ); }
		}
		return $chain;
	}
}
