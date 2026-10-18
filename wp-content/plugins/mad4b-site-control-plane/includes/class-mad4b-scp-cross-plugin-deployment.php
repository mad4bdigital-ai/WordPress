<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Reviewed multi-repository WordPress plugin deployment discovery and planning.
 * Does not fetch arbitrary ZIPs, install, modify options, or grant capabilities.
 * Actual Staging mutations remain with certified package/update abilities.
 * Production requires a separately certified production installer and approval.
 */
final class MAD4B_SCP_Cross_Plugin_Deployment {
    const CONTRACT = 'mad4b.cross-plugin-deployment.v1';
    const REGISTRY = 'config/cross-plugin-sources.json';

    public static function boot() {
        add_action( 'wp_abilities_api_init', array( __CLASS__, 'register_abilities' ), 34 );
    }

    public static function register_abilities() {
        if ( ! function_exists( 'wp_register_ability' ) ) return;
        $abilities = array(
            'mad4b/plugin-deployment-sources' => array( 'label' => 'Discover Reviewed Plugin Sources',
                'description' => 'Read reviewed same/external repository sources and plugin installation state.',
                'execute_callback' => array( __CLASS__, 'discover' ),
                'input_schema' => array( 'type' => 'object', 'additionalProperties' => false ) ),
            'mad4b/plugin-deployment-intake-plan' => array(
                'label' => 'Review New External Plugin Source',
                'description' => 'Validate a repository/source proposal without storing, fetching or installing it.',
                'execute_callback' => array( __CLASS__, 'onboarding_plan' ),
                'input_schema' => array( 'type' => 'object', 'additionalProperties' => false,
                    'required' => array( 'source_id', 'repository', 'plugin_subdirectory', 'plugin_file' ),
                    'properties' => array(
                        'source_id' => array( 'type' => 'string', 'maxLength' => 64 ),
                        'repository' => array( 'type' => 'string', 'maxLength' => 172 ),
                        'plugin_subdirectory' => array( 'type' => 'string', 'maxLength' => 240 ),
                        'plugin_file' => array( 'type' => 'string', 'maxLength' => 150 ),
                        'provider_id' => array( 'type' => 'string', 'maxLength' => 64 ),
                    ) ),
            ),
            'mad4b/plugin-deployment-plan' => array( 'label' => 'Plan Cross-Plugin Deployment',
                'description' => 'Preview exact Git source, plugin install/replace, artifact and environment gates. Never mutates.',
                'execute_callback' => array( __CLASS__, 'plan' ),
                'input_schema' => array( 'type' => 'object', 'additionalProperties' => false,
                    'required' => array( 'source_id', 'ref_type', 'reference' ),
                    'properties' => array(
                        'source_id' => array( 'type' => 'string', 'minLength' => 1, 'maxLength' => 64 ),
                        'ref_type' => array( 'type' => 'string', 'enum' => array( 'commit', 'branch', 'pull_request', 'branch_commit' ) ),
                        'reference' => array( 'type' => 'string', 'minLength' => 1, 'maxLength' => 161 ),
                        'reason' => array( 'type' => 'string', 'maxLength' => 500 ),
                    ) ),
            ),
        );
        foreach ( $abilities as $name => $definition ) {
            if ( function_exists( 'wp_has_ability' ) && wp_has_ability( $name ) ) continue;
            wp_register_ability( $name, array_merge( $definition, array(
                'category' => 'mad4b-read',
                'permission_callback' => array( 'MAD4B_SCP_Policy', 'can_read' ),
                'output_schema' => array( 'type' => 'object', 'additionalProperties' => true ),
                'meta' => array( 'public' => false, 'show_in_rest' => false,
                    'mcp' => array( 'public' => false, 'type' => 'tool', 'surface' => 'read' ),
                    'annotations' => array( 'readonly' => true, 'destructive' => false, 'idempotent' => true ) ),
            ) ) );
        }
    }


