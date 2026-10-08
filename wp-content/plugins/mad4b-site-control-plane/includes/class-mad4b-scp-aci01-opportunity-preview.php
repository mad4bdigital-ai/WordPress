<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Read-only ACI01 opportunity/blueprint candidate, reusing prior governed reads. */
final class MAD4B_SCP_ACI01_Opportunity_Preview {
    const CONTRACT = 'mad4b.aci01.opportunity-preview.v1';
    const MAX_GOAL_BYTES = 500;
    private static $booted = false;

    public static function boot() {
        if ( self::$booted ) return;
        self::$booted = true;
        add_action( 'wp_abilities_api_init', array( __CLASS__, 'register_ability' ), 44 );
    }
    public static function register_ability() {
        if ( ! function_exists( 'wp_register_ability' ) ) return;
        if ( function_exists( 'wp_has_ability' ) && wp_has_ability( 'mad4b/aci01-opportunity-preview' ) ) return;
        wp_register_ability( 'mad4b/aci01-opportunity-preview', array(
            'label' => 'ACI01 Opportunity and Blueprint Candidate',
            'description' => 'Compose scoped read-only intake and existing evidence into a review-only hypothesis.',
            'category' => 'mad4b-read',
            'execute_callback' => array( __CLASS__, 'preview' ),
            'permission_callback' => array( 'MAD4B_SCP_Policy', 'can_read' ),
            'input_schema' => array(
                'type' => 'object', 'additionalProperties' => false,
                'properties' => array(
                    'job_id' => array( 'type' => 'string', 'pattern' => '^[a-fA-F0-9-]{36}$' ),
                    'post_type' => array( 'type' => 'string', 'maxLength' => 64 ),
                    'goal' => array( 'type' => 'string', 'maxLength' => self::MAX_GOAL_BYTES ),
                    'artifact_ids' => array( 'type' => 'array', 'maxItems' => 12,
                        'items' => array( 'type' => 'string', 'pattern' => '^[a-fA-F0-9-]{36}$' ) ),
                ),
                'required' => array( 'job_id', 'post_type', 'goal' ),
            ),
            'output_schema' => array( 'type' => 'object', 'additionalProperties' => true ),
            'meta' => array( 'public' => false, 'show_in_rest' => false,
                'mcp' => array( 'public' => false, 'type' => 'tool', 'surface' => 'read' ),
                'annotations' => array( 'readonly' => true, 'destructive' => false, 'idempotent' => true ) ),
        ) );
    }
    public static function preview( $input = array() ) {
        if ( ! class_exists( 'MAD4B_SCP_Policy' ) || ! MAD4B_SCP_Policy::can_read() ) return self::denied( 'read_permission_missing' );
        if ( ! class_exists( 'MAD4B_SCP_ACI01_Intake_Preview' ) ||
             ! class_exists( 'MAD4B_SCP_ACI01_Evidence_Preview' ) ) return self::denied( 'existing_preview_providers_missing' );
        if ( ! is_array( $input ) ) return self::denied( 'input_invalid' );
        foreach ( $input as $key => $value ) if ( ! in_array( $key, array( 'job_id', 'post_type', 'goal', 'artifact_ids' ), true ) ) return self::denied( 'unknown_input' );
        if ( ! isset( $input['job_id'], $input['post_type'], $input['goal'] ) ||
             ! is_string( $input['job_id'] ) || ! is_string( $input['post_type'] ) ||
             ! is_string( $input['goal'] ) || '' === trim( $input['goal'] ) ||
             strlen( $input['goal'] ) > self::MAX_GOAL_BYTES ) return self::denied( 'goal_or_target_invalid' );
        $evidence = MAD4B_SCP_ACI01_Evidence_Preview::preview( array(
            'job_id' => $input['job_id'],
            'artifact_ids' => isset( $input['artifact_ids'] ) ? $input['artifact_ids'] : array(),
        ) );
        if ( ! is_array( $evidence ) || ! isset( $evidence['status'] ) ||
             'DENIED' === $evidence['status'] ) return self::denied( 'evidence_reader_denied' );
        if ( ! isset( $evidence['scope']['brand_id'], $evidence['scope']['locale'],
                    $evidence['scope']['market'] ) ) return self::denied( 'evidence_scope_missing' );
        // Never allow the caller to choose a different brand/market than the ContentJob.
        $intake = MAD4B_SCP_ACI01_Intake_Preview::preview( array(
            'post_type' => $input['post_type'],
            'brand_id' => $evidence['scope']['brand_id'],
            'locale' => $evidence['scope']['locale'],
            'market' => $evidence['scope']['market'],
        ) );
        if ( ! class_exists( 'MAD4B_SCP_ACI01_Semantic_Recipe', false )
            || ! class_exists( 'MAD4B_SCP_ACI01_Runtime_Binding', false ) ) return self::denied( 'semantic_or_binding_provider_missing' );
        $semantic = MAD4B_SCP_ACI01_Semantic_Recipe::current(
            array( 'content_type' => $evidence['scope']['content_type'] ?? '' ), $intake );
        $now = MAD4B_SCP_ACI01_Runtime_Binding::current();
        if ( is_wp_error( $now ) || ! MAD4B_SCP_ACI01_Runtime_Binding::same( $intake['scope']['binding'] ?? null, $now )
            || ! MAD4B_SCP_ACI01_Runtime_Binding::same( $evidence['scope']['binding'] ?? null, $now ) )
            return self::denied( 'runtime_snapshot_changed' );
        return self::compile( $intake, $evidence, $input['goal'], $semantic );
    }

