<?php
/** Lab-only scoped compatibility for retired geo post search with native destination term results. */
add_filter('jet-search/ajax-search/query-args', static function ($args, $handler) {
    if (!is_array($args)) return $args;
    $post_types = array_values(array_unique(array_map('strval', (array) ($args['post_type'] ?? []))));
    if ($post_types === ['__etg_geo_terms_only__']) {
        $args['post__in'] = [0];
        return $args;
    }
    sort($post_types);
    $expected = ['attractions', 'cities', 'continents', 'countries'];
    sort($expected);
    if ($post_types !== $expected) return $args;
    // Never allow post results from legacy geo CPTs; JetSearch's independent Terms source remains active.
    $args['post__in'] = [0];
    return $args;
}, 20, 2);
