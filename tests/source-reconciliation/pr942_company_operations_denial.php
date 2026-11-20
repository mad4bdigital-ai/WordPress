<?php
/** Hermetic denial and read-only Master Admin Skills workspace fixture. */
error_reporting(E_ALL);
define('ABSPATH', __DIR__ . '/');
$GLOBALS['can_company_ops'] = false;
$GLOBALS['mutations'] = 0;
$GLOBALS['catalog_reads'] = 0;
function current_user_can($cap) { return $cap === 'mad4b_manage_company_operations' && $GLOBALS['can_company_ops']; }
function is_wp_error($value) { return $value instanceof WP_Error; }
function get_role($role) { return (object) array('capabilities' => array('mad4b_manage_company_operations' => true)); }
function home_url($path = '/') { return 'https://example.invalid/'; }
function post_type_exists($post_type) { return false; }
function update_option(...$args) { ++$GLOBALS['mutations']; throw new RuntimeException('No option changes allowed in read-only test'); }
function wp_mail(...$args) { ++$GLOBALS['mutations']; throw new RuntimeException('No email dispatch allowed'); }
class WP_Error {
    private $code;
    public function __construct($code, $message = '', $extra = array()) { $this->code = $code; }
    public function get_error_code() { return $this->code; }
}
class MAD4B_SCP_Site_Profile { public static function status() { return array('skills_enabled' => true); } }
class MAD4B_SCP_Skill_Registry {
    public static function list_skills() { ++$GLOBALS['catalog_reads']; return array(array('level'=>'site','target'=>'wordpress','name'=>'core-reader','enabled'=>true)); }
    public static function status() { return array('catalog_enabled' => true); }
}
class MAD4B_SCP_Skill_Runtime_Certification {
    public static function current_status() { return array('checks' => array('site_profile_enrolled' => true)); }
    public static function persisted_status() { return array('ready' => false, 'build_identity_current' => false); }
    public static function capability_readiness($checks) {
        return array('local_operations_ready' => true, 'catalog_read_ready' => true,
            'external_app_projection_ready' => false, 'authoring_ready' => false,
            'catalog_blockers' => array(), 'editor_disabled_by_policy' => true);
    }
}
require dirname(__DIR__, 2) . '/wp-content/plugins/mad4b-site-control-plane/includes/class-mad4b-scp-skills-admin-ui.php';
function guard($value, $label) { if (!$value) { fwrite(STDERR, "FAIL $label\n"); exit(1); } }
$denied = MAD4B_SCP_Skills_Admin_UI::operations_snapshot();
guard(is_wp_error($denied) && $denied->get_error_code() === 'mad4b_master_skills_view_denied', 'Company operations capability required');
guard($GLOBALS['catalog_reads'] === 0 && $GLOBALS['mutations'] === 0, 'Unauthorized caller must not read catalog or write');
$GLOBALS['can_company_ops'] = true;
$allowed = MAD4B_SCP_Skills_Admin_UI::operations_snapshot();
guard(is_array($allowed) && $allowed['read_only'] === true && $allowed['authority_effect'] === 'none', 'Company operations remain read-only');
guard($allowed['local_operations_ready'] === true && $allowed['app_binding_optional_for_local_operations'] === true, 'Local Skills do not require external App');
guard($allowed['role_audit']['company_operations_capability'] === true && $allowed['role_audit']['role_review_required'] === false, 'No privilege escalation');
guard($GLOBALS['catalog_reads'] === 1 && $GLOBALS['mutations'] === 0, 'One bounded read, no mail or option writes');
echo "PASS: Master Admin operations denial precedes reads; authorized snapshot is read-only without app or writes\n";
