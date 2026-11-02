<?php
/**
 * Original-provider, independent non-mutating QA for exact Elementor WPML
 * structural deltas. It never issues an editorial/translation approval.
 */
defined('ABSPATH') || exit;
final class MAD4B_SCP_Content_Job_Structural_QA {
 const CONTRACT='mad4b.contentjob.structural-qa.v1';
 private const ALLOWED=['padding_mobile','_padding_mobile','animation','_animation',
  '_css_classes','_element_id','horizontal_gap_tablet','horizontal_gap_mobile'];
 private static function hold($reason,$base=[]):array {
  return $base+['state'=>'HOLD','reason'=>$reason,'eligible'=>false,'mutation_performed'=>false];
 }
 private static function nodes($nodes,&$map,$depth=0):bool {
  if(!is_array($nodes)||$depth>10||count($map)>250)return false;
  foreach($nodes as $node){
   if(!is_array($node)||empty($node['id']))return false;
   $id=(string)$node['id'];
   if(isset($map[$id]))return false;
   $map[$id]=[
    'anchor'=>(string)($node['settings']['_element_id']??''),
    'type'=>(string)($node['widgetType']??$node['elType']??''),
    'detached'=>($node['settings']['mad4b_wpml_sync_mode']??'')==='detach'
   ];
   if(!self::nodes($node['elements']??[],$map,$depth+1))return false;
  }
  return true;
 }
 private static function diff($before,$after,$path,&$changes):bool {
  if($before===$after)return true;
  if(is_array($before)&&is_array($after)){
   foreach(array_unique(array_merge(array_keys($before),array_keys($after))) as $key){
    if(!self::diff($before[$key]??null,$after[$key]??null,
       $path.'/'.(string)$key,$changes))return false;
   }
   return true;
  }
  if(count($changes)>=64)return false;
  // The only permitted delta is a known, non-content Elementor settings key.
  $parts=explode('/settings/',$path);
  if(count($parts)!==2)return false;
  $field=explode('/',$parts[1])[0];
  if(!in_array($field,self::ALLOWED,true))return false;
  if($field==='_element_id') {
   if($before!==null&&$before!==''||!is_string($after)||
       !preg_match('/^[a-zA-Z][a-zA-Z0-9_-]{0,79}$/D',$after))return false;
  }
  if($field==='_css_classes' && is_string($before) &&
     trim($before)!=='' && trim($after??'')==='' &&
     trim($before)!=='mad4b-wpml-needs-translation')return false;
  $changes[]=['path'=>$path,'field'=>$field,
   'before_sha256'=>hash('sha256',wp_json_encode($before)),
   'after_sha256'=>hash('sha256',wp_json_encode($after))];
  return true;
 }
 public static function inspect(string $jobId):array {
  $base=['contract'=>self::CONTRACT,'job_id'=>$jobId,'mutation_performed'=>false];
  if(!class_exists('MAD4B_WPML_Structural_Sync_V2',false)||
     !class_exists('MAD4B_SCP_Content_Jobs',false)||
     get_option('mad4b_wpml_structural_sync_v2_enabled','no')!=='yes')
   return self::hold('original_structural_provider_disabled',$base);
  $job=MAD4B_SCP_Content_Jobs::get_job(['job_id'=>$jobId]);
  if(is_wp_error($job)||!is_array($job['job']??null)||
     in_array(($job['job']['state']??''),['CANCELLED','COMPLETED'],true))
   return self::hold('original_job_terminal_or_unavailable',$base);
  $p=MAD4B_SCP_Content_Jobs::relationship_plan(['job_id'=>$jobId]);
  if(($p['state']??'')!=='READY'||($p['source_reference']['kind']??'')!=='post')
   return self::hold('native_wpml_family_or_artifact_not_ready',$base);
  $source=(int)($p['source_reference']['id']??0);
  $target=(int)($p['family']['target_id']??0);
  $lang=(string)($p['family']['target_locale']??'');
  if(!$source||!$target||$source===$target||!in_array($lang,['ar','de','es','fr','it'],true)||
     get_post_type($source)!=='elementor_library'||
     get_post_type($target)!=='elementor_library'||
     (int)($job['job']['current_artifact_id']??0)!==$target||
     get_post_status($source)!=='publish'||get_post_status($target)!=='publish')
   return self::hold('structural_object_or_language_not_eligible',$base);
  $orig=(string)get_post_meta($source,'_elementor_data',true);
  $dest=(string)get_post_meta($target,'_elementor_data',true);
  if(!$orig||!$dest||strlen($orig)>1048576||strlen($dest)>1048576)
   return self::hold('elementor_document_unavailable',$base);
  $a=json_decode($orig,true);$b=json_decode($dest,true);
  if(!is_array($a)||!is_array($b))return self::hold('elementor_json_invalid',$base);
  $issues=[];$n=0;
  $after=MAD4B_WPML_Structural_Sync_V2::plan($a,$b,$lang,$source,$target,$issues,$n);
  if($issues||!is_array($after)||$n<1)
   return self::hold('original_structural_provider_has_issues_or_no_delta',$base+
    ['issue_codes'=>array_values(array_unique(array_column($issues,'code')))]);
  $beforeNodes=[];$afterNodes=[];
  if(!self::nodes($b,$beforeNodes)||!self::nodes($after,$afterNodes)||
     array_keys($beforeNodes)!==array_keys($afterNodes))
   return self::hold('widget_structure_or_order_changed',$base);
  $anchors=[];
  foreach($afterNodes as $id=>$node){
   if($node['detached']!==$beforeNodes[$id]['detached']||
      $node['type']!==$beforeNodes[$id]['type'])
    return self::hold('detached_or_widget_identity_changed',$base);
   $anchor=$node['anchor'];
   if($anchor!==''){
    if(isset($anchors[$anchor]))return self::hold('duplicated_html_anchor',$base);
    $anchors[$anchor]=$id;
   }
  }
  $changes=[];
  if(!self::diff($b,$after,'',$changes)||!$changes)
   return self::hold('non_structural_or_rights_bearing_delta',$base);
  $srcHash=hash('sha256',$orig);$dstHash=hash('sha256',$dest);
  $planHash=hash('sha256',wp_json_encode($after,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));
  $report=$base+['state'=>'STRUCTURAL_DOCUMENT_VERIFIED',
    'eligible'=>true,'site_uuid'=>$p['site_uuid'],
    'source_post_id'=>$source,'target_post_id'=>$target,'locale'=>$lang,
    'job_revision'=>(int)$job['job']['job_revision'],
    'source_sha256'=>$srcHash,'target_sha256'=>$dstHash,
    'planned_sha256'=>$planHash,'changed_path_count'=>count($changes),
    'changed_fields'=>array_values(array_unique(array_column($changes,'field'))),
    'changes'=>$changes,'widget_count'=>count($afterNodes),
    'anchor_count'=>count($anchors),'missing_anchor_restored'=>
       in_array('_element_id',array_column($changes,'field'),true),
    'preserved_localized_content'=>true,'no_media_or_rights_bearing_delta'=>true,
    'render_or_interactive_verified'=>false,'editorial_translation_approved'=>false];
  $report['evidence_sha256']=hash('sha256',wp_json_encode($report,
   JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));
  return $report;
 }
}
