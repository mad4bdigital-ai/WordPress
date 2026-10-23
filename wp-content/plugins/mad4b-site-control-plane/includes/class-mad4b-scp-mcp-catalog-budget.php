<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Request-local, site-scoped MCP display budget. It never changes the Ability
 * registry, grants, permissions, execution, or the stable dispatch routes.
 * Only reviewed optional direct tools may be trimmed.
 */
final class MAD4B_SCP_MCP_Catalog_Budget {
    const CONTRACT = 'mad4b.mcp-catalog-budget.v1';
    const LEGACY_TOOL_LIMIT = 36;
    const LEGACY_BYTE_LIMIT = 98304;
    const HARD_TOOL_LIMIT = 64;
    const HARD_BYTE_LIMIT = 196608;
    const TELEMETRY_TTL = 7200;
    const TELEMETRY_KEY = 'mad4b_scp_mcp_catalog_telemetry_v1';

    private static function bounded_config( $name, $default, $minimum, $maximum ) {
        if ( ! defined( $name ) ) return $default;
        $value = constant( $name );
        if ( is_string( $value ) && ctype_digit( $value ) ) $value = (int) $value;
        // Invalid or out-of-bounds settings must never increase surface area.
        if ( ! is_int( $value ) || $value < $minimum || $value > $maximum )
            return $default;
        return $value;
    }

    private static function site_binding() {
        // Multisite transients are blog-local; this hash also invalidates
        // observations when site enrollment/origin changes.
        $blog_id = function_exists( 'get_current_blog_id' ) ? (int) get_current_blog_id() : 0;
        $origin = function_exists( 'home_url' ) ? (string) home_url( '/' ) : '';
        $environment = function_exists( 'wp_get_environment_type' )
            ? (string) wp_get_environment_type() : '';
        return hash( 'sha256', $blog_id . '|' . $origin . '|' . $environment );
    }

    public static function telemetry() {
        $data = function_exists( 'get_transient' ) ? get_transient( self::TELEMETRY_KEY ) : false;
        if ( ! is_array( $data ) || ( $data['contract'] ?? '' ) !== self::CONTRACT ||
             ( $data['site_binding'] ?? '' ) !== self::site_binding() ||
             ! is_int( $data['updated_at'] ?? null ) ||
             $data['updated_at'] < time() - self::TELEMETRY_TTL ||
             (int) ( $data['samples'] ?? 0 ) < 1 ||
             ! is_numeric( $data['ewma_ms'] ?? null ) ) return array();
        return $data;
    }

    public static function limits( $required_count = 0 ) {
        $configured_tools = self::bounded_config( 'MAD4B_MCP_CATALOG_MAX_TOOLS',
            self::LEGACY_TOOL_LIMIT, 8, self::HARD_TOOL_LIMIT );
        $configured_bytes = self::bounded_config( 'MAD4B_MCP_CATALOG_MAX_BYTES',
            self::LEGACY_BYTE_LIMIT, 32768, self::HARD_BYTE_LIMIT );
        $target_ms = self::bounded_config( 'MAD4B_MCP_CATALOG_TARGET_MS', 1200, 300, 5000 );
        $telemetry = self::telemetry();
        $effective_tools = $configured_tools;
        $effective_bytes = $configured_bytes;
        $state = 'unmeasured';
        if ( (int) ( $telemetry['samples'] ?? 0 ) >= 3 ) {
            $latency = (float) $telemetry['ewma_ms'];
            $state = $latency > $target_ms ? 'slow' : 'healthy';
            if ( $latency > $target_ms ) {
                // Conservative hysteresis: do not erase transport tools, and
                // do not change grants, visibility filters or the registry.
                $effective_tools = max( 8, (int) floor( $configured_tools * 0.75 ) );
                $effective_bytes = max( 32768, (int) floor( $configured_bytes * 0.75 ) );
            }
        }
        return array(
            'contract' => self::CONTRACT,
            'max_tools' => min( self::HARD_TOOL_LIMIT, max( (int) $required_count, $effective_tools ) ),
            'max_bytes' => $effective_bytes,
            'configured_max_tools' => $configured_tools,
            'configured_max_bytes' => $configured_bytes,
            'target_ms' => $target_ms,
            'latency_state' => $state,
            'samples' => (int) ( $telemetry['samples'] ?? 0 ),
            'ewma_ms' => isset( $telemetry['ewma_ms'] ) ? (int) round( $telemetry['ewma_ms'] ) : null,
            'authorizing' => false,
        );
    }

    /** Sample only authenticated, successful tools/list; at most once / 30s. */
    public static function observe( $elapsed_ms, $tool_count ) {
        if ( ! function_exists( 'set_transient' ) || ! is_numeric( $elapsed_ms ) ||
             ! is_int( $tool_count ) || $tool_count < 1 ||
             $elapsed_ms < 0 || $elapsed_ms > 30000 ) return false;
        $prior = self::telemetry();
        $now = time();
        if ( (int) ( $prior['updated_at'] ?? 0 ) + 30 > $now ) return false;
        $sample = max( 1.0, (float) $elapsed_ms );
        $old_samples = (int) ( $prior['samples'] ?? 0 );
        $ewma = $old_samples ? ( 0.6 * (float) $prior['ewma_ms'] + 0.4 * $sample ) : $sample;
        return (bool) set_transient( self::TELEMETRY_KEY, array(
            'contract' => self::CONTRACT,
            'site_binding' => self::site_binding(),
            'updated_at' => $now,
            'samples' => min( 10, $old_samples + 1 ),
            'ewma_ms' => round( $ewma, 2 ),
            'tool_count' => $tool_count,
        ), self::TELEMETRY_TTL );
    }
}
