<?php
/**
 * Plugin Name: MAD4B ETG Query-Backed Navigation Eligibility
 * Description: Strict WPML term projection and centrally editable JetEngine post-query authority for native navigation.
 */
defined('ABSPATH') || exit;
if (wp_get_environment_type() !== 'staging'
    || wp_parse_url(home_url('/'), PHP_URL_HOST) !== 'tours.dev.mad4b.com') return;

/** Read the persisted WPML taxonomy language, not get_term()'s current-language remapping. */
function mad4b_etg_nav_term_languages(array $term_ids): array {
    global $wpdb;
    // A single Elementor page may project the same term set from dozens of
    // JetEngine widgets. Cache exact SQL results only within this PHP request:
    // no cross-request WPML staleness, no persistent object-cache invalidation.
    static $memo = [];
    $term_ids = array_values(array_unique(array_filter(array_map('absint', $term_ids))));
    if (!$term_ids) return [];
    sort($term_ids, SORT_NUMERIC);
    $answer = [];
    foreach (array_chunk($term_ids, 250) as $chunk) {
        $key = implode(',', $chunk);
        if (!array_key_exists($key, $memo)) {
            $placeholders = implode(',', array_fill(0, count($chunk), '%d'));
            $sql = "SELECT tt.term_id, tr.language_code FROM {$wpdb->term_taxonomy} tt
              JOIN {$wpdb->prefix}icl_translations tr
              ON tr.element_id = tt.term_taxonomy_id
              AND tr.element_type = CONCAT('tax_', tt.taxonomy)
              WHERE tt.term_id IN ($placeholders)";
            $rows = $wpdb->get_results($wpdb->prepare($sql, ...$chunk), ARRAY_A);
            $found = [];
            foreach ((array) $rows as $row) $found[(int) $row['term_id']] = (string) $row['language_code'];
            // Bound request memory under unusual very large menus.
            if (count($memo) < 128) $memo[$key] = $found;
        }
        foreach (($memo[$key] ?? $found) as $id => $language) $answer[$id] = $language;
        unset($found);
    }
    return $answer;
}
function mad4b_etg_nav_project_terms(array $terms, string $language, string $service, string $taxonomy): array {
    if ($language === '' || !function_exists('product_navigation_source')) return $terms;
    $ids = [];
    foreach ($terms as $term) if ($term instanceof WP_Term || is_numeric($term)) {
        $ids[] = $term instanceof WP_Term ? (int) $term->term_id : (int) $term;
    }
    if (!$ids) return $terms;
    $languages = mad4b_etg_nav_term_languages($ids);
    $candidate = [];
    foreach ($ids as $id) if (($languages[$id] ?? '') === $language) $candidate[] = $id;
    // Extensions may narrow the authorized set, but cannot re-add an excluded term.
    $extension = apply_filters('mad4b_navigation_eligible_term_ids', $candidate, [
        'service' => $service, 'taxonomy' => $taxonomy,
        'language' => $language, 'source' => product_navigation_source($service),
    ]);
    if (is_array($extension)) {
        $candidate = array_values(array_intersect($candidate, array_map('absint', $extension)));
    }
    $allow = array_fill_keys($candidate, true);
    return array_values(array_filter($terms, static function ($term) use ($allow): bool {
        $id = $term instanceof WP_Term ? (int) $term->term_id : (is_numeric($term) ? (int) $term : 0);
        return isset($allow[$id]);
    }));
}

/**
 * JetEngine's native legacy Terms Query remains the source of term order,
 * pagination and taxonomy. The existing policy plugin adds:
 * Jet Query Builder post_ids ∩ taxonomy relationship ∩ term meta/parent.
 * This layer only checks final locale identity and optional current-archive scope.
 */
