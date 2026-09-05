<?php
namespace ETG\DynamicFilterSEOBridge\Diagnostics;

require_once __DIR__ . '/RuntimeInventoryQueryTrait.php';
require_once __DIR__ . '/RuntimeInventoryStructureTrait.php';

final class RuntimeInventory {
	const CONTRACT = 'etg.dfsb.runtime-inventory.v2';
	const UNAVAILABLE_CONTRACT = 'etg.dfsb.runtime-inventory-unavailable.v1';
	const MAX_POST_TYPES = 100;
	const MAX_TAXONOMIES = 150;
	const MAX_QUERIES = 100;
	const MAX_LANGUAGES = 50;
	const MAX_ARCHIVE_PATH_TRANSLATIONS = 500;
	const MAX_QUERY_IDENTITY_CONFLICTS = 100;
	const MAX_QUERY_CONFLICT_RECORDS = 10;

	private $queryProvider;
	private $languageProvider;

	use RuntimeInventoryQueryTrait;
	use RuntimeInventoryStructureTrait;

	public function __construct( $queryProvider = null, $languageProvider = null ) {
		$this->queryProvider = is_callable( $queryProvider ) ? $queryProvider : null;
		$this->languageProvider = is_callable( $languageProvider ) ? $languageProvider : null;
	}

	public function collect(): array {
		$languagesResult = $this->languages();
		$postTypesResult = $this->postTypes( $languagesResult['items'], ! empty( $languagesResult['availability']['available'] ) );
		$taxonomiesResult = $this->taxonomies();
		$queriesResult = $this->queries();
		$availability = array(
			'post_types' => $postTypesResult['availability'],
			'taxonomies' => $taxonomiesResult['availability'],
			'languages' => $languagesResult['availability'],
			'query_builder' => $queriesResult['availability'],
			'archive_path_translations' => $postTypesResult['archive_translation_availability'],
		);
		$availabilityErrors = array();
		foreach ( $availability as $section => $record ) {
			if ( empty( $record['available'] ) ) { $availabilityErrors[] = sanitize_key( (string) $section ) . '_unavailable'; }
		}
		$core = array(
			'post_types' => $postTypesResult['items'],
			'taxonomies' => $taxonomiesResult['items'],
			'languages' => $languagesResult['items'],
			'query_builder' => $queriesResult['data'],
			'availability' => $availability,
			'completeness' => array(
				'post_types' => $postTypesResult['completeness'],
				'taxonomies' => $taxonomiesResult['completeness'],
				'languages' => $languagesResult['completeness'],
				'query_builder' => $queriesResult['completeness'],
				'archive_path_translations' => $postTypesResult['archive_translation_completeness'],
			),
		);
		$complete = empty( $availabilityErrors );
		$fingerprint = hash( 'sha256', $this->encode( $core ) );
		return array(
			'contract' => $complete ? self::CONTRACT : self::UNAVAILABLE_CONTRACT,
			'expected_contract' => self::CONTRACT,
			'authorizing' => false,
			'read_only' => true,
			'profile_mutation' => false,
			'evidence_complete' => $complete,
			'availability_errors' => $availabilityErrors,
			'limits' => array(
				'post_types' => self::MAX_POST_TYPES,
				'taxonomies' => self::MAX_TAXONOMIES,
				'queries' => self::MAX_QUERIES,
				'languages' => self::MAX_LANGUAGES,
				'archive_path_translations' => self::MAX_ARCHIVE_PATH_TRANSLATIONS,
				'query_identity_conflicts' => self::MAX_QUERY_IDENTITY_CONFLICTS,
			),
			'snapshot_fingerprint' => $fingerprint,
			'collected_at_gmt' => gmdate( 'c' ),
			'inventory' => $core,
		);
	}
}
