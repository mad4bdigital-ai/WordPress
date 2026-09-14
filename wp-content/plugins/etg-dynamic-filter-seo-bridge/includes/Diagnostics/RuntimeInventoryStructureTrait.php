<?php
namespace ETG\DynamicFilterSEOBridge\Diagnostics;

trait RuntimeInventoryStructureTrait {
	private function postTypes( array $languages, bool $languagesAvailable ): array {
		$out = array();
		$translationBudget = self::MAX_ARCHIVE_PATH_TRANSLATIONS;
		$translationRequested = 0;
		$translationIncluded = 0;
		$archiveTranslationAvailable = $languagesAvailable && $this->filterAvailable( 'wpml_permalink' );
		$archiveTranslationSource = $archiveTranslationAvailable ? 'wpml_permalink' : ( $languagesAvailable ? 'wpml_permalink_unavailable' : 'languages_unavailable' );
		if ( ! function_exists( 'get_post_types' ) ) {
			return array(
				'items' => $out,
				'availability' => array( 'available' => false, 'source' => 'wordpress_get_post_types_unavailable' ),
				'completeness' => $this->completeness( 0, 0, self::MAX_POST_TYPES ),
				'archive_translation_availability' => array( 'available' => false, 'source' => $archiveTranslationSource ),
				'archive_translation_completeness' => $this->completeness( 0, 0, self::MAX_ARCHIVE_PATH_TRANSLATIONS ),
			);
		}
		$postTypeSource = 'wordpress_get_post_types';
		try {
			$objects = $this->iterableArray( get_post_types( array( 'public' => true ), 'objects' ) );
		} catch ( \Throwable $error ) {
			$objects = null;
			$postTypeSource = 'wordpress_get_post_types_exception';
		}
		if ( null === $objects ) {
			if ( 'wordpress_get_post_types' === $postTypeSource ) { $postTypeSource = 'wordpress_get_post_types_invalid'; }
			return array(
				'items' => $out,
				'availability' => array( 'available' => false, 'source' => $postTypeSource ),
				'completeness' => $this->completeness( 0, 0, self::MAX_POST_TYPES ),
				'archive_translation_availability' => array( 'available' => false, 'source' => $archiveTranslationSource ),
				'archive_translation_completeness' => $this->completeness( 0, 0, self::MAX_ARCHIVE_PATH_TRANSLATIONS ),
			);
		}
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
					if ( $translationBudget <= 0 || ! $archiveTranslationAvailable ) { continue; }
					try {
						$candidate = apply_filters( 'wpml_permalink', $archiveUrl, $code, true );
					} catch ( \Throwable $error ) {
						$archiveTranslationAvailable = false;
						$archiveTranslationSource = 'wpml_permalink_exception';
						continue;
					}
					if ( ! is_string( $candidate ) || '' === $candidate ) {
						$archiveTranslationAvailable = false;
						$archiveTranslationSource = 'wpml_permalink_invalid_result';
						continue;
					}
					$archivePaths[ $code ] = $this->pathOnly( $candidate );
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
			'availability' => array( 'available' => true, 'source' => $postTypeSource ),
			'completeness' => $this->completeness( $observedCount, count( $out ), self::MAX_POST_TYPES ),
			'archive_translation_availability' => array( 'available' => $archiveTranslationAvailable, 'source' => $archiveTranslationSource ),
			'archive_translation_completeness' => $this->completeness( $translationRequested, $translationIncluded, self::MAX_ARCHIVE_PATH_TRANSLATIONS ),
		);
	}

	private function taxonomies(): array {
		$out = array();
		if ( ! function_exists( 'get_taxonomies' ) ) {
			return array(
				'items' => $out,
				'availability' => array( 'available' => false, 'source' => 'wordpress_get_taxonomies_unavailable' ),
				'completeness' => $this->completeness( 0, 0, self::MAX_TAXONOMIES ),
			);
		}
		$taxonomySource = 'wordpress_get_taxonomies';
		try {
			$objects = $this->iterableArray( get_taxonomies( array( 'public' => true ), 'objects' ) );
		} catch ( \Throwable $error ) {
			$objects = null;
			$taxonomySource = 'wordpress_get_taxonomies_exception';
		}
		if ( null === $objects ) {
			if ( 'wordpress_get_taxonomies' === $taxonomySource ) { $taxonomySource = 'wordpress_get_taxonomies_invalid'; }
			return array(
				'items' => $out,
				'availability' => array( 'available' => false, 'source' => $taxonomySource ),
				'completeness' => $this->completeness( 0, 0, self::MAX_TAXONOMIES ),
			);
		}
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
		return array(
			'items' => $out,
			'availability' => array( 'available' => true, 'source' => $taxonomySource ),
			'completeness' => $this->completeness( $observedCount, count( $out ), self::MAX_TAXONOMIES ),
		);
	}

	private function languages(): array {
		$raw = null;
		$available = false;
		$source = 'wpml_active_languages_unavailable';
		if ( $this->languageProvider ) {
			try {
				$raw = $this->iterableArray( call_user_func( $this->languageProvider ) );
				$available = is_array( $raw ) && ! empty( $raw );
				$source = $available ? 'injected_test_provider' : 'injected_test_provider_invalid_or_empty';
			} catch ( \Throwable $error ) {
				$raw = null;
				$source = 'injected_test_provider_exception';
			}
		} elseif ( function_exists( 'apply_filters' ) && $this->filterAvailable( 'wpml_active_languages' ) ) {
			try {
				$raw = $this->iterableArray( apply_filters( 'wpml_active_languages', null, array( 'skip_missing' => 0, 'orderby' => 'code' ) ) );
				$available = is_array( $raw ) && ! empty( $raw );
				$source = $available ? 'wpml_active_languages' : 'wpml_active_languages_invalid_or_empty';
			} catch ( \Throwable $error ) {
				$raw = null;
				$source = 'wpml_active_languages_exception';
			}
		}
		$normalized = array();
		foreach ( $available ? $raw : array() as $key => $language ) {
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
		if ( $available && ! $normalized ) {
			$available = false;
			$source = 'wpml_active_languages_no_valid_records';
		}
		ksort( $normalized, SORT_STRING );
		$observedCount = count( $normalized );
		$items = array_values( array_slice( $normalized, 0, self::MAX_LANGUAGES, true ) );
		return array(
			'items' => $items,
			'availability' => array( 'available' => $available, 'source' => $source ),
			'completeness' => $this->completeness( $observedCount, count( $items ), self::MAX_LANGUAGES ),
		);
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

	private function filterAvailable( string $tag ): bool {
		if ( function_exists( 'has_filter' ) ) { return false !== has_filter( $tag ); }
		return function_exists( 'apply_filters' );
	}

	private function iterableArray( $value ) {
		if ( is_array( $value ) ) { return $value; }
		if ( $value instanceof \Traversable ) { return iterator_to_array( $value ); }
		return null;
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