add_filter('jet-engine/listing/grid/terms-query-args', static function ($args, $grid, $settings) {
    if (!is_array($args) || !is_array($settings)
        || !str_contains((string) ($settings['_css_classes'] ?? ''), 'navigation-editor-grid')) return $args;
    $service = (string) ($settings['product_navigation_service'] ?? '');
    if ($service === '' || !function_exists('product_navigation_source')) return $args;
    $source = product_navigation_source($service);
    $taxonomy = $args['taxonomy'] ?? '';
    $taxonomy = is_array($taxonomy) ? reset($taxonomy) : $taxonomy;
    if (!is_string($taxonomy) || !isset($source['terms'][$taxonomy])
        && !in_array($taxonomy, ['tour-types', 'destinations'], true)) return $args;
    $language = (string) (apply_filters('wpml_current_language', null) ?: apply_filters('wpml_default_language', null) ?: 'en');
    $args['mad4b_nav_locale'] = $language;
    $args['mad4b_nav_service'] = $service;
    $args['mad4b_nav_taxonomy'] = $taxonomy;
    $scope = (string) ($settings['product_navigation_context_scope'] ?? $source['context_scope'] ?? 'catalogue');
    if ($scope === 'current_term' && is_tax($taxonomy)) {
        $current = get_queried_object();
        if ($current instanceof WP_Term && $current->taxonomy === $taxonomy) {
            $root = (int) apply_filters('wpml_object_id',
                (int) ($source['terms'][$taxonomy]['parent'] ?? 0), $taxonomy, false, $language);
            $path = array_map('intval', get_ancestors((int) $current->term_id, $taxonomy, 'taxonomy'));
            if ($root && (int) $current->term_id !== $root && !in_array($root, $path, true)) {
                $args['include'] = [0];
            } else {
                $args['parent'] = (int) $current->term_id;
            }
        }
    }
    return $args;
}, 55, 3);

add_filter('get_terms', static function ($terms, $taxonomies, $args, $term_query) {
    if (!is_array($terms) || !is_array($args)) return $terms;
    $language = (string) ($args['mad4b_nav_locale'] ?? '');
    $service = (string) ($args['mad4b_nav_service'] ?? '');
    $taxonomy = (string) ($args['mad4b_nav_taxonomy'] ?? '');
    if ($language === '' || $service === '' || $taxonomy === '') return $terms;
    return mad4b_etg_nav_project_terms($terms, $language, $service, $taxonomy);
}, 55, 4);

add_filter('jet-engine/listing/grid/query/query', static function ($items, $settings, $widget) {
    if (!is_array($items) || !is_array($settings)
        || !str_contains((string) ($settings['_css_classes'] ?? ''), 'navigation-editor-grid')) return $items;
    $service = (string) ($settings['product_navigation_service'] ?? '');
    if ($service === '' || !$items || !($items[0] instanceof WP_Term)) return $items;
    $lang = (string) (apply_filters('wpml_current_language', null) ?: 'en');
    $tax = (string) $items[0]->taxonomy;
    return mad4b_etg_nav_project_terms($items, $lang, $service, $tax);
}, 55, 3);

// Configure the current taxonomy scope in Elementor without changing the JetEngine source.
add_action('elementor/element/jet-listing-grid/section_general/before_section_end', static function ($element) {
    $element->add_control('product_navigation_context_scope', [
        'label' => 'Navigation scope',
        'type' => \Elementor\Controls_Manager::SELECT,
        'options' => ['catalogue' => 'Whole eligible catalogue', 'current_term' => 'Current taxonomy term children'],
        'default' => 'catalogue',
        'condition' => ['product_navigation_service!' => ''],
    ]);
}, 25);

