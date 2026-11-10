<?php
if(!defined('WP_CLI')||!WP_CLI)throw new RuntimeException('WPCLI_ONLY');
if(!class_exists('MAD4B_Media_Rights_Extraction_Intake_V1',false))require_once '/tmp/mad4b-media-rights-extraction-intake.php';
$attachment=69805;
$factory=\Jet_Engine\Modules\Custom_Content_Types\Module::instance()->manager->get_content_types('media_rights');
if(!$factory||!is_file((string)get_attached_file($attachment)))throw new RuntimeException('IMAGE_TEST_FIXTURE_UNAVAILABLE');
$factory->db->set_format_flag(ARRAY_A);$key='discovered_attachment_'.$attachment;
$initial=$factory->db->query(['credit_key'=>$key],2);
if($initial)throw new RuntimeException('TEST_IMAGE_ALREADY_REGISTERED');
global $wpdb;$table=$factory->db->table();$before=(int)$wpdb->get_var("SELECT COUNT(*) FROM {$table}");
$intercepts=[];
$intercept=static function($pre,$event,$wp_error)use(&$intercepts){
 if($event->hook===MAD4B_Media_Rights_Extraction_Intake_V1::HOOK){$intercepts[]=$event->args;return true;}
 return $pre;
};
add_filter('pre_schedule_event',$intercept,10,3);
$wpdb->query('START TRANSACTION');
try{
 $first=MAD4B_Media_Rights_Extraction_Intake_V1::process($attachment);
 if(($first['status']??'')!=='pending_rights_review')throw new RuntimeException('DRAFT_DISCOVERY_DENIED');
 $source='https://example.org/verified-source-image.jpg';
 if(!add_post_meta($attachment,'_mad4b_import_source_url',$source,true))throw new RuntimeException('TEST_SOURCE_META_WRITE_DENIED');
 $second=MAD4B_Media_Rights_Extraction_Intake_V1::process($attachment);
 if(($second['status']??'')!=='pending_source_linked')throw new RuntimeException('SOURCE_LINKING_FAILED_'.$second['status']);
 $third=MAD4B_Media_Rights_Extraction_Intake_V1::process($attachment);
 if(($third['status']??'')!=='already_pending')throw new RuntimeException('SOURCE_LINKING_NOT_IDEMPOTENT');
 $rows=$factory->db->query(['credit_key'=>$key],2);
 if(count($rows)!==1||$rows[0]['source_url']!==$source||$rows[0]['cct_status']!=='draft'||$rows[0]['author']!==''||$rows[0]['license_url']!=='')throw new RuntimeException('UNREVIEWED_RIGHTS_LEAK');
 if((int)$wpdb->get_var("SELECT COUNT(*) FROM {$table}")!==$before+1)throw new RuntimeException('CCT_DUPLICATE');
 if(!in_array([$attachment],$intercepts,true))throw new RuntimeException('SOURCE_META_NOT_QUEUED');
 WP_CLI::line(wp_json_encode(['state'=>'EXTERNAL_SOURCE_BRIDGE_TEST_PASS','first'=>$first['status'],'second'=>$second['status'],'repeat'=>$third['status'],'unverified_rights_still_draft'=>true,'queued_from_source_meta'=>true]));
}finally{
 $wpdb->query('ROLLBACK');
 remove_filter('pre_schedule_event',$intercept,10);
}
wp_cache_delete($attachment,'post_meta');
$source_count=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE post_id=%d AND meta_key=%s AND meta_value=%s",$attachment,'_mad4b_import_source_url','https://example.org/verified-source-image.jpg'));
if((int)$wpdb->get_var("SELECT COUNT(*) FROM {$table}")!==$before||$source_count!==0)throw new RuntimeException('TEST_TRANSACTION_ROLLBACK_FAILED');
WP_CLI::line(wp_json_encode(['rollback'=>'PASS','records_preserved'=>$before]));
