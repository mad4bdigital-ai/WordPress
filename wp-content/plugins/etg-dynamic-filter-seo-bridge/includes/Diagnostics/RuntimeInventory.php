<?php
namespace ETG\DynamicFilterSEOBridge\Diagnostics;

require_once __DIR__ . '/RuntimeInventoryQueryTrait.php';
require_once __DIR__ . '/RuntimeInventoryStructureTrait.php';

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

	use RuntimeInventoryQueryTrait;
	use RuntimeInventoryStructureTrait;

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
}
