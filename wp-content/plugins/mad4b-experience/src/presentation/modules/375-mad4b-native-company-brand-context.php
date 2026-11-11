<?php
/**
 * MAD4B Experience — company-local brand context provider.
 * A lightweight fallback when mad4b-platform's original brand module is absent.
 * Source authority: this company's own Look & Feel + Elementor Kit settings.
 * Never sets, imports or overwrites visual settings.
 */
defined('ABSPATH') || exit;
if (!class_exists('MAD4B_Presentation_Experience', false)
    || !MAD4B_Presentation_Experience::enabled('company-brand-context')) return;

final class MAD4B_Experience_Native_Brand_Context_V1 {
    public const CONTRACT = 'mad4b.experience.brand-context.v1';
    private static array $memo = [];
    private static int $epoch = 0;

    public static function reset(): void {
        self::$epoch++;
        self::$memo = [];
    }

    private static function asset_id($raw): int {
        if (is_array($raw)) $raw = $raw['id'] ?? $raw['attachment_id'] ?? 0;
        if (!is_int($raw) && !is_string($raw)) return 0;
        if (!ctype_digit((string)$raw)) return 0;
        $id = (int)$raw;
        if ($id <= 0 || get_post_type($id) !== 'attachment') return 0;
        return in_array((string)get_post_mime_type($id),
            ['image/png','image/jpeg','image/webp','image/x-icon','image/vnd.microsoft.icon'],true)
            ? $id : 0;
    }

    private static function asset_url(int $id): string {
        if (!$id) return '';
        $url = wp_get_attachment_url($id);
        return is_string($url) && $url !== '' ? esc_url_raw($url) : '';
    }

    private static function native_options(): array {
        $opts = get_option('look-and-feel', []);
        return is_array($opts) ? $opts : [];
    }