    /**
     * Accept an external/new repository as a review REQUEST only.
     * No GitHub requests, WordPress settings writes, credentials or zip loads.
     */
    public static function onboarding_plan( $input ) {
        if ( ! is_array( $input ) ) return new WP_Error( 'cross_plugin_intake_invalid', 'Expected an object.' );
        $id = (string) ( $input['source_id'] ?? '' );
        $repo = (string) ( $input['repository'] ?? '' );
        $directory = (string) ( $input['plugin_subdirectory'] ?? '' );
        $plugin = (string) ( $input['plugin_file'] ?? '' );
        $provider = (string) ( $input['provider_id'] ?? $id );
        $folder = basename( $directory );
        if ( ! preg_match( '/^[a-z][a-z0-9_-]{0,63}$/D', $id )
            || ! preg_match( '/^[a-z][a-z0-9_-]{0,63}$/D', $provider )
            || ! preg_match( '#^[A-Za-z0-9_.-]{1,70}/[A-Za-z0-9_.-]{1,100}$#D', $repo )
            || false !== strpos( $repo, '..' )
            || ! preg_match( '#^[A-Za-z0-9_.-]+(?:/[A-Za-z0-9_.-]+){0,12}$#D', $directory )
            || false !== strpos( $directory, '..' )
            || ! preg_match( '#^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+\.php$#D', $plugin )
            || 0 !== strpos( $plugin, $folder . '/' ) )
            return new WP_Error( 'cross_plugin_intake_untrusted_path', 'Use canonical repository, directory and plugin file names.' );
        $existing = self::sources();
        if ( isset( $existing[$id] ) )
            return new WP_Error( 'cross_plugin_intake_source_exists', 'Source ID is already reviewed; amend it through repository code review.' );
        $candidate = array(
            'id' => $id, 'label' => $id, 'repository' => $repo,
            'plugin_subdirectory' => $directory,
            'plugin_file' => $plugin, 'provider_id' => $provider,
            'review_state' => 'needs_repository_owner_review',
            'source_commit_sha' => '', 'artifact_sha256' => '',
            'artifact_version' => '', 'package_certification' => 'pending',
            'environments' => array( 'staging', 'production' ),
            'production_promotion_attestation' => '',
        );
        return array(
            'contract' => 'mad4b.cross-plugin-onboarding-plan.v1',
            'state' => 'NEEDS_SOURCE_REVIEW',
            'candidate' => $candidate,
            'candidate_digest' => hash( 'sha256', (string) json_encode( $candidate, JSON_UNESCAPED_SLASHES ) ),
            'next_step' => 'review_registry_and_publish_exact_certified_artifact',
            'github_request_performed' => false, 'mutation_performed' => false,
            'installation_authorized' => false, 'production_authorized' => false,
        );
    }

    /** Strict source manifest. External repositories are first-class, but owner-reviewed in source. */
    public static function sources() {
        $file = dirname( __DIR__ ) . '/' . self::REGISTRY;
        $json = is_readable( $file ) ? file_get_contents( $file ) : '';
        $decoded = json_decode( (string) $json, true );
        if ( ! is_array( $decoded ) || 'mad4b.cross-plugin-sources.v1' !== ( $decoded['contract'] ?? '' ) )
            return array();
        $result = array();
        foreach ( $decoded['sources'] ?? array() as $row ) {
            if ( ! is_array( $row ) ) continue;
            $id = (string) ( $row['id'] ?? '' );
            $repo = (string) ( $row['repository'] ?? '' );
            $directory = (string) ( $row['plugin_subdirectory'] ?? '' );
            $plugin = (string) ( $row['plugin_file'] ?? '' );
            $provider = (string) ( $row['provider_id'] ?? '' );
            $folder = basename( $directory );
            if ( ! preg_match( '/^[a-z][a-z0-9_-]{0,63}$/D', $id )
                || ! preg_match( '#^[a-zA-Z0-9_.-]{1,70}/[a-zA-Z0-9_.-]{1,100}$#D', $repo )
                || false !== strpos( $repo, '..' )
                || ! preg_match( '#^[a-zA-Z0-9_.-]+(?:/[a-zA-Z0-9_.-]+){0,12}$#D', $directory )
                || false !== strpos( $directory, '..' )
                || ! preg_match( '#^[a-zA-Z0-9_.-]+/[a-zA-Z0-9_.-]+\.php$#D', $plugin )
                || 0 !== strpos( $plugin, $folder . '/' )
                || ! preg_match( '/^[a-z][a-z0-9_-]{0,63}$/D', $provider )
                || isset( $result[$id] ) ) continue;
            $row['id'] = $id;
            $row['repository'] = $repo;
            $row['plugin_subdirectory'] = $directory;
            $row['plugin_file'] = $plugin;
            $row['provider_id'] = $provider;
            $result[$id] = $row;
            if ( count( $result ) >= 100 ) break;
        }
        ksort( $result, SORT_STRING );
        return $result;
    }

