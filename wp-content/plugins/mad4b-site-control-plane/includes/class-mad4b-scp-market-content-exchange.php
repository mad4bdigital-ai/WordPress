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
        $post_type = isset( $input['post_type'] ) ? sanitize_key( (string) $input['post_type'] ) : '';
        if ( '' !== $post_type && ( ! function_exists( 'post_type_exists' ) || ! post_type_exists( $post_type ) ) )
            return self::error( 'mad4b_competitor_destination_missing', 'Selected destination post type is not registered.' );
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
            'destination_post_type' => '' === $post_type ? 'discover_at_runtime' : $post_type,
            'destination_taxonomies' => '' === $post_type || ! function_exists( 'get_object_taxonomies' )
                ? array() : array_values( (array) get_object_taxonomies( $post_type ) ),
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

    // Tourism-specific CPT/user mapping and DMC exchange deliberately live in
    // the All Royal site addon, never in the reusable Control Plane core.

    public static function assistant_route( $input = array() ) {
        $input = is_array( $input ) ? $input : array();
        $registry = MAD4B_SCP_Market_Growth_Policies::current();
        if ( is_wp_error( $registry ) ) return $registry;
        $role = isset( $input['role'] ) ? sanitize_key( (string) $input['role'] ) : '';
        if ( ! in_array( $role, array( 'researcher', 'writer', 'critic', 'reviewer', 'recovery' ), true ) )
            return self::error( 'mad4b_market_role_unknown', 'Requested assistant role is invalid.' );
        $routes = array();
        foreach ( $registry['assistant_roles'] as $id => $entry ) {
            if ( ! is_array( $entry ) || ! isset( $entry['role'], $entry['skill_name'] )
                || $entry['role'] !== $role ) continue;
            $routes[] = array( 'route_id' => $id, 'skill_name' => $entry['skill_name'] );
        }
        $cert = class_exists( 'MAD4B_SCP_Skill_Runtime_Certification' )
            ? MAD4B_SCP_Skill_Runtime_Certification::current_status() : array();
        $ready = is_array( $cert ) && ! empty( $cert['ready'] )
            && ! empty( $cert['external_client_snapshot_verified'] )
            && ! empty( $cert['build_identity_current'] ) && empty( $cert['historical_evidence_only'] );
        // Configuration is not proof a Skill exists. Select only an enabled
        // exact-name entry in the live registry, not an arbitrary label.
        $registered = class_exists( 'MAD4B_SCP_Skill_Registry' )
            ? MAD4B_SCP_Skill_Registry::list_skills( array() ) : array();
        $resolved = array();
        foreach ( $routes as $route ) {
            foreach ( is_array( $registered ) ? $registered : array() as $skill ) {
                if ( is_array( $skill ) && ! empty( $skill['enabled'] ) &&
                    isset( $skill['name'] ) && hash_equals( (string) $skill['name'], (string) $route['skill_name'] ) ) {
                    $resolved[] = $route;
                    break;
                }
            }
        }
        return array( 'contract' => 'mad4b.market-assistant-route.v1',
            'role' => $role, 'configured_candidates' => $routes,
            'registered_enabled_candidates' => $resolved,
            'managed_skills_runtime_ready' => $ready,
            'selected_for_research_or_draft' => $ready && ! empty( $resolved ) ? $resolved[0] : null,
            'fallback' => 'human_agent_with_independent_capability_review',
            'exact_agent_identity_verified' => false,
            'exact_write_grant_verified' => false,
            'writer_cannot_self_review' => true,
            'mutation_authorized' => false, 'read_only' => true,
            'mutation_performed' => false );
    }

}
