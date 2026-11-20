<?php
/**
 * Plugin Name: Local QA — Dynamic Filter SEO Bridge Tour Archive
 * Description: Read-only, query-opt-in Elementor/JetSmartFilters preview; not an SEO or commerce release.
 */
defined('ABSPATH') || exit;
if (!function_exists('wp_get_environment_type') || wp_get_environment_type() !== 'local') { return; }

$dfsb_qa_flag = isset($_GET['dfsb_qa']) && (string) $_GET['dfsb_qa'] === '1';

// Local-only canonical QA entrypoint. The pretty archive remains untouched
// for real visitors. JSF signed native queries must use exactly one preview
// referrer shape, so experimental pretty URLs redirect to the certified
// root-query QA fixture; never rewrite JSF signatures or loosen gateway ACLs.
if ($dfsb_qa_flag
    && in_array((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'), ['GET','HEAD'], true)
    && parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) === '/tours-and-activities/'
    && count($_GET) === 1) {
    add_action('template_redirect', static function () {
        wp_safe_redirect('/?post_type=tours-and-activities&dfsb_qa=1', 302, 'DFSB Local QA');
        exit;
    }, 0);
}
// Hide the experimental Elementor block on ALL ordinary archive visits.
add_action('wp_head', static function () {
    echo '<style id="dfsb-qa-default-hide">.dfsb-qa-only{display:none!important}</style>';
}, 10);
// Local Lab: read-only dynamic presentation for the exact Tours Archive.
// Commercial SEO publication, write routes and the persisted Safe Boot hold
// remain disabled. This deliberately does not enable DFSB on other pages.
$dfsb_method = (string) ($_SERVER['REQUEST_METHOD'] ?? 'GET');
$dfsb_path = parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
$dfsb_path = is_string($dfsb_path) ? $dfsb_path : '/';
// Direct JetSmartFilters deep links must be recognised BEFORE the plugin's
// guarded bootstrap. Only the registered tour archive/query and an exact,
// bounded native taxonomy path qualify. No generic /jsf/ route is accepted.
$dfsb_deep_archive = strlen($dfsb_path) <= 750 && (bool) preg_match(
    '~^/tours-and-activities/jsf/jet-engine:tours_query_archive/tax/(?:destinations|tour-types|tour-styles):[a-z0-9_-]{1,80}(?:;(?:destinations|tour-types|tour-styles):[a-z0-9_-]{1,80}){0,2}/$~D',
    $dfsb_path
);
$dfsb_archive_get = in_array($dfsb_method, ['GET', 'HEAD'], true)
    && (($dfsb_path === '/' && ($_GET['post_type'] ?? '') === 'tours-and-activities')
      || $dfsb_path === '/tours-and-activities/'
      || $dfsb_deep_archive);
$dfsb_preview_post = $dfsb_qa_flag && $dfsb_method === 'POST'
    && in_array($dfsb_path, [
        '/wp-json/dfsb/v1/ajax-presentation',
        '/wp-json/etg-dfsb/v1/ajax-presentation',
    ], true);
if (!$dfsb_archive_get && !$dfsb_preview_post) { return; }

// Exact Local Lab archive only: avoid cached frontend state and JS combine
// hiding the dynamic presentation events. No other archive has this exception.
if (!defined('DONOTCACHEPAGE')) { define('DONOTCACHEPAGE', true); }
add_filter('litespeed_can_cache', '__return_false', PHP_INT_MIN);
add_filter('litespeed_optimize', '__return_false', PHP_INT_MIN);
add_action('init', static function () {
    do_action('litespeed_control_set_nocache', 'dfsb_archive_qa');
}, 0);
add_action('send_headers', static function () {
    nocache_headers();
}, PHP_INT_MAX);


add_filter('etg_filter_seo_configuration', static function ($config) {
    if (!is_array($config)) { return $config; }
    $profiles = json_decode((string) ($config['profiles_json'] ?? '[]'), true);
    if (!is_array($profiles)) { return $config; }
    foreach ($profiles as &$profile) {
        if (!is_array($profile) || ($profile['id'] ?? '') !== 'tours') { continue; }
        $profile['enabled'] = true; // Only per-request presentation profile, never stored.
        $profile['required_capabilities'] = ['presentation.dynamic_content'];
        $publication = (array) ($profile['publication'] ?? []);
        foreach (['metadata','sitemap','schema','social','hreflang','multilingual',
            'include_images_in_sitemap'] as $key) { $publication[$key] = false; }
        $publication['require_elementor_content'] = true;
        $publication['elementor_render_when_global_off'] = true;
        $profile['publication'] = $publication;
    }
    unset($profile);
    $config['enabled'] = false; // Never authorize SEO publication or changed canonicals.
    $config['profiles_json'] = wp_json_encode($profiles);
    return $config;
}, 5);

// A direct /jsf/ archive link can have multiple selected facets before
// JetSmartFilters hydrates all checkbox controls. On this one Local Lab URL,
// reconcile *native* filter inputs with the native URL once; do not write a
// synthetic count or directly tamper with filterGroups.currentQuery.
if ($dfsb_archive_get && $dfsb_deep_archive && 'GET' === $dfsb_method) {
    add_action('wp_footer', static function () {
        ?>
        <script id="dfsb-lab-native-facet-hydration">
        (function () {
            'use strict';
            var path = window.location.pathname || '';
            var match = path.match(/^\/tours-and-activities\/jsf\/jet-engine:tours_query_archive\/tax\/([^/?#]{1,520})\/$/);
            if (!match) { return; }
            var expected = [], seen = Object.create(null);
            match[1].split(';').forEach(function (part) {
                var colon = part.indexOf(':');
                if (colon === -1) { expected = []; return; }
                var tax = part.slice(0, colon), slug = part.slice(colon + 1);
                if (!/^(?:destinations|tour-types|tour-styles)$/.test(tax)
                    || !/^[a-z0-9_-]{1,80}$/.test(slug) || seen[tax]) { expected = []; return; }
                seen[tax] = true; expected.push({ tax: tax, slug: slug });
            });
            if (!expected.length || expected.length > 3) { return; }
            var done = false, attempts = 0;
            function hydrate() {
                if (done || ++attempts > 12) { return; }
                var group = window.JetSmartFilters && window.JetSmartFilters.filterGroups
                    && window.JetSmartFilters.filterGroups['jet-engine/tours_query_archive'];
                if (!group) { window.setTimeout(hydrate, 140); return; }
                var missing = [], unavailable = false;
                expected.forEach(function (f) {
                    var inputs = Array.prototype.slice.call(document.querySelectorAll(
                        'input[name="' + f.tax + '"][data-url-value="' + f.slug + '"]'
                    ));
                    if (!inputs.length) { unavailable = true; return; }
                    if (!inputs.some(function (x) { return x.checked; })) { missing.push(inputs[0]); }
                });
                if (unavailable) { window.setTimeout(hydrate, 140); return; }
                done = true;
                // Native click is essential: JetSmartFilters itself creates
                // the provider query, result list, URL events and counters.
                missing.forEach(function (input, index) {
                    window.setTimeout(function () { if (!input.checked) { input.click(); } }, index * 85);
                });
            }
            document.addEventListener('jet-smart-filters/inited', function () {
                window.setTimeout(hydrate, 80);
            }, { once: true });
            window.setTimeout(hydrate, 180);
        }());
        </script>
        <?php
    }, 99);
}

// The original release remains HELD. Bootstrap exclusively this read-only
// QA request, without changing BootGuard status or registered package identity.
add_action('plugins_loaded', static function () {
    if (class_exists('DynamicFilterSEOBridge\\Bootstrap')) {
        DynamicFilterSEOBridge\Bootstrap::instance()->boot();
    }
}, 25);

add_filter('rest_url', static function ($url, $path) {
    if (in_array(trim((string) $path, '/'), ['etg-dfsb/v1/ajax-presentation','dfsb/v1/ajax-presentation'], true)) {
        return add_query_arg('dfsb_qa', '1', str_replace('/wp-json/etg-dfsb/v1/ajax-presentation', '/wp-json/dfsb/v1/ajax-presentation', $url));
    }
    return $url;
}, 20, 2);

if ($dfsb_archive_get && $dfsb_qa_flag) {
    add_action('wp_head', static function () {
        echo '<style id="dfsb-qa-archive-style">.dfsb-qa-only{display:block!important}</style>';
    }, 99);
}

// QA uses the exact packaged JavaScript now. No version override, no forked
// asset and no hardcoded archive-path mapping. Runtime presentation remains
// non-authorizing and safe boot remains held outside the QA request.
