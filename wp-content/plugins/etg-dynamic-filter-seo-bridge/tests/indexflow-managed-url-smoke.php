<?php
/** Executable URL laws and denial cases; fixtures are not a live certificate. */
set_error_handler(static function ($severity, $message, $file, $line) { throw new ErrorException($message, 0, $severity, $file, $line); });
spl_autoload_register(static function ($class) {
    $prefix = 'ETG\\DynamicFilterSEOBridge\\';
    if (0 === strpos($class, $prefix)) require_once dirname(__DIR__) . '/includes/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
});
use ETG\DynamicFilterSEOBridge\IndexFlow\ManagedUrlArchitecture;
use ETG\DynamicFilterSEOBridge\IndexFlow\NormalizedFilterState;
use ETG\DynamicFilterSEOBridge\IndexFlow\NativeFilterUrlAdapter;
use ETG\DynamicFilterSEOBridge\IndexFlow\SeoOwnershipResolver;
use ETG\DynamicFilterSEOBridge\IndexFlow\WordPressRouteResolver;

function sanitize_key($s) { return preg_replace('/[^a-z0-9_-]/', '', strtolower($s)); }
function sanitize_title($s) { return strtolower(trim($s)); }
function sanitize_text_field($s) { return $s; }
function apply_filters($name, $value) { return $value; }
function has_filter($name) { return false; }
function is_wp_error($value) { return false; }
function get_terms($args) {
    $out = array();
    foreach ($GLOBALS['terms'][$args['taxonomy']] ?? array() as $id => $row) if ($row[2] === $args['slug']) $out[] = (object)array('term_id' => $id);
    return $out;
}
$checks = 0;
function expect($condition, $message) { $GLOBALS['checks']++; if (!$condition) throw new RuntimeException($message); }
function denied(callable $callback, string $message) {
    try { $callback(); } catch (InvalidArgumentException $e) { expect(true, $message); return; }
    throw new RuntimeException('Denial missing: ' . $message);
}
$GLOBALS['terms'] = array('location' => array(1 => array('en', 1, 'cairo'), 11 => array('ar', 1, 'القاهرة')),
    'style' => array(2 => array('en', 2, 'luxury'), 22 => array('ar', 2, 'فاخر')));
$term = static function ($taxonomy, $id, $locale) {
    $row = $GLOBALS['terms'][$taxonomy][$id] ?? array();
    return $row && $row[0] === $locale ? array('term_id' => $id, 'semantic_id' => $row[1], 'slug' => $row[2]) : array();
};
$page = static function ($id, $locale) { return in_array($id, array(10, 20), true) ? array('id' => $id, 'url' => 'https://example.test/' . $locale . '/template-' . $id . '/') : array(); };
$collision = static function ($url, $id) { return array(); };
$native = new NativeFilterUrlAdapter($term);
$make = static function ($doc) use ($term, $page, $collision, $native) {
    return new ManagedUrlArchitecture($doc, 'https://example.test', $term, $page, $collision, array($native, 'dispatch'));
};
$state = array('profile_id' => 'tours', 'provider' => 'jet-engine', 'query_id' => 'Tours_01', 'locale' => 'en', 'filters' => array('style' => array(2), 'location' => array(1)));
$translated = array_replace($state, array('locale' => 'ar', 'filters' => array('location' => array(11), 'style' => array(22))));
$doc = array('version' => 1, 'profiles' => array('tours' => array('strategy' => 'published_seo', 'provider' => 'jet-engine', 'query_id' => 'Tours_01',
    'bases' => array('en' => '/en/tours/', 'ar' => '/ar/رحلات/'), 'dimensions' => array(
        array('name' => 'location', 'taxonomy' => 'location', 'required' => true), array('name' => 'style', 'taxonomy' => 'style', 'required' => false)))),
    'routes' => array(array('id' => 'cairo-en', 'source' => 'virtual', 'status' => 'published', 'object_id' => 10, 'seo_owner' => 'rank_math', 'state' => $state),
        array('id' => 'cairo-ar', 'source' => 'virtual', 'status' => 'published', 'object_id' => 20, 'seo_owner' => 'rank_math', 'state' => $translated)));
