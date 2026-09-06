<?php
namespace ETG\DynamicFilterSEOBridge\Admin;

final class AdminAssets {
    private $pages = array(
        'etg-filter-seo',
        'etg-dfsb-dynamic-content',
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

        if ('etg-dfsb-dynamic-content' === $page) {
            wp_enqueue_script(
                'etg-dfsb-dynamic-content-admin',
                plugins_url('assets/js/dynamic-content-admin.js', ETG_DFSB_DIR . 'etg-dynamic-filter-seo-bridge.php'),
                array('etg-dfsb-admin-shell'),
                ETG_DFSB_VERSION,
                true
            );
        }

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
