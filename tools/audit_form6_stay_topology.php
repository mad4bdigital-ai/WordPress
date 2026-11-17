<?php
/**
 * Read-only WP-CLI booking stay topology audit.
 * Run: wp --path=/var/www/html eval-file /tmp/audit_form6_stay_topology.php
 * Never writes Rate, Property, itinerary, price, supplier or order data.
 */
if (!defined('ABSPATH') || !function_exists('BookingExperience\tour_stay_rate_plan')) {
    fwrite(STDERR, "BookingExperience unavailable\n");
    exit(2);
}
$rates = get_posts([
    'post_type' => 'tour-rates', 'post_status' => 'publish',
    'posts_per_page' => -1, 'fields' => 'ids', 'suppress_filters' => true
]);
$counts = [
    'published_rates' => count($rates), 'stay_rates' => 0,
    'simple_or' => 0, 'multi_stage' => 0, 'multi_property' => 0,
    'no_common_destination' => 0, 'missing_destination_terms' => 0,
    'included' => 0, 'inquiry' => 0, 'additional' => 0, 'other' => 0
];
$candidates = [];
foreach ($rates as $rate) {
    $rate = (int) $rate;
    $plan = BookingExperience\tour_stay_rate_plan($rate);
    if (!is_array($plan)) continue;
    $properties = array_values(array_unique(array_map('intval', (array)($plan['properties'] ?? []))));
    $stages = (array)($plan['stages'] ?? []);
    if (!$properties && !$stages) continue;
    $counts['stay_rates']++;
    $mode = (string)($plan['mode'] ?? '');
    if ($mode === 'simple_or') $counts['simple_or']++;
    elseif ($mode === 'multi_stage') $counts['multi_stage']++;
    $policy = (string)($plan['price_policy'] ?? '');
    $counts[array_key_exists($policy, $counts) ? $policy : 'other']++;
    if ($mode !== 'simple_or' || count($properties) < 2) continue;
    $counts['multi_property']++;
    $locations = [];
    $common = null;
    $missing = false;
    foreach ($properties as $propertyId) {
        $terms = wp_get_post_terms($propertyId, 'destinations', ['fields' => 'slugs']);
        $slugs = is_wp_error($terms) ? [] : array_values(array_unique(array_map('strval', $terms)));
        if (!$slugs) $missing = true;
        $locations[(string)$propertyId] = $slugs;
        $common = $common === null ? $slugs : array_values(array_intersect($common, $slugs));
    }
    if ($missing) $counts['missing_destination_terms']++;
    if ($common) continue;
    $counts['no_common_destination']++;
    $candidates[] = [
        'tour_id' => (int)get_post_meta($rate, 'related_tour_id', true),
        'rate_id' => $rate, 'property_destinations' => $locations,
        'missing_terms' => $missing,
        'review_only' => true
    ];
}
echo wp_json_encode([
    'contract' => 'mad4b.form6.stay-topology-readonly-audit.v1',
    'site' => home_url('/'),
    'counts' => $counts, 'candidates' => $candidates,
    'automatic_conversion' => false,
    'financial_policy_mutated' => false,
    'supplier_confirmations_created' => false
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
echo "\n";
