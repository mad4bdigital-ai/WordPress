<?php
/**
 * Plugin Name: MAD4B Mobile External CSS Async — Local
 * Description: Defer only an existing, verified single LiteSpeed CSS bundle on mobile. Retain all source CSS/DOM/JetEngine rules.
 * Version: 0.3.0
 */
defined('ABSPATH') || exit;

final class MAD4B_Mobile_External_CSS_Async {
    private const POLICY = 'mad4b-mobile-external-css-async-policy.json';
    private static ?array $policy = null;
    private static bool $probe = false;

    public static function policy(): array {
        if (self::$policy !== null) return self::$policy;
        $p = WPMU_PLUGIN_DIR . '/' . self::POLICY;
        if (!is_file($p) || is_link($p) || filesize($p) > 4096) return self::$policy = [];
        $cfg = json_decode((string)file_get_contents($p), true);
        $host = strtolower((string)wp_parse_url(home_url('/'), PHP_URL_HOST));
        if (!is_array($cfg)
            || ($cfg['contract']??'') !== 'mad4b.mobile-external-css-async.v1'
            || ($cfg['site_host']??'') !== $host
            || ($cfg['environment']??'') !== wp_get_environment_type()
            || !in_array(($cfg['status']??''), ['canary','active'], true)
            || ($cfg['scope']??'') !== 'homepage_mobile'
            || !is_array($cfg['languages']??null)
            || !$cfg['languages']) return self::$policy = [];
        return self::$policy = $cfg;
    }

    private static function allowed(): bool {
        $cfg = self::policy();
        $trusted=(string)($_SERVER['MAD4B_TRUSTED_DEVICE_CLASS']??'');
        // An upstream shared HTML cache may use a desktop key. Never emit a
        // mobile-only markup variant into a desktop cache bucket.
        if ($trusted!=='' && $trusted!=='mobile')return false;
        $isProbe=(string)($_SERVER['QUERY_STRING']??'')==='mad4b_mobile_css_async_probe=1'
            && ($_SERVER['HTTP_X_MAD4B_CSS_ASYNC']??'')==='v1'
            && ($_SERVER['HTTP_X_MAD4B_BYPASS_CACHE']??'')==='1';
        if (!$cfg || !wp_is_mobile() || is_admin() || wp_doing_ajax()
            || is_user_logged_in() || is_preview()
            || (defined('REST_REQUEST') && REST_REQUEST)
            || (defined('DOING_CRON') && DOING_CRON)
            || (defined('WP_CLI') && WP_CLI)
            || ($_SERVER['REQUEST_METHOD']??'') !== 'GET'
            || !is_front_page()
            || !empty($_SERVER['HTTP_AUTHORIZATION'])
            || !empty($_SERVER['HTTP_X_MAD4B_CURRENCY'])
            || !empty($_SERVER['HTTP_X_MAD4B_MARKET_CONTEXT'])
            || !empty($_SERVER['HTTP_X_GEO_COUNTRY'])
            || !empty($_SERVER['HTTP_CF_IPCOUNTRY'])
            || !empty($_SERVER['HTTP_X_WP_NONCE'])
            || (defined('DONOTCACHEPAGE') && DONOTCACHEPAGE && !$isProbe)
            || !in_array((string)apply_filters('wpml_current_language',''),
                $cfg['languages'], true)) return false;
        // Never operate on a visitor's pricing, cart, market or preference context.
        if (!empty($_SERVER['HTTP_COOKIE'])) return false;
        $language=(string)apply_filters('wpml_current_language','');
        // WPML may populate $_COOKIE during the request without an inbound
        // browser Cookie. Accept ONLY the matching internal locale value.
        foreach ((array)$_COOKIE as $name=>$value) {
            if ($name!=='wp-wpml_current_language'
                || !is_string($value) || $value!==$language) return false;
        }
        if ((string)get_option('litespeed.conf.optm-css_async','0') === '1'
            || (string)get_option('litespeed.conf.optm-ucss','0') === '1') return false;
        if ((string)get_option('mad4b_perf_critical_css_v1','off') === 'on'
            || (string)get_option('mad4b_perf_predictive_css_v2','off') === 'on') return false;
        if ((string)get_option('mad4b_mobile_external_css_async_v1','1') === '0') return false;

        $query = (string)($_SERVER['QUERY_STRING'] ?? '');
        if ($cfg['status'] === 'active') {
            // Any user query bypasses automatic rewriting.
            return $query === '' && ($_SERVER['HTTP_X_MAD4B_CSS_ASYNC']??'') !== 'native';
        }
        self::$probe = ($query === '' || $query === 'mad4b_mobile_css_async_probe=1')
            && ($_SERVER['HTTP_X_MAD4B_CSS_ASYNC']??'') === 'v1'
            && ($_SERVER['HTTP_X_MAD4B_BYPASS_CACHE']??'') === '1';
        return self::$probe;
    }

