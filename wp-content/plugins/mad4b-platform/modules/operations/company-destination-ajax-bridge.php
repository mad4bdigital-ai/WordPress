<?php
/**
 * Plugin Name: Dynamic Destination AJAX Context
 * Description: Keep native JetEngine/JetSmartFilters destination listings scoped after AJAX sort and reset.
 */
if (!defined('ABSPATH')) { exit; }

// Native Tours archive AJAX shares one language/facet/route descriptor with MU
// Presentation. The original JSF provider still verifies the request signature.
add_action('admin_init', static function (): void {
    if ((!defined('MAD4B_COMMERCE_RUNTIME_ENABLED') || !MAD4B_COMMERCE_RUNTIME_ENABLED) || !function_exists('shared_presentation_catalog_jsf_request_v1')) return;
    $context = shared_presentation_catalog_jsf_request_v1();
    if (!is_array($context) || ($context['language'] ?? 'en') === 'en') return;
    do_action('wpml_switch_language', $context['language']);
}, 1);

// Preserve translated destination context for the exact signed native Activity grid.
// This is locale selection only; JetSmartFilters owns the actual signature check.
add_action('admin_init', static function (): void {
    if ((!defined('MAD4B_COMMERCE_RUNTIME_ENABLED') || !MAD4B_COMMERCE_RUNTIME_ENABLED) || !wp_doing_ajax() ||
        ($_POST['action']??'')!=='jet_smart_filters' ||
        ($_POST['provider']??'')!=='jet-engine/registry_destination_activity_grid') return;
    $settings=$_POST['settings']??null;
    $referrer=$_POST['referrer']['uri']??null;
    if (!is_array($settings) || !is_string($referrer) ||
        !in_array((string)($settings['lisitng_id']??''),['67257','67258','67259','67260'],true) ||
        !preg_match('/^[a-f0-9]{32}$/i',(string)($settings['jsf_signature']??'')) ||
        !preg_match('~^/(ar|de|it|es|fr)/destination/(?:[a-z0-9_-]+/){1,8}$~D',$referrer,$match)) return;
    do_action('wpml_switch_language',$match[1]);
}, 1);

/**
 * Public destination identity, not proof of nationality or market approval.
 * AJAX referrers are untrusted; match them to an actual canonical public term URL.
 */
function company_ajax_destination_term() {
    static $resolved = null;
    if ($resolved !== null) { return $resolved ?: null; }
    $resolved = false;
    if (!wp_doing_ajax() || ($_REQUEST['action'] ?? '') !== 'jet_smart_filters') {
        return null;
    }
    $referrer = $_POST['referrer'] ?? null;
    if (!is_array($referrer) || !isset($referrer['uri']) || !is_string($referrer['uri'])) {
        return null;
    }
    $uri = wp_unslash($referrer['uri']);
    if (strlen($uri) > 1024 || !str_starts_with($uri, '/') ||
        str_starts_with($uri, '//') || str_contains($uri, '?') ||
        str_contains($uri, '#') || str_contains($uri, '://')) {
        return null;
    }
    $path = wp_parse_url($uri, PHP_URL_PATH);
    if (!is_string($path) || $path === '') { return null; }
    $slug = basename(untrailingslashit($path));
    if (!preg_match('/^[a-z0-9_-]+$/D', $slug)) { return null; }
    $terms = get_terms([
        'taxonomy' => 'destinations',
        'slug' => $slug,
        'hide_empty' => false,
        'number' => 20,
    ]);
    if (is_wp_error($terms) || !is_array($terms)) { return null; }
    foreach ($terms as $term) {
        if (!$term instanceof WP_Term) { continue; }
        $permalink = get_term_link($term);
        if (is_wp_error($permalink)) { continue; }
        $canonical = wp_parse_url($permalink, PHP_URL_PATH);
        if (!is_string($canonical)) { continue; }
        $target_parts = explode('/', trim(untrailingslashit($canonical), '/'));
        $request_parts = explode('/', trim(untrailingslashit($path), '/'));
        if (count($target_parts) !== count($request_parts)) { continue; }
        $aliases = get_option('company_destination_path_aliases', []);
        if (!is_array($aliases)) { $aliases = []; }
        $matches = true;
        foreach ($target_parts as $position => $segment) {
            $provided = $request_parts[$position] ?? '';
            if ($provided === $segment) { continue; }
            // Explicitly configured historical slug aliases, not fuzzy matching.
            if (!isset($aliases[$provided]) || $aliases[$provided] !== $segment ||
                !preg_match('/^[a-z0-9_-]+$/D', $provided)) {
                $matches = false;
                break;
            }
        }
        if ($matches) {
            $resolved = $term;
            return $term;
        }
    }
    return null;
}