    public static function current(): array {
        // Existing Presentation module handles exact domain/UUID binding.
        // WPML language is always part of the request-scoped memo key.
        $blog = (int)get_current_blog_id();
        $lang = (string)apply_filters('wpml_current_language', 'en');
        if (!preg_match('/^[a-z]{2}(?:-[a-z]{2})?$/iD', $lang)) $lang = 'en';
        $lang = strtolower($lang);
        $cacheable = ($_SERVER['REQUEST_METHOD'] ?? '') === 'GET'
            && !is_admin()
            && !is_user_logged_in()
            && !wp_doing_ajax()
            && !(defined('REST_REQUEST') && REST_REQUEST)
            && !(defined('WP_CLI') && WP_CLI)
            && !isset($_GET['company_brand_preview'])
            && !isset($_GET['company_brand_until'])
            && !isset($_GET['company_brand_token']);
        $memo_enabled = $cacheable && get_option('mad4b_native_brand_request_memo_v1','on') === 'on';
        $key = $blog.'|'.$lang.'|'.self::$epoch;
        if ($memo_enabled && isset(self::$memo[$key])) {
            do_action('mad4b/native-brand-context/memo-hit', $lang);
            return self::$memo[$key];
        }
        $o = self::native_options();
        $ids = [
            'logo_default' => self::asset_id($o['mad4b_brand_logo_media'] ?? ($o['company_brand_logo_default'] ?? 0)),
            'logo_inverse' => self::asset_id($o['mad4b_brand_logo_inverse_media'] ?? ($o['company_brand_logo_inverse'] ?? 0)),
            'logo_compact' => self::asset_id($o['mad4b_brand_emblem_media'] ?? 0),
            'logo_mobile' => self::asset_id($o['mad4b_brand_emblem_media'] ?? 0),
            'favicon' => self::asset_id($o['mad4b_brand_favicon_media'] ?? 0),
            'pattern' => self::asset_id($o['mad4b_brand_pattern_media'] ?? 0),
        ];
        if (!$ids['logo_inverse']) $ids['logo_inverse'] = $ids['logo_default'];
        if (!$ids['logo_mobile']) $ids['logo_mobile'] = $ids['logo_default'];
        if (!$ids['logo_compact']) $ids['logo_compact'] = $ids['logo_default'];
        $media = [];
        foreach ($ids as $name => $id) $media[$name] = self::asset_url($id);

        $native_name = $o['mad4b_brand_name'] ?? '';
        $name = is_string($native_name) && trim($native_name) !== ''
            ? sanitize_text_field($native_name) : (string)get_bloginfo('name');
        $colors = [
            'primary' => '', 'secondary' => '', 'accent' => '', 'text' => '',
            'muted' => '', 'surface' => '', 'border' => '', 'focus' => '',
        ];
        $kit = (int)get_option('elementor_active_kit',0);
        if ($kit > 0) {
            $settings = get_post_meta($kit,'_elementor_page_settings',true);
            if (is_array($settings)) {
                foreach ((array)($settings['system_colors'] ?? []) as $row) {
                    if (!is_array($row)) continue;
                    $role = $row['_id'] ?? '';
                    $value = $row['color'] ?? '';
                    if (isset($colors[$role]) && is_string($value)
                        && preg_match('/^#[0-9a-f]{6}$/iD',$value))
                        $colors[$role] = strtoupper($value);
                }
            }
        }
        $context = [
            'contract' => self::CONTRACT,
            'enabled' => (bool)($ids['logo_default'] || $ids['logo_inverse'] || $name !== ''),
            'profile' => 'site-native',
            'name' => $name,
            'language' => $lang,
            'revision' => (string)($o['company_brand_revision'] ?? '1'),
            'palette' => 'elementor-kit',
            'colors' => $colors,
            'fonts' => ['heading'=>'','body'=>'','arabic'=>''],
            'media' => $media,
            'media_ids' => $ids,
            'font_urls' => ['heading_regular'=>'','heading_italic'=>''],
            'font_face_family' => '',
            'radii' => ['button'=>24,'card'=>18,'input'=>10],
            'heading_scale' => 1.0, 'body_size' => 16,
            'density' => 'comfortable', 'motion' => 'native',
            'decoration' => 'subtle', 'preview' => false,
        ];
        if ($memo_enabled) {
            if (count(self::$memo) >= 16) self::$memo = [];
            self::$memo[$key] = $context;
        }
        do_action('mad4b/native-brand-context/built', $lang);
        return $context;
    }

    public static function setup(): void {
        foreach (['updated_option','added_option','deleted_option'] as $hook) {
            add_action($hook,static function($name): void {
                if (in_array((string)$name,[
                    'look-and-feel','elementor_active_kit','home','blogname',
                    'mad4b-presentation-experience_features_v1',
                    'mad4b_native_brand_request_memo_v1'
                ], true)) self::reset();
            }, 55, 1);
        }
        add_action('updated_post_meta', static function($mid,$post_id,$key): void {
            if ((string)$key === '_elementor_page_settings' &&
                (int)$post_id === (int)get_option('elementor_active_kit')) self::reset();
        },55,3);
    }
}

// At plugins_loaded late priority the original platform provider (if installed)
// has already had a chance to register. Never declare duplicate global functions.
add_action('plugins_loaded', static function(): void {
    if (function_exists('company_brand_context')) return;
    // Future activation of the original platform must not clash with this
    // fallback even if its brand module is included at a later hook.
    $platform = WP_PLUGIN_DIR.'/mad4b-platform/modules/operations/company-brand-context.php';
    if (is_file($platform)) {
        $active = (array)get_option('active_plugins',[]);
        $network = (array)get_site_option('active_sitewide_plugins',[]);
        if (in_array('mad4b-platform/mad4b-platform.php',$active,true) ||
            isset($network['mad4b-platform/mad4b-platform.php'])) return;
    }
    function company_brand_context(): array {
        return MAD4B_Experience_Native_Brand_Context_V1::current();
    }
    MAD4B_Experience_Native_Brand_Context_V1::setup();
}, 110);
