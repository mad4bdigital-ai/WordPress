<?php
declare(strict_types=1);
define('ABSPATH','/tmp/');
define('ARRAY_A','ARRAY_A');
class WP_Error {
 private $code;
 public function __construct($code,$message=''){ $this->code=$code; }
 public function get_error_code(){return $this->code;}
}
function is_wp_error($v){return $v instanceof WP_Error;}
function wp_json_encode($v,$opts=0){return json_encode($v,$opts);}
function wp_get_environment_type(){return 'staging';}
function home_url(){return 'https://tours.dev.mad4b.com';}
function current_user_can($cap){return false;}
function get_option($k,$default=''){return $GLOBALS['option_state'][$k]??$default;}
class LegacyMockDB {
 public $prefix='wp_';
 public $allow=[];
 public $live=[];
 public $schema=true;
 function prepare($query,...$values){
  foreach($values as $v){
   $query=preg_replace('/%s/',"'".str_replace("'","''",$v)."'",$query,1);
  }
  return $query;
 }
 function get_var($sql){
  if(str_contains($sql,'SELECT ENGINE FROM information_schema.TABLES'))return $this->schema?'InnoDB':null;
  if(str_contains($sql,'information_schema.STATISTICS'))return $this->schema?1:0;
  return 0;
 }
 function get_row($sql,$mode=null){
  preg_match("/task_id='([^']+)'/",$sql,$m);
  return $this->allow[$m[1]??'']??null;
 }
 function get_results($sql,$mode=null){return $this->live;}
}
$GLOBALS['wpdb']=new LegacyMockDB();
$GLOBALS['option_state']=[];
$source=__DIR__.'/plugin/includes/class-mad4b-hub-semantic-legacy-cutover.php';
if(!is_file($source))$source=__DIR__.'/mad4b-hub-semantic-legacy-cutover.php';
require $source;
function ok($predicate,$description){if(!$predicate)throw new Exception('FAIL '.$description);echo 'PASS '.$description.PHP_EOL;}
function row($id='aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa'){
 return ['task_id'=>$id,'batch_id'=>'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb',
 'task_key'=>'stage','content_job_id'=>'','target_ref'=>'canonical:827',
 'stage'=>'ENTITY_RESOLUTION','operation'=>'verify_source_inventory',
 'dependencies_json'=>'[]','payload_json'=>'{"assigned_executor":"mcp_client"}',
 'created_at'=>'2026-10-01 00:00:00'];
}
$target=row();$new=row('cccccccc-cccc-4ccc-8ccc-cccccccccccc');
$h=MAD4B_Hub_Semantic_Legacy_Cutover::hash_row($target);
ok(strlen($h)===64,'complete original immutable identity fingerprint');
ok(MAD4B_Hub_Semantic_Legacy_Cutover::claim_allowed($target)===false,'disabled cutover denies unlisted claim');
$previewDb=$GLOBALS['wpdb'];$previewDb->live=[$target,$new];
$p=MAD4B_Hub_Semantic_Legacy_Cutover::snapshot(false);
ok(!is_wp_error($p)&&$p['task_count']===2&&$p['applied']===false,'dry run previews exactly two persisted records without mutation');
$p2=MAD4B_Hub_Semantic_Legacy_Cutover::snapshot(false);
ok($p['snapshot_sha256']===$p2['snapshot_sha256'],'repeatable cutover snapshot digest');
ok(is_wp_error(MAD4B_Hub_Semantic_Legacy_Cutover::snapshot(true,2,$p['snapshot_sha256'])),
 'unprivileged apply denied despite matching digest');
$GLOBALS['option_state']['mad4b_hub_semantic_legacy_cutover_locked']='1';
$previewDb->allow[$target['task_id']]=['batch_id'=>$target['batch_id'],'identity_sha256'=>$h];
ok(MAD4B_Hub_Semantic_Legacy_Cutover::claim_allowed($target),'verified pre-existing task allowed to reach original executor guards');
ok(!MAD4B_Hub_Semantic_Legacy_Cutover::claim_allowed($new),'new unlisted task denied');
$t=$target;$t['batch_id']='changed';
ok(!MAD4B_Hub_Semantic_Legacy_Cutover::claim_allowed($t),'tampered batch rejected');
$t=$target;$t['payload_json']='{}';
ok(!MAD4B_Hub_Semantic_Legacy_Cutover::claim_allowed($t),'tampered payload rejected');
$t=$target;$t['dependencies_json']='["unknown"]';
ok(!MAD4B_Hub_Semantic_Legacy_Cutover::claim_allowed($t),'tampered dependency graph rejected');
$t=$target;$t['content_job_id']='forged';
ok(!MAD4B_Hub_Semantic_Legacy_Cutover::claim_allowed($t),'forged job binding rejected');
$previewDb->schema=false;
ok(!MAD4B_Hub_Semantic_Legacy_Cutover::claim_allowed($target),'missing legacy table fails closed');
echo 'PASS 12 legacy cutover source assertions'.PHP_EOL;
