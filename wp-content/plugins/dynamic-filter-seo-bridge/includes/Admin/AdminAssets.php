<?php
namespace DynamicFilterSEOBridge\Admin;

final class AdminAssets {
    private $pages = array(
        'dfsb-control-center',
        'dfsb-dynamic-content',
        'dfsb-media-lab',
        'dfsb-jetengine-inspector',
        'dfsb-inventory-control',
        'dfsb-publication',
        'dfsb-url-architecture',
        'dfsb-usage-guide',
        'etg-filter-seo',
        'etg-dfsb-dynamic-content',
        'etg-dfsb-media-lab',
        'etg-dfsb-jetengine-inspector',
        'etg-dfsb-inventory-control',
        'etg-filter-seo-publication',
        'etg-indexflow-url-architecture',
        'etg-dfsb-usage-guide',
    );

    public function register(): void {
        add_action('admin_enqueue_scripts', array($this, 'enqueue'));
    }

    public function enqueue($hook): void {
        $page = isset($_GET['page']) ? sanitize_key((string) wp_unslash($_GET['page'])) : '';
        if (!in_array($page, $this->pages, true)) { return; }

        wp_enqueue_style(
            'etg-dfsb-admin-alpha13',
            plugins_url('assets/css/admin-alpha13.css', DFSB_MAIN_FILE),
            array(),
            defined('DFSB_ASSET_VERSION') ? DFSB_ASSET_VERSION : DFSB_VERSION
        );
        wp_enqueue_script(
            'etg-dfsb-admin-shell',
            plugins_url('assets/js/admin-shell.js', DFSB_MAIN_FILE),
            array(),
            defined('DFSB_ASSET_VERSION') ? DFSB_ASSET_VERSION : DFSB_VERSION,
            true
        );

        if (in_array($page, array('dfsb-media-lab','etg-dfsb-media-lab'), true)) {
            wp_enqueue_style(
                'dfsb-media-lab',
                plugins_url('assets/css/media-lab.css', DFSB_MAIN_FILE),
                array('etg-dfsb-admin-alpha13'),
                defined('DFSB_ASSET_VERSION') ? DFSB_ASSET_VERSION : DFSB_VERSION
            );
        }

        if (in_array($page, array('dfsb-media-lab', 'dfsb-dynamic-content', 'etg-dfsb-media-lab', 'etg-dfsb-dynamic-content'), true)) {
            wp_enqueue_style(
                'etg-dfsb-admin-discovery',
                plugins_url('assets/css/admin-discovery.css', DFSB_MAIN_FILE),
                array('etg-dfsb-admin-alpha13'),
                defined('DFSB_ASSET_VERSION') ? DFSB_ASSET_VERSION : DFSB_VERSION
            );
            wp_enqueue_script(
                'etg-dfsb-admin-discovery',
                plugins_url('assets/js/admin-discovery.js', DFSB_MAIN_FILE),
                array('etg-dfsb-admin-shell'),
                defined('DFSB_ASSET_VERSION') ? DFSB_ASSET_VERSION : DFSB_VERSION,
                true
            );
            wp_localize_script('etg-dfsb-admin-discovery', 'DFSB_ADMIN_DISCOVERY', array(
                'ajaxUrl' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce(AdminDiscoveryController::NONCE_ACTION),
                'catalogAction' => AdminDiscoveryController::CATALOG_ACTION,
                'mediaSlotsAction' => AdminDiscoveryController::MEDIA_SLOTS_ACTION,
                'saveMediaModeAction' => AdminDiscoveryController::SAVE_MEDIA_MODE_ACTION,
                'mediaLabUrl' => AdminUi::pageUrl('dfsb-media-lab', array('tab' => 'collections')),
                'maxItems' => AdminDiscoveryController::MAX_ITEMS,
            ));
            wp_add_inline_script('etg-dfsb-admin-discovery', 'window.ETGDFSB_ADMIN_DISCOVERY=window.DFSB_ADMIN_DISCOVERY;', 'before');
        }

        if (in_array($page, array('dfsb-dynamic-content','etg-dfsb-dynamic-content'), true)) {
            wp_enqueue_script(
                'etg-dfsb-dynamic-content-admin',
                plugins_url('assets/js/dynamic-content-admin.js', DFSB_MAIN_FILE),
                array('etg-dfsb-admin-shell', 'etg-dfsb-admin-discovery'),
                defined('DFSB_ASSET_VERSION') ? DFSB_ASSET_VERSION : DFSB_VERSION,
                true
            );
        }

        wp_enqueue_style(
            'etg-dfsb-admin-shell-responsive',
            plugins_url('assets/css/admin-shell-responsive.css', DFSB_MAIN_FILE),
            array('etg-dfsb-admin-alpha13'),
            defined('DFSB_ASSET_VERSION') ? DFSB_ASSET_VERSION : DFSB_VERSION
        );

        if (in_array($page, array('dfsb-usage-guide','etg-dfsb-usage-guide'), true)) {
            wp_enqueue_script(
                'dfsb-usage-guide',
                plugins_url('assets/js/usage-guide.js', DFSB_MAIN_FILE),
                array('etg-dfsb-admin-shell'),
                defined('DFSB_ASSET_VERSION') ? DFSB_ASSET_VERSION : DFSB_VERSION,
                true
            );
        }
    }
}
