<?php
/**
 * Plugin Name: MAD4B ETG Locale Presentation Parity (Staging)
 * Description: English Elementor layout authority with translated copy and locale-safe native navigation.
 */
defined('ABSPATH') || exit;
if (wp_get_environment_type() !== 'staging'
    || wp_parse_url(home_url('/'), PHP_URL_HOST) !== 'tours.dev.mad4b.com') return;

add_action('init', static function () {
    foreach (['see_all' => 'See all', 'plan_trip' => 'Plan Your Trip!'] as $key => $value) {
        do_action('wpml_register_single_string', 'MAD4B Navigation', $key, $value);
    }
}, 40);

function mad4b_etg_nav_label(string $key, string $language): string {
    $source = ['see_all' => 'See all', 'plan_trip' => 'Plan Your Trip!'][$key] ?? '';
    if ($source === '') return '';
    $fallback = [
        'see_all' => ['ar' => 'عرض الكل', 'es' => 'Ver todo', 'fr' => 'Tout voir', 'de' => 'Alle ansehen', 'it' => 'Vedi tutti'],
        'plan_trip' => ['ar' => 'خطط لرحلتك!', 'es' => '¡Planifica tu viaje!', 'fr' => 'Planifiez votre voyage !', 'de' => 'Planen Sie Ihre Reise!', 'it' => 'Pianifica il tuo viaggio!'],
    ];
    $translated = apply_filters('wpml_translate_single_string', $source, 'MAD4B Navigation', $key, $language);
    if (!is_string($translated) || trim($translated) === '' || ($language !== 'en' && $translated === $source)) {
        $translated = $fallback[$key][$language] ?? $source;
    }
    if ($key === 'see_all') $translated .= $language === 'ar' ? ' ‹' : ' ›';
    return $translated;
}
add_action('wp_enqueue_scripts', static function () {
    $file = WPMU_PLUGIN_DIR . '/360-mad4b-elementor-locale-layout.css';
    if (is_file($file)) wp_enqueue_style(
        'mad4b-elementor-locale-layout',
        content_url('mu-plugins/360-mad4b-elementor-locale-layout.css'), [],
        (string) filemtime($file)
    );
}, 100);

add_filter('elementor/frontend/builder_content_data', static function ($nodes, $document_id) {
    if (!is_array($nodes) || is_admin() || isset($_GET['elementor-preview'])) return $nodes;
    $header_ids = [26212, 43484, 43486, 43488, 43490, 43492];
    $blog_ids = [30875, 40485, 36452, 36454, 35430, 40128];
    $doc = (int) $document_id;
    if (!in_array($doc, $header_ids, true) && !in_array($doc, $blog_ids, true)) return $nodes;
    $header = in_array($doc, $header_ids, true);
    $master = $header ? 26212 : 30875;
    $original = json_decode((string) get_post_meta($master, '_elementor_data', true), true);
    if (!is_array($original)) return $nodes;

    // A deliberately narrow editable-layout whitelist: never override localized
    // strings, dynamic-tag bindings, listing queries, panel topology or visibility.
    $layout = static function (string $key): bool {
        return (bool) preg_match(
            '/^(?:content_width|boxed_width|width(?:_[a-z_]+)?|max_width(?:_[a-z_]+)?|min_height(?:_[a-z_]+)?|layout|flex_(?:direction|align_items|justify_content|wrap|gap)(?:_[a-z_]+)?|padding(?:_[a-z_]+)?|margin(?:_[a-z_]+)?|grid_(?:columns|rows|gaps)(?:_[a-z_]+)?)$/',
            $key
        );
    };
    $source = [];
    $index = static function (array $tree) use (&$index, &$source): void {
        foreach ($tree as $node) {
            if (!is_array($node)) continue;
            $source[(string) ($node['id'] ?? '')] = $node;
            $index($node['elements'] ?? []);
        }
    };
    $index($original);
    $lang = (string) (apply_filters('wpml_current_language', null) ?: apply_filters('wpml_default_language', null) ?: 'en');

    $apply = static function (array $tree, int $depth = 0) use (&$apply, $source, $header, $layout, $lang): array {
        foreach ($tree as &$node) {
            if (!is_array($node)) continue;
            $id = (string) ($node['id'] ?? '');
            $canonical = $source[$id] ?? null;
            if (is_array($canonical)
                && ($node['elType'] ?? null) === ($canonical['elType'] ?? null)
                && ($node['widgetType'] ?? null) === ($canonical['widgetType'] ?? null)) {
                $settings = (array) ($node['settings'] ?? []);
                $base = (array) ($canonical['settings'] ?? []);
                foreach ($settings as $key => $value) {
                    if ($layout((string) $key) && !array_key_exists($key, $base)) unset($settings[$key]);
                }
                foreach ($base as $key => $value) {
                    if ($layout((string) $key)) $settings[$key] = $value;
                }
                $node['settings'] = $settings;
            }
            if ($header && $id === 'e9d5787' && ($node['elType'] ?? '') === 'container') {
                $node['settings']['content_width'] = 'full';
                $node['settings']['width'] = ['unit' => '%', 'size' => 100, 'sizes' => []];
            }
            if (!$header && $depth === 1 && ($node['widgetType'] ?? '') === 'heading') {
                // Blog archive H1; translations may legitimately have a different Elementor node ID.
                $node['settings']['align'] = 'center';
                $node['settings']['align_tablet'] = 'center';
                $node['settings']['align_mobile'] = 'center';
            }
            if ($header && ($node['widgetType'] ?? '') === 'mega-menu' && $lang === 'ar') {
                $node['settings']['content_horizontal_position'] = 'right';
            }
            if ($header && ($node['widgetType'] ?? '') === 'button') {
                // Native destination panels clone Elementor nodes and assign fresh IDs.
                // Follow the editor-owned role class, not only the original widget IDs.
                $classes = (string) ($node['settings']['_css_classes'] ?? '');
                $key = str_contains($classes, 'navigation-editor-see-all')
                    || in_array($id, ['f7d17ff', 'b9e9c29'], true) ? 'see_all'
                    : (in_array($id, ['4203ab4', 'c45dc14'], true) ? 'plan_trip' : '');
                if ($key !== '') $node['settings']['text'] = mad4b_etg_nav_label($key, $lang);
            }
            if (!empty($node['elements']) && is_array($node['elements'])) {
                $node['elements'] = $apply($node['elements'], $depth + 1);
            }
        }
        unset($node);
        return $tree;
    };
    return $apply($nodes);
}, 170, 2);
