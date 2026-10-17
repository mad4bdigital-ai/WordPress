<?php
// Regression: passive wp-admin GET/POST must materialize the preflight
// definition without booting the heavy operational remediation subsystem.
define('ABSPATH', '/tmp/wp/');
class WP_Error {
    private $code;
    public function __construct($code, $message = '') { $this->code = $code; }
    public function get_error_code() { return $this->code; }
}
function current_user_can($capability) { return false; }
function is_wp_error($value) { return $value instanceof WP_Error; }
require dirname(__DIR__) . '/includes/class-mad4b-scp-context-admin-ui.php';
if (class_exists('MAD4B_SCP_Brand_Core_Remote_Recovery', false)) {
    throw new RuntimeException('Preflight definition should not preload');
}
$m = new ReflectionMethod('MAD4B_SCP_Context_Admin_UI','source_onboarding_preflight');
$m->setAccessible(true);
$denied = $m->invoke(null, array('external_root_id'=>'folder_A1', 'source_mode'=>'governed','write_policy'=>'read_only','recursive'=>true));
if (!class_exists('MAD4B_SCP_Brand_Core_Remote_Recovery', false) ||
    !is_wp_error($denied) ||
    'mad4b_brand_remote_permission_required' !== $denied->get_error_code()) {
    throw new RuntimeException('Lazy preflight did not reach canonical permission gate: '.(is_wp_error($denied)?$denied->get_error_code():'not_an_error'));
}
$again = $m->invoke(null,array('external_root_id'=>'folder_A1'));
if (!is_wp_error($again) || $again->get_error_code() !== $denied->get_error_code()) {
    throw new RuntimeException('Preflight class second request must be idempotent');
}
echo "CONTEXT_ADMIN_PREFLIGHT_PASSIVE_LOAD: PASS\n";
