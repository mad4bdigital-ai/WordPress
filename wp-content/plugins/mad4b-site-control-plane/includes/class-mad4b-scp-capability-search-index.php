<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Read-only, identity-scoped metadata cache for the existing Unified Capability
 * Gateway. This indexes WordPress Abilities; it is not a second authorization
 * registry, provider certification source, or execution router.
 *
 * A cheap sorted-name fingerprint is rechecked on every request. Full Ability
 * metadata and query ranking are cached only within a short lease. Fresh
 * descriptor / execution admission is still mandatory before any execution.
 */
final class MAD4B_SCP_Capability_Search_Index {
    const CONTRACT = 'mad4b.capability-search-index.v1';
    const TTL = 60;
    const MAX_STORED_BYTES = 1048576;
    private static $request_cache = array();

    public static function reset_request_cache() { self::$request_cache = array(); }

    private static function key( $kind, $scope, $digest ) {
        return 'mad4b_ci_' . substr( hash( 'sha256', self::CONTRACT . ':' . $kind . ':' . $scope . ':' . $digest ), 0, 38 );
    }

    private static function get( $key, $scope, $identity ) {
        if ( array_key_exists( $key, self::$request_cache ) ) $record = self::$request_cache[$key];
        else {
            $record = function_exists( 'get_transient' ) ? get_transient( $key ) : false;
            if ( is_array( $record ) ) self::$request_cache[$key] = $record;
        }
        if ( ! is_array( $record ) || self::CONTRACT !== ( $record['contract'] ?? '' )
            || ! hash_equals( $scope, (string) ( $record['scope'] ?? '' ) )
            || ! hash_equals( $identity, (string) ( $record['identity'] ?? '' ) )
            || ! isset( $record['payload'] ) || ! is_array( $record['payload'] ) ) return null;
        return $record['payload'];
    }

    private static function put( $key, $scope, $identity, array $payload ) {
        $record = array( 'contract' => self::CONTRACT, 'scope' => $scope,
            'identity' => $identity, 'payload' => $payload );
        $json = function_exists( 'wp_json_encode' ) ? wp_json_encode( $record ) : false;
        if ( ! is_string( $json ) || strlen( $json ) > self::MAX_STORED_BYTES ) return false;
        self::$request_cache[$key] = $record;
        if ( function_exists( 'set_transient' ) ) return (bool) set_transient( $key, $record, self::TTL );
        return false;
    }

    private static function bounded( $text, $bytes ) {
        $text = (string) $text;
        if ( function_exists( 'wp_strip_all_tags' ) ) $text = wp_strip_all_tags( $text );
        if ( strlen( $text ) <= $bytes ) return $text;
        if ( function_exists( 'mb_strcut' ) ) return (string) mb_strcut( $text, 0, $bytes, 'UTF-8' );
        return function_exists( 'wp_check_invalid_utf8' )
            ? (string) wp_check_invalid_utf8( substr( $text, 0, $bytes ), true ) : '';
    }

    public static function catalog( array $names, $scope ) {
        if ( ! is_string( $scope ) || ! preg_match( '/^[a-f0-9]{64}$/D', $scope ) )
            return new WP_Error( 'mad4b_capability_index_scope_invalid', 'Canonical catalog scope is required.' );
        $names = array_values( array_unique( array_map( 'strval', $names ) ) );
        sort( $names, SORT_STRING );
        $membership = hash( 'sha256', wp_json_encode( $names ) );
        $key = self::key( 'catalog', $scope, $membership );
        $cached = self::get( $key, $scope, $membership );
        if ( null !== $cached && isset( $cached['rows'], $cached['snapshot_sha256'] )
            && is_array( $cached['rows'] ) && count( $cached['rows'] ) + (int) ( $cached['skipped_count'] ?? -1 ) === count( $names ) ) {
            $cached['cache_hit'] = true;
            return $cached;
        }
        $rows = array(); $skipped = 0;
        foreach ( $names as $name ) {
            try {
                if ( ! function_exists( 'wp_has_ability' ) || ! function_exists( 'wp_get_ability' )
                    || ! wp_has_ability( $name ) ) { ++$skipped; continue; }
                $ability = wp_get_ability( $name );
                if ( ! is_object( $ability ) ) { ++$skipped; continue; }
                $meta = method_exists( $ability, 'get_meta' ) ? $ability->get_meta() : array();
                $meta = is_array( $meta ) ? $meta : array();
                $mcp = isset( $meta['mcp'] ) && is_array( $meta['mcp'] ) ? $meta['mcp'] : array();
                $annotations = isset( $meta['annotations'] ) && is_array( $meta['annotations'] ) ? $meta['annotations'] : array();
                $aliases = isset( $mcp['search_aliases'] ) && is_array( $mcp['search_aliases'] ) ? array_slice( $mcp['search_aliases'], 0, 24 ) : array();
                $aliases = array_values( array_map( static function ( $value ) {
                    return MAD4B_SCP_Capability_Search_Index::bounded( $value, 80 );
                }, array_filter( $aliases, 'is_string' ) ) );
                $rows[] = array(
                    'ability_name' => $name,
                    'search_aliases' => $aliases,
                    'label' => method_exists( $ability, 'get_label' ) ? self::bounded( $ability->get_label(), 160 ) : '',
                    'description' => method_exists( $ability, 'get_description' ) ? self::bounded( $ability->get_description(), 320 ) : '',
                    'category' => method_exists( $ability, 'get_category' ) ? self::bounded( $ability->get_category(), 160 ) : '',
                    'declared_surface' => isset( $mcp['surface'] ) ? sanitize_key( (string) $mcp['surface'] ) : '',
                    'declared_readonly' => array_key_exists( 'readonly', $annotations ) && is_bool( $annotations['readonly'] ) ? $annotations['readonly'] : null,
                );
            } catch ( Throwable $error ) { ++$skipped; }
        }
        $payload = array(
            'rows' => $rows, 'scanned_count' => count( $names ), 'skipped_count' => $skipped,
            'coverage_state' => $skipped ? 'PARTIAL' : 'COMPLETE',
            'snapshot_sha256' => hash( 'sha256', wp_json_encode( array( $membership, $rows, $skipped ) ) ),
            'cache_hit' => false, 'cache_persisted' => false,
        );
        $payload['cache_persisted'] = self::put( $key, $scope, $membership, $payload );
        return $payload;
    }

    /** Cache the already-filtered, deterministic result set to avoid re-ranking every page. */
    public static function results( array $catalog, $scope, $query_digest, $producer ) {
        if ( ! is_callable( $producer ) || ! preg_match( '/^[a-f0-9]{64}$/D', (string) $query_digest ) )
            return new WP_Error( 'mad4b_capability_index_query_invalid', 'Invalid indexed search input.' );
        $identity = hash( 'sha256', $catalog['snapshot_sha256'] . ':' . $query_digest );
        $key = self::key( 'results', $scope, $identity );
        $cached = self::get( $key, $scope, $identity );
        if ( null !== $cached && isset( $cached['matches'], $cached['digest'] ) && is_array( $cached['matches'] ) ) {
            $cached['cache_hit'] = true;
            return $cached;
        }
        $matches = call_user_func( $producer, $catalog['rows'] );
        if ( ! is_array( $matches ) ) return new WP_Error( 'mad4b_capability_index_results_invalid', 'Ranker did not return an array.' );
        $payload = array( 'matches' => $matches,
            'digest' => hash( 'sha256', wp_json_encode( $matches ) ),
            'cache_hit' => false, 'cache_persisted' => false );
        $payload['cache_persisted'] = self::put( $key, $scope, $identity, $payload );
        return $payload;
    }
}
