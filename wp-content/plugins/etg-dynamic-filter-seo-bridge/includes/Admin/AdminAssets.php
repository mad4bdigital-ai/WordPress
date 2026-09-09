<?php
namespace ETG\DynamicFilterSEOBridge\Admin;

final class AdminAssets {
    private $pages = array(
        'etg-filter-seo',
        'etg-dfsb-dynamic-content',
        'etg-dfsb-media-lab',
        'etg-dfsb-jetengine-inspector',
        'etg-dfsb-inventory-control',
        'etg-filter-seo-publication',
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
            plugins_url('assets/css/admin-alpha13.css', ETG_DFSB_DIR . 'etg-dynamic-filter-seo-bridge.php'),
            array(),
            ETG_DFSB_VERSION
        );
        wp_enqueue_script(
            'etg-dfsb-admin-shell',
            plugins_url('assets/js/admin-shell.js', ETG_DFSB_DIR . 'etg-dynamic-filter-seo-bridge.php'),
            array(),
            ETG_DFSB_VERSION,
            true
        );

        if ('etg-dfsb-media-lab' === $page) {
            wp_enqueue_style(
                'etg-dfsb-media-lab',
                plugins_url('assets/css/media-lab.css', ETG_DFSB_DIR . 'etg-dynamic-filter-seo-bridge.php'),
                array('etg-dfsb-admin-alpha13'),
                ETG_DFSB_VERSION
            );
        }

        if (in_array($page, array('etg-dfsb-media-lab', 'etg-dfsb-dynamic-content'), true)) {
            wp_enqueue_style(
                'etg-dfsb-admin-discovery',
                plugins_url('assets/css/admin-discovery.css', ETG_DFSB_DIR . 'etg-dynamic-filter-seo-bridge.php'),
                array('etg-dfsb-admin-alpha13'),
                ETG_DFSB_VERSION
            );
            wp_enqueue_script(
                'etg-dfsb-admin-discovery',
                plugins_url('assets/js/admin-discovery.js', ETG_DFSB_DIR . 'etg-dynamic-filter-seo-bridge.php'),
                array('etg-dfsb-admin-shell'),
                ETG_DFSB_VERSION,
                true
            );
            wp_localize_script('etg-dfsb-admin-discovery', 'ETGDFSB_ADMIN_DISCOVERY', array(
                'ajaxUrl' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce(AdminDiscoveryController::NONCE_ACTION),
                'catalogAction' => AdminDiscoveryController::CATALOG_ACTION,
                'mediaSlotsAction' => AdminDiscoveryController::MEDIA_SLOTS_ACTION,
                'saveMediaModeAction' => AdminDiscoveryController::SAVE_MEDIA_MODE_ACTION,
                'mediaLabUrl' => AdminUi::pageUrl('etg-dfsb-media-lab', array('tab' => 'collections')),
                'maxItems' => AdminDiscoveryController::MAX_ITEMS,
            ));
        }

        if ('etg-dfsb-dynamic-content' === $page) {
            wp_enqueue_script(
                'etg-dfsb-dynamic-content-admin',
                plugins_url('assets/js/dynamic-content-admin.js', ETG_DFSB_DIR . 'etg-dynamic-filter-seo-bridge.php'),
                array('etg-dfsb-admin-shell', 'etg-dfsb-admin-discovery'),
                ETG_DFSB_VERSION,
                true
            );
        }

        wp_enqueue_style(
            'etg-dfsb-admin-shell-responsive',
            plugins_url('assets/css/admin-shell-responsive.css', ETG_DFSB_DIR . 'etg-dynamic-filter-seo-bridge.php'),
            array('etg-dfsb-admin-alpha13'),
            ETG_DFSB_VERSION
        );

        if ('etg-dfsb-usage-guide' === $page) {
            wp_enqueue_script(
                'etg-dfsb-usage-guide',
                plugins_url('assets/js/usage-guide.js', ETG_DFSB_DIR . 'etg-dynamic-filter-seo-bridge.php'),
                array('etg-dfsb-admin-shell'),
                ETG_DFSB_VERSION,
                true
            );
        }
    }
}
