<?php
declare(strict_types=1);
define('ABSPATH','/tmp/');define('ARRAY_A','ARRAY_A');
class WP_Error {
 private $code;function __construct($code,$message='',$meta=[]){$this->code=$code;}
 function get_error_code(){return $this->code;}
}
function is_wp_error($v){return $v instanceof WP_Error;}
function wp_json_encode($v,$flags=0){return json_encode($v,$flags);}
class MAD4B_SCP_Site_Profile {
 static function site_uuid(){return 'e5e64bca-ed62-48d2-b2b3-dff693cd702a';}
}
class MAD4B_Hub_Workflow_Origin {
 static $accepted=true;
 static function claim_source_guard($task){return self::$accepted?true:new WP_Error('stale');}
}
class MockUnlinkedDB {
 public $prefix='wp_';
 public $row=[
  'id'=>19,'canonical_id'=>827,'company_id'=>'company-one','source_hub_id'=>11,
  'source_type'=>'tour','language'=>'en','gap_code'=>'source_pricing_missing',
  'gap_state'=>'needs_evidence','source_sha256'=>'aabbcc',
  'entity_company'=>'company-one','entity_hub'=>11,'entity_type'=>'tour',
  'entity_language'=>'en','snapshot_json'=>'{"provider":"tourism_wp","dataset":"tour_rates"}',
  'snapshot_hash'=>'dd','version'=>3
 ];
 function prepare($sql,...$args){return $sql.' /*'.json_encode($args).'*/';}
 function get_row($sql,$mode=null){
  if(!str_contains($sql,'FROM wp_mad4b_hub_canonical_gaps'))throw new Exception('unexpected SELECT');
  preg_match('~/\*\[(\d+),(\d+)\]\*/~',$sql,$m);
  if(!$m || (int)$m[1]!==19 || (int)$m[2]!==827)return null;
  return $this->row;
 }
}
$GLOBALS['wpdb']=new MockUnlinkedDB();
require __DIR__.'/class-mad4b-hub-unlinked-task-context.php';
require __DIR__.'/class-mad4b-hub-governed-single-flight.php';
function ok($v,$name){if(!$v){fwrite(STDERR,"FAIL ".$name."\n");exit(1);}echo "PASS ".$name."\n";}
function fixture($gap=19,$canonical=827,$company='company-one'){
 $o=[
  'contract'=>'mad4b.hub.workflow-origin.v1',
  'workflow_type'=>'product_data','origin_type'=>'canonical_gap',
  'source_record_id'=>'canonical_gap:'.$gap,'company_id'=>$company,'source_company'=>$company,
  'company_scope'=>'verified_canonical_source_not_distribution_target',
  'source_site_uuid'=>'','source_type'=>'tour','source_hub_id'=>11,
  'source_language'=>'en','source_provider'=>'tourism_wp','source_dataset'=>'tour_rates',
  'gap_code'=>'source_pricing_missing','required_capability'=>'verify_source_inventory',
  'executor_lane'=>'product_gap_resolution','source_verified'=>true
 ];
 $o['origin_sha256']=hash('sha256',wp_json_encode($o,JSON_UNESCAPED_SLASHES));
 return ['job_id'=>'','stage'=>'AVAILABILITY','operation'=>'verify_source_inventory',
  'target_ref'=>'canonical:'.$canonical,'payload'=>[
   'workflow_origin'=>$o,'assigned_executor'=>'mcp_client',
   'policy_snapshot_sha256'=>str_repeat('b',64),
   'capability_snapshot_sha256'=>str_repeat('c',64),
   'output_contract'=>'inventory_readback.v1','effect_type'=>'read_only',
   'stage_input'=>['check'=>'inventory']]];
}
function plan($item){return MAD4B_Hub_Unlinked_Task_Context::plan(
 $item,$item['payload']['workflow_origin'],$item['payload']);}
$a=fixture();$p=plan($a);
$first=fixture();$first['_batch_key']='canonical.gap.source.001';
$second=fixture();$second['_batch_key']='canonical.gap.source.002';
$cp=MAD4B_Hub_Governed_Single_Flight::plan($first);
$cr=MAD4B_Hub_Governed_Single_Flight::plan($second);
ok(!is_wp_error($cp)&&($cp['workflow_type']??'')==='product_data','unlinked work routed through original single flight');
ok($cp['work_hash']===$cr['work_hash']&&$cp['slot_hash']===$cr['slot_hash'],
 'unlinked batches share original scoped Work Slot');
