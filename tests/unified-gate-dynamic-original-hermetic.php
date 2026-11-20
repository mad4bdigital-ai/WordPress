<?php
/** First-party Unified Gate Funnel: exact graph, dynamic growth, no execution. */
define('ABSPATH',__DIR__.'/');
function wp_json_encode($x,$flags=0){return json_encode($x,$flags);}
function sanitize_key($value){return strtolower(preg_replace('/[^a-z0-9_-]/i','',(string)$value));}
function apply_filters($hook,$value){
 if($hook!=='mad4b_scp_unified_gate_registry')return $value;
 $mode=$GLOBALS['gate_filter']??'none';
 if($mode==='cycle') {
  $value['brand.source']['requires']=['content.execution'];
 } elseif($mode==='unknown_dependency') {
  $value['brand.source']['requires']=['absent.gate'];
 } elseif($mode==='invalid_descriptor') {
  $value['foreign:gate']=['requires'=>[],'surface'=>'content_generation'];
 } elseif($mode==='overflow') {
  foreach(range(1,133) as $i)
   $value['extra.gate.'.$i]=['surface'=>'content_generation',
      'requires'=>[],'required'=>false];
 } elseif($mode==='dynamic_required') {
  $value['content.external-policy']=['surface'=>'content_generation',
   'requires'=>['content.intake'],'required'=>true];
 }
 return $value;
}
final class MAD4B_SCP_Context_Authority {
 public static function status(){return [
   'site_uuid'=>'11111111-1111-4111-8111-111111111111',
   'registry_revision'=>12,'ready'=>$GLOBALS['context_ready']??true];}
 public static function review_policy(){return ['mode'=>$GLOBALS['policy_mode']??'ai_only'];}
}
final class MAD4B_SCP_Schema {
 public static function critical_ready(){return $GLOBALS['storage_ready']??true;}
}
final class MAD4B_SCP_Dynamic_Content_Pipeline {
 public static function registry(){return $GLOBALS['dynamic_stages']??[];}
}
function gate_assert($ok,$msg){if(!$ok){fwrite(STDERR,"FAIL: ".$msg."\n");exit(1);}}
require dirname(__DIR__).'/wp-content/plugins/mad4b-site-control-plane/includes/class-mad4b-scp-unified-gate-funnel.php';
$c='MAD4B_SCP_Unified_Gate_Funnel';
$GLOBALS['gate_filter']='none';
$p=$c::plan();
gate_assert($p['contract']===$c::CONTRACT && $p['mode']==='read_only'
 && $p['execution_authorized']===false && $p['approval_authorized']===false
 && $p['gate_count']===6 && strlen($p['plan_sha256'])===64,
 'Original graph and structural fingerprints are nonauthorizing');
$rows=array_column($p['gates'],'id');
gate_assert(array_search('brand.source',$rows,true)<array_search('brand.integrity',$rows,true)
 && array_search('brand.integrity',$rows,true)<array_search('review.quality',$rows,true)
 && array_search('content.intake',$rows,true)<array_search('content.execution',$rows,true),
 'Topological original graph respects dependencies');
gate_assert($p['plan_sha256']===$c::plan()['plan_sha256'],
 'Identical source state has deterministic graph fingerprint');
$r=$c::readiness();
gate_assert($r['execution_authorized']===false
 && in_array('content.execution',$r['mandatory_blockers'],true)
 && count($r['mandatory_blockers'])===1,
 'All source preconditions cannot self-promote content.execution');
$r=$c::readiness(['content.execution'=>true]);
gate_assert(in_array('content.execution',$r['mandatory_blockers'],true),
 'Caller observations cannot grant execution');
$r=$c::readiness(['review.quality'=>false]);
gate_assert(in_array('review.quality',$r['mandatory_blockers'],true)
 && in_array('content.intake',$r['mandatory_blockers'],true),
 'Negative quality observations transitively block dependent work');
$GLOBALS['policy_mode']='';
$r=$c::readiness();
gate_assert(in_array('review.authorization',$r['mandatory_blockers'],true),
 'Missing approved policy blocks graph readiness');
$GLOBALS['policy_mode']='ai_only';
$GLOBALS['context_ready']=false;
gate_assert(in_array('brand.source',$c::readiness()['mandatory_blockers'],true),
 'Original context not ready cannot admit work');
$GLOBALS['context_ready']=true;
$GLOBALS['storage_ready']=false;
gate_assert(in_array('content.intake',$c::readiness()['mandatory_blockers'],true),
 'Broken original SQL schema blocks work');
$GLOBALS['storage_ready']=true;
$GLOBALS['dynamic_stages']=[
 'tour-rate-dates'=>['phase'=>'pricing'],
 'images'=>['phase'=>'media'],
];
$p2=$c::plan();
gate_assert($p2['gate_count']===8
 && $p2['plan_sha256']!==$p['plan_sha256']
 && in_array('content.stage.tour-rate-dates',array_column($p2['gates'],'id'),true),
 'New provider stages grow from live registry and change exact source fingerprint');
$GLOBALS['gate_filter']='dynamic_required';
$r=$c::readiness();
gate_assert(in_array('content.external-policy',$r['mandatory_blockers'],true),
 'New required provider gate is never silently excluded');
foreach(['cycle','unknown_dependency','invalid_descriptor','overflow'] as $mode){
 $GLOBALS['gate_filter']=$mode;
 $threw=false;
 try{$c::plan();}catch(DomainException $e){$threw=true;}
 gate_assert($threw,'Unverified dynamic graph must fail closed: '.$mode);
}
$GLOBALS['gate_filter']='none';
foreach([[],['none'],['unknown']] as $surfaces) {
 $threw=false;
 try{$c::plan($surfaces);}catch(DomainException $e){$threw=true;}
 gate_assert($threw,'Invalid/empty requested graph scopes reject '.($surfaces[0]??'empty'));
}
echo "PASS: UNIFIED_GATE_DYNAMIC_ORIGINAL 17 first-party graph admission/denial cases\n";
