<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Feature 007: bounded, non-authorizing A-Z obstacle and source-scope planner.
 * One remote read returns safe next steps; it never executes, mints consent,
 * certifies a package, alters ownership or broadens WordPress permissions.
 */
final class MAD4B_SCP_Runtime_Obstacle_Resolution {
    const CONTRACT = 'mad4b.runtime-obstacle-resolution.v1';
    const ABILITY = 'mad4b/runtime-obstacle-plan';
    const FOLDER_ABILITY = 'mad4b/selected-folder-scope-proof';
    const MAX_BLOCKERS = 32;

    public static function boot() {
        if ( function_exists( 'add_action' ) )
            add_action( 'wp_abilities_api_init', array( __CLASS__, 'register_abilities' ), 39 );
    }

    public static function register_abilities() {
        if ( ! function_exists( 'wp_register_ability' ) ) return;
        $definitions = array(
            self::ABILITY => array( 'callback' => 'plan',
                'properties' => array(
                    'blockers' => array( 'type' => 'array', 'items' => array( 'type' => 'string', 'maxLength' => 128 ), 'maxItems' => self::MAX_BLOCKERS ),
                    'target_post_type' => array( 'type' => 'string', 'maxLength' => 64 ),
                    'perform_safe_probes' => array( 'type' => 'boolean' ) ) ),
            self::FOLDER_ABILITY => array( 'callback' => 'selected_folder_proof',
                'properties' => array( 'source_id' => array( 'type' => 'string', 'pattern' => '^[a-f0-9]{64}$' ) ) ),

        );
        foreach ( $definitions as $name => $def ) {
            if ( function_exists( 'wp_has_ability' ) && wp_has_ability( $name ) ) continue;
            wp_register_ability( $name, array(
                'label' => ucwords( str_replace( '-', ' ', substr( $name, 6 ) ) ),
                'description' => 'Governed read-only source evidence and runtime dependency resolution; no approval or mutations.',
                'category' => 'mad4b-read', 'execute_callback' => array( __CLASS__, $def['callback'] ),
                'permission_callback' => array( 'MAD4B_SCP_Policy', 'can_read' ),
                'input_schema' => array( 'type' => 'object', 'properties' => $def['properties'],
                    'additionalProperties' => false ),
                'output_schema' => array( 'type' => 'object', 'additionalProperties' => true ),
                'meta' => array( 'public' => false, 'show_in_rest' => false,
                    'mcp' => array( 'public' => false, 'type' => 'tool', 'surface' => 'read' ),
                    'annotations' => array( 'readonly' => true, 'destructive' => false, 'idempotent' => true ) ),
            ) );
        }
    }

    private static function allowed() {
        return class_exists( 'MAD4B_SCP_Policy' ) && MAD4B_SCP_Policy::can_read();
    }

    private static function available( $ability ) {
        return function_exists( 'wp_has_ability' ) && wp_has_ability( $ability );
    }

    private static function site() {
        $site = class_exists( 'MAD4B_SCP_Site_Profile' )
            ? MAD4B_SCP_Site_Profile::status() : array();
        return is_array( $site ) ? $site : array();
    }

    private static function site_stamp( $site ) {
        return array(
            'site_uuid' => (string) ( $site['site_uuid'] ?? '' ),
            'profile_digest' => (string) ( $site['profile_digest'] ?? '' ),
            'canonical_origin' => (string) ( $site['canonical_origin'] ?? '' ),
            'environment' => (string) ( $site['environment'] ?? '' ),
            'origin_match' => true === ( $site['origin_match'] ?? false ),
            'environment_match' => true === ( $site['environment_match'] ?? false ),
        );
    }

