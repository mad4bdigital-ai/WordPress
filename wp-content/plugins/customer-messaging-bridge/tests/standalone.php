<?php
/**
 * Standalone smoke tests for the WordPress adapter. No WordPress install,
 * database, real user, or external API requests required.
 */
declare(strict_types=1);
define('ABSPATH', '/tmp/wordpress-test/');
define('DAY_IN_SECONDS', 86400);
$GLOBALS['cmb_test_options'] = [];
function add_action(...$args): void {}
function add_shortcode(...$args): void {}
function get_option($name, $default = false) { return $GLOBALS['cmb_test_options'][$name] ?? $default; }
function wp_parse_args($args, $defaults = []) { return array_merge($defaults, (array) $args); }
function sanitize_text_field($v) { return strip_tags(trim((string) $v)); }
function sanitize_key($value) { return preg_replace('/[^a-z0-9_\-]/', '', strtolower((string) $value)); }
function wp_salt($scheme = 'auth') { return 'standalone-test-salt-not-a-real-site-secret'; }
function update_option($key, $value, $autoload = false) { $GLOBALS['cmb_test_options'][$key] = $value; return true; }
function add_option($key, $value, $deprecated = '', $autoload = false) {
    if (array_key_exists($key, $GLOBALS['cmb_test_options'])) { return false; }
    $GLOBALS['cmb_test_options'][$key] = $value; return true;
}
function delete_option($key) { unset($GLOBALS['cmb_test_options'][$key]); return true; }
function apply_filters($key, $value) { return $value; }
function get_transient($key) { return $GLOBALS['cmb_transient'][$key] ?? false; }
function set_transient($key, $value, $expiration) { $GLOBALS['cmb_transient'][$key] = $value; return true; }
function wp_remote_get($url, $args = []) {
    $GLOBALS['cmb_last_http_url'] = $url;
    $GLOBALS['cmb_last_http_options'] = $args;
    $query = parse_url($url, PHP_URL_QUERY) ?: '';
    parse_str($query, $params);
    $page = (int) ($params['page'] ?? 1);
    if (isset($GLOBALS['cmb_http_pages'][$page])) { return $GLOBALS['cmb_http_pages'][$page]; }
    return $GLOBALS['cmb_next_http_result'] ?? ['response' => ['code' => 200], 'body' => '{"data": [], "pageCount": 1}'];
}
function wp_remote_retrieve_response_code($value) { return (int) ($value['response']['code'] ?? 0); }
function wp_remote_retrieve_body($value) { return $value['body'] ?? ''; }
function rest_ensure_response($data) { return $data; }
function current_user_can($cap) { return $cap === 'manage_options'; }
function is_wp_error($value) { return $value instanceof WP_Error; }
class WP_Error { public function __construct(public $code, public $message, public $data = []) {} }

