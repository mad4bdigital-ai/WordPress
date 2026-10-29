<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Catalog -> intent-based main and complementary research task planning.
 *
 * This is a deterministic, read-only policy. It accepts already discovered
 * links with explicit extraction provenance; it does not crawl, persist,
 * approve, enqueue, copy publisher material, or write WordPress content.
 * The caller must use the existing Research/Content governance to execute
 * any proposed task.
 */
final class MAD4B_SCP_Catalog_Intent_Policy {
    const CONTRACT = 'mad4b.catalog-intent-policy.v1';
    const ABILITY = 'mad4b/catalog-intent-plan';
    const MAX_LINKS = 200;
    const INTENTS = array( 'tour_catalog', 'destination_guide', 'content_research', 'price_research' );
    const ENTITY_TYPES = array( 'individual_tour', 'package_tour', 'attraction', 'destination',
        'editorial_guide', 'comparison', 'collection', 'filter', 'pagination', 'unknown' );
    const CONTEXTS = array( 'listing_card', 'inline_reference', 'recommendation',
        'navigation', 'filter_control', 'pagination_control', 'footer', 'unknown' );
    const RELATIONS = array( 'direct', 'supporting', 'exploration', 'unrelated', 'unknown' );

    public static function boot() {
        if ( function_exists( 'add_action' ) ) {
            add_action( 'wp_abilities_api_init', array( __CLASS__, 'register' ), 40 );
        }
    }

    public static function register() {
        if ( ! function_exists( 'wp_register_ability' )
            || ( function_exists( 'wp_has_ability' ) && wp_has_ability( self::ABILITY ) ) ) return;
        wp_register_ability( self::ABILITY, array(
            'label' => 'Plan Catalog Main and Complementary Research Tasks',
            'description' => 'Govern intent-specific classification, de-duplication, side research branches and safe execution handoff for extracted catalog links. Does not fetch pages or create posts.',
            'category' => 'mad4b-read',
            'execute_callback' => array( __CLASS__, 'plan' ),
            'permission_callback' => array( 'MAD4B_SCP_Policy', 'can_read' ),
            'input_schema' => array(
                'type' => 'object',
                'additionalProperties' => false,
                'required' => array( 'catalog_url', 'intent', 'links' ),
                'properties' => array(
                    'catalog_url' => array( 'type' => 'string', 'maxLength' => 2048 ),
                    'intent' => array( 'type' => 'string', 'enum' => self::INTENTS ),
                    'links' => array( 'type' => 'array', 'minItems' => 1, 'maxItems' => self::MAX_LINKS,
                        'items' => array( 'type' => 'object', 'additionalProperties' => false,
                            'required' => array( 'url', 'title', 'entity_type', 'discovery_context', 'intent_relation' ),
                            'properties' => array(
                                'url' => array( 'type' => 'string', 'maxLength' => 2048 ),
                                'title' => array( 'type' => 'string', 'maxLength' => 240 ),
                                'entity_type' => array( 'type' => 'string', 'enum' => self::ENTITY_TYPES ),
                                'discovery_context' => array( 'type' => 'string', 'enum' => self::CONTEXTS ),
                                'intent_relation' => array( 'type' => 'string', 'enum' => self::RELATIONS ),
                                'entity_key' => array( 'type' => 'string', 'maxLength' => 128 ),
                                'related_primary_urls' => array( 'type' => 'array', 'maxItems' => 30,
                                    'items' => array( 'type' => 'string', 'maxLength' => 2048 ) ),
                            ),
                        ),
                    ),
                    'existing_urls' => array( 'type' => 'array', 'maxItems' => 200,
                        'items' => array( 'type' => 'string', 'maxLength' => 2048 ) ),
                ),
            ),
            'output_schema' => array( 'type' => 'object', 'additionalProperties' => true ),
            'meta' => array( 'public' => false, 'show_in_rest' => false,
                'mcp' => array( 'public' => false, 'type' => 'tool', 'surface' => 'read' ),
                'annotations' => array( 'readonly' => true, 'destructive' => false, 'idempotent' => true ) ),
        ) );
    }

    private static function error( $code ) {
        return new WP_Error( 'mad4b_catalog_intent_' . $code, 'Catalog planning rejected invalid or ungrounded input.',
            array( 'status' => 400, 'mutation_performed' => false ) );
    }

