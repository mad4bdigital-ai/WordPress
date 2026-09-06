<?php
namespace ETG\DynamicFilterSEOBridge\Admin;

final class AdminAssets {
    private $pages = array('etg-dfsb-inventory-control','etg-dfsb-dynamic-content');

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
    }
}
