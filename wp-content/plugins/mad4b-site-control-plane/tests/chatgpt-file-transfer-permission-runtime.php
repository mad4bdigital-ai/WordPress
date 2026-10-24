<?php
/* Isolated, zero-network scoped transfer permission fixture. */
define('ABSPATH', __DIR__ . '/');
$GLOBALS['logged_in'] = true;
$GLOBALS['grants'] = array('upload_files' => true, 'mad4b_attachment_transfer' => false);
$GLOBALS['mutation_enabled'] = true;
$GLOBALS['kill_switch'] = false;
$GLOBALS['environment'] = 'staging';
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
  public static function origin_enrolled() { return true; }
  public static function site_urls_match_enrollment() { return true; }
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
check(!$cls::permitted(), 'production denied regardless of grant');
$GLOBALS['environment']='staging';
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