    /** Pure candidate compiler. No generation, external tool dispatch or persistence. */
    public static function compile( $intake, $evidence, $goal, $semantic = null ) {
        if ( ! is_array( $intake ) || ! is_array( $evidence ) || ! is_string( $goal ) ||
             '' === trim( $goal ) || strlen( $goal ) > self::MAX_GOAL_BYTES ) return self::denied( 'candidate_inputs_invalid' );
        if ( ! isset( $intake['status'], $evidence['status'] ) ||
             'DENIED' === $intake['status'] || 'DENIED' === $evidence['status'] ) return self::denied( 'upstream_preflight_denied' );
        if ( ! isset( $intake['contract'], $evidence['contract'] ) ||
             'mad4b.aci01.intake-preview.v1' !== $intake['contract'] ||
             'mad4b.aci01.evidence-preview.v1' !== $evidence['contract'] ||
             ! in_array( $intake['status'], array( 'NEEDS_EVIDENCE', 'NEEDS_REVIEW' ), true ) ||
             'NEEDS_EVIDENCE' !== $evidence['status'] ||
             ! empty( $intake['authorizing'] ) || ! empty( $evidence['authorizing'] ) ||
             ! empty( $intake['mutation_performed'] ) || ! empty( $evidence['mutation_performed'] ) ) {
            return self::denied( 'upstream_preflight_contract_invalid' );
        }
        if ( ! isset( $intake['scope'], $evidence['scope'], $intake['candidate'],
                    $intake['plan_fingerprint_sha256'], $evidence['preview_sha256'] ) ||
             ! is_array( $intake['scope'] ) || ! is_array( $evidence['scope'] ) ||
             ! is_array( $intake['candidate'] ) ) return self::denied( 'upstream_contract_incomplete' );
        foreach ( array( 'site_uuid', 'origin', 'environment', 'brand_id' ) as $key ) {
            if ( ! isset( $intake['scope'][$key], $evidence['scope'][$key] ) ||
                 ! is_string( $intake['scope'][$key] ) || ! hash_equals( $intake['scope'][$key], $evidence['scope'][$key] ) ) {
                return self::denied( 'site_or_brand_snapshot_mismatch' );
            }
        }
        if ( ! isset( $intake['scope']['locale'], $intake['scope']['market'],
                    $evidence['scope']['locale'], $evidence['scope']['market'] ) ||
             $intake['scope']['locale'] !== $evidence['scope']['locale'] ||
             $intake['scope']['market'] !== $evidence['scope']['market'] ) return self::denied( 'locale_market_mismatch' );
        if ( ! class_exists( 'MAD4B_SCP_ACI01_Runtime_Binding', false )
            || ! MAD4B_SCP_ACI01_Runtime_Binding::same(
                $intake['scope']['binding'] ?? null, $evidence['scope']['binding'] ?? null ) )
            return self::denied( 'runtime_binding_mismatch' );
        if ( ! is_array( $semantic ) || ( $semantic['contract'] ?? '' ) !== 'mad4b.aci01.semantic-recipe.v1'
            || ( $semantic['status'] ?? '' ) !== 'NEEDS_EVIDENCE'
            || ! empty( $semantic['authorizing'] )
            || ! is_array( $semantic['mapping'] ?? null )
            || ! is_array( $semantic['obligations'] ?? null )
            || ! is_string( $semantic['semantic_fingerprint_sha256'] ?? null )
            || ! preg_match( '/^[a-f0-9]{64}$/D', $semantic['semantic_fingerprint_sha256'] ) )
            return self::denied( 'semantic_mapping_missing_or_invalid' );
        if ( ( $semantic['mapping']['post_type'] ?? null ) !== ( $intake['candidate']['post_type'] ?? null )
            || ( $semantic['mapping']['content_job_type'] ?? null ) !== ( $evidence['scope']['content_type'] ?? null ) )
            return self::denied( 'semantic_job_target_mismatch' );
        foreach ( array( $intake['plan_fingerprint_sha256'], $evidence['preview_sha256'] ) as $fingerprint ) {
            if ( ! is_string( $fingerprint ) || ! preg_match( '/^[a-f0-9]{64}$/', $fingerprint ) ) return self::denied( 'source_fingerprint_invalid' );
        }
        $post_type = isset( $intake['candidate']['post_type'] ) ? $intake['candidate']['post_type'] : '';
        if ( ! is_string( $post_type ) || '' === $post_type ) return self::denied( 'exact_content_type_required' );
        $relation_review = ! empty( $intake['candidate']['requires_native_relation_review'] );
        $required = array( 'business_goal', 'audience_intent', 'evidence_source_rights',
                           'approved_brand_context', 'factual_claims', 'editorial_qa' );
        if ( $relation_review ) $required[] = 'native_relation_identity';
        foreach ( $semantic['obligations'] as $obligation ) {
            if ( ! is_string( $obligation ) || ! preg_match( '/^[a-z0-9_.-]{1,80}$/D', $obligation ) )
                return self::denied( 'semantic_obligation_invalid' );
            $required[] = $obligation;
        }
        $required = array_values( array_unique( $required ) );
        $reasons = array();
        foreach ( array( $intake, $evidence ) as $part ) {
            if ( ! isset( $part['reason_codes'] ) || ! is_array( $part['reason_codes'] ) ||
                 count( $part['reason_codes'] ) > 100 ) return self::denied( 'upstream_reasons_invalid' );
            foreach ( $part['reason_codes'] as $r ) {
                if ( ! is_string( $r ) || ! preg_match( '/^[a-z0-9_.-]{1,120}$/', $r ) ) return self::denied( 'reason_code_invalid' );
                $reasons[] = $r;
            }
        }
        foreach ( $semantic['reason_codes'] ?? array() as $reason ) {
            if ( ! is_string( $reason ) || ! preg_match( '/^[a-z0-9_.-]{1,120}$/D', $reason ) )
                return self::denied( 'semantic_reason_invalid' );
            $reasons[] = $reason;
        }
        $reasons[] = 'independent_editorial_approval_required';
        $reasons[] = 'external_provider_and_rights_not_certified';
        sort( $required, SORT_STRING );
        $reasons = array_values( array_unique( $reasons ) );
        sort( $reasons, SORT_STRING );
        $material = array( 'scope' => $intake['scope'],
            'post_type' => $post_type,
            'goal_sha256' => hash( 'sha256', trim( $goal ) ),
            'source_previews' => array( $intake['plan_fingerprint_sha256'], $evidence['preview_sha256'] ),
            'required_sections' => $required, 'reason_codes' => $reasons,
            'semantic_sha256' => $semantic['semantic_fingerprint_sha256'] );
        return array( 'contract' => self::CONTRACT, 'status' => 'NEEDS_EVIDENCE',
            'review_status' => 'NEEDS_REVIEW',
            'candidate_kind' => 'OpportunityHypothesis_BlueprintCandidate',
            'site_scope' => $intake['scope'], 'target_post_type' => $post_type,
            'goal_sha256' => $material['goal_sha256'],
            'evidence_fingerprints' => $material['source_previews'],
            'semantic_fingerprint_sha256' => $semantic['semantic_fingerprint_sha256'],
            'recipe_mapping' => $semantic['mapping'],
            'required_blueprint_sections' => $required,
            'reason_codes' => $reasons,
            'handoff' => 'EXISTING_MAD4B_CONTENT_INTELLIGENCE_AFTER_APPROVAL',
            'candidate_sha256' => hash( 'sha256', json_encode( $material, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) ),
            'synthetic_candidate_only' => true,
            'trusted_authority_verified' => false, 'authorizing' => false,
            'eligible_for_mutation' => false, 'mutation_performed' => false,
            'provider_calls_performed' => false, 'paid_calls' => 0 );
    }

    private static function denied( $reason ) {
        return array( 'contract' => self::CONTRACT, 'status' => 'DENIED',
            'reason_codes' => array( $reason ), 'authorizing' => false,
            'eligible_for_mutation' => false, 'mutation_performed' => false,
            'provider_calls_performed' => false, 'paid_calls' => 0 );
    }
}
