<?php
/**
 * Explicitly opted-in, WordPress-backed, read-only booking quote smoke.
 *
 * Example: MAD4B_SMOKE_READONLY=1 MAD4B_SMOKE_SITE_ROOT=/var/www/html
 *          MAD4B_SMOKE_TOUR_ID=... MAD4B_SMOKE_RATE_ID=...
 *          MAD4B_SMOKE_DATE=YYYY-MM-DD php tests/live-booking-readonly.php
 *
 * Never creates WooCommerce carts, orders, bookings or payments.
 */
if (PHP_SAPI !== 'cli' || getenv('MAD4B_SMOKE_READONLY') !== '1') {
    fwrite(STDERR, "OPT_IN_REQUIRED\n");
    exit(2);
}
$root = getenv('MAD4B_SMOKE_SITE_ROOT');
if (!is_string($root) || $root === '' || !is_file(rtrim($root, '/') . '/wp-load.php')) {
    fwrite(STDERR, "WP_ROOT_REQUIRED\n");
    exit(2);
}
require_once rtrim($root, '/') . '/wp-load.php';
if (wp_get_environment_type() !== 'local'
    || !defined('SHARED_PRODUCT_LAB_ONLY')
    || SHARED_PRODUCT_LAB_ONLY !== true
    || !class_exists('WooCommerce', false)) {
    fwrite(STDERR, "LOCAL_WOOCOMMERCE_LAB_ONLY\n");
    exit(2);
}
$tour = filter_var(getenv('MAD4B_SMOKE_TOUR_ID'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$rate = filter_var(getenv('MAD4B_SMOKE_RATE_ID'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$date = getenv('MAD4B_SMOKE_DATE');
if (!$tour || !$rate || !is_string($date) || !preg_match('/^\\d{4}-\\d{2}-\\d{2}$/D', $date)
    || DateTimeImmutable::createFromFormat('!Y-m-d', $date)?->format('Y-m-d') !== $date) {
    fwrite(STDERR, "VALID_FIXTURE_REQUIRED\n");
    exit(2);
}
if (!function_exists('shared_product_booking_v2_validate')
    || !function_exists('shared_product_booking_v2_preview')
    || !function_exists('shared_product_tour_itinerary_contract_v1')) {
    fwrite(STDERR, "ENGINE_NOT_LOADED\n");
    exit(2);
}
$payload = [
    'schema_version' => 2,
    'tour_id' => $tour,
    'rate_id' => $rate,
    'source' => 'rate',
    'travel_date' => $date,
    'quantities' => ['persons' => 2],
    'audience' => 'general',
    'occupancy' => 'double',
    'booking_intent' => 'quote',
    'selections' => [],
    'extras' => [],
];
$failures = [];
$valid = shared_product_booking_v2_validate($payload);
if (is_wp_error($valid)) {
    $failures[] = 'VALIDATION:' . $valid->get_error_code();
}
$badDate = $payload;
$badDate['travel_date'] = 'invalid-date';
$dateError = shared_product_booking_v2_validate($badDate);
if (!is_wp_error($dateError) || $dateError->get_error_code() !== 'booking_v2_date_invalid') {
    $failures[] = 'BAD_DATE_NOT_REJECTED';
}
$badPrice = $payload;
$badPrice['price'] = 0.01;
$priceError = shared_product_booking_v2_validate($badPrice);
if (!is_wp_error($priceError) || $priceError->get_error_code() !== 'booking_v2_unexpected_keys') {
    $failures[] = 'CLIENT_PRICE_NOT_REJECTED';
}
$badRate = $payload;
$badRate['rate_id'] = PHP_INT_MAX;
$rateError = shared_product_booking_v2_validate($badRate);
if (!is_wp_error($rateError) || $rateError->get_error_code() !== 'booking_v2_rate_mismatch') {
    $failures[] = 'UNRELATED_RATE_NOT_REJECTED';
}
$quote = is_wp_error($valid) ? $valid : shared_product_booking_v2_preview($payload);
if (is_wp_error($quote)) {
    $failures[] = 'PREVIEW:' . $quote->get_error_code();
} elseif (!is_numeric($quote['total'] ?? null) || (float)$quote['total'] <= 0
    || !preg_match('/^[A-Z]{3}$/D', (string)($quote['currency'] ?? ''))) {
    $failures[] = 'INVALID_SERVER_QUOTE';
}
$result = [
    'contract' => 'mad4b.commerce.live-readonly-smoke.v1',
    'environment' => 'local',
    'tour_id' => $tour,
    'rate_id' => $rate,
    'travel_date' => $date,
    'server_quote_available' => !is_wp_error($quote),
    'currency' => is_array($quote) ? ($quote['currency'] ?? null) : null,
    'quoted_total' => is_array($quote) ? ($quote['total'] ?? null) : null,
    'client_price_rejected' => is_wp_error($priceError),
    'unrelated_rate_rejected' => is_wp_error($rateError),
    'invalid_date_rejected' => is_wp_error($dateError),
    'cart_order_booking_write_performed_by_script' => false,
    'failures' => $failures,
];
echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
exit($failures ? 1 : 0);
