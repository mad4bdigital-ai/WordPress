<?php
namespace DynamicFilterSEOBridge\Integration;

use DynamicFilterSEOBridge\IndexFlow\WordPressRouteEnvironment;
use DynamicFilterSEOBridge\Terms\AutoQualifiedTermPolicy;
use DynamicFilterSEOBridge\WPML\LanguageResolver;

/** Read-only, bounded source for the governed MAD4B Content Readiness ledger. */
final class WPMLTermRouteGapScanner {
    public const CONTRACT = 'dfsb.wpml-term-route-gaps.v1';
    private const MAX_TERMS_PER_TAXONOMY = 240;
    private const MAX_ISSUES = 600;

    private static function issue(string $taxonomy, int $id, string $locale, string $reason, string $description): array {
        $code = 'dfsb_wpml_' . $reason . '_' . $taxonomy . '_' . $id . '_' . $locale;
        return array(
            'id' => hash('sha256', get_current_blog_id() . '|site|0|' . $code),
            'post_type' => 'site', 'object_id' => 0, 'code' => $code,
            'severity' => 'high',
            'label' => substr('Priority ContentJob: ' . $description . '; source taxonomy=' . $taxonomy
                . ' term=' . $id . ' target=' . $locale, 0, 240),
            'source' => 'dynamic-filter-seo-bridge',
        );
    }

    public static function scan(): array {
        if (!function_exists('shared_presentation_provider_v2') || !has_filter('wpml_object_id'))
            return array();
        $native = shared_presentation_provider_v2('tours.archive');
        if (!is_array($native) || ($native['provider'] ?? '') !== 'jet-engine/tours_query_archive') return array();
        $language = new LanguageResolver();
        $locales = array_keys($language->activeLanguages());
        $default = $language->defaultLanguage();
        if (!in_array($default, $locales, true) || count($locales) < 2) return array();
        $registry = new \DynamicFilterSEOBridge\Config\ProfileRegistry(
            new \DynamicFilterSEOBridge\Config\Configuration()
        );
        $profile = $registry->get('tours');
        // The shared provider's facet inventory is request-context-dependent:
        // WP-CLI and cron may see no mounted Elementor widgets. In that mode
        // use only the already validated DFSB native profile taxonomy rules.
        $declared = (array)($native['taxonomies'] ?? array());
        $registered = array_keys((array)($profile['taxonomy_rules'] ?? array()));
        $authorized = $declared ? array_intersect($registered, $declared) : $registered;
        $route = new WordPressRouteEnvironment();
        $issues = array();
        foreach ($authorized as $taxonomy) {
            $taxonomy = sanitize_key((string)$taxonomy);
            if (!$taxonomy || !taxonomy_exists($taxonomy)) continue;
            $base = $language->executeInLanguage($default, static function () use ($taxonomy) {
                return get_terms(array(
                    'taxonomy'=>$taxonomy, 'hide_empty'=>false,
                    'number'=>self::MAX_TERMS_PER_TAXONOMY + 1,
                    'orderby'=>'term_id', 'order'=>'ASC',
                ));
            });
            $terms = $base['result'] ?? null;
            // Never give a complete-scan receipt for truncated/failed evidence.
            if (empty($base['available']) || is_wp_error($terms) || !is_array($terms)
                || count($terms) > self::MAX_TERMS_PER_TAXONOMY) {
                throw new \RuntimeException('dfsb_wpml_taxonomy_scan_incomplete');
            }
            foreach ($terms as $source) {
                if (!$source instanceof \WP_Term || !AutoQualifiedTermPolicy::qualify($source)['qualified']
                    || (int)$source->count < 1) continue;
                foreach ($locales as $locale) {
                    if ($locale === $default) continue;
                    $translation = $language->resolve($source, $taxonomy, $locale);
                    if (!empty($translation['translation_fallback']) ||
                        !($translation['term'] ?? null) instanceof \WP_Term) {
                        $issues[] = self::issue($taxonomy,(int)$source->term_id,$locale,
                            'term_missing','Missing approved WPML term translation for the filtered archive');
                        continue;
                    }
                    $term = $translation['term'];
                    $resolved = $route->term($taxonomy,(int)$term->term_id,$locale);
                    if (!$resolved || (int)($resolved['semantic_id'] ?? 0) !== (int)$source->term_id ||
                        (string)($resolved['slug'] ?? '') !== (string)$term->slug) {
                        $issues[] = self::issue($taxonomy,(int)$source->term_id,$locale,
                            'route_slug','Native SEO route slug differs from the real WPML term translation');
                    }
                    if (count($issues) > self::MAX_ISSUES) throw new \RuntimeException('dfsb_wpml_issue_scan_capacity_exceeded');
                }
            }
        }
        return $issues;
    }
}
