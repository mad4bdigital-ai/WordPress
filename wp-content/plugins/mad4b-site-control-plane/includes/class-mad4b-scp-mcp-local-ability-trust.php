<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Independently reviewed native WordPress Ability fallback without a PHP
 * Adapter subclass. Uses the existing owner-pinned MCP peer manifest, with
 * local_abilities as a separate non-authorizing trust universe.
 */
final class MAD4B_SCP_MCP_Local_Ability_Trust {
    private static $bindings = null;

    private static function safe_path( $path ) {
        return is_string( $path ) && strlen( $path ) <= 191
            && (bool) preg_match( '~^[A-Za-z0-9_.-]+(?:/[A-Za-z0-9_.-]+)+$~D', $path )
            && false === strpos( $path, '..' );
    }
    private static function schema_digest( $schema ) {
        if ( ! is_array( $schema ) ) return '';
        $sort = static function ( $value ) use ( &$sort ) {
            if ( ! is_array( $value ) ) return $value;
            if ( $value && array_keys( $value ) !== range( 0, count( $value ) - 1 ) )
                ksort( $value, SORT_STRING );
            foreach ( $value as $key => $child ) $value[$key] = $sort( $child );
            return $value;
        };
        $json = wp_json_encode( $sort( $schema ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
        return is_string( $json ) ? hash( 'sha256', $json ) : '';
    }
    public static function bindings() {
        if ( null !== self::$bindings ) return self::$bindings;
        self::$bindings = array();
        if ( ! defined( 'MAD4B_SCP_MCP_PEER_MANIFEST_PATH' )
            || ! defined( 'MAD4B_SCP_MCP_PEER_MANIFEST_SHA256' ) ) return self::$bindings;
        $file = MAD4B_SCP_MCP_PEER_MANIFEST_PATH;
        $pin = strtolower( (string) MAD4B_SCP_MCP_PEER_MANIFEST_SHA256 );
        if ( ! is_string( $file ) || ! preg_match( '/^[a-f0-9]{64}$/D', $pin ) ) return self::$bindings;
        $real = realpath( $file );
        if ( false === $real || ! is_file( $real ) || ! is_readable( $real ) || filesize( $real ) > 65536 )
            return self::$bindings;
        foreach ( array( 'WP_CONTENT_DIR', 'WP_PLUGIN_DIR', 'WPMU_PLUGIN_DIR' ) as $constant ) {
            if ( ! defined( $constant ) ) continue;
            $root = realpath( constant( $constant ) );
            if ( false !== $root && 0 === strpos( $real, $root . DIRECTORY_SEPARATOR ) )
                return self::$bindings;
        }
        $digest = hash_file( 'sha256', $real );
        if ( ! is_string( $digest ) || ! hash_equals( $pin, $digest ) ) return self::$bindings;
        $json = json_decode( (string) file_get_contents( $real ), true );
        if ( ! is_array( $json ) || ( $json['contract'] ?? '' ) !== MAD4B_SCP_MCP_Hybrid::CONTRACT
            || ! is_array( $json['peers'] ?? null ) || ! is_array( $json['local_abilities'] ?? null )
            || count( $json['local_abilities'] ) > 128 ) return self::$bindings;
        $rows = array();
        foreach ( $json['local_abilities'] as $row ) {
            $name = $row['ability'] ?? '';
            if ( ! is_array( $row ) || ! is_string( $name )
                || ! preg_match( '~^[a-z0-9_-]{1,64}/[a-z0-9._/-]{1,191}$~D', $name )
                || isset( $rows[$name] ) || ! self::safe_path( $row['plugin_basename'] ?? null )
                || ! self::safe_path( $row['callback_source_relpath'] ?? null )
                || 0 !== strpos( $row['callback_source_relpath'], dirname( $row['plugin_basename'] ) . '/' )
                || ! is_string( $row['callback_source_sha256'] ?? null )
                || ! preg_match( '/^[a-f0-9]{64}$/D', $row['callback_source_sha256'] )
                || ! is_string( $row['php_source_tree_sha256'] ?? null )
                || ! preg_match( '/^[a-f0-9]{64}$/D', $row['php_source_tree_sha256'] )
                || ! is_string( $row['input_schema_sha256'] ?? null )
                || ! preg_match( '/^[a-f0-9]{64}$/D', $row['input_schema_sha256'] )
                || ! is_string( $row['output_schema_sha256'] ?? null )
                || ! preg_match( '/^[a-f0-9]{64}$/D', $row['output_schema_sha256'] )
                || true !== ( $row['read_execution_reviewed'] ?? null ) ) return self::$bindings;
            $rows[$name] = $row;
        }
        ksort( $rows, SORT_STRING );
        self::$bindings = $rows;
        return self::$bindings;
    }
    public static function verified_read( $ability, $name ) {
        if ( ! is_object( $ability ) || ! is_string( $name )
            || ! method_exists( $ability, 'get_name' )
            || ! method_exists( $ability, 'get_meta' )
            || ! method_exists( $ability, 'get_input_schema' )
            || ! method_exists( $ability, 'get_output_schema' )
            || ! method_exists( $ability, 'execute' )
            || $ability->get_name() !== $name || ! defined( 'WP_PLUGIN_DIR' )
            || ! function_exists( 'get_option' ) ) return false;
        $row = self::bindings()[$name] ?? null;
        if ( ! is_array( $row ) ) return false;
        $meta = $ability->get_meta();
        $annotations = is_array( $meta ) ? ( $meta['annotations'] ?? array() ) : null;
        if ( ! is_array( $annotations ) || true !== ( $annotations['readonly'] ?? null )
            || true === ( $annotations['destructive'] ?? null ) ) return false;
        if ( ! hash_equals( $row['input_schema_sha256'], self::schema_digest( $ability->get_input_schema() ) )
            || ! hash_equals( $row['output_schema_sha256'], self::schema_digest( $ability->get_output_schema() ) ) )
            return false;
        $active = get_option( 'active_plugins', array() );
        $network = function_exists( 'is_multisite' ) && is_multisite()
            && function_exists( 'get_site_option' )
            ? get_site_option( 'active_sitewide_plugins', array() ) : array();
        if ( ! in_array( $row['plugin_basename'], is_array( $active ) ? $active : array(), true )
            && ! array_key_exists( $row['plugin_basename'], is_array( $network ) ? $network : array() ) )
            return false;
        $root = realpath( WP_PLUGIN_DIR );
        if ( false === $root ) return false;
        $plugin_root = realpath( $root . DIRECTORY_SEPARATOR . dirname( $row['plugin_basename'] ) );
        $callback_source = realpath( $root . DIRECTORY_SEPARATOR . $row['callback_source_relpath'] );
        if ( false === $plugin_root || false === $callback_source
            || 0 !== strpos( $plugin_root, $root . DIRECTORY_SEPARATOR )
            || 0 !== strpos( $callback_source, $plugin_root . DIRECTORY_SEPARATOR ) ) return false;
        if ( ! class_exists( 'MAD4B_SCP_External_Adapter_Trust', false ) ) return false;
        $tree = MAD4B_SCP_External_Adapter_Trust::php_source_tree_sha256( $plugin_root );
        $sha = hash_file( 'sha256', $callback_source );
        if ( ! is_string( $sha ) || '' === $tree
            || ! hash_equals( $row['callback_source_sha256'], $sha )
            || ! hash_equals( $row['php_source_tree_sha256'], $tree ) ) return false;
        try {
            $ref = new ReflectionProperty( $ability, 'execute_callback' );
            $ref->setAccessible( true );
            $callback = $ref->getValue( $ability );
            $source = MAD4B_SCP_MCP_Hybrid_Authority::callback_file( $callback );
            return is_string( $source ) && realpath( $source ) === $callback_source;
        } catch ( Throwable $error ) { return false; }
    }
}
