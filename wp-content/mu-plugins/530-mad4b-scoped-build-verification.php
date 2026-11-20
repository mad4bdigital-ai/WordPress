<?php
/**
 * MAD4B selective build integrity proof. Read-only, non-authorizing.
 * Routine drafts hash their *code-owned* material dependency subset; all
 * other operations keep the canonical full package provenance requirement.
 * This evidence cannot create grants or replace candidate-bound authority.
 */
defined( 'ABSPATH' ) || exit;
final class MAD4B_SCP_Scoped_Build_Verification {
    const CONTRACT = 'mad4b.scoped-build-verification.v1';
    const ABILITY = 'mad4b/scoped-build-verification';

    private static function common_dependencies() {
        return array(
            'includes/class-mad4b-scp-policy.php',
            'includes/class-mad4b-scp-authorization.php',
            'includes/class-mad4b-scp-agent-grant-lifecycle.php',
            'includes/class-mad4b-scp-staging-write-authority.php',
            'includes/class-mad4b-scp-execution-authority.php',
            'includes/class-mad4b-scp-execution-fence.php',
            'includes/class-mad4b-scp-execution-commit-guard.php',
            'includes/class-mad4b-scp-approval-tickets.php',
            'includes/class-mad4b-scp-abilities.php',
            'includes/class-mad4b-scp-servers.php',
            'includes/class-mad4b-scp-impact-policy.php',
            'includes/class-mad4b-scp-resource-constraint-set.php',
            'includes/class-mad4b-scp-context-preflight.php',
            'includes/class-mad4b-scp-agent-registry.php',
            'includes/class-mad4b-scp-identity-context.php',
            'includes/class-mad4b-scp-site-profile.php',
            'includes/class-mad4b-scp-transport-context.php',
            'includes/class-mad4b-scp-mcp-peer-governance.php',
            'includes/class-mad4b-scp-restore-epoch.php',
            'includes/class-mad4b-scp-replay-policy.php',
            'includes/class-mad4b-scp-connector-resilience.php',
            'includes/class-mad4b-scp-runtime-generation-fence.php',
            'includes/class-mad4b-scp-audit.php',
            'includes/class-mad4b-scp-approval-repository.php',
            'includes/class-mad4b-scp-capability-descriptor-registry.php',
        );
    }
    private static function content_dependencies() {
        return array(
            'includes/class-mad4b-scp-content-experience-profiles.php',
            'includes/class-mad4b-scp-content-experience-runtime.php',
            'includes/trait-mad4b-scp-content-experience-ability-definitions.php',
            'includes/class-mad4b-scp-content-experience-governance.php',
            'includes/class-mad4b-scp-content-experience-media-rights.php',
        );
    }
    private static function routine_content_ability( $ability, $input ) {
        if ( ! is_array( $input ) ) $input = array();
        $status = isset( $input['post_status'] ) ? sanitize_key( (string) $input['post_status'] ) : '';
        $is_create = 'mad4b/content-create-post' === $ability;
        $is_update = 'mad4b/content-update-post' === $ability;
        if ( class_exists( 'MAD4B_SCP_Content_Experience_Profiles' ) ) {
            $profile = MAD4B_SCP_Content_Experience_Profiles::profile_for_ability( $ability );
            if ( is_array( $profile ) && ! empty( $profile['enabled'] )
                && ( $profile['creation_status'] ?? '' ) === 'draft'
                && ( $profile['live_update_mode'] ?? '' ) === 'draft_first' ) {
                $routes = isset( $profile['routes'] ) && is_array( $profile['routes'] ) ? $profile['routes'] : array();
                $is_create = ( $routes['create_apply'] ?? '' ) === $ability;
                $is_update = ( $routes['update_apply'] ?? '' ) === $ability;
            }
        }
        if ( $is_create ) return in_array( $status, array( '', 'draft', 'pending' ), true );
        if ( ! $is_update || ! isset( $input['post_id'] ) ) return false;
        $id = absint( $input['post_id'] );
        $current = $id > 0 && function_exists( 'get_post_status' ) ? get_post_status( $id ) : false;
        return in_array( $current, array( 'draft', 'pending', 'auto-draft' ), true )
            && in_array( $status, array( '', 'draft', 'pending' ), true );
    }
    /** Scope only the original content writes and enabled Content Experience mutation routes. */
    public static function governs_content_write( $ability ) {
        if ( in_array( (string) $ability, array( 'mad4b/content-create-post', 'mad4b/content-update-post' ), true ) ) return true;
        if ( ! class_exists( 'MAD4B_SCP_Content_Experience_Profiles' ) ) return false;
        $profile = MAD4B_SCP_Content_Experience_Profiles::profile_for_ability( $ability );
        if ( ! is_array( $profile ) || empty( $profile['enabled'] ) ) return false;
        $routes = is_array( $profile['routes'] ?? null ) ? $profile['routes'] : array();
        foreach ( array( 'create_apply', 'update_apply', 'publish_apply' ) as $key ) {
            if ( ( $routes[ $key ] ?? '' ) === (string) $ability ) return true;
        }
        return false;
    }
    /** Commercial fields may appear in nested metadata; never treat those as draft-only. */
    private static function commercial_payload_present( $value, $depth = 0 ) {
        if ( ! is_array( $value ) ) return false;
        if ( $depth >= 5 ) return true;
        foreach ( $value as $key => $nested ) {
            if ( is_string( $key ) && preg_match(
                '/(?:^|_)(?:price|rate|rates|deposit|balance|payment|booking|discount|markup|currency|tax|commerce)(?:_|$)/i',
                $key ) ) return true;
            if ( is_array( $nested ) && self::commercial_payload_present( $nested, $depth + 1 ) ) return true;
        }
        return false;
    }
    public static function verify( $ability, $input = array() ) {
        $ability = trim( (string) $ability );
        if ( ! function_exists( 'wp_has_ability' ) || ! wp_has_ability( $ability )
            || ! class_exists( 'MAD4B_SCP_Site_Profile' )
            || ! MAD4B_SCP_Site_Profile::origin_enrolled()
            || ! MAD4B_SCP_Site_Profile::site_urls_match_enrollment()
            || ! class_exists( 'MAD4B_SCP_Live_Acceptance_Observer' ) ) {
            return new WP_Error( 'mad4b_scoped_build_runtime_not_enrolled', 'Exact registered Ability and enrolled site required.' );
        }
        $routine = self::governs_content_write( $ability ) && self::routine_content_ability( $ability, $input );
        if ( $routine && class_exists( 'MAD4B_SCP_Impact_Policy' ) ) {
            $impact = MAD4B_SCP_Impact_Policy::classify( $ability, 'core', $input );
            $routine = is_array( $impact ) && ( $impact['impact'] ?? '' ) === 'low'
                && empty( $impact['approval_required'] );
        }
        if ( $routine && self::commercial_payload_present( $input ) ) $routine = false;
        $scope = $routine ? 'scoped' : 'full';
        $result = array(
            'contract' => self::CONTRACT, 'ability' => $ability,
            'environment' => MAD4B_SCP_Site_Profile::current_environment(),
            'scope' => $scope, 'exemption_eligible' => $routine,
            'full_required' => ! $routine, 'verification_ready' => false,
            'verified_files' => 0, 'blockers' => array(),
            'authorizing' => false, 'grants_changed' => false,
            'candidate_binding_changed' => false, 'mutation_performed' => false,
        );
        if ( ! $routine ) {
            $full = MAD4B_SCP_Live_Acceptance_Observer::build_provenance_status();
            $ready = is_array( $full ) && ! empty( $full['manifest_valid'] )
                && ! empty( $full['runtime_manifest_match'] ) && empty( $full['stale'] )
                && empty( $full['provenance_mismatch'] );
            $result['verification_ready'] = $ready;
            $result['blockers'] = $ready ? array() : array( 'full_build_provenance_not_current' );
            return $result;
        }
        $identity = MAD4B_SCP_Live_Acceptance_Observer::build_provenance_identity_status();
        if ( ! is_array( $identity ) || empty( $identity['identity_ready'] )
            || empty( $identity['manifest_valid'] ) ) {
            $result['blockers'][] = 'manifest_identity_invalid';
            return $result;
        }
        $base = defined( 'MAD4B_SCP_DIR' ) ? MAD4B_SCP_DIR : '';
        if ( '' === $base || ! is_readable( $base . 'MAD4B-BUILD-PROVENANCE.json' ) ) {
            $result['blockers'][] = 'package_root_unavailable'; return $result;
        }
        $manifest = json_decode( (string) file_get_contents( $base . 'MAD4B-BUILD-PROVENANCE.json' ), true );
        if ( ! is_array( $manifest ) || ! is_array( $manifest['package_files'] ?? null ) ) {
            $result['blockers'][] = 'package_manifest_invalid'; return $result;
        }
        $indexed = array(); $lines = array();
        foreach ( $manifest['package_files'] as $row ) {
            if ( ! is_array( $row ) || ! isset( $row['path'], $row['bytes'], $row['sha256'] )
                || ! is_string( $row['path'] ) || ! preg_match( '#^[A-Za-z0-9_./-]+$#', $row['path'] )
                || strpos( $row['path'], '..' ) !== false || isset( $indexed[ $row['path'] ] )
                || ! is_numeric( $row['bytes'] ) || (int) $row['bytes'] < 0
                || ! is_string( $row['sha256'] ) || ! preg_match( '/^[a-f0-9]{64}$/', $row['sha256'] ) ) {
                $result['blockers'][] = 'package_manifest_entry_invalid'; return $result;
            }
            $indexed[ $row['path'] ] = $row;
            $lines[] = $row['path'] . "\0" . (int) $row['bytes'] . "\0" . $row['sha256'] . "\n";
        }
        sort( $lines, SORT_STRING );
        if ( empty( $lines ) || ! hash_equals( (string) ( $manifest['package_manifest_digest'] ?? '' ), hash( 'sha256', implode( '', $lines ) ) ) ) {
            $result['blockers'][] = 'package_manifest_digest_invalid'; return $result;
        }
        $dependencies = array_values( array_unique( array_merge( self::common_dependencies(), self::content_dependencies() ) ) );
        foreach ( $dependencies as $relative ) {
            $row = $indexed[ $relative ] ?? null;
            $file = $base . $relative;
            if ( ! is_array( $row ) || ! is_file( $file ) || ! is_readable( $file )
                || (int) filesize( $file ) !== (int) $row['bytes']
                || ! hash_equals( (string) $row['sha256'], (string) hash_file( 'sha256', $file ) ) ) {
                $result['blockers'][] = 'material_file_mismatch:' . $relative;
                continue;
            }
            $result['verified_files']++;
        }
        $result['dependency_count'] = count( $dependencies );
        $result['verification_ready'] = empty( $result['blockers'] )
            && $result['verified_files'] === count( $dependencies );
        $result['exemption_applies'] = $result['verification_ready'];
        return $result;
    }
    public static function can_read() { return current_user_can( 'manage_options' ); }
    public static function register() {
        if ( ! function_exists( 'wp_register_ability' ) || wp_has_ability( self::ABILITY ) ) return;
        wp_register_ability( self::ABILITY, array(
            'label' => 'Scoped Build Verification',
            'description' => 'Read-only verification of exact manifest-listed material files for draft changes; full package provenance remains mandatory for all other operations.',
            'category' => 'mad4b-read', 'permission_callback' => array( __CLASS__, 'can_read' ),
            'execute_callback' => array( __CLASS__, 'plan' ),
            'input_schema' => array( 'type' => 'object', 'properties' => array(
                'ability_name' => array( 'type' => 'string', 'minLength' => 3, 'maxLength' => 191 ),
                'post_id' => array( 'type' => 'integer', 'minimum' => 1 ),
                'post_status' => array( 'type' => 'string', 'enum' => array( 'draft', 'pending', 'publish', 'private' ) ),
            ), 'required' => array( 'ability_name' ), 'additionalProperties' => false ),
            'output_schema' => array( 'type' => 'object', 'additionalProperties' => true ),
            'meta' => array( 'public' => false, 'show_in_rest' => false,
                'mcp' => array( 'public' => false, 'type' => 'tool', 'surface' => 'read', 'production_mutation_allowed' => false ),
                'annotations' => array( 'readonly' => true, 'destructive' => false, 'idempotent' => true ) ),
        ) );
    }
    public static function plan( $input = array() ) {
        if ( ! self::can_read() ) return new WP_Error( 'mad4b_scoped_build_admin_required', 'Read capability required.' );
        $input = is_array( $input ) ? $input : array();
        return self::verify( $input['ability_name'] ?? '', $input );
    }
    public static function cli_plan( $args, $assoc ) {
        if ( $args || ! is_array( $assoc ) || array_diff( array_keys( $assoc ), array( 'ability', 'post-id', 'post-status' ) )
            || empty( $assoc['ability'] ) ) WP_CLI::error( 'Provide --ability and optional --post-id / --post-status.' );
        $input = array();
        if ( isset( $assoc['post-id'] ) ) $input['post_id'] = absint( $assoc['post-id'] );
        if ( isset( $assoc['post-status'] ) ) $input['post_status'] = sanitize_key( $assoc['post-status'] );
        $result = self::verify( $assoc['ability'], $input );
        if ( is_wp_error( $result ) ) WP_CLI::error( $result->get_error_code() );
        WP_CLI::line( wp_json_encode( $result, JSON_UNESCAPED_SLASHES ) );
    }
    public static function boot() {
        add_action( 'wp_abilities_api_init', array( __CLASS__, 'register' ), 65 );
        if ( defined( 'WP_CLI' ) && WP_CLI && class_exists( 'WP_CLI' ) )
            WP_CLI::add_command( 'mad4b build scoped-plan', array( __CLASS__, 'cli_plan' ) );
    }
}
MAD4B_SCP_Scoped_Build_Verification::boot();
