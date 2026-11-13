<?php
/**
 * Plugin Name: MAD4B — Native Elementor Home Reviews Visibility
 * Description: Reusable review eligibility and Elementor template visibility on public pages, archives and WPML translations.
 * Version: 1.1.1
 */
if (!defined('ABSPATH')) {
    exit;
}

/**
 * This is an Elementor pre-render condition, not CSS/JS hiding.
 * Scope: the homepage's Business Reviews Section template (source ID 27219,
 * including WPML's linked translations). It cannot affect other sections.
 */
final class MAD4B_Home_Reviews_Native_Visibility_V1 {
    // Keep object identity rather than Elementor IDs to avoid suppressing an
    // unrelated widget when translated templates reuse internal element IDs.
    private static ?\WeakMap $early_suppressed = null;
    private const SOURCE_TEMPLATE = 27219;
    private const SOURCE_HOME = 35138;
    private const REVIEWS_QUERY_ID = 257;
    private const PUBLIC_HOSTS = ['tours.dev.mad4b.com', 'wayfatours.com', 'www.wayfatours.com'];

    public static function boot(): void {
        // Elementor's should_render is post-content. The original Template
        // widget must be made cheap in before_render, before nested templates
        // produce HTML, while should_render remains the final native decision.
        add_action('elementor/frontend/widget/before_render', [__CLASS__, 'before_render'], 34, 1);
        add_filter('elementor/frontend/widget/should_render', [__CLASS__, 'should_render'], 35, 2);
        add_action('update_option_look-and-feel', [__CLASS__, 'on_look_and_feel'], 25, 2);
        add_action('update_option_contact-and-help-info', [__CLASS__, 'on_contact_info'], 25, 2);
        add_action('add_option_look-and-feel', [__CLASS__, 'invalidate_home'], 25, 0);
        add_action('add_option_contact-and-help-info', [__CLASS__, 'invalidate_home'], 25, 0);
        add_action('transition_post_status', [__CLASS__, 'on_review_status'], 25, 3);
        add_action('save_post_custom-reviews', [__CLASS__, 'on_review_save'], 25, 1);
        add_action('before_delete_post', [__CLASS__, 'on_review_delete'], 25, 2);
        add_action('delete_attachment', [__CLASS__, 'on_attachment_delete'], 25, 1);
    }

    private static function permitted_site(): bool {
        $host = strtolower((string) wp_parse_url(home_url('/'), PHP_URL_HOST));
        return in_array($host, self::PUBLIC_HOSTS, true);
    }

    private static function is_elementor_editor(): bool {
        static $editing = null;
        if ($editing !== null) return $editing;
        $editing = false;
        try {
            if (class_exists('\\Elementor\\Plugin') &&
                isset(\Elementor\Plugin::$instance->editor) &&
                method_exists(\Elementor\Plugin::$instance->editor, 'is_edit_mode')) {
                $editing = (bool) \Elementor\Plugin::$instance->editor->is_edit_mode();
            }
        } catch (\Throwable $ignored) {
            $editing = false;
        }
        return $editing;
    }

    private static function is_review_template(int $template_id): bool {
        if ($template_id === self::SOURCE_TEMPLATE) {
            return true;
        }
        if (!has_filter('wpml_object_id')) {
            return false;
        }
        $lang = (string) apply_filters('wpml_current_language', null);
        if ($lang === '') {
            return false;
        }
        $translated = (int) apply_filters(
            'wpml_object_id', self::SOURCE_TEMPLATE, 'elementor_library', false, $lang
        );
        return $translated > 0 && $translated === $template_id;
    }

    public static function before_render($widget): void {
        // Mobile staging only: do not change the desktop, editor, previews,
        // AJAX, logged-in user flows or other companies.
        if (!self::permitted_site()
            || is_admin() || wp_doing_ajax() || self::is_elementor_editor()
            || !is_object($widget) || !method_exists($widget, 'get_name')
            || $widget->get_name() !== 'template'
            || !method_exists($widget, 'get_settings_for_display')
            || !method_exists($widget, 'set_settings')) return;
        $settings = $widget->get_settings_for_display();
        if (!is_array($settings)
            || !self::is_review_template((int)($settings['template_id'] ?? 0))
            || self::has_eligible_content()) return;
        self::$early_suppressed ??= new \WeakMap();
        self::$early_suppressed[$widget] = true;
        // Elementor Pro Template::render() explicitly returns when empty.
        // No nested galleries, tabs or JetEngine listing templates execute.
        $widget->set_settings('template_id', '');
    }