    /**
     * Canonicalize for comparison only. Preserve functional filters, page and
     * query parameters; remove a small known set of advertising trackers.
     * Never treat query-free category URLs as equivalent to filtered results.
     */
    public static function canonical_url( $value ) {
        if ( ! is_string( $value ) || '' === $value || strlen( $value ) > 2048
            || preg_match( '/[\\x00-\\x20\\x7f\\\\]/', $value ) ) return '';
        $parts = parse_url( $value );
        if ( ! is_array( $parts ) || ! isset( $parts['scheme'], $parts['host'] )
            || 'https' !== strtolower( $parts['scheme'] )
            || isset( $parts['user'] ) || isset( $parts['pass'] )
            || isset( $parts['port'] ) ) return '';
        $host = strtolower( $parts['host'] );
        if ( 'localhost' === $host || filter_var( $host, FILTER_VALIDATE_IP )
            || ! filter_var( $host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME )
            || false === strpos( $host, '.' ) ) return '';
        $path = isset( $parts['path'] ) ? $parts['path'] : '/';
        if ( '' === $path ) $path = '/';
        $path = preg_replace( '~/+~', '/', $path );
        if ( '/' !== $path ) $path = rtrim( $path, '/' );
        $query = array();
        if ( isset( $parts['query'] ) ) {
            if ( strlen( $parts['query'] ) > 1024 ) return '';
            parse_str( $parts['query'], $query );
            foreach ( array_keys( $query ) as $key ) {
                if ( preg_match( '/^utm_/i', (string) $key )
                    || in_array( strtolower( (string) $key ), array( 'fbclid', 'gclid', 'msclkid' ), true ) ) {
                    unset( $query[ $key ] );
                }
            }
            ksort( $query, SORT_STRING );
        }
        $suffix = empty( $query ) ? '' : '?' . http_build_query( $query, '', '&', PHP_QUERY_RFC3986 );
        return 'https://' . $host . $path . $suffix;
    }

    private static function lane( $intent, $type, $context, $relation ) {
        if ( 'unrelated' === $relation || 'unknown' === $relation ) {
            return array( 'review', 'intent_relevance_not_proven' );
        }
        if ( 'filter' === $type || 'pagination' === $type
            || 'filter_control' === $context || 'pagination_control' === $context ) {
            return array( 'discovery_expansion', 'functional_navigation_can_expand_relevant_catalog' );
        }
        if ( 'collection' === $type && 'tour_catalog' === $intent
            && 'navigation' === $context
            && in_array( $relation, array( 'direct', 'supporting', 'exploration' ), true ) ) {
            return array( 'discovery_expansion', 'related_catalog_category_can_expand_inventory' );
        }
        $primary_types = 'destination_guide' === $intent
            ? array( 'destination', 'attraction' ) : array( 'individual_tour', 'package_tour' );
        if ( 'direct' === $relation && 'listing_card' === $context
            && in_array( $type, $primary_types, true ) ) {
            return array( 'primary', 'direct_catalog_card_matches_requested_entity_type' );
        }
        if ( 'unknown' === $type || 'unknown' === $context
            || 'footer' === $context || ( 'navigation' === $context && 'exploration' === $relation ) ) {
            return array( 'review', 'source_context_or_relevance_requires_review' );
        }
        if ( in_array( $type, self::ENTITY_TYPES, true )
            && in_array( $relation, array( 'direct', 'supporting', 'exploration' ), true ) ) {
            return array( 'complementary', 'context_supports_primary_intent_without_becoming_same_entity' );
        }
        return array( 'review', 'manual_intent_assessment_required' );
    }

    private static function task( $catalog, $url, array $link, $lane, $reason ) {
        $id = 'cat-' . substr( hash( 'sha256', $catalog . '|' . $lane . '|' . $url ), 0, 20 );
        return array(
            'task_id' => $id,
            'lane' => $lane,
            'state' => 'proposed_requires_governed_intake',
            'source_url' => $url,
            'catalog_source_url' => $catalog,
            'source_title' => $link['title'],
            'entity_type_candidate' => $link['entity_type'],
            'source_context' => $link['discovery_context'],
            'intent_relation' => $link['intent_relation'],
            'decision_reason' => $reason,
            'relationship_status' => 'not_yet_mapped',
            'related_primary_task_ids' => array(),
            'required_next_steps' => 'discovery_expansion' === $lane
                ? array( 'enumerate_results_with_bounded_budget', 'reclassify_discovered_links' )
                : ( 'review' === $lane ? array( 'verify_source_and_intent' )
                : array( 'review_individual_page', 'verify_existing_local_entity', 'check_source_rights',
                    'prepare_original_content', 'obtain_approved_write_and_publication' ) ),
            'governed_task_persisted' => false,
            'publish_authorized' => false,
            'pricing_verified' => false,
            'rights_verified' => false,
        );
    }

