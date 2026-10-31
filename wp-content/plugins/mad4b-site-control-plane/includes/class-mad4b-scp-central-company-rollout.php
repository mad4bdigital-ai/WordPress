<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Read-only, tenant-local compatibility assessment for one centrally tested
 * MAD4B release. Company Staging is deliberately not required.
 *
 * The release receipt MUST come from the registered central Control MCP
 * readback provider, never from caller input or a site option alone.
 * This planner neither issues deployment authority nor installs packages.
 */
final class MAD4B_SCP_Central_Company_Rollout {
    const CONTRACT = 'mad4b.central-company-rollout-plan.v1';
    const ABILITY = 'mad4b/central-company-rollout-plan';
    const MAX_RECEIPT_AGE = 604800; // Seven days, re-evaluate after expiry.

    public static function boot() {
        require_once __DIR__ . '/class-mad4b-scp-central-release-registry.php';
        MAD4B_SCP_Central_Release_Registry::boot();
        add_action( 'wp_abilities_api_init', array( __CLASS__, 'register_ability' ), 39 );
    }

    public static function register_ability() {
        if ( ! function_exists( 'wp_register_ability' ) ||
            ( function_exists( 'wp_has_ability' ) && wp_has_ability( self::ABILITY ) ) ) return;
        wp_register_ability( self::ABILITY, array(
            'label' => 'Plan Central Staging to Company Production',
            'description' => 'Read-only compatibility check using a verified central Staging release; per-company Staging is optional.',
            'category' => 'mad4b-read',
            'execute_callback' => array( __CLASS__, 'plan' ),
            'permission_callback' => array( 'MAD4B_SCP_Policy', 'can_read' ),
            'input_schema' => array(
                'type' => 'object', 'additionalProperties' => false,
                'required' => array( 'release_sha256' ),
                'properties' => array(
                    'release_sha256' => array( 'type' => 'string', 'pattern' => '^[a-f0-9]{64}$' ),
                ),
            ),
            'output_schema' => array( 'type' => 'object', 'additionalProperties' => true ),
            'meta' => array(
                'public' => false, 'show_in_rest' => false,
                'mcp' => array( 'public' => false, 'type' => 'tool', 'surface' => 'read' ),
                'annotations' => array( 'readonly' => true, 'destructive' => false, 'idempotent' => true ),
            ),
        ) );
    }

    public static function plan( $input = array() ) {
        if ( ! is_array( $input ) || array_keys( $input ) !== array( 'release_sha256' ) ||
            ! self::hash_ok( $input['release_sha256'] ) )
            return new WP_Error( 'mad4b_central_rollout_input_invalid',
                'Provide only an exact 64-character release SHA-256; no caller-supplied readiness.' );

        if ( ! class_exists( 'MAD4B_SCP_Central_Release_Registry', false ) )
            require_once __DIR__ . '/class-mad4b-scp-central-release-registry.php';
        $hash = strtolower( (string) $input['release_sha256'] );
        // Runtime snapshot is sampled on the COMPANY target, not on central Staging.
        $site = self::company_snapshot();
        $receipt = array();
        $readback = false;
        if ( class_exists( 'MAD4B_SCP_Central_Release_Registry' ) &&
            is_callable( array( 'MAD4B_SCP_Central_Release_Registry', 'verified_readback' ) ) ) {
            $found = MAD4B_SCP_Central_Release_Registry::verified_readback( $hash );
            if ( is_array( $found ) ) {
                $receipt = $found;
                $readback = true;
            }
        }
        $assessment = self::assess( $hash, $receipt, $site, $readback );
        $assessment['read_only'] = true;
        $assessment['mutation_performed'] = false;
        $assessment['authorizing'] = false;
        $assessment['production_mutation_authorized'] = false;
        $assessment['executor'] = '';
        $assessment['next_step'] = $assessment['ready_for_separate_owner_authorization']
            ? 'Request a separate exact-artifact governed Production deployment and fresh readback.'
            : 'Resolve evidence or company compatibility blockers; do not deploy.';
        return $assessment;
    }

