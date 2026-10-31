<?php
declare(strict_types=1);
define('ABSPATH','/tmp/');
define('ARRAY_A','ARRAY_A');
class WP_Error {
 private $code;function __construct($code,$message='',$meta=[]){$this->code=$code;}
 function get_error_code(){return $this->code;}
}
function is_wp_error($x){return $x instanceof WP_Error;}
function wp_json_encode($x,$flags=0){return json_encode($x,$flags);}
function wp_get_environment_type(){return 'staging';}
function home_url(){return 'https://tours.dev.mad4b.com';}
class MAD4B_SCP_Content_Jobs {
 static $row=['job_id'=>'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa',
 'site_uuid'=>'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb',
 'tenant_id'=>'test-tenant','brand_id'=>'test-brand','language'=>'en',
 'country'=>'us','content_type'=>'article','job_revision'=>5,
 'stage'=>'INFORMATION_GAIN','state'=>'RUNNING'];
 static function get_job($x){return ['job'=>self::$row];}
}
class StubDb {
 public $prefix='wp_';
 public $flights=[];public $triggers=[];public $legacy=0;public $rolledBack=false;
 function prepare($sql,...$a){
   foreach($a as $v){
     $needle=strpos($sql,'%s');$kind='%s';
     if($needle===false){$needle=strpos($sql,'%d');$kind='%d';}
     if($needle===false)throw new Exception('unbound args');
     $replace=$kind==='%d'?(string)(int)$v:"'".str_replace("'","''",(string)$v)."'";
     $sql=substr_replace($sql,$replace,$needle,2);
   }
   return $sql;
 }
 function get_var($sql){
  if(str_contains($sql,'SELECT COUNT(*) FROM wp_mad4b_hub_bulk_tasks'))return $this->legacy;
  if(str_contains($sql,'SELECT leader_task_id FROM')){
    preg_match("/work_hash='([a-f0-9]{64})'/",$sql,$m);
    return $this->flights[$m[1]??'']['leader_task_id']??null;
  }
  if(str_contains($sql,'SHOW TABLES LIKE')){
    preg_match("/LIKE '([^']+)'/",$sql,$m);
    return $m[1]??null;
  }
  if(str_contains($sql,'SELECT ENGINE FROM information_schema.TABLES'))return 'InnoDB';
  if(str_contains($sql,'SELECT COUNT(*) FROM information_schema.STATISTICS'))return 1;
  return null;
 }
 function get_row($sql,$mode=null){
  preg_match("/work_hash='([a-f0-9]{64})'/",$sql,$m);
  return $this->flights[$m[1]??'']??null;
 }
 function query($sql) {
   if($sql==='ROLLBACK'){$this->rolledBack=true;return 1;}
   if($sql==='START TRANSACTION'||$sql==='COMMIT')return 1;
   if(str_contains($sql,'INSERT IGNORE INTO wp_mad4b_hub_semantic_flights')){
     preg_match("/VALUES \('([a-f0-9]{64})'[^)]*'([a-f0-9-]{36})'\s*,\s*'[^']*'\s*\)/",$sql,$m);
     if(!preg_match("/VALUES \('([a-f0-9]{64})'/",$sql,$h))throw new Exception('hash missing');
     if(isset($this->flights[$h[1]]))return 0;
     $this->flights[$h[1]]=['work_hash'=>$h[1],'job_id'=>MAD4B_SCP_Content_Jobs::$row['job_id'],
       'site_uuid'=>MAD4B_SCP_Content_Jobs::$row['site_uuid'],
       'tenant_id'=>'test-tenant','brand_id'=>'test-brand','job_revision'=>5,
       'workflow_type'=>'content_production','source_company'=>'',
       'source_record_id'=>MAD4B_SCP_Content_Jobs::$row['job_id'],
       'leader_task_id'=>'cccccccc-cccc-4ccc-8ccc-cccccccccccc'];
     return 1;
   }
   if(str_contains($sql,'INSERT IGNORE INTO wp_mad4b_hub_semantic_triggers')){
     preg_match("/VALUES \('([a-f0-9]{64})',\s*'([a-f0-9]{64})'/",$sql,$m);
     if(!isset($m[2]))throw new Exception('trigger parse failed');
     $key=$m[1].':'.$m[2];if(isset($this->triggers[$key]))return 0;
     $this->triggers[$key]=1;return 1;
   }
   throw new Exception('unexpected query');
 }
 function insert($table,$data){if(!str_ends_with($table,'semantic_triggers'))throw new Exception('unexpected insert');$this->triggers[$data['work_hash'].':'.$data['trigger_hash']]=1;return 1;}
}
$GLOBALS['wpdb']=new StubDb;
require __DIR__.'/class-mad4b-hub-governed-single-flight.php';
function check($yes,$label){if(!$yes){fwrite(STDERR,"FAIL $label\n");exit(1);}echo "PASS $label\n";}
function fixture($trigger='event',$event='a'){
 $origin=['contract'=>'mad4b.hub.workflow-origin.v1','workflow_type'=>'content_production',
  'source_record_id'=>MAD4B_SCP_Content_Jobs::$row['job_id'],'source_verified'=>true,
  'job_revision'=>5,'source_site_uuid'=>MAD4B_SCP_Content_Jobs::$row['site_uuid'],
  'source_language'=>'en'];
 $origin['origin_sha256']=hash('sha256',wp_json_encode($origin,JSON_UNESCAPED_SLASHES));
 return ['job_id'=>MAD4B_SCP_Content_Jobs::$row['job_id'],'stage'=>'INFORMATION_GAIN',
 'operation'=>'stage_work','target_ref'=>'','payload'=>[
   'workflow_origin'=>$origin,'content_job_revision'=>5,
   'source_snapshot_sha256'=>str_repeat('a',64),
   'policy_snapshot_sha256'=>str_repeat('b',64),
   'capability_snapshot_sha256'=>str_repeat('c',64),
   'output_contract'=>'keyword.v1','effect_type'=>'research_artifact',
   'stage_input'=>['keyword'=>'egypt'],'trigger_source'=>$trigger,'trigger_event_id'=>$event
 ]];
}
$a=fixture();$b=fixture('manual','b');
$p=MAD4B_Hub_Governed_Single_Flight::plan($a);
$q=MAD4B_Hub_Governed_Single_Flight::plan($b);
check(!is_wp_error($p)&&$p['eligible'],'valid original signed workflow scope');
check($p['work_hash']===$q['work_hash'],'same work across different trigger sources');
check($p['trigger_hash']!==$q['trigger_hash'],'distinct trigger audit identities');
$bad=$b;$bad['payload']['policy_snapshot_sha256']=str_repeat('d',64);
check(MAD4B_Hub_Governed_Single_Flight::plan($bad)['work_hash']!==$p['work_hash'],'policy change creates new work');
$bad=$b;$bad['payload']['content_job_revision']=4;
check(is_wp_error(MAD4B_Hub_Governed_Single_Flight::plan($bad)),'stale original revision denied');
$bad=$b;$bad['payload']['workflow_origin']['source_verified']=false;
check(is_wp_error(MAD4B_Hub_Governed_Single_Flight::plan($bad)),'forged origin denied');
$bad=$b;unset($bad['payload']['source_snapshot_sha256']);
check(is_wp_error(MAD4B_Hub_Governed_Single_Flight::plan($bad)),'missing source proof denied');
$f=$GLOBALS['wpdb'];
check(MAD4B_Hub_Governed_Single_Flight::reserve($a,'cccccccc-cccc-4ccc-8ccc-cccccccccccc','batchA')['decision']==='ADMITTED_SINGLE_LEADER','first leader reserved');
check(is_wp_error(MAD4B_Hub_Governed_Single_Flight::reserve($b,'dddddddd-dddd-4ddd-8ddd-dddddddddddd','batchB')),'second leader rejected atomically');
$r=MAD4B_Hub_Governed_Single_Flight::record_duplicate_trigger($b);
check(!is_wp_error($r)&&$r['decision']==='DUPLICATE_TRIGGER_RECORDED','follower trigger recorded without new task');
check(count($f->flights)===1 && count($f->triggers)===2,'one leader two triggers');
$r=MAD4B_Hub_Governed_Single_Flight::record_duplicate_trigger($b);
check(!$r['trigger_newly_recorded']&&count($f->triggers)===2,'follower receipt replay idempotent');
$task=['task_id'=>'cccccccc-cccc-4ccc-8ccc-cccccccccccc','content_job_id'=>$a['job_id'],
 'payload_json'=>wp_json_encode($a['payload']), 'stage'=>'INFORMATION_GAIN','operation'=>'stage_work','target_ref'=>''];
check(MAD4B_Hub_Governed_Single_Flight::claim_eligible($task),'disabled gate is nonmutating');
$f->legacy=1;
check(is_wp_error(MAD4B_Hub_Governed_Single_Flight::reserve($bad,'eeeeeeee-eeee-4eee-8eee-eeeeeeeeeeee','batchC')),'invalid legacy or proof denied');
echo "PASS 14 isolated single-flight contract checks\n";
