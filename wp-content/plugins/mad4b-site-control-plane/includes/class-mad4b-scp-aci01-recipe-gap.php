<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * ACI01 dynamic domain recipe requirement checker.
 * A metadata declaration is a proposal, never an independently certified fact.
 * Callers must separately certify profile ownership, evidence, and rights.
 */
final class MAD4B_SCP_ACI01_Recipe_Gap {
    const CONTRACT = 'mad4b.aci01.recipe-gap.v1';
    const MAX_REQUIREMENTS = 40;
    const MAX_RECEIPTS = 80;

    /**
     * Scope a stored declaration through the existing governed profile.
     * A profile receipt is a declaration, not editorial/fact/rights approval.
     * No caller-supplied recipe payload can self-certify this read.
     */
    public static function resolve_current( $semantic, $scope ) {
        if ( ! is_array( $semantic ) || ! is_array( $semantic['mapping'] ?? null )
            || ! is_array( $scope ) || ! class_exists( 'MAD4B_SCP_Content_Experience_Profiles', false ) )
            return self::resolved_denied( 'recipe_profile_provider_unavailable' );
        $mapping = $semantic['mapping'];
        foreach ( array( 'site_uuid', 'brand_id', 'locale', 'market' ) as $field )
            if ( ! isset( $scope[$field] ) || ! is_string( $scope[$field] ) || $scope[$field] === '' )
                return self::resolved_denied( 'recipe_scope_missing' );
        foreach ( array( 'profile_slug', 'profile_revision', 'profile_authority_sha256' ) as $field )
            if ( ! isset( $mapping[$field] ) ) return self::resolved_denied( 'recipe_profile_identity_missing' );
        $status = MAD4B_SCP_Content_Experience_Profiles::profile_status( array() );
        if ( ! is_array( $status ) || ( $status['contract'] ?? '' ) !== 'mad4b.content-experience-profiles.v1'
            || ! is_array( $status['profiles'] ?? null ) )
            return self::resolved_denied( 'recipe_profile_status_unavailable' );
        $matches = array();
        foreach ( $status['profiles'] as $item ) {
            if ( ! is_array( $item ) ) return self::resolved_denied( 'recipe_profile_status_malformed' );
            if ( ( $item['slug'] ?? null ) === $mapping['profile_slug'] ) $matches[] = $item;
        }
        if ( count( $matches ) !== 1 ) return self::resolved_denied( 'recipe_profile_status_ambiguous_or_missing' );
        $selected = $matches[0];
        if ( true !== ( $selected['authority_current'] ?? null ) || empty( $selected['enabled'] )
            || true !== ( $selected['runtime_post_type_ready'] ?? null )
            || true !== ( $selected['helper_catalog_match'] ?? null )
            || ! is_int( $selected['revision'] ?? null )
            || $selected['revision'] !== $mapping['profile_revision']
            || ! is_string( $selected['authority_sha256'] ?? null )
            || ! hash_equals( $mapping['profile_authority_sha256'], $selected['authority_sha256'] ) )
            return self::resolved_denied( 'recipe_profile_authority_changed' );
        $profile = MAD4B_SCP_Content_Experience_Profiles::profile( $mapping['profile_slug'] );
        if ( ! is_array( $profile ) || ( $profile['slug'] ?? null ) !== $selected['slug']
            || ( $profile['revision'] ?? null ) !== $selected['revision']
            || ( $profile['post_type'] ?? null ) !== ( $mapping['post_type'] ?? null )
            || ! is_string( $profile['authority_sha256'] ?? null )
            || ! hash_equals( $selected['authority_sha256'], $profile['authority_sha256'] ) )
            return self::resolved_denied( 'recipe_profile_source_changed' );
        $variants = $profile['aci01_recipe_variants'] ?? array();
        if ( ! is_array( $variants ) || count( $variants ) > 24 )
            return self::resolved_denied( 'recipe_variant_registry_invalid' );
        $found = array();
        $seen = array();
        foreach ( $variants as $variant ) {
            if ( ! is_array( $variant ) || array_diff( array_keys( $variant ), array(
                'site_uuid', 'brand_id', 'locale', 'market', 'requirements' ) ) )
                return self::resolved_denied( 'recipe_variant_invalid' );
            foreach ( array( 'site_uuid', 'brand_id', 'locale', 'market' ) as $key )
                if ( ! isset( $variant[$key] ) || ! is_string( $variant[$key] ) )
                    return self::resolved_denied( 'recipe_variant_scope_invalid' );
            $identity = implode( '|', array( $variant['site_uuid'], $variant['brand_id'], $variant['locale'], $variant['market'] ) );
            if ( isset( $seen[$identity] ) ) return self::resolved_denied( 'recipe_variant_duplicate' );
            $seen[$identity] = true;
            if ( ! is_array( $variant['requirements'] ?? null ) || ! count( $variant['requirements'] )
                || count( $variant['requirements'] ) > self::MAX_REQUIREMENTS )
                return self::resolved_denied( 'recipe_variant_requirements_invalid' );
            $requirements = array();
            foreach ( $variant['requirements'] as $key ) {
                if ( ! is_string( $key ) || ! preg_match( '/^[a-z][a-z0-9_.-]{1,79}$/D', $key )
                    || isset( $requirements[$key] ) )
                    return self::resolved_denied( 'recipe_variant_requirement_invalid' );
                $requirements[$key] = true;
            }
            if ( $variant['site_uuid'] === $scope['site_uuid']
                && $variant['brand_id'] === $scope['brand_id']
                && $variant['locale'] === $scope['locale']
                && $variant['market'] === $scope['market'] ) $found[] = $variant;
        }
        $fresh = MAD4B_SCP_Content_Experience_Profiles::profile_status( array() );
        if ( ! is_array( $fresh ) || $status !== $fresh )
            return self::resolved_denied( 'recipe_profile_changed_during_read' );
        if ( count( $found ) === 0 ) return array( 'status' => 'MISSING', 'recipe' => null,
            'reason_codes' => array( 'scoped_content_recipe_not_configured' ) );
        if ( count( $found ) !== 1 ) return self::resolved_denied( 'recipe_variant_ambiguous' );
        return array( 'status' => 'FOUND',
            'recipe' => array( 'contract' => 'mad4b.aci01.recipe-declaration.v1',
                'profile_slug' => $mapping['profile_slug'],
                'profile_revision' => $mapping['profile_revision'],
                'profile_authority_sha256' => $mapping['profile_authority_sha256'],
                'requirements' => $found[0]['requirements'] ),
            'reason_codes' => array( 'independent_recipe_review_and_fact_authority_pending' ) );
    }

