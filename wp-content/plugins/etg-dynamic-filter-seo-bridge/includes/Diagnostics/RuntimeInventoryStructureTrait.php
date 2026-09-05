<?php
namespace ETG\DynamicFilterSEOBridge\Diagnostics;

trait RuntimeInventoryStructureTrait {
	private function postTypes( array $languages ): array {
		$out = array();
		$translationBudget = self::MAX_ARCHIVE_PATH_TRANSLATIONS;
		$translationRequested = 0;
		$translationIncluded = 0;
		if ( ! function_exists( 'get_post_types' ) ) {
			return array(
				'items' => $out,
				'completeness' => $this->completeness( 0, 0, self::MAX_POST_TYPES ),
				'archive_translation_completeness' => $this->completeness( 0, 0, self::MAX_ARCHIVE_PATH_TRANSLATIONS ),
			);
		}
		$objects = get_post_types( array( 'public' => true ), 'objects' );
		$objects = is_array( $objects ) ? $objects : array();
		$normalized = array();
		foreach ( $objects as $name => $object ) {
			$key = sanitize_key( (string) $name );
			if ( '' === $key || ! is_object( $object ) ) { continue; }
			$normalized[ $key ] = $object;
		}
		ksort( $normalized, SORT_STRING );
		$observedCount = count( $normalized );
		foreach ( array_slice( $normalized, 0, self::MAX_POST_TYPES, true ) as $name => $object ) {
			$rewrite = isset( $object->rewrite ) && is_array( $object->rewrite ) ? $object->rewrite : array();
			$archiveUrl = function_exists( 'get_post_type_archive_link' ) ? get_post_type_archive_link( $name ) : false;
			$archivePaths = array();
			if ( is_string( $archiveUrl ) && '' !== $archiveUrl ) {
				$archivePaths['current'] = $this->pathOnly( $archiveUrl );
				foreach ( $languages as $language ) {
					$code = sanitize_key( (string) ( $language['code'] ?? '' ) );
					if ( '' === $code ) { continue; }
					$translationRequested++;
					if ( $translationBudget <= 0 ) { continue; }
					$translated = $archiveUrl;
					if ( function_exists( 'apply_filters' ) ) {
						$candidate = apply_filters( 'wpml_permalink', $archiveUrl, $code, true );
						if ( is_string( $candidate ) && '' !== $candidate ) { $translated = $candidate; }
					}
					$archivePaths[ $code ] = $this->pathOnly( $translated );
					$translationBudget--;
					$translationIncluded++;
				}
			}
			$attached = function_exists( 'get_object_taxonomies' ) ? get_object_taxonomies( $name, 'names' ) : array();
			$attached = array_values( array_unique( array_filter( array_map( 'sanitize_key', is_array( $attached ) ? $attached : array() ) ) ) );
			sort( $attached, SORT_STRING );
			$out[ $name ] = array(
				'label' => isset( $object->label ) ? sanitize_text_field( (string) $object->label ) : $name,
				'publicly_queryable' => ! empty( $object->publicly_queryable ),
				'has_archive' => ! empty( $object->has_archive ),
				'rewrite_slug' => isset( $rewrite['slug'] ) ? sanitize_title( (string) $rewrite['slug'] ) : '',
				'taxonomies' => $attached,
				'archive_paths' => array_filter( $archivePaths, 'strlen' ),
			);
		}
		return array(
			'items' => $out,
			'completeness' => $this->completeness( $observedCount, count( $out ), self::MAX_POST_TYPES ),
			'archive_translation_completeness' => $this->completeness( $translationRequested, $translationIncluded, self::MAX_ARCHIVE_PATH_TRANSLATIONS ),
		);
	}

