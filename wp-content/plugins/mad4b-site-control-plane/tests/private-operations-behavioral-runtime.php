<?php
/**
 * Hermetic behavioral regression for the private company operations boundary.
 * No WordPress site, credentials, network, files, or customer data required.
 */
declare(strict_types=1);
define('ABSPATH', __DIR__ . '/');

$qa32b_hooks = [];
$qa32b_identity = ['logged' => false, 'roles' => [], 'caps' => []];

function qa32b_assert($condition, string $why): void {
    if (!$condition) throw new RuntimeException($why);
}
function add_filter($hook, $callback, $priority = 10, $accepted = 1): void {
    $GLOBALS['qa32b_hooks'][] = [$hook, $priority, $accepted];
}
function add_action($hook, $callback, $priority = 10, $accepted = 1): void {
    $GLOBALS['qa32b_hooks'][] = [$hook, $priority, $accepted];
}
function absint($value): int { return abs((int) $value); }
function maybe_unserialize($value) { return unserialize($value, ['allowed_classes' => false]); }
function is_user_logged_in(): bool { return $GLOBALS['qa32b_identity']['logged']; }
function current_user_can($capability): bool {
    return in_array($capability, $GLOBALS['qa32b_identity']['caps'], true);
}
function wp_get_current_user() {
    return (object) ['roles' => $GLOBALS['qa32b_identity']['roles']];
}
function is_admin(): bool { return false; }
function wp_doing_ajax(): bool { return false; }
function get_post_types($filters, $result = 'names'): array {
    return ['post' => 'post', 'tour_orders' => 'tour_orders', 'tour_bookings' => 'tour_bookings',
        'tours-and-activities' => 'tours-and-activities'];
}
class WP_REST_Request {
    private string $route;
    public function __construct(string $method, string $route) { $this->route = $route; }
    public function get_route(): string { return $this->route; }
}
class WP_Error {
    private string $code;
    public function __construct(string $code, $message = '', $data = []) { $this->code = $code; }
    public function get_error_code(): string { return $this->code; }
}
class WP_Query {
    private array $vars;
    public function __construct(array $vars = []) { $this->vars = $vars; }
    public function get($name) { return $this->vars[$name] ?? null; }
    public function set($name, $value): void { $this->vars[$name] = $value; }
}
class QA32B_Database {
    public string $prefix = 'wp_';
    public int $reads = 0;
    public array $relations = [];
    public function prepare(string $sql, int $id): string {
        return str_replace('%d', (string) $id, $sql);
    }
    public function get_var(string $sql) {
        $this->reads++;
        preg_match('/id=(\d+)/', $sql, $m);
        return $this->relations[(int) ($m[1] ?? 0)] ?? null;
    }
}
$wpdb = new QA32B_Database();
$wpdb->relations[126] = serialize(['parent_object' => 'posts::tour_orders',
    'child_object' => 'posts::tour_bookings']);
$wpdb->relations[427] = serialize(['parent_object' => 'posts::tour_bookings',
    'child_object' => 'mix::users']);
$wpdb->relations[46] = serialize(['parent_object' => 'terms::location_jet',
    'child_object' => 'posts::tours-and-activities']);

require __DIR__ . '/../includes/class-mad4b-scp-private-operations-boundary.php';
$guard = 'MAD4B_SCP_Private_Operations_Boundary';
qa32b_assert(count($qa32b_hooks) === 4, 'Expected 4 early WordPress hooks');
$hook_names = array_column($qa32b_hooks, 0);
foreach (['register_post_type_args', 'rest_pre_dispatch', 'pre_get_posts', 'template_redirect'] as $hook) {
    qa32b_assert(in_array($hook, $hook_names, true), 'Missing hook: ' . $hook);
}
$args = ['public' => true, 'show_ui' => true, 'show_in_menu' => true,
    'show_in_rest' => true, 'publicly_queryable' => true];
foreach (['tour_orders', 'tour_bookings'] as $type) {
    $limited = $guard::post_type_args($args, $type);
    qa32b_assert($limited['public'] === false && $limited['publicly_queryable'] === false,
        'Private post type remains public: ' . $type);
    qa32b_assert($limited['show_ui'] === true && $limited['show_in_rest'] === true,
        'Staff editors lost: ' . $type);
}
qa32b_assert($guard::post_type_args($args, 'tours-and-activities') === $args,
    'Public tours registration changed');
foreach (['/wp/v2/tour_orders', '/wp/v2/tour_bookings/9',
    '/jet-rel/126', '/jet-rel/126/child/5', '/jet-rel/427'] as $route) {
    $res = $guard::rest(null, null, new WP_REST_Request('GET', $route));
    qa32b_assert($res instanceof WP_Error
        && $res->get_error_code() === 'mad4b_private_operations_forbidden',
        'Anonymous private route not forbidden: ' . $route);
}
qa32b_assert($guard::rest(null, null, new WP_REST_Request('GET', '/jet-rel/46')) === null,
    'Public location relation unexpectedly forbidden');
qa32b_assert($guard::rest(null, null,
    new WP_REST_Request('GET', '/wp/v2/tours-and-activities')) === null,
    'Public tours unexpectedly forbidden');

// No permissions by login alone, or by generic editor capability.
$GLOBALS['qa32b_identity'] = ['logged' => true, 'roles' => ['editor'],
    'caps' => ['edit_others_posts']];
qa32b_assert($guard::rest(null, null, new WP_REST_Request('GET', '/jet-rel/126'))
    instanceof WP_Error, 'Editor role escaped operations boundary');
foreach ([
    ['administrator', ['manage_options']],
    ['master_admin', ['edit_others_posts']],
    ['operator', ['edit_others_posts']],
] as [$role, $caps]) {
    $GLOBALS['qa32b_identity'] = ['logged' => true, 'roles' => [$role], 'caps' => $caps];
    qa32b_assert($guard::rest(null, null, new WP_REST_Request('GET', '/jet-rel/427'))
        === null, 'Legitimate company operations role denied: ' . $role);
}
$GLOBALS['qa32b_identity'] = ['logged' => false, 'roles' => [], 'caps' => []];
$any = new WP_Query(['post_type' => 'any']);
$guard::query($any);
qa32b_assert(!in_array('tour_orders', $any->get('post_type'), true)
    && !in_array('tour_bookings', $any->get('post_type'), true),
    'Private records escaped public any query');
qa32b_assert($wpdb->reads === 3, 'Site-local relationship resolver cache not effective');
echo "PASS: private operations routes, dynamic company relations, staff RBAC, public query\n";