    public static function transform($html) {
        if (!is_string($html) || strlen($html) < 1000
            || strlen($html) > 6000000 || !self::allowed()) return $html;
        $headEnd = stripos($html, '</head>');
        if ($headEnd === false) return $html;
        if (stripos($html, 'data-mad4b-mobile-async="v1"') !== false
            || stripos($html, 'mad4b-css-rest-v1') !== false
            || stripos($html, 'mad4b-predictive-full-css') !== false) return $html;

        // Enforce one, same-origin, generated LiteSpeed CSS bundle.
        // No inline <style> tags, generated listing CSS, or Elementor DOM touched.
        preg_match_all('~<link\b[^>]*>~i', substr($html,0,$headEnd),
            $found, PREG_OFFSET_CAPTURE);
        $matches = [];
        foreach ($found[0] as [$tag,$pos]) {
            if (!preg_match('~\brel=["\x27]stylesheet["\x27]~i',$tag)) continue;
            if (!preg_match('~\bhref=["\x27]([^"\x27]+)["\x27]~i',$tag,$url)) continue;
            $src = html_entity_decode($url[1],ENT_QUOTES|ENT_HTML5,'UTF-8');
            $path = (string)wp_parse_url($src,PHP_URL_PATH);
            $host = (string)wp_parse_url($src,PHP_URL_HOST);
            if (!preg_match('~^/wp-content/litespeed/css/[a-f0-9]{32}\.css$~D',$path)
                || strcasecmp($host,(string)wp_parse_url(home_url('/'),PHP_URL_HOST))!==0) continue;
            if (preg_match('~\b(?:integrity|crossorigin|disabled|onload|media)=~i',$tag)) continue;
            $matches[] = [$tag,$pos,$src];
        }
        if (count($matches)!==1) return $html;
        [$tag,$pos,$url] = $matches[0];

        // Keep exact URL, including LiteSpeed's version query. The CSS
        // remains external, cacheable, and identical to the native stylesheet.
        if ((self::policy()['inline_css_async_lib'] ?? false) !== true) return $html;
        $safe = esc_url($url);
        // LiteSpeed's own 1.3 KB async CSS compatibility library, inline
        // only for the already audited mobile/homepage CSS transformation.
        // This is JavaScript, NOT inline CSS or DOM Optimization.
        $lib = WP_PLUGIN_DIR . '/litespeed-cache/assets/js/css_async.min.js';
        if (!is_file($lib) || !is_readable($lib) || is_link($lib)
            || filesize($lib) > 4096 || filesize($lib) < 500) return $html;
        $js = (string) file_get_contents($lib);
        if (stripos($js, '</script') !== false
            || strpos($js, 'loadCSS') === false
            || strpos($js, 'relpreload') === false) return $html;
        $link = '<script data-mad4b-css-async-lib="litespeed-inline-v1">'
              . $js . '</script>';
        // Use native LiteSpeed preload/onload semantics. The native inline
        // library polyfills preload on older browsers; noscript is fallback.
        // Only ONE active CSS stylesheet is created, preventing duplicates.
        $link .= '<link rel="preload" as="style" fetchpriority="high" href="' . $safe
              . '" onload="this.onload=null;this.rel=&quot;stylesheet&quot;"'
              . ' data-mad4b-mobile-async="v1" />';
        $link .= '<noscript>' . $tag . '</noscript>';
        return substr_replace($html,$link,$pos,strlen($tag));
    }

    public static function boot(): void {
        // After MAD4B CSS owners (999/1001), no parallel optimizer.
        add_filter('litespeed_buffer_finalize',[__CLASS__,'transform'],12001,1);
    }
}
add_action('init',['MAD4B_Mobile_External_CSS_Async','boot'],44);
