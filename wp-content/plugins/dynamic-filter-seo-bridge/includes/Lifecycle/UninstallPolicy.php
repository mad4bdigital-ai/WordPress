<?php
namespace DynamicFilterSEOBridge\Lifecycle;

require_once dirname( __DIR__ ) . '/SEO/PublicationCache.php';
require_once dirname( __DIR__ ) . '/Config/ConfigurationMigrations.php';

use DynamicFilterSEOBridge\SEO\PublicationCache;
use DynamicFilterSEOBridge\Config\ConfigurationMigrations;

final class UninstallPolicy {
    public const CONTRACT = 'etg.dfsb.uninstall-policy.v1';

    public static function shouldDeleteData(): bool {
        return '' === self::retentionReason();
    }

    private static function retentionReason(): string {
        if ( function_exists( 'is_multisite' ) && is_multisite() ) { return 'multisite_preserve_policy'; }
        // Both editions own the same historical storage. An inactive or
        // partially installed historical package may still be needed for rollback.
        if ( ! defined( 'WP_PLUGIN_DIR' ) || ! is_dir( WP_PLUGIN_DIR ) || ! is_readable( WP_PLUGIN_DIR ) ) {
            return 'plugin_inventory_unavailable';
        }
        $legacy = rtrim( WP_PLUGIN_DIR, '/\\' ) . '/etg-dynamic-filter-seo-bridge';
        if ( file_exists( $legacy ) || is_link( $legacy ) ) { return 'shared_legacy_storage_preserve'; }
        if ( ! function_exists( 'get_option' ) ) { return 'retention_preserve'; }
        $settings = get_option( 'etg_dfsb_settings', array() );
        if ( ! is_array( $settings ) ) { return 'retention_preserve'; }
        $schema = isset( $settings['schema_version'] ) && is_numeric( $settings['schema_version'] ) ? (int) $settings['schema_version'] : 0;
        $state = (string) ( $settings['migration_state'] ?? '' );
        return ConfigurationMigrations::CURRENT_SCHEMA_VERSION === $schema
            && 'current' === $state
            && 'delete_on_uninstall' === (string) ( $settings['data_retention'] ?? 'preserve' )
            ? '' : 'retention_preserve';
    }

    public static function cleanup(): array {
        $reason = self::retentionReason();
        if ( '' !== $reason ) {
            return array( 'contract'=>self::CONTRACT, 'canonical_contract'=>'dfsb.uninstall-policy.v1', 'deleted'=>false, 'reason'=>$reason );
        }

        PublicationCache::deleteTrackedTransients();
        if ( function_exists( 'delete_transient' ) ) { delete_transient( 'etg_dfsb_runtime_topology_v1' ); }
        if ( function_exists( 'delete_option' ) ) {
            foreach ( array(
                'etg_dfsb_settings',
                'dfsb_settings',
                'etg_dfsb_boot_guard_v1',
                'etg_dfsb_dynamic_content_slots',
                'dfsb_dynamic_content_slots',
                'etg_dfsb_media_discovery',
                'dfsb_media_discovery',
                PublicationCache::EPOCH_OPTION,
                PublicationCache::KEYS_OPTION,
            ) as $option ) { delete_option( $option ); }
        }
        return array( 'contract'=>self::CONTRACT, 'canonical_contract'=>'dfsb.uninstall-policy.v1', 'deleted'=>true, 'reason'=>'delete_on_uninstall' );
    }
}
