<?php
/** Central SSH-only Site Profile admission: no owner impersonation or grants. */
declare(strict_types=1);
define('ABSPATH', '/');
define('WP_CLI', true);
class WP_Error {
    private $code;
    public function __construct($code, $message = '') { $this->code = $code; }
    public function get_error_code() { return $this->code; }
}
function is_wp_error($x) { return $x instanceof WP_Error; }
function get_current_user_id() { return (int)($GLOBALS['actor'] ?? 0); }
function home_url($suffix='/') { return ($GLOBALS['origin'] ?? 'https://egypttourgates.com') . '/'; }
function site_url($suffix='/') { return ($GLOBALS['siteurl'] ?? $GLOBALS['origin']) . '/'; }
function untrailingslashit($x) { return rtrim($x, '/'); }
function wp_get_environment_type() { return $GLOBALS['env']; }
function wp_parse_url($x, $arg=-1) { return parse_url($x,$arg); }
function get_option($key, $default=null) { return $GLOBALS['current_profile'] ?? $default; }
function current_user_can($cap) { return $GLOBALS['admin'] ?? false; }
function sanitize_key($x) { return preg_replace('/[^a-z0-9_\\-]/', '', strtolower($x)); }
class MAD4B_SCP_Audit {
    public static function storage_status() { return array('ready' => $GLOBALS['audit_ready']); }
}
require dirname(__DIR__) . '/wp-content/plugins/mad4b-site-control-plane/includes/class-mad4b-scp-site-profile.php';
$GLOBALS['env']='production';
$GLOBALS['origin']='https://egypttourgates.com';
$GLOBALS['siteurl']='https://egypttourgates.com';
$GLOBALS['actor']=0;$GLOBALS['audit_ready']=false;$GLOBALS['admin']=false;
$checks=0;
$assert=static function($condition,$label)use(&$checks){if(!$condition)throw new RuntimeException($label);++$checks;};
$call=static function($code='egypt-tour-gates', $origin='https://egypttourgates.com') {
    return MAD4B_SCP_Site_Profile::provision_from_central_host(
        array('company_code'=>$code,'canonical_origin'=>$origin));
};
$deny=static function($expected,$label)use($call,$assert){
 $result=$call();
 $assert(is_wp_error($result) && $result->get_error_code()===$expected,$label);
};
putenv('MAD4B_CENTRAL_SSH_ENROLLMENT');
$deny('mad4b_central_host_cli_admission_required','must declare exact CLI intent');
putenv('MAD4B_CENTRAL_SSH_ENROLLMENT=1');
putenv('MAD4B_CENTRAL_SSH_COMPANY=egypt-tour-gates');
putenv('MAD4B_CENTRAL_SSH_ORIGIN=https://egypttourgates.com');
$GLOBALS['actor']=7;
$deny('mad4b_central_host_cli_admission_required','never impersonate a WordPress user');
$GLOBALS['actor']=0;
$assert(is_wp_error($call('not-this-company')),'wrong company rejected');
$assert($call('not-this-company')->get_error_code()==='mad4b_central_host_exact_scope_required','company code is bound to origin');
$GLOBALS['env']='staging';
$deny('mad4b_central_host_exact_scope_required','staging not promoted to production');
$GLOBALS['env']='production';
$GLOBALS['siteurl']='https://foreign.example';
$deny('mad4b_central_host_exact_scope_required','siteurl mismatch rejected');
$GLOBALS['siteurl']='https://egypttourgates.com';
$GLOBALS['origin']='https://tourism.almallahgroup-mg.com';
$deny('mad4b_central_host_exact_scope_required','cross-company origin rejected');
$GLOBALS['origin']='https://egypttourgates.com';
putenv('MAD4B_CENTRAL_SSH_ORIGIN=https://another.example');
$deny('mad4b_central_host_exact_scope_required','origin cannot differ from Host policy');
putenv('MAD4B_CENTRAL_SSH_ORIGIN=https://egypttourgates.com');
putenv('MAD4B_CENTRAL_SSH_COMPANY=another-company');
$deny('mad4b_central_host_exact_scope_required','company cannot differ from Host policy');
putenv('MAD4B_CENTRAL_SSH_COMPANY=egypt-tour-gates');
$deny('mad4b_central_host_audit_required','append-only audit prerequisite');
$assert(!array_key_exists('current_profile', $GLOBALS),'denied operations do not write');
$assert(!current_user_can('manage_options'),'no WordPress Administrator required');
$src=file_get_contents(dirname(__DIR__).'/wp-content/plugins/mad4b-site-control-plane/includes/class-mad4b-scp-site-profile.php');
$assert(strpos($src, "'enrollment_authority' => '' !== \$central_company ? 'central_pinned_ssh_host'")!==false,'native authority must be audited');
$assert(strpos($src, "'' !== \$central_company ? array() : array( get_current_user_id() )")!==false,'native path must not attach fictitious OAuth user');
echo json_encode(array('contract'=>'mad4b.central-native-ssh-profile-admission.v1','assertions'=>$checks,'written'=>false,'capabilities_granted'=>0)), "\n";
