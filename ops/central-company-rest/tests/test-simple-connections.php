<?php
/**
 * Standalone PHPUnit-free contract test for the simple native REST connection.
 * Run: php ops/central-company-rest/tests/test-simple-connections.php
 * No WordPress database, credentials, external network or production state.
 */
error_reporting(E_ALL);
define('ABSPATH', '/tmp/');
$GLOBALS['opts'] = [];
$GLOBALS['site_url'] = 'https://tours.dev.mad4b.com';
$GLOBALS['user_id'] = 1;
$GLOBALS['can_admin'] = true;
$GLOBALS['fake_remote'] = 'ok';
$GLOBALS['remote_calls'] = 0;
$GLOBALS['last_request'] = [];
function add_action() {}
function wp_parse_url($u, $p = -1) { return parse_url($u, $p); }
function home_url($p = '/') { return $GLOBALS['site_url']; }
function get_option($k, $default = false) { return $GLOBALS['opts'][$k] ?? $default; }
function update_option($k, $v, $autoload = null) { $GLOBALS['opts'][$k] = $v; return true; }
function delete_option($k) { unset($GLOBALS['opts'][$k]); return true; }
function wp_salt($v) { return 'test-only-not-a-secret'; }
function get_current_user_id() { return $GLOBALS['user_id']; }
function current_user_can($cap) { return $cap === 'manage_options' ? $GLOBALS['can_admin'] : $cap === 'read'; }
function wp_is_application_passwords_available() { return true; }
function is_wp_error($v) { return $v instanceof WP_Error; }
function wp_remote_retrieve_response_code($r) { return $r['status']; }
function wp_remote_retrieve_body($r) { return $r['body']; }
function wp_safe_remote_request($url, $args) {
    $GLOBALS['remote_calls']++;
    $GLOBALS['last_request'] = ['url' => $url, 'args' => $args];
    if ($GLOBALS['fake_remote'] === 'fail') { return ['status' => 401, 'body' => '{}']; }
    $targetAre = strpos($url, 'www.allroyalegypt.com') !== false;
    $id = $GLOBALS['fake_remote'] === 'wrong_tenant' ? 'wrong-tenant' :
        ($targetAre ? 'all-royal-egypt' : 'tourism-almallahgroup');
    $host = $targetAre ? 'www.allroyalegypt.com' : 'tourism.almallahgroup-mg.com';
    return ['status' => 200, 'body' => json_encode([
        'contract' => 'mad4b.company.simple-identity.v1',
        'company_id' => $id,
        'canonical_host' => $host,
        'canonical_url' => 'https://' . $host,
        'auth_method' => 'wp_application_password',
        'authenticated' => true, 'reference_only' => true,
        'writes_enabled' => false, 'checkout_enabled' => false
    ])];
}
class WP_Error {
    public $code; public $message; public $data;
    public function __construct($code, $message, $data = []) { $this->code = $code; $this->message = $message; $this->data = $data; }
}
class WP_REST_Response {
    public $data; public $status; public $headers = [];
    public function __construct($data, $status = 200) { $this->data = $data; $this->status = $status; }
    public function header($k, $v) { $this->headers[$k] = $v; }
}
class WP_REST_Server { const READABLE = 'GET'; const CREATABLE = 'POST'; }
class WP_REST_Request implements ArrayAccess {
    public $company; public $params; public $method; public $route;
    public function __construct($company = '', $params = [], $method = 'GET', $route = '') {
        $this->company = $company; $this->params = $params; $this->method = $method; $this->route = $route;
    }
    public function get_json_params() { return $this->params; }
    public function get_method() { return $this->method; }
    public function get_route() { return $this->route; }
    public function offsetExists($o): bool { return $o === 'company'; }
    public function offsetGet($o) { return $o === 'company' ? $this->company : null; }
    public function offsetSet($o, $v): void {}
    public function offsetUnset($o): void {}
}
class MAD4B_Central_Company_REST_V1 {
    const COMPANIES = [
        'tourism-almallahgroup' => [
            'name' => 'Tourism Almallah Group',
            'url' => 'https://tourism.almallahgroup-mg.com',
            'host' => 'tourism.almallahgroup-mg.com'
        ],
        'all-royal-egypt' => [
            'name' => 'All Royal Egypt',
            'url' => 'https://www.allroyalegypt.com',
            'host' => 'www.allroyalegypt.com'
        ]
    ];
}
$root = dirname(__DIR__);
require $root . '/central/240-mad4b-central-simple-connections.php';
$check_count = 0;
function check($condition, $message) {
    global $check_count;
    $check_count++;
    if (!$condition) { fwrite(STDERR, "FAIL: $message\n"); exit(1); }
}
check(MAD4B_Central_Simple_Connections_V1::enabled(), 'Staging host enabled');
$GLOBALS['site_url'] = 'https://tourism.almallahgroup-mg.com';
check(!MAD4B_Central_Simple_Connections_V1::enabled(), 'Central plugin rejects remote host');
$GLOBALS['site_url'] = 'https://tours.dev.mad4b.com';
check(MAD4B_Central_Simple_Connections_V1::admin_only(), 'Admin allowed');
$GLOBALS['can_admin'] = false;
check(!MAD4B_Central_Simple_Connections_V1::admin_only(), 'Nonadmin denied');
$GLOBALS['can_admin'] = true;

