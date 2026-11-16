<?php
/**
 * Plugin Name: MAD4B Media Rights Content Job Bridge
 * Description: Optional, bounded rights intake for content jobs with imported media. No automatic rights approval.
 * Version: 1.0.0
 */
defined('ABSPATH') || exit;
if (!class_exists('MAD4B_Media_Rights_Content_Job_Bridge_V1', false)) {
final class MAD4B_Media_Rights_Content_Job_Bridge_V1 {
 const STAGES = ['MEDIA', 'POST_PUBLISH'];
 const MAX_IMAGES = 24;
 const MEDIA_META = ['_mad4b_import_source_url','_mad4b_remote_media_source_sha256','_mad4b_remote_media_manifest_sha256','_mad4b_media_operator_responsibility_v1','_mad4b_source_media_origin_v1'];
 public static function attachment_ids(int $post_id): array {
  $post = get_post($post_id);
  if (!$post || $post->post_status==='trash' || $post->post_type==='attachment') return [];
  $ids=[];
  $featured = get_post_thumbnail_id($post_id);
  if ($featured) $ids[]=(int)$featured;
  $attached=get_posts(['post_type'=>'attachment','post_status'=>'inherit','post_parent'=>$post_id,'posts_per_page'=>self::MAX_IMAGES,'fields'=>'ids','orderby'=>'ID','order'=>'ASC']);
  foreach((array)$attached as $id)$ids[]=(int)$id;
  $content=(string)$post->post_content;
  // Scan only IDs explicitly used by this post. Never crawl all attachments.
  if (preg_match_all('/\\bwp-image-(\\d+)\\b/', $content, $m)) foreach($m[1] as $id)$ids[]=(int)$id;
  if (preg_match_all('/"(?:id|mediaId)"\\s*:\\s*(\\d+)/', $content, $m)) foreach($m[1] as $id)$ids[]=(int)$id;
  $ids=array_values(array_unique(array_filter($ids, static fn($id)=>$id>0)));
  return array_slice($ids,0,self::MAX_IMAGES);
 }
 private static function external_evidence(int $id): bool {
  if(get_post_type($id)!=='attachment' || !str_starts_with((string)get_post_mime_type($id),'image/'))return false;
  global $wpdb;
  $placeholders=implode(',',array_fill(0,count(self::MEDIA_META),'%s'));
  $q=$wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE post_id=%d AND meta_key IN ($placeholders) LIMIT 1",array_merge([$id],self::MEDIA_META));
  return (int)$wpdb->get_var($q)>0;
 }
 public static function reconcile_post(int $post_id): array {
  if (!class_exists('MAD4B_Media_Rights_Extraction_Intake_V1',false))return ['state'=>'intake_unavailable','scheduled'=>0];
  $ids=self::attachment_ids($post_id);$scheduled=[];$skipped=0;
  foreach($ids as $id){
   if(!self::external_evidence($id)){$skipped++;continue;}
   if(MAD4B_Media_Rights_Extraction_Intake_V1::schedule($id))$scheduled[]=$id;
  }
  return ['state'=>'bounded_conditional_media_check','post_id'=>$post_id,'considered'=>count($ids),'scheduled'=>count($scheduled),'skipped_without_external_provenance'=>$skipped,'attachment_ids'=>$scheduled];
 }
 public static function on_job_transition(array $event): void {
  $stage=(string)($event['stage']??'');
  if(!in_array($stage,self::STAGES,true) || !preg_match('/^[a-f0-9-]{36}$/D',(string)($event['job_id']??'')))return;
  if(!class_exists('MAD4B_SCP_Content_Jobs',false))return;
  $record=MAD4B_SCP_Content_Jobs::get_job(['job_id'=>$event['job_id']]);
  if(is_wp_error($record)||!is_array($record['job']??null))return;
  $job=$record['job'];
  if(($job['stage']??'')!==$stage || (int)($job['job_revision']??0)!==(int)($event['job_revision']??-1))return;
  $post_id=absint($job['target_post_id']??0);
  if(!$post_id || get_post_status($post_id)===false)return;
  $result=self::reconcile_post($post_id);
  do_action('mad4b_media_rights_content_job_reconciled',$result, $event);
 }
}
add_action('mad4b_scp_content_job_transitioned',[MAD4B_Media_Rights_Content_Job_Bridge_V1::class,'on_job_transition'],20,1);
}