    /** Pure policy: the readback flag is set ONLY by the server-side provider. */
    public static function assess( $hash, array $receipt, array $site, $provider_readback_verified = false ) {
        $blockers = array();
        if ( ! self::hash_ok( $hash ) ) $blockers[] = 'release_identity_invalid';
        if ( true !== $provider_readback_verified )
            $blockers[] = 'trusted_central_release_readback_unavailable';
        $central_origin = class_exists( 'MAD4B_SCP_Central_Release_Registry' )
            ? MAD4B_SCP_Central_Release_Registry::central_origin() : '';
        if ( '' === $central_origin ||
            (string) ( $receipt['contract'] ?? '' ) !== 'mad4b.central-staging-release-readback.v1' ||
            (string) ( $receipt['staging_origin'] ?? '' ) !== $central_origin ||
            (string) ( $receipt['environment'] ?? '' ) !== 'staging' ||
            true !== ( $receipt['registry_readback_verified'] ?? null ) )
            $blockers[] = 'central_staging_identity_unverified';

        if ( ! self::hash_ok( $receipt['artifact_sha256'] ?? null ) ||
            ! self::hash_ok( $hash ) ||
            ! hash_equals( (string) $hash, (string) ( $receipt['artifact_sha256'] ?? '' ) ) )
            $blockers[] = 'central_release_artifact_mismatch';
        if ( ! self::hash_ok( $receipt['file_manifest_sha256'] ?? null ) )
            $blockers[] = 'central_release_manifest_missing';

        $source = (string) ( $receipt['source_type'] ?? '' );
        if ( ! in_array( $source, array( 'local_git', 'remote_git' ), true ) ||
            ! preg_match( '/^[a-f0-9]{40}$/D', (string) ( $receipt['source_commit'] ?? '' ) ) )
            $blockers[] = 'immutable_source_identity_missing';

        if ( true !== ( $receipt['staging_pass'] ?? null ) ||
            ! is_array( $receipt['checks'] ?? null ) ||
            true !== ( $receipt['checks']['install'] ?? null ) ||
            true !== ( $receipt['checks']['smoke'] ?? null ) ||
            true !== ( $receipt['checks']['rollback'] ?? null ) )
            $blockers[] = 'central_staging_tests_not_passed';
        $tested_at = $receipt['tested_at'] ?? null;
        if ( ! is_int( $tested_at ) || $tested_at > time() + 60 ||
            $tested_at < time() - self::MAX_RECEIPT_AGE )
            $blockers[] = 'central_staging_evidence_stale';

        // Current site and its enrolled production profile must agree.
        if ( (string) ( $site['environment'] ?? '' ) !== 'production' ||
            (string) ( $site['enrolled_environment'] ?? '' ) !== 'production' ||
            true !== ( $site['origin_enrolled'] ?? null ) ||
            ! is_string( $site['site_uuid'] ?? null ) ||
            ! preg_match( '/^[a-f0-9-]{36}$/iD', $site['site_uuid'] ) )
            $blockers[] = 'company_production_identity_unverified';

        $requirements = $receipt['requirements'] ?? null;
        if ( ! is_array( $requirements ) || ! is_array( $requirements['required_plugins'] ?? null ) ||
            count( $requirements['required_plugins'] ) > 50 ||
            empty( $requirements['target_plugin_file'] ) ||
            ! self::plugin_file_ok( $requirements['target_plugin_file'] ) ||
            empty( $requirements['target_version'] ) ||
            ! self::version_ok( $requirements['target_version'] ) ) {
            $blockers[] = 'release_requirements_invalid';
            $requirements = array();
        } else {
            foreach ( array( 'php_min', 'wordpress_min' ) as $field ) {
                $minimum = $requirements[$field] ?? '';
                $current = (string) ( $site[ 'php_min' === $field ? 'php_version' : 'wordpress_version' ] ?? '' );
                if ( ! self::version_ok( $minimum ) || ! self::version_ok( $current ) )
                    $blockers[] = 'runtime_versions_unavailable';
                elseif ( version_compare( $current, $minimum, '<' ) )
                    $blockers[] = 'company_' . $field . '_incompatible';
            }
            $seen = array();
            foreach ( $requirements['required_plugins'] as $dep ) {
                if ( ! is_array( $dep ) || ! self::plugin_file_ok( $dep['file'] ?? null ) ||
                    ! self::version_ok( $dep['min_version'] ?? null ) ||
                    isset( $seen[$dep['file']] ) ) {
                    $blockers[] = 'company_plugin_requirements_invalid'; continue;
                }
                $seen[$dep['file']] = true;
                $installed = $site['plugins'][$dep['file']] ?? null;
                if ( ! is_array( $installed ) ||
                    ! self::version_ok( $installed['version'] ?? null ) ||
                    version_compare( $installed['version'], $dep['min_version'], '<' ) ||
                    ( true === ( $dep['active_required'] ?? false ) && true !== ( $installed['active'] ?? null ) ) )
                    $blockers[] = 'company_plugin_dependency_incompatible';
            }
            $target = $site['plugins'][$requirements['target_plugin_file']] ?? null;
            if ( is_array( $target ) && self::version_ok( $target['version'] ?? null ) &&
                version_compare( $target['version'], $requirements['target_version'], '>' ) )
                $blockers[] = 'company_target_downgrade_denied';
            if ( true === ( $requirements['requires_schema_migration'] ?? false ) &&
                true !== ( $receipt['checks']['schema_rollback'] ?? null ) )
                $blockers[] = 'company_schema_migration_rollback_unverified';
        }

        $blockers = array_values( array_unique( $blockers ) );
        sort( $blockers, SORT_STRING );
        return array(
            'contract' => self::CONTRACT,
            'central_staging_origin' => $central_origin,
            'central_release_sha256' => (string) $hash,
            'company_site_uuid' => (string) ( $site['site_uuid'] ?? '' ),
            'company_origin' => (string) ( $site['origin'] ?? '' ),
            'company_environment' => (string) ( $site['environment'] ?? '' ),
            'company_staging_required' => false,
            'company_staging_evaluated' => false,
            'company_compatibility_preflight' => true,
            'package_certification_required' => false,
            'central_release_readback_verified' => true === $provider_readback_verified,
            'ready_for_separate_owner_authorization' => empty( $blockers ),
            'blockers' => $blockers,
            'read_only' => true,
            'mutation_performed' => false,
            'authorizing' => false,
            'production_mutation_authorized' => false,
            'executor' => '',
        );
    }

