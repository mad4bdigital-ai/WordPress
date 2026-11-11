<?php
/**
 * Plugin Name: MAD4B – Portable Archive Runtime Profile
 * Description: Supply read-only Elementor/JetEngine presentation capability to the tour archive even before company DFSB profile provisioning. SEO publication remains independent.
 * Version: 1.0.0
 */
defined('ABSPATH') || exit;

/**
 * Effective, non-persistent compatibility overlay for the company profile registry.
 * Never copy another site's identity, URLs, publication approvals, credentials or term IDs.
 * A missing persisted DFSB profile is not an archive rendering blocker.
 */
function mad4b_archive_runtime_profiles_v1($profiles, $config = null): array {
    $profiles = is_array($profiles) ? $profiles : [];
    $existingKey = null;
    foreach ($profiles as $key => $profile) {
        if (is_array($profile) && sanitize_key((string)($profile['id'] ?? $key)) === 'tours') {
            $existingKey = $key;
            break;
        }
    }
    if ($existingKey === null) {
        // Only register an inert, tour-archive-specific fallback. Source content
        // and site-specific taxonomies are resolved by the current WordPress site.
        $rules = [];
        foreach (['destinations', 'tour-types', 'tour-styles'] as $index => $taxonomy) {
            $rules[$taxonomy] = [
                'role' => $taxonomy, 'priority' => ($index + 1) * 10,
                'index_single' => false, 'min_results' => 1,
            ];
        }
        $profiles['tours'] = [
            'id' => 'tours', 'enabled' => false,
            'inherit_global_defaults' => false,
            'post_types' => ['tours-and-activities'],
            'archive_slugs' => ['tours-and-activities'],
            'archive_paths' => ['/tours-and-activities/'],
            'providers' => ['jet-engine'],
            'query_ids' => ['tours_query_archive'],
            'routes' => [[
                'provider' => 'jet-engine', 'query_id' => 'tours_query_archive',
                'provider_query_id' => 'tours_query_archive',
                'query_builder_query_id' => 'tours_query_archive',
            ]],
            'taxonomy_rules' => $rules,
            'allowed_taxonomy_sets' => ['destinations', 'tour-types', 'tour-styles'],
            'required_capabilities' => ['presentation.dynamic_content'],
            'publication' => [
                'metadata' => false, 'sitemap' => false, 'hreflang' => false,
                'multilingual' => false, 'schema' => false, 'social' => false,
                'include_images_in_sitemap' => false,
                'require_elementor_content' => false,
                'elementor_render_when_global_off' => true,
            ],
        ];
    } else {
        // Don't overwrite the company's existing profile or SEO authorization;
        // add just the independent presentation capability to a tours profile.
        $profile = $profiles[$existingKey];
        $publication = is_array($profile['publication'] ?? null) ? $profile['publication'] : [];
        $publication['elementor_render_when_global_off'] = true;
        $profile['publication'] = $publication;
        $capabilities = (array)($profile['required_capabilities'] ?? []);
        $capabilities[] = 'presentation.dynamic_content';
        $profile['required_capabilities'] = array_values(array_unique($capabilities));
        $profiles[$existingKey] = $profile;
    }
    return $profiles;
}
add_filter('etg_filter_seo_surface_profiles', 'mad4b_archive_runtime_profiles_v1', 20, 2);
