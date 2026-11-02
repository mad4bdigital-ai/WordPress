<?php
/**
 * Read-only WordPress integration acceptance:
 * wp eval-file wp-content/plugins/dynamic-filter-seo-bridge/tests/runtime-layers/multilingual-integration-wp-cli.php
 * Never creates publications, alters options, translates posts or flushes rewrites.
 */
if (!defined('ABSPATH')) { exit(2); }

$failures = array();
$check = static function (bool $ok, string $label) use (&$failures): void {
    echo ($ok ? 'PASS ' : 'FAIL ') . $label . PHP_EOL;
    if (!$ok) $failures[] = $label;
};

$policy = class_exists('MAD4B_SCP_DFSB_Environment_Policy');
$check($policy, 'Control Plane policy registered');
if ($policy) {
    $snapshot = MAD4B_SCP_DFSB_Environment_Policy::snapshot();
    $check(!empty($snapshot['effective_environment']), 'Effective environment resolved');
    $check(array_key_exists('publication', $snapshot) && array_key_exists('managed_routes', $snapshot),
        'Independent SEO publication and managed-route switches');
}

$cfg = new \DynamicFilterSEOBridge\Config\Configuration();
$profiles = new \DynamicFilterSEOBridge\Config\ProfileRegistry($cfg);
$profile = $profiles->get('tours');
$languages = new \DynamicFilterSEOBridge\WPML\LanguageResolver();
$active = $languages->activeLanguages();
$check(!empty($active), 'Active WPML languages registered');
$check(!empty($profile), 'Tours profile present');
if ($active && $profile) {
    $parser = new \DynamicFilterSEOBridge\JetSmartFilters\FilterUrlParser($profiles->allowedTaxonomies());
    $routes = (array) ($profile['routes'] ?? array());
    $route = count($routes) === 1 ? (array) reset($routes) : array();
    $provider = (string) ($route['provider'] ?? '');
    $query = (string) ($route['provider_query_id'] ?? $route['query_id'] ?? '');
    $check((bool) $provider && (bool) $query, 'Single native query authority');
    foreach (array_keys($active) as $lang) {
        $prefix = $lang === $languages->defaultLanguage() ? '/' : '/' . $lang . '/';
        $url = $prefix . 'tours-and-activities/jsf/' . $provider . ':' . $query . '/tax/destinations:egypt/';
        $scope = $profiles->resolveForEvidence($parser->parse($url));
        $check(!empty($scope['in_scope']) && !empty($scope['scope_valid']), 'Archive profile: ' . $lang);
    }
}

$native = \DynamicFilterSEOBridge\SEO\NativeFeaturePriority::inspect();
$seoEnabled = function_exists('jet_smart_filters') && !empty(jet_smart_filters()->settings->is_seo_enabled);
$check(($native['overlapping_seo_fallback_allowed'] ?? null) === !$seoEnabled,
    'Rank Math fallback tracks active JetSmartFilters SEO engine');
if (function_exists('shared_presentation_provider_v2')) {
    $descriptor = shared_presentation_provider_v2('tours.archive');
    $check(is_array($descriptor) && ($descriptor['provider'] ?? '') === 'jet-engine/tours_query_archive',
        'Native Shared Product Core provider');
    $check(class_exists('Presentation_Policy_Context_v1') &&
        !empty(Presentation_Policy_Context_v1::decide('tours-and-activities', 'archive', 'discover')['allowed']),
        'MU Presentation archive policy');
}
if (function_exists('jet_smart_filters')) {
    $aliases = jet_smart_filters()->settings->get('url_aliases');
    $check(!\DynamicFilterSEOBridge\IndexFlow\ManagedRoutesRuntime::aliasConflicts((array)$aliases,
        array('/tours-and-activities/jsf/jet-engine:tours_query_archive/tax/destinations:egypt/')),
        'Current native URL aliases preserve technical archive route');
}

$term = get_term_by('slug', 'egypt', 'destinations');
if ($term instanceof \WP_Term && $active) {
    $trid = apply_filters('wpml_element_trid', null, $term->term_id, 'tax_destinations');
    $translations = (array) apply_filters('wpml_get_element_translations', null, $trid, 'tax_destinations');
    foreach (array_keys($active) as $lang) {
        if (empty($translations[$lang]->element_id)) continue;
        $expectedId = (int)$translations[$lang]->element_id;
        $resolved = $languages->resolve($term, 'destinations', $lang);
        $check(empty($resolved['translation_fallback']) &&
            ($resolved['term'] ?? null) instanceof \WP_Term &&
            (int)$resolved['term']->term_id === $expectedId, 'WPML term translation: ' . $lang);
    }
}

$decision = (new \DynamicFilterSEOBridge\SEO\IndexingPolicy($cfg))->decide(array('active' => true));
$check(!$policy || MAD4B_SCP_DFSB_Environment_Policy::allows('publication')
    || ($decision['reason'] ?? '') === 'control_plane_publication_disabled',
    'SEO indexing denied when Control Plane publication is OFF');
if ($failures) {
    fwrite(STDERR, implode(', ', $failures) . PHP_EOL);
    exit(1);
}
echo 'COMPLETE multilingual-readonly-integration' . PHP_EOL;