$service = $make($doc);
$normalized = $service->normalize($state);
expect($normalized === $service->normalize($normalized), 'Normalization is idempotent');
expect($normalized['identity'] === $service->normalize($translated)['identity'], 'Translated real IDs share semantic identity');
$ordered = $state; $ordered['filters'] = array_reverse($state['filters'], true);
expect($service->normalize($ordered) === $normalized, 'Input order does not change identity');
$changed = $state; $changed['query_id'] = 'other';
denied(static function () use ($service, $changed) { $service->normalize($changed); }, 'Provider/query exact binding');
denied(static function () use ($service, $state) { $service->build($state, 'ar'); }, 'Cannot reuse English term IDs in Arabic');
foreach (array($state, $translated) as $input) {
    $url = $service->build($input, $input['locale']);
    expect($service->normalize($service->resolve($url)['state']) === $service->normalize($input), 'normalize(resolve(build)) law');
    expect($service->canonicalize($service->canonicalize($url)) === $url, 'Canonical idempotence');
    expect($service->canonicalize($url . '?utm_source=test') === $url, 'Tracking stripped safely');
}
foreach (array('https://evil.test/en/tours/', '//evil.test/en/tours/', '/en/tours/location/no-route/', '/en/tours/%2fadmin/', '/en/tours/%252fadmin/', '/en/tours/../admin/', '/wp-json/routes/', '/en/tours//location/cairo/') as $url) {
    denied(static function () use ($service, $url) { $service->resolve($url); }, 'Unknown, foreign or unsafe path rejected');
}
$url = $service->build($state, 'en');
foreach (array('?paged=2', '?sort=price', '?filter[]=x') as $query) denied(static function () use ($service, $url, $query) { $service->canonicalize($url . $query); }, 'No state-loss canonical');
$copy = $doc; $copy['routes'][] = array_replace($doc['routes'][0], array('id' => 'duplicate', 'source' => 'native_page'));
denied(static function () use ($make, $copy) { $make($copy); }, 'Native and virtual cannot duplicate identity');
$copy = $doc; $copy['profiles']['tours']['dimensions'][] = $copy['profiles']['tours']['dimensions'][0];
denied(static function () use ($make, $copy) { $make($copy); }, 'Duplicate dimension rejected');
$copy = $doc; $copy['routes'][0]['state']['filters']['style'] = array(2, 22);
denied(static function () use ($make, $copy) { $make($copy); }, 'Missing translation rejected');
$copy = $doc; $copy['routes'][0]['object_id'] = 999;
denied(static function () use ($make, $copy) { $make($copy); }, 'Missing template rejected');
$copy = $doc; $copy['routes'][0]['state']['filters']['outside'] = array(1);
denied(static function () use ($make, $copy) { $make($copy); }, 'Unknown dimension rejected');
$copy = $doc; $copy['routes'][0]['status'] = 'approved'; $draft = $make($copy);
denied(static function () use ($draft, $state) { $draft->build($state, 'en'); }, 'Approved is not published');
expect($draft->preview()['routes'][0]['canonical_candidate'] === '', 'Draft/approved do not claim canonical');
$copy['routes'][0]['status'] = 'retired';
expect(!$make($copy)->lookup($normalized['identity'], 'en'), 'Retired route never resolves');
$copy = $doc; $copy['routes'][0]['source'] = 'native_page'; $editorial = $make($copy);
expect($editorial->build($state, 'en') === 'https://example.test/en/template-10/', 'Native Page is authoritative permalink');
denied(static function () use ($doc, $term, $page, $native) {
    new ManagedUrlArchitecture($doc, 'https://example.test', $term, $page, static function () { return array('native_post:99'); }, array($native, 'dispatch'));
}, 'Native permalink collision rejected');
$rules = (new WordPressRouteResolver($service))->rewritePlan();
expect(count($rules['rules']) === 2 && false === $rules['activation_authorized'], 'Exact rewrite plan only');
expect(false !== strpos($rules['rules'][0]['query'], 'page_id=10&indexflow_route=cairo-en'), 'Virtual uses approved native template');
denied(static function () use ($service, $url) { (new WordPressRouteResolver($service))->resolveRequest($url, 'cairo-ar'); }, 'Query var cannot spoof route');
$copy = $doc; $copy['profiles']['tours']['bases']['en'] = '/en/new-tours/';
$migration = $service->planMigration($make($copy));
expect($migration['migration_required'] && count($migration['changes']) === 1 && !$migration['changes'][0]['redirect_authorized'], 'Migration plans change but do not redirect');
expect(!$service->planMigration($service)['migration_required'], 'Unchanged schema requires no migration');
$row = $service->lookup($normalized['identity'], 'en');
$observations = array('route_revision' => $service->revision(), 'unknown_route_status' => 404,
    'seo' => array('canonical' => $url, 'canonical_count' => 1, 'owner' => 'rank_math', 'robots' => 'index,follow', 'sitemap_urls' => array($url),
        'hreflang' => array('en' => $url, 'ar' => $service->build($translated, 'ar'))));
