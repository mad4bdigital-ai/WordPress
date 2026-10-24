<?php
/* Staging incident regression. Isolated PHP mocks; no WordPress/network mutation. */
if (!defined('ABSPATH')) define('ABSPATH', __DIR__ . '/');
class WP_Error {
  private $code;
  public function __construct($code, $message = '') {$this->code = $code;}
  public function get_error_code() {return $this->code;}
}
function is_wp_error($x) {return $x instanceof WP_Error;}
$GLOBALS['responses'] = [];
$GLOBALS['calls'] = [];
function wp_safe_remote_get($url,$options) {
  $prefix='https://api.github.com/repos/mad4bdigital-ai/WordPress/';
  if (strpos($url,$prefix)!==0 || !isset($options['sslverify']) || $options['sslverify']!==true || $options['redirection']!==0 || !in_array($options['limit_response_size'],[262145,2049],true))
    throw new RuntimeException('Untrusted repository or network options');
  if (strpos($url,$prefix.'compare/')!==0 && strpos($url,$prefix.'commits/')!==0)
    throw new RuntimeException('Unexpected GitHub endpoint');
  $GLOBALS['calls'][]=['url'=>$url,'limit'=>$options['limit_response_size']];
  if (!count($GLOBALS['responses'])) throw new RuntimeException('Unexpected network request');
  return array_shift($GLOBALS['responses']);
}
function wp_remote_retrieve_response_code($r) {return $r['code'];}
function wp_remote_retrieve_body($r) {return $r['body'];}
function valid($value, $label) {if (!$value) throw new RuntimeException($label);}
require dirname(__DIR__).'/includes/class-mad4b-scp-self-update.php';
$class='MAD4B_SCP_Self_Update';
$current=['source_commit_sha'=>str_repeat('a',40),'version'=>'0.4.0-rc.103'];
$target=['source_commit_sha'=>str_repeat('b',40),'version'=>'0.4.0-rc.104'];
$GLOBALS['responses']=[['code'=>404,'body'=>'Not Found'],['code'=>422,'body'=>'No commit found for SHA']];
valid($class::native_source_relationship($current,$target)==='installed_source_unresolvable','404+422 must classify orphaned installed SHA');
valid(count($GLOBALS['calls'])===2,'Only bounded read-only immutable GitHub checks are permitted');
valid(strpos($GLOBALS['calls'][1]['url'],'/commits/'.str_repeat('a',40))!==false,'Installed SHA must be immutable probe target');
valid($GLOBALS['calls'][1]['limit']===2049,'Probe must remain response-size bounded');
$fixture=sys_get_temp_dir().'/etg-orphan-readonly-'.bin2hex(random_bytes(8)).'/';
mkdir($fixture,0700);
define('MAD4B_SCP_DIR',$fixture);
define('MAD4B_SCP_FILE',$fixture.'plugin.php');
file_put_contents(MAD4B_SCP_FILE,"<?php\n/*\n * Version: 0.4.0-rc.103\n */\n");
file_put_contents($fixture.'MAD4B-BUILD-PROVENANCE.json',json_encode($current));
register_shutdown_function(static function() use($fixture) {unlink($fixture.'plugin.php');unlink($fixture.'MAD4B-BUILD-PROVENANCE.json');rmdir($fixture);});
$GLOBALS['responses']=[['code'=>404,'body'=>'Not Found'],['code'=>422,'body'=>'No commit found for SHA']];
$state=$class::native_release_state($target,true);
valid($state['state']==='needs_review','Orphaned source must not be treated as available');
valid(in_array('native_release_installed_source_unresolvable',$state['blockers'],true),'Specific blocker missing');
valid($state['recovery_guidance']['automatic_apply_allowed']===false && $state['recovery_guidance']['mutation_performed']===false,'Recovery guidance must never grant authority');
valid($state['recovery_guidance']['owner_oauth_step_up_required']===true,'Exact owner authentication cannot be bypassed');
$admit=new ReflectionMethod($class,'native_replacement_admission');$admit->setAccessible(true);
$GLOBALS['responses']=[['code'=>404,'body'=>'Not Found'],['code'=>422,'body'=>'No commit found for SHA']];
valid(is_wp_error($admit->invoke(null,$target,'wordpress_admin_plugin_update')),'Native upgrade must fail closed on orphaned installed SHA');
$GLOBALS['responses']=[['code'=>404,'body'=>'Not Found'],['code'=>404,'body'=>'Not Found']];
valid($class::native_source_relationship($current,$target)==='unknown','Opaque 404 must not claim known missing SHA');
$GLOBALS['responses']=[['code'=>200,'body'=>json_encode(['status'=>'ahead','ahead_by'=>2,'behind_by'=>0])]];
valid($class::native_source_relationship($current,$target)==='target_ahead','Verified ancestry still accepted');
$GLOBALS['responses']=[['code'=>429,'body'=>'Rate limit']];
$other=$target;$other['source_commit_sha']=str_repeat('c',40);
valid($class::native_source_relationship($current,$other)==='unknown','Rate limit must remain unknown');
$older=$target;$older['version']='0.4.0-rc.102';
valid($class::native_source_relationship($current,$older)==='target_behind','Older release must never be offered');
echo "ETG_ORPHAN_SOURCE_READONLY_GUARD=PASS\n";
