<?php
if (!defined('WP_CLI')||!WP_CLI)throw new RuntimeException('WPCLI_REQUIRED');
if (!class_exists('MAD4B_Media_Rights_Extraction_Intake_V1',false))require_once '/tmp/mad4b-media-rights-extraction-intake.php';
$factory=\Jet_Engine\Modules\Custom_Content_Types\Module::instance()->manager->get_content_types('media_rights');
if(!$factory)throw new RuntimeException('CCT_NOT_FOUND');
global $wpdb;
$table=$factory->db->table();$original=(int)$wpdb->get_var("SELECT COUNT(*) FROM {$table}");
// Image #69805 has local bytes. Exercise actual JetEngine draft insertion inside
// a transaction that is ALWAYS rolled back; never publish extracted images.
$test_id=69805;$f=get_attached_file($test_id);
if(get_post_type($test_id)!=='attachment'||!is_file((string)$f))throw new RuntimeException('TEST_IMAGE_NOT_LOCAL');
$wpdb->query('START TRANSACTION');
try {
 $one=MAD4B_Media_Rights_Extraction_Intake_V1::process($test_id);
 $two=MAD4B_Media_Rights_Extraction_Intake_V1::process($test_id);
 $after=(int)$wpdb->get_var("SELECT COUNT(*) FROM {$table}");
 if(!in_array($one['status'],['pending_rights_review','already_pending','already_licensed'],true))throw new RuntimeException('UNEXPECTED_DISCOVERY_'.$one['status']);
 if($one['status']==='pending_rights_review' && ($two['status']!=='already_pending'||$after!==$original+1))throw new RuntimeException('DUPLICATE_NOT_PREVENTED');
 $page=MAD4B_Media_Attribution_Registry_V1::render();
 if(substr_count($page,'<article class="wayfa-credits-card">')!==2)throw new RuntimeException('PENDING_LEAKED_TO_PUBLIC');
 WP_CLI::line(wp_json_encode(['status'=>'PENDING_INTAKE_TRANSACTION_PASS','first'=>$one['status'],'second'=>$two['status'],'original_records'=>$original,'during_records'=>$after,'public_cards'=>2]));
} finally {$wpdb->query('ROLLBACK');}
$final=(int)$wpdb->get_var("SELECT COUNT(*) FROM {$table}");
if($final!==$original)throw new RuntimeException('ROLLBACK_NOT_EXACT');
WP_CLI::line(wp_json_encode(['rollback'=>'PASS','permanent_records'=>$final]));