    private static function resolved_denied( $reason ) {
        return array( 'status' => 'DENIED', 'recipe' => null,
            'reason_codes' => array( $reason ) );
    }

    public static function evaluate( $semantic, $recipe, $evidence ) {
        if ( ! is_array( $semantic ) || ( $semantic['contract'] ?? '' ) !== 'mad4b.aci01.semantic-recipe.v1'
            || ( $semantic['status'] ?? '' ) !== 'NEEDS_EVIDENCE'
            || ! is_array( $semantic['mapping'] ?? null )
            || ! is_string( $semantic['semantic_fingerprint_sha256'] ?? null )
            || ! preg_match( '/^[a-f0-9]{64}$/D', $semantic['semantic_fingerprint_sha256'] ) )
            return self::deny( 'semantic_authority_missing' );
        if ( ! is_array( $recipe ) || ( $recipe['contract'] ?? '' ) !== 'mad4b.aci01.recipe-declaration.v1' )
            return self::unconfigured( $semantic, 'recipe_declaration_missing' );
        $mapping = $semantic['mapping'];
        if ( ! isset( $recipe['profile_slug'], $recipe['profile_revision'],
                     $recipe['profile_authority_sha256'], $recipe['requirements'] )
            || ! is_string( $recipe['profile_slug'] ) || ! is_int( $recipe['profile_revision'] )
            || ! is_string( $recipe['profile_authority_sha256'] )
            || ! hash_equals( (string) ( $mapping['profile_slug'] ?? '' ), $recipe['profile_slug'] )
            || ( $mapping['profile_revision'] ?? 0 ) !== $recipe['profile_revision']
            || ! hash_equals( (string) ( $mapping['profile_authority_sha256'] ?? '' ), $recipe['profile_authority_sha256'] )
            || ! is_array( $recipe['requirements'] )
            || count( $recipe['requirements'] ) < 1
            || count( $recipe['requirements'] ) > self::MAX_REQUIREMENTS ) return self::deny( 'recipe_profile_binding_invalid' );
        $requirements = array();
        foreach ( $recipe['requirements'] as $key ) {
            if ( ! is_string( $key ) || ! preg_match( '/^[a-z][a-z0-9_.-]{1,79}$/D', $key ) )
                return self::deny( 'recipe_requirement_invalid' );
            if ( isset( $requirements[$key] ) ) return self::deny( 'duplicate_requirement' );
            $requirements[$key] = true;
        }
        if ( ! is_array( $evidence ) || count( $evidence ) > self::MAX_RECEIPTS )
            return self::deny( 'receipt_shape_invalid' );
        $observed = array();
        foreach ( $evidence as $receipt ) {
            if ( ! is_array( $receipt ) || ! isset( $receipt['requirement'], $receipt['source_sha256'],
                        $receipt['semantic_sha256'] )
                || ! is_string( $receipt['requirement'] )
                || ! is_string( $receipt['source_sha256'] )
                || ! is_string( $receipt['semantic_sha256'] )
                || ! preg_match( '/^[a-f0-9]{64}$/D', $receipt['source_sha256'] )
                || ! hash_equals( $semantic['semantic_fingerprint_sha256'], $receipt['semantic_sha256'] )
                || ! isset( $requirements[$receipt['requirement']] )
                || isset( $observed[$receipt['requirement']] ) ) return self::deny( 'receipt_cross_scope_or_unexpected' );
            $observed[$receipt['requirement']] = $receipt['source_sha256'];
        }
        $missing = array_values( array_diff( array_keys( $requirements ), array_keys( $observed ) ) );
        sort( $missing, SORT_STRING );
        $keys = array_keys( $requirements );
        sort( $keys, SORT_STRING );
        return array(
            'contract' => self::CONTRACT, 'status' => 'NEEDS_EVIDENCE',
            'review_status' => 'NEEDS_REVIEW',
            'required_requirement_keys' => $keys, 'missing_requirement_keys' => $missing,
            'observed_receipt_count' => count( $observed ),
            'source_receipts_independently_certified' => false,
            'reason_codes' => array( 'provider_and_rights_certification_pending',
                'recipe_authority_independent_review_pending',
                'native_identity_and_editorial_gate_pending' ),
            'candidate_sha256' => hash( 'sha256', json_encode( array(
                $semantic['semantic_fingerprint_sha256'], $recipe, $observed, $missing
            ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) ),
            'authorizing' => false, 'eligible_for_mutation' => false,
            'mutation_performed' => false, 'paid_calls' => 0,
        );
    }
    private static function unconfigured( $semantic, $reason ) {
        return array( 'contract' => self::CONTRACT, 'status' => 'NEEDS_EVIDENCE',
            'review_status' => 'NEEDS_REVIEW',
            'missing_requirement_keys' => array( 'reviewed_domain_recipe' ),
            'reason_codes' => array( $reason ),
            'semantic_sha256' => $semantic['semantic_fingerprint_sha256'],
            'authorizing' => false, 'eligible_for_mutation' => false,
            'mutation_performed' => false, 'paid_calls' => 0 );
    }
    private static function deny( $reason ) {
        return array( 'contract' => self::CONTRACT, 'status' => 'DENIED',
            'reason_codes' => array( $reason ), 'authorizing' => false,
            'eligible_for_mutation' => false, 'mutation_performed' => false,
            'paid_calls' => 0 );
    }
}