    public static function should_render($render, $widget) {
        // Elementor calls this hook after creating widget content. Preserve
        // its native output decision while honoring the early skip above.
        if (is_object($widget) && self::$early_suppressed !== null
            && isset(self::$early_suppressed[$widget])) return false;
        if (!$render || !self::permitted_site() || self::is_elementor_editor()
            || !is_object($widget) || !method_exists($widget, 'get_name')
            || $widget->get_name() !== 'template'
            || !method_exists($widget, 'get_settings_for_display')) {
            return $render;
        }

        $settings = $widget->get_settings_for_display();
        if (!is_array($settings) || !self::is_review_template((int) ($settings['template_id'] ?? 0))) {
            return $render;
        }

        // Run before Elementor renders the nested template, its galleries,
        // query grid, shortcode, scripts and template-specific styles.
        return self::has_eligible_content();
    }

    public static function has_eligible_content(): bool {
        static $cached = null;
        if ($cached !== null) {
            return $cached;
        }

        $look = get_option('look-and-feel', []);
        $look = is_array($look) ? $look : [];
        if (($look['home_section_reviews'] ?? 'on') === 'off') {
            return $cached = false;
        }

        // Only the actual gallery counts; a hero background cannot keep this
        // section visible without reviews/content.
        if (self::has_image_gallery($look['review_gellery_slider'] ?? null)) {
            return $cached = true;
        }

        $contact = get_option('contact-and-help-info', []);
        $contact = is_array($contact) ? $contact : [];
        if (self::has_renderable_reviews_shortcode($contact['trip_advisor_review_shortcode'] ?? null)) {
            return $cached = true;
        }

        return $cached = self::query_has_items();
    }

    private static function has_image_gallery($value): bool {
        if ($value === null || $value === '' || $value === []) {
            return false;
        }
        $entries = is_array($value) ? $value : preg_split('/\s*,\s*/', (string) $value);
        foreach ((array) $entries as $entry) {
            $id = 0;
            if (is_numeric($entry)) {
                $id = (int) $entry;
            } elseif (is_array($entry)) {
                $id = (int) ($entry['id'] ?? ($entry['ID'] ?? 0));
            }
            if ($id > 0 && wp_attachment_is_image($id) && get_post_status($id) === 'inherit') {
                return true;
            }
        }
        return false;
    }

    private static function has_renderable_reviews_shortcode($value): bool {
        if (!is_string($value) || trim($value) === '') {
            return false;
        }
        // A literal, unregistered shortcode is not content. Avoid making
        // network requests: only use WordPress's registered local renderer.
        if (!preg_match_all('/\[([A-Za-z][A-Za-z0-9_-]*)(?:\s|\/?\])/', $value, $matches)) {
            return trim(wp_strip_all_tags($value)) !== '';
        }
        foreach (array_unique($matches[1]) as $tag) {
            if (!shortcode_exists($tag)) {
                return false;
            }
        }
        $html = (string) do_shortcode($value);
        // Script-only or empty divs are not server-confirmed review results.
        $without_scripts = preg_replace('~<(?:script|style)\b[^>]*>.*?</(?:script|style)>~is', '', $html);
        if (trim(wp_strip_all_tags((string) $without_scripts)) !== '') {
            return true;
        }
        return (bool) preg_match('~<(?:img|iframe)\b[^>]*\bsrc\s*=\s*["\x27]https?://~i', (string) $without_scripts);
    }