// A minimal, guarded admin interface selects which JetEngine Query Builder post-query
// is authoritative. Optional extra policies remain in product_navigation_sources.
add_action('admin_menu', static function () {
    add_management_page('Navigation query authority', 'Navigation query authority',
        'manage_options', 'mad4b-navigation-query-authority', static function () {
            if (!current_user_can('manage_options')) return;
            $sources = get_option('product_navigation_sources', []);
            $registry = function_exists('product_navigation_registry') ? product_navigation_registry() : [];
            echo '<div class="wrap"><h1>Navigation query authority</h1>';
            echo '<p>JetEngine Query Builder supplies eligible published CPT records. Central service policy, terms, WPML and optional taxonomy scope restrict the visible navigation. EN Elementor is the layout authority.</p>';
            foreach ($registry as $service => $definition) {
                $id = absint($sources[$service]['query_id'] ?? 0);
                echo '<form action="' . esc_url(admin_url('admin-post.php')) . '" method="post">';
                wp_nonce_field('mad4b_nav_query_' . $service);
                echo '<input type="hidden" name="action" value="mad4b_nav_query_update">';
                echo '<input type="hidden" name="service" value="' . esc_attr($service) . '">';
                echo '<p><label><strong>' . esc_html($definition['label'] ?? $service) . '</strong> — JetEngine post Query ID ';
                echo '<input name="query_id" type="number" min="0" value="' . esc_attr((string) $id) . '" size="7"></label> ';
                submit_button('Validate and save', 'secondary', '', false);
                echo '</p></form>';
            }
            echo '<p><em>Setting a query to zero hides its dynamic navigation terms. Choose a published-post Query Builder query matching the service CPT.</em></p></div>';
        });
});
add_action('admin_post_mad4b_nav_query_update', static function () {
    if (!current_user_can('manage_options')) wp_die('Unauthorized');
    $service = sanitize_key(wp_unslash($_POST['service'] ?? ''));
    $registry = function_exists('product_navigation_registry') ? product_navigation_registry() : [];
    if (!isset($registry[$service])) wp_die('Unknown service');
    check_admin_referer('mad4b_nav_query_' . $service);
    $id = absint($_POST['query_id'] ?? 0);
    if ($id > 0) {
        if (!class_exists('Jet_Engine\Query_Builder\Manager')) wp_die('JetEngine Query Builder unavailable');
        $query = \Jet_Engine\Query_Builder\Manager::instance()->get_query_by_id($id);
        if (!$query || $query->query_type !== 'posts'
            || !in_array($registry[$service]['post_type'], (array) ($query->query['post_type'] ?? []), true)) {
            wp_die('Query must be a Posts Query for the selected service CPT.');
        }
    }
    $sources = get_option('product_navigation_sources', []);
    if (!is_array($sources)) $sources = [];
    if (!isset($sources[$service]) || !is_array($sources[$service])) $sources[$service] = [];
    $sources[$service]['query_id'] = $id;
    update_option('product_navigation_sources', $sources, false);
    wp_safe_redirect(add_query_arg('updated', 'true', admin_url('tools.php?page=mad4b-navigation-query-authority')));
    exit;
});

/** Shared hook covers BOTH directly built native menu items and JetEngine grids. */
add_filter('product_navigation_term_args', static function ($args, $service, $taxonomy, $language) {
    if (!is_array($args) || !function_exists('product_navigation_source')) return $args;
    $language = (string) ($language ?: apply_filters('wpml_current_language', null) ?: 'en');
    $source = product_navigation_source((string) $service);
    $rule = $source['terms'][(string) $taxonomy] ?? [];
    $args['mad4b_nav_locale'] = $language;
    $args['mad4b_nav_service'] = (string) $service;
    $args['mad4b_nav_taxonomy'] = (string) $taxonomy;

    if (($source['context_scope'] ?? 'catalogue') === 'current_term' && is_tax((string) $taxonomy)) {
        $queried = get_queried_object();
        if ($queried instanceof WP_Term && $queried->taxonomy === $taxonomy) {
            $root = (int) apply_filters('wpml_object_id',
                (int) ($rule['parent'] ?? 0), (string) $taxonomy, false, $language);
            $ancestors = array_map('intval',
                get_ancestors((int) $queried->term_id, (string) $taxonomy, 'taxonomy'));
            if ($root && (int) $queried->term_id !== $root && !in_array($root, $ancestors, true)) {
                $args['include'] = [0];
            } else {
                $args['parent'] = (int) $queried->term_id;
            }
        }
    }
    if (is_array($rule) && !empty($rule['exclude_ids']) && is_array($rule['exclude_ids'])) {
        $args['exclude'] = array_values(array_unique(array_merge(
            array_map('absint', (array) ($args['exclude'] ?? [])),
            array_map('absint', $rule['exclude_ids'])
        )));
    }
    // Custom dimensions can ONLY narrow the certified query, never add posts
    // or alter the taxonomy/locale, to preserve the core source authority.
    $extra = apply_filters('mad4b_navigation_term_query_conditions', $args, [
        'service' => $service, 'taxonomy' => $taxonomy, 'language' => $language,
        'source' => $source,
    ]);
    if (is_array($extra)) {
        // Compound new meta rules with the saved source. A callback cannot remove it.
        if (!empty($extra['meta_query']) && is_array($extra['meta_query'])
            && $extra['meta_query'] !== ($args['meta_query'] ?? [])) {
            $args['meta_query'] = empty($args['meta_query']) ? $extra['meta_query']
                : ['relation' => 'AND', $args['meta_query'], $extra['meta_query']];
        }
        $args['exclude'] = array_values(array_unique(array_merge(
            array_map('absint', (array) ($args['exclude'] ?? [])),
            array_map('absint', (array) ($extra['exclude'] ?? []))
        )));
        if (isset($extra['include']) && is_array($extra['include'])
            && $extra['include'] !== ($args['include'] ?? null)) {
            $requested = array_map('absint', $extra['include']);
            $prior = isset($args['include']) ? array_map('absint', (array) $args['include']) : null;
            $args['include'] = $prior === null ? $requested : array_values(array_intersect($prior, $requested));
            if (!$args['include']) $args['include'] = [0];
        }
    }
    $args['object_ids'] = $args['object_ids'] ?? [0];
    $args['taxonomy'] = $taxonomy;
    $args['hide_empty'] = true;
    $args['lang'] = $language;
    return $args;
}, 30, 4);

