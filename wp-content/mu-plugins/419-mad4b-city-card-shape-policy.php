<?php
/**
 * Plugin Name: MAD4B City Card Shape Policy
 * Description: JetEngine Look & Feel governs city image geometry across WPML listing cards, without editing Elementor templates.
 * Version: 1.0.0
 */
defined('ABSPATH') || exit;

final class MAD4B_City_Card_Shape_Policy_V1 {
    private const PAGE = 'look-and-feel';
    private const SHAPE = 'mad4b_city_card_shape_v1';
    private const RADIUS = 'mad4b_city_card_radius_v1';
    private const RECEIPT = 'mad4b_city_card_shape_last_change_v1';

    public static function settings(?array $options = null): array {
        $options ??= get_option(self::PAGE, []);
        $options = is_array($options) ? $options : [];
        $shape = (string)($options[self::SHAPE] ?? 'circle');
        if (!in_array($shape, ['circle', 'landscape', 'wide'], true)) $shape = 'circle';
        $radius = $options[self::RADIUS] ?? 16;
        $radius = is_numeric($radius) ? max(0, min(40, (int)$radius)) : 16;
        return ['shape' => $shape, 'radius' => $radius];
    }

    public static function widget_id(): string {
        $id = strtolower((string)get_option('mad4b_perf_city_image_widget_id_v1', ''));
        return preg_match('/^[a-f0-9]{5,16}$/D', $id) ? $id : '';
    }

    private static function is_city_page(): bool {
        return !is_admin() && !wp_doing_ajax() && (is_front_page() || is_tax('destinations'));
    }

    public static function fields($fields, $page) {
        if (!is_array($fields) || !is_object($page) || ($page->slug ?? '') !== self::PAGE) return $fields;
        if (!is_admin() && !(defined('WP_CLI') && WP_CLI)) return $fields;
        foreach ($fields as $field) {
            if (is_array($field) && ($field['name'] ?? '') === self::SHAPE) return $fields;
        }
        $fields[] = [
            'name' => 'mad4b_city_card_geometry_tab_v1',
            'title' => 'Destinations · City Image Layout',
            'type' => 'text', 'object_type' => 'tab',
            'tab_layout' => 'vertical', 'width' => '100%',
        ];
        $fields[] = [
            'name' => self::SHAPE,
            'title' => 'City card image shape (all languages)',
            'type' => 'select', 'object_type' => 'field', 'width' => '100%',
            'options_source' => 'manual',
            'options' => [
                ['key' => 'circle', 'value' => 'Circle · 1:1 (original)'],
                ['key' => 'landscape', 'value' => 'Landscape rectangle · 16:10 (recommended)'],
                ['key' => 'wide', 'value' => 'Wide rectangle · 16:9'],
            ],
            'default_val' => 'circle',
            'description' => 'Applies to the configured JetEngine City Listing on Home and Destination pages. Shared by WPML languages; no image edits or Elementor template rewrites. Circle restores the original geometry.',
            'repeater-fields' => [],
        ];
        $fields[] = [
            'name' => self::RADIUS,
            'title' => 'Rectangle corner radius (px)',
            'type' => 'number', 'object_type' => 'field', 'width' => '100%',
            'default_val' => 16, 'min' => 0, 'max' => 40, 'step' => 1,
            'description' => '0 = square corners; 16 = softly rounded. Used only for rectangular layouts; the circle style is unaffected.',
            'repeater-fields' => [],
        ];
        return $fields;
    }

    public static function validate($new, $old) {
        if (!is_array($new)) return $new;
        if (array_key_exists(self::SHAPE, $new)) {
            $shape = (string)$new[self::SHAPE];
            $new[self::SHAPE] = in_array($shape, ['circle', 'landscape', 'wide'], true) ? $shape : 'circle';
        }
        if (array_key_exists(self::RADIUS, $new)) {
            $new[self::RADIUS] = is_numeric($new[self::RADIUS])
                ? max(0, min(40, (int)$new[self::RADIUS])) : 16;
        }
        return $new;
    }

    public static function body_classes(array $classes): array {
        if (self::is_city_page() && self::widget_id() !== '') {
            $policy = self::settings();
            if ($policy['shape'] !== 'circle') $classes[] = 'mad4b-city-card-shape-' . $policy['shape'];
        }
        return $classes;
    }

