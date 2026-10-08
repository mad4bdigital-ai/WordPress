<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Denial-first semantic operations for the existing durable Remote Work Queue.
 * This is never an installer, credential lookup, shell, mutation, or approval.
 */
final class MAD4B_SCP_Assistant_Read_Work_Operations {
    const CONTRACT = 'mad4b.assistant-read-work.v1';
    const OPERATIONS = array(
        'assistant_provider_catalog_snapshot',
        'assistant_dependency_readback',
        'assistant_configuration_diff',
    );

    public static function definitions( array $registered ) {
        foreach ( self::OPERATIONS as $operation ) {
            // Existing registration owns collisions; never override providers.
            if ( isset( $registered[ $operation ] ) ) continue;
            $registered[ $operation ] = array(
                'executor' => 'external_browser_agent',
                'authority_surface' => 'mad4b-enrollment',
                'production_policy' => 'deny',
                'read_only' => true,
                'credential_access_allowed' => false,
                'plugin_lifecycle_allowed' => false,
                'validate_payload' => array( __CLASS__, 'validate_payload' ),
            );
        }
        return $registered;
    }

    /** Payload may name only exact identities, no executable content. */
    public static function validate_payload( $payload ) {
        if ( ! is_array( $payload ) ) return false;
        $allowed = array( 'contract', 'task_id', 'plan_sha256', 'binding_sha256',
            'capabilities', 'provider_ids', 'purpose' );
        foreach ( array_keys( $payload ) as $key ) {
            if ( ! is_string( $key ) || ! in_array( $key, $allowed, true ) ) return false;
        }
        if ( ( $payload['contract'] ?? null ) !== self::CONTRACT ) return false;
        if ( ! is_string( $payload['task_id'] ?? null )
            || ! preg_match( '/^proposal-[a-f0-9]{32}$/D', $payload['task_id'] ) ) return false;
        foreach ( array( 'plan_sha256', 'binding_sha256' ) as $key ) {
            if ( ! is_string( $payload[ $key ] ?? null )
                || ! preg_match( '/^[a-f0-9]{64}$/D', $payload[ $key ] ) ) return false;
        }
        if ( ! in_array( $payload['purpose'] ?? null,
            array( 'catalog_read', 'dependency_read', 'configuration_diff_read' ), true ) ) return false;
        foreach ( array( 'capabilities', 'provider_ids' ) as $name ) {
            $items = $payload[ $name ] ?? null;
            if ( ! is_array( $items ) || count( $items ) > 16
                || ( count( $items ) && array_keys( $items ) !== range( 0, count( $items ) - 1 ) ) ) return false;
            $seen = array();
            foreach ( $items as $item ) {
                if ( ! is_string( $item ) || strlen( $item ) > 80
                    || ! preg_match( '/^[a-z][a-z0-9._-]{2,79}$/D', $item )
                    || isset( $seen[ $item ] ) ) return false;
                $seen[ $item ] = true;
            }
        }
        return count( $payload['capabilities'] ) > 0;
    }
    /** Prevent cross-operation payload substitution and browser-agent mutation. */
    public static function validate_for_operation( $operation, $payload ) {
        $purposes = array(
            'assistant_provider_catalog_snapshot' => 'catalog_read',
            'assistant_dependency_readback' => 'dependency_read',
            'assistant_configuration_diff' => 'configuration_diff_read',
        );
        return isset( $purposes[ $operation ] )
            && self::validate_payload( $payload )
            && $payload['purpose'] === $purposes[ $operation ];
    }

    /** Exact site, origin, restore and generation fence for durable enqueue. */
    public static function runtime_binding_matches( $payload ) {
        if ( ! self::validate_payload( $payload )
            || ! class_exists( 'MAD4B_SCP_Adaptive_Operations_Context', false ) ) return false;
        $current = MAD4B_SCP_Adaptive_Operations_Context::current();
        if ( is_wp_error( $current ) || ! is_array( $current )
            || ( $current['environment'] ?? null ) !== 'staging' ) return false;
        $fields = array( 'site_uuid', 'environment', 'profile_digest',
            'origin_sha256', 'runtime_generation', 'artifact_sha256',
            'restore_epoch', 'external_record_sha256' );
        $exact = array();
        foreach ( $fields as $key ) {
            if ( ! array_key_exists( $key, $current ) ) return false;
            $exact[] = $current[ $key ];
        }
        return hash_equals( hash( 'sha256', serialize( $exact ) ),
            $payload['binding_sha256'] );
    }

}
