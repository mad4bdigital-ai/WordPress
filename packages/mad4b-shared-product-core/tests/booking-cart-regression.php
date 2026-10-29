<?php
/** Regression: cart's normalized identity must not enter public quote validation. */
error_reporting(E_ALL);
define('ABSPATH', __DIR__);
define('SHARED_PRODUCT_LAB_ONLY', true);
final class WP_Error {
    public function __construct(private string $code) {}
    public function get_error_code(): string { return $this->code; }
}
function is_wp_error($value): bool { return $value instanceof WP_Error; }
function get_post_meta($id, $name, $single = true): string { return $name === 'tour_mode' ? 'tour' : ''; }
function shared_product_booking_v2_cart_identity($config, $product): array {
    return ['product_id' => $product, 'configuration' => $config + ['normalized_internal' => true]];
}
function shared_product_booking_v2_preview($config) {
    if (isset($config['normalized_internal'])) return new WP_Error('booking_v2_unexpected_keys');
    return ['currency' => 'USD', 'total' => $config['tour_id'] === 101 ? 80.25 : 20.0];
}
require __DIR__ . '/../mu-plugins/shared-product-core/pricing/booking-cart-v2.php';
$input = [['tour_id' => 101], ['tour_id' => 102]];
$draft = shared_product_booking_v2_cart_draft($input);
if (is_wp_error($draft) || $draft['line_count'] !== 2
    || empty($draft['cart_lines'][0]['configuration']['normalized_internal'])) {
    fwrite(STDERR, "DRAFT_IDENTITY_FAILED\n"); exit(1);
}
$quote = shared_product_booking_v2_cart_quote_preview($input);
if (is_wp_error($quote) || $quote['total'] !== 100.25 || count($quote['lines']) !== 2
    || $quote['status'] !== 'preview_only_no_cart_mutation') {
    fwrite(STDERR, "CART_QUOTE_INPUT_SHAPE_FAILED\n"); exit(1);
}
echo "CART_QUOTE_INPUT_SHAPE_PASS\n";
