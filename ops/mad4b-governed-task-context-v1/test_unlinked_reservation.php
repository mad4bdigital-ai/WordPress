<?php
/** Isolated transaction simulation of original Canonical Gap lane. */
require __DIR__.'/test_unlinked.php';
function wp_get_environment_type(){return 'staging';}
function home_url(){return 'https://tours.dev.mad4b.com';}
class FlightHarnessDB extends MockUnlinkedDB {
 public $flights=[];public $triggers=[];public $legacy=0;
 function esc_like($v){return $v;}
 function args($sql){
  preg_match('~/\*(\[.*\])\*/~s',$sql,$m);
  return isset($m[1])?json_decode($m[1],true):[];
 }
 function get_row($sql,$mode=null){
  if(str_contains($sql,'wp_mad4b_hub_semantic_flights')){
    $v=$this->args($sql);$hash=$v[0]??'';
    foreach($this->flights as $row){
      if(str_contains($sql,'slot_hash=')&&$row['slot_hash']===$hash)return $row;
      if(str_contains($sql,'work_hash=')&&$row['work_hash']===$hash)return $row;
    }
    return null;
  }
  return parent::get_row($sql,$mode);
 }
 function get_var($sql){
  if(str_contains($sql,'SELECT COUNT(*) FROM wp_mad4b_hub_bulk_tasks'))return $this->legacy;
  if(str_contains($sql,'SELECT leader_task_id FROM wp_mad4b_hub_semantic_flights')){
    $v=$this->args($sql);$key=$v[0]??'';
    return $this->flights[$key]['leader_task_id']??null;
  }
  if(str_contains($sql,'SHOW TABLES LIKE'))return $this->args($sql)[0]??null;
  if(str_contains($sql,'SELECT ENGINE FROM information_schema.TABLES'))return 'InnoDB';
  if(str_contains($sql,'SELECT COUNT(*) FROM information_schema.STATISTICS'))return 1;
  return null;
 }
 function query($sql){
  if($sql==='START TRANSACTION'||$sql==='COMMIT'||$sql==='ROLLBACK')return 1;
  if(str_contains($sql,'INSERT IGNORE INTO wp_mad4b_hub_semantic_flights')){
    $v=$this->args($sql);
    if(count($v)!==12)throw new Exception('flight field count should be 12 not '.count($v));
    [$work,$slot,$job,$site,$tenant,$brand,$rev,$workflow,$company,$source,$task,$at]=$v;
    foreach($this->flights as $existing){
      if($existing['slot_hash']===$slot)return 0;
    }
    $this->flights[$work]=['work_hash'=>$work,'slot_hash'=>$slot,
      'job_id'=>$job,'site_uuid'=>$site,'tenant_id'=>$tenant,'brand_id'=>$brand,
      'job_revision'=>$rev,'workflow_type'=>$workflow,'source_company'=>$company,
      'source_record_id'=>$source,'leader_task_id'=>$task];
    return 1;
  }
  if(str_contains($sql,'INSERT IGNORE INTO wp_mad4b_hub_semantic_triggers')){
    $v=$this->args($sql);$key=$v[0].':'.$v[1];
    if(isset($this->triggers[$key]))return 0;
    $this->triggers[$key]=1;return 1;
  }
  throw new Exception('unexpected SQL '.substr($sql,0,120));
 }
 function insert($table,$data){
  $key=$data['work_hash'].':'.$data['trigger_hash'];
  if(isset($this->triggers[$key]))return 0;
  $this->triggers[$key]=1;return 1;
 }
}
$GLOBALS['wpdb']=new FlightHarnessDB();
$a=fixture();$a['_batch_key']='manual.gap.verified.one';
$b=fixture();$b['_batch_key']='manual.gap.verified.two';
$c=fixture();$c['_batch_key']='manual.gap.verified.three';
$c['payload']['stage_input']=['another'=>'independent-source'];
$leader='aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
$result=MAD4B_Hub_Governed_Single_Flight::reserve($a,$leader,'batch-one');
ok(!is_wp_error($result) && $result['decision']==='ADMITTED_SINGLE_LEADER',
 'unlinked exact source reserves first leader');
ok(count($GLOBALS['wpdb']->flights)===1,'one durable Work Slot created');
$second=MAD4B_Hub_Governed_Single_Flight::reserve($b,'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb','batch-two');
ok(is_wp_error($second)&&$second->get_error_code()==='mad4b_hub_flight_existing_single_flight_requires_follower_handoff',
 'cross-batch same original Work Hash rejected');
$third=MAD4B_Hub_Governed_Single_Flight::reserve($c,'cccccccc-cccc-4ccc-8ccc-cccccccccccc','batch-three');
ok(is_wp_error($third)&&$third->get_error_code()==='mad4b_hub_flight_overlapping_iteration_requires_review',
 'different semantic source in exact Work Slot requires review');
$receipt=MAD4B_Hub_Governed_Single_Flight::record_duplicate_trigger($b);
ok(!is_wp_error($receipt)&&($receipt['leader_task_id']??'')===$leader,'follower receipt attached to original leader');
ok(count($GLOBALS['wpdb']->flights)===1&&count($GLOBALS['wpdb']->triggers)===2,'two distinct triggers only one reserved executable task');
putenv('MAD4B_HUB_SEMANTIC_FLIGHT_MODE=enforce');
$task=['task_id'=>$leader,'content_job_id'=>'','target_ref'=>$a['target_ref'],
  'stage'=>$a['stage'],'operation'=>$a['operation'],
  'payload_json'=>wp_json_encode($a['payload']),'dependencies_json'=>'[]'];
ok(MAD4B_Hub_Governed_Single_Flight::claim_eligible($task),'original canonical leader claim eligible');
$task['task_id']='dddddddd-dddd-4ddd-8ddd-dddddddddddd';
ok(!MAD4B_Hub_Governed_Single_Flight::claim_eligible($task),'unlinked follower may not claim');
putenv('MAD4B_HUB_SEMANTIC_FLIGHT_MODE=');
echo "PASS 8 unlinked end-to-end isolated transaction checks\n";
