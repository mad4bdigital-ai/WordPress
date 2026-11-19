<?php
/**
 * Central Host-resource ticket input validator and disabled-until-enrolled
 * WordPress Ability. The Host Principal is independent of WP user accounts.
 * Original Broker, Host312 and independent Host313 remain the only runtime
 * authorities; NO direct Host execution is implemented here.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }
final class MAD4B_SCP_Native_Host_Source_Release_Review {
    const ABILITY = 'mad4b/host-source-release';
    const PROVIDER = 'mad4b-native-host-source';
    const OPERATION_INPUT = array(
        'ability_name', 'git_commit', 'host_scope', 'operation_id',
        'owner_catalog_sha256', 'owner_id', 'plan_sha256',
    );

    public static function request_input( $input ) {
        if ( ! is_array( $input ) || count( $input ) !== count( self::OPERATION_INPUT ) ) {
            return new WP_Error( 'mad4b_host_source_review_shape', 'Exact original Host source ticket input is required.' );
        }
        $expected = self::OPERATION_INPUT;
        $actual = array_keys( $input );
        sort( $expected, SORT_STRING );
        sort( $actual, SORT_STRING );
        if ( $expected !== $actual ) {
            return new WP_Error( 'mad4b_host_source_review_fields', 'Unexpected Host release ticket fields.' );
        }
        foreach ( $input as $value ) {
            if ( ! is_string( $value ) ) {
                return new WP_Error( 'mad4b_host_source_review_type', 'Host release ticket fields must be strings.' );
            }
        }
        if ( self::ABILITY !== $input['ability_name']
            || 1 !== preg_match( '/^[a-z][a-z0-9._-]{2,79}$/D', $input['operation_id'] )
            || 1 !== preg_match( '/^host:[a-zA-Z0-9_.-]{3,80}$/D', $input['host_scope'] )
            || 1 !== preg_match( '/^[a-zA-Z0-9_.:-]{3,120}$/D', $input['owner_id'] )
            || 1 !== preg_match( '/^[a-f0-9]{40}$/D', $input['git_commit'] )
            || 1 !== preg_match( '/^[a-f0-9]{64}$/D', $input['plan_sha256'] )
            || 1 !== preg_match( '/^[a-f0-9]{64}$/D', $input['owner_catalog_sha256'] ) ) {
            return new WP_Error( 'mad4b_host_source_review_identity', 'Host operation, owner and immutable Git plan must be exact.' );
        }
        // Preserve original canonical key values. A reviewer must not broaden
        // the approved target, append a WordPress principal, or transform hashes.
        return $input;
    }

    public static function boot() {
        if ( ! function_exists( 'add_action' ) ) return;
        add_action( 'wp_abilities_api_init', array( __CLASS__, 'register_ability' ), 43 );
    }

    public static function register_ability() {
        if ( ! function_exists( 'wp_register_ability' ) ) return;
        if ( function_exists( 'wp_has_ability' ) && wp_has_ability( self::ABILITY ) ) return;
        $schema = array( 'type' => 'object', 'additionalProperties' => false, 'properties' => array(), 'required' => self::OPERATION_INPUT );
        foreach ( self::OPERATION_INPUT as $key ) {
            $schema['properties'][ $key ] = array( 'type' => 'string', 'maxLength' => 120 );
        }
        return wp_register_ability( self::ABILITY, array(
            'label' => 'MAD4B Host Source Release (Original Authority Required)',
            'description' => 'Review an immutable Host release. Execution requires distinct registered Original306, Host312, Master and Broker authority.',
            'category' => 'mad4b-admin',
            'execute_callback' => array( __CLASS__, 'execute' ),
            'permission_callback' => array( __CLASS__, 'can_execute' ),
            'input_schema' => $schema,
            'output_schema' => array( 'type' => 'object', 'additionalProperties' => true ),
            'meta' => array( 'public' => false, 'show_in_rest' => false,
                'mcp' => array( 'public' => false, 'type' => 'tool', 'surface' => 'write',
                    'production_mutation_allowed' => false ),
                'annotations' => array( 'readonly' => false, 'destructive' => true,
                    'idempotent' => false ) ),
        ) );
    }

    public static function can_execute( $input = null ) {
        // Deliberately never approve Host execution through WP permissions,
        // even for Master Admin. The original root-pinned execution lane must
        // enforce fresh signed proof/grant; review is not a delegation.
        return new WP_Error( 'mad4b_host_source_native_issuer_not_enrolled',
            'Host source execution requires independently signed Original312 and Original313.' );
    }

    public static function execute( $input = array() ) {
        return self::can_execute( $input );
    }
}