/** Read-only, on-demand eligibility diagnostics: explain every excluded locale term. */
function mad4b_etg_nav_eligibility_audit(string $service, string $language): array {
    global $wpdb, $sitepress;
    if (!function_exists('product_navigation_registry') || !function_exists('product_navigation_term_args')) return [];
    $registry = product_navigation_registry();
    if (!isset($registry[$service])) return [];
    $source = product_navigation_source($service);
    $default = (string) (apply_filters('wpml_default_language', null) ?: 'en');
    $previous = (string) (apply_filters('wpml_current_language', null) ?: $default);
    $rows = [];
    try {
        if (is_object($sitepress)) $sitepress->switch_lang($language);
        $post_ids = product_navigation_eligible_post_ids($service, $language) ?: [];
        $post_ids = array_values(array_unique(array_filter(array_map('absint', $post_ids))));
        foreach (['destinations', 'tour-types'] as $taxonomy) {
            if (!taxonomy_exists($taxonomy)) continue;
            $visible = get_terms(product_navigation_term_args($service, $taxonomy, $language, [
                'fields' => 'ids', 'number' => 0
            ]));
            $visible = is_array($visible) ? array_fill_keys(array_map('intval', $visible), true) : [];
            $rule = (array) ($source['terms'][$taxonomy] ?? []);
            if (is_object($sitepress)) $sitepress->switch_lang($default);
            $master = get_terms(product_navigation_term_args($service, $taxonomy, $default, [
                'fields' => 'ids', 'number' => 0
            ]));
            if (!is_array($master)) $master = [];
            if (is_object($sitepress)) $sitepress->switch_lang($language);
            foreach ($master as $base_id) {
                $base_id = (int) $base_id;
                $canonical = $wpdb->get_row($wpdb->prepare(
                    "SELECT tt.term_taxonomy_id, t.name FROM {$wpdb->term_taxonomy} tt
                     JOIN {$wpdb->terms} t ON t.term_id=tt.term_id WHERE tt.term_id=%d AND tt.taxonomy=%s",
                    $base_id, $taxonomy
                ), ARRAY_A);
                if (!$canonical) continue;
                $translated = $wpdb->get_row($wpdb->prepare(
                    "SELECT tt.term_id, tt.term_taxonomy_id, tt.parent, t.name
                     FROM {$wpdb->prefix}icl_translations original
                     JOIN {$wpdb->prefix}icl_translations translated
                       ON translated.trid=original.trid
                       AND translated.element_type=original.element_type AND translated.language_code=%s
                     JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id=translated.element_id
                     JOIN {$wpdb->terms} t ON t.term_id=tt.term_id
                     WHERE original.element_id=%d AND original.element_type=%s LIMIT 1",
                    $language, (int) $canonical['term_taxonomy_id'], 'tax_' . $taxonomy
                ), ARRAY_A);
                $local_id = $translated ? (int) $translated['term_id'] : 0;
                $reasons = [];
                if (!$local_id) $reasons[] = 'missing_wpml_translation';
                $relation_count = 0;
                if ($local_id && $post_ids) {
                    $relation_count = (int) $wpdb->get_var(
                        "SELECT COUNT(DISTINCT object_id) FROM {$wpdb->term_relationships}
                         WHERE term_taxonomy_id=" . (int) $translated['term_taxonomy_id'] .
                        " AND object_id IN (" . implode(',', $post_ids) . ")"
                    );
                }
                if ($local_id && !$relation_count) $reasons[] = 'no_qualifying_cpt_relationship';
                if ($local_id && isset($rule['parent'])) {
                    $parent_id = (int) apply_filters('wpml_object_id', (int) $rule['parent'], $taxonomy, false, $language);
                    if (!$parent_id || (int) $translated['parent'] !== $parent_id) {
                        $reasons[] = 'parent_hierarchy_mismatch';
                    }
                }
                if ($local_id && !empty($rule['meta_query']) && is_array($rule['meta_query'])) {
                    foreach ($rule['meta_query'] as $condition) {
                        if (!is_array($condition) || empty($condition['key'])) continue;
                        $actual = (string) get_term_meta($local_id, (string) $condition['key'], true);
                        $expected = (string) ($condition['value'] ?? '');
                        $compare = strtoupper((string) ($condition['compare'] ?? '='));
                        $passes = match ($compare) {
                            '=', '==' => $actual === $expected,
                            '!=', '<>' => $actual !== $expected,
                            'EXISTS' => metadata_exists('term', $local_id, (string) $condition['key']),
                            'NOT EXISTS' => !metadata_exists('term', $local_id, (string) $condition['key']),
                            default => true,
                        };
                        if (!$passes) $reasons[] = 'term_meta:' . (string) $condition['key'];
                    }
                }
                $shown = $local_id > 0 && isset($visible[$local_id]);
                if (!$shown && !$reasons) $reasons[] = 'excluded_by_additional_query_policy';
                $rows[] = [
                    'taxonomy' => $taxonomy,
                    'source_id' => $base_id, 'source_name' => (string) $canonical['name'],
                    'locale_id' => $local_id, 'locale_name' => (string) ($translated['name'] ?? ''),
                    'eligible_post_relationships' => $relation_count,
                    'visible' => $shown, 'reasons' => array_values(array_unique($reasons))
                ];
            }
        }
    } finally {
        if (is_object($sitepress)) $sitepress->switch_lang($previous);
    }
    return $rows;
}
add_action('admin_menu', static function () {
    add_management_page('Navigation eligibility audit', 'Navigation eligibility audit',
        'manage_options', 'mad4b-navigation-eligibility-audit', static function () {
            if (!current_user_can('manage_options')) return;
            $supported = (array) (get_option('icl_sitepress_settings', [])['active_languages'] ?? ['en', 'ar']);
            $language = sanitize_key((string) wp_unslash($_GET['language'] ?? 'ar'));
            if (!in_array($language, $supported, true)) $language = 'en';
            echo '<div class="wrap"><h1>Navigation eligibility audit</h1>';
            echo '<p>The canonical JetEngine Posts Query is intersected with the translated CPT relationships, taxonomy hierarchy and term metadata. A term can have multiple exclusion reasons. This report is read-only.</p><p>';
            foreach ($supported as $lang) {
                $url = add_query_arg(['page' => 'mad4b-navigation-eligibility-audit', 'language' => $lang], admin_url('tools.php'));
                echo '<a class="button button-secondary" style="margin-inline-end:7px" href="' . esc_url($url) . '">' . esc_html(strtoupper($lang)) . '</a>';
            }
            echo '</p>';
            $source = product_navigation_source('tours');
            echo '<p><strong>Source:</strong> JetEngine Query Builder #' . esc_html((string) ($source['query_id'] ?? 'none')) . ' · <a href="' .
                esc_url(admin_url('tools.php?page=mad4b-navigation-query-authority')) . '">Change authoritative query</a></p>';
            $rows = mad4b_etg_nav_eligibility_audit('tours', $language);
            echo '<table class="widefat striped"><thead><tr><th>Taxonomy</th><th>Canonical term</th><th>Translation</th><th>Linked eligible CPT</th><th>Visible</th><th>Reason(s)</th></tr></thead><tbody>';
            foreach ($rows as $row) {
                echo '<tr><td>' . esc_html($row['taxonomy']) . '</td><td>' .
                    esc_html($row['source_name'] . ' (#' . $row['source_id'] . ')') . '</td><td>' .
                    esc_html($row['locale_name'] . ($row['locale_id'] ? ' (#' . $row['locale_id'] . ')' : '')) .
                    '</td><td>' . esc_html((string) $row['eligible_post_relationships']) . '</td><td>' .
                    ($row['visible'] ? 'Yes' : 'No') . '</td><td>' .
                    esc_html(implode(' + ', $row['reasons'])) . '</td></tr>';
            }
            echo '</tbody></table></div>';
        });
}, 20);
