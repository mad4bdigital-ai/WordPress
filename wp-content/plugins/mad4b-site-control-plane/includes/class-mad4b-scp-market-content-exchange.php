<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Contracted DMC exchange and independent competitor analysis are
 * disjoint capabilities with different sources, permissions and outcomes.
 * No remote download, image sideload or publication occurs in these plans.
 */
final class MAD4B_SCP_Market_Content_Exchange {
    const CONTRACT = 'mad4b.market-content-exchange.v1';
    private static function error( $key, $msg ) { return new WP_Error( $key, $msg ); }
    private static function valid_url( $value ) {
        if ( ! is_string( $value ) || strlen( $value ) > 2048 ) return false;
        $url = parse_url( $value );
        return is_array( $url ) && isset( $url['scheme'], $url['host'] )
            && in_array( strtolower( $url['scheme'] ), array( 'http', 'https' ), true )
            && ! isset( $url['user'] ) && ! isset( $url['pass'] );
    }

    public static function competitor_plan( $input = array() ) {
        $input = is_array( $input ) ? $input : array();
        $registry = MAD4B_SCP_Market_Growth_Policies::current();
        if ( is_wp_error( $registry ) ) return $registry;
        $id = isset( $input['competitor_id'] ) ? (string) $input['competitor_id'] : '';
        $profile = isset( $registry['competitors'][ $id ] ) ? $registry['competitors'][ $id ] : array();
        if ( ! $profile || ! empty( $profile['disabled'] ) )
            return self::error( 'mad4b_competitor_unavailable', 'A configured active competitor profile is required.' );
        $facts = isset( $input['facts'] ) && is_array( $input['facts'] ) ? $input['facts'] : array();
        $media = isset( $input['media_candidates'] ) && is_array( $input['media_candidates'] ) ? $input['media_candidates'] : array();
        if ( count( $facts ) > 64 || count( $media ) > 30 )
            return self::error( 'mad4b_competitor_limits', 'Research inputs exceed bounded limits.' );
        $normalized = array();
        foreach ( $facts as $fact ) {
            if ( ! is_array( $fact ) || empty( $fact['field'] ) || 1 !== preg_match( '/^[a-z][a-z0-9_\\-]{1,63}$/', (string) $fact['field'] )
                || ! isset( $fact['value'] ) || ! is_scalar( $fact['value'] )
                || strlen( (string) $fact['value'] ) > 1000 || ! self::valid_url( isset( $fact['source_url'] ) ? $fact['source_url'] : '' ) )
                return self::error( 'mad4b_competitor_fact_invalid', 'Each fact requires bounded value and exact public source URL.' );
            $normalized[] = array( 'field' => (string) $fact['field'], 'value' => (string) $fact['value'], 'source_url' => (string) $fact['source_url'] );
        }
        foreach ( $media as $item ) {
            if ( ! is_array( $item ) || ! self::valid_url( isset( $item['url'] ) ? $item['url'] : '' ) )
                return self::error( 'mad4b_competitor_media_invalid', 'Media candidates require bounded HTTP(S) URLs.' );
        }
        $pricing = isset( $input['pricing'] ) && is_array( $input['pricing'] ) ? $input['pricing'] : array();
        $price = null;
        if ( ! empty( $pricing['pricing_rule_id'] ) ) {
            $assessment = MAD4B_SCP_Market_Growth_Policies::inspect( $pricing );
            if ( is_wp_error( $assessment ) ) return $assessment;
            $price = $assessment['suggested_price'];
        }
        return array(
            'contract' => self::CONTRACT,
            'lane' => 'competitor_intelligence_without_contract',
            'competitor_id' => $id, 'source_url' => $profile['source_url'],
            'registry_revision' => (int) $registry['revision'],
            'registry_sha256' => MAD4B_SCP_Market_Growth_Policies::checksum( $registry ),
            'research_contract_required' => false,
            'facts' => $normalized,
            'media_candidates' => $media,
            'media_copied' => false,
            'media_ingest_requires_independent_license' => true,
            'derived_content' => 'new_original_brand_aligned_copy_from_verified_facts',
            'pricing' => $price,
            'requires_source_freshness_and_independent_price_validation' => true,
            'draft_without_supplier_contract_allowed' => true,
            'commercial_supplier_affiliation_implied' => false,
            'resale_rights_granted' => false,
            'content_exported' => false, 'content_imported' => false,
            'next_abilities' => array( 'context/brand-core-coverage', 'mad4b/content-model-discover',
                'mad4b/content-orchestration-plan', 'mad4b/content-apply-bundle', 'mad4b/content-bundle-readback' ),
            'publication_authorized' => false, 'read_only' => true, 'mutation_performed' => false,
        );
    }

