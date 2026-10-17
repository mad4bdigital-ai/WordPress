<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Admission for owner-reviewed external PHP adapters without loading foreign code.
 *
 * The manifest is an independently deployed, SHA-pinned file. A plugin-owned
 * filter, option, REST request or MCP tool can NEVER approve itself.
 * Admission is not a WP capability, OAuth scope, write grant or certification.
 */
final class MAD4B_SCP_External_Adapter_Trust {
    const CONTRACT = 'mad4b.external-adapter-trust.v1';
    private static $verified_manifest = null;

    public static function manifest() {
        if ( null !== self::$verified_manifest ) return self::$verified_manifest;
        self::$verified_manifest = array();
        if ( ! defined( 'MAD4B_SCP_EXTERNAL_ADAPTER_MANIFEST_PATH' )
            || ! defined( 'MAD4B_SCP_EXTERNAL_ADAPTER_MANIFEST_SHA256' ) ) return self::$verified_manifest;
        $path = MAD4B_SCP_EXTERNAL_ADAPTER_MANIFEST_PATH;
        $expected = strtolower( (string) MAD4B_SCP_EXTERNAL_ADAPTER_MANIFEST_SHA256 );
        if ( ! is_string( $path ) || '' === $path || ! preg_match( '/^[a-f0-9]{64}$/D', $expected ) ) return self::$verified_manifest;
        $real = realpath( $path );
        if ( false === $real || ! is_file( $real ) || ! is_readable( $real ) ) return self::$verified_manifest;
        // A plugin must not self-approve by storing or replacing the manifest.
        foreach ( array( 'WP_PLUGIN_DIR', 'WPMU_PLUGIN_DIR', 'WP_CONTENT_DIR' ) as $constant ) {
            if ( ! defined( $constant ) ) continue;
            $root = realpath( constant( $constant ) );
            if ( false !== $root && 0 === strpos( $real, $root . DIRECTORY_SEPARATOR ) ) return self::$verified_manifest;
        }
        $actual = hash_file( 'sha256', $real );
        if ( ! is_string( $actual ) || ! hash_equals( $expected, $actual ) ) return self::$verified_manifest;
        $raw = file_get_contents( $real );
        if ( ! is_string( $raw ) || strlen( $raw ) > 65536 ) return self::$verified_manifest;
        $data = json_decode( $raw, true );
        if ( ! is_array( $data ) || ( $data['contract'] ?? '' ) !== self::CONTRACT
            || ! isset( $data['adapters'] ) || ! is_array( $data['adapters'] )
            || count( $data['adapters'] ) > 64 ) return self::$verified_manifest;
        $seen = array();
        foreach ( $data['adapters'] as $row ) {
            if ( ! is_array( $row ) || ! isset( $row['class'], $row['adapter_id'], $row['plugin_basename'], $row['source_relpath'], $row['source_sha256'], $row['abilities'], $row['reversible_contracts'] ) ) return self::$verified_manifest;
            $class = $row['class'];
            if ( ! is_string( $class ) || ! preg_match( '/^[A-Za-z_\\\\][A-Za-z0-9_\\\\]{0,191}$/D', $class ) || isset( $seen[$class] )
                || ! is_string( $row['adapter_id'] ) || ! preg_match( '/^[a-z][a-z0-9_-]{0,63}$/D', $row['adapter_id'] )
                || ! self::safe_relpath( $row['plugin_basename'] )
                || ! self::safe_relpath( $row['source_relpath'] )
                || ! is_string( $row['source_sha256'] ) || ! preg_match( '/^[a-f0-9]{64}$/D', $row['source_sha256'] )
                || ! is_array( $row['abilities'] ) || ! is_array( $row['reversible_contracts'] ) ) return self::$verified_manifest;
            $seen[$class] = $row;
        }
        self::$verified_manifest = $seen;
        return self::$verified_manifest;
    }

    private static function safe_relpath( $value ) {
        return is_string( $value ) && strlen( $value ) <= 191
            && preg_match( '~^[A-Za-z0-9_.-]+(?:/[A-Za-z0-9_.-]+)+$~D', $value )
            && false === strpos( $value, '..' )
            && false === strpos( $value, '/./' );
    }

