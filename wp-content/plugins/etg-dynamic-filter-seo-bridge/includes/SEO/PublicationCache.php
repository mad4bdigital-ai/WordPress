<?php
namespace ETG\DynamicFilterSEOBridge\SEO;

use ETG\DynamicFilterSEOBridge\Config\Configuration;

final class PublicationCache {
    public const EPOCH_OPTION = 'etg_dfsb_publication_cache_epoch';
    public const KEYS_OPTION = 'etg_dfsb_publication_cache_keys';
    public const KEY_PREFIX = 'etg_dfsb_pub_';
    public const MAX_TRACKED_KEYS = 500;

    private $config;
    private $hits = 0;
    private $misses = 0;
    private $writes = 0;

    public function __construct( Configuration $config ) { $this->config = $config; }

    public function get( string $identity ) {
        if ( ! function_exists( 'get_transient' ) ) { $this->misses++; return null; }
        $value = get_transient( $this->key( $identity ) );
        if ( ! is_array( $value ) || (string) ( $value['contract'] ?? '' ) !== 'etg.dfsb.publication-cache-entry.v1' || ! isset( $value['candidate'] ) || ! is_array( $value['candidate'] ) ) {
            $this->misses++;
            return null;
        }
        $this->hits++;
        return $value['candidate'];
    }

    public function set( string $identity, array $candidate ): void {
        if ( ! function_exists( 'set_transient' ) ) { return; }
        $ttl = max( 300, min( 86400, (int) $this->config->get( 'publication_cache_ttl', 21600 ) ) );
        $key = $this->key( $identity );
        set_transient( $key, array(
            'contract' => 'etg.dfsb.publication-cache-entry.v1',
            'configuration_revision' => $this->config->revision(),
            'cached_at_gmt' => gmdate( 'c' ),
            'candidate' => $candidate,
        ), $ttl );
        $this->trackKey( $key );
        $this->writes++;
    }

    public function invalidate(): void {
        self::deleteTrackedTransients();
        if ( function_exists( 'update_option' ) ) { update_option( self::EPOCH_OPTION, $this->epoch() + 1, false ); }
    }

    public function stats(): array {
        return array(
            'contract' => 'etg.dfsb.publication-cache-stats.v1',
            'hits' => $this->hits,
            'misses' => $this->misses,
            'writes' => $this->writes,
            'epoch' => $this->epoch(),
            'tracked_keys' => count( self::trackedKeys() ),
            'ttl_seconds' => max( 300, min( 86400, (int) $this->config->get( 'publication_cache_ttl', 21600 ) ) ),
        );
    }

    public static function deleteTrackedTransients(): void {
        if ( function_exists( 'delete_transient' ) ) {
            foreach ( self::trackedKeys() as $key ) { delete_transient( $key ); }
        }
        if ( function_exists( 'delete_option' ) ) { delete_option( self::KEYS_OPTION ); }
    }

    public static function trackedKeys(): array {
        if ( ! function_exists( 'get_option' ) ) { return array(); }
        $keys = get_option( self::KEYS_OPTION, array() );
        $out = array();
        foreach ( array_slice( (array) $keys, 0, self::MAX_TRACKED_KEYS ) as $key ) {
            $key = is_scalar( $key ) ? (string) $key : '';
            if ( 0 === strpos( $key, self::KEY_PREFIX ) && strlen( $key ) <= 64 ) { $out[] = $key; }
        }
        return array_values( array_unique( $out ) );
    }

    private function trackKey( string $key ): void {
        if ( ! function_exists( 'update_option' ) || 0 !== strpos( $key, self::KEY_PREFIX ) ) { return; }
        $keys = self::trackedKeys();
        if ( ! in_array( $key, $keys, true ) ) { $keys[] = $key; }
        if ( count( $keys ) > self::MAX_TRACKED_KEYS ) {
            $drop = array_slice( $keys, 0, count( $keys ) - self::MAX_TRACKED_KEYS );
            $keys = array_slice( $keys, -self::MAX_TRACKED_KEYS );
            if ( function_exists( 'delete_transient' ) ) { foreach ( $drop as $stale ) { delete_transient( $stale ); } }
        }
        update_option( self::KEYS_OPTION, array_values( $keys ), false );
    }

    private function key( string $identity ): string {
        return self::KEY_PREFIX . substr( hash( 'sha256', $this->epoch() . '|' . $this->config->revision() . '|' . $identity ), 0, 32 );
    }

    private function epoch(): int {
        if ( ! function_exists( 'get_option' ) ) { return 1; }
        $value = get_option( self::EPOCH_OPTION, 1 );
        return is_numeric( $value ) ? max( 1, (int) $value ) : 1;
    }
}
