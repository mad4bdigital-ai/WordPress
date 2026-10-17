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
    const MAX_ABILITIES = 2048;

    private static function callback_is_ours( $callback ) {
        if ( ! is_array( $callback ) || 2 !== count( $callback )
            || ! is_string( $callback[1] )
            || ! preg_match( '/^MAD4B_SCP_[A-Za-z0-9_]+$/D',
                is_object( $callback[0] ) ? get_class( $callback[0] ) : (string) $callback[0] ) )
            return false;
        try {
            $method = new ReflectionMethod( $callback[0], $callback[1] );
            if ( ! $method->isPublic() ) return false;
            $file = realpath( (string) $method->getFileName() );
            $root = realpath( __DIR__ );
            return false !== $file && false !== $root
                && 0 === strpos( $file, $root . DIRECTORY_SEPARATOR );
        } catch ( Throwable $error ) {
            return false;
        }
    }

    private static function trusted_callback_pair( $ability ) {
        // WordPress 6.9+ exposes these callback properties as protected, not as
        // public getters. Read-only Reflection is used solely for provenance;
        // never invoke either callback during discovery or preparation.
        if ( ! class_exists( 'WP_Ability', false ) || ! ( $ability instanceof WP_Ability ) ) return false;
        try {
            foreach ( array( 'execute_callback', 'permission_callback' ) as $property_name ) {
                $property = new ReflectionProperty( 'WP_Ability', $property_name );
                $property->setAccessible( true );
                if ( ! self::callback_is_ours( $property->getValue( $ability ) ) ) return false;
            }
        } catch ( Throwable $error ) {
            return false;
        }
        return true;
    }

    /**
     * The same registry loop classifies Staging broker targets separately from
     * direct-only, independently governed host/runtime gate controls. A direct
     * tool does not automatically qualify for generic enrollment dispatch.
     */
    private static function scan( $mode ) {
        if ( ! function_exists( 'wp_get_abilities' ) || ! function_exists( 'wp_get_ability' )
            || ! class_exists( 'MAD4B_SCP_Site_Profile' )
            || ! MAD4B_SCP_Site_Profile::configured()
            || ! MAD4B_SCP_Site_Profile::origin_enrolled()
            || ! MAD4B_SCP_Site_Profile::site_urls_match_enrollment() ) return array();
        $environment = (string) MAD4B_SCP_Site_Profile::current_environment();
        if ( 'staging' === $mode && 'staging' !== $environment ) return array();
        if ( 'direct_only' === $mode && ! in_array( $environment, array( 'staging', 'production' ), true ) ) return array();
        $registry = wp_get_abilities();
        if ( ! is_array( $registry ) || count( $registry ) > self::MAX_ABILITIES ) return array();
        $result = array();
        foreach ( $registry as $key => $ability ) {
            if ( ! is_object( $ability ) || ! method_exists( $ability, 'get_meta' ) ) continue;
            $name = method_exists( $ability, 'get_name' )
                ? (string) $ability->get_name() : ( is_string( $key ) ? $key : '' );
            if ( ! preg_match( '#^mad4b/[a-z][a-z0-9-]{2,159}$#D', $name )
                || wp_get_ability( $name ) !== $ability ) continue;
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
            if ( 'staging' === $mode ) {
                if ( false !== ( $mcp['production_mutation_allowed'] ?? null )
                    || true === ( $mcp['production_allowed'] ?? false )
                    || true === ( $mcp['direct_only_excluded_from_staging_broker'] ?? false ) ) continue;
            } else {
                if ( true !== ( $mcp['direct_only_excluded_from_staging_broker'] ?? null )
                    || true !== ( $mcp['production_allowed'] ?? null )
                    || true !== ( $mcp['generic_raw_sql_breakglass_configuration'] ?? null ) ) continue;
            }
            if ( ! self::trusted_callback_pair( $ability ) ) continue;
            $result[ $name ] = array(
                'ability_name' => $name,
                'operation_id' => 'core_step_up_' . substr( str_replace( '-', '_', substr( str_replace( '/', '_', $name ), 0, 48 ) ), 0, 48 )
                    . '_' . substr( hash( 'sha256', $name ), 0, 12 ),
                'owner_intent_only' => true === ( $mcp['owner_intent_only'] ?? null ),
                'source' => 'verified_first_party_php_callbacks',
                'authority_effect' => 'none',
                'execution_lane' => 'staging' === $mode ? 'staging_enrollment' : 'direct_only',
            );
        }
        ksort( $result, SORT_STRING );
        return $result;
    }

    public static function eligible() { return self::scan( 'staging' ); }
    public static function direct_only() { return self::scan( 'direct_only' ); }

    public static function operation_id_for( $name ) {
        $row = self::eligible()[ (string) $name ] ?? null;
        return is_array( $row ) ? (string) $row['operation_id'] : '';
    }
}
