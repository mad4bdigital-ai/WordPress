<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$files = array(
    'config' => file_get_contents($root . '/includes/Config/ProfileRegistryNormalizationTrait.php'),
    'policy' => file_get_contents($root . '/includes/SEO/IndexingPolicy.php'),
    'registry' => file_get_contents($root . '/includes/SEO/PublicationRegistry.php'),
    'count' => file_get_contents($root . '/includes/SEO/PublicationResultCountProbe.php'),
    'metadata' => file_get_contents($root . '/includes/RankMath/MetadataAdapter.php'),
    'sitemap' => file_get_contents($root . '/includes/RankMath/PublicationSitemapProvider.php'),
    'registrar' => file_get_contents($root . '/includes/RankMath/PublicationSitemapRegistrar.php'),
    'shortcodes' => file_get_contents($root . '/includes/Elementor/Shortcodes.php'),
    'admin' => file_get_contents($root . '/includes/Admin/PublicationPage.php'),
    'scope' => file_get_contents($root . '/includes/Runtime/RequestScope.php'),
    'builder' => file_get_contents($root . '/includes/Context/FilterContextBuilder.php'),
    'simulator' => file_get_contents($root . '/includes/Simulation/ScenarioSimulator.php'),
    'compat' => file_get_contents($root . '/includes/Compatibility.php'),
    'readiness' => file_get_contents($root . '/includes/Runtime/Readiness.php'),
);

foreach ($files as $name => $content) {
    if (!is_string($content) || '' === $content) { fwrite(STDERR, 'missing:' . $name . "\n"); exit(1); }
}

$assert = static function ($condition, string $message): void { if (!$condition) { fwrite(STDERR, $message . "\n"); exit(1); } };
$compact = static function (string $value): string { return preg_replace('/\s+/', '', $value); };

$assert(false !== strpos($files['config'], "'elementor_content_verified'"), 'publication verification flag missing');
$assert(false !== strpos($files['config'], "'elementor_render_when_global_off'"), 'legacy dark presentation input missing');
$assert(false !== strpos($files['policy'], 'elementor_content_not_verified'), 'Elementor fail-closed gate missing');
$assert(false !== strpos($files['scope'], 'evaluateForEvidence'), 'non-authorizing evidence scope missing');
$assert(false !== strpos($files['builder'], 'buildEvidence'), 'evidence context builder missing');
$assert(false !== strpos($files['builder'], 'evidenceRuntimeReady'), 'Global-OFF evidence readiness missing');
$assert(false !== strpos($files['builder'], "'dark_presentation_allowed'=>\$darkPresentationAllowed"), 'derived dark presentation decision missing');
$assert(false !== strpos($files['shortcodes'], 'dark_presentation_allowed'), 'Elementor dark presentation consumer missing');
$assert(false !== strpos($compact($files['shortcodes']), "'disabled'!==(string)(\$c['scope']['reason']??'')"), 'dark presentation disabled-state guard missing');
$assert(false !== strpos($files['shortcodes'], "['scope']['reason']"), 'dark presentation scope-reason guard missing');
$assert(false !== strpos($files['registry'], 'global_bridge_off'), 'Global OFF sitemap exclusion missing');
$assert(false !== strpos($files['registry'], 'profile_bound_publication_signature_required'), 'profile-bound publication signature missing');
$assert(false !== strpos($files['registry'], 'sitemap_included'), 'publication sitemap decision missing');
$assert(false !== strpos($files['count'], 'jet_engine_query_builder_background_tax_query'), 'background result-count authority missing');
$assert(false !== strpos($files['sitemap'], 'RankMath\\Sitemap\\Providers\\Provider'), 'Rank Math sitemap provider missing');
$assert(false !== strpos($files['sitemap'], '$pageCount'), 'Rank Math sitemap pagination missing');
$assert(false !== strpos($files['registrar'], 'rank_math/sitemap/providers'), 'Rank Math provider registration missing');
$assert(false !== strpos($files['registrar'], 'updated_term_meta'), 'term-meta sitemap freshness invalidation missing');
$assert(false !== strpos($files['registrar'], 'invalidate_storage'), 'sitemap freshness invalidation missing');

foreach (array('rank_math/opengraph/facebook/og_title','rank_math/opengraph/facebook/og_description','rank_math/opengraph/twitter/twitter_title','rank_math/opengraph/twitter/twitter_description','rank_math/opengraph/twitter/image','rank_math/json_ld','wpml_hreflangs') as $hook) {
    $assert(false !== strpos($files['metadata'], $hook), 'missing metadata hook:' . $hook);
}
foreach (array('etg_filter_term', 'etg_filter_term_section', 'etg_filter_sections') as $shortcode) { $assert(false !== strpos($files['shortcodes'], $shortcode), 'missing Elementor shortcode:' . $shortcode); }

