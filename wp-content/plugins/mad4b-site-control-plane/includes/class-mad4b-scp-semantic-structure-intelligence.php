<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Evidence-first semantic structure interpreter, independent of business domain.
 *
 * The WordPress registration surface is a trustworthy source of schema facts,
 * NOT a source of workflow permission, intended business meaning, ownership of
 * copy/media, JetEngine relation contents or editorial approval.
 *
 * The resulting graph helps an AI planner reason about composition, reuse,
 * cardinality, variants and incomplete native-provider evidence. No writes.
 */
final class MAD4B_SCP_Semantic_Structure_Intelligence {
    const CONTRACT = 'mad4b.semantic-structure-intelligence.v1';
    const ABILITY = 'mad4b/semantic-structure-inspect';
    const MAX_NODES = 128;
    const MAX_EDGES = 192;
    const MAX_FIELDS = 128;

    public static function boot() {
        if ( function_exists( 'add_action' ) )
            add_action( 'wp_abilities_api_init', array( __CLASS__, 'register_ability' ), 35 );
    }

    public static function register_ability() {
        if ( ! function_exists( 'wp_register_ability' ) ||
            ( function_exists( 'wp_has_ability' ) && wp_has_ability( self::ABILITY ) ) ) return;
        wp_register_ability( self::ABILITY, array(
            'label' => 'Semantic Structure Intelligence',
            'description' => 'Read-only runtime schema graph: reusable shared references, composable content, cardinality, provider evidence gaps. No domain hardcoding or execution.',
            'category' => 'mad4b-read',
            'execute_callback' => array( __CLASS__, 'inspect' ),
            'permission_callback' => array( 'MAD4B_SCP_Policy', 'can_read' ),
            'input_schema' => array(
                'type' => 'object', 'additionalProperties' => false,
                'properties' => array(
                    'post_type' => array( 'type' => 'string', 'pattern' => '^[a-z0-9_-]{1,64}$' ),
                    'post_id' => array( 'type' => 'integer', 'minimum' => 1 ),
                    'goal' => array( 'type' => 'string',
                        'enum' => array( 'understand_structure', 'compose_content', 'analyze_reuse' ) ),
                ), 'required' => array( 'post_type' ),
            ),
            'output_schema' => array( 'type' => 'object', 'additionalProperties' => true ),
            'meta' => array(
                'public' => false, 'show_in_rest' => false,
                'mcp' => array( 'public' => false, 'type' => 'tool', 'surface' => 'read' ),
                'annotations' => array( 'readonly' => true, 'destructive' => false, 'idempotent' => true ),
            ),
        ) );
    }