    public static function dmc_plan( $input = array() ) {
        $input = is_array( $input ) ? $input : array();
        $registry = MAD4B_SCP_Market_Growth_Policies::current();
        if ( is_wp_error( $registry ) ) return $registry;
        $id = isset( $input['connection_id'] ) ? (string) $input['connection_id'] : '';
        $map_id = isset( $input['mapping_id'] ) ? (string) $input['mapping_id'] : '';
        $direction = isset( $input['direction'] ) ? (string) $input['direction'] : '';
        $dmc = isset( $registry['dmc_connections'][ $id ] ) ? $registry['dmc_connections'][ $id ] : array();
        $mapping = isset( $registry['feed_mappings'][ $map_id ] ) ? $registry['feed_mappings'][ $map_id ] : array();
        if ( ! $dmc || ! $mapping || ! in_array( $direction, array( 'import', 'export' ), true ) )
            return self::error( 'mad4b_dmc_invalid', 'Exact DMC connection, mapping and import/export direction are required.' );
        if ( ! in_array( $dmc['direction'], array( $direction, 'bidirectional' ), true )
            || ! in_array( $mapping['direction'], array( $direction, 'bidirectional' ), true ) )
            return self::error( 'mad4b_dmc_direction_denied', 'Direction not allowed by configured DMC connection and mapping.' );
        $supplier_id = $dmc['supplier_id'];
        $supplier = isset( $registry['suppliers'][ $supplier_id ] ) ? $registry['suppliers'][ $supplier_id ] : array();
        $until = ! empty( $supplier['valid_until'] ) ? strtotime( $supplier['valid_until'] . ' 23:59:59 UTC' ) : false;
        $contract = $supplier && 'contract_reviewed' === ( isset( $supplier['commercial_status'] ) ? $supplier['commercial_status'] : '' )
            && ! empty( $supplier['agreement_ref'] ) && false !== $until && $until >= time();
        $type = sanitize_key( (string) $mapping['post_type'] );
        $object = function_exists( 'get_post_type_object' ) ? get_post_type_object( $type ) : null;
        if ( ! $object ) return self::error( 'mad4b_dmc_post_type_unregistered', 'DMC mapping targets an unregistered WordPress post type.' );
        $cap = 'export' === $direction ? ( isset( $object->cap->edit_posts ) ? $object->cap->edit_posts : 'edit_posts' )
            : ( isset( $object->cap->create_posts ) ? $object->cap->create_posts : 'edit_posts' );
        $actor = function_exists( 'current_user_can' ) && current_user_can( $cap );
        return array(
            'contract' => self::CONTRACT, 'lane' => 'contracted_dmc_exchange',
            'connection_id' => $id, 'mapping_id' => $map_id, 'supplier_id' => $supplier_id,
            'direction' => $direction, 'post_type' => $type,
            'native_taxonomies' => function_exists( 'get_object_taxonomies' ) ? array_values( (array) get_object_taxonomies( $type ) ) : array(),
            'mapped_fields' => isset( $mapping['fields'] ) ? $mapping['fields'] : array(),
            'contract_record_present' => (bool) $contract, 'actor_capability_ready' => (bool) $actor,
            'exchange_plan_ready' => (bool) ( $contract && $actor ),
            'registry_revision' => (int) $registry['revision'],
            'registry_sha256' => MAD4B_SCP_Market_Growth_Policies::checksum( $registry ),
            'source_authorization_independently_verified' => false,
            'import_writes_require_exact_authorization' => true,
            'export_releases_require_channel_scope_and_content_provenance' => true,
            'next_abilities' => array( 'mad4b/content-model-discover', 'mad4b/content-orchestration-plan',
                'mad4b/content-apply-bundle', 'mad4b/content-bundle-readback' ),
            'external_feed_transfer_executed' => false, 'read_only' => true,
            'mutation_performed' => false, 'publication_authorized' => false,
        );
    }
    /**
     * Read-only, bounded WordPress CPT export for contracted DMCs. Produces a
     * handoff payload, not an unaudited HTTP push to an external distributor.
     */
    public static function export_preview( $input = array() ) {
        $input = is_array( $input ) ? $input : array();
        $plan = self::dmc_plan( array(
            'connection_id' => isset( $input['connection_id'] ) ? $input['connection_id'] : '',
            'mapping_id' => isset( $input['mapping_id'] ) ? $input['mapping_id'] : '',
            'direction' => 'export',
        ) );
        if ( is_wp_error( $plan ) ) return $plan;
        if ( empty( $plan['exchange_plan_ready'] ) )
            return self::error( 'mad4b_dmc_export_not_ready', 'DMC export requires a current registered contract and WordPress capabilities.' );
        $registry = MAD4B_SCP_Market_Growth_Policies::current();
        if ( is_wp_error( $registry ) ) return $registry;
        $map = $registry['feed_mappings'][ $plan['mapping_id'] ];
        $allowed = array( 'post_title', 'post_excerpt', 'post_content' );
        $fields = isset( $map['fields'] ) ? $map['fields'] : array( 'post_title', 'post_excerpt' );
        if ( ! is_array( $fields ) || count( $fields ) > count( $allowed ) ||
            array_diff( $fields, $allowed ) ) return self::error( 'mad4b_dmc_export_field_denied', 'Mapping contains fields not permitted for DMC export.' );
        $limit = isset( $input['limit'] ) ? max( 1, min( 20, (int) $input['limit'] ) ) : 10;
        $offset = isset( $input['offset'] ) ? max( 0, min( 100000, (int) $input['offset'] ) ) : 0;
        if ( ! function_exists( 'get_posts' ) ) return self::error( 'mad4b_dmc_export_runtime_missing', 'WordPress CPT query is unavailable.' );
        $posts = get_posts( array(
            'post_type' => $plan['post_type'], 'post_status' => 'publish',
            'posts_per_page' => $limit, 'offset' => $offset, 'orderby' => 'ID',
            'order' => 'ASC', 'suppress_filters' => false,
        ) );
        if ( ! is_array( $posts ) ) return self::error( 'mad4b_dmc_export_query_failed', 'WordPress export query failed.' );
        $items = array();
        foreach ( $posts as $post ) {
            if ( ! is_object( $post ) || ! isset( $post->ID ) || ! current_user_can( 'edit_post', (int) $post->ID ) ) continue;
            $record = array( 'source_post_id' => (int) $post->ID, 'post_type' => $plan['post_type'] );
            foreach ( $fields as $field ) $record[ $field ] = isset( $post->$field ) ? (string) $post->$field : '';
            $items[] = $record;
        }
        return array( 'contract' => 'mad4b.dmc-export-preview.v1', 'plan' => $plan,
            'items' => $items, 'count' => count( $items ), 'offset' => $offset,
            'content_export_payload_generated' => true, 'remote_transfer_executed' => false,
            'channel_contract_verification_before_send_required' => true,
            'read_only' => true, 'mutation_performed' => false, 'publication_authorized' => false );
    }