    public static function selection( $input ) {
        if ( ! is_array( $input ) ) return new WP_Error( 'cross_plugin_selection_invalid', 'Selection must be an object.' );
        $id = (string) ( $input['source_id'] ?? '' );
        $ref_type = (string) ( $input['ref_type'] ?? '' );
        $reference = trim( (string) ( $input['reference'] ?? '' ) );
        if ( ! preg_match( '/^[a-z][a-z0-9_-]{0,63}$/D', $id ) || ! isset( self::sources()[$id] ) )
            return new WP_Error( 'cross_plugin_source_unreviewed', 'The repository/plugin source is not in the reviewed source registry.' );
        if ( ! in_array( $ref_type, array( 'commit', 'branch', 'pull_request', 'branch_commit' ), true ) )
            return new WP_Error( 'cross_plugin_ref_type_invalid', 'Unknown source selector.' );
        if ( 'commit' === $ref_type && ! preg_match( '/^[a-f0-9]{40}$/D', $reference ) )
            return new WP_Error( 'cross_plugin_commit_invalid', 'Use the entire lowercase 40-character SHA.' );
        if ( 'branch' === $ref_type && ( strlen( $reference ) > 120
            || ! preg_match( '#^[A-Za-z0-9._/-]+$#D', $reference ) || false !== strpos( $reference, '..' )
            || false !== strpos( $reference, '//' ) || '/' === $reference[0] ) )
            return new WP_Error( 'cross_plugin_branch_invalid', 'Invalid Git branch reference.' );
        if ( 'pull_request' === $ref_type && ! preg_match( '/^[1-9][0-9]{0,8}$/D', $reference ) )
            return new WP_Error( 'cross_plugin_pr_invalid', 'Invalid PR number.' );
        if ( 'branch_commit' === $ref_type ) {
            if ( strlen( $reference ) > 161 || ! preg_match( '/^(.+)@([a-f0-9]{40})$/D', $reference, $pair ) )
                return new WP_Error( 'cross_plugin_pair_invalid', 'Provide branch@40-character-commit.' );
            $validated = self::selection( array( 'source_id' => $id, 'ref_type' => 'branch', 'reference' => $pair[1] ) );
            if ( is_wp_error( $validated ) ) return $validated;
        }
        return array( 'source_id' => $id, 'ref_type' => $ref_type, 'reference' => $reference );
    }

    /** Pure upstream response validation: no arbitrary URL, ZIP, or source code execution. */
    public static function verified_sha_from_response( array $selection, array $response ) {
        $type = $selection['ref_type'];
        $reference = $selection['reference'];
        $sha = '';
        if ( 'branch' === $type ) $sha = (string) ( $response['commit']['sha'] ?? '' );
        elseif ( 'pull_request' === $type ) {
            $repo = self::sources()[$selection['source_id']]['repository'];
            $head_repo = (string) ( $response['head']['repo']['full_name'] ?? '' );
            if ( ! hash_equals( strtolower( $repo ), strtolower( $head_repo ) ) )
                return new WP_Error( 'cross_plugin_forked_pr_denied', 'PR head must be in the reviewed repository.' );
            $sha = (string) ( $response['head']['sha'] ?? '' );
        } elseif ( 'commit' === $type ) $sha = (string) ( $response['sha'] ?? '' );
        else {
            $parts = explode( '@', $reference, 2 );
            $selected = $parts[1];
            if ( ! in_array( (string) ( $response['status'] ?? '' ), array( 'ahead', 'identical' ), true )
                || ! hash_equals( $selected, (string) ( $response['base_commit']['sha'] ?? '' ) )
                || ! hash_equals( $selected, (string) ( $response['merge_base_commit']['sha'] ?? '' ) ) )
                return new WP_Error( 'cross_plugin_ancestry_not_verified', 'Commit does not belong to the selected branch.');
            $sha = $selected;
        }
        if ( ! preg_match( '/^[a-f0-9]{40}$/D', $sha )
            || ( 'commit' === $type && ! hash_equals( $reference, $sha ) ) )
            return new WP_Error( 'cross_plugin_source_sha_unverified', 'Source SHA could not be verified.' );
        return $sha;
    }

