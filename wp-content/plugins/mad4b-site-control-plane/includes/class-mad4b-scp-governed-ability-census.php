<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Registry-driven, bounded first-party Staging enrollment census.
 *
 * WordPress registration and metadata are NOT authority. Only actual core
 * WP_Ability callbacks implemented by this plugin may enter the existing
 * OAuth step-up enrollment dispatcher. The target's permission_callback,
 * execution fence, exact registration/schema digests and site gates remain
 * independently enforced at every execution. No authorization is cached.
 */
final class MAD4B_SCP_Governed_Ability_Census {
    const CONTRACT = 'mad4b.governed-ability-census.v1';
    // Limit *first-party matches*, not all WordPress abilities registered by
    // unrelated plugins. A large third-party registry is not a MAD4B outage.
    const MAX_ABILITIES = 2048;
    const MAX_FIRST_PARTY_CANDIDATES = 256;

    /** Return stable, installation-path-independent first-party callback identity. */
    private static function callback_identity( $callback ) {
        if ( ! is_array( $callback ) || 2 !== count( $callback )
            || ! is_string( $callback[1] )
            || ! preg_match( '/^MAD4B_SCP_[A-Za-z0-9_]+$/D',
                is_object( $callback[0] ) ? get_class( $callback[0] ) : (string) $callback[0] ) )
            return '';
        try {
            $method = new ReflectionMethod( $callback[0], $callback[1] );
            if ( ! $method->isPublic() || ! is_callable( $callback ) ) return '';
            $file = realpath( (string) $method->getFileName() );
            $root = realpath( __DIR__ );
            if ( false === $file || false === $root
                || 0 !== strpos( $file, $root . DIRECTORY_SEPARATOR ) ) return '';
            return $method->getDeclaringClass()->getName() . '::' . $method->getName()
                . '@' . str_replace( DIRECTORY_SEPARATOR, '/', substr( $file, strlen( $root ) + 1 ) );
        } catch ( Throwable $error ) {
            return '';
        }
    }

    private static function callback_is_ours( $callback ) {
        return '' !== self::callback_identity( $callback );
    }

    private static function callback_pair_digest( $ability ) {
        if ( ! class_exists( 'WP_Ability', false ) || ! ( $ability instanceof WP_Ability ) ) return '';
        $identities = array();
        try {
            foreach ( array( 'execute_callback', 'permission_callback' ) as $property_name ) {
                $property = new ReflectionProperty( 'WP_Ability', $property_name );
                $property->setAccessible( true );
                $identity = self::callback_identity( $property->getValue( $ability ) );
                if ( '' === $identity ) return '';
                $identities[ $property_name ] = $identity;
            }
        } catch ( Throwable $error ) {
            return '';
        }
        $encoded = function_exists( 'wp_json_encode' )
            ? wp_json_encode( $identities, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE )
            : json_encode( $identities );
        return is_string( $encoded ) ? hash( 'sha256', $encoded ) : '';
    }

    private static function trusted_callback_pair( $ability ) {
        return '' !== self::callback_pair_digest( $ability );
    }

    /**
     * Security-sensitive enumeration needs the *registered* objects, not a
     * result filtered by unrelated plugins through WordPress 7.1 discovery
     * filters. Core explicitly supports raw registry inspection for this
     * purpose. Named wp_get_ability() identity is checked for every result.
     */
    private static function registered_snapshot() {
        if ( class_exists( 'WP_Abilities_Registry', false )
            && method_exists( 'WP_Abilities_Registry', 'get_instance' ) ) {
            try {
                $registry = WP_Abilities_Registry::get_instance();
                if ( is_object( $registry ) && method_exists( $registry, 'get_all_registered' ) ) {
                    $registered = $registry->get_all_registered();
                    return is_array( $registered ) ? $registered : array();
                }
            } catch ( Throwable $error ) {
                return array();
            }
        }
        // Minimal hermetic and pre-6.9 partial integration fallback.
        $filtered = function_exists( 'wp_get_abilities' ) ? wp_get_abilities() : array();
        return is_array( $filtered ) ? $filtered : array();
    }