    /** Deterministic policy; caller supplies explicit link evidence, not guesses inferred from URLs. */
    public static function plan( $input ) {
        if ( ! class_exists( 'MAD4B_SCP_Policy' )
            || true !== MAD4B_SCP_Policy::can_read() ) return self::error( 'permission_denied' );
        if ( ! is_array( $input ) || array_diff( array_keys( $input ),
            array( 'catalog_url', 'intent', 'links', 'existing_urls' ) ) ) return self::error( 'input_invalid' );
        $catalog = self::canonical_url( $input['catalog_url'] ?? null );
        $intent = $input['intent'] ?? '';
        $links = $input['links'] ?? null;
        if ( '' === $catalog || ! in_array( $intent, self::INTENTS, true )
            || ! is_array( $links ) || count( $links ) < 1 || count( $links ) > self::MAX_LINKS ) {
            return self::error( 'contract_invalid' );
        }
        $existing = $input['existing_urls'] ?? array();
        if ( ! is_array( $existing ) || count( $existing ) > 200 ) return self::error( 'existing_invalid' );
        $known = array();
        foreach ( $existing as $u ) {
            $normalized = self::canonical_url( $u );
            if ( '' === $normalized ) return self::error( 'existing_invalid' );
            $known[ $normalized ] = true;
        }

        $tasks = array( 'primary' => array(), 'complementary' => array(),
            'discovery_expansion' => array(), 'review' => array() );
        $duplicates = array();
        $seen_url = array();
        $seen_entity = array();
        $primary_by_url = array();
        $task_lane_by_id = array();
        $unresolved_relations = 0;
        $rows = array();

        foreach ( $links as $index => $link ) {
            if ( ! is_array( $link ) || array_diff( array_keys( $link ),
                array( 'url', 'title', 'entity_type', 'discovery_context', 'intent_relation',
                    'related_primary_urls', 'entity_key' ) ) ) return self::error( 'link_invalid' );
            $url = self::canonical_url( $link['url'] ?? null );
            $title = $link['title'] ?? null;
            $type = $link['entity_type'] ?? null;
            $context = $link['discovery_context'] ?? null;
            $relation = $link['intent_relation'] ?? null;
            $entity = $link['entity_key'] ?? '';
            $related = $link['related_primary_urls'] ?? array();
            if ( '' === $url || ! is_string( $title ) || '' === trim( $title ) || strlen( $title ) > 240
                || ! in_array( $type, self::ENTITY_TYPES, true )
                || ! in_array( $context, self::CONTEXTS, true )
                || ! in_array( $relation, self::RELATIONS, true )
                || ! is_string( $entity ) || strlen( $entity ) > 128
                || ( '' !== $entity && ! preg_match( '/^[a-z0-9][a-z0-9._:-]{0,127}$/D', $entity ) )
                || ! is_array( $related ) || count( $related ) > 30 ) return self::error( 'evidence_invalid' );
            $rel = array();
            foreach ( $related as $target ) {
                $target = self::canonical_url( $target );
                if ( '' === $target ) return self::error( 'relation_invalid' );
                $rel[ $target ] = true;
            }
            list( $lane, $reason ) = self::lane( $intent, $type, $context, $relation );
            $rows[] = array( 'url' => $url, 'link' => array(
                'title' => trim( $title ), 'entity_type' => $type,
                'discovery_context' => $context, 'intent_relation' => $relation ),
                'lane' => $lane, 'reason' => $reason, 'entity' => $entity,
                'related_urls' => array_keys( $rel ), 'position' => $index + 1 );
        }
        // Prefer main listing-card evidence when a URL appears in several
        // contexts; do not let navigation/footer evidence hide a valid card.
        $weight = array( 'primary' => 4, 'complementary' => 3,
            'discovery_expansion' => 2, 'review' => 1 );
        usort( $rows, static function ( $a, $b ) use ( $weight, $known ) {
            $delta = $weight[ $b['lane'] ] - $weight[ $a['lane'] ];
            if ( $delta ) return $delta;
            $existing_delta = (int) isset( $known[ $b['url'] ] )
                - (int) isset( $known[ $a['url'] ] );
            return $existing_delta ?: $a['position'] - $b['position'];
        } );
        foreach ( $rows as $row ) {
            $url = $row['url'];
            $key = $row['entity'];
            $existing_task = $seen_url[ $url ] ?? ( '' !== $key ? ( $seen_entity[ $key ] ?? null ) : null );
            if ( null !== $existing_task ) {
                $duplicates[] = array( 'url' => $url, 'matching_task_id' => $existing_task,
                    'reason' => isset( $seen_url[ $url ] ) ? 'same_canonical_url' : 'explicit_entity_key_alias',
                    'candidate_context' => $row['link']['discovery_context'] );
                // Preserve every verified alias URL for future explicit
                // side-branch dependency resolution without a duplicate task.
                $seen_url[ $url ] = $existing_task;
                if ( 'primary' === ( $task_lane_by_id[ $existing_task ] ?? '' ) ) {
                    $primary_by_url[ $url ] = $existing_task;
                }
                continue;
            }
            $task = self::task( $catalog, $url, $row['link'], $row['lane'], $row['reason'] );
            $task['discovery_position'] = $row['position'];
            $task['existing_local_url_match'] = isset( $known[ $url ] );
            if ( $task['existing_local_url_match'] ) {
                $task['required_next_steps'] = array( 'review_existing_local_entity', 'assess_update_not_create' );
            }
            $task['entity_key_claim'] = $key;
            $task['relation_urls_pending_mapping'] = $row['related_urls'];
            $tasks[ $row['lane'] ][] = $task;
            $seen_url[ $url ] = $task['task_id'];
            $task_lane_by_id[ $task['task_id'] ] = $row['lane'];
            if ( '' !== $key ) $seen_entity[ $key ] = $task['task_id'];
            if ( 'primary' === $row['lane'] ) $primary_by_url[ $url ] = $task['task_id'];
        }
        // Bind only explicit relationships to main tasks in this plan.
        foreach ( array( 'complementary', 'discovery_expansion', 'review' ) as $lane ) {
            foreach ( $tasks[ $lane ] as &$task ) {
                $targets = $task['relation_urls_pending_mapping'];
                foreach ( $targets as $url ) {
                    if ( isset( $primary_by_url[ $url ] ) ) {
                        $task['related_primary_task_ids'][] = $primary_by_url[ $url ];
                    }
                }
                if ( ! empty( $task['related_primary_task_ids'] ) ) {
                    $task['relationship_status'] = 'mapped_from_explicit_catalog_evidence';
                } elseif ( ! empty( $targets ) ) {
                    $task['relationship_status'] = 'requires_primary_mapping_review';
                    ++$unresolved_relations;
                }
                unset( $task['relation_urls_pending_mapping'] );
            }
            unset( $task );
        }
        foreach ( $tasks['primary'] as &$task ) unset( $task['relation_urls_pending_mapping'] );
        unset( $task );
        $counts = array();
        foreach ( $tasks as $lane => $items ) $counts[ $lane ] = count( $items );
        return array(
            'contract' => self::CONTRACT,
            'catalog_url' => $catalog,
            'intent' => $intent,
            'policy' => 'classify_by_declared_intent_and_evidence_not_by_url_exclusion',
            'counts' => $counts,
            'duplicate_count' => count( $duplicates ),
            'duplicates' => $duplicates,
            'tasks' => $tasks,
            'unresolved_relationships' => $unresolved_relations,
            'next_ability' => 'mad4b/content-orchestration-plan',
            'required_write_lanes' => array( 'approved_content_job', 'governed_mcp_write' ),
            'task_creation_performed' => false,
            'network_requests_performed' => 0,
            'wordpress_mutation_performed' => false,
            'third_party_content_copied' => false,
            'publication_authorized' => false,
            'source_rights_clearance_required' => true,
            'prices_and_availability_independently_verified' => false,
            'provider_unavailable_continuation' => 'retain_plan_and_research_gaps_without_false_completion',
        );
    }
}
MAD4B_SCP_Catalog_Intent_Policy::boot();