    /**
     * Same selected folder may scope first-party research once all original
     * file identities have been read from Drive. Quarantined legacy ownership
     * still needs a separate exact owner approval and review of Brand assets.
     */
    public static function selected_folder_proof( $input = array() ) {
        if ( ! self::allowed() )
            return new WP_Error( 'mad4b_selected_folder_permission_denied', 'Governed read required.' );
        if ( ! is_array( $input ) || array_diff( array_keys( $input ), array( 'source_id' ) ) )
            return new WP_Error( 'mad4b_selected_folder_input_invalid', 'Unexpected folder input.' );
        $site = self::site();
        $stamp = self::site_stamp( $site );
        if ( 'staging' !== $stamp['environment'] || ! $stamp['origin_match'] ||
            ! $stamp['environment_match'] || '' === $stamp['site_uuid'] )
            return new WP_Error( 'mad4b_selected_folder_site_invalid', 'Exact enrolled Staging site required.' );
        if ( ! class_exists( 'MAD4B_SCP_Context_Authority' ) ||
            ! method_exists( 'MAD4B_SCP_Context_Authority', 'legacy_owner_transfer_discover' ) ||
            ! method_exists( 'MAD4B_SCP_Context_Authority', 'legacy_owner_transfer_evidence' ) )
            return new WP_Error( 'mad4b_selected_folder_provider_missing', 'Existing Context registry unavailable.' );
        $source = strtolower( trim( (string) ( $input['source_id'] ?? '' ) ) );
        if ( '' !== $source && ! preg_match( '/^[a-f0-9]{64}$/D', $source ) )
            return new WP_Error( 'mad4b_selected_folder_source_invalid', 'Invalid saved source identity.' );
        $discovery = MAD4B_SCP_Context_Authority::legacy_owner_transfer_discover( array() );
        if ( is_wp_error( $discovery ) ) return $discovery;
        $candidates = is_array( $discovery ) && is_array( $discovery['eligible_unbound_candidates'] ?? null )
            ? $discovery['eligible_unbound_candidates'] : array();
        if ( '' === $source && 1 !== count( $candidates ) )
            return array( 'contract' => self::CONTRACT . '.folder.v1',
                'state' => 'EXACT_FOLDER_SELECTION_REQUIRED',
                'candidate_count' => count( $candidates ),
                'next_read_ability' => 'context/legacy-owner-transfer-discover',
                'scope_verified' => false, 'ownership_transferred' => false,
                'read_only' => true, 'mutation_performed' => false );
        if ( '' === $source ) $source = (string) ( $candidates[0]['source_id'] ?? '' );
        $matched = false;
        foreach ( $candidates as $candidate ) {
            if ( is_array( $candidate ) &&
                hash_equals( $source, (string) ( $candidate['source_id'] ?? '' ) ) )
                $matched = true;
        }
        if ( ! $matched ) return new WP_Error( 'mad4b_selected_folder_quarantined_source_mismatch',
            'Only the exact enrolled eligible selected source can be scoped.' );
        $proof = MAD4B_SCP_Context_Authority::legacy_owner_transfer_evidence(
            array( 'source_id' => $source ) );
        if ( is_wp_error( $proof ) ) return $proof;
        if ( ! is_array( $proof ) || true !== ( $proof['folder_membership_verified'] ?? false ) ||
            ! preg_match( '/^[a-f0-9]{64}$/D', (string) ( $proof['provider_proof_sha256'] ?? '' ) ) ||
            ! hash_equals( $source, (string) ( $proof['source_id'] ?? '' ) ) )
            return new WP_Error( 'mad4b_selected_folder_provider_proof_incomplete',
                'Fresh complete original folder/file membership proof required.' );
        if ( self::site_stamp( self::site() ) !== $stamp )
            return new WP_Error( 'mad4b_selected_folder_site_drift', 'Site identity changed during provider read.' );
        return array(
            'contract' => self::CONTRACT . '.folder.v1',
            'state' => 'FOLDER_SCOPE_VERIFIED_OWNER_REVIEW_PENDING',
            'site_uuid' => $stamp['site_uuid'], 'profile_digest' => $stamp['profile_digest'],
            'source_id' => $source,
            'selected_folder_id' => (string) ( $proof['original_folder_id'] ?? '' ),
            'matched_file_count' => (int) ( $proof['matched_file_count'] ?? 0 ),
            'scope_proof_sha256' => (string) $proof['provider_proof_sha256'],
            'source_plan_sha256' => (string) ( $proof['plan_sha256'] ?? '' ),
            'scope_verified' => true, 'research_scope_eligible' => true,
            'next_read_ability' => 'context/legacy-owner-transfer-plan',
            'one_exact_owner_review_for_legacy_transfer' => true,
            'approved_brand_core_issued' => false,
            'legal_ownership_inferred' => false, 'ownership_transferred' => false,
            'write_authority_created' => false,
            'read_only' => true, 'mutation_performed' => false,
        );
    }

