<?php
namespace ETG\DynamicFilterSEOBridge\IndexFlow;

use ETG\DynamicFilterSEOBridge\JetSmartFilters\FilterUrlParser;

/** Reuses the existing parser and vendor aliases. No new filter/query engine. */
final class NativeFilterUrlAdapter {
    private $term;
    private $alias;
    public function __construct(callable $term, ?callable $alias = null) { $this->term = $term; $this->alias = $alias; }

    public function dispatch(string $operation, $value, array $configuration) {
        if ('build' === $operation) {
            $state = $value;
            $url = rtrim($configuration['bases'][$state['locale']], '/') . '/jsf/'
                . rawurlencode($state['provider']) . ':' . rawurlencode($state['query_id']) . '/tax/';
            $pairs = array();
            foreach ($state['filters'] as $taxonomy => $ids) {
                if (count($ids) !== 1) throw new \InvalidArgumentException('native_single_term_required');
                $term = call_user_func($this->term, $taxonomy, $ids[0], $state['locale']);
                $pairs[] = rawurlencode($taxonomy) . ':' . rawurlencode(rawurldecode($term['slug']));
            }
            $url .= implode(';', $pairs) . '/';
            if ('aliased' === $configuration['strategy']) {
                if (!$this->alias) throw new \InvalidArgumentException('vendor_alias_adapter_unavailable');
                $alias = call_user_func($this->alias, 'build', $url);
                if (!is_string($alias) || call_user_func($this->alias, 'resolve', $alias) !== $url) throw new \InvalidArgumentException('vendor_alias_round_trip_failed');
                return $alias;
            }
            return $url;
        }
        if ('resolve' !== $operation || !is_string($value)) return array();
        $matches = array();
        foreach ($configuration as $profileId => $profile) {
            if (!is_array($profile)) continue;
            $url = $value;
            if ('aliased' === ($profile['strategy'] ?? '')) {
                if (!$this->alias) continue;
                $url = call_user_func($this->alias, 'resolve', $url);
                if (!is_string($url)) continue;
            }
            $path = rawurldecode((string)parse_url($url, PHP_URL_PATH));
            if (!preg_match('~/jsf/[^/]+/tax/[^/]+/?$~D', $path)) continue;
            $taxonomies = array_column($profile['dimensions'] ?? array(), 'taxonomy');
            $parsed = (new FilterUrlParser($taxonomies))->parse($url);
            if (empty($parsed['active']) || !empty($parsed['malformed']) || !empty($parsed['unknown_filters'])
                || !empty($parsed['unsupported_query_params']) || !empty($parsed['duplicates'])
                || ($parsed['provider'] ?? '') !== ($profile['provider'] ?? '') || ($parsed['query_id'] ?? '') !== ($profile['query_id'] ?? '')) continue;
            foreach ($profile['bases'] ?? array() as $locale => $base) {
                if (rtrim((string)parse_url($base, PHP_URL_PATH), '/') !== rtrim($parsed['archive_path'] ?? '', '/')) continue;
                $filters = array();
                foreach ($parsed['filters'] as $taxonomy => $slug) {
                    $read = static function () use ($taxonomy, $slug) {
                        return get_terms(array('taxonomy' => $taxonomy, 'slug' => $slug, 'hide_empty' => false, 'number' => 50));
                    };
                    // Reuse ETG's existing language-context adapter and its finally
                    // restoration. No undocumented WPML query flags or slug map.
                    if (has_filter('wpml_object_id')) {
                        $execution = (new \ETG\DynamicFilterSEOBridge\WPML\LanguageResolver())->executeInLanguage((string)$locale, $read);
                        $terms = !empty($execution['available']) ? $execution['result'] : null;
                    } else $terms = $read();
                    if (!is_array($terms) || count($terms) >= 50) { $filters = array(); break; }
                    $valid = array();
                    foreach ($terms as $term) {
                        $id = (int)$term->term_id;
                        if (call_user_func($this->term, $taxonomy, $id, (string)$locale)) $valid[] = $id;
                    }
                    if (count($valid) !== 1) { $filters = array(); break; }
                    $filters[$taxonomy] = $valid;
                }
                if ($filters) $matches[] = array('profile_id' => (string)$profileId, 'provider' => $parsed['provider'],
                    'query_id' => $parsed['query_id'], 'locale' => (string)$locale, 'filters' => $filters);
            }
        }
        if (count($matches) > 1) throw new \InvalidArgumentException('ambiguous_native_profile');
        return $matches ? $matches[0] : array();
    }
}
