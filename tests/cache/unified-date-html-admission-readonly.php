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
// impact_preview() is deliberately capped at 50 rows and labels its
// coverage partial even when a separate exact census could prove completeness.
// Cache admission must use the uncapped, read-only census instead.
$census = $depReady
    ? MAD4B_Page_Dependency_Cache::impact_census('post:60629')
    : ['status' => 'schema_not_ready'];
$exact = ($census['status'] ?? '') === 'ok';
$unknown = $exact ? (int) ($census['unknown_coverage_nodes'] ?? PHP_INT_MAX) : PHP_INT_MAX;
$nodes = $exact ? (int) ($census['total_nodes'] ?? 0) : 0;
$complete = $depReady && $exact && $nodes > 0 && $unknown === 0
    && (($census['all_nodes_proven_complete'] ?? false) === true);
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
    'impact_total_nodes' => $nodes,
    'impact_unknown_nodes' => $unknown,
    'impact_groups' => $census['groups'] ?? [],
    'impact_preview_truncated' => $selection['dependency_impact']['truncated'] ?? null,
    'selective_purge_socket_present' => $socket,
    'admit_shared_html' => $admit,
    'action' => $admit ? 'verify_served_variants_before_activation' : 'keep_private_no_store',
];
echo wp_json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), PHP_EOL;