    /**
     * Converts contracted feed records to exact CPT draft bundle candidates.
     * The content-apply-bundle endpoint, never this planner, owns mutation,
     * idempotency, its independent approval and post-write acceptance.
     */
    public static function import_prepare( $input = array() ) {
        $input = is_array( $input ) ? $input : array();
        $plan = self::dmc_plan( array(
            'connection_id' => isset( $input['connection_id'] ) ? $input['connection_id'] : '',
            'mapping_id' => isset( $input['mapping_id'] ) ? $input['mapping_id'] : '',
            'direction' => 'import',
        ) );
        if ( is_wp_error( $plan ) ) return $plan;
        if ( empty( $plan['exchange_plan_ready'] ) )
            return self::error( 'mad4b_dmc_import_not_ready', 'DMC draft import requires current registered agreement and post-type capabilities.' );
        $items = isset( $input['items'] ) && is_array( $input['items'] ) ? $input['items'] : array();
        if ( ! $items || count( $items ) > 20 ) return self::error( 'mad4b_dmc_import_items_invalid', 'One to twenty source records are required.' );
        $registry = MAD4B_SCP_Market_Growth_Policies::current();
        if ( is_wp_error( $registry ) ) return $registry;
        $mapping = $registry['feed_mappings'][ $plan['mapping_id'] ];
        $fields = isset( $mapping['fields'] ) ? $mapping['fields'] : array( 'post_title', 'post_excerpt' );
        if ( ! is_array( $fields ) || count( $fields ) > 3 || array_diff( $fields, array( 'post_title', 'post_excerpt', 'post_content' ) ) )
            return self::error( 'mad4b_dmc_import_fields_invalid', 'Mapping fields are not permitted for safe draft import.' );
        $out = array(); $seen = array();
        foreach ( $items as $item ) {
            $foreign = isset( $item['external_id'] ) ? (string) $item['external_id'] : '';
            if ( ! is_array( $item ) || ! preg_match( '/^[A-Za-z0-9._:-]{1,128}$/', $foreign ) )
                return self::error( 'mad4b_dmc_import_identity_invalid', 'Every source record needs a bounded stable external ID.' );
            if ( isset( $seen[ $foreign ] ) ) return self::error( 'mad4b_dmc_import_duplicate', 'Duplicate external record ID.' );
            $seen[ $foreign ] = true;
            $post = array( 'post_status' => 'draft' );
            foreach ( $fields as $field ) {
                if ( ! isset( $item[ $field ] ) || ! is_string( $item[ $field ] ) ) continue;
                $bound = 'post_title' === $field ? 1000 : ( 'post_excerpt' === $field ? 262144 : 2097152 );
                if ( strlen( $item[ $field ] ) > $bound )
                    return self::error( 'mad4b_dmc_import_value_excess', 'Source field exceeds WordPress bundle maximum.' );
                $post[ $field ] = $item[ $field ];
            }
            if ( empty( $post['post_title'] ) )
                return self::error( 'mad4b_dmc_import_title_missing', 'Source item must have a mapped post title.' );
            $key = 'dmc-' . hash( 'sha256', $plan['connection_id'] . '|' . $plan['mapping_id'] . '|' . $foreign );
            $out[] = array(
                'mode' => 'create', 'post_type' => $plan['post_type'], 'operation_key' => $key,
                'post' => $post, 'external_id' => $foreign,
                'next_ability' => 'mad4b/content-orchestration-plan',
                'mutation_ability_after_independent_approval' => 'mad4b/content-apply-bundle',
            );
        }
        return array( 'contract' => 'mad4b.dmc-import-prepare.v1',
            'plan' => $plan, 'draft_candidates' => $out,
            'source_feed_trusted_without_independent_validation' => false,
            'requires_brand_rewriting_and_licensing_check' => true,
            'requires_existing_duplicate_search_before_creation' => true,
            'import_written' => false, 'publishing_authorized' => false,
            'read_only' => true, 'mutation_performed' => false );
    }

}