    public static function head_css(): void {
        if (!self::is_city_page()) return;
        $id = self::widget_id();
        $policy = self::settings();
        if (!$id) return;
        // Color overlays are purely decorative. Under optimized markup their
        // hit box may cover a JetEngine link even when the text is visible.
        // Keep the original visual/hover rules, but pass the pointer to the link.
        echo '<style id="mad4b-city-overlay-click-through-v1" data-no-optimize="1">'
            . '.jet-listing-grid__item .elementor-element.elementor-element-' . $id
            . '::before{pointer-events:none!important;}' . '</style>' . "\n";
        if ($policy['shape'] === 'circle') return;
        $shape = $policy['shape'];
        $ratio = $shape === 'wide' ? '16 / 9' : '16 / 10';
        $radius = (int)$policy['radius'];
        // The native Elementor template has 1:1, 50% !important rules.
        // A body-scoped selector wins specificity without changing saved template CSS.
        $selector = 'body.mad4b-city-card-shape-' . $shape
            . ' .jet-listing-grid__item .elementor-element.elementor-element-' . $id;
        echo '<style id="mad4b-city-card-shape-policy-v1" data-no-optimize="1">'
            . $selector . '{aspect-ratio:' . $ratio . '!important;'
            // Elementor paints its ::before color overlay using --border-radius,
            // not the container border-radius property. Keep both in sync with
            // JetEngine Look & Feel (including on responsive and hover states).
            . '--border-radius:' . $radius . 'px!important;'
            . 'border-radius:' . $radius . 'px!important;'
            . 'height:auto!important;min-height:0!important;overflow:hidden!important;'
            . 'background-size:cover!important;background-position:center!important;'
            . 'box-sizing:border-box!important;}'
            . '@media(max-width:767px){' . $selector
            . '{width:min(300px,100%)!important;}}'
            . '</style>' . "\n";
    }

    public static function on_save($old, $new): void {
        if (!is_array($old) || !is_array($new)) return;
        $previous = self::settings($old);
        $current = self::settings($new);
        if ($previous === $current) return;
        // Targeted page purge only. No Redis FLUSHDB, no global purge, no CSS regeneration.
        $paths = ['/'];
        $wpml = (array)get_option('icl_sitepress_settings', []);
        $languages = (array)($wpml['active_languages'] ?? []);
        if ((int)($wpml['language_negotiation_type'] ?? 0) === 1) {
            $default = (string)($wpml['default_language'] ?? 'en');
            $separate = !empty($wpml['urls']['directory_for_default_language']);
            foreach ($languages as $lang) {
                if (is_string($lang) && preg_match('/^[a-z]{2}$/D', $lang)
                    && ($lang !== $default || $separate)) $paths[] = '/' . $lang . '/';
            }
        }
        // Term archives share this listing. Invalidate exactly their local URLs.
        if (taxonomy_exists('destinations')) {
            $terms = get_terms(['taxonomy'=>'destinations','hide_empty'=>false,'fields'=>'ids']);
            if (!is_wp_error($terms)) foreach ($terms as $id) {
                $url = get_term_link((int)$id, 'destinations');
                if (!is_string($url)) continue;
                if (wp_parse_url($url, PHP_URL_HOST) !== wp_parse_url(home_url(), PHP_URL_HOST)) continue;
                $path = (string)wp_parse_url($url, PHP_URL_PATH);
                if ($path !== '' && str_starts_with($path, '/')) $paths[] = $path;
            }
        }
        $paths = array_values(array_unique($paths));
        foreach ($paths as $path) do_action('litespeed_purge_url', home_url($path));
        $origin = ['status'=>'unavailable', 'paths'=>0];
        $socket = WP_CONTENT_DIR . '/.mad4b-purge/origin.sock';
        if (file_exists($socket)) {
            $origin = ['status'=>'ok', 'paths'=>0, 'removed'=>0];
            foreach (array_chunk($paths, 80) as $batch) {
                $stream = @stream_socket_client('unix://' . $socket, $errno, $errstr, 2);
                if (!$stream) { $origin['status']='unavailable'; break; }
                stream_set_timeout($stream, 5);
                $wire = wp_json_encode($batch) . "\n";
                $reply = fwrite($stream, $wire) === strlen($wire)
                    ? json_decode((string)fgets($stream, 4096), true) : null;
                fclose($stream);
                if (($reply['status'] ?? '') !== 'ok') { $origin['status']='failed'; break; }
                $origin['paths'] += (int)($reply['paths'] ?? 0);
                $origin['removed'] += (int)($reply['removed'] ?? 0);
            }
        }
        update_option(self::RECEIPT, [
            'at'=>gmdate('c'), 'from'=>$previous, 'to'=>$current,
            'url_count'=>count($paths), 'origin'=>$origin,
            'scope'=>'home_and_destinations', 'cache'=>'targeted_only',
        ], false);
    }
}
add_filter('jet-engine/options-pages/raw-fields', ['MAD4B_City_Card_Shape_Policy_V1','fields'], 145, 2);
add_filter('pre_update_option_look-and-feel', ['MAD4B_City_Card_Shape_Policy_V1','validate'], 145, 2);
add_filter('body_class', ['MAD4B_City_Card_Shape_Policy_V1','body_classes'], 30);
add_action('wp_head', ['MAD4B_City_Card_Shape_Policy_V1','head_css'], 99);
add_action('update_option_look-and-feel', ['MAD4B_City_Card_Shape_Policy_V1','on_save'], 145, 2);