    /**
     * One bounded, admin-scoped live observation. Provider-specific graphs
     * (JetEngine relations, native meta references, translations) must be
     * obtained through certified read adapters; never inferred from names.
     */
    public static function inspect( $input = array() ) {
        if ( ! class_exists( 'MAD4B_SCP_Policy' ) || ! MAD4B_SCP_Policy::can_read() )
            return new WP_Error( 'mad4b_semantic_structure_read_denied', 'Governed read permission required.' );
        if ( ! is_array( $input ) || array_diff( array_keys( $input ), array( 'post_type', 'goal', 'post_id' ) ) )
            return new WP_Error( 'mad4b_semantic_structure_input_invalid', 'Unexpected inspection input.' );
        $type = $input['post_type'] ?? null;
        $goal = $input['goal'] ?? 'understand_structure';
        if ( ! is_string( $type ) || ! preg_match( '/^[a-z0-9_-]{1,64}$/D', $type ) ||
            ! in_array( $goal, array( 'understand_structure', 'compose_content', 'analyze_reuse' ), true ) )
            return new WP_Error( 'mad4b_semantic_structure_target_invalid', 'Exact registered post type and known read goal required.' );
        $post_id = $input['post_id'] ?? 0;
        if ( $post_id && ( ! is_int( $post_id ) || $post_id < 1 ||
            ! function_exists( 'get_post' ) || ! function_exists( 'wp_get_object_terms' ) ) )
            return new WP_Error( 'mad4b_semantic_structure_post_invalid', 'Exact supported post ID required.' );
        if ( $post_id ) {
            $post = get_post( $post_id );
            if ( ! is_object( $post ) || (string) ( $post->post_type ?? '' ) !== $type ||
                ! function_exists( 'current_user_can' ) || ! current_user_can( 'read_post', $post_id ) )
                return new WP_Error( 'mad4b_semantic_structure_post_read_denied', 'Post identity or read capability invalid.' );
        }
        if ( ! function_exists( 'get_post_type_object' ) ||
            ! function_exists( 'get_object_taxonomies' ) )
            return new WP_Error( 'mad4b_semantic_structure_wp_schema_unavailable', 'WordPress schema APIs unavailable.' );
        $post_type = get_post_type_object( $type );
        if ( ! is_object( $post_type ) )
            return new WP_Error( 'mad4b_semantic_structure_type_unknown', 'Post type is not registered.' );
        $site = class_exists( 'MAD4B_SCP_Site_Profile' ) ? MAD4B_SCP_Site_Profile::status() : array();
        if ( ! is_array( $site ) || empty( $site['site_uuid'] ) ||
             empty( $site['origin_match'] ) || empty( $site['environment_match'] ) )
            return new WP_Error( 'mad4b_semantic_structure_site_unbound', 'Exact Site Profile is not enrolled.' );

        $subject = 'post_type:' . $type;
        $nodes = array( array( 'id' => $subject, 'kind' => 'post_type',
            'name' => $type, 'evidence' => 'wp_registered_post_type' ) );
        $edges = array();
        $taxonomies = get_object_taxonomies( $type, 'objects' );
        if ( ! is_array( $taxonomies ) || count( $taxonomies ) > self::MAX_NODES - 1 )
            return new WP_Error( 'mad4b_semantic_structure_taxonomy_overflow', 'Unexpected or unbounded taxonomy catalog.' );
        foreach ( $taxonomies as $tax ) {
            if ( ! is_object( $tax ) || ! isset( $tax->name ) ||
                 ! preg_match( '/^[a-z0-9_-]{1,64}$/D', (string) $tax->name ) )
                return new WP_Error( 'mad4b_semantic_structure_taxonomy_invalid', 'Invalid registered taxonomy identity.' );
            $node_id = 'taxonomy:' . $tax->name;
            $nodes[] = array( 'id' => $node_id, 'kind' => 'taxonomy_term_set',
                'name' => $tax->name, 'hierarchical' => ! empty( $tax->hierarchical ),
                'evidence' => 'wp_registered_taxonomy' );
            $assigned = array(); $assigned_complete = true;
            if ( $post_id ) {
                $terms = wp_get_object_terms( $post_id, $tax->name, array( 'fields' => 'all' ) );
                if ( is_wp_error( $terms ) || ! is_array( $terms ) )
                    return new WP_Error( 'mad4b_semantic_structure_term_read_failed',
                        'Cannot independently read assigned taxonomy terms.' );
                $assigned_complete = count( $terms ) <= 40;
                foreach ( array_slice( $terms, 0, 40 ) as $term ) {
                    if ( ! is_object( $term ) || ! isset( $term->term_id, $term->taxonomy ) ||
                        (string) $term->taxonomy !== (string) $tax->name )
                        return new WP_Error( 'mad4b_semantic_structure_term_invalid', 'Term identity inconsistent.' );
                    $assigned[] = array( 'term_id' => (int) $term->term_id,
                        'taxonomy' => (string) $term->taxonomy,
                        'name' => (string) ( $term->name ?? '' ),
                        'slug' => (string) ( $term->slug ?? '' ),
                        'observed_post_count' => isset( $term->count ) ? (int) $term->count : null );
                }
            }
            $edges[] = array(
                'from' => $subject, 'to' => $node_id,
                'relation' => 'taxonomy_assignment',
                // A post can have many terms and a term can label many posts.
                // This is a schema affordance, NOT proof that any given term
                // is assigned to an existing post or approved for publication.
                'from_to' => 'many', 'to_from' => 'many',
                'evidence' => 'wp_registered_taxonomy_association',
                'verified_schema' => true, 'verified_record_links' => $post_id && $assigned_complete,
                'observed_post_id' => $post_id ? $post_id : null,
                'assigned_terms' => $assigned, 'assignment_complete' => $post_id ? $assigned_complete : false,
            );
        }
        $fields = array();
        if ( function_exists( 'get_registered_meta_keys' ) ) {
            $meta = get_registered_meta_keys( 'post', $type );
            if ( ! is_array( $meta ) || count( $meta ) > self::MAX_FIELDS )
                return new WP_Error( 'mad4b_semantic_structure_meta_overflow', 'Registered meta catalog is invalid or unbounded.' );
            foreach ( $meta as $key => $spec ) {
                if ( ! is_string( $key ) || strlen( $key ) > 180 || ! is_array( $spec ) )
                    return new WP_Error( 'mad4b_semantic_structure_meta_invalid', 'Invalid registered meta descriptor.' );
                $fields[] = array( 'key' => $key, 'type' => isset( $spec['type'] ) ? (string) $spec['type'] : 'unknown',
                    'single' => isset( $spec['single'] ) ? (bool) $spec['single'] : null,
                    'show_in_rest' => ! empty( $spec['show_in_rest'] ),
                    'evidence' => 'wp_registered_meta_schema',
                    // A field ending in "_id" does not prove any relation.
                    'relationship_asserted' => false );
            }
        }
        $graph = array( 'nodes' => $nodes, 'edges' => $edges, 'fields' => $fields );
        $model = self::assess( $graph, $goal );
        if ( is_wp_error( $model ) ) return $model;
        $model['site_uuid'] = (string) $site['site_uuid'];
        $model['post_type'] = $type;
        $model['observed_post_id'] = $post_id ? $post_id : null;
        $model['schema_snapshot_sha256'] = hash( 'sha256', wp_json_encode( array(
            'site_uuid' => (string) $site['site_uuid'], 'graph' => $graph ),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
        $model['additional_provider_evidence'] = array(
            'jetengine_native_relation_schema',
            'post_and_taxonomy_meta_reference_schema',
            'existing_object_term_assignments',
            'template_rendering_and_variant_selection',
            'translation_and_native_relation_identity',
        );
        $model['provider_evidence_not_assumed'] = true;
        return $model;
    }

    /**
     * Pure graph analysis for independently obtained schema observations.
     * A reasoning model can consume patterns/ambiguities and ask for missing
     * evidence; it cannot promote them into permissions or invented relations.
     */
    public static function assess( $graph, $goal = 'understand_structure' ) {
        if ( ! is_array( $graph ) || ! is_array( $graph['nodes'] ?? null ) ||
            ! is_array( $graph['edges'] ?? null ) || ! is_array( $graph['fields'] ?? null ) ||
            ! in_array( $goal, array( 'understand_structure', 'compose_content', 'analyze_reuse' ), true ) ||
            count( $graph['nodes'] ) < 1 || count( $graph['nodes'] ) > self::MAX_NODES ||
            count( $graph['edges'] ) > self::MAX_EDGES ||
            count( $graph['fields'] ) > self::MAX_FIELDS )
            return new WP_Error( 'mad4b_semantic_structure_graph_invalid', 'Bounded graph and known goal required.' );
        $nodes = array(); $patterns = array(); $ambiguities = array(); $grouped = array();
        foreach ( $graph['nodes'] as $node ) {
            if ( ! is_array( $node ) || ! is_string( $node['id'] ?? null ) ||
                ! preg_match( '/^[a-z][a-z0-9_-]*:[a-z0-9_-]{1,96}$/D', $node['id'] ) ||
                isset( $nodes[$node['id']] ) )
                return new WP_Error( 'mad4b_semantic_structure_node_invalid', 'Node identifier invalid or repeated.' );
            $nodes[$node['id']] = $node;
        }
        $seen = array();
        foreach ( $graph['edges'] as $edge ) {
            if ( ! is_array( $edge ) ||
                ! is_string( $edge['from'] ?? null ) || ! isset( $nodes[$edge['from']] ) ||
                ! is_string( $edge['to'] ?? null ) || ! isset( $nodes[$edge['to']] ) ||
                ! is_string( $edge['relation'] ?? null ) ||
                ! preg_match( '/^[a-z][a-z0-9_-]{1,63}$/D', $edge['relation'] ) ||
                ! in_array( $edge['from_to'] ?? null, array( 'one', 'many', 'unknown' ), true ) ||
                ! in_array( $edge['to_from'] ?? null, array( 'one', 'many', 'unknown' ), true ) )
                return new WP_Error( 'mad4b_semantic_structure_edge_invalid', 'Relation identity or multiplicity incomplete.' );
            $id = $edge['from'] . '|' . $edge['to'] . '|' . $edge['relation'];
            if ( isset( $seen[$id] ) )
                return new WP_Error( 'mad4b_semantic_structure_edge_duplicate', 'Duplicate relation observations need reconciliation.' );
            $seen[$id] = true;
            $verified = ! empty( $edge['verified_schema'] );
            $multi = 'many' === $edge['from_to'];
            $shared = $multi && 'many' === $edge['to_from'];
            $kind = $shared ? 'shared_reusable_reference' : ( $multi ? 'multi_item_composition' : 'single_or_unknown_reference' );
            if ( $shared && ! $verified ) $kind = 'shared_reference_hypothesis';
            $patterns[] = array(
                'kind' => $kind, 'from' => $edge['from'], 'to' => $edge['to'],
                'cardinality' => array( 'from_to' => $edge['from_to'], 'to_from' => $edge['to_from'] ),
                'evidence' => $edge['evidence'] ?? '',
                'confidence' => $verified ? 0.90 : 0.45,
                'record_assignment_verified' => ! empty( $edge['verified_record_links'] ),
                'require_record_readback' => empty( $edge['verified_record_links'] ),
                'may_copy_entity_data_into_parent' => false,
                'semantic_role_confirmed' => false,
            );
            $grouped[$edge['from']][] = $edge;
        }
        foreach ( $grouped as $from => $edges ) {
            if ( count( $edges ) < 2 ) continue;
            $patterns[] = array(
                'kind' => 'multi_source_composition', 'from' => $from,
                'source_count' => count( $edges ),
                'targets' => array_values( array_unique( array_map(
                    static function ( $edge ) { return $edge['to']; }, $edges ) ) ),
                'confidence' => 0.60, 'semantic_role_confirmed' => false,
                'requires_template_field_mapping' => true,
            );
            foreach ( $edges as $a ) foreach ( $edges as $b ) {
                if ( $a === $b ) continue;
                if ( $a['to'] === $b['to'] && $a['relation'] !== $b['relation'] ) {
                    $sig = $from . '|' . $a['to'];
                    $ambiguities[$sig] = array( 'code' => 'parallel_relationship_representations',
                        'from' => $from, 'to' => $a['to'],
                        'next_read' => 'compare_native_and_provider_relation_storage',
                        'automatic_reconciliation_allowed' => false );
                }
            }
        }
        foreach ( $graph['fields'] as $field ) {
            if ( ! is_array( $field ) || ! is_string( $field['key'] ?? null ) ||
                '' === $field['key'] || strlen( $field['key'] ) > 180 )
                return new WP_Error( 'mad4b_semantic_structure_field_invalid', 'Meta field descriptor invalid.' );
            // Array-like fields can be collections, but names alone do not
            // establish what business entities or reusable records they mean.
            if ( 'array' === ( $field['type'] ?? '' ) || false === ( $field['single'] ?? true ) ) {
                $patterns[] = array( 'kind' => 'structured_collection_candidate',
                    'field' => $field['key'], 'confidence' => 0.40,
                    'requires_field_schema_and_sample_readback' => true,
                    'relationship_not_inferred_from_field_name' => true );
            }
        }
        $stable = array( 'graph' => $graph, 'goal' => $goal );
        return array(
            'contract' => self::CONTRACT, 'goal' => $goal,
            'graph_sha256' => hash( 'sha256', wp_json_encode( $stable,
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) ),
            'nodes_observed' => count( $nodes ), 'edges_observed' => count( $seen ),
            'patterns' => $patterns, 'ambiguities' => array_values( $ambiguities ),
            'planner_instruction' => 'Ground business semantics in actual term content, stored object relationships, source-of-truth and template rendering; compare alternatives; never hardcode domain taxonomies or silently flatten reusable entities.',
            'next_read_steps' => array(
                'retrieve_actual_object_assignments_and_term_content',
                'retrieve_native_and_provider_relation_definitions',
                'inspect_registered_meta_and_content_experience_profiles',
                'inspect_frontend_template_repeater_and_variants',
                'compare_independent_same_site_readback_before_plan',
            ),
            'graph_is_not_execution_plan' => true,
            'layout_not_verified' => true, 'business_intent_not_inferred_as_fact' => true,
            'authorization_created' => false, 'mutation_performed' => false,
            'publication_authorized' => false, 'read_only' => true,
        );
    }
}
