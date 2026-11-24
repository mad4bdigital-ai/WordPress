<?php
/**
 * Run in an authorized WP-CLI WordPress session with a read-capable account:
 * wp eval-file tests/mad4b-mcp-entity-labels-v1.php --allow-root --user=1
 * Read-only contract acceptance; no WooCommerce/FluentForm/cost writes.
 */
defined('ABSPATH') || exit;
$ability = function_exists('wp_get_ability') ? wp_get_ability('mad4b/entity-reference-resolve') : null;
if (!$ability) throw new RuntimeException('entity_resolver_not_registered');
$tests = [
    ['id' => 2348, 'field' => 'program_id'],
    ['id' => 60629, 'field' => 'tour_id'],
    ['id' => 36084, 'field' => 'property_id'],
    ['id' => 2354, 'field' => 'tier_id'],
    ['id' => 2348],
    ['id' => 36084, 'entity_type' => 'programs'],
];
$before = (string)apply_filters('wpml_current_language', '');
$out = $ability->execute(['references' => $tests]);
if (is_wp_error($out)) throw new RuntimeException('resolver_denied_or_failed');
if (($out['count'] ?? 0) !== 6 || ($out['resolved_count'] ?? 0) !== 4 ||
    ($out['read_only'] ?? false) !== true || ($out['mutation_performed'] ?? true) !== false)
    throw new RuntimeException('resolver_contract_or_count_incorrect');
foreach ([0, 1, 2, 3] as $i) {
    $r = $out['items'][$i];
    if (empty($r['name']) || empty($r['type']) ||
        !str_starts_with($r['display_label'], (string)$tests[$i]['id']))
        throw new RuntimeException('missing_id_name_type_' . $i);
}
if (($out['items'][4]['resolution_status'] ?? '') !== 'type_required_for_ambiguous_id' ||
    ($out['items'][5]['resolution_status'] ?? '') !== 'term_not_found')
    throw new RuntimeException('ambiguous_entity_disambiguation_regression');
$localized = $ability->execute([
    'references' => [['id' => 2348, 'entity_type' => 'programs']],
    'language' => 'es',
]);
if (is_wp_error($localized) ||
    ($localized['items'][0]['id'] ?? 0) !== 2348 ||
    ($localized['items'][0]['resolved_id'] ?? 0) !== 2445 ||
    ($localized['items'][0]['language'] ?? '') !== 'es')
    throw new RuntimeException('wpml_locale_identity_regression');
if ($before !== (string)apply_filters('wpml_current_language', ''))
    throw new RuntimeException('wpml_original_language_not_restored');
$catalog = MAD4B_SCP_Servers::core_tools('mad4b-read');
if (!in_array('mad4b/entity-reference-resolve', $catalog, true))
    throw new RuntimeException('read_mcp_surface_not_mounted');
echo wp_json_encode([
    'site' => home_url(),
    'contract' => 'mad4b.entity-reference-labels.live-acceptance.v1',
    'checks_passed' => 9,
    'sample' => $out['items'],
    'localized_program' => $localized['items'][0],
    'mounted_on_read' => true,
    'read_only' => true,
    'mutation_performed' => false,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;

