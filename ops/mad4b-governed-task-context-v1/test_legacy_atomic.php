<?php
declare(strict_types=1);
define('ABSPATH','/tmp/');
define('ARRAY_A','ARRAY_A');
class WP_Error {
 private string $code;
 public function __construct($code,$message=''){$this->code=$code;}
 public function get_error_code(){return $this->code;}
}
function is_wp_error($x){return $x instanceof WP_Error;}
function wp_json_encode($value,$options=0){return json_encode($value,$options);}
function wp_get_environment_type(){return 'staging';}
function home_url(){return 'https://tours.dev.mad4b.com';}
function current_user_can($cap){return $cap==='manage_options';}
function get_option($name,$default=''){
 $v=$GLOBALS['wpdb']->option;
 return $name==='mad4b_hub_semantic_legacy_cutover_locked'&&$v!==null?$v:$default;
}
function wp_cache_delete($key,$group=''){
 $GLOBALS['cache_invalidations'][]="$group:$key";
 return true;
}
class AtomicLegacyDB {
 public string $prefix='wp_';
 public string $options='wp_options';
 public bool $fail_option_write=false;
 public ?string $option=null;
 public array $live=[];
 public array $allow=[];
 public array $events=[];
 private ?array $before=null;
 public function prepare($query,...$vals){
  foreach($vals as $val){
   $q="'".str_replace("'","''",(string)$val)."'";
   $query=preg_replace('/%s/',$q,$query,1);
  }
  return $query;
 }
 public function get_results($query,$mode=null){return $this->live;}
 public function get_var($query){
  if(str_contains($query,'information_schema.TABLES'))return 'InnoDB';
  if(str_contains($query,'information_schema.STATISTICS'))return 1;
  if(str_contains($query,'SELECT COUNT(*) FROM wp_mad4b_hub_semantic_legacy'))return count($this->allow);
  if(str_contains($query,'SELECT option_value FROM wp_options'))return $this->option;
  return null;
 }
 public function get_row($query,$mode=null){
  preg_match("/task_id='([^']+)'/",$query,$m);
  return $this->allow[$m[1]??'']??null;
 }
 public function insert($table,$data){
  if($table===$this->options){
   $this->events[]='lock-insert';
   if($this->fail_option_write)return false;
   $this->option=(string)$data['option_value'];
   return 1;
  }
  if($table==='wp_mad4b_hub_semantic_legacy'){
   $this->events[]='allow-insert';
   if(isset($this->allow[$data['task_id']]))return false;
   $this->allow[$data['task_id']]=[
    'batch_id'=>$data['batch_id'],'identity_sha256'=>$data['identity_sha256']];
   return 1;
  }
  return false;
 }
 public function update($table,$data,$where){
  if($table!==$this->options)return false;
  $this->events[]='lock-update';
  if($this->fail_option_write)return false;
  $this->option=(string)$data['option_value'];
  return 1;
 }
 public function query($sql){
  if($sql==='START TRANSACTION'){
   $this->events[]='begin';
   $this->before=['allow'=>$this->allow,'option'=>$this->option];
   return 1;
  }
  if($sql==='ROLLBACK'){
   $this->events[]='rollback';
   if($this->before!==null){
    $this->allow=$this->before['allow'];$this->option=$this->before['option'];
   }
   $this->before=null;
   return 1;
  }
  if($sql==='COMMIT'){
   $this->events[]='commit';
   $this->before=null;
   return 1;
  }
  return false;
 }
}
$GLOBALS['wpdb']=new AtomicLegacyDB();
$GLOBALS['cache_invalidations']=[];
require __DIR__.'/plugin/includes/class-mad4b-hub-semantic-legacy-cutover.php';
function check($pass,$message){
 if(!$pass)throw new RuntimeException('FAIL '.$message);
 echo 'PASS '.$message.PHP_EOL;
}
function task($id){
 return ['task_id'=>$id,'batch_id'=>'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb',
  'task_key'=>'stage','content_job_id'=>'','target_ref'=>'canonical:827',
  'stage'=>'ENTITY_RESOLUTION','operation'=>'verify_source_inventory',
  'dependencies_json'=>'[]','payload_json'=>'{"assigned_executor":"mcp_client"}',
  'created_at'=>'2026-10-01 00:00:00'];
}
$db=$GLOBALS['wpdb'];
$db->live=[task('aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa'),task('cccccccc-cccc-4ccc-8ccc-cccccccccccc')];
$preview=MAD4B_Hub_Semantic_Legacy_Cutover::snapshot(false);
check($preview['task_count']===2,'dry-run count verified before transaction');
$db->fail_option_write=true;
$failed=MAD4B_Hub_Semantic_Legacy_Cutover::snapshot(true,2,$preview['snapshot_sha256']);
check(is_wp_error($failed)&&$failed->get_error_code()==='mad4b_legacy_capture_failed','lock write failure aborts apply');
check(count($db->allow)===0&&$db->option===null,'lock write failure rolls back EVERY allowlist row');
check(end($db->events)==='rollback','rollback follows failure before commit');
$db->fail_option_write=false;
$success=MAD4B_Hub_Semantic_Legacy_Cutover::snapshot(true,2,$preview['snapshot_sha256']);
check(!is_wp_error($success)&&$success['applied']===true,'atomic lock succeeds with the allowlist');
check(count($db->allow)===2&&$db->option==='1','two tasks and durable lock committed together');
$commitPos=array_search('commit',$db->events,true);
$lockPos=array_search('lock-insert',$db->events,true);
check($lockPos!==false&&$commitPos!==false&&$lockPos<$commitPos,'option lock is written before first commit');
check(in_array('options:mad4b_hub_semantic_legacy_cutover_locked',$GLOBALS['cache_invalidations'],true),
 'lock option cache invalidated after commit');
check(MAD4B_Hub_Semantic_Legacy_Cutover::claim_allowed($db->live[0]),'original immutable claim remains allowed');
$spoof=$db->live[0];$spoof['payload_json']='{}';
check(!MAD4B_Hub_Semantic_Legacy_Cutover::claim_allowed($spoof),'rewritten claim remains blocked');
check(is_wp_error(MAD4B_Hub_Semantic_Legacy_Cutover::snapshot(true,2,$preview['snapshot_sha256'])),
 'locked snapshot cannot be silently overwritten');
$db=new AtomicLegacyDB();$GLOBALS['wpdb']=$db;
$db->option='0';
$db->live=[task('dddddddd-dddd-4ddd-8ddd-dddddddddddd')];
$next=MAD4B_Hub_Semantic_Legacy_Cutover::snapshot(false);
$again=MAD4B_Hub_Semantic_Legacy_Cutover::snapshot(true,1,$next['snapshot_sha256']);
check(!is_wp_error($again)&&$again['applied']===true,'pre-existing disabled option is updated atomically');
check(in_array('lock-update',$db->events,true),'pre-existing option update uses same transaction');
echo 'PASS 11 atomic legacy cutover source assertions'.PHP_EOL;