    /** Only an explicit plan probes GitHub. Neither discovery nor wp-admin GET does. */
    public static function resolve_sha( array $selection ) {
        $sources = self::sources();
        $source = $sources[$selection['source_id']];
        $repo = $source['repository'];
        $type = $selection['ref_type'];
        $reference = $selection['reference'];
        if ( 'branch_commit' === $type ) {
            $parts = explode( '@', $reference, 2 );
            $endpoint = '/compare/' . $parts[1] . '...' . rawurlencode( $parts[0] );
        } elseif ( 'branch' === $type ) $endpoint = '/branches/' . rawurlencode( $reference );
        elseif ( 'pull_request' === $type ) $endpoint = '/pulls/' . $reference;
        else $endpoint = '/commits/' . $reference;
        if ( ! function_exists( 'wp_safe_remote_get' ) )
            return new WP_Error( 'cross_plugin_github_client_missing', 'GitHub safe HTTP client unavailable.' );
        $response = wp_safe_remote_get( 'https://api.github.com/repos/' . $repo . $endpoint,
            array( 'timeout' => 7, 'redirection' => 0, 'limit_response_size' => 65536,
                'headers' => array( 'Accept' => 'application/vnd.github+json', 'User-Agent' => 'MAD4B-Plugin-Deployment' ) ) );
        if ( is_wp_error( $response ) ) return $response;
        if ( 200 !== (int) wp_remote_retrieve_response_code( $response ) )
            return new WP_Error( 'cross_plugin_github_ref_unavailable', 'The exact reviewed repository reference could not be resolved.' );
        $body = json_decode( (string) wp_remote_retrieve_body( $response ), true );
        return is_array( $body ) ? self::verified_sha_from_response( $selection, $body )
            : new WP_Error( 'cross_plugin_github_invalid_json', 'Source resolution response was invalid.' );
    }

    private static function environment() {
        if ( ! class_exists( 'MAD4B_SCP_Site_Profile' ) || ! function_exists( 'wp_get_environment_type' )
            || ! MAD4B_SCP_Site_Profile::origin_enrolled() ) return 'not_verified';
        $profile = (string) MAD4B_SCP_Site_Profile::current_environment();
        $wp = (string) wp_get_environment_type();
        return in_array( $wp, array( 'staging', 'production' ), true ) && $profile === $wp
            ? $profile : 'not_verified';
    }

