<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
/** Exact, short-lived owner intent: NEVER a grant, ticket or execution authority. */
final class MAD4B_SCP_Staging_Write_Owner_Intent {
    const OPTION = 'mad4b_scp_staging_write_owner_intent_v1';
    const PAGE = 'mad4b-staging-write-owner-review';
    const ATTEST_ABILITY = 'mad4b/staging-write-owner-attest';
    const REVIEW_ABILITY = 'mad4b/staging-write-owner-review';
    const DIAGNOSE_ABILITY = 'mad4b/staging-write-owner-reconciliation-diagnose';
    const AUDIT_COMMIT_PREFIX = 'mad4b_scp_owner_audit_commit_';
    const ACTION = 'mad4b_staging_write_owner_review';
    const CONFIRM = 'AUTHORIZE EXACT STAGING WRITE CONVERGENCE';
    const TTL = 600;

    public static function boot() {
        add_action( 'wp_abilities_api_init', array( __CLASS__, 'register_ability' ), 18 );
        add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
        add_action( 'admin_post_' . self::ACTION, array( __CLASS__, 'post' ) );
    }
    public static function register_ability() {
        if ( ! function_exists( 'wp_register_ability' ) ) return;
        if ( ! function_exists( 'wp_has_ability' ) || ! wp_has_ability( self::REVIEW_ABILITY ) ) {
            wp_register_ability( self::REVIEW_ABILITY, array(
                'label' => 'Review Exact Staging Write Owner Plan',
                'description' => 'Read-only, nonauthorizing exact owner review for current source and Site Profile.',
                'category' => 'mad4b-staging-write-authority',
                'execute_callback' => array( __CLASS__, 'review' ),
                'permission_callback' => array( __CLASS__, 'can_remote_diagnose' ),
                'input_schema' => array( 'type'=>'object', 'properties'=>array(), 'additionalProperties'=>false ),
                'output_schema' => array( 'type'=>'object', 'additionalProperties'=>true ),
                'meta' => array( 'public'=>false, 'show_in_rest'=>false,
                    'mcp'=>array( 'public'=>false, 'type'=>'tool', 'surface'=>'read',
                        'non_authorizing'=>true, 'production_mutation_allowed'=>false ),
                    'annotations'=>array( 'readonly'=>true, 'destructive'=>false, 'idempotent'=>true ) ),
            ) );
        }
        if ( ! function_exists( 'wp_has_ability' ) || ! wp_has_ability( self::DIAGNOSE_ABILITY ) ) {
            wp_register_ability( self::DIAGNOSE_ABILITY, array(
                'label' => 'Diagnose Staging owner review and OAuth plan parity',
                'description' => 'Read-only exact owner plan, receipt and binding drift report; never grants or retries authority.',
                'category' => 'mad4b-staging-write-authority',
                'execute_callback' => array( __CLASS__, 'diagnose' ),
                'permission_callback' => array( __CLASS__, 'can_remote_diagnose' ),
                'input_schema' => array( 'type' => 'object', 'properties' => array(), 'additionalProperties' => false ),
                'output_schema' => array( 'type' => 'object', 'additionalProperties' => true ),
                'meta' => array( 'public' => false, 'show_in_rest' => false,
                    'mcp' => array( 'public' => false, 'type' => 'tool', 'surface' => 'read',
                        'owner_oauth_read_required' => true, 'exact_chatgpt_client_required' => true,
                        'authority_step_up_required' => false,
                        'production_mutation_allowed' => false ),
                    'annotations' => array( 'readonly' => true, 'destructive' => false, 'idempotent' => true ) ),
            ) );
        }
        if ( function_exists( 'wp_has_ability' ) && wp_has_ability( self::ATTEST_ABILITY ) ) return;
        wp_register_ability( self::ATTEST_ABILITY, array(
            'label' => 'Attest Exact Staging Write Owner Decision',
            'description' => 'Record only a short-lived, independently reviewed plan-bound owner intent, not an execution ticket, grant or deployment.',
            'category' => 'mad4b-staging-write-authority',
            'execute_callback' => array( __CLASS__, 'attest' ),
            'permission_callback' => array( __CLASS__, 'can_remote_attest' ),
            'input_schema' => array(
                'type' => 'object',
                'properties' => array(
                    'review_digest' => array( 'type' => 'string', 'pattern' => '^[a-f0-9]{64}$' ),
                    'confirmation' => array( 'type' => 'string', 'enum' => array( self::CONFIRM ) ),
                ),
                'required' => array( 'review_digest', 'confirmation' ),
                'additionalProperties' => false,
            ),
            'output_schema' => array( 'type'=>'object', 'additionalProperties'=>true ),
            'meta' => array( 'public'=>false, 'show_in_rest'=>false,
                'mcp' => array( 'public'=>false, 'type'=>'tool', 'surface'=>'enrollment',
                    'chatgpt_direct_step_up'=>true, 'exact_chatgpt_client_required'=>true,
                    'generic_remote_admin'=>false, 'production_mutation_allowed'=>false,
                    'owner_intent_only'=>true, 'breakglass_allowed'=>false ),
                'annotations' => array( 'readonly'=>false, 'destructive'=>false, 'idempotent'=>false ),
            ),
        ) );
    }
    public static function can_remote_diagnose( $input = null ) {
        if ( ! self::owner() ) return new WP_Error( 'mad4b_staging_owner_not_explicitly_enrolled', 'Exact enrolled Staging owner required.' );
        if ( ! class_exists( 'MAD4B_SCP_OAuth_Resource_Bridge' )
            || ! MAD4B_SCP_OAuth_Resource_Bridge::verified_bearer_active() )
            return new WP_Error( 'mad4b_staging_owner_oauth_read_session_required', 'Verified owner OAuth session required for read-only diagnostics.' );
        if ( ! class_exists( 'MAD4B_SCP_Local_OAuth_Server' )
            || ! MAD4B_SCP_OAuth_Resource_Bridge::verified_bearer_client_is( MAD4B_SCP_Local_OAuth_Server::CHATGPT_CIMD_CLIENT_ID ) )
            return new WP_Error( 'mad4b_staging_owner_chatgpt_client_required', 'Exact ChatGPT OAuth client required.' );
        if ( ! class_exists( 'MAD4B_SCP_Identity_Context' ) ) return new WP_Error( 'mad4b_staging_owner_identity_missing', 'Verified OAuth subject required.' );
        $id = MAD4B_SCP_Identity_Context::current();
        if ( is_wp_error( $id ) || ! is_array( $id ) || empty( $id['authenticated'] )
            || 'oauth' !== (string) ( $id['subject_type'] ?? '' )
            || 'oauth2_bearer' !== (string) ( $id['auth_method'] ?? '' ) )
            return new WP_Error( 'mad4b_staging_owner_oauth_subject_required', 'Normal OAuth bearer identity required.' );
        return true;
    }
    public static function can_remote_attest( $input = null ) {
        if ( ! self::owner() ) return new WP_Error( 'mad4b_staging_owner_not_explicitly_enrolled', 'Exact enrolled Staging owner required.' );
        if ( ! class_exists( 'MAD4B_SCP_OAuth_Resource_Bridge' )
            || ! MAD4B_SCP_OAuth_Resource_Bridge::verified_bearer_active()
            || ! MAD4B_SCP_OAuth_Resource_Bridge::verified_bearer_has_scope( MAD4B_SCP_OAuth_Resource_Bridge::AUTHORITY_STEP_UP_SCOPE ) )
            return new WP_Error( 'mad4b_staging_owner_oauth_step_up_required', 'Verified owner OAuth authority step-up required.' );
        if ( ! class_exists( 'MAD4B_SCP_Local_OAuth_Server' )
            || ! MAD4B_SCP_OAuth_Resource_Bridge::verified_bearer_client_is( MAD4B_SCP_Local_OAuth_Server::CHATGPT_CIMD_CLIENT_ID ) )
            return new WP_Error( 'mad4b_staging_owner_chatgpt_client_required', 'Exact ChatGPT OAuth client required.' );
        if ( ! class_exists( 'MAD4B_SCP_Identity_Context' ) ) return new WP_Error( 'mad4b_staging_owner_identity_missing', 'Verified OAuth subject required.' );
        $id = MAD4B_SCP_Identity_Context::current();
        if ( is_wp_error( $id ) || ! is_array( $id ) || empty( $id['authenticated'] )
            || 'oauth' !== (string) ( $id['subject_type'] ?? '' )
            || 'oauth2_bearer' !== (string) ( $id['auth_method'] ?? '' ) )
            return new WP_Error( 'mad4b_staging_owner_oauth_subject_required', 'Normal OAuth bearer identity required.' );
        return true;
    }
    public static function owner() {
        if ( ! current_user_can( 'manage_options' ) || ! class_exists( 'MAD4B_SCP_Site_Profile' )
            || ! MAD4B_SCP_Site_Profile::configured()
            || 'staging' !== MAD4B_SCP_Site_Profile::current_environment()
            || ! MAD4B_SCP_Site_Profile::origin_enrolled()
            || ! MAD4B_SCP_Site_Profile::site_urls_match_enrollment() ) return false;
        $ids = MAD4B_SCP_Site_Profile::oauth_user_ids();
        // Never accept legacy_zero_touch fallback as an owner attestation.
        if ( ! is_array( $ids ) || 1 !== count( array_unique( array_map( 'intval', $ids ) ) )
            || ! in_array( (int) get_current_user_id(), array_map( 'intval', $ids ), true ) ) return false;
        if ( class_exists( 'MAD4B_SCP_Policy' ) && MAD4B_SCP_Policy::can_breakglass() ) return false;
        if ( class_exists( 'MAD4B_SCP_Governed_Runtime_Gates' )
            && MAD4B_SCP_Governed_Runtime_Gates::raw_sql_breakglass_enabled() ) return false;
        return true;
    }
    /**
     * Cross-transport diagnostics only. This fingerprint never replaces the
     * complete mutable plan SHA or its exact CAS at apply time.
     */
    public static function canonical_scope_sha256( array $plan ) {
        $scope = array( 'contract' => 'mad4b.owner-read-scope.v1' );
        foreach ( array( 'site_uuid', 'site_profile_revision', 'site_profile_digest',
            'source_commit_sha', 'build_fingerprint', 'package_manifest_digest',
            'artifact_identity', 'exact_grant_plan_sha256' ) as $key )
            $scope[ $key ] = isset( $plan[ $key ] ) && is_scalar( $plan[ $key ] )
                ? (string) $plan[ $key ] : '';
        $write = isset( $plan['write_reconciliation'] ) && is_array( $plan['write_reconciliation'] )
            ? $plan['write_reconciliation'] : array();
        foreach ( array( 'write_inventory_fingerprint', 'grant_rows_fingerprint' ) as $key )
            $scope[ $key ] = isset( $write[ $key ] ) && is_string( $write[ $key ] )
                ? $write[ $key ] : '';
        $json = wp_json_encode( $scope, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
        return is_string( $json ) ? hash( 'sha256', $json ) : '';
    }

    public static function digest( array $plan ) {
        $bound = array( 'contract'=>'mad4b.staging-write-owner-intent.v1', 'operation'=>'mad4b/staging-write-authority-convergence-apply' );
        foreach ( array( 'plan_sha256', 'site_uuid', 'site_profile_revision', 'site_profile_digest',
            'source_commit_sha', 'build_fingerprint', 'package_manifest_digest', 'artifact_identity' ) as $key )
            $bound[ $key ] = $plan[ $key ] ?? null;
        return hash( 'sha256', wp_json_encode( $bound, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
    }
    /** Site-secret keyed receipt, not a forgeable bare options digest. */
    private static function signature( array $record ) {
        if ( ! function_exists( 'wp_salt' ) ) return '';
        $secret = (string) wp_salt( 'auth' );
        if ( '' === $secret ) return '';
        return hash_hmac( 'sha256',
            wp_json_encode( $record, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ), $secret );
    }
    private static function audit_commit_key( $receipt ) {
        return self::AUDIT_COMMIT_PREFIX . substr( $receipt, 0, 40 );
    }
    private static function audit_commit_signature( array $record ) {
        return self::signature( array(
            'contract' => 'mad4b.owner-audit-commit.v1',
            'receipt_sha256' => (string) $record['receipt_sha256'],
            'owner' => (int) $record['owner'],
            'review_digest' => (string) $record['review_digest'],
        ) );
    }
    private static function audit_committed( array $record ) {
        if ( ! isset( $record['receipt_sha256'], $record['review_digest'], $record['owner'] ) )
            return false;
        $marker = get_option( self::audit_commit_key( $record['receipt_sha256'] ), array() );
        $expected = self::audit_commit_signature( $record );
        return is_array( $marker ) && isset( $marker['signature'], $marker['receipt'] )
            && '' !== $expected
            && hash_equals( $expected, (string) $marker['signature'] )
            && hash_equals( (string) $record['receipt_sha256'], (string) $marker['receipt'] );
    }
    private static function mark_audit_committed( array $record ) {
        $key = self::audit_commit_key( $record['receipt_sha256'] );
        $value = array( 'receipt' => $record['receipt_sha256'],
            'signature' => self::audit_commit_signature( $record ) );
        if ( '' === $value['signature'] ) return false;
        if ( ! add_option( $key, $value, '', false ) ) return self::audit_committed( $record );
        return self::audit_committed( $record );
    }
    /**
     * Narrow plan diagnostics: no grant rows, agent secrets, tokens or URLs.
     * Never feed this snapshot into authority matching or relax plan_sha256.
     */
    public static function evidence_snapshot( array $plan ) {
        $write = isset( $plan['write_reconciliation'] ) && is_array( $plan['write_reconciliation'] )
            ? $plan['write_reconciliation'] : array();
        $binding = isset( $write['candidate_binding'] ) && is_array( $write['candidate_binding'] )
            ? $write['candidate_binding'] : array();
        $result = array();
        $source = array(
            'exact_grant_plan_sha256' => $plan,
            'site_profile_revision' => $plan,
            'write_inventory_fingerprint' => $write,
            'grant_rows_fingerprint' => $write,
            'agent_public_id' => $write,
            'current_build_fingerprint' => $binding,
            'current_package_manifest_digest' => $binding,
            'stored_source_commit_sha' => $binding,
            'stored_build_fingerprint' => $binding,
            'stored_package_manifest_digest' => $binding,
        );
        foreach ( $source as $key => $item ) {
            $value = isset( $item[ $key ] ) ? $item[ $key ] : null;
            $result[ $key ] = is_string( $value ) && strlen( $value ) <= 191
                ? $value : ( is_int( $value ) ? $value : null );
        }
        foreach ( array( 'write_tool_count', 'exact_grants_existing', 'exact_grants_missing_count' ) as $key )
            $result[ $key ] = isset( $write[ $key ] ) && is_numeric( $write[ $key ] )
                ? (int) $write[ $key ] : null;
        $result['candidate_binding_match'] = isset( $binding['match'] ) ? (bool) $binding['match'] : null;
        $result['write_current_ready'] = isset( $write['current_ready'] ) ? (bool) $write['current_ready'] : null;
        $result['exact_grant_plan_eligible'] = isset( $plan['exact_grant_plan_eligible'] )
            ? (bool) $plan['exact_grant_plan_eligible'] : null;
        return $result;
    }

    public static function review() {
        if ( ! self::owner() ) return new WP_Error( 'mad4b_owner_explicit_enrollment_required', 'Explicit Staging owner required.' );
        $local = function_exists( 'is_admin' ) && is_admin()
            && ( ! class_exists( 'MAD4B_SCP_OAuth_Resource_Bridge', false )
              || ! MAD4B_SCP_OAuth_Resource_Bridge::verified_bearer_active() );
        $plan = $local
            ? MAD4B_SCP_Staging_Write_Authority_Convergence::plan_for_local_owner()
            : MAD4B_SCP_Staging_Write_Authority_Convergence::plan();
        if ( is_wp_error( $plan ) ) return $plan;
        if ( ! is_array( $plan ) || empty( $plan['ready_to_apply'] ) || empty( $plan['plan_sha256'] ) ) {
            $blockers = is_array( $plan ) && isset( $plan['hard_blockers'] ) && is_array( $plan['hard_blockers'] )
                ? array_values( array_unique( array_filter( array_map( 'sanitize_key', $plan['hard_blockers'] ) ) ) ) : array();
            $grant_blockers = is_array( $plan ) && isset( $plan['exact_grant_plan_blockers'] ) && is_array( $plan['exact_grant_plan_blockers'] )
                ? array_values( array_unique( array_filter( array_map( 'sanitize_text_field', $plan['exact_grant_plan_blockers'] ) ) ) ) : array();
            return new WP_Error( 'mad4b_owner_plan_not_ready', 'Fresh eligible Staging Write plan required.',
                array( 'blockers' => array_slice( $blockers, 0, 20 ),
                    'exact_grant_blockers' => array_slice( $grant_blockers, 0, 20 ),
                    'read_only' => true, 'mutation_performed' => false ) );
        }
        // Return only the exact identity needed for owner review, not the
        // broad grant inventory or private provider information.
        $identity = array();
        foreach ( array( 'plan_sha256', 'site_uuid', 'site_profile_revision',
            'site_profile_digest', 'source_commit_sha', 'build_fingerprint',
            'package_manifest_digest', 'artifact_identity' ) as $key )
            $identity[ $key ] = $plan[ $key ] ?? null;
        return array( 'contract'=>'mad4b.staging-write-owner-review.v1',
            'review_digest'=>self::digest( $plan ), 'plan'=>$identity,
            'canonical_scope_sha256'=>self::canonical_scope_sha256( $plan ),
            'plan_evidence'=>self::evidence_snapshot( $plan ),
            'read_only'=>true, 'authorizing'=>false, 'mutation_performed'=>false,
            'decision_recorded'=>false,
        );
    }
    private static function retire_expired_prior_intent( $previous ) {
        if ( ! function_exists( 'delete_option' ) || ! is_array( $previous )
            || ! isset( $previous['receipt_sha256'], $previous['expires'], $previous['owner'] )
            || time() < (int) $previous['expires'] ) return false;
        $receipt = (string) $previous['receipt_sha256'];
        if ( ! preg_match( '/^[a-f0-9]{64}$/D', $receipt ) ) return false;
        $signed = $previous;
        unset( $signed['receipt_sha256'] );
        $valid = self::signature( $signed );
        if ( '' === $valid || ! hash_equals( $valid, $receipt ) ) return false;
        // Never delete the append-only Audit or the operation-journal tombstone.
        // This receipt is expired and has already been replaced by a newer
        // same-owner signed record; its auxiliary marker is no longer useful.
        delete_option( self::AUDIT_COMMIT_PREFIX . substr( $receipt, 0, 40 ) );
        delete_option( 'mad4b_scp_owner_claim_' . substr( $receipt, 0, 40 ) );
        return true;
    }

    public static function attest( $input ) {
        $review = self::review();
        if ( is_wp_error( $review ) ) return $review;
        if ( ! is_array( $input ) || ! isset( $input['review_digest'], $input['confirmation'] )
            || ! is_string( $input['review_digest'] )
            || ! hash_equals( $review['review_digest'], $input['review_digest'] )
            || self::CONFIRM !== $input['confirmation'] )
            return new WP_Error( 'mad4b_owner_review_mismatch', 'Exact plan and confirmation required.' );
        if ( ! class_exists( 'MAD4B_SCP_Audit' ) || empty( MAD4B_SCP_Audit::storage_status()['ready'] ) )
            return new WP_Error( 'mad4b_owner_audit_unavailable', 'Durable audit required.' );
        $existing = self::page_intent_status( $review );
        if ( 'OWNER_INTENT_CURRENT' === $existing['state'] ) return array(
            'state' => 'OWNER_INTENT_NOT_AUTHORITY',
            'receipt_sha256' => $existing['receipt_sha256'],
            'expires_at' => $existing['expires_at'],
            'reused_existing_intent' => true,
            'mutation_performed' => false );
        $previous_record = get_option( self::OPTION, array() );
        try { $nonce = bin2hex( random_bytes( 16 ) ); }
        catch ( Throwable $exception ) { return new WP_Error( 'mad4b_owner_entropy_unavailable', 'Secure nonce unavailable.' ); }
        $record = array( 'owner'=>(int) get_current_user_id(), 'review_digest'=>$review['review_digest'],
            'plan_identity'=>is_array( $review['plan'] ) ? $review['plan'] : array(),
            'canonical_scope_sha256'=>is_string( $review['canonical_scope_sha256'] ?? null )
                ? $review['canonical_scope_sha256'] : '',
            'plan_evidence'=>isset( $review['plan_evidence'] ) && is_array( $review['plan_evidence'] )
                ? $review['plan_evidence'] : array(),
            'review_channel'=>function_exists( 'is_admin' ) && is_admin()
                && ( ! class_exists( 'MAD4B_SCP_OAuth_Resource_Bridge', false )
                    || ! MAD4B_SCP_OAuth_Resource_Bridge::verified_bearer_active() ) ? 'wp_admin' : 'oauth_mcp',
            'issued'=>time(), 'expires'=>time()+self::TTL, 'nonce'=>$nonce );
        $record['receipt_sha256'] = self::signature( $record );
        if ( '' === $record['receipt_sha256'] ) return new WP_Error( 'mad4b_owner_signing_unavailable', 'Site-secret signature unavailable.' );
        // Persist and read back before recording a successful review. An
        // uncommitted receipt is NEVER claimable even if a later audit fails.
        if ( ! update_option( self::OPTION, $record, false ) )
            return new WP_Error( 'mad4b_owner_review_persist_failed', 'Owner intent not persisted.' );
        $saved = get_option( self::OPTION, array() );
        if ( ! is_array( $saved ) || ! hash_equals( $record['receipt_sha256'], (string) ( $saved['receipt_sha256'] ?? '' ) ) )
            return new WP_Error( 'mad4b_owner_review_readback_failed', 'Owner intent readback failed.' );
        $audit = MAD4B_SCP_Audit::record( 'mad4b/staging-write-owner-reviewed', array(
            'owner'=>$record['owner'], 'site_uuid'=>$review['plan']['site_uuid'],
            'review_digest'=>$review['review_digest'], 'receipt_sha256'=>$record['receipt_sha256'],
            'expires'=>$record['expires'], 'authority_granted'=>false, 'production_mutation'=>false ), 'ok' );
        if ( is_wp_error( $audit ) || false === $audit ) return new WP_Error( 'mad4b_owner_audit_commit_failed',
            'Signed owner intent persisted but audit did not commit; it cannot be claimed.' );
        if ( ! self::mark_audit_committed( $record ) )
            return new WP_Error( 'mad4b_owner_audit_marker_failed',
                'Owner audit recorded but durable completion marker is unavailable; do not retry blindly.' );
        self::retire_expired_prior_intent( $previous_record );
        return array( 'state'=>'OWNER_INTENT_NOT_AUTHORITY', 'receipt_sha256'=>$record['receipt_sha256'],
            'expires_at'=>$record['expires'], 'mutation_performed'=>false );
    }
    public static function validate_claimable( array $plan, $input ) {
        if ( ! self::owner() ) return new WP_Error( 'mad4b_owner_claim_identity_denied', 'Explicit enrolled Staging owner required.' );
        $hash = is_array( $input ) ? (string) ( $input['owner_intent_sha256'] ?? '' ) : '';
        $saved = get_option( self::OPTION, array() );
        if ( ! preg_match( '/^[a-f0-9]{64}$/D', $hash ) || ! is_array( $saved )
            || ! isset( $saved['receipt_sha256'], $saved['owner'], $saved['issued'],
                $saved['expires'], $saved['nonce'], $saved['review_digest'] ) )
            return new WP_Error( 'mad4b_owner_receipt_missing', 'Exact owner intent required.' );
        $payload = $saved;
        unset( $payload['receipt_sha256'] );
        $computed = self::signature( $payload );
        if ( ! hash_equals( $computed, (string) $saved['receipt_sha256'] )
            || ! hash_equals( $hash, (string) $saved['receipt_sha256'] )
            || ! hash_equals( self::digest( $plan ), (string) $saved['review_digest'] )
            || (int) $saved['owner'] !== (int) get_current_user_id()
            || time() >= (int) $saved['expires']
            || (int) $saved['issued'] > time()
            || (int) $saved['expires'] - (int) $saved['issued'] > self::TTL )
            return new WP_Error( 'mad4b_owner_receipt_stale', 'Expired, altered or different plan/owner.' );
        if ( ! self::audit_committed( $saved ) )
            return new WP_Error( 'mad4b_owner_receipt_audit_unconfirmed',
                'Owner intent is not accompanied by a durable signed audit completion marker.' );
        if ( ! class_exists( 'MAD4B_SCP_Audit' ) || empty( MAD4B_SCP_Audit::storage_status()['ready'] ) )
            return new WP_Error( 'mad4b_owner_claim_audit_unavailable', 'Durable claim audit unavailable.' );
        // Preflight is explicitly non-authorizing. Do not consume a claim
        // when an operation reservation is already occupied.
        $claim_key = 'mad4b_scp_owner_claim_' . substr( $hash, 0, 40 );
        if ( false !== get_option( $claim_key, false ) )
            return new WP_Error( 'mad4b_owner_receipt_replayed', 'The owner receipt was already claimed.' );
        return array( 'owner_user' => (int) $saved['owner'],
            'receipt_sha256' => $hash,
            'preflight_only' => true, 'mutation_performed' => false );
    }

    public static function claim( array $plan, $input ) {
        if ( ! self::owner() ) return new WP_Error( 'mad4b_owner_claim_identity_denied', 'Explicit enrolled Staging owner required.' );
        $hash = is_array( $input ) ? (string) ( $input['owner_intent_sha256'] ?? '' ) : '';
        $saved = get_option( self::OPTION, array() );
        if ( ! preg_match( '/^[a-f0-9]{64}$/D', $hash ) || ! is_array( $saved )
            || ! isset( $saved['receipt_sha256'], $saved['owner'], $saved['issued'],
                $saved['expires'], $saved['nonce'], $saved['review_digest'] ) )
            return new WP_Error( 'mad4b_owner_receipt_missing', 'Exact owner intent required.' );
        $payload = $saved;
        unset( $payload['receipt_sha256'] );
        $computed = self::signature( $payload );
        if ( ! hash_equals( $computed, (string) $saved['receipt_sha256'] )
            || ! hash_equals( $hash, (string) $saved['receipt_sha256'] )
            || ! hash_equals( self::digest( $plan ), (string) $saved['review_digest'] )
            || (int) $saved['owner'] !== (int) get_current_user_id()
            || time() >= (int) $saved['expires']
            || (int) $saved['issued'] > time()
            || (int) $saved['expires'] - (int) $saved['issued'] > self::TTL )
            return new WP_Error( 'mad4b_owner_receipt_stale', 'Expired, altered or different plan/owner.' );
        if ( ! self::audit_committed( $saved ) )
            return new WP_Error( 'mad4b_owner_receipt_audit_unconfirmed',
                'Owner intent is not accompanied by a durable signed audit completion marker.' );
        if ( ! class_exists( 'MAD4B_SCP_Audit' ) || empty( MAD4B_SCP_Audit::storage_status()['ready'] ) )
            return new WP_Error( 'mad4b_owner_claim_audit_unavailable', 'Durable claim audit unavailable.' );
        // wp_options.option_name is unique. Failed/unknown attempts consume this receipt.
        $claim_key = 'mad4b_scp_owner_claim_' . substr( $hash, 0, 40 );
        if ( ! add_option( $claim_key, array( 'receipt'=>$hash, 'claimed'=>time() ), '', false ) )
            return new WP_Error( 'mad4b_owner_receipt_replayed', 'This owner receipt was already claimed.' );
        $audit = MAD4B_SCP_Audit::record( 'mad4b/staging-write-owner-claimed', array(
            'owner'=>$saved['owner'], 'receipt_sha256'=>$hash, 'production_mutation'=>false ), 'ok' );
        if ( is_wp_error( $audit ) ) return new WP_Error( 'mad4b_owner_claim_audit_failed', 'Claim consumed; reconcile before retry.' );
        return array( 'owner_user'=>$saved['owner'], 'receipt_sha256'=>$hash, 'claimed_once'=>true );
    }
    /**
     * Read-only owner-intent UI receipt. The URL is NEVER evidence of success.
     * The signed, same-owner, same-plan, unexpired option is authoritative for
     * rendering a recorded/claimed state. This does not create write access.
     */
    public static function page_intent_status( $review = null ) {
        $saved = get_option( self::OPTION, array() );
        $absent = array( 'state' => 'NO_VERIFIED_OWNER_INTENT', 'receipt_sha256' => '',
            'expires_at' => 0, 'read_only' => true, 'write_authorized' => false );
        if ( ! is_array( $saved ) || ! isset( $saved['receipt_sha256'], $saved['owner'],
            $saved['review_digest'], $saved['issued'], $saved['expires'], $saved['nonce'] ) )
            return $absent;
        $receipt = $saved['receipt_sha256'];
        if ( ! is_string( $receipt ) || ! preg_match( '/^[a-f0-9]{64}$/D', $receipt ) )
            return $absent;
        $signed = $saved;
        unset( $signed['receipt_sha256'] );
        $actual = self::signature( $signed );
        if ( '' === $actual || ! hash_equals( $actual, $receipt )
            || (int) $saved['owner'] !== (int) get_current_user_id()
            || ! self::owner() ) return $absent;
        $valid_time = (int) $saved['issued'] <= time()
            && (int) $saved['expires'] > time()
            && (int) $saved['expires'] > (int) $saved['issued']
            && (int) $saved['expires'] - (int) $saved['issued'] <= self::TTL;
        $same_review = is_array( $review ) && isset( $review['review_digest'] )
            && is_string( $review['review_digest'] )
            && hash_equals( (string) $review['review_digest'], (string) $saved['review_digest'] );
        $claim_key = 'mad4b_scp_owner_claim_' . substr( $receipt, 0, 40 );
        $claim = get_option( $claim_key, array() );
        $claimed = is_array( $claim ) && isset( $claim['receipt'] )
            && hash_equals( $receipt, (string) $claim['receipt'] );
        $audited = self::audit_committed( $saved );
        return array(
            'state' => ! $audited ? 'OWNER_INTENT_AUDIT_UNCONFIRMED'
                : ( ! $valid_time ? 'OWNER_INTENT_EXPIRED'
                : ( ! is_array( $review ) ? 'OWNER_INTENT_PLAN_UNAVAILABLE'
                : ( ! $same_review ? 'OWNER_INTENT_PLAN_CHANGED'
                : ( $claimed ? 'OWNER_INTENT_CLAIMED' : 'OWNER_INTENT_CURRENT' ) ) ) ),
            'receipt_sha256' => $receipt,
            'expires_at' => (int) $saved['expires'],
            'read_only' => true, 'write_authorized' => false,
        );
    }

    /**
     * Exact transport-parity diagnosis for the same enrolled owner. A local
     * WordPress confirmation does not imply an OAuth grant or apply success.
     * Neither prior receipt nor observed counts can authorize a mutation here.
     */
    public static function diagnose( $input = array() ) {
        if ( ! is_array( $input ) || $input ) return new WP_Error(
            'mad4b_owner_diagnose_invalid_input', 'This read-only diagnostic accepts no parameters.' );
        $permission = self::can_remote_diagnose();
        if ( is_wp_error( $permission ) || true !== $permission ) return $permission;
        $review = self::review();
        $eligible = is_array( $review );
        $state = self::page_intent_status( $eligible ? $review : null );
        $saved = get_option( self::OPTION, array() );
        $valid_record = 'NO_VERIFIED_OWNER_INTENT' !== $state['state'];
        $stored = $valid_record && is_array( $saved ) && isset( $saved['plan_identity'] )
            && is_array( $saved['plan_identity'] ) ? $saved['plan_identity'] : array();
        $current = $eligible && isset( $review['plan'] ) && is_array( $review['plan'] )
            ? $review['plan'] : array();
        $blocked_plan = array();
        if ( ! $eligible ) {
            $raw = MAD4B_SCP_Staging_Write_Authority_Convergence::plan();
            if ( is_array( $raw ) ) {
                $blocked_plan = $raw;
                foreach ( array( 'plan_sha256', 'site_uuid', 'site_profile_revision',
                    'site_profile_digest', 'source_commit_sha', 'build_fingerprint',
                    'package_manifest_digest', 'artifact_identity' ) as $key )
                    $current[ $key ] = $raw[ $key ] ?? null;
            }
        }
        $identity_fields = array( 'plan_sha256', 'site_uuid', 'site_profile_revision',
            'site_profile_digest', 'source_commit_sha', 'build_fingerprint',
            'package_manifest_digest', 'artifact_identity' );
        $changed = array();
        if ( $stored && $current ) {
            foreach ( $identity_fields as $key )
                if ( ! isset( $stored[ $key ], $current[ $key ] )
                    || ! hash_equals( (string) $stored[ $key ], (string) $current[ $key ] ) )
                    $changed[] = $key;
        }
        $stored_evidence = $valid_record && is_array( $saved ) && isset( $saved['plan_evidence'] )
            && is_array( $saved['plan_evidence'] ) ? $saved['plan_evidence'] : array();
        $current_evidence = $eligible && isset( $review['plan_evidence'] )
            && is_array( $review['plan_evidence'] ) ? $review['plan_evidence']
            : ( $blocked_plan ? self::evidence_snapshot( $blocked_plan ) : array() );
        $evidence_changed = array();
        if ( $stored_evidence && $current_evidence ) {
            foreach ( $current_evidence as $key => $value ) {
                $old_value = isset( $stored_evidence[ $key ] ) ? $stored_evidence[ $key ] : null;
                if ( $value !== $old_value ) $evidence_changed[] = $key;
            }
        }
        $legacy = $valid_record && ! $stored;
        $identity_drift = (bool) $changed;
        $old_scope = $valid_record && is_array( $saved )
            && is_string( $saved['canonical_scope_sha256'] ?? null )
            ? $saved['canonical_scope_sha256'] : '';
        $current_scope = $eligible ? (string) ( $review['canonical_scope_sha256'] ?? '' )
            : ( $blocked_plan ? self::canonical_scope_sha256( $blocked_plan ) : '' );
        $same_scope = '' !== $old_scope && '' !== $current_scope
            && hash_equals( $old_scope, $current_scope );
        $digest_differs = $valid_record && $eligible
            && is_array( $saved ) && isset( $saved['review_digest'] )
            && ! hash_equals( (string) $saved['review_digest'], (string) $review['review_digest'] );
        $authority = class_exists( 'MAD4B_SCP_Staging_Write_Authority_Convergence', false )
            && method_exists( 'MAD4B_SCP_Staging_Write_Authority_Convergence', 'status' )
            ? MAD4B_SCP_Staging_Write_Authority_Convergence::status() : array();
        $write = is_array( $authority ) && isset( $authority['write_plan'] )
            && is_array( $authority['write_plan'] ) ? $authority['write_plan'] : array();
        $binding = is_array( $authority ) && isset( $authority['candidate_binding'] )
            && is_array( $authority['candidate_binding'] ) ? $authority['candidate_binding'] : array();
        $count = isset( $write['write_tool_count'] ) ? (int) $write['write_tool_count'] : null;
        $grants = isset( $write['exact_grants_existing'] ) ? (int) $write['exact_grants_existing'] : null;
        $exact_grants = null !== $count && $count > 0 && null !== $grants && $count === $grants;
        $binding_match = ! empty( $binding['match'] );
        $ready = is_array( $authority ) && ! empty( $authority['write_ready'] );
        $latest_operation = class_exists( 'MAD4B_SCP_Staging_Operation_Journal', false )
            ? MAD4B_SCP_Staging_Operation_Journal::latest(
                (string) ( $current['site_uuid'] ?? $stored['site_uuid'] ?? '' ) )
            : array( 'state' => 'OPERATION_JOURNAL_NOT_AVAILABLE', 'read_only' => true );
        $operation_uncertain = in_array( (string) ( $latest_operation['state'] ?? '' ),
            array( 'EXECUTION_UNCERTAIN', 'RECOVERY_REQUIRED',
                'OPERATION_LOCK_WITHOUT_INDEX', 'UNVERIFIED_OR_UNAVAILABLE',
                'OPERATION_LOCK_MISSING', 'OPERATION_LOCK_CONFLICT',
                'FINALIZATION_LOCK_HELD', 'FINALIZATION_INCOMPLETE',
                'VERIFIED_LOCK_HELD' ), true );
        $next = $operation_uncertain ? 'readback_write_runtime_binding_audit_do_not_replay'
            : ( ! $eligible ? 'repair_current_plan_blockers_then_review_fresh_plan'
            : ( $identity_drift || $digest_differs || $evidence_changed || $legacy
                ? 'compare_exact_owner_plan_fields_no_blind_retry'
                : ( 'OWNER_INTENT_CURRENT' === $state['state']
                    ? 'get_new_oauth_handshake_then_claim_once_with_exact_receipt'
                    : ( 'OWNER_INTENT_CLAIMED' === $state['state']
                        ? 'readback_write_runtime_binding_audit_do_not_replay'
                        : ( $ready ? 'verify_current_authority_and_stop'
                            : 'obtain_explicit_fresh_owner_approval_for_exact_plan' ) ) ) ) );
        return array(
            'contract' => 'mad4b.staging-owner-transport-reconciliation.v1',
            'state' => $operation_uncertain ? 'OPERATION_REQUIRES_RECOVERY_READBACK'
                : ( ! $eligible ? 'PLAN_BLOCKED'
                : ( $identity_drift || $digest_differs || $evidence_changed ? 'OWNER_REVIEW_PLAN_DRIFT'
                : ( $legacy ? 'LEGACY_RECEIPT_IDENTITY_UNAVAILABLE'
                : ( 'OWNER_INTENT_CLAIMED' === $state['state'] && ! $ready
                    ? 'CLAIMED_EXECUTION_UNVERIFIED_DO_NOT_RETRY'
                    : ( $ready && $binding_match ? 'WRITE_RUNTIME_REPORTED_READY'
                        : 'EXECUTION_NOT_CONFIRMED' ) ) ) ) ),
            'owner_intent_state' => $state['state'],
            'recorded_review_channel' => $valid_record && isset( $saved['review_channel'] )
                ? (string) $saved['review_channel'] : 'unknown_legacy',
            'recorded_receipt_legacy_identity_missing' => $legacy,
            'stored_identity_available' => (bool) $stored,
            'current_identity_available' => (bool) $current,
            'identity_fields_changed' => $changed,
            'non_authorizing_plan_evidence_fields_changed' => $evidence_changed,
            'recorded_evidence_unavailable' => $valid_record && ! $stored_evidence,
            'identity_drift' => $identity_drift,
            'review_digest_differs' => $digest_differs,
            'canonical_scope_sha256_recorded' => $old_scope,
            'canonical_scope_sha256_current' => $current_scope,
            'canonical_scope_match_diagnostic_only' => $same_scope,
            'canonical_scope_does_not_authorize_apply' => true,
            'stored_plan_sha256' => isset( $stored['plan_sha256'] ) ? (string) $stored['plan_sha256'] : '',
            'current_plan_sha256' => isset( $current['plan_sha256'] ) ? (string) $current['plan_sha256'] : '',
            'owner_intent_receipt_sha256' => 'OWNER_INTENT_CURRENT' === $state['state']
                ? $state['receipt_sha256'] : '',
            'plan_read_error' => is_wp_error( $review ) ? $review->get_error_code() : '',
            'plan_blockers' => $blocked_plan && isset( $blocked_plan['hard_blockers'] )
                && is_array( $blocked_plan['hard_blockers'] )
                ? array_slice( $blocked_plan['hard_blockers'], 0, 20 )
                : ( is_wp_error( $review ) && method_exists( $review, 'get_error_data' )
                    && is_array( $review->get_error_data() )
                    ? array_slice( (array) ( $review->get_error_data()['blockers'] ?? array() ), 0, 20 ) : array() ),
            'grant_rows_existing' => $grants,
            'registered_write_abilities' => $count,
            'exact_grant_count_parity' => $exact_grants,
            'candidate_binding_match' => $binding_match,
            'write_ready_readback' => $ready,
            'grant_count_alone_not_authority' => true,
            'current_source_commit_sha' => isset( $current['source_commit_sha'] )
                ? (string) $current['source_commit_sha'] : '',
            'stored_binding_source_commit_sha' => isset( $binding['stored_source_commit_sha'] )
                ? (string) $binding['stored_source_commit_sha'] : '',
            'current_binding_source_commit_sha' => isset( $binding['current_source_commit_sha'] )
                ? (string) $binding['current_source_commit_sha'] : '',
            'evidence_scope' => 'same_request_read_only_not_independent_browser_or_release_certification',
            'latest_operation' => $latest_operation,
            'oauth_step_up_required_for_diagnostic' => false,
            'oauth_step_up_still_required_for_apply' => true,
            'requested_plan_not_replayed' => true,
            'next_action' => $next,
            'read_only' => true, 'authorizing' => false, 'mutation_performed' => false,
            'production_mutation_allowed' => false, 'developer_or_breakglass_allowed' => false,
        );
    }

    public static function menu() {
        add_management_page( 'MAD4B Staging Write Owner Review', 'MAD4B Write Review',
            'manage_options', self::PAGE, array( __CLASS__, 'page' ) );
    }
    public static function page() {
        if ( ! self::owner() ) wp_die( 'Explicit enrolled Staging owner required.' );
        if ( ! headers_sent() && function_exists( 'nocache_headers' ) ) nocache_headers();
        $review = self::review();
        $view = self::page_intent_status( is_array( $review ) ? $review : null );
        echo '<div class="wrap"><h1>Staging Write — Exact Owner Review</h1>';
        if ( is_wp_error( $review ) ) {
            if ( 'NO_VERIFIED_OWNER_INTENT' !== $view['state'] )
                echo '<div class="notice notice-warning"><p>Previous owner confirmation exists, but the current exact plan is not eligible. State: <code>'
                    . esc_html( $view['state'] ) . '</code>. Reconcile the plan before requesting a new confirmation.</p></div>';
            echo '<p role="alert">' . esc_html( $review->get_error_code() ) . '</p>';
            echo '<p><a href="' . esc_url( admin_url( 'admin.php?page=mad4b-operational-remediation' ) )
                . '">Inspect the current blocking gate and recommended next step</a></p>';
            $details = $review->get_error_data();
            if ( is_array( $details ) ) {
                foreach ( array( 'blockers', 'exact_grant_blockers' ) as $key ) {
                    $items = isset( $details[ $key ] ) && is_array( $details[ $key ] ) ? $details[ $key ] : array();
                    if ( ! $items ) continue;
                    echo '<h2>' . esc_html( 'exact_grant_blockers' === $key ? 'Exact grant plan blockers' : 'Convergence prerequisites' ) . '</h2><ul>';
                    foreach ( array_slice( $items, 0, 20 ) as $item )
                        echo '<li><code>' . esc_html( (string) $item ) . '</code></li>';
                    echo '</ul>';
                }
            }
            echo '</div>';
            return;
        }
        echo '<p>Owner intent only: no grant, ticket, OAuth change or installation.</p>';
        if ( 'OWNER_INTENT_CURRENT' === $view['state'] )
            echo '<div class="notice notice-success" role="status"><p><strong>Owner confirmation recorded and verified.</strong> This records intent only, not an applied write permission. Receipt expires at UTC timestamp <code>'
                . esc_html( gmdate( 'Y-m-d H:i:s', (int) $view['expires_at'] ) . ' UTC' ) . '</code>.</p></div>';
        elseif ( 'OWNER_INTENT_CLAIMED' === $view['state'] )
            echo '<div class="notice notice-info" role="status"><p><strong>Owner confirmation consumed.</strong> Check a fresh Governed Write status and candidate binding. Do not submit the same approval again.</p></div>';
        elseif ( in_array( $view['state'], array( 'OWNER_INTENT_EXPIRED',
            'OWNER_INTENT_PLAN_CHANGED', 'OWNER_INTENT_PLAN_UNAVAILABLE',
            'OWNER_INTENT_AUDIT_UNCONFIRMED' ), true ) )
            echo '<div class="notice notice-warning" role="status"><p>Previous confirmation cannot be reused: <code>'
                . esc_html( $view['state'] ) . '</code>. An exact current plan and a new confirmation are required.</p></div>';
        if ( ! in_array( $view['state'], array( 'OWNER_INTENT_CURRENT', 'OWNER_INTENT_CLAIMED' ), true ) )
            echo '<p><strong>Manual WordPress approval:</strong> confirm the exact site, revision, source, plan SHA and type '
                . '<code>' . esc_html( self::CONFIRM ) . '</code>. This saves one owner intent for 600 seconds; it does not apply grants.</p>';
        echo '<details><summary>Advanced: OAuth and exact plan details</summary>';
        echo '<p><strong>Remote OAuth alternative:</strong> discover '
            . '<code>' . esc_html( self::REVIEW_ABILITY ) . '</code>, obtain the current review digest, then use '
            . '<code>' . esc_html( MAD4B_SCP_Staging_Write_Authority_Convergence::HANDSHAKE_ABILITY ) . '</code> '
            . 'and a verified owner OAuth Step-Up to execute '
            . '<code>' . esc_html( MAD4B_SCP_Staging_Write_Authority_Convergence::APPLY_ABILITY ) . '</code> '
            . 'with exact current plan fields and owner_review_digest. No direct grant or Developer/Breakglass authority.</p>';
        echo '<p>Site UUID: <code>' . esc_html( $review['plan']['site_uuid'] ) . '</code></p>';
        echo '<p>Source: <code>' . esc_html( $review['plan']['source_commit_sha'] ) . '</code></p>';
        echo '<p>Plan SHA: <code>' . esc_html( $review['plan']['plan_sha256'] ) . '</code></p>';
        if ( in_array( $view['state'], array( 'OWNER_INTENT_CURRENT', 'OWNER_INTENT_CLAIMED' ), true ) )
            echo '<p>Verified receipt: <code>' . esc_html( $view['receipt_sha256'] ) . '</code></p>';
        if ( 'OWNER_INTENT_CURRENT' === $view['state'] )
            echo '<p><strong>Next:</strong> use a delegated OAuth session for the SAME enrolled owner, get a fresh exact Staging Write handshake, then apply with <code>owner_intent_sha256</code> equal to the receipt above and all expected plan fields. The receipt is not an execution ticket. Finish with a NEW-REQUEST readback of grant counts and candidate binding, then recheck the runtime recovery checkpoint and Context source selection.</p>';
        echo '</details>';
        // Post/Redirect/Get must display the persisted terminal state, not
        // return to the same blank confirmation form after a successful POST.
        if ( in_array( $view['state'], array( 'OWNER_INTENT_CURRENT', 'OWNER_INTENT_CLAIMED' ), true ) ) {
            echo '<p><a href="' . esc_url( admin_url( 'admin.php?page=mad4b-operational-remediation' ) ) . '">View current Control Plane runtime status</a></p></div>';
            return;
        }
        echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
        echo '<input type="hidden" name="action" value="' . esc_attr( self::ACTION ) . '">';
        echo '<input type="hidden" name="review_digest" value="' . esc_attr( $review['review_digest'] ) . '">';
        wp_nonce_field( self::ACTION . ':' . $review['review_digest'] );
        echo '<p>Type <code>' . esc_html( self::CONFIRM ) . '</code></p>';
        echo '<input type="text" name="confirmation" style="width:36em" required>';
        submit_button( 'Attest exact Staging owner decision' );
        echo '</form></div>';
    }
    public static function post() {
        if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) || ! self::owner() )
            wp_die( 'Owner POST required.' );
        $review = isset( $_POST['review_digest'] ) && is_string( $_POST['review_digest'] )
            ? sanitize_text_field( wp_unslash( $_POST['review_digest'] ) ) : '';
        check_admin_referer( self::ACTION . ':' . $review );
        $confirmation = isset( $_POST['confirmation'] ) && is_string( $_POST['confirmation'] )
            ? sanitize_text_field( wp_unslash( $_POST['confirmation'] ) ) : '';
        $result = self::attest( array( 'review_digest'=>$review, 'confirmation'=>$confirmation ) );
        if ( is_wp_error( $result ) ) wp_die( esc_html( $result->get_error_code() ) );
        // Read the just-persisted signed option. The redirect query is never
        // an authority receipt and must not be shown as success if stale.
        // attest() already verified the exact current plan before persisting.
        // Recheck the signed saved option against that reviewed plan digest here;
        // the fresh GET re-evaluates present plan readiness independently.
        $verified = self::page_intent_status( array( 'review_digest' => $review ) );
        if ( 'OWNER_INTENT_CURRENT' !== $verified['state']
            || ! hash_equals( (string) $result['receipt_sha256'], (string) $verified['receipt_sha256'] ) )
            wp_die( 'Owner intent was submitted but a fresh signed readback could not be verified. Do not retry until the current status is inspected.' );
        wp_safe_redirect( admin_url( 'tools.php?page=' . self::PAGE . '&intent=recorded' ), 303 );
        exit;
    }
}
