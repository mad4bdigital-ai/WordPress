<?php
namespace ETG\DynamicFilterSEOBridge\IndexFlow;

/** Reads real WordPress/WPML objects. Never copies translated slugs into settings. */
final class WordPressRouteEnvironment {
    public function term(string $taxonomy, int $id, string $locale): array {
        $term = get_term($id, $taxonomy);
        if (!$term instanceof \WP_Term) return array();
        $semantic = $id;
        if (has_filter('wpml_object_id')) {
            $default = apply_filters('wpml_default_language', null);
            if (!is_string($default) || '' === $default) return array();
            $semantic = (int)apply_filters('wpml_object_id', $id, $taxonomy, false, $default);
            // No return-original fallback: a missing translation denies the route.
            $localized = (int)apply_filters('wpml_object_id', $semantic, $taxonomy, false, $locale);
            if (!$semantic || $localized !== $id) return array();
        } else {
            $siteLocale = str_replace('_', '-', (string)get_locale());
            if (strtolower($locale) !== strtolower($siteLocale) && strtolower($locale) !== strtolower(substr($siteLocale, 0, 2))) return array();
        }
        return array('term_id' => $id, 'semantic_id' => $semantic, 'slug' => (string)$term->slug);
    }

    public function page(int $id, string $locale): array {
        $post = get_post($id);
        if (!$post instanceof \WP_Post || 'publish' !== $post->post_status || !is_post_type_viewable($post->post_type)) return array();
        if (has_filter('wpml_object_id')) {
            if ((int)apply_filters('wpml_object_id', $id, $post->post_type, false, $locale) !== $id) return array();
        } elseif (!$this->localeAvailable($locale)) {
            return array();
        }
        $url = get_permalink($id);
        return is_string($url) ? array('id' => $id, 'url' => $url) : array();
    }

    public function localeAvailable(string $locale): bool {
        if (has_filter('wpml_active_languages')) {
            $languages = apply_filters('wpml_active_languages', null, array('skip_missing' => 0));
            return is_array($languages) && isset($languages[$locale]);
        }
        $siteLocale = str_replace('_', '-', (string)get_locale());
        return strtolower($locale) === strtolower($siteLocale) || strtolower($locale) === strtolower(substr($siteLocale, 0, 2));
    }

    /** Native owners are checked without executing or mutating the main query. */
    public function collisions(string $url, int $ownPost): array {
        $out = array();
        $id = (int)url_to_postid($url);
        if ($id && $id !== $ownPost) $out[] = 'native_post:' . $id;
        $path = trim((string)parse_url($url, PHP_URL_PATH), '/');
        $homePath = trim((string)parse_url(home_url('/'), PHP_URL_PATH), '/');
        if ($homePath && 0 === strpos($path, $homePath . '/')) $path = substr($path, strlen($homePath) + 1);
        $post = get_page_by_path($path, OBJECT, get_post_types(array('public' => true)));
        if ($post instanceof \WP_Post && (int)$post->ID !== $ownPost) $out[] = 'native_path:' . $post->ID;
        $slug = rawurldecode(basename($path));
        foreach (get_taxonomies(array('public' => true)) as $taxonomy) {
            $terms = get_terms(array('taxonomy' => $taxonomy, 'slug' => $slug, 'hide_empty' => false, 'number' => 50));
            if (is_wp_error($terms)) { $out[] = 'taxonomy_collision_check_unavailable'; continue; }
            foreach ($terms as $term) {
                $link = get_term_link($term);
                if (is_string($link) && rtrim($link, '/') === rtrim($url, '/')) $out[] = 'native_taxonomy:' . $taxonomy . ':' . $term->term_id;
            }
            if (count($terms) >= 50) $out[] = 'taxonomy_collision_check_incomplete';
        }
        return array_values(array_unique($out));
    }
}