    private static function sorted_contract( $value ) {
        if ( ! is_array( $value ) ) return null;
        $out = array();
        foreach ( $value as $key => $names ) {
            if ( ! is_string( $key ) || ! in_array( $key, array( 'read', 'write', 'content', 'admin' ), true )
                || ! is_array( $names ) ) return null;
            $clean = array();
            foreach ( $names as $name ) {
                if ( ! is_string( $name ) || ! preg_match( '~^[a-z0-9_-]{1,64}/[a-z0-9._/-]{1,191}$~D', $name ) ) return null;
                $clean[$name] = true;
            }
            $out[$key] = array_keys( $clean );
            sort( $out[$key], SORT_STRING );
        }
        ksort( $out, SORT_STRING );
        return $out;
    }

    public static function allows( $adapter ) {
        if ( ! $adapter instanceof MAD4B_SCP_Adapter_Base
            || ! defined( 'WP_PLUGIN_DIR' ) || ! function_exists( 'get_option' ) ) return false;
        $class = get_class( $adapter );
        $manifest = self::manifest();
        if ( ! isset( $manifest[$class] ) ) return false;
        $row = $manifest[$class];
        if ( (string) $adapter->id() !== $row['adapter_id'] ) return false;
        // The external class must have been loaded by its own activated plugin.
        if ( ! class_exists( $class, false ) ) return false;
        $root = realpath( WP_PLUGIN_DIR );
        if ( false === $root ) return false;
        $source = realpath( $root . DIRECTORY_SEPARATOR . $row['source_relpath'] );
        $reflection = new ReflectionClass( $class );
        $actual = $reflection->getFileName();
        if ( false === $source || false === $actual || $source !== realpath( $actual )
            || 0 !== strpos( $source, $root . DIRECTORY_SEPARATOR ) ) return false;
        $digest = hash_file( 'sha256', $source );
        if ( ! is_string( $digest ) || ! hash_equals( $row['source_sha256'], $digest ) ) return false;
        $active = get_option( 'active_plugins', array() );
        $active = is_array( $active ) ? $active : array();
        $network = function_exists( 'is_multisite' ) && is_multisite() && function_exists( 'get_site_option' )
            ? get_site_option( 'active_sitewide_plugins', array() ) : array();
        $network = is_array( $network ) ? $network : array();
        if ( ! in_array( $row['plugin_basename'], $active, true )
            && ! array_key_exists( $row['plugin_basename'], $network ) ) return false;
        try {
            if ( self::sorted_contract( $adapter->ability_names() ) !== self::sorted_contract( $row['abilities'] ) ) return false;
            $actual_rollback = $adapter->reversible_contracts();
            if ( ! is_array( $actual_rollback ) ) return false;
            $expected_rollback = $row['reversible_contracts'];
            ksort( $actual_rollback, SORT_STRING );
            ksort( $expected_rollback, SORT_STRING );
            if ( $actual_rollback !== $expected_rollback ) return false;
        } catch ( Throwable $error ) { return false; }
        return true;
    }

    public static function status() {
        $rows = self::manifest();
        return array(
            'contract' => self::CONTRACT,
            'configured' => defined( 'MAD4B_SCP_EXTERNAL_ADAPTER_MANIFEST_PATH' ) && defined( 'MAD4B_SCP_EXTERNAL_ADAPTER_MANIFEST_SHA256' ),
            'validated_source_pin_count' => count( $rows ),
            'state' => ! defined( 'MAD4B_SCP_EXTERNAL_ADAPTER_MANIFEST_SHA256' ) ? 'OWNER_PIN_NOT_CONFIGURED'
                : ( empty( $rows ) ? 'NO_VERIFIED_BINDINGS' : 'OWNER_PIN_VERIFIED' ),
            'unreviewed_class_auto_register' => false,
            'native_mcp_endpoint_authorized' => false,
            'write_authority_created' => false,
            'mutation_performed' => false,
        );
    }
}
