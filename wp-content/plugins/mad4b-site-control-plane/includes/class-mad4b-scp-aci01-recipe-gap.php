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
