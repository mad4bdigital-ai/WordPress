<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * SEP-2243 custom routing headers from owner-reviewed MCP tool JSON Schema.
 * Reject invalid schema locations and duplicate headers; encode values with
 * the MCP sentinel to prevent control-character injection into HTTP.
 */
final class MAD4B_SCP_MCP_Header_Parameters {
    private static function scan( $node, $path, $allowed, &$definitions, &$names, &$count ) {
        if ( ! is_array( $node ) || ++$count > 8192 ) return false;
        if ( array_key_exists( 'x-mcp-header', $node ) ) {
            $name = $node['x-mcp-header'];
            $type = $node['type'] ?? '';
            if ( ! $allowed || ! $path || ! is_string( $name )
                || ! preg_match( '/^[!#$%&\x27*+.^_\x60|~0-9A-Za-z-]{1,64}$/D', $name )
                || ! in_array( $type, array( 'string', 'integer', 'boolean' ), true )
                || isset( $names[strtolower( $name )] ) || count( $definitions ) >= 32 ) return false;
            $names[strtolower( $name )] = true;
            $definitions[] = array( 'name' => $name, 'path' => $path, 'type' => $type );
        }
        foreach ( $node as $key => $value ) {
            if ( 'properties' === $key && is_array( $value ) && $allowed ) {
                foreach ( $value as $property => $schema ) {
                    if ( ! is_string( $property )
                        || ! self::scan( $schema, array_merge( $path, array( $property ) ), true,
                            $definitions, $names, $count ) ) return false;
                }
            } elseif ( is_array( $value ) ) {
                // Composition, arrays, conditionals and refs never allow a
                // statically reachable x-mcp-header annotation.
                if ( ! self::scan( $value, $path, false, $definitions, $names, $count ) ) return false;
            }
        }
        return true;
    }
    public static function definition( $schema ) {
        if ( ! is_array( $schema ) ) return new WP_Error( 'mad4b_mcp_header_schema_invalid', 'MCP tool input schema is not an object.' );
        $definitions = array(); $names = array(); $count = 0;
        if ( ! self::scan( $schema, array(), true, $definitions, $names, $count ) )
            return new WP_Error( 'mad4b_mcp_header_annotation_invalid', 'Tool contains invalid, duplicate, or unreachable x-mcp-header declarations.' );
        return $definitions;
    }
    private static function encode( $raw ) {
        if ( ! is_string( $raw ) || strlen( $raw ) > 8192 ) return null;
        if ( '' === $raw || $raw !== trim( $raw, " \t" )
            || ! preg_match( '/^[\x20-\x7e]*$/D', $raw )
            || ( 0 === strpos( $raw, '=?base64?' ) && '?=' === substr( $raw, -2 ) ) )
            return '=?base64?' . base64_encode( $raw ) . '?=';
        return $raw;
    }
    public static function from_schema( $schema, $arguments ) {
        $definitions = self::definition( $schema );
        if ( is_wp_error( $definitions ) ) return $definitions;
        if ( ! is_array( $arguments ) )
            return new WP_Error( 'mad4b_mcp_header_arguments_invalid', 'MCP arguments must be an object.' );
        $headers = array();
        foreach ( $definitions as $item ) {
            $value = $arguments;
            foreach ( $item['path'] as $segment ) {
                if ( ! is_array( $value ) || ! array_key_exists( $segment, $value ) ) continue 2;
                $value = $value[$segment];
            }
            if ( null === $value ) continue;
            if ( 'string' === $item['type'] && is_string( $value ) ) $encoded = $value;
            elseif ( 'integer' === $item['type'] && is_int( $value )
                && $value >= -9007199254740991 && $value <= 9007199254740991 )
                $encoded = (string) $value;
            elseif ( 'boolean' === $item['type'] && is_bool( $value ) )
                $encoded = $value ? 'true' : 'false';
            else return new WP_Error( 'mad4b_mcp_header_value_invalid', 'MCP header argument value does not match its primitive schema.' );
            $encoded = self::encode( $encoded );
            if ( null === $encoded ) return new WP_Error( 'mad4b_mcp_header_value_unbounded', 'MCP header value exceeded the bounded limit.' );
            $headers['Mcp-Param-' . $item['name']] = $encoded;
        }
        return $headers;
    }
}
