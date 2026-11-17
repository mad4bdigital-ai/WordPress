<?php
// Strictly offline unit test for host-independent classification and no dispatch.
define('ABSPATH','/fake-site/');
class WP_CLI {public static $commands=[];public static function add_command($n,$cb,$a){self::$commands[$n]=$cb;}public static function line($msg){echo $msg,"\n";}}
define('WP_CLI',true);
$GLOBALS['wp_fake_schedule']=['action_scheduler_run_queue'=>time()+30];
$GLOBALS['fake_cron_enabled']=true;
class FakeDB {
 public $prefix='wp_';public function prepare($sql,...$args){return [$sql,$args];}
 public function get_var($prepared){
  [$sql,$args]=$prepared;
  if(str_starts_with($sql,'SHOW TABLES'))return 'wp_actionscheduler_actions';
  if(str_contains($sql,'scheduled_date_gmt'))return '2';
  if(str_contains($sql,'COUNT(*)'))return '15';
  return null;
 }
}
$GLOBALS['wpdb']=new FakeDB;
function wp_parse_url($v,$type){return parse_url($v,$type);}
function home_url($path='/'){return 'https://different-cloud.example'.$path;}
function wp_get_environment_type(){return 'production';}
function wp_next_scheduled($x){return $GLOBALS['wp_fake_schedule'][$x]??false;}
function has_action($hook){return $hook==='action_scheduler_run_queue'?12:false;}
function wp_json_encode($x,$opts=0){return json_encode($x,$opts);}
function _get_cron_array(){return [time()-30=>['first'=>[['args'=>[]]],'second'=>[['args'=>[]]]],time()+30=>['third'=>[['args'=>[]]]]];}
require $argv[1];
function check($ok,$msg){if(!$ok)throw new RuntimeException($msg);}
$x=MAD4B_Site_Local_Cron_Runtime_V1::status();
check($x['mode']==='wordpress_core_request_driven','request-driven default');
check($x['execution_owner']==='site_host_runtime','runtime owner must be site');
check($x['central_remote_cron_dispatch_allowed']===false,'never central dispatch');
check($x['native_host_runner_attested']===false,'never counterfeit native host proof');
check($x['host_native_reconciliation_pending']===true,'native provider evidence remains pending');
check($x['site_local_fallback']==='wordpress_core_wp_cron','fallback stays at same site');
check($x['action_scheduler_queue']['pending']===15 && $x['action_scheduler_queue']['overdue_more_than_5m']===2,'real queue metadata shape');
check($x['wpcron_due_events']===2,'due event count');
check(isset(WP_CLI::$commands['mad4b cron-runtime status']),'CLI command exists');
check($x['request_driven_wp_cron_enabled']===true && $x['host_cron_reconciliation_required']===false,'safe default');
check($x['site_host']==='different-cloud.example','host neutral');
check($x['site_environment']==='production','environment neutral');
check(MAD4B_Site_Local_Cron_Runtime_V1::status(false)['action_scheduler_hook_registered']===true,'lightweight explicit status');
$source=file_get_contents($argv[1]);
foreach(['wp_schedule_event(','wp_remote_post(','wp_remote_get(','wp-cron.php','do_action('] as $forbidden)
 check(!str_contains($source,$forbidden),'no auto scheduling, dispatch or HTTP transport');
check(!str_contains($source,'update_option('),'no DB mutations');
print("PASS 15/15 site-owned Cron read-only runtime invariants\n");