foreach (array('direct', 'ajax', 'mixed', 'reload', 'back', 'forward') as $mode) $observations[$mode] = array('result_ids' => array(7, 4), 'complete' => true, 'state_identity' => $row['identity'], 'locale' => 'en', 'http_status' => 200);
$observations['seo_ownership'] = array('rank_math_available' => true, 'rank_math_disabled' => false, 'rank_math_hooks_active' => true, 'jsf_rule_matched' => false);
$observations['seo_ownership'] += array('emissions' => array_fill_keys(array('title', 'description', 'canonical', 'robots', 'sitemap'), array('rank_math')),
    'robots_index_confirmed' => true, 'sitemap_inclusion_verified' => true, 'direct_ajax_parity_verified' => true,
    'url_alias_parity_verified' => true, 'wpml_hreflang_verified' => true);
foreach (array('canonical', 'robots', 'sitemap', 'hreflang') as $surface) $observations['seo_ownership'][$surface . '_owner'] = 'rank_math';
foreach (array('direct', 'ajax', 'mixed', 'reload', 'back', 'forward') as $mode) {
    $observations[$mode]['widgets_state'] = $state;
    $observations[$mode]['filter_state'] = $state;
    $observations[$mode]['url'] = $url;
}
expect($service->verify($row, $observations)['observed_pass'], 'Complete fixture evidence reconciles');
$bad = $observations; $bad['ajax']['result_ids'] = array(7, 9);
expect(!$service->verify($row, $bad)['observed_pass'], 'Equal counts with different IDs fail');
$bad = $observations; unset($bad['back']); expect(!$service->verify($row, $bad)['observed_pass'], 'Missing browser history fails');
$bad = $observations; $bad['route_revision'] = 'stale'; expect(!$service->verify($row, $bad)['observed_pass'], 'Stale evidence fails');
$bad = $observations; $bad['seo']['canonical_count'] = 2; expect(!$service->verify($row, $bad)['observed_pass'], 'Duplicate canonical fails');
$bad = $observations; $bad['reload']['result_ids'] = array(7, 4, 7); expect(!$service->verify($row, $bad)['observed_pass'], 'Duplicate results fail');
$bad = $observations; $bad['ajax']['result_ids'] = array(array('id' => 7)); expect(!$service->verify($row, $bad)['observed_pass'], 'Malformed result IDs fail without notices');
$bad = $observations; $bad['mixed']['widgets_state'] = $translated; expect(!$service->verify($row, $bad)['observed_pass'], 'Widget/locale mismatch fails');
$bad = $observations; $bad['forward']['url'] = 'https://evil.test/'; expect(!$service->verify($row, $bad)['observed_pass'], 'History URL must bind to route');
expect(!$service->verify($row, $observations)['runtime_certified'], 'Fixture pass grants no runtime authority');
$owner = new SeoOwnershipResolver();
$proof = array('rank_math_available' => true, 'rank_math_disabled' => false, 'rank_math_hooks_active' => true, 'jsf_rule_matched' => false);
$proof += $observations['seo_ownership'];
foreach (array('canonical', 'robots', 'sitemap', 'hreflang') as $surface) $proof[$surface . '_owner'] = 'rank_math';
expect($owner->inspect($row, $proof)['ready'], 'Rank Math owner with output hooks');
$proof['rank_math_disabled'] = true; expect(!$owner->inspect($row, $proof)['ready'], 'JSF disables Rank Math: fail closed');
expect(!$owner->inspect($row, array())['ready'], 'Unknown ownership fails closed');
expect(!$owner->inspect(array(), $proof)['managed'], 'Unmanaged routes remain native');
$nativeDoc = $doc; $nativeDoc['routes'] = array(); $nativeDoc['profiles']['tours']['strategy'] = 'native';
$nativeService = $make($nativeDoc);
// Query IDs are case-sensitive in existing QueryId; preserve the real binding.
$nativeUrl = $nativeService->build($state, 'en');
expect($nativeService->normalize($nativeService->resolve($nativeUrl)['state']) === $normalized, 'Existing JSF parser native round trip');
expect($service->canonicalize($nativeUrl) === $url, 'Native technical URL maps to same published canonical');
echo 'Managed URL contracts: ' . $checks . " checks PASS (fixtures, no runtime certification)\n";
