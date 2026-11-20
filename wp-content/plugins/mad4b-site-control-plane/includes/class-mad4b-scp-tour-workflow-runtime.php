<?php
if (!defined('ABSPATH')) exit;
/** Read-only twelve-stage orchestrator with persistent task state and checkpoint receipts. */
final class MAD4B_SCP_Tour_Workflow_Runtime {
 const CONTRACT='mad4b.tour-workflow-runtime.v1';
 const STAGES=array('source_discovery','market_competitor_dominating','content_intelligence','data_mapping','jetengine_woocommerce_fields','prices_itineraries','wpml','media_scraping_importing','elementor_qa','approval','publication','post_publish_monitoring');
 private static function gate(){return wp_get_environment_type()==='staging' && current_user_can('manage_options');}
 public static function evaluate($post_id){
  if(!self::gate())return new WP_Error('mad4b_workflow_scope_denied','Staging administrator required.');
  $post=get_post(absint($post_id));
  if(!$post||$post->post_type!=='tours-and-activities')return new WP_Error('mad4b_workflow_target_invalid','Tour target required.');
  $prior=MAD4B_SCP_Research_Task_Bridge::preflight($post_id);
  if(is_wp_error($prior))return $prior;
  $site=class_exists('MAD4B_SCP_Restore_Epoch')?MAD4B_SCP_Restore_Epoch::status(false,true):array();
  $stage_rows=array();
  foreach(self::STAGES as $i=>$name){
   $old=$prior['stages'][$i]??array();
   $stage_rows[$name]=array('index'=>$i+1,'state'=>$old['state']??'not_started','reason'=>$old['blocking_reason']??'not_verified','dependency'=>$i?self::STAGES[$i-1]:null);
  }
  $config=class_exists('MAD4B_SCP_Firecrawl_Provider')?MAD4B_SCP_Firecrawl_Provider::probe():array('ready'=>false,'reason'=>'provider_missing');
  $preflight=array('principal_id'=>get_current_user_id(),'environment'=>wp_get_environment_type(),
   'restore_ready'=>!empty($site['ready']),'restore_epoch'=>$site['epoch']??null,
   'provider_connection'=>$config,'research_group_uid'=>$prior['group_uid'],'research_records'=>$prior['research_record_count'],
   'target_post_id'=>(int)$post_id,'target_status'=>$post->post_status,
   'write_authorized'=>false,'publication_authorized'=>false,
   'journal_contract'=>'mad4b.tour-stage-checkpoint.v1','recovery_policy'=>'manual_review_on_uncertain_write');
  $blockers=$prior['blocking_reasons'];
  if(empty($site['ready']))$blockers[]='restore_epoch_not_ready';
  if($post->post_status!=='draft')$blockers[]='not_draft';
  return array('contract'=>self::CONTRACT,'run_key'=>$prior['task_key'],'stage_count'=>count($stage_rows),
   'preflight'=>$preflight,'stages'=>$stage_rows,'blockers'=>array_values(array_unique($blockers)),
   'next_action'=>empty($config['ready'])?'configure_firecrawl':'verify_provider_connection_and_discover_sources',
   'dry_run'=>true,'mutation_performed'=>false);
 }
 public static function checkpoint($post_id,$stage,$receipt,$dependency_sha256){
  if(!self::gate())return new WP_Error('mad4b_workflow_scope_denied','Staging administrator required.');
  if(!in_array($stage,self::STAGES,true)||!is_array($receipt)||strlen(wp_json_encode($receipt))>32768
    ||!preg_match('/^[a-f0-9]{64}$/',(string)$dependency_sha256))
    return new WP_Error('mad4b_workflow_checkpoint_invalid','Invalid stage receipt.');
  $p=self::evaluate($post_id);if(is_wp_error($p))return $p;
  if(empty($p['preflight']['restore_ready']))return new WP_Error('mad4b_workflow_restore_blocked','Restore Epoch is not ready.');
  $key='_mad4b_workflow_stage_'.$stage;
  $old=get_post_meta($post_id,$key,true);
  $hash=hash('sha256',wp_json_encode($receipt));
  if(is_array($old)&&($old['dependency_sha256']??'')===$dependency_sha256&&($old['receipt_sha256']??'')===$hash)
    return array('reused'=>true,'checkpoint'=>$old);
  $row=array('contract'=>'mad4b.tour-stage-checkpoint.v1','stage'=>$stage,'dependency_sha256'=>$dependency_sha256,
    'receipt_sha256'=>$hash,'recorded_by'=>get_current_user_id(),'recorded_at'=>gmdate('c'),
    'receipt'=>$receipt,'publication_authorized'=>false);
  if(!update_post_meta($post_id,$key,$row))return new WP_Error('mad4b_workflow_checkpoint_write_failed','Checkpoint write failed.');
  $back=get_post_meta($post_id,$key,true);
  if(!is_array($back)||($back['receipt_sha256']??'')!==$hash)return new WP_Error('mad4b_workflow_checkpoint_readback_failed','Checkpoint readback failed.');
  return array('reused'=>false,'checkpoint'=>$back);
 }
}
