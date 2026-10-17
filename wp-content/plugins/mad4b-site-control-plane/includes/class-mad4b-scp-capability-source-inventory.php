<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Read-only coverage census for the already-installed capability registries.
 * This DOES NOT merge provider metadata into an executable registry or assert
 * coverage of external ChatGPT connections (which WordPress cannot enumerate).
 */
final class MAD4B_SCP_Capability_Source_Inventory {
    const CONTRACT = 'mad4b.capability-source-inventory.v1';

    private static function source( $id, $state, $count, $detail ) {
        return array(
            'source_id' => $id, 'coverage_state' => $state,
            'observed_count' => $count, 'detail' => $detail,
            'source_is_execution_authority' => false,
        );
    }

    public static function inspect( array $input = array() ) {
        $permission = class_exists( 'MAD4B_SCP_Policy' ) ? MAD4B_SCP_Policy::can_read() : false;
        if ( is_wp_error( $permission ) || true !== $permission )
            return new WP_Error( 'mad4b_capability_inventory_read_denied', 'Governed read authority is required.' );
        // Summary never traverses expensive provider registries. Detail is
        // explicit and limited to one trusted source per call.
        $known = array( 'wordpress_abilities', 'operation_registry', 'managed_skills',
            'remote_operation_parity', 'host_authorized_external_federation' );
        $selected = isset( $input['source_id'] ) ? $input['source_id'] : '';
        if ( ! is_string( $selected ) || ( '' !== $selected && ! in_array( $selected, $known, true ) ) )
            return new WP_Error( 'mad4b_capability_inventory_source_invalid', 'Unknown capability source.' );
        $sources = array();
        foreach ( $known as $source_id ) {
            if ( '' !== $selected && $selected !== $source_id ) continue;
            $sources[] = self::source( $source_id,
                'host_authorized_external_federation' === $source_id
                    ? 'EXTERNAL_ATTESTATION_REQUIRED' : 'NOT_INSPECTED',
                null, 'Select a trusted source_id to inspect its current coverage without scanning other registries.' );
        }
        if ( '' === $selected || 'host_authorized_external_federation' === $selected ) {
            $uncertain = array_map( static function ( $item ) { return $item['source_id']; }, $sources );
            return array(
                'contract' => self::CONTRACT, 'source_count' => count( $sources ),
                'registered_source_count' => count( $known ),
                'mode' => '' === $selected ? 'summary' : 'detail',
                'sources' => $sources, 'uncertain_sources' => $uncertain,
                'complete_federation_proven' => false,
                'current_runtime_execution_authority_evaluated' => false,
                'no_implicit_external_connector_access' => true,
                'read_only' => true, 'mutation_performed' => false, 'authority_effect' => 'none',
            );
        }
        $ability_names = null;
        if ( 'wordpress_abilities' === $selected ) {
            try {
                if ( class_exists( 'MAD4B_SCP_ChatGPT_Tool_Projection' ) )
                    $ability_names = MAD4B_SCP_ChatGPT_Tool_Projection::all_site_ability_names();
            } catch ( Throwable $error ) { $ability_names = null; }
        }
        if ( 'wordpress_abilities' === $selected ) $sources[0] = self::source( 'wordpress_abilities',
            is_array( $ability_names ) ? 'REGISTERED_NOT_AUTHORIZED' : 'UNAVAILABLE',
            is_array( $ability_names ) ? count( $ability_names ) : null,
            'Use indexed search to obtain verified metadata coverage; registration alone is not execution permission.' );

        $operations = null;
        if ( 'operation_registry' === $selected ) {
            try {
                if ( class_exists( 'MAD4B_SCP_Operation_Registry' ) )
                    $operations = MAD4B_SCP_Operation_Registry::status();
            } catch ( Throwable $error ) { $operations = null; }
        }
        $operations_ready = is_array( $operations ) && ! empty( $operations['ready'] );
        if ( 'operation_registry' === $selected ) $sources[0] = self::source( 'operation_registry',
            $operations_ready ? 'REGISTERED_NOT_AUTHORIZED' : 'PARTIAL_OR_UNAVAILABLE',
            is_array( $operations ) && isset( $operations['count'] ) ? (int) $operations['count'] : null,
            is_array( $operations ) ? (string) ( $operations['state'] ?? 'unknown' ) : 'unavailable' );

        $skills = null;
        if ( 'managed_skills' === $selected ) {
            try {
                if ( class_exists( 'MAD4B_SCP_Skill_Registry' ) )
                    $skills = MAD4B_SCP_Skill_Registry::status();
            } catch ( Throwable $error ) { $skills = null; }
        }
        $skills_count = is_array( $skills ) && isset( $skills['skill_count'] ) ? (int) $skills['skill_count'] : null;
        $skill_scan_max = class_exists( 'MAD4B_SCP_Skill_Registry' )
            ? MAD4B_SCP_Skill_Registry::MAX_SCAN_SKILLS : 0;
        if ( 'managed_skills' === $selected ) $sources[0] = self::source( 'managed_skills',
            null === $skills_count ? 'UNAVAILABLE'
                : ( $skills_count >= $skill_scan_max ? 'TRUNCATED_OR_UNKNOWN' : 'INVENTORIED_NOT_CERTIFIED' ),
            $skills_count, 'Enabled state and lifecycle certification must be verified separately.' );

        $remote = null;
        if ( 'remote_operation_parity' === $selected ) {
            try {
                if ( class_exists( 'MAD4B_SCP_Remote_Operation_Parity' ) )
                    $remote = MAD4B_SCP_Remote_Operation_Parity::status();
            } catch ( Throwable $error ) { $remote = null; }
        }
        $rejected = is_array( $remote ) ? (int) ( $remote['rejected_registration_count'] ?? 0 ) : 0;
        if ( 'remote_operation_parity' === $selected ) $sources[0] = self::source( 'remote_operation_parity',
            ! is_array( $remote ) ? 'UNAVAILABLE' :
                ( $rejected || empty( $remote['ready'] ) ? 'PARTIAL_OR_REJECTED' : 'BOUNDED_CATALOG_COMPLETENESS_UNVERIFIED' ),
            is_array( $remote ) && isset( $remote['operation_count'] ) ? (int) $remote['operation_count'] : null,
            is_array( $remote ) ? 'Rejected registrations: ' . $rejected . '; hard catalog ceilings require separate completeness proof.' : 'unavailable' );

        $uncertain = array_values( array_map( static function ( $item ) {
            return $item['source_id'];
        }, array_filter( $sources, static function ( $item ) {
            return ! in_array( $item['coverage_state'], array( 'REGISTERED_NOT_AUTHORIZED', 'INVENTORIED_NOT_CERTIFIED' ), true );
        } ) ) );

        return array(
            'contract' => self::CONTRACT, 'source_count' => count( $sources ),
            'registered_source_count' => count( $known ), 'mode' => 'detail',
            'sources' => $sources, 'uncertain_sources' => $uncertain,
            'complete_federation_proven' => false, 'current_runtime_execution_authority_evaluated' => false,
            'no_implicit_external_connector_access' => true, 'read_only' => true,
            'mutation_performed' => false, 'authority_effect' => 'none',
        );
    }
}
