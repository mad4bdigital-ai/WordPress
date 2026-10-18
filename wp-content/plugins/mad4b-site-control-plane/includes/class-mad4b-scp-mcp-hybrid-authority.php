<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Per-tool authorization and verified local WP_Ability callback provenance.
 * Reuses Agent Registry and the pre-existing exact (server, ability, provider)
 * grant ledger. Does not create a grant or relax provider isolation.
 */
final class MAD4B_SCP_MCP_Hybrid_Authority {
    public static function authorize_read( $peer_id, $ability, $source = 'native' ) {
        if ( ! in_array( $source, array( 'native', 'local' ), true )
            || ! is_string( $ability ) || ! preg_match( '~^[a-z0-9_-]{1,64}/[a-z0-9._/-]{1,191}$~D', $ability )
            || ! class_exists( 'MAD4B_SCP_Policy' ) || ! MAD4B_SCP_Policy::can_read() )
            return new WP_Error( 'mad4b_hybrid_read_permission_denied', 'WordPress read permission is unavailable.' );
        if ( ! class_exists( 'MAD4B_SCP_Identity_Context' )
            || ! class_exists( 'MAD4B_SCP_Agent_Registry' ) )
            return new WP_Error( 'mad4b_hybrid_agent_unavailable', 'Exact Agent authority is unavailable.' );
        $identity = MAD4B_SCP_Identity_Context::current();
        if ( is_wp_error( $identity ) || ! is_array( $identity ) || empty( $identity['authenticated'] ) )
            return new WP_Error( 'mad4b_hybrid_subject_required', 'An authenticated agent subject is required.' );
        $agent = MAD4B_SCP_Agent_Registry::resolve_agent( $identity );
        if ( is_wp_error( $agent ) || ! is_array( $agent ) || empty( $agent['id'] ) )
            return new WP_Error( 'mad4b_hybrid_agent_unbound', 'The authenticated subject has no eligible agent binding.' );
        if ( 'native' === $source ) {
            if ( ! is_string( $peer_id ) || ! preg_match( '/^[a-z][a-z0-9_-]{0,51}$/D', $peer_id ) )
                return new WP_Error( 'mad4b_hybrid_peer_invalid', 'Peer identity is not eligible for exact provider grants.' );
            $provider = 'native_mcp_' . $peer_id;
        } else $provider = 'hybrid_local';
        // No wildcard or server fallback: the owner must explicitly grant
        // this exact semantic capability on the MAD4B read surface.
        $grant = MAD4B_SCP_Agent_Registry::exact_grant(
            (int) $agent['id'], 'mad4b-read', $ability, $provider
        );
        if ( is_wp_error( $grant ) || ! is_array( $grant ) || ( $grant['effect'] ?? '' ) !== 'allow' )
            return new WP_Error( 'mad4b_hybrid_exact_grant_required', 'Exact owner-managed per-tool Agent grant is required.' );
        return true;
    }

    private static function callback_file( $callback ) {
        try {
            if ( $callback instanceof Closure ) return ( new ReflectionFunction( $callback ) )->getFileName();
            if ( is_array( $callback ) && count( $callback ) === 2 && is_callable( $callback ) )
                return ( new ReflectionMethod( $callback[0], $callback[1] ) )->getFileName();
            if ( is_string( $callback ) && function_exists( $callback ) )
                return ( new ReflectionFunction( $callback ) )->getFileName();
            if ( is_object( $callback ) && is_callable( $callback ) )
                return ( new ReflectionMethod( $callback, '__invoke' ) )->getFileName();
        } catch ( Throwable $error ) { return false; }
        return false;
    }

    /**
     * The effective execution callback, not just an untrusted ability name
     * or its provider-declared metadata, must originate in the exact reviewed
     * Adapter source. If WP does not expose the callback under its expected
     * protected API contract, the hybrid executor fails closed.
     */
    public static function verified_local_read( $ability, $adapter, $ability_name ) {
        if ( ! is_object( $ability ) || ! is_object( $adapter )
            || ! $adapter instanceof MAD4B_SCP_Adapter_Base
            || ! method_exists( $ability, 'execute' ) || ! method_exists( $ability, 'get_meta' )
            || ! method_exists( $ability, 'get_name' )
            || $ability->get_name() !== $ability_name ) return false;
        $meta = $ability->get_meta();
        $annotations = is_array( $meta ) ? ( $meta['annotations'] ?? array() ) : array();
        if ( ! is_array( $annotations ) || true !== ( $annotations['readonly'] ?? null )
            || true === ( $annotations['destructive'] ?? null ) ) return false;
        try {
            $adapter_ref = new ReflectionClass( $adapter );
            $adapter_file = realpath( (string) $adapter_ref->getFileName() );
            $property = new ReflectionProperty( $ability, 'execute_callback' );
            $property->setAccessible( true );
            $callback = $property->getValue( $ability );
            $callback_file = self::callback_file( $callback );
            $actual_file = is_string( $callback_file ) ? realpath( $callback_file ) : false;
            if ( false === $adapter_file || false === $actual_file
                || ! hash_equals( $adapter_file, $actual_file ) ) return false;
            $declared = $adapter->ability_names();
            return is_array( $declared ) && isset( $declared['read'] )
                && is_array( $declared['read'] ) && in_array( $ability_name, $declared['read'], true );
        } catch ( Throwable $error ) { return false; }
    }

    public static function matching_adapter( $ability, $ability_name ) {
        if ( ! class_exists( 'MAD4B_SCP_Adapter_Registry', false ) ) return null;
        $matches = array();
        foreach ( MAD4B_SCP_Adapter_Registry::instance()->all() as $adapter ) {
            if ( self::verified_local_read( $ability, $adapter, $ability_name ) )
                $matches[] = $adapter;
            if ( count( $matches ) > 1 ) return null;
        }
        return 1 === count( $matches ) ? $matches[0] : null;
    }
}
