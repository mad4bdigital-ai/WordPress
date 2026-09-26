<?php
namespace ETG\DynamicFilterSEOBridge\Lifecycle;

require_once dirname( __DIR__ ) . '/SEO/PublicationCache.php';
require_once dirname( __DIR__ ) . '/Config/ConfigurationMigrations.php';

use ETG\DynamicFilterSEOBridge\SEO\PublicationCache;
use ETG\DynamicFilterSEOBridge\Config\ConfigurationMigrations;

final class UninstallPolicy {
    public const CONTRACT = 'etg.dfsb.uninstall-policy.v1';

    public static function shouldDeleteData(): bool {
        if ( function_exists( 'is_multisite' ) && is_multisite() ) { return false; }
        if ( ! function_exists( 'get_option' ) ) { return false; }
        $settings = get_option( 'etg_dfsb_settings', array() );
        if ( ! is_array( $settings ) ) { return false; }
        $schema = isset( $settings['schema_version'] ) && is_numeric( $settings['schema_version'] ) ? (int) $settings['schema_version'] : 0;
        $state = (string) ( $settings['migration_state'] ?? '' );
        return ConfigurationMigrations::CURRENT_SCHEMA_VERSION === $schema
            && 'current' === $state
            && 'delete_on_uninstall' === (string) ( $settings['data_retention'] ?? 'preserve' );
    }

    public static function cleanup(): array {
        if ( ! self::shouldDeleteData() ) {
            return array( 'contract'=>self::CONTRACT, 'deleted'=>false, 'reason'=>( function_exists('is_multisite') && is_multisite() ) ? 'multisite_preserve_policy' : 'retention_preserve' );
        }

        PublicationCache::deleteTrackedTransients();
        if ( function_exists( 'delete_transient' ) ) { delete_transient( 'etg_dfsb_runtime_topology_v1' ); }
        if ( function_exists( 'delete_option' ) ) {
            foreach ( array(
                'etg_dfsb_settings',
                'etg_dfsb_boot_guard_v1',
                'etg_dfsb_dynamic_content_slots',
                'etg_dfsb_media_discovery',
                PublicationCache::EPOCH_OPTION,
                PublicationCache::KEYS_OPTION,
            ) as $option ) { delete_option( $option ); }
        }
        return array( 'contract'=>self::CONTRACT, 'deleted'=>true, 'reason'=>'delete_on_uninstall' );
    }
}
