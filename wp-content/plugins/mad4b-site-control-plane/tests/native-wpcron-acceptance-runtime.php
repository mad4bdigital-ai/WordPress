<?php
/* WP-Cron is only a bounded read-only native smoke, never browser certification. */
define('ABSPATH', __DIR__ . '/');
$GLOBALS['opts']=[];
$GLOBALS['calls']=0;
$GLOBALS['env']='staging';
$GLOBALS['cron_disabled']=false;
function add_action() {}
function current_user_can($cap) { return true; }
function get_option($k,$def=false) { return array_key_exists($k,$GLOBALS['opts'])?$GLOBALS['opts'][$k]:$def; }
function add_option($k,$v,$a='',$autoload=false) {
 if(array_key_exists($k,$GLOBALS['opts']))return false;
 $GLOBALS['opts'][$k]=$v;return true;
}
function update_option($k,$v,$autoload=false){$GLOBALS['opts'][$k]=$v;return true;}
function delete_option($k){
 if($k===MAD4B_SCP_Native_Cron_Acceptance::LOCK && !empty($GLOBALS['fail_lock_delete_once'])){
  $GLOBALS['fail_lock_delete_once']=false;return false;
 }
 unset($GLOBALS['opts'][$k]);return true;
}
function wp_generate_uuid4(){return 'aaaaaaaa-aaaa-4aaa-aaaa-aaaaaaaaaaaa';}
function wp_schedule_single_event($when,$hook,$args=[],$error=false){
 $GLOBALS['calls']++;$GLOBALS['scheduled']=[$when,$hook,$args];return true;
}
function wp_next_scheduled($hook,$args=[]) {return !empty($GLOBALS['cron_event_pending']) ? time()+60 : false;}
function home_url($path='/'){return 'https://staging.example.test/';}
function site_url($path='/'){return 'https://staging.example.test/';}
function wp_parse_url($url,$part){return parse_url($url,$part);}
function wp_safe_remote_get($url,$args){
 $GLOBALS['http']=[$url,$args];
 return ['response'=>['code'=>200],'body'=>'PRIVATE NEVER PERSIST'];
}
function wp_remote_retrieve_response_code($r){return $r['response']['code'];}
function is_wp_error($x){return $x instanceof WP_Error;}
function esc_html($x){return htmlspecialchars($x,ENT_QUOTES);}
class WP_Error{
 private $c; public function __construct($c,$m=''){$this->c=$c;}
 public function get_error_code(){return $this->c;}
}
class MAD4B_SCP_Native_Hybrid_Acceptance {
 public static function current_identity(){return [
  'source_commit_sha'=>str_repeat('a',40),
  'site_origin_sha256'=>hash('sha256','https://staging.example.test/'),
  'preference_revision'=>str_repeat('a',32),
  'build_manifest_current'=>true
 ];}
}
class MAD4B_SCP_Environment {
 public static function effective(){return $GLOBALS['env'];}
}
class MAD4B_SCP_Browser_Acceptance_Admin_UI {
 const PAGE_SLUG='mad4b-browser-acceptance';
 public static function selection(){return ['executor'=>$GLOBALS['executor']??'auto'];}
 public static function public_selection(){return [
   'preference_source'=>$GLOBALS['consented']??true?'operator_saved_or_legacy':'default_observed',
   'preference_valid'=>true,
   'configuration_revision'=>$GLOBALS['consented']??true?str_repeat('a',32):''
 ];}
}
function ok($b,$s){if(!$b){fwrite(STDERR,'FAIL '.$s."\n");exit(1);}}
require dirname(__DIR__).'/includes/class-mad4b-scp-native-cron-acceptance.php';
$c='MAD4B_SCP_Native_Cron_Acceptance';
ok($c::status()['signed_browser_acceptance']===false,'never signs browser');
ok($GLOBALS['calls']===0,'zero cron jobs on boot');
$GLOBALS['env']='production';
ok(is_wp_error($c::enqueue()) && $GLOBALS['calls']===0,'production denied');
$GLOBALS['env']='staging';
ok(is_wp_error($c::enqueue()) && $GLOBALS['calls']===0,'explicit optin required');
$GLOBALS['executor']='native_hybrid';
$GLOBALS['consented']=false;
ok(is_wp_error($c::enqueue()) && $GLOBALS['calls']===0,'default hybrid never auto-queues without owner consent');
$GLOBALS['consented']=true;
$job=$c::enqueue();
ok(is_array($job) && $GLOBALS['calls']===1 && $job['job']['state']==='queued','single queued smoke');
ok(is_wp_error($c::enqueue()) && $GLOBALS['calls']===1,'duplicate queue denied');
$c::run('bbbbbbbb-bbbb-4bbb-bbbb-bbbbbbbbbbbb');
ok(!isset($GLOBALS['http']),'mismatched request never executes');
$c::run('aaaaaaaa-aaaa-4aaa-aaaa-aaaaaaaaaaaa');
$done=$c::status();
ok($done['job']['state']==='smoke_observed' && $done['job']['http_status']===200 &&
 $done['signed_browser_acceptance']===false && !isset($done['job']['body']) &&
 !isset($GLOBALS['opts'][$c::LOCK]),'redacted readback and lock release');
ok($GLOBALS['http'][1]['redirection']===0 && $GLOBALS['http'][1]['timeout']===10 &&
 $GLOBALS['http'][1]['limit_response_size']===4096,'bounded wordpress safe http');
// Interrupted read-only jobs need explicit, identity-bound recovery, not replay.
$id='aaaaaaaa-aaaa-4aaa-aaaa-aaaaaaaaaaaa';
$GLOBALS['opts'][$c::OPTION]=array_merge(
 MAD4B_SCP_Native_Hybrid_Acceptance::current_identity(),[
  'job_id'=>$id,'state'=>'queued','queued_at'=>gmdate('c',time()-1900)]);
$GLOBALS['opts'][$c::LOCK]=$id;
$stale=$c::status();
ok($stale['stale_lock_detected']===true && $stale['manual_reconciliation_eligible']===true
 && $stale['blind_retry_allowed']===false,'stale lock identified without replay');
ok(is_wp_error($c::reconcile_stale('bbbbbbbb-bbbb-4bbb-bbbb-bbbbbbbbbbbb')),
 'stale lock cannot be removed using wrong identity');
$GLOBALS['cron_event_pending']=true;
ok(is_wp_error($c::reconcile_stale($id)), 'still-scheduled event cannot be reconciled blindly');
$GLOBALS['cron_event_pending']=false;
$GLOBALS['env']='production';
ok(is_wp_error($c::reconcile_stale($id)),'Production stale job repair denied');
$GLOBALS['env']='staging';
$GLOBALS['fail_lock_delete_once']=true;
$first=$c::reconcile_stale($id);
ok(is_wp_error($first) && $c::status()['manual_reconciliation_eligible']===true,
 'interrupted stale lock cleanup remains resumable without replay');
$result=$c::reconcile_stale($id);
ok(is_array($result) && $result['lock_released']===true
 && $result['new_job_enqueued']===false && $GLOBALS['calls']===1
 && $c::status()['job']['state']==='abandoned_after_owner_review',
 'exact stale lock reconciled without scheduling any new request');
echo "NATIVE_WPCRON_ACCEPTANCE_PASS\n";