/** Scope only a native Post Query which already declares a destination taxonomy clause. */
add_filter('jet-engine/query-builder/types/posts-query/args', static function ($args, $query) {
    if (!wp_doing_ajax() || ($_REQUEST['action'] ?? '') !== 'jet_smart_filters') {
        return $args;
    }
    if (!is_array($args) || !is_object($query)) { return $args; }
    $definition = is_array($query->query ?? null) ? $query->query : [];
    $types = (array)($definition['post_type'] ?? []);
    $allowed = ['tours-and-activities', 'properties', 'transportations'];
    if (!$types || array_diff($types, $allowed)) { return $args; }

    $has_destination = false;
    foreach ((array)($definition['tax_query'] ?? []) as $clause) {
        if (is_array($clause) && ($clause['taxonomy'] ?? '') === 'destinations') {
            $has_destination = true;
            break;
        }
    }
    if (!$has_destination) { return $args; }

    // A signed Property Archive filter is not a Destination Term listing.
    // Query #239 includes dormant destination facets, but its scope is the
    // authored Property archive. Preserve native JSF security and original
    // public/prop_puplic eligibility; do not manufacture destination context.
    if ((int)($query->id ?? 0) === 239
        && (string)($query->query_id ?? '') === 'property_query_archive'
        && $types === ['properties']
        && ($_POST['provider'] ?? '') === 'jet-engine/property_query_archive') {
        $settings = $_POST['settings'] ?? null;
        $referrer = $_POST['referrer']['uri'] ?? null;
        if (is_array($settings) && is_string($referrer)
            && (string)($settings['custom_query_id'] ?? '') === '239'
            && strlen($referrer) < 1024
            && preg_match('~^/(?:ar/|de/|fr/|es/|it/)?properties/(?:jsf/[a-z0-9_:/\\-]+/)?$~D',
                (string)wp_parse_url(wp_unslash($referrer), PHP_URL_PATH))) {
            $listing = absint($settings['lisitng_id'] ?? 0);
            $trid = $listing ? (int)apply_filters('wpml_element_trid', null, $listing, 'post_jet-engine') : 0;
            $listTrid = (int)apply_filters('wpml_element_trid', null, 37916, 'post_jet-engine');
            $gridTrid = (int)apply_filters('wpml_element_trid', null, 37910, 'post_jet-engine');
            if ($listing && $trid && in_array($trid, [$listTrid, $gridTrid], true)
                && get_post_type($listing) === 'jet-engine'
                && get_post_status($listing) === 'publish'
                && function_exists('jet_smart_filters')
                && is_callable([jet_smart_filters()->render, 'verify_request_signature'])
                && jet_smart_filters()->render->verify_request_signature()) {
                return $args;
            }
        }
    }

    // Query Builder #5 with its signed JetSmartFilters Tours archive provider
    // is NOT a Destination Term archive. Its selected destination facets are
    // already present in $args['tax_query']; do not require a Term referrer or
    // force post__in=[0]. The native Tour/public eligibility guards still apply.
    // The shared MU Presentation contract recognizes native Tours archive URLs,
    // including permitted query-string display context such as ?wcmlc=EUR.
    // Native JSF still verifies the original listing signature and submitted facets.
    // All unrelated/invalid destination requests retain the fail-closed branch.
    $contract = function_exists('shared_presentation_catalog_contract_v1')
        ? shared_presentation_catalog_contract_v1() : [];
    if ((int)($query->id ?? 0) === (int)($contract['query_id'] ?? 0) &&
        function_exists('shared_presentation_catalog_jsf_request_v1') &&
        is_array(shared_presentation_catalog_jsf_request_v1())) {
        return $args;
    }
    $term = company_ajax_destination_term();
    if (!$term) {
        $args['post__in'] = [0]; // No cross-destination fallback when referrer is absent.
        return $args;
    }

    $tax = is_array($args['tax_query'] ?? null) ? $args['tax_query'] : [];
    $found = false;
    foreach ($tax as &$clause) {
        if (is_array($clause) && ($clause['taxonomy'] ?? '') === 'destinations') {
            $clause['field'] = 'term_id';
            $clause['terms'] = [(int)$term->term_id];
            $clause['operator'] = 'IN';
            $found = true;
        }
    }
    unset($clause);
    if (!$found) {
        $tax[] = [
            'taxonomy' => 'destinations',
            'field' => 'term_id',
            'terms' => [(int)$term->term_id],
            'operator' => 'IN',
        ];
    }
    $args['tax_query'] = $tax;
    return $args;
}, 950, 2);

/** Always resolve the native search widget's scope from the public term. */
add_action('elementor/frontend/widget/before_render', static function ($widget) {
    if (is_admin() || !is_tax('destinations') || !is_object($widget) ||
        !method_exists($widget, 'get_name') || $widget->get_name() !== 'jet-ajax-search') {
        return;
    }
    $term = get_queried_object();
    if ($term instanceof WP_Term && $term->taxonomy === 'destinations') {
        $widget->set_settings('include_terms_ids', [(string)$term->term_id]);
    }
}, 50);

// An AJAX request may not have a queried main WP taxonomy object.
// The validated public destination path supplies display scope ONLY.
add_filter('company_recommendation_context', static function ($context) {
    $term = company_ajax_destination_term();
    if ($term instanceof WP_Term) {
        $context['surface'] = 'destination';
        $context['destination_id'] = (int)$term->term_id;
    }
    return $context;
}, 60);



// Render-time scoping for JetSearch's hidden JSON configuration.
// Elementor templates stay reusable: never store the previewed term ID.
add_filter('elementor/widget/render_content', static function ($content, $widget) {
    if (!is_string($content) || !is_tax('destinations') ||
        !is_object($widget) || !method_exists($widget, 'get_name') ||
        $widget->get_name() !== 'jet-ajax-search') {
        return $content;
    }
    $term = get_queried_object();
    if (!$term instanceof WP_Term || $term->taxonomy !== 'destinations') {
        return $content;
    }
    return preg_replace_callback(
        '~<input\b[^>]*\bname="jet_ajax_search_settings"[^>]*>~i',
        static function ($matches) use ($term) {
            $tag = $matches[0];
            if (!preg_match('~\bvalue="([^"]*)"~', $tag, $value_match)) {
                return $tag;
            }
            $raw = html_entity_decode($value_match[1], ENT_QUOTES, 'UTF-8');
            $settings = json_decode($raw, true);
            if (!is_array($settings)) { return $tag; }
            $settings['include_terms_ids'] = [(int)$term->term_id];
            $replacement = 'value="' . esc_attr(wp_json_encode($settings)) . '"';
            return str_replace($value_match[0], $replacement, $tag);
        },
        $content
    );
}, 80, 2);
