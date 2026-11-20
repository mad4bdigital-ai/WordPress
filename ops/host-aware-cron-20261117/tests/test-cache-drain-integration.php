<?php
/* Test the ACTUAL original PHP class in an isolated fake WordPress store.
 * This executable never loads wp-load.php, the database or the network.
 */
define('ABSPATH','/var/www/html/');
define('HOUR_IN_SECONDS',3600);
class MAD4B_Cache_Lifecycle_V1 {
 const OPTION='cache_fixture_data';
 public static function safe_url($url){return str_starts_with($url,'https://tours.dev.mad4b.com/');}
}
class WP_Error {}
$GLOBALS['options']=[];
$GLOBALS['requests']=[];
$GLOBALS['mode']='pass';
function wp_generate_uuid4(){return 'test-uuid-no-provenance';}
function get_option($key,$default=null){return $GLOBALS['options'][$key]??$default;}
function update_option($key,$value,$autoload=null){$GLOBALS['options'][$key]=$value;return true;}
function maybe_unserialize($v){return is_string($v)?unserialize($v):$v;}
function maybe_serialize($v){return serialize($v);}
function wp_cache_delete($key,$group){return true;}
function is_wp_error($v){return $v instanceof WP_Error;}
function wp_remote_retrieve_response_code($v){return $v['code']??0;}
function wp_remote_retrieve_header($v,$k){return $v['headers'][$k]??'';}
function wp_remote_get($url,$opts){
 if(!MAD4B_Cache_Lifecycle_V1::safe_url($url))throw new RuntimeException('REMOTE_OR_SENSITIVE_URL');
 if(($opts['timeout']??null)!==3 || ($opts['redirection']??null)!==0 || ($opts['limit_response_size']??null)!==1024)
  throw new RuntimeException('BUDGET_OR_HTTP_CONTEXT_WRONG');
 $GLOBALS['requests'][]=['url'=>$url,'timeout'=>$opts['timeout']];
 if($GLOBALS['mode']==='fail')return new WP_Error;
 return ['code'=>200,'headers'=>['x-mad4b-content-revision'=>'rev','cache-control'=>'public']];
}
function do_action($name,...$args){throw new RuntimeException('CRON_MUST_NOT_PURGE:'.$name);}
class FakeWpdb {
 public $options='wp_options';
 public function prepare($sql,...$args){return $sql;}
 public function get_var($sql){
  if(str_contains($sql,'GET_LOCK(') || str_contains($sql,'RELEASE_LOCK('))return '1';
  if(str_contains($sql,'SELECT option_value'))return serialize($GLOBALS['options'][MAD4B_Cache_Lifecycle_V1::OPTION]);
  return null;
 }
 public function query($sql){if(!in_array($sql,['START TRANSACTION','COMMIT','ROLLBACK']))throw new RuntimeException('OTHER_SQL');return 1;}
 public function update($table,$values,$where){
  if($table!==$this->options || $where['option_name']!==MAD4B_Cache_Lifecycle_V1::OPTION)
    throw new RuntimeException('FOREIGN_OPTION_MUTATED');
  $GLOBALS['options'][MAD4B_Cache_Lifecycle_V1::OPTION]=unserialize($values['option_value']);
  return 1;
 }
}
$GLOBALS['wpdb']=new FakeWpdb();
require $argv[1];
function assert_true($cond,$reason){if(!$cond)throw new RuntimeException($reason);}
function fixture(){
 $rows=[];
 for($i=0;$i<8;$i++){
  $rows['route-'.$i]=['url'=>'https://tours.dev.mad4b.com/tours/'.$i.'/','revision'=>'rev','state'=>'PENDING_INVALIDATION','checked'=>0,'updated'=>$i];
 }
 $GLOBALS['options'][MAD4B_Cache_Lifecycle_V1::OPTION]=$rows;
 $GLOBALS['requests']=[];
}
fixture();
$result=MAD4B_Cache_Lifecycle_Control_V1::drain();
$rows=get_option(MAD4B_Cache_Lifecycle_V1::OPTION);
assert_true($result['checked']===3,'not_bounded_to_three_routes');
assert_true(count($GLOBALS['requests'])===3,'too_many_http_requests');
assert_true($result['verification_budget']==['max_routes'=>3,'per_request_timeout_seconds'=>3,'max_wall_seconds'=>12],'invalid_budget_ledger');
assert_true(count(array_filter($rows,fn($r)=>$r['state']==='VERIFIED'))===3,'verification_count_wrong');
assert_true(count(array_filter($rows,fn($r)=>$r['state']==='PENDING_INVALIDATION'))===5,'unselected_rows_modified');
assert_true($result['budget_deferred']===0,'unexpected_budget_deferral');
fixture();$GLOBALS['mode']='fail';
$failed=MAD4B_Cache_Lifecycle_Control_V1::drain();
$rows=get_option(MAD4B_Cache_Lifecycle_V1::OPTION);
assert_true($failed['checked']===3 && $failed['failed']===3,'timeout_failures_not_reported');
assert_true(count(array_filter($rows,fn($r)=>$r['state']==='VERIFY_FAILED'))===3,'timeout_was_certified');
assert_true(count(array_filter($rows,fn($r)=>$r['state']==='PENDING_INVALIDATION'))===5,'failure_destroyed_unprocessed_rows');
assert_true(count(array_filter($rows,fn($r)=>($r['retry_after']??0)>time()))===3,'retry_backoff_missing');
assert_true($failed['state']==='RETRY_PENDING','timeout_state_not_fail_closed');
assert_true($failed['duration_ms']>=0,'missing_duration_metrics');
echo "PASS 12/12 original PHP drain mocked network+database+retry invariants\n";
