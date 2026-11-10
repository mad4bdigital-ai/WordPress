<?php
if(!defined('WP_CLI')||!WP_CLI)throw new RuntimeException('WPCLI_REQUIRED');
if(!class_exists('MAD4B_Media_Rights_Content_Job_Bridge_V1',false))require_once '/tmp/mad4b-media-rights-content-job-bridge.php';
$source=69551;
if(get_post_type($source)!=='attachment'||!is_file((string)get_attached_file($source))||!metadata_exists('post',$source,'_mad4b_source_media_origin_v1'))throw new RuntimeException('EXTERNAL_SOURCE_FIXTURE_CHANGED');
$wpdb=$GLOBALS['wpdb'];$rows_before=(int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}jet_cct_media_rights");
$schedules=[];$intercept=static function($pre,$event,$wp_error)use(&$schedules){if($event->hook===MAD4B_Media_Rights_Extraction_Intake_V1::HOOK){$schedules[]=$event->args;return true;}return $pre;};
add_filter('pre_schedule_event',$intercept,10,3);
$wpdb->query('START TRANSACTION');$post_id=0;
try {
 $now=gmdate('Y-m-d H:i:s');
 $ok=$wpdb->insert($wpdb->posts,['post_author'=>1,'post_date'=>$now,'post_date_gmt'=>$now,'post_modified'=>$now,'post_modified_gmt'=>$now,'post_title'=>'QA Content Job Media Rights Conditional Bridge','post_name'=>'qa-media-conditional-rights-bridge','post_status'=>'draft','post_type'=>'post','post_content'=>'<figure><img class="wp-image-69551" src="/wp-content/uploads/fixture.jpg"></figure>']);
 if(!$ok)throw new RuntimeException('QA_TEMP_POST_INSERT_FAILED');$post_id=(int)$wpdb->insert_id;
 $result=MAD4B_Media_Rights_Content_Job_Bridge_V1::reconcile_post($post_id);
 if(($result['scheduled']??-1)!==1||($result['attachment_ids']??[])!==[$source]||!in_array([$source],$schedules,true))throw new RuntimeException('EXTERNAL_CONTENT_IMAGE_NOT_SCHEDULED');
 $wpdb->update($wpdb->posts,['post_content'=>'Only original text, no image'],['ID'=>$post_id]);clean_post_cache($post_id);
 $none=MAD4B_Media_Rights_Content_Job_Bridge_V1::reconcile_post($post_id);
 if(($none['scheduled']??-1)!==0||($none['considered']??-1)!==0)throw new RuntimeException('TEXT_ONLY_JOB_SCHEDULED_UNNECESSARILY');
 $same=MAD4B_Media_Rights_Content_Job_Bridge_V1::reconcile_post($post_id);
 if(($same['scheduled']??-1)!==0)throw new RuntimeException('TEXT_ONLY_JOB_REPEAT_NOT_NOOP');
 WP_CLI::line(wp_json_encode(['state'=>'CONTENT_JOB_CONDITIONAL_MEDIA_TEST_PASS','image_with_provenance'=>$result,'text_only'=>$none,'no_media'=>'SKIPPED','cron_intent_intercepted'=>true]));
} finally {
 $wpdb->query('ROLLBACK');remove_filter('pre_schedule_event',$intercept,10);
 if($post_id)clean_post_cache($post_id);
}
if($post_id && $wpdb->get_var($wpdb->prepare("SELECT ID FROM {$wpdb->posts} WHERE ID=%d",$post_id)))throw new RuntimeException('TEMP_POST_NOT_ROLLED_BACK');
if((int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}jet_cct_media_rights")!==$rows_before)throw new RuntimeException('CCT_CHANGED');
WP_CLI::line(wp_json_encode(['rollback'=>'PASS','records_preserved'=>$rows_before]));
