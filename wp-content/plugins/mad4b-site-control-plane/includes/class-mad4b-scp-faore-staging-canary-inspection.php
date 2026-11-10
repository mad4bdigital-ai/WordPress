<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Nonmutating, environment-scoped discovery for an exact FAORE option canary.
 *
 * No write ability is registered. A future write operation must be reviewed
 * separately and must not be used to replay an externally denied apply.
 */
final class MAD4B_SCP_FAORE_Staging_Canary_Inspection {
    const PLAN = 'mad4b/faore-staging-canary-plan';
    const VERIFY = 'mad4b/faore-staging-canary-verify';
    const CONTRACT = 'mad4b.faore.staging-canary-read.v1';
    const PREFIX = 'mad4b_faore_write_canary_';

    public static function boot() {
        add_action( 'wp_abilities_api_init', array( __CLASS__, 'register_abilities' ), 39 );
    }

    public static function input_schema() {
        return array(
            'type' => 'object',
            'properties' => array(
                'correlation_id' => array(
                    'type' => 'string',
                    'pattern' => '^[a-z0-9][a-z0-9_-]{7,79}$',
                ),
                'option_suffix' => array(
                    'type' => 'string',
                    'pattern' => '^[a-f0-9]{16}$',
                ),
            ),
            'required' => array( 'correlation_id', 'option_suffix' ),
            'additionalProperties' => false,
        );
    }

    public static function register_abilities() {
        if ( ! function_exists( 'wp_register_ability' ) ) return;
        foreach ( array(
            array( self::PLAN, 'Inspect Exact FAORE Staging Canary', 'plan' ),
            array( self::VERIFY, 'Verify Exact FAORE Staging Canary', 'verify' ),
        ) as $row ) {
            if ( function_exists( 'wp_has_ability' ) && wp_has_ability( $row[0] ) ) continue;
            wp_register_ability( $row[0], array(
                'label' => $row[1],
                'description' => 'Read-only exact Staging option presence inspection. Does not create a grant, option, ticket or receipt.',
                'category' => 'mad4b-read',
                'execute_callback' => array( __CLASS__, $row[2] ),
                'permission_callback' => array( 'MAD4B_SCP_Policy', 'can_read' ),
                'input_schema' => self::input_schema(),
                'output_schema' => array( 'type' => 'object', 'additionalProperties' => true ),
                'meta' => array(
                    'public' => false,
                    'show_in_rest' => false,
                    'mcp' => array( 'public' => false, 'type' => 'tool',
                        'surface' => 'read', 'mad4b_governed_write_authority' => false,
                        'production_mutation_allowed' => false ),
                    'annotations' => array( 'readonly' => true, 'destructive' => false, 'idempotent' => true ),
                ),
            ) );
        }
    }

    private static function scoped_input( $input ) {
        if ( ! is_array( $input ) || count( $input ) !== 2
            || array_diff( array_keys( $input ), array( 'correlation_id', 'option_suffix' ) )
            || ! is_string( $input['correlation_id'] ?? null )
            || 1 !== preg_match( '/^[a-z0-9][a-z0-9_-]{7,79}$/D', $input['correlation_id'] )
            || ! is_string( $input['option_suffix'] ?? null )
            || 1 !== preg_match( '/^[a-f0-9]{16}$/D', $input['option_suffix'] ) ) {
            return new WP_Error( 'mad4b_faore_canary_input_invalid',
                'An exact correlation_id and 16-hex suffix are required; arbitrary option keys are not accepted.' );
        }
        if ( ! class_exists( 'MAD4B_SCP_Site_Profile', false )
            || ! method_exists( 'MAD4B_SCP_Site_Profile', 'status' )
            || ! method_exists( 'MAD4B_SCP_Site_Profile', 'current_environment' )
            || 'staging' !== (string) MAD4B_SCP_Site_Profile::current_environment()
            || 'staging' !== wp_get_environment_type() ) {
            return new WP_Error( 'mad4b_faore_canary_wrong_environment',
                'Read-only FAORE option inspection is available on enrolled Staging only.' );
        }
        $profile = MAD4B_SCP_Site_Profile::status();
        if ( ! is_array( $profile )
            || 'mad4b.site-profile.v2' !== ( $profile['contract'] ?? '' )
            || true !== ( $profile['configured'] ?? false )
            || true !== ( $profile['origin_match'] ?? false )
            || true !== ( $profile['environment_match'] ?? false )
            || true !== ( $profile['deployment_binding_match'] ?? false )
            || 'staging' !== ( $profile['environment'] ?? '' )
            || ! wp_is_uuid( (string) ( $profile['site_uuid'] ?? '' ) )
            || ! is_string( $profile['canonical_origin'] ?? null )
            || untrailingslashit( home_url( '/' ) ) !== $profile['canonical_origin'] ) {
            return new WP_Error( 'mad4b_faore_canary_profile_unverified',
                'Current Site Profile and deployment binding must match exactly.' );
        }
        return array(
            'site_uuid' => $profile['site_uuid'],
            'canonical_origin' => $profile['canonical_origin'],
            'environment' => $profile['environment'],
            'correlation_id' => $input['correlation_id'],
            'option_key' => self::PREFIX . $input['option_suffix'],
        );
    }

    private static function inspect( $input, $operation ) {
        $scope = self::scoped_input( $input );
        if ( is_wp_error( $scope ) ) return $scope;
        // Unique object sentinel avoids conflating an absent option with a
        // stored false, empty string or a deliberately chosen string token.
        $missing = new stdClass();
        $value = get_option( $scope['option_key'], $missing );
        $present = $value !== $missing;
        // A read-only output explicitly refuses to certify any mutation. Do
        // not expose the stored value or infer rollback from absent state.
        $fingerprint = hash( 'sha256', wp_json_encode( array(
            'contract' => self::CONTRACT,
            'site_uuid' => $scope['site_uuid'],
            'canonical_origin' => $scope['canonical_origin'],
            'environment' => $scope['environment'],
            'correlation_id' => $scope['correlation_id'],
            'option_key_sha256' => hash( 'sha256', $scope['option_key'] ),
            'option_present' => $present,
        ), JSON_UNESCAPED_SLASHES ) );
        return array(
            'contract' => self::CONTRACT,
            'operation' => $operation,
            'site_uuid' => $scope['site_uuid'],
            'environment' => $scope['environment'],
            'correlation_id' => $scope['correlation_id'],
            'option_key_sha256' => hash( 'sha256', $scope['option_key'] ),
            'option_present' => $present,
            'observation_fingerprint' => $fingerprint,
            'write_ability_registered' => false,
            'approval_ticket_created' => false,
            'authorization_granted' => false,
            'mutation_performed' => false,
            'rollback_verified' => false,
        );
    }

    public static function plan( $input = array() ) {
        return self::inspect( $input, 'plan' );
    }

    public static function verify( $input = array() ) {
        return self::inspect( $input, 'verify' );
    }
}
