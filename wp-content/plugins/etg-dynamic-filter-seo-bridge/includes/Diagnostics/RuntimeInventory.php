<?php
namespace ETG\DynamicFilterSEOBridge\Diagnostics;

final class RuntimeInventory {
	const CONTRACT = 'etg.dfsb.runtime-inventory.v2';
	const MAX_POST_TYPES = 100;
	const MAX_TAXONOMIES = 150;
	const MAX_QUERIES = 100;
	const MAX_LANGUAGES = 50;
	const MAX_ARCHIVE_PATH_TRANSLATIONS = 500;
	const MAX_QUERY_IDENTITY_CONFLICTS = 100;
	const MAX_QUERY_CONFLICT_RECORDS = 10;

	private $queryProvider;
	private $languageProvider;

	public function __construct( $queryProvider = null, $languageProvider = null ) {
		$this->queryProvider = is_callable( $queryProvider ) ? $queryProvider : null;
		$this->languageProvider = is_callable( $languageProvider ) ? $languageProvider : null;
	}

	public function collect(): array {
		$languagesResult = $this->languages();
		$postTypesResult = $this->postTypes( $languagesResult['items'] );
		$taxonomiesResult = $this->taxonomies();
		$queriesResult = $this->queries();
		$core = array(
			'post_types' => $postTypesResult['items'],
			'taxonomies' => $taxonomiesResult['items'],
			'languages' => $languagesResult['items'],
			'query_builder' => $queriesResult['data'],
			'completeness' => array(
				'post_types' => $postTypesResult['completeness'],
				'taxonomies' => $taxonomiesResult['completeness'],
				'languages' => $languagesResult['completeness'],
				'query_builder' => $queriesResult['completeness'],
				'archive_path_translations' => $postTypesResult['archive_translation_completeness'],
			),
		);
		return array(
			'contract' => self::CONTRACT,
			'authorizing' => false,
			'read_only' => true,
			'profile_mutation' => false,
			'limits' => array(
				'post_types' => self::MAX_POST_TYPES,
				'taxonomies' => self::MAX_TAXONOMIES,
				'queries' => self::MAX_QUERIES,
				'languages' => self::MAX_LANGUAGES,
				'archive_path_translations' => self::MAX_ARCHIVE_PATH_TRANSLATIONS,
				'query_identity_conflicts' => self::MAX_QUERY_IDENTITY_CONFLICTS,
			),
			'snapshot_fingerprint' => hash( 'sha256', $this->encode( $core ) ),
			'collected_at_gmt' => gmdate( 'c' ),
			'inventory' => $core,
		);
	}

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

