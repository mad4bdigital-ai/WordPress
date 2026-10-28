<?php
/**
 * MU Presentation v2 — native provider descriptors.
 * Registry only: owning JetEngine/Elementor/FluentForms settings and commercial
 * source rates remain authoritative, and this registry grants no write rights.
 */
defined('ABSPATH') || exit;

function shared_presentation_registry_v2(): array {
    $providers = [
        'tours.archive' => [
            'slot' => 'tour.archive.results',
            'provider' => 'jet-engine/tours_query_archive',
            'query_id' => 5,
            'listing_id' => 25102,
            'entity_type' => 'tours-and-activities',
            'taxonomies' => ['destinations', 'tour-types', 'tour-styles'],
            'price_filter_ids' => [24515,26043,26044,26045,41896],
            'price_source' => 'shared_lab_tour_price_resolve',
            'currency_source' => 'wcml_client_currency',
        ],
    ];
    return apply_filters('shared_presentation_registry_v2', $providers);
}

/** Fail closed when a dynamic registry contains a malformed or stale descriptor. */
function shared_presentation_provider_v2(string $slot): ?array {
    $registry = shared_presentation_registry_v2();
    $source = $registry[$slot] ?? null;
    if (!is_array($source) || empty($source['provider']) ||
        !is_string($source['provider']) || !ctype_digit((string)($source['query_id'] ?? '')) ||
        !ctype_digit((string)($source['listing_id'] ?? ''))) return null;
    return $source;
}
