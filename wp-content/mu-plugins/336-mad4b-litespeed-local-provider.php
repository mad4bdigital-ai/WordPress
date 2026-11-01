<?php
/**
 * Plugin Name: MAD4B Local-First LiteSpeed Compatibility Authority
 * Version: 0.1.0
 * Description: Coordinates MAD4B's certified, device-aware CSS overlays with LiteSpeed Cache without changing LSCWP files or replacing its native optimizer.
 */
defined('ABSPATH') || exit;

final class MAD4B_LSC_Local_Provider_V1 {
    const SITE = 'tours.dev.mad4b.com';
    const UUID = 'e5e64bca-ed62-48d2-b2b3-dff693cd702a';
    const CONTRACT = 'mad4b.lsc.local-first.v1';
    const OPT = 'mad4b_lsc_css_provider_v1';
    const DEFAULT_MODE = 'local_first';

    public static function scope(): bool {
        if (wp_get_environment_type() !== 'staging'
            || (string)wp_parse_url(home_url(), PHP_URL_HOST) !== self::SITE) {
            return false;
        }
        $profile = get_option('mad4b_scp_brand_context_profile_v1', []);
        return is_array($profile) && ($profile['site_uuid'] ?? '') === self::UUID;
    }

    public static function mode(): string {
        if (!self::scope()) return 'native';
        $value = (string)get_option(self::OPT, self::DEFAULT_MODE);
        return in_array($value, ['local_first', 'native'], true) ? $value : 'native';
    }

    public static function local_css_eligible(): bool {
        if (!self::scope() || self::mode() !== 'local_first') return false;
        if (!defined('LSCWP_V') || LSCWP_V !== '7.9.2'
            || !defined('ELEMENTOR_VERSION') || ELEMENTOR_VERSION !== '4.3.5') return false;
        if ((string)get_option('litespeed.conf.optm-css_comb', '0') !== '1'
            || (string)get_option('litespeed.conf.cache-mobile', '0') !== '1') return false;
        // A genuine QUIC CSS optimizer is mutually exclusive with MAD4B CSS deferral.
        if ((string)get_option('litespeed.conf.optm-ucss', '0') === '1'
            || (string)get_option('litespeed.conf.optm-css_async', '0') === '1'
            || (string)get_option('litespeed.conf.guest_optm', '0') === '1') return false;
        if ((string)get_option('mad4b_css_priority_staging_en_ar_v1', '0') === '1') return false;
        return true;
    }

    public static function legacy_css_allowed(): bool {
        // The older experimental MU334 has overlapping selectors and must not be
        // co-active with the local-first CSS runtime. Existing LS CSS Combine remains on.
        return self::mode() === 'native';
    }

    public static function capabilities(): array {
        if (!self::scope()) return ['scope' => 'unavailable'];
        return [
            'contract' => self::CONTRACT,
            'scope' => 'staging',
            'mode' => self::mode(),
            'css_combine_owner' => 'litespeed_cache',
            'device_cache_owner' => 'litespeed_cache_and_nginx',
            'candidate_and_certification_owner' => 'mad4b_local_worker',
            'runtime_css_overlay' => self::local_css_eligible() ? 'eligible_certified_only' : 'native_litespeed',
            'remote_css_optimization' => 'mutually_exclusive_fail_closed',
            'quic_services' => [
                // These remain in native LSCWP/QUIC until a locally VERIFIED functional
                // replacement exists. Never pretend MU CSS replaces CDN or image transcoding.
                'cdn' => (string)get_option('litespeed.conf.cdn-quic', '0') === '1' ? 'litespeed_passthrough' : 'off',
                'image_optimization' => (string)get_option('litespeed.conf.img_optm-auto', '0') === '1' ? 'litespeed_passthrough' : 'off',
                'viewport_images' => (string)get_option('litespeed.conf.media-vpi', '0') === '1' ? 'litespeed_passthrough' : 'off',
                'ccss' => (string)get_option('litespeed.conf.optm-css_async', '0') === '1' ? 'litespeed_selected_mad4b_native' : 'not_running',
                'ucss' => (string)get_option('litespeed.conf.optm-ucss', '0') === '1' ? 'litespeed_selected_mad4b_native' : 'not_running',
            ],
            'no_plugin_source_mutation' => true,
        ];
    }

    public static function boot(): void {
        if (!self::scope()) return;
        // No duplicate remote job, generator, HTTP hook or image replacement.
        // LSCWP owns its native CSS combine. MAD4B CSS overlay attaches only
        // after it has certified source, device, language, cache, and vitals.
        add_action('wp', [__CLASS__, 'enforce_single_css_owner'], 104);
    }

    public static function enforce_single_css_owner(): void {
        if (self::mode() !== 'local_first') return;
        // A user switching on QUIC UCSS/async at any time gets native LSCWP,
        // never two CSS generators or competing asynchronous CSS treatments.
        if (!self::local_css_eligible()) {
            do_action('mad4b/litespeed/compat_fallback', [
                'contract' => self::CONTRACT,
                'reason' => 'local_css_ineligible_or_quic_css_active',
            ]);
        }
    }
}
add_action('plugins_loaded', ['MAD4B_LSC_Local_Provider_V1', 'boot'], 30);