ok($cp['trigger_hash']!==$cr['trigger_hash'],'manual unlinked batch receives separate trigger receipt');
$change=fixture();$change['_batch_key']='canonical.gap.source.003';
$change['payload']['stage_input']['check']='different_input';
$next=MAD4B_Hub_Governed_Single_Flight::plan($change);
ok(!is_wp_error($next)&&$next['slot_hash']===$cp['slot_hash']&&$next['work_hash']!==$cp['work_hash'],
 'unlinked new semantic iteration conflicts in same slot');
$untrusted=fixture();$untrusted['_batch_key']='canonical.gap.source.004';
$untrusted['payload']['effect_type']='pricing_write';
ok(is_wp_error(MAD4B_Hub_Governed_Single_Flight::plan($untrusted)),
 'unlinked write request never inherits read-only slot authority');

ok(!is_wp_error($p)&&($p['eligible']??false)===true,'canonical original provider and gap proof accepted');
ok(($p['scope']['source_company']??'')==='company-one' && ($p['scope']['job_id']??'INVALID')==='',
 'source company kept separate from Content Job identity');
$b=fixture();$b['payload']['stage_input']=['check'=>'new-source-review'];
ok(!is_wp_error(plan($b))&&$p['slot_hash']===plan($b)['slot_hash']
   &&$p['work_hash']!==plan($b)['work_hash'],
 'semantic iteration uses same work slot but distinct work hash');
$b=fixture(20);ok(is_wp_error(plan($b)),'unknown gap not adopted');
$b=fixture(19,999);ok(is_wp_error(plan($b)),'wrong canonical entity not adopted');
$b=fixture(19,827,'company-two');ok(is_wp_error(plan($b)),'company spoof denied');
$b=fixture();$b['payload']['workflow_origin']['source_provider']='forged';
ok(is_wp_error(plan($b)),'forged origin digest denied');
$b=fixture();$b['payload']['effect_type']='pricing_write';
ok(is_wp_error(plan($b)),'mutating pricing denied');
$b=fixture();$b['operation']='resolve_source_pricing';
ok(is_wp_error(plan($b)),'high impact pricing operation needs original review');
$b=fixture();$b['payload']['assigned_executor']='vps_runner';
ok(is_wp_error(plan($b)),'cannot silently upgrade provider executor');
$b=fixture();unset($b['payload']['policy_snapshot_sha256']);
ok(is_wp_error(plan($b)),'missing context policy evidence denied');
$b=fixture();$GLOBALS['wpdb']->row['company_id']='company-two';
ok(is_wp_error(plan($b)),'changed original company denied');
$GLOBALS['wpdb']->row['company_id']='company-one';
$GLOBALS['wpdb']->row['snapshot_json']='{"provider":"other","dataset":"tour_rates"}';
ok(is_wp_error(plan($b)),'changed original provider denied');
$GLOBALS['wpdb']->row['snapshot_json']='{"provider":"tourism_wp","dataset":"tour_rates"}';
MAD4B_Hub_Workflow_Origin::$accepted=false;
ok(is_wp_error(plan(fixture())),'original claim-source guard veto respected');
MAD4B_Hub_Workflow_Origin::$accepted=true;
foreach(['qa_diagnostic','integration_operation','unknown'] as $type){
 $b=fixture();$b['payload']['workflow_origin']['workflow_type']=$type;
 ok(is_wp_error(plan($b)),'unverified '.$type.' cannot inherit Canonical authority');
}
foreach([null,[],['workflow_type'=>'qa_diagnostic'],['workflow_type'=>'integration_operation'],
 ['workflow_type'=>'product_data']] as $o){
 $result=MAD4B_Hub_Unlinked_Task_Context::classify($o);
 ok(($result['eligible']??true)===false,'all unlinked types classified with no invented authority');
}
echo "PASS 27 original-source unlinked contract assertions\n";
