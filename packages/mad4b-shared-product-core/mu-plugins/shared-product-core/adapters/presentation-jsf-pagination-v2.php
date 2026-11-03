<?php
/** Browser-facing adapter to the *native* JetSmartFilters paginator object. */
defined('ABSPATH') || exit;
if (wp_get_environment_type() !== 'local') return;

add_action('wp_enqueue_scripts', static function (): void {
    // This script only serves the registered original Tour archive context.
    $context = shared_presentation_catalog_url_context_v1(
        (string)wp_parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) .
        (isset($_SERVER['QUERY_STRING']) && $_SERVER['QUERY_STRING'] !== ''
            ? '?' . $_SERVER['QUERY_STRING'] : '')
    );
    if (!$context) return;
    $registry = shared_presentation_catalog_contract_v1();
    if (($registry['query_id'] ?? null) !== 5) return;
    $handle = 'shared-presentation-jsf-pagination-v2';
    wp_enqueue_script(
        $handle,
        plugin_dir_url(__FILE__) . '../assets/presentation-jsf-pagination-v2.js',
        ['jet-smart-filters'],
        '2.0.0',
        true
    );
    wp_add_inline_script(
        $handle,
        'window.SharedPresentationPaginationV2=' . wp_json_encode([
            'slot' => 'tour.archive.results',
            'provider' => $registry['provider'],
        ]) . ';',
        'before'
    );
}, 100);
