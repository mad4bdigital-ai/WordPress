<?php
namespace DynamicFilterSEOBridge\Admin;

final class AdminUi {
    private const LEGACY_PAGES = array(
        'etg-filter-seo' => 'dfsb-control-center',
        'etg-dfsb-dynamic-content' => 'dfsb-dynamic-content',
        'etg-dfsb-media-lab' => 'dfsb-media-lab',
        'etg-dfsb-jetengine-inspector' => 'dfsb-jetengine-inspector',
        'etg-dfsb-inventory-control' => 'dfsb-inventory-control',
        'etg-filter-seo-publication' => 'dfsb-publication',
        'etg-indexflow-url-architecture' => 'dfsb-url-architecture',
        'etg-dfsb-usage-guide' => 'dfsb-usage-guide',
    );

    private const PAGES = array(
        'dfsb-control-center' => 'Control Center',
        'dfsb-dynamic-content' => 'Dynamic Content',
        'dfsb-media-lab' => 'Media Lab',
        'dfsb-jetengine-inspector' => 'JetEngine Inspector',
        'dfsb-inventory-control' => 'Inventory Control',
        'dfsb-publication' => 'SEO Publication',
        'dfsb-url-architecture' => 'URL Architecture',
        'dfsb-usage-guide' => 'Usage Guide',
    );

    /** Register the neutral page as visible and retain old bookmarked URLs invisibly. */
    public static function registerPage(string $title, string $slug, callable $handler): void {
        add_options_page($title, $title, 'manage_options', $slug, $handler);
        $legacy = array_search($slug, self::LEGACY_PAGES, true);
        if ($legacy !== false) {
            add_options_page($title . ' (compatibility)', $title . ' (compatibility)', 'manage_options', $legacy, $handler);
            // Keep the registered page hook for old deep links; avoid duplicate menu items.
            remove_submenu_page('options-general.php', $legacy);
        }
    }

    /** The POST action selects its own exact legacy or neutral nonce domain. */
    public static function checkActionNonce(string $legacyAction, string $suffix = ''): int {
        $action = isset($_POST['action']) && is_string($_POST['action'])
            ? sanitize_key(wp_unslash($_POST['action'])) : '';
        $neutral = preg_replace('/^etg_dfsb_/', 'dfsb_', $legacyAction);
        if ($neutral === $legacyAction || !in_array($action, array($neutral, $legacyAction), true)) {
            wp_die('Invalid operation.', 'Forbidden', array('response' => 403));
        }
        return check_admin_referer($action . $suffix);
    }

    public static function pageUrl(string $page, array $args = array()): string {
        return add_query_arg(array_merge(array('page' => $page), $args), admin_url('options-general.php'));
    }

    public static function activeTab(array $tabs, string $default): string {
        $tab = isset($_GET['tab']) && is_scalar($_GET['tab']) ? sanitize_key((string) wp_unslash($_GET['tab'])) : $default;
        return isset($tabs[$tab]) ? $tab : $default;
    }

    public static function renderHeader(string $activePage, string $title, string $description, array $actions = array()): void {
        ?>
        <div class="etg-page-head">
            <div class="etg-page-head__copy">
                <span class="etg-eyebrow">Dynamic Filter SEO Bridge</span>
                <h1><?php echo esc_html($title); ?></h1>
                <p><?php echo esc_html($description); ?></p>
            </div>
            <?php if ($actions): ?>
                <div class="etg-actions etg-page-head__actions">
                    <?php foreach ($actions as $action):
                        $label = (string) ($action['label'] ?? 'Open');
                        $url = (string) ($action['url'] ?? '#');
                        $primary = !empty($action['primary']);
                    ?>
                        <a class="button <?php echo $primary ? 'button-primary' : 'button-secondary'; ?>" href="<?php echo esc_url($url); ?>"><?php echo esc_html($label); ?></a>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
        <?php self::renderProductNav($activePage); ?>
        <?php
    }

    public static function renderProductNav(string $activePage): void {
        echo '<nav class="etg-product-nav" aria-label="Plugin pages">';
        foreach (self::PAGES as $slug => $label) {
            $class = 'etg-product-nav__item' . ($slug === $activePage ? ' is-active' : '');
            printf('<a class="%1$s" href="%2$s">%3$s</a>', esc_attr($class), esc_url(self::pageUrl($slug)), esc_html($label));
        }
        echo '</nav>';
    }

    public static function renderTabs(string $page, array $tabs, string $active, array $extraArgs = array()): void {
        echo '<nav class="nav-tab-wrapper etg-subtabs" aria-label="Page sections">';
        foreach ($tabs as $slug => $label) {
            $args = array_merge($extraArgs, array('tab' => $slug));
            $class = 'nav-tab' . ($slug === $active ? ' nav-tab-active' : '');
            printf('<a class="%1$s" href="%2$s">%3$s</a>', esc_attr($class), esc_url(self::pageUrl($page, $args)), esc_html($label));
        }
        echo '</nav>';
    }

    public static function renderTableSearch(string $inputId, string $targetId, string $placeholder = 'Search…', string $countLabel = ''): void {
        ?>
        <div class="etg-table-tools">
            <?php if ('' !== $countLabel): ?><span class="etg-table-tools__count"><?php echo esc_html($countLabel); ?></span><?php endif; ?>
            <label class="screen-reader-text" for="<?php echo esc_attr($inputId); ?>"><?php echo esc_html($placeholder); ?></label>
            <input id="<?php echo esc_attr($inputId); ?>" type="search" class="etg-table-search" data-etg-table-search="#<?php echo esc_attr($targetId); ?>" placeholder="<?php echo esc_attr($placeholder); ?>">
        </div>
        <?php
    }

    private function __construct() {}
}