    private static function company_snapshot() {
        $profile = class_exists( 'MAD4B_SCP_Site_Profile' )
            && method_exists( 'MAD4B_SCP_Site_Profile', 'status' )
            ? MAD4B_SCP_Site_Profile::status() : array();
        if ( ! is_array( $profile ) ) $profile = array();
        if ( ! function_exists( 'get_plugins' ) )
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        $list = function_exists( 'get_plugins' ) ? get_plugins() : array();
        $plugins = array();
        foreach ( $list as $plugin => $data ) {
            if ( ! self::plugin_file_ok( $plugin ) ) continue;
            $plugins[$plugin] = array(
                'version' => (string) ( $data['Version'] ?? '' ),
                'active' => function_exists( 'is_plugin_active' ) ? is_plugin_active( $plugin ) : false,
            );
        }
        return array(
            'environment' => function_exists( 'wp_get_environment_type' ) ? wp_get_environment_type() : '',
            'enrolled_environment' => class_exists( 'MAD4B_SCP_Site_Profile' )
                && method_exists( 'MAD4B_SCP_Site_Profile', 'current_environment' )
                ? MAD4B_SCP_Site_Profile::current_environment() : '',
            'origin_enrolled' => class_exists( 'MAD4B_SCP_Site_Profile' )
                && method_exists( 'MAD4B_SCP_Site_Profile', 'origin_enrolled' )
                ? (bool) MAD4B_SCP_Site_Profile::origin_enrolled() : false,
            'site_uuid' => (string) ( $profile['site_uuid'] ?? '' ),
            'origin' => function_exists( 'home_url' ) ? home_url() : '',
            'php_version' => PHP_VERSION,
            'wordpress_version' => function_exists( 'get_bloginfo' ) ? get_bloginfo( 'version' ) : '',
            'plugins' => $plugins,
        );
    }

    private static function version_ok( $v ) {
        return is_string( $v ) && (bool) preg_match( '/^[0-9][0-9A-Za-z._-]{0,63}$/D', $v );
    }
    private static function plugin_file_ok( $v ) {
        return is_string( $v ) &&
            (bool) preg_match( '#^[a-zA-Z0-9._-]+/[a-zA-Z0-9._-]+\.php$#D', $v ) &&
            false === strpos( $v, '..' );
    }
    private static function hash_ok( $v ) {
        return is_string( $v ) && (bool) preg_match( '/^[a-f0-9]{64}$/D', $v );
    }
}