$company = 'tourism-almallahgroup';
$password = 'abcd efgh ijkl mnop qrst uvwx';
$request = new WP_REST_Request($company, ['username' => 'mad4b-service', 'application_password' => $password]);
$unknown = MAD4B_Central_Simple_Connections_V1::connect(new WP_REST_Request('unknown-company', $request->params));
check($unknown instanceof WP_Error && $unknown->data['status'] === 404, 'Unknown source denied');
$invalid = MAD4B_Central_Simple_Connections_V1::connect(new WP_REST_Request($company, ['username'=>'bad', 'application_password'=>'short']));
check($invalid instanceof WP_Error, 'Invalid password blocked');
$GLOBALS['fake_remote'] = 'fail';
$failure = MAD4B_Central_Simple_Connections_V1::connect($request);
check($failure instanceof WP_Error && !$GLOBALS['opts'], 'Network failure does not persist secret');
$GLOBALS['fake_remote'] = 'wrong_tenant';
$foreign = MAD4B_Central_Simple_Connections_V1::connect($request);
check($foreign instanceof WP_Error && !$GLOBALS['opts'], 'Foreign tenant identity rejected');
$GLOBALS['fake_remote'] = 'ok';
$ok = MAD4B_Central_Simple_Connections_V1::connect($request);
check($ok instanceof WP_REST_Response && $ok->data['connected'] === true, 'Connect works after matching identity');
check(($GLOBALS['last_request']['url'] ?? '') === 'https://tourism.almallahgroup-mg.com/wp-json/mad4b-company/v1/simple-identity', 'Exact host pinned');
check($GLOBALS['last_request']['args']['redirection'] === 0 && $GLOBALS['last_request']['args']['sslverify'] === true, 'TLS and redirect fences enabled');
check($GLOBALS['last_request']['args']['method'] === 'POST', 'Identity uses POST and avoids GET caching');
check(strpos(serialize($GLOBALS['opts']), $password) === false, 'Plaintext app password not stored in options');
check(strpos(serialize($GLOBALS['opts']), base64_encode('mad4b-service:'.$password)) === false, 'Basic header not stored');
$state = MAD4B_Central_Simple_Connections_V1::status();
check($state->data['companies'][0]['configured'] === true, 'Configuration visible without credentials');
check(!isset($state->data['companies'][0]['username']), 'Status redacts username');

$saved = MAD4B_Central_Simple_Connections_V1::test_saved(new WP_REST_Request($company));
check($saved instanceof WP_REST_Response && $saved->data['connected'] === true, 'Saved credentials test works');
$key = 'mad4b_simple_connection_tourism_almallahgroup';
$GLOBALS['opts'][$key]['tag'] = base64_encode(random_bytes(16));
$tamper = MAD4B_Central_Simple_Connections_V1::test_saved(new WP_REST_Request($company));
check($tamper instanceof WP_Error && $tamper->code === 'mad4b_simple_not_connected', 'Ciphertext tampering fails closed');
$bye = MAD4B_Central_Simple_Connections_V1::disconnect(new WP_REST_Request($company));
check($bye instanceof WP_REST_Response && !isset($GLOBALS['opts'][$key]), 'Disconnect deletes stored credential');

$GLOBALS['site_url'] = 'https://tourism.almallahgroup-mg.com';
require $root . '/remote/240-mad4b-company-simple-identity.php';
$GLOBALS['opts']['mad4b_simple_service_user_id_v1'] = 9;
$GLOBALS['user_id'] = 9;
$remote_request = new WP_REST_Request('', [], 'POST', '/mad4b-company/v1/simple-identity');
$remote_deny = MAD4B_Company_Simple_Identity_V1::permission($remote_request);
check($remote_deny instanceof WP_Error, 'Remote denies user without app-password authentication event');
MAD4B_Company_Simple_Identity_V1::mark_app_password_auth((object)['ID'=>9]);
check(MAD4B_Company_Simple_Identity_V1::permission($remote_request) === true, 'Native app-password auth allows bound service user');
$GLOBALS['user_id'] = 10;
check(MAD4B_Company_Simple_Identity_V1::permission($remote_request) instanceof WP_Error, 'Remote denies wrong user');
$GLOBALS['user_id'] = 9;
$identity = MAD4B_Company_Simple_Identity_V1::identity();
check($identity->data['writes_enabled'] === false && $identity->data['reference_only'] === true, 'Remote never grants writes/sales');
$GLOBALS['site_url'] = 'https://unrelated.example';
check(MAD4B_Company_Simple_Identity_V1::permission($remote_request) instanceof WP_Error, 'Remote rejects unrecognized hosts');
echo "PASS $check_count simple REST contract tests\n";