function wp_json_encode($value, $options = 0) { return json_encode($value, $options); }
function esc_url($v) { return htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function esc_attr($v) { return htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function is_admin(): bool { return false; }
function assert_true($ok, $label): void {
    if (!$ok) { fwrite(STDERR, "FAIL: " . $label . "\n"); exit(1); }
    echo "PASS: " . $label . "\n";
}
require dirname(__DIR__) . '/customer-messaging-bridge.php';
$klass = Customer_Messaging_Bridge::class;
$safe = $klass::sanitize([
    'origin' => 'http://example.invalid:4444/unsafe',
    'workspace_id' => '11755877814459770',
    'webchat_id' => '11755877814902375',
    'brand_color' => '#AA12Ff',
    'enabled' => '1',
    'woo_local_audit' => '1',
]);
assert_true($safe['origin'] === 'https://chatbotx.mad4b.com', 'fixed safe endpoint');
assert_true($safe['brand_color'] === '#aa12ff', 'valid brand color normalized');
assert_true($safe['workspace_id'] === '11755877814459770', 'valid workspace ID');
assert_true($safe['webchat_id'] === '11755877814902375', 'valid webchat ID');
assert_true($safe['enabled'] === '1', 'enabled when explicitly requested');
$bad = $klass::sanitize(['workspace_id' => '42;select 1', 'webchat_id' => '1e3', 'brand_color' => 'red']);
assert_true($bad['workspace_id'] === '' && $bad['webchat_id'] === '', 'invalid IDs discarded');
assert_true($bad['brand_color'] === '#2563eb', 'invalid color rejected');
assert_true(!$klass::ready(), 'absent settings do not bootstrap widget');
$GLOBALS['cmb_test_options'][$klass::OPTION] = $safe;
assert_true($klass::ready(), 'configured settings permit widget bootstrap');
ob_start();
$klass::render_footer();
$klass::render_footer();
$html = ob_get_clean();
assert_true(substr_count($html, '/chat-widget/plugin.js') === 1, 'widget initializes once only');
assert_true(strpos($html, '11755877814902375') !== false, 'webchat resource reference rendered');
assert_true(strpos($html, '11755877814459770') !== false, 'workspace resource reference rendered');
assert_true(strpos($html, 'api_token') === false, 'no credentials exposed to frontend');

assert_true(CMB_Channels::api_token() === '', 'no API token stored by default');
assert_true(CMB_Channels::configured_inboxes()['state'] === 'needs_token', 'channel discovery stays read-only and requires token');
assert_true(count(CMB_Channels::CHANNELS) === 5, 'live-chat WhatsApp Messenger Instagram and Email modeled');
assert_true(count(CMB_Channels::FEATURES) >= 7, 'CRM commerce carts automations AI and inbox modules modeled');
assert_true(CMB_Commerce::settings()['orders'] === '0', 'order event capture starts disabled');
assert_true(CMB_Commerce::settings()['abandoned_cart'] === '0', 'cart observation starts disabled');
assert_true(!CMB_Commerce::consent_allowed(), 'default marketing consent is denied');
assert_true(!CMB_Commerce::record('order.created', 'order-test-100'), 'cannot record when event capture disabled');
$commerce = CMB_Commerce::sanitize(['orders' => '1', 'customers'=>'1', 'abandoned_cart'=>'1', 'local_event_capture'=>'1', 'cart_idle_minutes'=>'2', 'consent_required'=>'0']);
assert_true($commerce['cart_idle_minutes'] === 15, 'cart throttle interval has a lower bound');
assert_true($commerce['consent_required'] === '1', 'customer consent requirement cannot be disabled');
$GLOBALS['cmb_test_options'][CMB_Commerce::OPTION] = $commerce;
assert_true(CMB_Commerce::record('order.payment_complete', 'order-test-payment', ['status'=>'paid']), 'Woo payment completion observed');
assert_true(CMB_Commerce::record('order.refunded', 'order-test-refund-1', ['status'=>'refunded']), 'partial refund observed distinctly');
assert_true(CMB_Commerce::record('order.refunded', 'order-test-refund-2', ['status'=>'refunded']), 'multiple refunds observed distinctly');
assert_true(CMB_Commerce::record('order.payment_failed', 'order-test-failed', ['status'=>'failed']), 'payment failure observed');
assert_true(CMB_Commerce::record('order.cancelled', 'order-test-cancelled', ['status'=>'cancelled']), 'cancellation observed');
assert_true(CMB_Commerce::record('order.created', 'order-test-100'), 'first order event captured');
assert_true(!CMB_Commerce::record('order.created', 'order-test-100'), 'duplicate order event rejected');
assert_true(CMB_Commerce::record('order.status_changed', 'order-test-100', ['status'=>'completed','revision'=>'processing-completed']), 'status change event captured');
assert_true(CMB_Commerce::record('order.status_changed', 'order-test-100', ['status'=>'completed','revision'=>'pending-completed']), 'distinct transition to same final status retained');
assert_true(!CMB_Commerce::record('unknown', 'order-test-100'), 'unrecognized event rejected');
$items = get_option(CMB_Commerce::OUTBOX, []);
assert_true(count($items) === 8, 'eight unique local event records');
assert_true(strpos(json_encode($items), 'order-test-100') === false, 'raw order identifiers are not persisted');
assert_true(strpos(json_encode($items), 'email') === false, 'no customer personal information in event records');
for ($i=0; $i<220; $i++) {
    CMB_Commerce::record('order.created', 'test-' . $i);
}
assert_true(count(get_option(CMB_Commerce::OUTBOX, [])) === CMB_Commerce::MAX_EVENTS, 'event history remains bounded');
// API contract: external origin is fixed, route includes /api/, and scope is
// defined by the upstream ChatbotX API's singular `inbox` scope.
define('CMB_CHATBOTX_WORKSPACE_TOKEN', 'cbx_ws_standalone_testing_nonsecret_000000');
$GLOBALS['cmb_next_http_result'] = [
    'response' => ['code' => 200],
    'body' => '{"data":[{"id":"11755877814902375","channel":"webchat","name":"ETG","status":"connected"},{"id":"25","channel":"whatsapp","name":"Whatsapp","status":"disconnected"},{"id":"77","channel":"instagram","name":"IG","status":"connected"}],"pageCount":1}',
];
$channels = CMB_Channels::configured_inboxes(true);
assert_true($channels['state'] === 'connected', 'authorized ChatbotX inbox response accepted');
assert_true($GLOBALS['cmb_last_http_url'] === 'https://chatbotx.mad4b.com/api/v1/inboxes?page=1&perPage=50', 'authorized channel API endpoint uses canonical paginated route');
assert_true($GLOBALS['cmb_last_http_options']['headers']['Authorization'] === 'Bearer ' . CMB_CHATBOTX_WORKSPACE_TOKEN, 'API token passed only as bearer header');
assert_true($GLOBALS['cmb_last_http_options']['redirection'] === 0 && $GLOBALS['cmb_last_http_options']['sslverify'] === true, 'API request disallows redirects and verifies TLS');
assert_true(count($channels['inboxes']) === 3 && $channels['inboxes'][1]['status'] === 'disconnected', 'channel statuses preserved instead of treating every inbox as connected');
assert_true(str_contains(file_get_contents(dirname(__DIR__) . '/README.md'), 'only the `inbox` scope'), 'README describes correct singular inbox scope');
$GLOBALS['cmb_next_http_result'] = ['response' => ['code' => 401], 'body' => '{"code":"INVALID_CHATBOT_TOKEN"}'];
$invalidTokenResult = CMB_Channels::configured_inboxes(true);
assert_true($invalidTokenResult['state'] === 'http_error' && $invalidTokenResult['error'] === '401', 'unauthorized token rejected and status reported');
$GLOBALS['cmb_next_http_result'] = ['response' => ['code' => 200], 'body' => '{"pageCount":1}'];
assert_true(CMB_Channels::configured_inboxes(true)['state'] === 'invalid_response', 'invalid ChatbotX schema rejected');
$GLOBALS['cmb_next_http_result'] = ['response' => ['code' => 200], 'body' => '{"data":[]}'];
assert_true(CMB_Channels::configured_inboxes(true)['state'] === 'connected', 'empty inbox list accepted');
$GLOBALS['cmb_http_pages'] = [
    1 => ['response' => ['code' => 200], 'body' => '{"pageCount":2,"data":[{"id":"10","channel":"webchat","status":"connected"}]}'],
    2 => ['response' => ['code' => 200], 'body' => '{"pageCount":2,"data":[{"id":"11","channel":"whatsapp","status":"connected"}]}'],
];
$paged = CMB_Channels::configured_inboxes(true);
assert_true($paged['state'] === 'connected' && count($paged['inboxes']) === 2, 'all inbox pages are collected');
assert_true(str_contains($GLOBALS['cmb_last_http_url'], 'page=2&perPage=50'), 'second authorized API page requested');
$GLOBALS['cmb_http_pages'][1] = ['response' => ['code' => 200], 'body' => '{"pageCount":11,"data":[]}'];
assert_true(CMB_Channels::configured_inboxes(true)['state'] === 'invalid_pagination', 'more than ten pages fails closed instead of silently truncating');
unset($GLOBALS['cmb_http_pages']);

assert_true(CMB_Webhooks::secret() === '', 'inbound receiver disabled by default');
assert_true(!CMB_Webhooks::verify('{"kind":"message.received"}', (string) time(), 'sha256=' . str_repeat('a',64)), 'unconfigured webhook always denied');
define('CMB_INBOUND_HMAC_SECRET', str_repeat('long-local-only-secret', 3));
$now = 1793100000;
$body = '{"kind":"message.received","workspaceId":"11755877814459770","id":"event-1"}';
$timestamp = (string) $now;
$signature = 'sha256=' . hash_hmac('sha256', $timestamp . '.' . $body, CMB_INBOUND_HMAC_SECRET);
assert_true(CMB_Webhooks::verify($body, $timestamp, $signature, $now), 'correct HMAC accepted');
assert_true(!CMB_Webhooks::verify($body, $timestamp, $signature, $now + 301), 'stale timestamp denied');
assert_true(!CMB_Webhooks::verify($body . ' ', $timestamp, $signature, $now), 'body tampering denied');
assert_true(!CMB_Webhooks::verify($body, $timestamp, 'sha256=' . str_repeat('0',64), $now), 'bad signature denied');

class CMB_Test_Request {
    public function __construct(private string $body, private string $timestamp, private string $signature) {}
    public function get_body() { return $this->body; }
    public function get_header($header) { return $header === 'X-CMB-Timestamp' ? $this->timestamp : ($header === 'X-CMB-Signature' ? $this->signature : ''); }
}
$r = new CMB_Test_Request($body, $timestamp, $signature);
$first = CMB_Webhooks::receive($r);
assert_true($first instanceof WP_Error && $first->code === 'cmb_invalid_signature', 'old but properly signed webhook rejected');
$realTime = (string) time();
$realSig = 'sha256=' . hash_hmac('sha256', $realTime . '.' . $body, CMB_INBOUND_HMAC_SECRET);
$accepted = CMB_Webhooks::receive(new CMB_Test_Request($body, $realTime, $realSig));
assert_true(is_array($accepted) && $accepted['accepted'] === true && !$accepted['duplicate'], 'signed first-party webhook accepted');
$dup = CMB_Webhooks::receive(new CMB_Test_Request($body, $realTime, $realSig));
assert_true(is_array($dup) && $dup['duplicate'] === true, 'webhook replay is idempotent');
$foreign = '{"kind":"message.received","workspaceId":"99999999999","id":"event-2"}';
$foreignSig = 'sha256=' . hash_hmac('sha256', $realTime . '.' . $foreign, CMB_INBOUND_HMAC_SECRET);
$denied = CMB_Webhooks::receive(new CMB_Test_Request($foreign, $realTime, $foreignSig));
assert_true($denied instanceof WP_Error && $denied->code === 'cmb_invalid_event', 'other workspace webhook blocked');
$events = get_option(CMB_Webhooks::AUDIT, []);
assert_true(count($events) === 1 && !isset($events[0]['workspaceId']), 'webhook audit retains no payload or tenant identity');
echo "ALL EXTENDED STANDALONE TESTS PASSED\n";

