<?php
/**
 * Plugin Name: MAD4B Home First Screen — JetEngine Dynamic Copy
 * Description: Shared, site-specific homepage hero copy with JetEngine Look & Feel and Elementor dynamic text.
 * Version: 1.2.0
 */
defined('ABSPATH') || exit;

final class MAD4B_Home_First_Screen {
    private const PAGE = 'look-and-feel';
    private const PREFIX = 'mad4b_hero_';
    public static function copy(string $field): string {
        if (!in_array($field, ['headline', 'subtitle'], true)) return '';
        $options = get_option(self::PAGE, []);
        $raw = is_array($options) ? ($options[self::PREFIX.$field] ?? '') : '';
        if (!is_string($raw)) return '';
        $raw = trim(wp_strip_all_tags($raw));
        $lang = (string) apply_filters('wpml_current_language', 'en');
        if ($lang !== 'en') {
            // Explicit Home Page WPML strings outrank legacy Brand Copy.
            // Do not leak English copy or an unrelated older marketing slogan
            // into a published but not-yet-reviewed locale template.
            $translated = apply_filters('wpml_translate_single_string', $raw,
                'mad4b-home-first-screen', self::PREFIX.$field, $lang);
            return is_string($translated) && trim($translated) !== '' &&
                trim($translated) !== $raw ? trim(wp_strip_all_tags($translated)) : '';
        }
        return $raw;
    }
    public static function register_strings(): void {
        if (!defined('ICL_SITEPRESS_VERSION')) return;
        $options = get_option(self::PAGE, []);
        foreach (['headline', 'subtitle'] as $field) {
            $key = self::PREFIX.$field;
            $value = is_array($options) ? ($options[$key] ?? '') : '';
            if (is_string($value) && trim($value) !== '')
                do_action('wpml_register_single_string', 'mad4b-home-first-screen', $key, $value);
        }
    }
    public static function shortcode($atts): string {
        $atts = shortcode_atts(['field' => 'headline'], (array)$atts, 'mad4b_home_hero_copy');
        return esc_html(self::copy((string)$atts['field']));
    }
    public static function register_tag($registry): void {
        if (!class_exists('\Elementor\Core\DynamicTags\Tag')) return;
        require_once __DIR__ . '/mad4b-home-first-screen/elementor-tag.php';
        $registry->register(new MAD4B_Home_Hero_Text_Tag());
    }
    public static function invalidate_on_copy_change($old, $new): void {
        if (!is_array($old) || !is_array($new)) return;
        $changed = [];
        foreach (['headline', 'subtitle'] as $field) {
            $key = self::PREFIX . $field;
            if (($old[$key] ?? null) !== ($new[$key] ?? null)) $changed[] = $key;
        }
        if (!$changed) return;

        // Elementor element output caches literal rendered text, even when
        // the source value is an Elementor dynamic tag.
        $template_ids = [41727];
        $languages = (array) (get_option('icl_sitepress_settings', [])['active_languages'] ?? []);
        foreach ($languages as $lang) {
            if (!is_string($lang) || !preg_match('/^[a-z]{2}$/D', $lang)) continue;
            $translated = (int) apply_filters('wpml_object_id', 41727, 'elementor_library', false, $lang);
            if ($translated > 0 && get_post_type($translated) === 'elementor_library') $template_ids[] = $translated;
        }
        foreach (array_unique($template_ids) as $template_id) {
            delete_post_meta($template_id, '_elementor_element_cache');
            clean_post_cache($template_id);
        }

        // The sites use WPML directory-based locale URLs. Never touch URLs
        // belonging to a different site, or purge non-homepage cache keys.
        $paths = ['/'];
        $wpml = (array) get_option('icl_sitepress_settings', []);
        if ((int)($wpml['language_negotiation_type'] ?? 0) === 1) {
            $default = (string) ($wpml['default_language'] ?? 'en');
            $separate_default = !empty($wpml['urls']['directory_for_default_language']);
            foreach ($languages as $lang) {
                if (is_string($lang) && preg_match('/^[a-z]{2}$/D', $lang)
                    && ($lang !== $default || $separate_default)) {
                    $paths[] = '/' . $lang . '/';
                }
            }
        }
        $paths = array_values(array_unique($paths));

        foreach ($paths as $path) {
            $url = home_url($path);
            do_action('litespeed_purge_url', $url);
        }
        $origin = ['status' => 'unavailable'];
        $socket_path = WP_CONTENT_DIR . '/.mad4b-purge/origin.sock';
        if (is_file($socket_path) || file_exists($socket_path)) {
            $sock = @stream_socket_client('unix://' . $socket_path, $errno, $errstr, 2);
            if ($sock) {
                stream_set_timeout($sock, 5);
                $line = wp_json_encode($paths) . "\n";
                if (fwrite($sock, $line) === strlen($line)) {
                    $reply = json_decode((string)fgets($sock, 4096), true);
                    if (is_array($reply)) $origin = $reply;
                }
                fclose($sock);
            }
        }
        update_option('mad4b_home_first_screen_invalidation_receipt', [
            'timestamp' => gmdate('c'),
            'changed' => $changed,
            'paths' => $paths,
            'origin' => $origin,
            'templates' => array_values(array_unique($template_ids)),
        ], false);
    }
    public static function mobile_style(): void {
        if (!is_front_page()) return;
        echo '<style id="mad4b-home-first-screen-mobile">
        .home .elementor-41727 .elementor-element-ab47c1d {max-width:760px;margin-left:auto;margin-right:auto}
        .home .elementor-41727 .elementor-element-ab47c1d .elementor-heading-title {color:#fff;text-shadow:0 2px 12px rgba(0,0,0,.65);font-size:clamp(17px,1.6vw,21px);font-weight:400;line-height:1.45}
        @media(max-width:767px) {
          .home .elementor-41727 .elementor-element-e991bc9 {padding:24px 0 20px;min-height:270px}
          .home .elementor-41727 .elementor-element-ea1eb12 .elementor-heading-title {font-size:clamp(26px,7.2vw,34px);line-height:1.16;text-wrap:balance}
          .home .elementor-41727 .elementor-element-ab47c1d {padding:0 15px}
          .home .elementor-41727 .elementor-element-ab47c1d .elementor-heading-title {font-size:15.5px;line-height:1.44}
        }
        </style>'."\n";
    }
}
add_action('init', ['MAD4B_Home_First_Screen', 'register_strings'], 40);
add_action('init', static function () {
    add_shortcode('mad4b_home_hero_copy', ['MAD4B_Home_First_Screen', 'shortcode']);
});
add_action('elementor/dynamic_tags/register', ['MAD4B_Home_First_Screen', 'register_tag'], 25);
add_action('wp_head', ['MAD4B_Home_First_Screen', 'mobile_style'], 99);

add_action('update_option_look-and-feel', ['MAD4B_Home_First_Screen', 'invalidate_on_copy_change'], 90, 2);
