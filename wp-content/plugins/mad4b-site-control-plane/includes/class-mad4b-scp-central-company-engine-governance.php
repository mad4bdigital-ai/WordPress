<?php
/**
 * Central Company Management: read-only domain engine projection.
 * Reuses the ORIGINAL MAD4B Core operation registry and policy.
 * Has no dispatcher, executor, grant, write, or authority-creation path.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class MAD4B_SCP_Central_Company_Engine_Governance {
    const CONTRACT = 'mad4b.central-company-engine-governance-status.v1';
    const MANIFEST = 'config/central-company-engine-registry.json';
    const ABILITY = 'mad4b/central-company-engine-governance-status';

    private static function fail( $code ) {
        return new WP_Error( 'mad4b_company_engine_' . $code, 'Central company engine registry is unavailable or invalid.' );
    }

    public static function boot() {
        add_action( 'wp_abilities_api_init', array( __CLASS__, 'register_ability' ), 34 );
    }

    public static function register_ability() {
        if ( ! function_exists( 'wp_register_ability' ) ||
            ( function_exists( 'wp_has_ability' ) && wp_has_ability( self::ABILITY ) ) ) {
            return;
        }
        wp_register_ability( self::ABILITY, array(
            'label' => 'Central Company Engine Governance Status',
            'description' => 'Read-only projection of company-owned engine contracts and original MAD4B Core operation mappings.',
            'category' => 'mad4b-read',
            'execute_callback' => array( __CLASS__, 'status' ),
            'permission_callback' => array( 'MAD4B_SCP_Policy', 'can_read' ),
            'input_schema' => array( 'type' => 'object', 'properties' => array(), 'additionalProperties' => false ),
            'output_schema' => array( 'type' => 'object', 'additionalProperties' => true ),
            'meta' => array(
                'public' => false, 'show_in_rest' => false,
                'mcp' => array( 'public' => false, 'type' => 'tool', 'surface' => 'read' ),
                'annotations' => array( 'readonly' => true, 'destructive' => false, 'idempotent' => true ),
            ),
        ) );
        if ( function_exists( 'wp_has_ability' ) && wp_has_ability( 'mad4b/central-company-engine-native-preflight' ) ) return;
        wp_register_ability( 'mad4b/central-company-engine-native-preflight', array(
            'label' => 'Central Company Native Engine Preflight',
            'description' => 'Inspect exact site-bound original Core operation mapping and outstanding provider/evidence gates; never dispatch or authorize execution.',
            'category' => 'mad4b-read',
            'execute_callback' => array( __CLASS__, 'native_preflight' ),
            'permission_callback' => array( 'MAD4B_SCP_Policy', 'can_read' ),
            'input_schema' => array(
                'type' => 'object',
                'required' => array( 'engine_id' ),
                'properties' => array(
                    'engine_id' => array( 'type' => 'string', 'minLength' => 10, 'maxLength' => 72 ),
                ),
                'additionalProperties' => false,
            ),
            'output_schema' => array( 'type' => 'object', 'additionalProperties' => true ),
            'meta' => array(
                'public' => false, 'show_in_rest' => false,
                'mcp' => array( 'public' => false, 'type' => 'tool', 'surface' => 'read' ),
                'annotations' => array( 'readonly' => true, 'destructive' => false, 'idempotent' => true ),
            ),
        ) );
    }

    /**
     * Native Core readback is not a mutation admission, even when the operation
     * is registered. Site Profile observes the site, but cannot attest company
     * ownership; company-bound authority must come from the original Core.
     */
    public static function native_preflight( $input = array() ) {
        if ( ! is_array( $input ) || array_keys( $input ) !== array( 'engine_id' ) ||
            ! is_string( $input['engine_id'] ) ||
            ! preg_match( '/^company\.[a-z][a-z0-9_]{2,63}$/D', $input['engine_id'] ) ) {
            return self::fail( 'engine_scope_invalid' );
        }
        $status = self::status( array() );
        if ( is_wp_error( $status ) ) return $status;
        $selected = null;
        foreach ( $status['engines'] as $row ) {
            if ( $row['engine_id'] === $input['engine_id'] ) {
                $selected = $row;
                break;
            }
        }
        if ( null === $selected ) return self::fail( 'engine_unregistered' );
        $profile = class_exists( 'MAD4B_SCP_Site_Profile' ) ? MAD4B_SCP_Site_Profile::status() : null;
        $site = is_array( $profile ) ? $profile : array();
        $identity_ready = ! empty( $site['site_uuid'] ) &&
            ! empty( $site['origin_match'] ) &&
            ! empty( $site['deployment_binding_match'] ) &&
            ! empty( $site['same_origin_clone_protection'] );
        $environment = class_exists( 'MAD4B_SCP_Site_Profile' )
            ? MAD4B_SCP_Site_Profile::current_environment()
            : ( function_exists( 'wp_get_environment_type' ) ? wp_get_environment_type() : 'unknown' );
        $blockers = array();
        if ( ! $identity_ready ) $blockers[] = 'site_identity_not_verified';
        if ( ! $selected['core_registry_mapping'] ) $blockers[] = 'native_operation_not_registered';
        if ( ! empty( $selected['wordpress_modules_missing'] ) ) $blockers[] = 'runtime_module_missing';
        if ( 'vps' === $selected['source'] ) $blockers[] = 'vps_source_inventory_receipt_not_bound';
        if ( 'company.schema_evolution' === $selected['engine_id'] ) {
            $blockers[] = 'canonical_schema_and_provider_adapters_not_certified';
        }
        if ( 'company.code_reconciliation' === $selected['engine_id'] ) {
            $blockers[] = 'target_semantic_dependency_graph_not_certified';
        }
        $blockers[] = 'company_binding_not_attested_by_core';
        $blockers[] = 'native_executor_authority_and_restore_not_certified';
        return array(
            'contract' => 'mad4b.central-company-engine-native-preflight.v1',
            'engine_id' => $selected['engine_id'],
            'native_operation' => $selected['native_operation'],
            'core_registry_mapping' => $selected['core_registry_mapping'],
            'site_uuid' => $identity_ready ? (string) $site['site_uuid'] : '',
            'site_identity_observed' => $identity_ready,
            'environment' => (string) $environment,
            'site_origin' => function_exists( 'home_url' ) ? home_url() : '',
            'blockers' => array_values( array_unique( $blockers ) ),
            'status' => 'BLOCKED_PENDING_NATIVE_EVIDENCE',
            'company_scope_authority_verified' => false,
            'full_native_executor_certified' => false,
            'can_apply' => false,
            'can_commit' => false,
            'authorizing' => false,
            'mutation_performed' => false,
            'production_mutation' => false,
        );
    }

    public static function status( $input = array() ) {
        if ( ! is_array( $input ) || $input !== array() ) return self::fail( 'input_invalid' );
        if ( ! defined( 'MAD4B_SCP_DIR' ) ) return self::fail( 'plugin_path_missing' );
        $path = MAD4B_SCP_DIR . self::MANIFEST;
        if ( ! is_file( $path ) || ! is_readable( $path ) || is_link( $path ) ||
            (int) filesize( $path ) > 65536 ) {
            return self::fail( 'manifest_missing' );
        }
        $raw = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
        $data = is_string( $raw ) ? json_decode( $raw, true ) : null;
        if ( ! is_array( $data ) ) return self::fail( 'manifest_invalid' );
        return self::assess_manifest( $data );
    }

    /**
     * Explicit manifest input is useful for native isolated tests. It never
     * grants mutation authority, regardless of caller-supplied fields.
     */
    public static function assess_manifest( $data ) {
        if ( ! is_array( $data ) ||
            ( $data['contract'] ?? null ) !== 'mad4b.central-company-engine-registry.v1' ||
            ( $data['version'] ?? null ) !== 1 ||
            ( $data['domain_owner'] ?? null ) !== 'central_company_management' ||
            ( $data['core_authority'] ?? null ) !== 'MAD4B_SCP_Operation_Registry' ||
            ( $data['core_dispatch'] ?? null ) !== 'original_governed_operation_pipeline' ||
            ( $data['default_write_policy'] ?? null ) !== 'deny_without_native_operation_and_fresh_site_bound_authority' ||
            ( $data['site_data_replication'] ?? null ) !== 'never_implicit' ||
            ! isset( $data['engines'] ) || ! is_array( $data['engines'] ) ||
            count( $data['engines'] ) < 1 || count( $data['engines'] ) > 100 ) {
            return self::fail( 'contract_invalid' );
        }
        if ( ! class_exists( 'MAD4B_SCP_Operation_Registry' ) ) return self::fail( 'core_registry_missing' );
        $seen = array(); $ops = array(); $entries = array(); $mapped = 0;
        foreach ( $data['engines'] as $engine ) {
            if ( ! is_array( $engine ) || ! is_string( $engine['id'] ?? null ) ||
                ! preg_match( '/^company\.[a-z][a-z0-9_]{2,63}$/D', $engine['id'] ) ||
                isset( $seen[ $engine['id'] ] ) ||
                ( $engine['owner'] ?? null ) !== 'central_company_management' ||
                ( $engine['scope'] ?? null ) !== 'company_and_site' ||
                ! in_array( $engine['source'] ?? null, array( 'vps', 'wordpress' ), true ) ||
                ! in_array( $engine['risk'] ?? null, array( 'low', 'medium', 'high', 'exceptional' ), true ) ||
                ! is_array( $engine['modules'] ?? null ) || empty( $engine['modules'] ) ||
                ! is_array( $engine['depends_on'] ?? null ) || ! is_array( $engine['protected'] ?? null ) ||
                empty( $engine['protected'] ) ) {
                return self::fail( 'engine_invalid' );
            }
            $seen[ $engine['id'] ] = true;
            $operation = $engine['native_operation'] ?? null;
            if ( null !== $operation && ( ! is_string( $operation ) ||
                ! preg_match( '/^[a-z][a-z0-9.-]{2,95}$/D', $operation ) || isset( $ops[ $operation ] ) ) ) {
                return self::fail( 'operation_binding_invalid' );
            }
            if ( null !== $operation ) $ops[ $operation ] = true;
            $missing = array(); $module_count = 0;
            foreach ( $engine['modules'] as $module ) {
                if ( ! is_string( $module ) || ! preg_match( '~^[a-zA-Z0-9_./-]+$~D', $module ) ||
                    0 === strpos( $module, '/' ) || false !== strpos( $module, '..' ) ) {
                    return self::fail( 'module_path_invalid' );
                }
                if ( 'wordpress' === $engine['source'] ) {
                    $file = MAD4B_SCP_DIR . 'includes/' . $module;
                    if ( is_file( $file ) && ! is_link( $file ) ) $module_count++;
                    else $missing[] = $module;
                }
            }
            $native_mapped = false;
            if ( null !== $operation ) {
                $found = MAD4B_SCP_Operation_Registry::operation( $operation );
                $native_mapped = is_array( $found ) &&
                    ! empty( $found['planner'] ) && ! empty( $found['executor'] ) &&
                    ( $found['pipeline_profile'] ?? null ) === 'governed_mutation_v1' &&
                    empty( $found['generic_mutation_dispatch'] );
            }
            if ( $native_mapped ) $mapped++;
            $entries[] = array(
                'engine_id' => $engine['id'],
                'source' => $engine['source'],
                'risk' => $engine['risk'],
                'dependencies' => $engine['depends_on'],
                'native_operation' => $operation,
                'core_registry_mapping' => $native_mapped,
                'wordpress_modules_observed' => $module_count,
                'wordpress_modules_missing' => $missing,
                'vps_source_readback' => 'vps' === $engine['source'] ? 'SEPARATE_CENTRAL_EVIDENCE_REQUIRED' : 'NOT_APPLICABLE',
                'status' => 'vps' === $engine['source'] ? 'CENTRAL_SOURCE_READBACK_PENDING' :
                    ( ! empty( $missing ) ? 'SOURCE_INCOMPLETE' :
                        ( $native_mapped ? 'CORE_OPERATION_MAPPED_UNCERTIFIED' : 'PLANNING_ONLY' ) ),
                'executor_certified' => false,
                'authorizing' => false,
            );
        }
        $dependencies = array();
        foreach ( $entries as $entry ) {
            foreach ( $entry['dependencies'] as $dep ) {
                if ( ! is_string( $dep ) || ! isset( $seen[ $dep ] ) || $dep === $entry['engine_id'] ) {
                    return self::fail( 'dependency_invalid' );
                }
                $dependencies[ $entry['engine_id'] ][] = $dep;
            }
        }
        // Explicit bounded cycle check. A cyclic plan cannot be certified.
        $resolved = array();
        for ( $iteration = 0; $iteration <= count( $entries ); $iteration++ ) {
            $changed = false;
            foreach ( $entries as $entry ) {
                $id = $entry['engine_id'];
                if ( isset( $resolved[ $id ] ) ) continue;
                $need = $dependencies[ $id ] ?? array();
                if ( ! array_diff( $need, array_keys( $resolved ) ) ) {
                    $resolved[ $id ] = true; $changed = true;
                }
            }
            if ( ! $changed ) break;
        }
        if ( count( $resolved ) !== count( $entries ) ) return self::fail( 'dependency_cycle' );
        return array(
            'contract' => self::CONTRACT,
            'owner' => 'central_company_management',
            'original_core_registry' => 'mad4b.operation-registry.v1',
            'engine_count' => count( $entries ),
            'core_operations_mapped' => $mapped,
            'engines' => $entries,
            'status' => 'READ_ONLY_CORE_MAPPING_OBSERVED',
            'site_execution_status' => 'NOT_CERTIFIED',
            'mutation_authorized' => false,
            'write_performed' => false,
            'dispatcher_created' => false,
        );
    }
}
