<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Read-only stage classifier for a failed MCP connection. Input is an observed
 * local snapshot. "not_checked" is never converted to success or failure.
 * It cannot change transport permission, OAuth, catalog, grants or writes.
 */
final class MAD4B_SCP_MCP_Connection_Stage_Diagnostics {
    const CONTRACT = 'mad4b.mcp-connection-stage-diagnostics.v1';

    private static function stage( $status, $reason ) {
        return array( 'status' => $status, 'reason' => $reason,
            'independently_verified' => false );
    }

    private static function readiness( array $input, array $fields, $failure ) {
        foreach ( $fields as $field ) {
            if ( ! array_key_exists( $field, $input )
                || ! is_bool( $input[$field] ) )
                return self::stage( 'not_checked', 'local_evidence_not_available' );
        }
        foreach ( $fields as $field ) {
            if ( false === $input[$field] )
                return self::stage( 'blocked', $failure . '_' . $field );
        }
        return self::stage( 'ready_local', 'local_prerequisites_observed' );
    }

    public static function project( array $evidence ) {
        $local_oauth = isset( $evidence['oauth'] ) && is_array( $evidence['oauth'] )
            ? $evidence['oauth'] : array();
        $registration = isset( $evidence['registration'] ) && is_array( $evidence['registration'] )
            ? $evidence['registration'] : array();
        $catalog = isset( $evidence['catalog'] ) && is_array( $evidence['catalog'] )
            ? $evidence['catalog'] : array();
        $bootstrap = isset( $evidence['bootstrap'] ) && is_array( $evidence['bootstrap'] )
            ? $evidence['bootstrap'] : array();

        $stages = array(
            'bootstrap' => self::readiness( $bootstrap, array( 'ready' ), 'runtime_bootstrap' ),
            'oauth_configuration' => self::readiness( $local_oauth,
                array( 'environment_ready', 'local_oauth_effective',
                    'bridge_effective', 'cimd_ready' ), 'oauth_configuration' ),
            'server_registration' => self::stage( 'not_checked', 'deep_registration_not_observed' ),
            'catalog_materialization' => self::stage( 'not_checked', 'catalog_readback_not_observed' ),
            'external_bearer_validation' => self::stage( 'not_checked', 'requires_independent_client_request' ),
            'mcp_initialize' => self::stage( 'not_checked', 'requires_independent_client_request' ),
            'mcp_tools_list' => self::stage( 'not_checked', 'requires_independent_client_request' ),
            'mcp_read_call' => self::stage( 'not_checked', 'requires_independent_client_request' ),
            'mcp_write_call' => self::stage( 'not_checked', 'requires_independent_client_request' ),
        );

        if ( ! empty( $registration['deep_registration_deferred'] ) ) {
            $stages['server_registration'] = self::stage( 'not_checked', 'deep_registration_deferred' );
        } elseif ( array_key_exists( 'actual_registered', $registration )
            && is_bool( $registration['actual_registered'] ) ) {
            $stages['server_registration'] = $registration['actual_registered']
                ? self::stage( 'ready_local', 'route_registration_observed' )
                : self::stage( 'blocked', 'server_registration_failed' );
        }

        if ( array_key_exists( 'ready', $catalog ) && is_bool( $catalog['ready'] ) ) {
            $stages['catalog_materialization'] = $catalog['ready']
                ? self::stage( 'ready_local', 'ability_descriptor_catalog_observed' )
                : self::stage( 'blocked', 'ability_descriptor_or_schema_not_ready' );
        }

        $first_blocked = '';
        foreach ( $stages as $name => $row ) {
            if ( 'blocked' === $row['status'] ) { $first_blocked = $name; break; }
        }

        return array(
            'contract' => self::CONTRACT,
            'authorizing' => false,
            'mutating' => false,
            'external_connection_certified' => false,
            'profile_comparison_proven' => false,
            'first_observed_local_blocker' => $first_blocked,
            'root_cause_determined' => false,
            'stages' => $stages,
            'next_experiment' => 'same_endpoint_same_user_compare_direct_and_governed_after_oauth_transport_catalog_readbacks',
        );
    }
}
