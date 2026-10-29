<?php
/**
 * QA30: regression fixture for native General Settings vs MCP discovery.
 * Pure PHP, no WP database or SMTP; safe for GitHub Actions.
 */
declare(strict_types=1);
define('ABSPATH', '/tmp/qa30/');
define('WP_SITEURL', 'https://qa30.example.invalid');
define('WP_HOME', 'https://qa30.example.invalid');
$GLOBALS['qa30_admin'] = true;
$GLOBALS['qa30_rest_calls'] = 0;
$GLOBALS['qa30_filters'] = [];
function is_admin(): bool { return $GLOBALS['qa30_admin']; }
function add_filter($tag, $fn, $priority = 10, $accepted = 1): void {
    $GLOBALS['qa30_filters'][$tag][] = ['callback' => $fn, 'priority' => $priority];
}
class WP_Error {
    private string $code;
    public function __construct(string $code, string $message = '') { $this->code = $code; }
    public function get_error_code(): string { return $this->code; }
}
function is_wp_error($x): bool {return $x instanceof WP_Error;}
function did_action(string $name): int { return $name === 'wp_loaded' ? 1 : 0; }
function doing_action(string $name): bool { return false; }
class QA30_RestServer {
    public function get_routes(): array { return []; }
}
function rest_get_server(): QA30_RestServer {
    ++$GLOBALS['qa30_rest_calls'];
    return new QA30_RestServer();
}
function qa30_assert(bool $valid, string $name): void {
    if (!$valid) { fwrite(STDERR, "FAIL $name\n"); exit(1); }
    echo "PASS $name\n";
}
require_once __DIR__ . '/../includes/class-mad4b-scp-native-general-settings-contract.php';
require_once __DIR__ . '/../includes/class-mad4b-scp-mcp-peer-governance.php';
qa30_assert(count($GLOBALS['qa30_filters']['allowed_options'] ?? []) === 1, 'source_contract_registered_once');
$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['SCRIPT_NAME'] = '/wp-admin/options.php';
$_POST['option_page'] = 'general';
$base = ['general' => ['siteurl','home','admin_email','new_admin_email','blogdescription'], 'reading' => ['posts_per_page']];
$out = MAD4B_SCP_Native_General_Settings_Contract::allowed_options($base);
qa30_assert($out['general'] === ['new_admin_email','blogdescription'], 'locked_urls_and_direct_admin_mail_excluded');
qa30_assert($out['reading'] === $base['reading'], 'unrelated_settings_unchanged');
$reflect = new ReflectionMethod('MAD4B_SCP_MCP_Peer_Governance', 'synchronize_rest_inventory_lifecycle');
$sync = $reflect->invoke(null);
qa30_assert($sync instanceof WP_Error && $sync->get_error_code() === 'mcp_inventory_deferred_native_general_settings', 'mcp_deferred_during_general_save');
qa30_assert($GLOBALS['qa30_rest_calls'] === 0, 'no_rest_bootstrap_during_general_save');
$_POST['option_page'] = 'reading';
qa30_assert(MAD4B_SCP_Native_General_Settings_Contract::allowed_options($base) === $base, 'other_settings_group_untouched');
$sync = $reflect->invoke(null);
qa30_assert(is_array($sync) && $sync['state'] === 'canonical_rest_initialized', 'mcp_rest_still_initializes_elsewhere');
qa30_assert($GLOBALS['qa30_rest_calls'] === 1, 'other_settings_can_initialize_rest');
$_POST['option_page'] = 'general';
$_SERVER['REQUEST_METHOD'] = 'GET';
qa30_assert(MAD4B_SCP_Native_General_Settings_Contract::allowed_options($base) === $base, 'general_get_untouched');
$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['SCRIPT_NAME'] = '/wp-admin/admin-ajax.php';
qa30_assert(MAD4B_SCP_Native_General_Settings_Contract::allowed_options($base) === $base, 'other_admin_post_untouched');
$GLOBALS['qa30_admin'] = false;
qa30_assert(MAD4B_SCP_Native_General_Settings_Contract::allowed_options($base) === $base, 'non_admin_context_untouched');
echo "QA30_SOURCE_CONTRACT_REGRESSION_PASS\n";
