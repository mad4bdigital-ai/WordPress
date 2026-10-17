<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * CSO adapter discovery, NEVER a universal wp_options/SQL writer.
 * The explicit, deployment-owned allowlist must be pinned to a native
 * provider implementation and WordPress-registered read/write Abilities.
 * Other plugin hooks cannot silently claim mutation authority.
 */
interface MAD4B_SCP_CSO_Storage_Provider {
    public function provider_key();
    public function describe( $target, $scope );
    public function read( $target, $scope );
}

final class MAD4B_SCP_CSO_Storage_Adapters {
    const CONTRACT = 'mad4b.cso.storage-adapters.v1';
    const MAX_ADAPTERS = 16;

    /**
     * Read an exact native provider snapshot. Returns only non-secret values;
     * adapter-originated approval claims are discarded.
     */
    public static function snapshot( $provider_id, $target = array() ) {
        $discovery = self::discover( $provider_id, $target );
        if ( is_wp_error( $discovery ) ) return $discovery;
        $descriptor = $discovery['descriptor'];
        $ability = wp_get_ability( $descriptor['native_read_ability'] );
        if ( ! is_object( $ability ) || ! method_exists( $ability, 'check_permissions' ) ||
            true !== $ability->check_permissions( $target ) )
            return MAD4B_SCP_CSO_Scope::error( 'ADAPTER_READ_PERMISSION_DENIED' );
        $scope = MAD4B_SCP_CSO_Scope::current();
        if ( is_wp_error( $scope ) ) return $scope;
        $class = MAD4B_CSO_STORAGE_ADAPTER_CLASSES[ $provider_id ];
        try {
            $adapter = new $class();
            $snapshot = $adapter->read( $target, $scope );
        } catch ( \Throwable $e ) {
            return MAD4B_SCP_CSO_Scope::error( 'ADAPTER_READ_FAILED' );
        }
        if ( ! is_array( $snapshot ) || array_diff( array_keys( $snapshot ),
            array( 'revision', 'values', 'observed_at' ) ) ||
            ! is_array( $snapshot['values'] ?? null ) ||
            count( $snapshot['values'] ) > 32 ||
            true !== MAD4B_SCP_CSO_Scope::safe_data( $snapshot['values'] ) ||
            ! is_string( $snapshot['revision'] ?? null ) ||
            ! hash_equals( (string) $descriptor['revision'], $snapshot['revision'] ) ||
            ! is_int( $snapshot['observed_at'] ?? null ) ||
            $snapshot['observed_at'] > time() ||
            $snapshot['observed_at'] < time() - 300 )
            return MAD4B_SCP_CSO_Scope::error( 'ADAPTER_READBACK_INVALID' );
        if ( true !== MAD4B_SCP_CSO_Scope::assert_current( $scope ) )
            return MAD4B_SCP_CSO_Scope::error( 'ADAPTER_SCOPE_CHANGED' );
        return array( 'contract' => self::CONTRACT . '.snapshot.v1',
            'descriptor' => $descriptor, 'revision' => $snapshot['revision'],
            'values' => $snapshot['values'], 'observed_at' => $snapshot['observed_at'],
            'mutation_performed' => false, 'authorizing' => false );
    }

