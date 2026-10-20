<?php
define('ABSPATH', __DIR__ . '/');
$GLOBALS['options'] = array();
$GLOBALS['owner'] = true; $GLOBALS['nonce'] = true; $GLOBALS['audit'] = true;
$GLOBALS['persist'] = true; $GLOBALS['events'] = array();
function get_option($key, $default = false) { return array_key_exists($key, $GLOBALS['options']) ? $GLOBALS['options'][$key] : $default; }
function update_option($key, $value, $autoload = false) { if ($GLOBALS['persist']) $GLOBALS['options'][$key] = $value; }
function sanitize_key($s) { return strtolower(preg_replace('/[^a-zA-Z0-9_-]/', '', $s)); }
function sanitize_text_field($s) { return trim(strip_tags($s)); }
function wp_unslash($s) { return stripslashes($s); }
function wp_json_encode($v) { return json_encode($v); }
function apply_filters($tag, $value, ...$args) { return $value; }
function check_admin_referer($action) { if (!$GLOBALS['nonce']) throw new Exception('invalid_nonce'); }
function wp_die($message, $title = '', $args = array()) { throw new Exception($message); }
function get_current_user_id() { return 7; }
function is_wp_error($value) { return $value instanceof WP_Error; }
function add_query_arg($args, $url) { return $url . '?' . http_build_query($args); }
function admin_url($path) { return 'https://example.test/wp-admin/' . $path; }
function wp_safe_redirect($url) { throw new Exception($url); }
class WP_Error {}
class MAD4B_SCP_Staging_Write_Owner_Intent { static function owner() { return $GLOBALS['owner']; } }
class MAD4B_SCP_Site_Profile {
    static function site_uuid() { return 'site'; }
    static function reset_cache() {}
    static function status() { return array('site_uuid'=>'site', 'revision'=>1, 'profile_digest'=>'digest', 'configured_environment'=>'staging', 'authority_ready'=>true); }
}
class MAD4B_SCP_Audit {
    static function storage_status() { return array('ready'=>$GLOBALS['audit']); }
    static function record($event, $data, $state) { $GLOBALS['events'][] = $data; return true; }
}
class MAD4B_SCP_Admin_Experience { static function notice_receipt(...$args) { return 'notice'; } }
require dirname(__DIR__) . '/includes/class-mad4b-scp-auto-reconcile-scenarios.php';
require dirname(__DIR__) . '/includes/class-mad4b-scp-site-profile-admin.php';
function check($condition, $message) { if (!$condition) throw new Exception($message); echo 'PASS ' . $message . PHP_EOL; }
function save_mode($mode) {
    $_POST = array('reconciliation_mode'=>$mode); $_SERVER['REQUEST_METHOD']='POST';
    try { MAD4B_SCP_Site_Profile_Admin::handle_reconciliation_policy(); }
    catch (Exception $e) { return $e->getMessage(); }
    throw new Exception('handler did not terminate');
}
$base = array('environment'=>'staging', 'runtime_identity_complete'=>true, 'build_changed'=>true,
    'candidate_binding'=>array('required'=>true,'stored_bound'=>true,'match'=>false),
    'current_version'=>'0.4.0-rc.102', 'stored_version'=>'0.4.0-rc.101');
$class = 'MAD4B_SCP_Auto_Reconcile_Scenarios'; $key = $class::MODE_OPTION;
check($class::evaluate($base)['decision']==='SCHEDULE_PROBE', 'existing automatic default retained');
check(strpos(save_mode('manual'), 'reconciliation_policy_saved')!==false, 'owner can pause automatic scheduling');
check($class::evaluate($base)['decision']==='REVIEW_REQUIRED', 'central worker decisions honor manual mode');
check(strpos(save_mode('automatic_same_scope'), 'reconciliation_policy_saved')!==false, 'owner can resume same-scope scheduling');
check($class::evaluate($base)['automatic_probe_allowed']===true, 'automatic mode schedules bounded probe');
foreach (array('production','staging') as $environment) {
    $context=$base; $context['environment']=$environment;
    if ($environment==='staging') $context['breakglass_enabled']=true;
    check($class::evaluate($context)['decision']==='HARD_BLOCK', 'hard block preserved: '.$environment);
}
foreach (array('grant_inventory_drift','actor_identity_drift','site_profile_drift','write_contract_drift','baseline_expired','transport_contract_drift') as $signal) {
    $context=$base; $context['signals']=array($signal=>true);
    check($class::evaluate($context)['decision']==='REVIEW_REQUIRED', 'scope evidence cannot be auto-approved: '.$signal);
}
$GLOBALS['options'][$key]=array('automatic_same_scope');
check($class::evaluate($base)['decision']==='REVIEW_REQUIRED', 'malformed option fails closed');
$GLOBALS['options'][$key]='automatic_same_scope';
$catalog=$class::batch_policy();
check($catalog['count']===count($class::registry()) && !$catalog['authorizing'] && !$catalog['mutation_performed'], 'complete batch catalog remains non-authorizing');
check(strpos(save_mode(array('manual')), 'reconciliation_mode_blocked')!==false, 'malformed form input rejected');
$GLOBALS['owner']=false;
check(save_mode('manual')==='Exact enrolled Staging owner required.' && get_option($key)==='automatic_same_scope', 'non-owner cannot change policy');
$GLOBALS['owner']=true; $GLOBALS['nonce']=false;
check(save_mode('manual')==='invalid_nonce' && get_option($key)==='automatic_same_scope', 'CSRF rejected without settings change');
$GLOBALS['nonce']=true; $GLOBALS['audit']=false;
check(strpos(save_mode('manual'),'reconciliation_audit_blocked')!==false && get_option($key)==='automatic_same_scope', 'missing audit prevents settings change');
$GLOBALS['audit']=true; $GLOBALS['persist']=false;
check(strpos(save_mode('manual'),'reconciliation_readback_blocked')!==false, 'failed persistence cannot report success');
foreach ($GLOBALS['events'] as $event) check(!$event['authority_expansion_allowed'] && !$event['write_authority_attested'], 'settings audit is not an owner approval receipt');
echo 'mad4b.reconciliation-batch-policy.v1: PASS' . PHP_EOL;