    private static function routes() {
        return array(
            'selected_folder_scope' => array( 'mad4b/selected-folder-scope-proof', 'context/legacy-owner-transfer-plan', true ),
            'google_drive_auth' => array( 'context/runtime-readiness', 'context/brand-core-control-loop', true ),
            'brand_context' => array( 'mad4b/brand-core-remote-recovery-status', 'context/remote-owner-review-plan', true ),
            'host_binding' => array( 'mad4b/deployment-binding-native-plan', 'mad4b/host-doctor', true ),
            'write_authority' => array( 'mad4b/write-runtime-certification', 'mad4b/approval-plan', true ),
            'content_model' => array( 'mad4b/content-model-discover', 'mad4b/content-experience-profile-plan', true ),
            'semantic_structure' => array( 'mad4b/semantic-structure-inspect', 'mad4b/aci01-native-relation-audit', true ),
            'source_rights' => array( 'mad4b/scrape-transfer-plan', 'mad4b/research-provider-plan', false ),
            'draft_creation' => array( 'mad4b/content-orchestration-plan', 'mad4b/content-bundle-readback', true ),
            'publication' => array( 'mad4b/publication-verification-evaluate', 'mad4b/content-update-post', true ),
            'release_update' => array( 'mad4b/control-plane-update-status', 'mad4b/control-plane-selected-head-plan', true ),
            'runtime_unknown' => array( 'mad4b/chatgpt-tool-projection-discover', 'mad4b/operation-route-discover', true ),
        );
    }

    private static function classify( $blocker ) {
        if ( preg_match( '/folder|source_.*ownership|legacy_owner|quarantine/i', $blocker ) ) return 'selected_folder_scope';
        if ( preg_match( '/drive|oauth|token|revocation|refresh|reauth/i', $blocker ) ) return 'google_drive_auth';
        if ( preg_match( '/brand_core|tone_of_voice|editorial_guidelines|brand_strategy|context_receipt/i', $blocker ) ) return 'brand_context';
        if ( preg_match( '/host.*binding|deployment_binding|physical_identity/i', $blocker ) ) return 'host_binding';
        if ( preg_match( '/write_authority|write_runtime|candidate_binding|approval|ticket|step.up/i', $blocker ) ) return 'write_authority';
        if ( preg_match( '/relation|taxonomy|jetengine|meta_mapper|template_composition/i', $blocker ) ) return 'semantic_structure';
        if ( preg_match( '/profile|content_model|post_type|field_schema/i', $blocker ) ) return 'content_model';
        if ( preg_match( '/rights|license|supplier|media|competitive|competitor/i', $blocker ) ) return 'source_rights';
        if ( preg_match( '/draft|bundle|content_apply/i', $blocker ) ) return 'draft_creation';
        if ( preg_match( '/publication|publish|front.end|seo|wpml/i', $blocker ) ) return 'publication';
        if ( preg_match( '/build|archive|release|install|package|zip/i', $blocker ) ) return 'release_update';
        return 'runtime_unknown';
    }

    public static function plan( $input = array() ) {
        if ( ! self::allowed() )
            return new WP_Error( 'mad4b_runtime_resolver_read_denied', 'Governed read required.' );
        $planned = self::reduce( $input );
        if ( is_wp_error( $planned ) ) return $planned;
        if ( ! empty( $input['perform_safe_probes'] ) ) {
            foreach ( $planned['resolution_routes'] as $route ) {
                if ( 'selected_folder_scope' !== $route['category'] ) continue;
                $probe = self::selected_folder_proof( array() );
                $planned['selected_folder_live_probe'] = is_wp_error( $probe )
                    ? array( 'state' => 'BLOCKED', 'error_code' => $probe->get_error_code() )
                    : $probe;
                break; // One bounded, idempotent external-provider read per call.
            }
        }
        return $planned;
    }