$assert(false !== strpos($files['admin'], 'Elementor Theme Builder support'), 'Elementor publication admin guidance missing');
$assert(false !== strpos($files['admin'], 'Safe dark presentation'), 'dark presentation guidance missing');
$assert(false !== strpos($files['admin'], 'Rank Math dynamic sitemap'), 'sitemap admin visibility missing');
$assert(false !== strpos($files['simulator'], 'elementor_content_verified'), 'Scenario Lab must challenge Elementor verification gate');
foreach (array('rank_math_sitemap_provider_interface', 'rank_math_sitemap_router', 'rank_math_sitemap_cache', 'wpml_active_languages_filter', 'wpml_permalink_filter') as $cap) { $assert(false !== strpos($files['compat'], $cap), 'missing publication capability:' . $cap); }
$assert(false !== strpos($files['readiness'], 'publication_requires_elementor_pro'), 'Elementor Pro publication readiness signal missing');
$assert(false !== strpos($files['builder'], 'tour-styles_jet'), 'Production taxonomy role alignment missing');
$assert(false === strpos($files['builder'], "'tour-style_jet'=>'style'"), 'stale singular style taxonomy remains');

/* Behavioral proof: dark presentation is derived by the context builder and
 * consumed by renderers. The legacy profile flag alone must not authorize it. */
if (!function_exists('sanitize_key')) { function sanitize_key($v) { return preg_replace('/[^a-z0-9_\-]/', '', strtolower((string) $v)); } }
if (!function_exists('esc_html')) { function esc_html($v) { return (string) $v; } }
if (!function_exists('esc_attr')) { function esc_attr($v) { return (string) $v; } }
if (!function_exists('esc_url')) { function esc_url($v) { return (string) $v; } }
if (!function_exists('sanitize_html_class')) { function sanitize_html_class($v) { return preg_replace('/[^A-Za-z0-9_-]/', '', (string) $v); } }
if (!function_exists('wp_strip_all_tags')) { function wp_strip_all_tags($v) { return strip_tags((string) $v); } }
if (!function_exists('wp_kses_post')) { function wp_kses_post($v) { return (string) $v; } }
if (!function_exists('wpautop')) { function wpautop($v) { return (string) $v; } }
if (!function_exists('shortcode_atts')) { function shortcode_atts($pairs, $atts, $shortcode = '') { return array_merge($pairs, (array) $atts); } }
if (!function_exists('apply_filters')) { function apply_filters($hook, $value) { return $value; } }
if (!function_exists('wp_get_attachment_image_url')) { function wp_get_attachment_image_url($id, $size) { return 'https://example.test/image-' . $id . '.jpg'; } }

require_once $root . '/includes/Content/GalleryComposer.php';
require_once $root . '/includes/Content/ContentComposer.php';
require_once $root . '/includes/Elementor/Shortcodes.php';

$gallery = new \ETG\DynamicFilterSEOBridge\Content\GalleryComposer();
$content = new \ETG\DynamicFilterSEOBridge\Content\ContentComposer($gallery);
$normal = array('active'=>true,'in_scope'=>false,'scope_valid'=>false,'runtime_ready'=>false,'filters'=>array('location_jet'=>'cairo'),'scope'=>array('reason'=>'disabled'));
$evidence = array(
    'active'=>true,'in_scope'=>true,'scope_valid'=>true,'runtime_ready'=>true,'evidence_only'=>true,'dark_presentation_allowed'=>true,'dark_presentation_source'=>'safe_live_evidence',
    'filters'=>array('location_jet'=>'cairo'),'unknown_filters'=>array(),'malformed'=>array(),'missing_terms'=>array(),'translation_fallback'=>false,'provider_observation_matches_url'=>true,
    'profile'=>array('enabled'=>false,'publication'=>array('elementor_render_when_global_off'=>false),'taxonomy_rules'=>array('location_jet'=>array('role'=>'location','priority'=>10))),
    'post_type_binding'=>array('observed'=>false,'matches_profile'=>true),
    'terms'=>array('location'=>array('name'=>'Cairo','description'=>'Visible Cairo Term content','short_description'=>'Visible Cairo Term content')),
    'taxonomy_roles'=>array('location_jet'=>'location'),'language'=>'en',
);
$normalProvider = static function () use (&$normal) { return $normal; };
$evidenceProvider = static function () use (&$evidence) { return $evidence; };
$shortcodes = new \ETG\DynamicFilterSEOBridge\Elementor\Shortcodes($normalProvider, $content, $gallery, $evidenceProvider);
$assert('Cairo' === $shortcodes->h1(), 'derived dark presentation must render resolved H1 with Global OFF even when legacy flag is false');
$assert(false !== strpos($shortcodes->term(array('role'=>'location','field'=>'description')), 'Visible Cairo Term content'), 'derived dark presentation must render resolved Term content');
$evidence['dark_presentation_allowed'] = false;
$assert('' === $shortcodes->h1(), 'legacy flag cannot authorize presentation when derived decision is false');
$evidence['dark_presentation_allowed'] = true;
$normal['scope']['reason'] = 'provider_not_profiled';
$assert('' === $shortcodes->h1(), 'dark presentation cannot bypass a live scope failure');

echo "publication-layer-smoke:ok\n";
