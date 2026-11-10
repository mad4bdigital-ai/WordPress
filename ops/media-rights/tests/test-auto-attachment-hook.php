<?php
if(!defined('WP_CLI')||!WP_CLI)throw new RuntimeException('WPCLI_REQUIRED');
$src=69805;$file=get_attached_file($src);
if(!is_file((string)$file))throw new RuntimeException('QA_LOCAL_FILE_MISSING');
global $wpdb;$c=\Jet_Engine\Modules\Custom_Content_Types\Module::instance()->manager->get_content_types('media_rights');$t=$c->db->table();
$before=(int)$wpdb->get_var("SELECT COUNT(*) FROM $t");
$created=0;$event=[];$intercepted=[];
// Isolate cron dispatch from the fixture transaction. A concurrent WP-Cron
// request otherwise sees the uncommitted test attachment and creates an orphan.
$stub=function($pre,$job,$wp_error)use(&$intercepted){
 if($job->hook===MAD4B_Media_Rights_Extraction_Intake_V1::HOOK){
  $intercepted[]=$job->args;
  return true;
 }
 return $pre;
};
add_filter('pre_schedule_event',$stub,10,3);
$wpdb->query('START TRANSACTION');
try {
 $created=wp_insert_attachment(['post_title'=>'QA ONLY media extraction pipeline event','post_mime_type'=>'image/jpeg','post_status'=>'inherit'],$file,0,true);
 if(is_wp_error($created))throw new RuntimeException('ATTACHMENT_EVENT_INSERT_FAILED_'.$created->get_error_code());
 $event=[$created];
 if(!in_array($event,$intercepted,true))throw new RuntimeException('ATTACHMENT_NOT_QUEUED');
 $one=MAD4B_Media_Rights_Extraction_Intake_V1::process($created);
 $two=MAD4B_Media_Rights_Extraction_Intake_V1::process($created);
 if($one['status']!=='pending_rights_review'||$two['status']!=='already_pending')throw new RuntimeException('INTAKE_NOT_IDEMPOTENT');
 $count=(int)$wpdb->get_var("SELECT COUNT(*) FROM $t");
 if($count!==$before+1)throw new RuntimeException('WRONG_CCT_COUNT');
 $public=MAD4B_Media_Attribution_Registry_V1::render();
 if(substr_count($public,'<article class="wayfa-credits-card">')!==2)throw new RuntimeException('UNVERIFIED_PUBLIC_LEAK');
 WP_CLI::line(wp_json_encode(['state'=>'AUTO_ATTACHMENT_HOOK_PASS','queued'=>true,'first'=>$one['status'],'repeat'=>$two['status'],'public_credit_cards'=>2,'temporary_attachment'=>$created]));
} finally {
 $wpdb->query('ROLLBACK');
 remove_filter('pre_schedule_event',$stub,10);
}
if((int)$wpdb->get_var("SELECT COUNT(*) FROM $t")!==$before)throw new RuntimeException('CCT_NOT_ROLLED_BACK');
if($created && get_post($created)){clean_post_cache($created); if(get_post($created))throw new RuntimeException('ATTACHMENT_NOT_ROLLED_BACK');}
WP_CLI::line(wp_json_encode(['rollback'=>'PASS','permanent_cct_rows'=>$before,'cron_delivery_intercepted_not_persisted'=>count($intercepted)>=1]));