    private static function current_plugin( $plugin_file ) {
        if ( ! defined( 'ABSPATH' ) || ! function_exists( 'get_plugins' ) ) {
            if ( defined( 'ABSPATH' ) && is_readable( ABSPATH . 'wp-admin/includes/plugin.php' ) )
                require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        $all = function_exists( 'get_plugins' ) ? get_plugins() : array();
        $record = $all[$plugin_file] ?? array();
        return array( 'installed' => isset( $all[$plugin_file] ),
            'version' => (string) ( $record['Version'] ?? '' ),
            'active' => function_exists( 'is_plugin_active' ) ? (bool) is_plugin_active( $plugin_file ) : false );
    }

    public static function discover( $input = array() ) {
        $list = array();
        foreach ( self::sources() as $id => $row ) {
            $current = self::current_plugin( $row['plugin_file'] );
            $list[] = array(
                'source_id' => $id, 'label' => (string) ( $row['label'] ?? $id ),
                'repository' => $row['repository'],
                'plugin_subdirectory' => $row['plugin_subdirectory'],
                'plugin_file' => $row['plugin_file'],
                'provider_id' => $row['provider_id'],
                'environments' => (array) ( $row['environments'] ?? array() ),
                'review_state' => (string) ( $row['review_state'] ?? 'needs_review' ),
                'package_certification' => (string) ( $row['package_certification'] ?? 'pending' ),
                'installed' => $current['installed'], 'installed_version' => $current['version'],
                'active' => $current['active'],
            );
        }
        return array( 'contract' => self::CONTRACT, 'mode' => 'discover',
            'environment' => self::environment(), 'items' => $list,
            'count' => count( $list ), 'mutation_performed' => false,
            'unlisted_repository_execution_allowed' => false );
    }

    public static function plan( $input ) {
        $selection = self::selection( $input );
        if ( is_wp_error( $selection ) ) return $selection;
        $list = self::sources();
        $source = $list[$selection['source_id']];
        $sha = self::resolve_sha( $selection );
        if ( is_wp_error( $sha ) ) return $sha;
        $environment = self::environment();
        $current = self::current_plugin( $source['plugin_file'] );
        $blockers = array();
        if ( ! in_array( $environment, array( 'staging', 'production' ), true )
            || ! in_array( $environment, (array) ( $source['environments'] ?? array() ), true ) )
            $blockers[] = 'environment_not_registered_for_source';
        if ( ! class_exists( 'MAD4B_SCP_Site_Profile' )
            || ! MAD4B_SCP_Site_Profile::origin_enrolled() )
            $blockers[] = 'site_origin_not_enrolled';
        $cert_sha = (string) ( $source['source_commit_sha'] ?? '' );
        $artifact_sha = (string) ( $source['artifact_sha256'] ?? '' );
        if ( 'source_reviewed_artifact_certified' !== (string) ( $source['review_state'] ?? '' ) )
            $blockers[] = 'source_review_promotion_required';
        if ( ! preg_match( '/^[a-f0-9]{40}$/D', $cert_sha ) || ! hash_equals( $cert_sha, $sha ) )
            $blockers[] = 'exact_source_commit_not_certified';
        if ( ! preg_match( '/^[a-f0-9]{64}$/D', $artifact_sha )
            || 'certified' !== (string) ( $source['package_certification'] ?? '' ) )
            $blockers[] = 'exact_zip_artifact_not_certified';
        if ( 'production' === $environment ) {
            if ( empty( $source['production_promotion_attestation'] ) )
                $blockers[] = 'independent_production_promotion_attestation_required';
            $blockers[] = 'production_package_executor_not_yet_certified';
        }
        $native = array();
        if ( 'staging' === $environment
            && ( ! class_exists( 'MAD4B_SCP_Plugin_Package' )
                || ! is_callable( array( 'MAD4B_SCP_Plugin_Package', 'plan' ) ) ) )
            $blockers[] = 'native_package_planner_unavailable';
        if ( 'staging' === $environment && empty( $blockers ) ) {
            $native = MAD4B_SCP_Plugin_Package::plan( array(
                'provider_id' => $source['provider_id'],
                'source' => 'auto_certified',
                'reason' => (string) ( $input['reason'] ?? 'Exact reviewed cross-plugin deployment' ) ) );
            if ( is_wp_error( $native ) ) {
                $blockers[] = $native->get_error_code();
                $native = array();
            } elseif ( ! is_array( $native )
                || ! preg_match( '/^[a-f0-9]{64}$/D', (string) ( $native['plan_sha256'] ?? '' ) )
                || ! isset( $native['eligible'] ) ) {
                $blockers[] = 'native_package_plan_invalid';
                $native = array();
            } elseif ( ! hash_equals( (string) ( $native['plugin_file'] ?? '' ), $source['plugin_file'] )
                || ! hash_equals( (string) ( $native['certified_archive_sha256'] ?? '' ), $artifact_sha )
                || ! hash_equals( (string) ( $native['target_version'] ?? '' ), (string) ( $source['artifact_version'] ?? '' ) ) ) {
                $blockers[] = 'native_package_certificate_mismatch';
            } elseif ( empty( $native['eligible'] ) ) {
                $blockers = array_merge( $blockers, (array) ( $native['blockers'] ?? array( 'native_plan_ineligible' ) ) );
            }
        }
        $blockers = array_values( array_unique( $blockers ) );
        sort( $blockers, SORT_STRING );
        return array(
            'contract' => self::CONTRACT, 'mode' => 'plan',
            'source_id' => $selection['source_id'], 'repository' => $source['repository'],
            'plugin_subdirectory' => $source['plugin_subdirectory'],
            'plugin_file' => $source['plugin_file'], 'provider_id' => $source['provider_id'],
            'ref_type' => $selection['ref_type'], 'reference' => $selection['reference'],
            'resolved_sha' => $sha, 'certified_sha' => $cert_sha,
            'environment' => $environment, 'operation' => $current['installed'] ? 'replace' : 'install',
            'current' => $current, 'artifact_sha256' => $artifact_sha,
            'native_plan_sha256' => (string) ( $native['plan_sha256'] ?? '' ),
            'native_executor' => 'staging' === $environment && empty( $blockers ) ? 'mad4b/plugin-package-apply' : '',
            'native_apply_input' => 'staging' === $environment && empty( $blockers ) ? array(
                'provider_id' => $source['provider_id'], 'source' => 'auto_certified',
                'expected_plan_sha256' => $native['plan_sha256'],
                'reason' => (string) ( $input['reason'] ?? 'Exact reviewed cross-plugin deployment' ),
            ) : array(),
            'eligible' => empty( $blockers ), 'blockers' => $blockers,
            'production_requires_separate_authority' => true,
            'wpcron_auto_execute' => false, 'mutation_performed' => false,
            'installer_created' => false, 'authorizing' => false,
        );
    }
}