    public static function reduce( $input ) {
        if ( ! is_array( $input ) || array_diff( array_keys( $input ), array( 'blockers', 'target_post_type', 'perform_safe_probes' ) ) )
            return new WP_Error( 'mad4b_runtime_resolver_input_invalid', 'Unexpected resolver input.' );
        $blockers = $input['blockers'] ?? array();
        if ( ! is_array( $blockers ) || count( $blockers ) > self::MAX_BLOCKERS )
            return new WP_Error( 'mad4b_runtime_resolver_blockers_invalid', 'Bounded blocker list required.' );
        $post_type = (string) ( $input['target_post_type'] ?? '' );
        if ( '' !== $post_type && ! preg_match( '/^[a-z0-9_-]{1,64}$/D', $post_type ) )
            return new WP_Error( 'mad4b_runtime_resolver_type_invalid', 'Post type not canonical.' );
        $rows = array(); $unique = array();
        foreach ( $blockers as $blocker ) {
            if ( ! is_string( $blocker ) || strlen( $blocker ) > 128 ||
                ! preg_match( '/^[A-Za-z0-9_.:-]{1,128}$/D', $blocker ) )
                return new WP_Error( 'mad4b_runtime_resolver_blocker_invalid', 'Invalid observed blocker code.' );
            $unique[$blocker] = true;
        }
        ksort( $unique, SORT_STRING );
        foreach ( array_keys( $unique ) as $blocker ) {
            $kind = self::classify( $blocker );
            $r = self::routes()[$kind];
            $rows[] = array(
                'blocker' => $blocker, 'category' => $kind,
                'next_read_ability' => $r[0], 'next_plan_ability' => $r[1],
                'read_available' => self::available( $r[0] ),
                'plan_available' => self::available( $r[1] ),
                'separate_approval_if_mutation' => $r[2],
                'action_executed' => false, 'blocker_resolved' => false,
            );
        }
        $flow = array(
            'discover_site_model' => array( 'mad4b/content-model-discover' ),
            'inspect_relationships_and_shared_data' => array( 'mad4b/semantic-structure-inspect' ),
            'select_brand_sources_and_scope' => array( 'mad4b/selected-folder-scope-proof' ),
            'review_brand_context' => array( 'mad4b/brand-core-remote-recovery-status' ),
            'scrape_source_evidence' => array( 'mad4b/scrape-transfer-plan' ),
            'prepare_draft' => array( 'mad4b/content-orchestration-plan' ),
            'approve_and_write_draft' => array( 'mad4b/content-apply-bundle' ),
            'readback_draft' => array( 'mad4b/content-bundle-readback' ),
            'approve_publication' => array( 'mad4b/content-update-post' ),
            'verify_published_result' => array( 'mad4b/publication-verification-evaluate' ),
        );
        $steps = array();
        foreach ( $flow as $stage => $abilities ) {
            $ability = $abilities[0];
            $steps[] = array( 'stage' => $stage, 'ability' => $ability,
                'registered' => self::available( $ability ),
                'execution_permitted_by_this_plan' => false );
        }
        return array(
            'contract' => self::CONTRACT,
            'state' => $rows ? 'RUNTIME_OBSTACLES_REQUIRE_GOVERNED_RESOLUTION' : 'NO_OBSERVED_BLOCKERS',
            'target_post_type' => $post_type,
            'observed_blockers' => array_keys( $unique ),
            'resolution_routes' => $rows, 'publication_lifecycle' => $steps,
            'requery_after_each_step' => true, 'fresh_exact_site_approval_for_mutations' => true,
            'old_tickets_must_not_be_reused' => true,
            'source_scope_not_ownership_transfer' => true,
            'authorizing' => false, 'mutation_performed' => false, 'read_only' => true,
        );
    }
}
