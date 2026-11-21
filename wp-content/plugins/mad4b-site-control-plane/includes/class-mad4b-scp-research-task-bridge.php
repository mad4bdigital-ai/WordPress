<?php
if (!defined('ABSPATH')) exit;
/**
 * Read-only lifecycle preflight and explicit draft-to-research binding.
 * Provider calls, approval and publishing remain separate abilities.
 */
final class MAD4B_SCP_Research_Task_Bridge {
 const CONTRACT='mad4b.research-task-bridge.v1';
 const BINDING_META='_mad4b_research_group_uid';
 const TASK_META='_mad4b_research_task_key';
 private static function allowed(){return wp_get_environment_type()==='staging' && current_user_can('manage_options');}
 private static function error($code,$message){return new WP_Error($code,$message);}
 public static function bind($task_key,$site_uuid,$post_id,$locale='en'){
  if(!self::allowed())return self::error('mad4b_research_bind_denied','Staging operator required.');
  $post_id=absint($post_id);$post=get_post($post_id);
  if(!$post||$post->post_type!=='tours-and-activities'||$post->post_status!=='draft'||!current_user_can('edit_post',$post_id))return self::error('mad4b_research_target_not_draft','A writable tour draft is required.');
  $group=MAD4B_SCP_Research_Groups::group($task_key,$site_uuid,$locale);
  if(is_wp_error($group))return $group;
  $prev=(string)get_post_meta($post_id,self::BINDING_META,true);
  if($prev!==''&&!hash_equals($prev,$group['group_uid']))return self::error('mad4b_research_binding_conflict','Draft is already bound to a different research group.');
  update_post_meta($post_id,self::BINDING_META,$group['group_uid']);
  update_post_meta($post_id,self::TASK_META,$task_key);
  if(get_post_meta($post_id,self::BINDING_META,true)!==$group['group_uid'])return self::error('mad4b_research_binding_readback_failed','Binding could not be verified.');
  return array('contract'=>self::CONTRACT,'group_id'=>(int)$group['id'],'group_uid'=>$group['group_uid'],'task_key'=>$task_key,'post_id'=>$post_id,'binding_verified'=>true);
 }
 public static function preflight($post_id){
  if(!self::allowed())return self::error('mad4b_research_read_denied','Staging operator required.');
  global $wpdb;$post_id=absint($post_id);$post=get_post($post_id);
  if(!$post)return self::error('mad4b_research_post_missing','Target post unavailable.');
  $uid=(string)get_post_meta($post_id,self::BINDING_META,true);
  $table=$wpdb->prefix.'mad4b_research_groups';
  $group=$uid!==''?$wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE group_uid=%s",$uid),ARRAY_A):null;
  $records=$group?MAD4B_SCP_Research_Groups::records($group['id']):array();
  if(is_wp_error($records))return $records;
  $providers=array();$locales=array();foreach($records as $r){$providers[$r['provider_id']]=true;$locales[$r['locale']]=true;}
  $fire=class_exists('MAD4B_SCP_Firecrawl_Provider')?MAD4B_SCP_Firecrawl_Provider::probe():array('ready'=>false,'reason'=>'provider_missing');
  $stages=array('source_discovery','market_competitor_dominating','content_intelligence','data_mapping','jetengine_woocommerce_fields','prices_itineraries','wpml','media_scraping_importing','elementor_qa','approval','publication','post_publish_monitoring');
  $result=array();foreach($stages as $i=>$id)$result[]=array('stage_id'=>$id,'ordinal'=>$i+1,'state'=>'not_started','blocking_reason'=>'not_verified');
  $result[0]['state']=count($records)>0?'evidence_present':'blocked';
  $result[0]['blocking_reason']=count($records)>0?'provider_connection_not_proven':'no_source_evidence';
  $result[4]['state']=$post->post_status==='draft'?'draft_exists':'blocked';
  $result[4]['blocking_reason']='field_mapping_unverified';
  $attachments=get_posts(array('post_type'=>'attachment','post_status'=>'inherit','post_parent'=>$post_id,'fields'=>'ids','posts_per_page'=>100));
  $result[7]['state']=count($attachments)>0?'import_evidence_present':'not_started';
  $result[7]['blocking_reason']='media_provenance_and_qa_unverified';
  return array(
   'contract'=>self::CONTRACT,'authorizing'=>false,'mutation_performed'=>false,
   'post_id'=>$post_id,'post_status'=>$post->post_status,
   'group_uid'=>$uid,'group_id'=>$group?(int)$group['id']:0,
   'task_key'=>$group['task_key']??'','site_uuid'=>$group['site_uuid']??'',
   'source_locale'=>$group['source_locale']??'en',
   'research_record_count'=>count($records),'provider_ids'=>array_keys($providers),
   'record_locales'=>array_keys($locales),'attachment_count'=>count($attachments),
   'firecrawl_connection'=>$fire,'stages'=>$result,'publication_ready'=>false,
   'blocking_reasons'=>array_values(array_filter(array(
     !$group?'research_group_unbound':null,
     empty($providers['firecrawl'])?'firecrawl_source_not_recorded':null,
     empty($fire['ready'])?'firecrawl_connection_not_configured_or_unverified':null,
     'commercial_rates_unverified','translation_mapping_unverified','elementor_qa_missing','approval_missing'
   )))
  );
 }
}
