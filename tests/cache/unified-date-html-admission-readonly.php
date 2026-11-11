<?php
/**
 * Read-only date-page HTML cache admission audit.
 *
 * Run from the enrolled staging site:
 * wp eval-file /path/to/tests/cache/unified-date-html-admission-readonly.php --allow-root
 *
 * Never issues a purge, changes a WordPress option, sets a cache header,
 * or asserts that CLI eligibility equals actual FastCGI eligibility.
 */
if (!defined('ABSPATH')) {
    fwrite(STDERR, "WORDPRESS_REQUIRED\n");
    exit(2);
}
$site = (string) get_option('home', '');
$mode = (string) get_option('mad4b_public_page_origin_cache_v1', 'canary');
$verified = get_option('mad4b_cache_lifecycle_edge_isolation_verified_v1', false) === true;
$policy = class_exists('MAD4B_Company_Browser_HTML_Policy', false)
    && MAD4B_Company_Browser_HTML_Policy::enabled();
$depReady = class_exists('MAD4B_Page_Dependency_Cache', false)
    && MAD4B_Page_Dependency_Cache::ready();
$socket = is_file(WP_CONTENT_DIR . '/.mad4b-purge/origin.sock')
    || file_exists(WP_CONTENT_DIR . '/.mad4b-purge/origin.sock');

$selection = MAD4B_Cache_Control_V1::preview_target('post:60629');
$impact = is_array($selection) ? ($selection['dependency_impact'] ?? []) : [];
$unknown = (int) ($impact['unknown_nodes'] ?? PHP_INT_MAX);
$truncated = (bool) ($impact['truncated'] ?? true);
$exact = ($impact['exact_census_ready'] ?? false) === true;
$complete = $depReady && $exact && !$truncated && $unknown === 0
    && (($impact['coverage'] ?? '') !== 'partial_unknown_requires_legacy_fallback');
$admit = $verified && $mode === 'on' && $policy && $complete && $socket;
$data = [
    'contract' => 'mad4b.date-html-cache-admission.audit.v1',
    'read_only' => true,
    'tour_id' => 60629,
    'site' => (string) wp_parse_url($site, PHP_URL_HOST),
    'mode' => $mode,
    'edge_isolation_verified' => $verified,
    'browser_policy_enabled' => $policy,
    'page_dependency_ready' => $depReady,
    'impact_exact_census_ready' => $exact,
    'impact_unknown_nodes' => $unknown,
    'impact_truncated' => $truncated,
    'impact_coverage' => $impact['coverage'] ?? 'unavailable',
    'selective_purge_socket_present' => $socket,
    'admit_shared_html' => $admit,
    'action' => $admit ? 'verify_served_variants_before_activation' : 'keep_private_no_store',
];
echo wp_json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), PHP_EOL;

