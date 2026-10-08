<?php
/**
 * Capability-first, provider-neutral read-only inventory.
 * Browser claims, installed plugin families and registered ability metadata
 * are NOT equivalent to executed functionality or release certification.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class MAD4B_SCP_Capability_Atlas {
    const CONTRACT = 'mad4b.capability-atlas.v1';
    const ABILITY = 'mad4b/capability-atlas';
    const MAX_PROVIDERS = 32;
    const MAX_PLUGIN_ROWS = 240;
    const MAX_CAPABILITIES_PER_PROVIDER = 64;
    const MAX_CAPABILITIES = 256;
    private static $booted = false;

    public static function boot() {
        if ( self::$booted || ! function_exists( 'add_action' ) ) return;
        self::$booted = true;
        add_action( 'wp_abilities_api_init', array( __CLASS__, 'register_ability' ), 42 );
    }

    public static function register_ability() {
        if ( ! function_exists( 'wp_register_ability' ) ||
            ( function_exists( 'wp_has_ability' ) && wp_has_ability( self::ABILITY ) ) ) return;
        wp_register_ability( self::ABILITY, array(
            'label' => 'Capability Atlas',
            'description' => 'Read-only capability-first coverage across browser providers and plugin families; no executor authorization.',
            'category' => 'mad4b-read',
            'execute_callback' => array( __CLASS__, 'read_snapshot' ),
            'permission_callback' => array( 'MAD4B_SCP_Policy', 'can_read' ),
            'input_schema' => array( 'type' => 'object', 'properties' => array(), 'additionalProperties' => false ),
            'output_schema' => array( 'type' => 'object', 'additionalProperties' => true ),
            'meta' => array(
                'public' => false, 'show_in_rest' => false,
                'mcp' => array( 'public' => false, 'type' => 'tool', 'surface' => 'read' ),
                'annotations' => array( 'readonly' => true, 'destructive' => false, 'idempotent' => true ),
            ),
        ) );
    }

    public static function read_snapshot( $input = array() ) {
        if ( ! is_array( $input ) || count( $input ) ) {
            return self::blocked( array( 'input_invalid' ) );
        }
        if ( ! class_exists( 'MAD4B_SCP_Browser_Acceptance_Core', false ) ||
            ! class_exists( 'MAD4B_SCP_Plugin_Discovery', false ) ) {
            return self::blocked( array( 'inventory_provider_unavailable' ) );
        }
        return self::compose(
            MAD4B_SCP_Browser_Acceptance_Core::capabilities(),
            MAD4B_SCP_Plugin_Discovery::functional_coverage_report()
        );
    }

    private static function blocked( $reasons ) {
        return array(
            'contract' => self::CONTRACT, 'read_only' => true,
            'authorizing' => false, 'execution_allowed' => false,
            'complete' => false, 'capabilities' => array(), 'count' => 0,
            'blocking_reasons' => $reasons
        );
    }

    private static function capability_id( $id ) {
        return is_string( $id ) && strlen( $id ) <= 80 &&
            1 === preg_match( '/^[a-z][a-z0-9._-]{2,79}$/D', $id );
    }

    public static function compose( $browser, $plugin ) {
        if ( ! is_array( $browser ) || ! is_array( $plugin ) ||
            ( $browser['contract'] ?? '' ) !== 'mad4b.browser-acceptance-capabilities.v1' ||
            ( $plugin['contract'] ?? '' ) !== 'mad4b.provider-functional-coverage.v1' ||
            ( $browser['read_only'] ?? null ) !== true ||
            ( $browser['authorizing'] ?? null ) !== false ||
            ( $plugin['read_only'] ?? null ) !== true ||
            ( $plugin['authority_created'] ?? null ) !== false ||
            ! isset( $browser['providers'], $plugin['items'] ) ||
            ! is_array( $browser['providers'] ) || ! is_array( $plugin['items'] ) ||
            count( $browser['providers'] ) > self::MAX_PROVIDERS ||
            count( $plugin['items'] ) > self::MAX_PLUGIN_ROWS ||
            ( $browser['provider_count'] ?? -1 ) !== count( $browser['providers'] ) ||
            ( $plugin['count'] ?? -1 ) !== count( $plugin['items'] ) ) {
            return self::blocked( array( 'inventory_contract_invalid' ) );
        }

        $capabilities = array();
        $blockers = array();
        $seen_provider = array();
        $recognition = array();
        $discovery = $browser['site_discovery'] ?? array();
        if ( ! is_array( $discovery ) || ( $discovery['discovery_complete'] ?? false ) !== true ) {
            $blockers[] = 'browser_discovery_incomplete';
        } else {
            foreach ( (array) ( $discovery['provider_matches'] ?? array() ) as $match ) {
                if ( is_array( $match ) && isset( $match['provider_id'] ) &&
                    is_string( $match['provider_id'] ) && ! empty( $match['recognized'] ) ) {
                    $recognition[ $match['provider_id'] ] = true;
                }
            }
        }
        foreach ( $browser['providers'] as $row ) {
            if ( ! is_array( $row ) ) { $blockers[] = 'browser_provider_invalid'; break; }
            $provider = $row['provider_id'] ?? '';
            $contract = $row['contract'] ?? '';
            if ( ! self::capability_id( $provider ) || ! self::capability_id( $contract ) ||
                isset( $seen_provider[ $provider ] ) ) {
                $blockers[] = 'browser_provider_identity_invalid'; break;
            }
            $seen_provider[ $provider ] = true;
            $claims = $row['capabilities']['capabilities'] ?? array();
            if ( ! is_array( $claims ) || count( $claims ) > self::MAX_CAPABILITIES_PER_PROVIDER ) {
                $blockers[] = 'browser_capability_claim_invalid'; break;
            }
            if ( ( $row['capabilities']['authorizing'] ?? null ) !== false ||
                ( $row['capabilities']['read_only'] ?? null ) !== true ) {
                $blockers[] = 'browser_capability_authority_invalid'; break;
            }
            foreach ( $claims as $capability ) {
                if ( ! self::capability_id( $capability ) ) {
                    $blockers[] = 'browser_capability_id_invalid'; break 2;
                }
                if ( ! isset( $capabilities[ $capability ] ) ) $capabilities[ $capability ] = array();
                $capabilities[ $capability ][] = array(
                    'source' => 'browser_provider',
                    'provider_id' => $provider,
                    'provider_contract' => $contract,
                    'recognized' => isset( $recognition[ $provider ] ),
                    'status' => 'declared_not_certified',
                );
            }
        }

        $seen_files = array();
        foreach ( $plugin['items'] as $row ) {
            if ( ! is_array( $row ) ) { $blockers[] = 'plugin_family_row_invalid'; break; }
            $file = $row['plugin_file'] ?? '';
            $family = $row['functional_family_key'] ?? '';
            if ( ! is_string( $file ) || '' === $file || strlen( $file ) > 190 ||
                isset( $seen_files[ $file ] ) || ! self::capability_id( $family ) ) {
                $blockers[] = 'plugin_family_identity_invalid'; break;
            }
            $seen_files[ $file ] = true;
            $key = 'family.' . $family;
            if ( ! isset( $capabilities[ $key ] ) ) $capabilities[ $key ] = array();
            $capabilities[ $key ][] = array(
                'source' => 'plugin_family',
                'plugin_file' => $file,
                'adapter_id' => is_string( $row['adapter_id'] ?? null ) ? $row['adapter_id'] : '',
                'status' => 'inventory_only',
                'functional_coverage_state' => is_string( $row['functional_coverage']['state'] ?? null )
                    ? $row['functional_coverage']['state'] : 'unknown',
            );
        }

        if ( count( $capabilities ) > self::MAX_CAPABILITIES ) $blockers[] = 'capability_atlas_overflow';
        if ( $blockers ) return self::blocked( array_values( array_unique( $blockers ) ) );
        ksort( $capabilities, SORT_STRING );
        $items = array();
        foreach ( $capabilities as $id => $providers ) {
            usort( $providers, static function ( $a, $b ) {
                return strcmp( (string) ( $a['provider_id'] ?? $a['plugin_file'] ?? '' ),
                    (string) ( $b['provider_id'] ?? $b['plugin_file'] ?? '' ) );
            } );
            $items[] = array(
                'capability_id' => $id,
                'candidate_count' => count( $providers ),
                'candidate_ambiguous' => count( $providers ) > 1,
                'candidates' => $providers,
                'state' => 'unverified',
                'execution_allowed' => false,
                'certification_issued' => false,
                'next_required_evidence' => array( 'provider_contract', 'semantic_oracle',
                    'approved_execution_adapter', 'independent_reducer' ),
            );
        }
        return array(
            'contract' => self::CONTRACT, 'read_only' => true,
            'authorizing' => false, 'execution_allowed' => false,
            'complete' => true, 'capabilities' => $items,
            'count' => count( $items ),
            'candidate_count' => array_sum( array_map( static function ( $x ) {
                return $x['candidate_count'];
            }, $items ) ),
            'unmapped_plugin_count' => count( (array) ( $discovery['unmapped_plugins'] ?? array() ) ),
            'blocking_reasons' => array(),
        );
    }
}
