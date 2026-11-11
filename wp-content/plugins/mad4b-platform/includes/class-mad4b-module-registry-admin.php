<?php
/** Read-only inventory of modules actually loaded from canonical WordPress plugins. */
defined('ABSPATH') || exit;
final class MAD4B_Module_Registry_Admin_V1 {
    public static function boot(): void {
        if (is_admin()) add_action('admin_menu', [__CLASS__, 'menu'], 30);
    }
    public static function menu(): void {
        if (!current_user_can('manage_options')) return;
        add_management_page('MAD4B Modules', 'MAD4B Modules', 'manage_options',
            'mad4b-module-registry', [__CLASS__, 'render']);
    }
    public static function rows(): array {
        $map = WP_PLUGIN_DIR . '/mad4b-platform/config/ordered-mu-map.php';
        if (!is_file($map)) return [];
        $entries = require $map;
        if (!is_array($entries) || !class_exists('MAD4B_MU_Map_Contract_V1', false) || !MAD4B_MU_Map_Contract_V1::current($entries)) return [];
        $loaded = [];
        foreach (get_included_files() as $file) $loaded[$file] = true;
        $rows = [];
        foreach ($entries as $item) {
            if (!is_array($item) || count($item) !== 2) return [];
            [$name, $relative] = $item;
            if (!is_string($name) || !is_string($relative)
                || !preg_match('/^[a-zA-Z0-9._-]+\.php$/D', $name)
                || !preg_match('~^mad4b-(?:platform|commerce)/modules/[a-z-]+/[a-zA-Z0-9._-]+\.php$~D', $relative)) return [];
            $parts = explode('/', $relative);
            $file = WP_PLUGIN_DIR . '/' . $relative;
            $actual = realpath($file);
            $rows[] = [
                'name' => $name,
                'family' => $parts[0],
                'group' => $parts[2],
                'loaded' => is_string($actual) && isset($loaded[$actual]),
                'file_present' => is_file($file),
                'phase' => 'MU bootstrap',
            ];
        }
        if (class_exists('MAD4B_Presentation_Experience', false)) {
            foreach (MAD4B_Presentation_Experience::MODULES as $feature => $relative) {
                $file = WP_PLUGIN_DIR . '/mad4b-experience/src/presentation/modules/' . $relative;
                $actual = realpath($file);
                $rows[] = [
                    'name' => (string) $feature,
                    'family' => 'mad4b-experience',
                    'group' => str_starts_with($relative, 'performance/') ? 'performance' : 'presentation',
                    'loaded' => is_string($actual) && isset($loaded[$actual]),
                    'file_present' => is_file($file),
                    'phase' => 'Plugin',
                    'feature_enabled' => MAD4B_Presentation_Experience::enabled((string) $feature),
                ];
            }
        }
        if (class_exists('MAD4B_Visitor_Experience', false)) {
            foreach (MAD4B_Visitor_Experience::MODULES as $feature => $relative) {
                $file = WP_PLUGIN_DIR . '/mad4b-experience/src/visitor/modules/' . $relative;
                $actual = realpath($file);
                $rows[] = [
                    'name' => (string) $feature,
                    'family' => 'mad4b-experience',
                    'group' => 'visitor',
                    'loaded' => is_string($actual) && isset($loaded[$actual]),
                    'file_present' => is_file($file),
                    'phase' => 'Plugin',
                    'feature_enabled' => MAD4B_Visitor_Experience::enabled((string) $feature),
                ];
            }
        }
        return $rows;
    }
    public static function summary(): array {
        $rows = self::rows();
        $family = [];
        $groups = [];
        $missing = 0;
        foreach ($rows as $row) {
            $family[$row['family']] = ($family[$row['family']] ?? 0) + 1;
            $label = $row['family'] . ' / ' . $row['group'];
            $groups[$label] = ($groups[$label] ?? 0) + 1;
            if (!$row['loaded'] || !$row['file_present']) ++$missing;
        }
        ksort($family);
        ksort($groups);
        return ['total' => count($rows), 'by_family' => $family,
            'by_group' => $groups, 'missing_or_not_loaded' => $missing,
            'mu_bootstrap_files' => count(wp_get_mu_plugins())];
    }
    public static function render(): void {
        if (!current_user_can('manage_options')) wp_die('Permission denied');
        $all = self::rows();
        $summary = self::summary();
        $family = isset($_GET['family']) ? sanitize_key(wp_unslash($_GET['family'])) : '';
        if (!in_array($family, ['', 'mad4b-platform', 'mad4b-commerce', 'mad4b-experience'], true)) $family = '';
        $q = isset($_GET['q']) ? sanitize_text_field(wp_unslash($_GET['q'])) : '';
        $q = substr($q, 0, 80);
        $matched = array_values(array_filter($all, static function (array $item) use ($family, $q): bool {
            return ($family === '' || $item['family'] === $family)
                && ($q === '' || stripos($item['name'] . ' ' . $item['group'], $q) !== false);
        }));
        $page = isset($_GET['page_num']) ? max(1, absint($_GET['page_num'])) : 1;
        $per_page = 30;
        $count = count($matched);
        $pages = max(1, (int) ceil($count / $per_page));
        $page = min($page, $pages);
        $part = array_slice($matched, ($page - 1) * $per_page, $per_page);
        echo '<div class="wrap"><h1>MAD4B Modules</h1>';
        echo '<p>Read-only inventory from the current WordPress runtime. Modules are grouped inside three plugins.</p>';
        echo '<p><strong>' . esc_html((string) $summary['total']) . ' modules</strong> · '
            . esc_html((string) $summary['mu_bootstrap_files']) . ' MU bootstrap file · '
            . esc_html((string) $summary['missing_or_not_loaded']) . ' missing/not loaded</p>';
        echo '<p><strong>Important:</strong> Platform and Commerce modules load early through the single MU bootstrap. '
            . 'Disabling a plugin container alone does not disable those modules. Runtime control requires a dependency-aware module policy.</p>';
        echo '<form method="get"><input type="hidden" name="page" value="mad4b-module-registry" />';
        echo '<label for="mad4b-family">Plugin </label><select id="mad4b-family" name="family">';
        foreach (['' => 'All', 'mad4b-platform' => 'Platform', 'mad4b-commerce' => 'Commerce', 'mad4b-experience' => 'Experience'] as $key => $title) {
            echo '<option value="' . esc_attr($key) . '" ' . selected($family, $key, false) . '>' . esc_html($title) . '</option>';
        }
        echo '</select> <label for="mad4b-search">Search </label><input id="mad4b-search" name="q" type="search" value="'
            . esc_attr($q) . '" /> <button type="submit" class="button">Filter</button></form>';
        echo '<p>' . esc_html((string) $count) . ' matching modules · page ' . esc_html((string) $page) . ' of '
            . esc_html((string) $pages) . '</p>';
        echo '<table class="widefat striped"><thead><tr><th>Module</th><th>Plugin</th><th>Group</th><th>Load phase</th><th>Loaded</th></tr></thead><tbody>';
        foreach ($part as $item) {
            $status = $item['loaded'] && $item['file_present'] ? 'Loaded' : 'Missing / not loaded';
            if (array_key_exists('feature_enabled', $item) && !$item['feature_enabled']) $status .= ' · feature disabled';
            echo '<tr><td><code>' . esc_html($item['name']) . '</code></td><td>' . esc_html($item['family'])
                . '</td><td>' . esc_html($item['group']) . '</td><td>' . esc_html($item['phase'])
                . '</td><td>' . esc_html($status) . '</td></tr>';
        }
        echo '</tbody></table>';
        if ($pages > 1) {
            echo '<p class="tablenav-pages">';
            foreach (range(max(1, $page - 2), min($pages, $page + 2)) as $next) {
                $url = add_query_arg(['page' => 'mad4b-module-registry', 'family' => $family,
                    'q' => $q, 'page_num' => $next], admin_url('tools.php'));
                echo '<a class="button" href="' . esc_url($url) . '">' . esc_html((string) $next) . '</a> ';
            }
            echo '</p>';
        }
        echo '</div>';
    }
}
MAD4B_Module_Registry_Admin_V1::boot();
