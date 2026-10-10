<?php
/**
 * Provider-neutral plugin update recovery discovery and CI-outage policy.
 *
 * Discover every installed plugin, but never infer permission or package
 * trust from a plugin header, remote URL, Actions status, or admin screen.
 * Source-approved provider contracts bind the only executable update lane.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class MAD4B_SCP_Plugin_Update_Recovery {
    const CONTRACT = 'mad4b.plugin-update-recovery.v1';
    const DISCOVER_ABILITY = 'mad4b/plugin-update-recovery-discover';
    const PLAN_ABILITY = 'mad4b/plugin-update-recovery-plan';
    const MAX_PLUGINS = 300;

    public static function boot() {
        add_action( 'wp_abilities_api_init', array( __CLASS__, 'register_abilities' ), 36 );
    }

    public static function register_abilities() {
        if ( ! function_exists( 'wp_register_ability' ) ) return;
        foreach ( array(
            array( self::DISCOVER_ABILITY, 'Discover Plugin Update Recovery Routes', 'discover', self::discover_schema() ),
            array( self::PLAN_ABILITY, 'Plan Governed Plugin Update Recovery', 'plan', self::plan_schema() ),
        ) as $a ) {
            if ( function_exists( 'wp_has_ability' ) && wp_has_ability( $a[0] ) ) continue;
            wp_register_ability( $a[0], array(
                'label' => $a[1],
                'description' => 'Read-only per-plugin CI-independent update route and provider certification readiness; no mutation.',
                'category' => 'mad4b-read',
                'execute_callback' => array( __CLASS__, $a[2] ),
                'permission_callback' => array( 'MAD4B_SCP_Policy', 'can_read' ),
                'input_schema' => $a[3],
                'output_schema' => array( 'type' => 'object', 'additionalProperties' => true ),
                'meta' => array(
                    'public' => false, 'show_in_rest' => false,
                    'mcp' => array( 'public' => false, 'type' => 'tool', 'surface' => 'read' ),
                    'annotations' => array( 'readonly' => true, 'destructive' => false, 'idempotent' => true ),
                ),
            ) );
        }
    }

    private static function discover_schema() {
        return array( 'type' => 'object', 'additionalProperties' => false,
            'properties' => array(
                'filter' => array( 'type' => 'string', 'maxLength' => 100 ),
                'include_uncertified' => array( 'type' => 'boolean' ),
                'limit' => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => self::MAX_PLUGINS ),
            ) );
    }

    private static function plan_schema() {
        return array( 'type' => 'object', 'additionalProperties' => false,
            'required' => array( 'plugin_file', 'reason' ),
            'properties' => array(
                'plugin_file' => array( 'type' => 'string', 'minLength' => 3, 'maxLength' => 220 ),
                'reason' => array( 'type' => 'string', 'minLength' => 3, 'maxLength' => 500 ),
                'source' => array( 'type' => 'string',
                    'enum' => array( 'auto_certified', 'certified_upstream_release', 'wordpress_update_offer',
                        'certified_repository_archive', 'certified_local_archive' ) ),
            ) );
    }

    /** Pure policy; external CI is never confused with tested, signed native evidence. */
    public static function ci_policy( $ci_state, array $native, $certified_package ) {
        $valid = array( 'unknown', 'queued', 'unavailable', 'passed',
            'infrastructure_failure', 'test_failure', 'security_failure' );
        $ci = is_string( $ci_state ) ? $ci_state : '';
        $blockers = array();
        if ( ! in_array( $ci, $valid, true ) ) $blockers[] = 'unknown_ci_diagnostic';
        if ( ! $certified_package ) $blockers[] = 'exact_provider_package_certification_required';
        if ( in_array( $ci, array( 'test_failure', 'security_failure' ), true ) )
            $blockers[] = 'confirmed_native_or_security_test_failure';
        $keys = array( 'source_exact', 'package_verified', 'tests_passed',
            'owner_reviewed', 'independently_signed', 'signature_trust_verified',
            'evidence_fresh', 'same_plugin_and_site' );
        $attested = true;
        foreach ( $keys as $key ) {
            if ( true !== ( $native[ $key ] ?? null ) ) {
                $attested = false;
                break;
            }
        }
        $mode = 'await_independent_evidence';
        if ( 'passed' === $ci && $certified_package )
            $mode = 'certified_provider_with_ci';
        elseif ( $certified_package && $attested &&
            in_array( $ci, array( 'unknown', 'queued', 'unavailable', 'infrastructure_failure' ), true ) )
            $mode = 'certified_provider_with_signed_native_tests';
        if ( 'await_independent_evidence' === $mode ) $blockers[] = 'source_bound_independent_tests_or_valid_ci_required';
        return array(
            'contract' => self::CONTRACT . '.ci-policy.v1',
            'ci_state' => $ci,
            'evidence_mode' => $mode,
            'eligible' => empty( $blockers ),
            'blockers' => array_values( array_unique( $blockers ) ),
            'github_ci_terminal_success_required' => false,
            'github_ci_queue_alone_is_blocker' => false,
            'signature_and_native_tests_still_required_for_ci_outage' => true,
            'generic_unsigned_archive_allowed' => false,
            'production_allowed' => false,
            'mutation_performed' => false,
            'authorizing' => false,
        );
    }

    /** One authoritative certified provider is required; plugin_file comes from registry. */
    public static function catalog( array $contracts ) {
        $lookup = array();
        foreach ( $contracts as $provider_id => $p ) {
            if ( ! is_string( $provider_id ) || ! preg_match( '/^[a-z0-9_\\-]{1,80}$/D', $provider_id ) ||
                ! is_array( $p ) ) continue;
            $components = array( '' => $p );
            if ( isset( $p['components'] ) && is_array( $p['components'] ) ) {
                foreach ( $p['components'] as $component => $item ) {
                    if ( ! is_string( $component ) || ! is_array( $item ) ||
                        ! preg_match( '/^[a-z0-9_\\-]{1,80}$/D', $component ) ) continue;
                    $components[ $component ] = $item;
                }
            }
            foreach ( $components as $component => $meta ) {
                $file = (string) ( $meta['plugin_file'] ?? '' );
                $sha = (string) ( $meta['archive_sha256'] ?? '' );
                if ( ! preg_match( '#^[A-Za-z0-9._-]+/[A-Za-z0-9._-]+\\.php$#D', $file ) ||
                    1 !== preg_match( '/^[a-f0-9]{64}$/D', $sha ) ||
                    empty( $meta['version'] ) || empty( $meta['critical_files'] ) ) continue;
                // Ambiguous provider ownership is denied rather than first-wins.
                if ( array_key_exists( $file, $lookup ) ) {
                    $lookup[ $file ] = array( 'conflict' => true );
                    continue;
                }
                $lookup[ $file ] = array( 'provider_id' => $provider_id,
                    'component' => $component, 'version' => (string) $meta['version'],
                    'archive_sha256' => $sha, 'conflict' => false );
            }
        }
        ksort( $lookup, SORT_STRING );
        return $lookup;
    }

    /** Safe snapshot; third-party names/headers cannot generate trusted executors. */
    public static function inventory( array $plugins, array $catalog, $include_uncertified = true, $limit = self::MAX_PLUGINS, $filter = '' ) {
        $items = array();
        ksort( $plugins, SORT_STRING );
        foreach ( $plugins as $file => $info ) {
            if ( count( $items ) >= $limit ) break;
            if ( ! is_string( $file ) ||
                ! preg_match( '#^[A-Za-z0-9._-]+/[A-Za-z0-9._-]+\\.php$#D', $file ) ) continue;
            if ( '' !== $filter && false === stripos( $file, $filter ) ) continue;
            $authority = $catalog[ $file ] ?? array();
            $certified = ! empty( $authority ) && empty( $authority['conflict'] );
            if ( ! $include_uncertified && ! $certified ) continue;
            $items[] = array(
                'plugin_file' => $file,
                'installed_version' => is_array( $info ) ? (string) ( $info['Version'] ?? '' ) : '',
                'certified_provider' => $certified,
                'provider_id' => $certified ? $authority['provider_id'] : '',
                'component' => $certified ? $authority['component'] : '',
                'certified_version' => $certified ? $authority['version'] : '',
                'authority_state' => ! empty( $authority['conflict'] ) ? 'conflicting_provider_contracts'
                    : ( $certified ? 'exact_repo_provider_contract' : 'requires_source_reviewed_provider_enrollment' ),
                'update_plan_ability' => $certified ? 'mad4b/plugin-package-plan' : '',
                'update_apply_ability' => $certified ? 'mad4b/plugin-package-apply' : '',
                'ci_terminal_result_mandatory' => false,
                'automatic_install' => false, 'arbitrary_url_allowed' => false,
            );
        }
        return $items;
    }

    private static function runtime_plugins() {
        if ( ! function_exists( 'get_plugins' ) ) require_once ABSPATH . 'wp-admin/includes/plugin.php';
        return get_plugins();
    }

    public static function discover( $input = array() ) {
        if ( ! is_array( $input ) || array_diff( array_keys( $input ),
            array( 'filter', 'include_uncertified', 'limit' ) ) )
            return new WP_Error( 'mad4b_plugin_update_discover_input_invalid', 'Only bounded inventory inputs accepted.' );
        $filter = isset( $input['filter'] ) ? (string) $input['filter'] : '';
        if ( strlen( $filter ) > 100 || ( '' !== $filter &&
            ! preg_match( '/^[A-Za-z0-9._-]{1,100}$/D', $filter ) ) )
            return new WP_Error( 'mad4b_plugin_update_filter_invalid', 'Invalid plugin identifier filter.' );
        $limit = isset( $input['limit'] ) ? (int) $input['limit'] : self::MAX_PLUGINS;
        if ( $limit < 1 || $limit > self::MAX_PLUGINS ) return new WP_Error( 'mad4b_plugin_update_limit_invalid', 'Invalid inventory limit.' );
        $contracts = class_exists( 'MAD4B_SCP_Provider_Contracts' ) ? MAD4B_SCP_Provider_Contracts::all() : array();
        $plugins = self::runtime_plugins();
        $catalog = self::catalog( is_array( $contracts ) ? $contracts : array() );
        return array(
            'contract' => self::CONTRACT, 'state' => 'read_only_discovery',
            'installed_plugin_count' => is_array( $plugins ) ? count( $plugins ) : 0,
            'certified_plugin_count' => count( array_filter( $catalog, static function ( $x ) { return empty( $x['conflict'] ); } ) ),
            'items' => self::inventory( is_array( $plugins ) ? $plugins : array(), $catalog,
                ! array_key_exists( 'include_uncertified', $input ) || true === $input['include_uncertified'],
                $limit, $filter ),
            'ci_outage_support' => 'signed_native_evidence_or_independent_certified_provider_source',
            'ci_outage_is_not_permission_to_skip_native_tests' => true,
            'unsupported_plugins' => 'discoverable_only_until_source_certification',
            'production_allowed' => false, 'read_only' => true, 'mutation_performed' => false,
        );
    }

    public static function plan( $input = array() ) {
        if ( ! is_array( $input ) || array_diff( array_keys( $input ),
            array( 'plugin_file', 'reason', 'source' ) ) )
            return new WP_Error( 'mad4b_plugin_update_plan_input_invalid', 'Only plugin ID, selected certified source, CI diagnostic and test evidence accepted.' );
        $plugin = (string) ( $input['plugin_file'] ?? '' );
        if ( ! preg_match( '#^[A-Za-z0-9._-]+/[A-Za-z0-9._-]+\\.php$#D', $plugin ) )
            return new WP_Error( 'mad4b_plugin_update_file_invalid', 'Exact registered plugin file required.' );
        $reason = isset( $input['reason'] ) ? trim( (string) $input['reason'] ) : '';
        if ( strlen( $reason ) < 3 || strlen( $reason ) > 500 )
            return new WP_Error( 'mad4b_plugin_update_reason_invalid', 'A bounded reason is required.' );
        $contracts = class_exists( 'MAD4B_SCP_Provider_Contracts' ) ? MAD4B_SCP_Provider_Contracts::all() : array();
        $catalog = self::catalog( is_array( $contracts ) ? $contracts : array() );
        $target = $catalog[ $plugin ] ?? array();
        if ( empty( $target ) || ! empty( $target['conflict'] ) )
            return new WP_Error( 'mad4b_plugin_update_uncertified', 'Plugin has no uniquely certified provider: discoverable but not installable.' );
        $source = (string) ( $input['source'] ?? 'auto_certified' );
        // CI diagnostic, when present, must come from trusted server evidence.
        // An assistant cannot self-assert a green run or a signed test bundle.
        $ci = 'unknown';
        if ( ! in_array( $source, array( 'auto_certified', 'certified_upstream_release', 'wordpress_update_offer',
            'certified_repository_archive', 'certified_local_archive' ), true ) )
            return new WP_Error( 'mad4b_plugin_update_source_invalid', 'Unknown certified source type.' );
        $evidence = array(); // Never promote caller-supplied PASS flags into trust.
        $ci_policy = self::ci_policy( $ci, $evidence, true );
        // Native-evidence flags supplied by callers are for diagnostics only.
        // They never authorize a plugin write or certify source bytes.
        $ci_policy['caller_asserted_native_evidence_authoritative'] = false;
        if ( ! class_exists( 'MAD4B_SCP_Plugin_Package' ) )
            return new WP_Error( 'mad4b_plugin_update_backend_missing', 'Canonical governed plugin installer unavailable.' );
        $base = MAD4B_SCP_Plugin_Package::plan( array(
            'provider_id' => $target['provider_id'], 'component' => $target['component'],
            'source' => $source, 'reason' => $reason,
        ) );
        if ( is_wp_error( $base ) ) return $base;
        $base['ci_outage_policy'] = $ci_policy;
        $base['certification_route'] = 'exact_source_owned_provider_contract';
        $base['ci_verdict_is_not_a_precondition_for_existing_certified_source'] = true;
        $base['staging_native_receipt_required_only_for_a_new_uncertified_release_lane'] = true;
        $base['ci_state_is_authoritative'] = false;
        $base['native_evidence_is_authoritative'] = false;
        $base['update_executor'] = 'mad4b/plugin-package-apply';
        $base['update_plan_ability'] = 'mad4b/plugin-package-plan';
        $base['do_not_forward_ci_or_evidence_fields_to_executor'] = true;
        $base['next_step'] = 'Review canonical plugin-package-plan and its exact SHA, use existing scoped plugin-package-apply with separate central authorization.';
        $base['ci_state_is_required_for_package_eligibility'] = false;
        $base['host_runner_required'] = false;
        $base['generic_auto_apply_allowed'] = false;
        // Do not alter the canonical plan's plan_sha256: any extra metadata is
        // nonauthoritative advisory only, and must not change the executor plan.
        return $base;
    }
}
