<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * ACI01 Slice 02: a bounded evidence-read projection over existing Feature 007
 * ContentJob, ContextPack and Artifact services. No provider calls or mutations.
 */
final class MAD4B_SCP_ACI01_Evidence_Preview {
    const CONTRACT = 'mad4b.aci01.evidence-preview.v1';
    const MAX_REFS = 12;
    const MAX_AGE_SECONDS = 604800;
    private static $booted = false;

    public static function boot() {
        if ( self::$booted ) return;
        self::$booted = true;
        add_action( 'wp_abilities_api_init', array( __CLASS__, 'register_ability' ), 43 );
    }

    public static function register_ability() {
        if ( ! function_exists( 'wp_register_ability' ) ) return;
        if ( function_exists( 'wp_has_ability' ) && wp_has_ability( 'mad4b/aci01-evidence-preview' ) ) return;
        wp_register_ability( 'mad4b/aci01-evidence-preview', array(
            'label' => 'ACI01 Read-Only Evidence Preflight',
            'description' => 'Read current scoped ContentJob/ContextPack and bounded research artifact fingerprints without materializing or spending.',
            'category' => 'mad4b-read',
            'execute_callback' => array( __CLASS__, 'preview' ),
            'permission_callback' => array( 'MAD4B_SCP_Policy', 'can_read' ),
            'input_schema' => array(
                'type' => 'object',
                'properties' => array(
                    'job_id' => array( 'type' => 'string', 'pattern' => '^[a-fA-F0-9-]{36}$' ),
                    'artifact_ids' => array( 'type' => 'array', 'maxItems' => self::MAX_REFS,
                        'items' => array( 'type' => 'string', 'pattern' => '^[a-fA-F0-9-]{36}$' ) ),
                    'max_age_seconds' => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => self::MAX_AGE_SECONDS ),
                ),
                'required' => array( 'job_id' ), 'additionalProperties' => false,
            ),
            'output_schema' => array( 'type' => 'object', 'additionalProperties' => true ),
            'meta' => array( 'public' => false, 'show_in_rest' => false,
                'mcp' => array( 'public' => false, 'type' => 'tool', 'surface' => 'read' ),
                'annotations' => array( 'readonly' => true, 'destructive' => false, 'idempotent' => true ) ),
        ) );
    }

    public static function preview( $input = array() ) {
        if ( ! class_exists( 'MAD4B_SCP_Policy' ) || ! MAD4B_SCP_Policy::can_read() ) return self::denied( 'read_permission_missing' );
        foreach ( array( 'MAD4B_SCP_Site_Profile', 'MAD4B_SCP_Content_Jobs', 'MAD4B_SCP_Context_Pack', 'MAD4B_SCP_Artifacts' ) as $owner ) {
            if ( ! class_exists( $owner ) ) return self::denied( 'existing_read_provider_unavailable' );
        }
        if ( ! class_exists( 'MAD4B_SCP_ACI01_Runtime_Binding', false ) ) return self::denied( 'binding_provider_unavailable' );
        $binding = MAD4B_SCP_ACI01_Runtime_Binding::current();
        if ( is_wp_error( $binding ) || ! is_array( $binding ) ) return self::denied( 'site_binding_unverified' );
        if ( ! is_array( $input ) || ! isset( $input['job_id'] ) || ! self::uuid( $input['job_id'] ) ) return self::denied( 'job_identity_invalid' );
        foreach ( $input as $key => $value ) if ( ! in_array( $key, array( 'job_id', 'artifact_ids', 'max_age_seconds' ), true ) ) return self::denied( 'unknown_input' );
        $refs = isset( $input['artifact_ids'] ) ? $input['artifact_ids'] : array();
        if ( ! is_array( $refs ) || count( $refs ) > self::MAX_REFS ) return self::denied( 'references_unbounded' );
        foreach ( $refs as $ref ) if ( ! self::uuid( $ref ) ) return self::denied( 'artifact_identity_invalid' );
        if ( count( array_unique( array_map( 'strtolower', $refs ) ) ) !== count( $refs ) ) return self::denied( 'duplicate_artifact_references' );
        $age = isset( $input['max_age_seconds'] ) ? $input['max_age_seconds'] : self::MAX_AGE_SECONDS;
        if ( ! is_int( $age ) || $age < 1 || $age > self::MAX_AGE_SECONDS ) return self::denied( 'freshness_budget_invalid' );
        $job_id = strtolower( $input['job_id'] );
        $result = MAD4B_SCP_Content_Jobs::get_job( array( 'job_id' => $job_id ) );
        if ( is_wp_error( $result ) || ! isset( $result['job'] ) || ! is_array( $result['job'] ) ) return self::denied( 'governed_job_unavailable' );
        $context = MAD4B_SCP_Context_Pack::preview( array( 'job_id' => $job_id ) );
        if ( is_wp_error( $context ) || ! is_array( $context ) ) return self::denied( 'governed_context_unavailable' );
        $rows = array();
        foreach ( $refs as $ref ) {
            // Existing registry verifies site identity in its own SELECT.
            $artifact = MAD4B_SCP_Artifacts::get_artifact( array( 'artifact_id' => strtolower( $ref ) ) );
            if ( is_wp_error( $artifact ) || ! isset( $artifact['artifact'] ) || ! is_array( $artifact['artifact'] ) ) return self::denied( 'artifact_not_available_for_site' );
            $rows[] = $artifact['artifact'];
        }
        $scope = array(
            'site_uuid' => MAD4B_SCP_Site_Profile::site_uuid(),
            'origin' => MAD4B_SCP_Site_Profile::site_origin(),
            'environment' => $binding['environment'],
            'binding' => $binding,
        );
        $after = MAD4B_SCP_ACI01_Runtime_Binding::current();
        if ( is_wp_error( $after ) || ! MAD4B_SCP_ACI01_Runtime_Binding::same( $binding, $after ) )
            return self::denied( 'runtime_changed_during_evidence_read' );
        return self::project( $job_id, $result['job'], $context, $rows, $age, $scope );
    }

    /** Pure projection. Untrusted read responses cannot self-certify authority. */
    public static function project( $job_id, $job, $context, $artifacts, $age, $scope, $now = null ) {
        if ( ! self::uuid( $job_id ) || ! is_array( $job ) || ! is_array( $context ) ||
            ! is_array( $artifacts ) || count( $artifacts ) > self::MAX_REFS ||
            ! is_int( $age ) || $age < 1 || $age > self::MAX_AGE_SECONDS || ! is_array( $scope ) ) return self::denied( 'projection_shape_invalid' );
        if ( ! self::uuid( isset( $scope['site_uuid'] ) ? $scope['site_uuid'] : '' ) ||
             ! isset( $scope['origin'] ) || ! is_string( $scope['origin'] ) || 0 !== strpos( $scope['origin'], 'https://' ) ||
             ! isset( $scope['environment'] ) || ! in_array( $scope['environment'], array( 'local', 'staging', 'production', 'development', 'disposable' ), true ) ) return self::denied( 'site_scope_invalid' );
        if ( isset( $scope['binding'] ) &&
             ( ! class_exists( 'MAD4B_SCP_ACI01_Runtime_Binding', false )
               || ! MAD4B_SCP_ACI01_Runtime_Binding::is_valid( $scope['binding'] )
               || $scope['binding']['site_uuid'] !== $scope['site_uuid']
               || $scope['binding']['origin'] !== $scope['origin']
               || $scope['binding']['environment'] !== $scope['environment'] ) )
            return self::denied( 'binding_scope_mismatch' );
        if ( ! isset( $job['job_id'] ) || ! is_string( $job['job_id'] ) || strtolower( $job['job_id'] ) !== strtolower( $job_id ) ||
             ! isset( $job['brand_id'], $job['language'], $job['country'], $job['content_type'] ) ) return self::denied( 'job_scope_mismatch' );
        if ( ! isset( $context['job_id'], $context['brand_id'], $context['language'], $context['country'] ) ||
             ! is_string( $context['job_id'] ) || strtolower( $context['job_id'] ) !== strtolower( $job_id ) ||
             (string) $context['brand_id'] !== (string) $job['brand_id'] ||
             (string) $context['language'] !== (string) $job['language'] ||
             (string) $context['country'] !== (string) $job['country'] ) return self::denied( 'context_job_binding_mismatch' );
        if ( ! isset( $context['missing_required_classes'] ) || ! is_array( $context['missing_required_classes'] ) ||
             count( $context['missing_required_classes'] ) > 64 ) return self::denied( 'context_contract_invalid' );
        $now = null === $now ? time() : $now;
        if ( ! is_int( $now ) || $now < 1 ) return self::denied( 'observation_clock_invalid' );
        $missing = array();
        foreach ( $context['missing_required_classes'] as $name ) {
            if ( ! is_string( $name ) || ! preg_match( '/^[a-z0-9_.-]{1,80}$/', $name ) ) return self::denied( 'context_class_invalid' );
            $missing[] = $name;
        }
        $missing = array_values( array_unique( $missing ) );
        sort( $missing, SORT_STRING );
        $reasons = array();
        if ( count( $missing ) ) $reasons[] = 'required_brand_context_missing';
        if ( empty( $context['ready'] ) ) $reasons[] = 'context_not_ready';
        $summaries = array();
        $seen = array();
        foreach ( $artifacts as $row ) {
            if ( ! is_array( $row ) || ! isset( $row['artifact_id'], $row['job_id'], $row['artifact_type'], $row['payload'], $row['status'] ) ||
                 ! self::uuid( $row['artifact_id'] ) || ! is_string( $row['job_id'] ) || strtolower( $row['job_id'] ) !== strtolower( $job_id ) ||
                 ! is_array( $row['payload'] ) ) return self::denied( 'artifact_job_binding_invalid' );
            $id = strtolower( $row['artifact_id'] );
            if ( isset( $seen[$id] ) ) return self::denied( 'artifact_identity_duplicate' );
            $seen[$id] = true;
            if ( 'active' !== $row['status'] ) return self::denied( 'artifact_not_active' );
            $type = $row['artifact_type'];
            if ( ! in_array( $type, array( 'keyword_research', 'serp_research' ), true ) ) return self::denied( 'artifact_type_not_research' );
            $payload = $row['payload'];
            if ( ! isset( $payload['provider_id'], $payload['request_fingerprint'], $payload['collected_at'], $payload['source_refs'] ) ||
                 ! is_string( $payload['provider_id'] ) || '' === trim( $payload['provider_id'] ) ||
                 ! is_string( $payload['request_fingerprint'] ) || ! preg_match( '/^[a-f0-9]{64}$/', $payload['request_fingerprint'] ) ||
                 ! is_array( $payload['source_refs'] ) || count( $payload['source_refs'] ) > 64 ) return self::denied( 'research_provenance_missing' );
            $timestamp = self::utc_time( $payload['collected_at'] );
            if ( false === $timestamp || $timestamp > $now || $now - $timestamp > $age ) $reasons[] = 'research_evidence_expired_or_future';
            if ( empty( $payload['source_refs'] ) ) $reasons[] = 'research_source_refs_missing';
            $reasons[] = 'research_usage_rights_not_independently_verified';
            $summaries[] = array( 'artifact_id' => $id, 'artifact_type' => $type,
                'provider_id' => substr( $payload['provider_id'], 0, 80 ),
                'request_fingerprint' => $payload['request_fingerprint'],
                'collected_at' => $payload['collected_at'],
                'freshness_valid' => false !== $timestamp && $timestamp <= $now && $now - $timestamp <= $age,
                'source_ref_count' => count( $payload['source_refs'] ) );
        }
        usort( $summaries, static function ( $a, $b ) { return strcmp( $a['artifact_id'], $b['artifact_id'] ); } );
        if ( empty( $summaries ) ) $reasons[] = 'research_evidence_required';
        $reasons = array_values( array_unique( $reasons ) );
        sort( $reasons, SORT_STRING );
        // Return identifiers and digests, never raw brand extracts or scraper HTML.
        $output = array(
            'contract' => self::CONTRACT, 'status' => 'NEEDS_EVIDENCE',
            'job_id' => strtolower( $job_id ),
            'scope' => array( 'site_uuid' => $scope['site_uuid'], 'origin' => $scope['origin'],
                'environment' => $scope['environment'], 'brand_id' => (string) $job['brand_id'],
                'locale' => (string) $job['language'], 'market' => (string) $job['country'],
                'content_type' => (string) $job['content_type'],
                'binding' => $scope['binding'] ?? null ),
            'context' => array( 'required_missing' => $missing,
                'requirements_digest' => isset( $context['job_requirements_sha256'] ) ? (string) $context['job_requirements_sha256'] : '',
                'context_digest' => isset( $context['context_pack_sha256'] ) ? (string) $context['context_pack_sha256'] : '',
                'reviewed_coverage_observed' => ! empty( $context['ready'] ) ),
            'evidence_summaries' => $summaries, 'reason_codes' => $reasons,
            'next_steps' => array( 'independently_verify_source_rights', 'resolve_missing_context',
                'obtain_scoped_provider_certification', 'request_editorial_review' ),
            'source' => 'EXISTING_FEATURE007_READ_SERVICES',
            'trusted_authority_verified' => false, 'authorizing' => false,
            'eligible_for_mutation' => false, 'mutation_performed' => false,
            'provider_calls_performed' => false, 'paid_calls' => 0,
        );
        $output['preview_sha256'] = hash( 'sha256', json_encode( array(
            $output['job_id'], $output['scope'], $output['context'], $summaries, $reasons
        ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
        return $output;
    }

    private static function uuid( $value ) {
        return is_string( $value ) && 1 === preg_match(
            '/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/i', $value );
    }
    private static function utc_time( $value ) {
        if ( ! is_string( $value ) || strlen( $value ) > 35 ||
             ! preg_match( '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:\d{2})$/', $value ) ) return false;
        try { $dt = new DateTimeImmutable( $value ); }
        catch ( Exception $e ) { return false; }
        return $dt->getTimestamp();
    }
    private static function denied( $reason ) {
        return array( 'contract' => self::CONTRACT, 'status' => 'DENIED',
            'reason_codes' => array( $reason ), 'authorizing' => false,
            'eligible_for_mutation' => false, 'mutation_performed' => false,
            'provider_calls_performed' => false, 'paid_calls' => 0 );
    }
}