	private function queries(): array {
		$raw = array();
		$available = false;
		$source = 'unavailable';
		if ( $this->queryProvider ) {
			$raw = call_user_func( $this->queryProvider );
			$available = true;
			$source = 'injected_test_provider';
		} elseif ( class_exists( '\\Jet_Engine\\Query_Builder\\Manager' ) ) {
			$manager = \Jet_Engine\Query_Builder\Manager::instance();
			if ( is_object( $manager ) && method_exists( $manager, 'get_queries' ) ) {
				$raw = $manager->get_queries();
				$available = true;
				$source = 'jet_engine_query_builder_manager_get_queries';
			}
		}
		$records = array();
		foreach ( is_array( $raw ) ? $raw : array() as $query ) {
			if ( ! is_object( $query ) ) { continue; }
			$id = isset( $query->id ) && is_scalar( $query->id ) ? sanitize_text_field( (string) $query->id ) : '';
			$customId = isset( $query->query_id ) && is_scalar( $query->query_id ) ? sanitize_key( (string) $query->query_id ) : '';
			$type = method_exists( $query, 'get_query_type' ) ? sanitize_key( (string) $query->get_query_type() ) : '';
			$key = $this->queryIdentityKey( $id, $customId );
			$records[] = array( 'object' => $query, 'id' => $id, 'custom_query_id' => $customId, 'type' => $type, 'identity_key' => $key );
		}
		usort( $records, static function ( $a, $b ) {
			foreach ( array( 'identity_key', 'id', 'type' ) as $field ) {
				$cmp = strcmp( (string) $a[ $field ], (string) $b[ $field ] );
				if ( 0 !== $cmp ) { return $cmp; }
			}
			return 0;
		} );
		$identityMap = array();
		foreach ( $records as $record ) {
			$key = (string) $record['identity_key'];
			if ( '' === $key ) { continue; }
			if ( ! isset( $identityMap[ $key ] ) ) { $identityMap[ $key ] = array(); }
			$identityMap[ $key ][] = array( 'id' => (string) $record['id'], 'custom_query_id' => (string) $record['custom_query_id'], 'type' => (string) $record['type'] );
		}
		$conflicts = array();
		$conflictCount = 0;
		foreach ( $identityMap as $key => $matches ) {
			if ( count( $matches ) < 2 ) { continue; }
			$conflictCount++;
			if ( count( $conflicts ) < self::MAX_QUERY_IDENTITY_CONFLICTS ) {
				$conflicts[] = array(
					'identity_key' => $key,
					'count' => count( $matches ),
					'records' => array_slice( $matches, 0, self::MAX_QUERY_CONFLICT_RECORDS ),
				);
			}
		}
		$items = array();
		foreach ( array_slice( $records, 0, self::MAX_QUERIES ) as $record ) {
			$query = $record['object'];
			$type = (string) $record['type'];
			$args = array();
			if ( 'posts' === $type && method_exists( $query, 'get_query_args' ) ) { $args = $query->get_query_args(); }
			$args = is_array( $args ) ? $args : array();
			$postTypes = $this->postTypesFromArgs( $args );
			$taxonomies = array_values( array_unique( $this->taxonomiesFromArgs( $args ) ) );
			sort( $taxonomies, SORT_STRING );
			$bounded = 'posts' !== $type || ( ! empty( $postTypes ) && ! in_array( 'any', $postTypes, true ) );
			$items[] = array(
				'id' => (string) $record['id'],
				'custom_query_id' => (string) $record['custom_query_id'],
				'identity_key' => (string) $record['identity_key'],
				'type' => $type,
				'post_types' => $postTypes,
				'post_type_bounded' => $bounded,
				'taxonomies' => $taxonomies,
			);
		}
		return array(
			'data' => array(
				'available' => $available,
				'source' => $source,
				'identity_conflict_count' => $conflictCount,
				'identity_conflicts_truncated' => $conflictCount > count( $conflicts ),
				'identity_conflicts' => $conflicts,
				'queries' => $items,
			),
			'completeness' => $this->completeness( count( $records ), count( $items ), self::MAX_QUERIES ),
		);
	}

	private function queryIdentityKey( string $id, string $customId ): string {
		$key = '' !== $customId ? $customId : sanitize_key( $id );
		return sanitize_key( $key );
	}

	private function postTypesFromArgs( array $args ): array {
		$value = $args['post_type'] ?? array();
		$list = is_array( $value ) ? $value : array( $value );
		$out = array_values( array_unique( array_filter( array_map( static function ( $item ) {
			$item = strtolower( trim( (string) $item ) );
			return 'any' === $item ? 'any' : sanitize_key( $item );
		}, $list ) ) ) );
		sort( $out, SORT_STRING );
		return $out;
	}

	private function taxonomiesFromArgs( array $args ): array {
		$out = array();
		$walk = function ( $value ) use ( &$walk, &$out ) {
			if ( ! is_array( $value ) ) { return; }
			if ( isset( $value['taxonomy'] ) && is_scalar( $value['taxonomy'] ) ) {
				$taxonomy = sanitize_key( (string) $value['taxonomy'] );
				if ( '' !== $taxonomy ) { $out[] = $taxonomy; }
			}
			foreach ( $value as $child ) { if ( is_array( $child ) ) { $walk( $child ); } }
		};
		if ( isset( $args['tax_query'] ) ) { $walk( $args['tax_query'] ); }
		return $out;
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