    private static function query_has_items(): bool {
        // Query Builder's own API (not raw post_count or a list of IDs) preserves
        // the live query's configured filters, source and current WPML language.
        $class = '\Jet_Engine\Query_Builder\Manager';
        if (!class_exists($class)) {
            return false;
        }
        $query = $class::instance()->get_query_by_id(self::REVIEWS_QUERY_ID);
        if (!is_object($query)) {
            return false;
        }
        // An unbounded has_items() would fetch every review because Query 257
        // is configured with posts_per_page=-1. Use JetEngine's *resolved*
        // query arguments, but ask WordPress for only one ID. This preserves
        // the query's language, tax/meta/date conditions and live source.
        if (method_exists($query, 'get_query_type') && $query->get_query_type() === 'posts'
            && method_exists($query, 'get_query_args')) {
            $args = $query->get_query_args();
            if (!is_array($args)) {
                return false;
            }
            $args['posts_per_page'] = 1;
            $args['nopaging'] = false;
            $args['fields'] = 'ids';
            $args['no_found_rows'] = true;
            $args['suppress_filters'] = false; // honor WPML's active language
            $args['ignore_sticky_posts'] = true;
            $args['update_post_meta_cache'] = false;
            $args['update_post_term_cache'] = false;
            $probe = new WP_Query($args);
            return !empty($probe->posts);
        }
        return method_exists($query, 'has_items') && (bool) $query->has_items();
    }

    public static function on_look_and_feel($old, $new): void {
        if (!is_array($old) || !is_array($new)) {
            self::invalidate_home();
            return;
        }
        foreach (['home_section_reviews','review_gellery_slider','review_hero_image'] as $key) {
            if (($old[$key] ?? null) !== ($new[$key] ?? null)) {
                self::invalidate_home();
                return;
            }
        }
    }

    public static function on_contact_info($old, $new): void {
        if (!is_array($old) || !is_array($new)
            || ($old['trip_advisor_review_shortcode'] ?? null) !== ($new['trip_advisor_review_shortcode'] ?? null)) {
            self::invalidate_home();
        }
    }

    public static function on_review_status($new, $old, $post): void {
        if ($post instanceof \WP_Post && $post->post_type === 'custom-reviews'
            && ($new === 'publish' || $old === 'publish') && $new !== $old) {
            self::invalidate_home();
        }
    }

    public static function on_review_save($post_id): void {
        if (get_post_status((int) $post_id) === 'publish') {
            self::invalidate_home();
        }
    }

    public static function on_review_delete($post_id, $post = null): void {
        if ($post instanceof \WP_Post && $post->post_type === 'custom-reviews') {
            self::invalidate_home();
        }
    }

    public static function on_attachment_delete($id): void {
        $look = get_option('look-and-feel', []);
        $v = is_array($look) ? ($look['review_gellery_slider'] ?? null) : null;
        if (is_string($v) && in_array((string) $id, preg_split('/\s*,\s*/', $v), true)) {
            self::invalidate_home();
        }
    }

    public static function invalidate_home(): void {
        if (!self::permitted_site()) {
            return;
        }
        // Every homepage language is a separate URL/HTML cache variant.
        $ids = [self::SOURCE_HOME];
        $langs = apply_filters('wpml_active_languages', null, ['skip_missing' => 0]);
        if (is_array($langs)) {
            foreach (array_keys($langs) as $lang) {
                $id = (int) apply_filters('wpml_object_id', self::SOURCE_HOME, 'page', false, (string) $lang);
                if ($id > 0) {
                    $ids[] = $id;
                }
            }
        }
        $current_home = (int) get_option('page_on_front', 0);
        if ($current_home > 0) {
            $ids[] = $current_home;
        }
        foreach (array_unique(array_filter($ids)) as $id) {
            clean_post_cache($id);
            do_action('litespeed_purge_post', $id);
            $url = get_permalink($id);
            if (is_string($url) && $url !== '') {
                do_action('litespeed_purge_url', $url);
            }
        }
        // The Nginx microcache retains at most its configured short TTL; do not
        // globally flush Redis, LiteSpeed, or unrelated sites for this section.
        do_action('mad4b/home_reviews/source_changed', self::SOURCE_TEMPLATE);
        // Host-side Nginx FastCGI caches cannot be purged by LiteSpeed hooks.
        // Signal a narrowly scoped native host watcher (only home/translated
        // home entries; never the full site or shared object cache).
        $signal = WP_CONTENT_DIR . '/.mad4b-home-reviews-purge.signal';
        if (is_writable(WP_CONTENT_DIR)) {
            @file_put_contents($signal, gmdate('c') . ' ' . wp_generate_uuid4() . "\n", LOCK_EX);
        }
    }
}

MAD4B_Home_Reviews_Native_Visibility_V1::boot();