    /**
     * The same registry loop classifies Staging broker targets separately from
     * direct-only, independently governed host/runtime gate controls. A direct
     * tool does not automatically qualify for generic enrollment dispatch.
     */
    private static function scan( $mode ) {
        // tools/list materialization happens before OAuth and Site Profile
        // authority convergence. Metadata discovery must not be a grant.
        // The enrollment broker rechecks exact Staging identity separately.
        if ( ! function_exists( 'wp_get_ability' ) ) return array();
        $registry = self::registered_snapshot();
        if ( ! is_array( $registry ) ) return array();
        $result = array();
        $first_party_candidates = 0;
        foreach ( $registry as $key => $ability ) {
            if ( ! is_object( $ability ) || ! method_exists( $ability, 'get_meta' ) ) continue;
            $name = method_exists( $ability, 'get_name' )
                ? (string) $ability->get_name() : ( is_string( $key ) ? $key : '' );
            if ( ! preg_match( '#^mad4b/[a-z][a-z0-9-]{2,159}$#D', $name ) ) continue;
            ++$first_party_candidates;
            // Too many MAD4B candidates is an integrity anomaly, not a reason
            // to start admitting arbitrary callbacks. Fail closed.
            if ( $first_party_candidates > self::MAX_FIRST_PARTY_CANDIDATES ) return array();
            if ( wp_get_ability( $name ) !== $ability ) continue;
            $meta = $ability->get_meta();
            $mcp = is_array( $meta ) && is_array( $meta['mcp'] ?? null ) ? $meta['mcp'] : array();
            $annotations = is_array( $meta ) && is_array( $meta['annotations'] ?? null )
                ? $meta['annotations'] : array();
            if ( 'enrollment' !== (string) ( $mcp['surface'] ?? '' )
                || true !== ( $mcp['chatgpt_direct_step_up'] ?? null )
                || true !== ( $mcp['exact_chatgpt_client_required'] ?? null )
                || false !== ( $mcp['generic_remote_admin'] ?? null )
                || false !== ( $annotations['readonly'] ?? null )
                || ! method_exists( $ability, 'execute' )
                || ! method_exists( $ability, 'get_input_schema' ) ) continue;
            if ( 'staging' === $mode || 'projection' === $mode ) {
                if ( false !== ( $mcp['production_mutation_allowed'] ?? null )
                    || true === ( $mcp['production_allowed'] ?? false )
                    || true === ( $mcp['direct_only_excluded_from_staging_broker'] ?? false ) ) continue;
                // Structural MCP presentation can include reviewed direct
                // step-ups. The generic Enrollment broker requires independent,
                // explicit first-party owner-review opt-in. No metadata-only
                // inference can waive a human decision.
                if ( 'staging' === $mode
                    && ( true !== ( $mcp['governed_enrollment_broker'] ?? null )
                        || 'owner_attestation_v1' !== ( $mcp['broker_dispatch_policy'] ?? null )
                        || true !== ( $mcp['owner_intent_only'] ?? null ) ) ) continue;
            } else {
                if ( true !== ( $mcp['direct_only_excluded_from_staging_broker'] ?? null )
                    || true !== ( $mcp['production_allowed'] ?? null )
                    || true !== ( $mcp['generic_raw_sql_breakglass_configuration'] ?? null ) ) continue;
            }
            $callback_digest = self::callback_pair_digest( $ability );
            if ( '' === $callback_digest ) continue;
            $result[ $name ] = array(
                'ability_name' => $name,
                'operation_id' => 'core_step_up_' . substr( str_replace( '-', '_', substr( str_replace( '/', '_', $name ), 0, 48 ) ), 0, 48 )
                    . '_' . substr( hash( 'sha256', $name ), 0, 12 ),
                'owner_intent_only' => true === ( $mcp['owner_intent_only'] ?? null ),
                'broker_dispatch_policy' => 'staging' === $mode
                    ? (string) ( $mcp['broker_dispatch_policy'] ?? '' ) : '',
                'callback_pair_digest' => $callback_digest,
                'source' => 'verified_first_party_php_callbacks',
                'authority_effect' => 'none',
                'execution_lane' => 'staging' === $mode ? 'staging_enrollment' : ( 'projection' === $mode ? 'structural_projection' : 'direct_only' ),
            );
        }
        ksort( $result, SORT_STRING );
        return $result;
    }

    public static function structural_step_up() { return self::scan( 'projection' ); }

    public static function eligible() {
        if ( ! class_exists( 'MAD4B_SCP_Site_Profile' )
            || ! MAD4B_SCP_Site_Profile::configured()
            || 'staging' !== (string) MAD4B_SCP_Site_Profile::current_environment()
            || ! MAD4B_SCP_Site_Profile::origin_enrolled()
            || ! MAD4B_SCP_Site_Profile::site_urls_match_enrollment() ) return array();
        return self::scan( 'staging' );
    }

    public static function direct_only() { return self::scan( 'direct_only' ); }

    /**
     * Bounded, secret-free metadata troubleshooting for CI and Staging reads.
     * It never calls the registered execution or permission callbacks.
     */
    public static function diagnostic( $name ) {
        $ability = function_exists( 'wp_get_ability' ) ? wp_get_ability( (string) $name ) : null;
        $meta = is_object( $ability ) && method_exists( $ability, 'get_meta' )
            ? $ability->get_meta() : array();
        $mcp = is_array( $meta ) && is_array( $meta['mcp'] ?? null ) ? $meta['mcp'] : array();
        $annotations = is_array( $meta ) && is_array( $meta['annotations'] ?? null )
            ? $meta['annotations'] : array();
        $callbacks = array();
        if ( is_object( $ability ) && class_exists( 'WP_Ability', false )
            && $ability instanceof WP_Ability ) {
            foreach ( array( 'execute_callback', 'permission_callback' ) as $property_name ) {
                try {
                    $property = new ReflectionProperty( 'WP_Ability', $property_name );
                    $property->setAccessible( true );
                    $callbacks[ $property_name ] = self::callback_is_ours(
                        $property->getValue( $ability ) );
                } catch ( Throwable $error ) {
                    $callbacks[ $property_name ] = false;
                }
            }
        }
        return array(
            'registered' => is_object( $ability ),
            'registry_count' => count( self::registered_snapshot() ),
            'ability_is_wp_ability' => is_object( $ability )
                && class_exists( 'WP_Ability', false ) && $ability instanceof WP_Ability,
            'surface_enrollment' => 'enrollment' === ( $mcp['surface'] ?? null ),
            'direct_step_up' => true === ( $mcp['chatgpt_direct_step_up'] ?? null ),
            'exact_client_required' => true === ( $mcp['exact_chatgpt_client_required'] ?? null ),
            'generic_remote_admin_false' => false === ( $mcp['generic_remote_admin'] ?? null ),
            'production_mutation_allowed_false' => false === ( $mcp['production_mutation_allowed'] ?? null ),
            'production_allowed_true' => true === ( $mcp['production_allowed'] ?? null ),
            'readonly_false' => false === ( $annotations['readonly'] ?? null ),
            'callback_provenance' => $callbacks,
            'structural_discovery' => isset( self::structural_step_up()[ (string) $name ] ),
        );
    }

    public static function operation_id_for( $name ) {
        $row = self::eligible()[ (string) $name ] ?? null;
        return is_array( $row ) ? (string) $row['operation_id'] : '';
    }
}
