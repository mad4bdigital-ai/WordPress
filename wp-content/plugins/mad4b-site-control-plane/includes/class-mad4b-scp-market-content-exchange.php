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
}