	private function taxonomies(): array {
		$out = array();
		if ( ! function_exists( 'get_taxonomies' ) ) {
			return array( 'items' => $out, 'completeness' => $this->completeness( 0, 0, self::MAX_TAXONOMIES ) );
		}
		$objects = get_taxonomies( array( 'public' => true ), 'objects' );
		$objects = is_array( $objects ) ? $objects : array();
		$normalized = array();
		foreach ( $objects as $name => $object ) {
			$key = sanitize_key( (string) $name );
			if ( '' === $key || ! is_object( $object ) ) { continue; }
			$normalized[ $key ] = $object;
		}
		ksort( $normalized, SORT_STRING );
		$observedCount = count( $normalized );
		foreach ( array_slice( $normalized, 0, self::MAX_TAXONOMIES, true ) as $name => $object ) {
			$rewrite = isset( $object->rewrite ) && is_array( $object->rewrite ) ? $object->rewrite : array();
			$objectTypes = array_values( array_unique( array_filter( array_map( 'sanitize_key', isset( $object->object_type ) ? (array) $object->object_type : array() ) ) ) );
			sort( $objectTypes, SORT_STRING );
			$out[ $name ] = array(
				'label' => isset( $object->label ) ? sanitize_text_field( (string) $object->label ) : $name,
				'publicly_queryable' => ! empty( $object->publicly_queryable ),
				'hierarchical' => ! empty( $object->hierarchical ),
				'object_type' => $objectTypes,
				'rewrite_slug' => isset( $rewrite['slug'] ) ? sanitize_title( (string) $rewrite['slug'] ) : '',
			);
		}
		return array( 'items' => $out, 'completeness' => $this->completeness( $observedCount, count( $out ), self::MAX_TAXONOMIES ) );
	}

	private function languages(): array {
		$raw = array();
		if ( $this->languageProvider ) {
			$raw = call_user_func( $this->languageProvider );
		} elseif ( function_exists( 'apply_filters' ) ) {
			$candidate = apply_filters( 'wpml_active_languages', null, array( 'skip_missing' => 0, 'orderby' => 'code' ) );
			if ( is_array( $candidate ) ) { $raw = $candidate; }
		}
		$normalized = array();
		foreach ( is_array( $raw ) ? $raw : array() as $key => $language ) {
			if ( ! is_array( $language ) ) { continue; }
			$code = sanitize_key( (string) ( $language['code'] ?? $key ) );
			if ( '' === $code ) { continue; }
			$normalized[ $code ] = array(
				'code' => $code,
				'default_locale' => sanitize_text_field( (string) ( $language['default_locale'] ?? '' ) ),
				'native_name' => sanitize_text_field( (string) ( $language['native_name'] ?? '' ) ),
				'translated_name' => sanitize_text_field( (string) ( $language['translated_name'] ?? '' ) ),
				'active' => ! empty( $language['active'] ),
				'url_path' => isset( $language['url'] ) ? $this->pathOnly( (string) $language['url'] ) : '',
			);
		}
		ksort( $normalized, SORT_STRING );
		$observedCount = count( $normalized );
		$items = array_values( array_slice( $normalized, 0, self::MAX_LANGUAGES, true ) );
		return array( 'items' => $items, 'completeness' => $this->completeness( $observedCount, count( $items ), self::MAX_LANGUAGES ) );
	}

	private function completeness( int $observed, int $included, int $limit ): array {
		$observed = max( 0, $observed );
		$included = max( 0, $included );
		$limit = max( 0, $limit );
		return array(
			'observed_count' => $observed,
			'included_count' => $included,
			'limit' => $limit,
			'truncated' => $observed > $included,
		);
	}

	private function pathOnly( string $url ): string {
		$path = parse_url( $url, PHP_URL_PATH );
		if ( ! is_string( $path ) || '' === $path ) { return '/'; }
		$path = '/' . trim( rawurldecode( $path ), '/' );
		return '/' === $path ? '/' : $path . '/';
	}

	private function encode( array $value ): string {
		if ( function_exists( 'wp_json_encode' ) ) {
			$json = wp_json_encode( $value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
			if ( is_string( $json ) ) { return $json; }
		}
		$json = json_encode( $value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		return is_string( $json ) ? $json : '{}';
	}
}
