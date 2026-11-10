<?php
if (!defined('ABSPATH')) exit;
/** Read-only gate discovery and deterministic dependency plan; never grants authority. */
final class MAD4B_SCP_Unified_Gate_Funnel {
 const CONTRACT='mad4b.gate-funnel-plan.v1';
 public static function definitions(){
  $g=array(
   'brand.source'=>array('surface'=>'brand_authority','requires'=>array(),'required'=>true),
   'brand.integrity'=>array('surface'=>'brand_authority','requires'=>array('brand.source'),'required'=>true),
   'review.quality'=>array('surface'=>'context_review','requires'=>array('brand.integrity'),'required'=>true),
   'review.authorization'=>array('surface'=>'context_review','requires'=>array('review.quality'),'required'=>true),
   'content.intake'=>array('surface'=>'content_generation','requires'=>array('review.authorization'),'required'=>true),
   'content.execution'=>array('surface'=>'content_generation','requires'=>array('content.intake'),'required'=>true),
  );
  if(class_exists('MAD4B_SCP_Dynamic_Content_Pipeline'))foreach(MAD4B_SCP_Dynamic_Content_Pipeline::registry() as $id=>$stage)
   $g['content.stage.'.sanitize_key($id)]=array('surface'=>'content_generation','requires'=>array('content.intake'),'origin'=>'dynamic_content_pipeline','phase'=>$stage['phase']);
  $g=apply_filters('mad4b_scp_unified_gate_registry',$g);
  if(!is_array($g)||count($g)>128)throw new DomainException('gate_registry_invalid');
  $out=array();foreach($g as $id=>$gate){
   if(!is_string($id)||!preg_match('/^[a-z][a-z0-9._-]{1,95}$/D',$id)||!is_array($gate)||!in_array($gate['surface']??'',array('brand_authority','context_review','content_generation'),true))throw new DomainException('gate_descriptor_invalid');
   $deps=$gate['requires']??array();if(!is_array($deps)||count($deps)>32)throw new DomainException('gate_dependencies_invalid');
   foreach($deps as $dep)if(!is_string($dep)||!preg_match('/^[a-z][a-z0-9._-]{1,95}$/D',$dep))throw new DomainException('gate_dependency_invalid');
   $out[$id]=array('id'=>$id,'surface'=>$gate['surface'],'requires'=>array_values(array_unique($deps)),'required'=>!empty($gate['required']),'origin'=>$gate['origin']??'native','phase'=>$gate['phase']??'');
  }ksort($out,SORT_STRING);return $out;
 }
 /** Evaluate only observed preconditions; a plan is not an execution grant. */
 public static function readiness($observations=array()){
  $plan=self::plan();$authority=class_exists('MAD4B_SCP_Context_Authority')?MAD4B_SCP_Context_Authority::status():array();
  $policy=class_exists('MAD4B_SCP_Context_Authority')?MAD4B_SCP_Context_Authority::review_policy():array();
  $storage=class_exists('MAD4B_SCP_Schema')&&MAD4B_SCP_Schema::critical_ready();
  $observed=array('brand.source'=>!empty($authority['ready']),'brand.integrity'=>!empty($authority['ready']),
   'review.quality'=>!empty($authority['ready']),'review.authorization'=>!empty($authority['ready'])&&!empty($policy['mode']),
   'content.intake'=>$storage,'content.execution'=>false);
  // Caller-supplied observations may only close gates, never open one.
  foreach($observations as $id=>$value)if(array_key_exists($id,$observed)&&$value===false)$observed[$id]=false;
  $rows=array();$passed=array();$blocked=array();
  foreach($plan['gates'] as $gate){$id=$gate['id'];$ready=array_key_exists($id,$observed)?$observed[$id]:false;
   foreach($gate['requires'] as $dep)if(empty($passed[$dep]))$ready=false;
   $passed[$id]=$ready;
   if($gate['required']&&!$ready)$blocked[]=$id;
   $gate['status']=$ready?'ready':'blocked';$rows[]=$gate;
  }
  return array('contract'=>self::CONTRACT,'plan_sha256'=>$plan['plan_sha256'],'readiness'=>'blocked','execution_authorized'=>false,
   'mandatory_blockers'=>$blocked,'gates'=>$rows,'storage_ready'=>$storage,'context_ready'=>!empty($authority['ready']));
 }
 public static function plan($surfaces=array('brand_authority','context_review','content_generation'),$identity=array()){
  if(!is_array($surfaces)||!$surfaces||array_diff($surfaces,array('brand_authority','context_review','content_generation')))throw new DomainException('gate_surfaces_invalid');
  $all=self::definitions();$nodes=array();$collecting=array();$visit=function($id)use(&$visit,&$nodes,&$collecting,$all){
   if(!isset($all[$id]))throw new DomainException('gate_dependency_missing');
   if(isset($collecting[$id]))throw new DomainException('gate_dependency_cycle');
   if(isset($nodes[$id]))return;
   $collecting[$id]=true;foreach($all[$id]['requires'] as $dep)$visit($dep);
   unset($collecting[$id]);$nodes[$id]=$all[$id];
  };
  foreach($all as $id=>$g)if(in_array($g['surface'],$surfaces,true))$visit($id);
  $order=array();$active=array();$done=array();$sort=function($id)use(&$sort,&$order,&$active,&$done,$nodes){
   if(isset($active[$id]))throw new DomainException('gate_dependency_cycle');
   if(isset($done[$id]))return;
   $active[$id]=true;foreach($nodes[$id]['requires'] as $dep)$sort($dep);
   unset($active[$id]);$done[$id]=true;$order[]=$nodes[$id];
  };ksort($nodes,SORT_STRING);foreach(array_keys($nodes) as $id)$sort($id);
  if(!$identity && class_exists('MAD4B_SCP_Context_Authority')) { $state=MAD4B_SCP_Context_Authority::status(); $identity=array('site_uuid'=>$state['site_uuid']??null,'registry_revision'=>$state['registry_revision']??null,'ready'=>!empty($state['ready'])); }
  $sha=hash('sha256',wp_json_encode(array('contract'=>self::CONTRACT,'identity'=>$identity,'gates'=>$order)));
  return array('contract'=>self::CONTRACT,'mode'=>'read_only','execution_authorized'=>false,'approval_authorized'=>false,'plan_sha256'=>$sha,'gate_count'=>count($order),'identity'=>$identity,'gates'=>$order);
 }
}