    public static function discover( $provider_id, $target = array() ) {
        if ( ! MAD4B_SCP_CSO_Scope::enabled( 'forms' ) ||
            ! is_string( $provider_id ) ||
            ! preg_match( '/^[a-z][a-z0-9_-]{1,63}$/D', $provider_id ) ||
            ! is_array( $target ) || count( $target ) > 16 ||
            true !== MAD4B_SCP_CSO_Scope::safe_data( $target ) )
            return MAD4B_SCP_CSO_Scope::error( 'ADAPTER_INPUT_INVALID' );
        $scope = MAD4B_SCP_CSO_Scope::current();
        if ( is_wp_error( $scope ) ) return $scope;
        if ( ! defined( 'MAD4B_CSO_STORAGE_ADAPTER_CLASSES' ) ||
            ! is_array( MAD4B_CSO_STORAGE_ADAPTER_CLASSES ) ||
            count( MAD4B_CSO_STORAGE_ADAPTER_CLASSES ) > self::MAX_ADAPTERS )
            return MAD4B_SCP_CSO_Scope::error( 'ADAPTER_NOT_ENROLLED' );
        $registered = MAD4B_CSO_STORAGE_ADAPTER_CLASSES;
        if ( ! array_key_exists( $provider_id, $registered ) ||
            ! is_string( $registered[ $provider_id ] ) ||
            ! class_exists( $registered[ $provider_id ], false ) )
            return MAD4B_SCP_CSO_Scope::error( 'ADAPTER_NOT_ENROLLED' );
        try { $adapter = new $registered[ $provider_id ](); }
        catch ( \Throwable $e ) { return MAD4B_SCP_CSO_Scope::error( 'ADAPTER_LOAD_FAILED' ); }
        if ( ! $adapter instanceof MAD4B_SCP_CSO_Storage_Provider ||
            ! hash_equals( $provider_id, (string) $adapter->provider_key() ) )
            return MAD4B_SCP_CSO_Scope::error( 'ADAPTER_IDENTITY_MISMATCH' );
        try { $descriptor = $adapter->describe( $target, $scope ); }
        catch ( \Throwable $e ) { return MAD4B_SCP_CSO_Scope::error( 'ADAPTER_DESCRIPTOR_FAILED' ); }
        if ( ! is_array( $descriptor ) || array_diff( array_keys( $descriptor ),
            array( 'provider_id', 'target', 'fields', 'native_read_ability',
                'native_write_ability', 'descriptor_sha256', 'revision' ) ) ||
            $provider_id !== ( $descriptor['provider_id'] ?? '' ) ||
            ! is_array( $descriptor['fields'] ?? null ) ||
            count( $descriptor['fields'] ) > 32 ||
            ! is_array( $descriptor['target'] ?? null ) ||
            ! hash_equals( MAD4B_SCP_CSO_Scope::digest( $target ),
                MAD4B_SCP_CSO_Scope::digest( $descriptor['target'] ) ) ||
            ! is_string( $descriptor['revision'] ?? null ) ||
            strlen( $descriptor['revision'] ) < 8 ||
            strlen( $descriptor['revision'] ) > 128 ||
            ! is_string( $descriptor['descriptor_sha256'] ?? null ) ||
            ! preg_match( '/^[a-f0-9]{64}$/D', $descriptor['descriptor_sha256'] ) )
            return MAD4B_SCP_CSO_Scope::error( 'ADAPTER_CONTRACT_INVALID' );
        // A registry entry alone is NOT proof of a certified write driver.
        foreach ( array( 'native_read_ability', 'native_write_ability' ) as $field ) {
            $ability = $descriptor[ $field ] ?? '';
            if ( ! is_string( $ability ) ||
                ! preg_match( '~^[a-z0-9._-]{1,96}/[a-z0-9._-]{1,96}$~D', $ability ) ||
                ! function_exists( 'wp_get_ability' ) ||
                ! is_object( wp_get_ability( $ability ) ) )
                return MAD4B_SCP_CSO_Scope::error( 'NATIVE_ADAPTER_ABILITY_UNVERIFIED' );
        }
        // Read and write certification must be separately checked by native
        // authorization at the point of effect; discovery never grants either.
        if ( true !== MAD4B_SCP_CSO_Scope::assert_current( $scope ) )
            return MAD4B_SCP_CSO_Scope::error( 'ADAPTER_SCOPE_CHANGED' );
        return array( 'contract' => self::CONTRACT, 'provider_id' => $provider_id,
            'descriptor' => $descriptor, 'scope_fingerprint' => $scope['binding_sha256'],
            'read_only' => true, 'write_authorized' => false,
            'mutation_performed' => false, 'native_execution_required' => true );
    }
}
