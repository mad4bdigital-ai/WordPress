<?php
namespace DynamicFilterSEOBridge\Integration;

use DynamicFilterSEOBridge\IndexFlow\WordPressRouteEnvironment;
use DynamicFilterSEOBridge\Terms\AutoQualifiedTermPolicy;
use DynamicFilterSEOBridge\WPML\LanguageResolver;
require_once __DIR__ . '/RewriteGapPriority.php';
require_once dirname(__DIR__) . '/IndexFlow/LocalizedTaxonomyBase.php';

/** Read-only, bounded source for the governed MAD4B Content Readiness ledger. */
final class WPMLTermRouteGapScanner {
    public const CONTRACT = 'dfsb.wpml-term-route-gaps.v1';
    private const MAX_TERMS_PER_TAXONOMY = 240;
    private const MAX_ISSUES = 600;

    private static function issue(string $taxonomy, int $id, string $locale, string $reason, string $description): array {
        $code = 'dfsb_wpml_' . $reason . '_' . $taxonomy . '_' . $id . '_' . $locale;
        $triage=RewriteGapPriority::inspect($taxonomy,$id,$reason);
        return array(
            'id' => hash('sha256', get_current_blog_id() . '|site|0|' . $code),
            'post_type' => 'site', 'object_id' => 0, 'code' => $code,
            'severity' => 'high',
            'priority' => $triage['priority'],
            'triage' => $triage,
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
        // Inspect public taxonomy bases across all plugins, not just tour
        // facets. Term translation completeness remains profile-scoped below.
        $taxonomies = get_taxonomies(array('public'=>true),'objects');
        $scannedTaxonomies = 0;
        foreach ($taxonomies as $taxonomyName=>$taxonomyObject) {
            if (empty($taxonomyObject->rewrite) || !is_array($taxonomyObject->rewrite)
                || empty($taxonomyObject->rewrite['slug'])) continue;
            $count = wp_count_terms(array('taxonomy'=>$taxonomyName,'hide_empty'=>true));
            if (is_wp_error($count)) throw new \RuntimeException('dfsb_wpml_taxonomy_population_unavailable');
            if ((int)$count < 1) continue;
            if (++$scannedTaxonomies > 50) throw new \RuntimeException('dfsb_wpml_taxonomy_base_scan_incomplete');
            foreach ($locales as $locale) {
                if ($locale === $default) continue;
                $base = \DynamicFilterSEOBridge\IndexFlow\LocalizedRewriteBase::inspect(
                    'taxonomy', (string)$taxonomyName, $locale);
                if (!empty($base['translation_verified'])) continue;
                $issues[] = self::issue((string)$taxonomyName,0,$locale,'base_slug_unverified',
                    'Registered taxonomy rewrite base has no verified per-language WPML translation');
            }
        }
        // Inspect all public, rewritable CPT bases that have published content;
        // the site may add new CPTs without requiring a DFSB code change.
        $types = get_post_types(array('public'=>true),'objects');
        $scanned = 0;
        foreach ($types as $typeName => $typeObject) {
            if (in_array($typeName,array('post','page','attachment'),true) ||
                empty($typeObject->rewrite) || !is_array($typeObject->rewrite) ||
                empty($typeObject->rewrite['slug'])) continue;
            $counts=wp_count_posts($typeName);
            if (!is_object($counts) || (int)($counts->publish??0)<1) continue;
            if (++$scanned>30) throw new \RuntimeException('dfsb_wpml_cpt_base_scan_incomplete');
            foreach ($locales as $locale) {
                if ($locale===$default) continue;
                $base=\DynamicFilterSEOBridge\IndexFlow\LocalizedRewriteBase::inspect('post_type',$typeName,$locale);
                if (!empty($base['translation_verified'])) continue;
                $issues[]=self::issue($typeName,0,$locale,'cpt_base_unverified',
                    'CPT permalink rewrite base requires a verified per-language WPML translation');
            }
        }

        // A missing translated taxonomy URL base is a separate, actionable
        // WPML settings issue; it must not be disguised as a missing term.
        foreach ($authorized as $taxonomy) {
            foreach ($locales as $locale) {
                if ($locale === $default) continue;
                $base = \DynamicFilterSEOBridge\IndexFlow\LocalizedTaxonomyBase::inspect((string)$taxonomy, $locale);
                if (!empty($base['translation_verified'])) continue;
                if (isset($taxonomies[$taxonomy]) &&
                    is_array($taxonomies[$taxonomy]->rewrite??null) &&
                    !empty($taxonomies[$taxonomy]->rewrite['slug'])) continue;
                $issues[] = self::issue((string)$taxonomy, 0, $locale,
                    'base_slug_unverified', 'Native facet taxonomy URL rewrite is missing or unverified');
            }
        }

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
                    // A newly linked WPML term is not editorial approval.
                    // Keep the original stable term_missing issue/ContentJob
                    // open until the localized term passes review and is
                    // explicitly released. Do not auto-close on mere linkage.
                    if ((string)get_term_meta((int)$term->term_id,
                            '_mad4b_wpml_review_required',true)==='1') {
                        $issues[] = self::issue($taxonomy,(int)$source->term_id,$locale,
                            'term_missing','WPML term translated but pending editorial/public URL review');
                        continue;
                    }
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
        if (count($issues) > self::MAX_ISSUES)
            throw new \RuntimeException('dfsb_wpml_issue_scan_capacity_exceeded');
        return $issues;
    }
}
