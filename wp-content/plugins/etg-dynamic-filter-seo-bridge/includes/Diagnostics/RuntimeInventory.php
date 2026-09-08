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
    const MAX_QUERY_IDENTITIES = 2000;
    const MAX_LANGUAGES = 50;
    const MAX_ARCHIVE_PATH_TRANSLATIONS = 500;
    const MAX_QUERY_IDENTITY_CONFLICTS = 100;
    const MAX_QUERY_CONFLICT_RECORDS = 10;
    const MAX_TERM_META_KEYS = 50;
    const MAX_TERM_META_TERMS = 10;

    private $queryProvider;
    private $languageProvider;
    private $topologyProvider;

    use RuntimeInventoryQueryTrait;
    use RuntimeInventoryStructureTrait;

    public function __construct( $queryProvider = null, $languageProvider = null, $topologyProvider = null ) {
        $this->queryProvider = is_callable( $queryProvider ) ? $queryProvider : null;
        $this->languageProvider = is_callable( $languageProvider ) ? $languageProvider : null;
        $this->topologyProvider = is_callable( $topologyProvider ) ? $topologyProvider : null;
    }

    public function collect(): array {
        $languagesResult = $this->languages();
        $postTypesResult = $this->postTypes( $languagesResult['items'], ! empty( $languagesResult['availability']['available'] ) );
        $taxonomiesResult = $this->taxonomies();
        $queriesResult = $this->queries();
        $topology = $this->topology();
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
            'elementor_topology' => $topology,
            'availability' => $availability,
            'completeness' => array(
                'post_types' => $postTypesResult['completeness'],
                'taxonomies' => $taxonomiesResult['completeness'],
                'languages' => $languagesResult['completeness'],
                'query_builder' => $queriesResult['completeness'],
                'query_identity_index' => $queriesResult['identity_completeness'],
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
                'query_identities' => self::MAX_QUERY_IDENTITIES,
                'languages' => self::MAX_LANGUAGES,
                'archive_path_translations' => self::MAX_ARCHIVE_PATH_TRANSLATIONS,
                'query_identity_conflicts' => self::MAX_QUERY_IDENTITY_CONFLICTS,
                'term_meta_keys_per_taxonomy' => self::MAX_TERM_META_KEYS,
            ),
            'snapshot_fingerprint' => $fingerprint,
            'collected_at_gmt' => gmdate( 'c' ),
            'inventory' => $core,
        );
    }

    private function topology(): array {
        if ( $this->topologyProvider ) {
            try {
                $value = call_user_func( $this->topologyProvider );
                if ( is_array( $value ) ) { return $value; }
            } catch ( \Throwable $error ) {}
        }
        if ( class_exists( '\\ETG\\DynamicFilterSEOBridge\\Runtime\\RuntimeTopologyDiscoverer' ) ) {
            try { return ( new \ETG\DynamicFilterSEOBridge\Runtime\RuntimeTopologyDiscoverer() )->discover(); } catch ( \Throwable $error ) {}
        }
        return array(
            'contract'=>'etg.dfsb.runtime-topology.v1','authorizing'=>false,'read_only'=>true,'profile_mutation'=>false,
            'available'=>false,'sources'=>array(),'templates_scanned'=>0,'query_builder_records_observed'=>0,'elements_scanned'=>0,'truncated'=>false,
            'provider_query_ids'=>array(),'bindings'=>array(),'binding_count'=>0,'bindings_truncated'=>false
        );
    }
}
