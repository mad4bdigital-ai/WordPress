<?php
/* Isolated, zero-network scoped transfer permission fixture. */
define('ABSPATH', __DIR__ . '/');
$GLOBALS['logged_in'] = true;
$GLOBALS['grants'] = array('upload_files' => true, 'mad4b_attachment_transfer' => false);
$GLOBALS['mutation_enabled'] = true;
$GLOBALS['kill_switch'] = false;
$GLOBALS['environment'] = 'staging';
$GLOBALS['profile_write'] = true;
$GLOBALS['confirmed_production_write'] = false;
$GLOBALS['site_enrolled'] = true;
$GLOBALS['site_origin_match'] = true;
$GLOBALS['abilities'] = array();
function add_action($name, $handler, $priority = 10) {}
function is_user_logged_in() { return $GLOBALS['logged_in']; }
function current_user_can($cap) { return !empty($GLOBALS['grants'][$cap]); }
function get_current_user_id() { return 17; }
function sanitize_key($value) { return strtolower($value); }
function wp_has_ability($ability) { return isset($GLOBALS['abilities'][$ability]); }
function wp_register_ability($ability, $definition) { $GLOBALS['abilities'][$ability] = $definition; return (object)array('name'=>$ability); }
class MAD4B_SCP_Policy {
  public static function can_read() { return true; }
  public static function can_connect_user($id) { return $id === 17; }
  public static function mutation_gate_status() { return array('effective' => $GLOBALS['mutation_enabled'], 'explicit_kill_switch' => $GLOBALS['kill_switch']); }
}
class MAD4B_SCP_Site_Profile {
  public static function configured() { return true; }
  public static function current_environment() { return $GLOBALS['environment']; }
  public static function origin_enrolled() { return $GLOBALS['site_enrolled']; }
  public static function site_urls_match_enrollment() { return $GLOBALS['site_origin_match']; }
  public static function site_uuid() { return $GLOBALS['fixture_site_uuid'] ?? 'a1111111-1111-1111-1111-111111111111'; }
  public static function site_origin() { return $GLOBALS['fixture_site_origin'] ?? 'https://sample-site.example.test'; }
  public static function write_enabled() { return $GLOBALS['profile_write'] &&
    ($GLOBALS['environment'] !== 'production' || $GLOBALS['confirmed_production_write']); }
}
require dirname(__DIR__) . '/includes/class-mad4b-scp-chatgpt-file-transfer.php';
function check($condition, $label) { if (!$condition) { fwrite(STDERR, "FAILED: " . $label . "\n"); exit(1); } }
$cls='MAD4B_SCP_ChatGPT_File_Transfer';
check(!$cls::permitted(), 'default deny without scoped grant');
$GLOBALS['grants']['mad4b_attachment_transfer']=true;
check($cls::permitted(), 'explicit scoped grant accepted when all gates pass');
$GLOBALS['mutation_enabled']=false;
check(!$cls::permitted(), 'global mutation gate cannot be bypassed');
$GLOBALS['mutation_enabled']=true;
$GLOBALS['kill_switch']=true;
check(!$cls::permitted(), 'explicit kill switch cannot be bypassed');
$GLOBALS['kill_switch']=false;
$GLOBALS['environment']='production';
check(!$cls::permitted(), 'Production without confirmed Site Profile write is denied');
$GLOBALS['confirmed_production_write']=true;
check($cls::permitted(), 'Production explicit write plus scoped upload and transfer grants accepted');
$GLOBALS['kill_switch']=true;
check(!$cls::permitted(), 'Production kill switch also prevents transfer');
$GLOBALS['kill_switch']=false;
$GLOBALS['site_origin_match']=false;
check(!$cls::permitted(), 'Foreign Production origin never inherits grants');
$GLOBALS['site_origin_match']=true;
$GLOBALS['site_enrolled']=false;
check(!$cls::permitted(), 'Unenrolled Production site never inherits grants');
$GLOBALS['site_enrolled']=true;
$GLOBALS['profile_write']=false;
check(!$cls::permitted(), 'Production Site Profile write flag is mandatory');
$GLOBALS['profile_write']=true;
$GLOBALS['environment']='development';
check(!$cls::permitted(), 'Unreviewed environment is not eligible');
$GLOBALS['environment']='staging';
$rootMethod=new ReflectionMethod($cls,'root');
$firstRoot=$rootMethod->invoke(null);
$GLOBALS['fixture_site_uuid']='b2222222-2222-2222-2222-222222222222';
$otherRoot=$rootMethod->invoke(null);
check(is_string($firstRoot) && is_string($otherRoot) && $firstRoot!==$otherRoot,
  'Private media transfer storage must be per-company Site Profile');
$GLOBALS['fixture_site_uuid']='a1111111-1111-1111-1111-111111111111';
check($rootMethod->invoke(null)===$firstRoot,'Exact site identity root must be stable');
$GLOBALS['grants']['upload_files']=false;
check(!$cls::permitted(), 'WordPress upload capability remains required');
$GLOBALS['grants']['upload_files']=true;
$GLOBALS['logged_in']=false;
check(!$cls::permitted(), 'anonymous request denied');
$GLOBALS['logged_in']=true;
$cls::register_ability();
check(isset($GLOBALS['abilities']['mad4b/attachment-transfer']), 'Ability registered');
$ability=$GLOBALS['abilities']['mad4b/attachment-transfer'];
check($ability['meta']['public']===false && $ability['meta']['mcp']['surface']==='write', 'non-public governed write');
check($ability['permission_callback']===array($cls,'permitted'), 'permission callback same gate');
echo "CHATGPT_TRANSFER_PERMISSION_RUNTIME: PASS\n";
