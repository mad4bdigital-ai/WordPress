<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * ACI01 strict semantic resolution: an editable post_type is not itself
 * an approved Recipe. Native content identity must be declared by a governed
 * Content Experience Profile (and never guessed from a name or taxonomy).
 */
final class MAD4B_SCP_ACI01_Semantic_Recipe {
    const CONTRACT = 'mad4b.aci01.semantic-recipe.v1';
    const MAX_PROFILES = 64;

    public static function current( $job, $intake ) {
        if ( ! class_exists( 'MAD4B_SCP_Content_Experience_Profiles', false ) ) return self::deny( 'profile_provider_missing' );
        $inventory = MAD4B_SCP_Content_Experience_Profiles::profile_status( array() );
        return self::resolve( $job, $intake, $inventory );
    }

    /** Pure, fail-closed classification. Does not certify commercial facts. */
    public static function resolve( $job, $intake, $inventory ) {
        if ( ! is_array( $job ) || ! is_array( $intake ) || ! is_array( $inventory )
            || ( $inventory['contract'] ?? '' ) !== 'mad4b.content-experience-profiles.v1'
            || ! is_array( $inventory['profiles'] ?? null )
            || count( $inventory['profiles'] ) > self::MAX_PROFILES ) return self::deny( 'profile_inventory_invalid' );
        $kind = $job['content_type'] ?? null;
        $target = $intake['candidate']['post_type'] ?? null;
        if ( ! is_string( $kind ) || ! preg_match( '/^[a-z0-9_-]{1,64}$/D', $kind )
            || ! is_string( $target ) || ! preg_match( '/^[a-z0-9_-]{1,64}$/D', $target ) ) return self::deny( 'job_or_target_invalid' );
        $matches = array();
        foreach ( $inventory['profiles'] as $p ) {
            if ( ! is_array( $p ) || ! isset( $p['slug'], $p['post_type'] )
                || ! is_string( $p['slug'] ) || ! is_string( $p['post_type'] ) ) return self::deny( 'profile_shape_invalid' );
            if ( $p['slug'] === $kind && $p['post_type'] === $target ) $matches[] = $p;
        }
        if ( count( $matches ) > 1 ) return self::deny( 'profile_ambiguous' );
        if ( count( $matches ) === 0 ) return self::deny( 'unreviewed_job_posttype_mapping' );
        $p = $matches[0];
        if ( empty( $p['enabled'] ) || empty( $p['runtime_post_type_ready'] )
            || empty( $p['helper_catalog_match'] ) || empty( $p['authority_current'] )
            || ! empty( $p['migration_required'] )
            || ! is_int( $p['revision'] ?? null ) || $p['revision'] < 1
            || ! is_string( $p['authority_sha256'] ?? null )
            || ! preg_match( '/^[a-f0-9]{64}$/D', $p['authority_sha256'] ) )
            return self::deny( 'content_profile_uncertified' );
        $relations = ! empty( $intake['candidate']['requires_native_relation_review'] );
        $obligations = array( 'brand_context', 'fact_qa', 'source_rights', 'editorial_qa', 'seo_qa',
                              'declared_content_recipe', 'native_language_identity' );
        if ( $relations ) $obligations[] = 'native_relation_identity';
        sort( $obligations, SORT_STRING );
        $pinned = array(
            'site_scope' => $intake['scope'] ?? array(),
            'content_job_type' => $kind,
            'post_type' => $target,
            'profile_slug' => $p['slug'],
            'revision' => $p['revision'],
            'profile_authority_sha256' => $p['authority_sha256'],
            'obligations' => $obligations,
        );
        return array(
            'contract' => self::CONTRACT, 'status' => 'NEEDS_EVIDENCE',
            'mapping' => array( 'content_job_type' => $kind, 'post_type' => $target,
                'profile_slug' => $p['slug'], 'profile_revision' => $p['revision'],
                'profile_authority_sha256' => $p['authority_sha256'] ),
            'obligations' => $obligations,
            'reason_codes' => array( 'domain_recipe_facts_not_independently_certified',
                'native_translation_identity_not_verified', 'recipe_field_ownership_not_verified' ),
            'semantic_fingerprint_sha256' => hash( 'sha256', json_encode( $pinned, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) ),
            'authorizing' => false, 'mutation_performed' => false, 'trusted_authority_verified' => false,
        );
    }

    private static function deny( $reason ) {
        return array( 'contract' => self::CONTRACT, 'status' => 'DENIED',
            'reason_codes' => array( $reason ), 'authorizing' => false, 'mutation_performed' => false );
    }
}
