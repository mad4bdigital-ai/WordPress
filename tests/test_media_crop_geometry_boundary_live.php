<?php
if(!defined('WP_CLI')||!WP_CLI)throw new RuntimeException('requires WP CLI');
$path=getenv('MAD4B_PHOTO_CROP_CANDIDATE')?:'/tmp/mad4b-photo-quality-reframe-candidate.php';$text=file_get_contents($path);
$start=strpos($text,'final class MAD4B_Tour_Photo_Quality_V1 {');$end=strpos($text,"\nadd_filter('mad4b/content_readiness/external_issues'");
if($start===false||$end===false)throw new RuntimeException('source contract changed');
$body=str_replace('MAD4B_Tour_Photo_Quality_V1','MAD4B_Tour_Photo_Quality_Geometry_Boundary_Candidate',substr($text,$start,$end-$start));eval($body);$c='MAD4B_Tour_Photo_Quality_Geometry_Boundary_Candidate';
function verify_geometry($ok,$name){if(!$ok)throw new RuntimeException('FAILED:'.$name);echo 'PASS '.$name."\n";}
verify_geometry(!$c::same_original_grid_reframe_geometry(900,1350),'portrait_cannot_qualify_for_wide_grid');
verify_geometry(!$c::same_original_grid_reframe_geometry(500,500),'small_source_not_sufficient');
verify_geometry(!$c::same_original_grid_reframe_geometry(800,1200),'tall_image_rejected');
verify_geometry($c::same_original_grid_reframe_geometry(1100,1100),'square_source_eligible');
verify_geometry($c::same_original_grid_reframe_geometry(1600,1060),'landscape_source_eligible');
verify_geometry(!$c::same_original_grid_reframe_geometry(1000,1021),'just_below_ratio_boundary_rejected');
verify_geometry($c::same_original_grid_reframe_geometry(1000,1020),'boundary_ratio_accepted');
$loss=$c::cover_crop_geometry(900,1350,1.48);
verify_geometry(($loss['estimated_cropped_fraction']??0)>.5,'real_hero_frame_crop_loss_detected');
echo '__CROP_GEOMETRY_DECISION_CONFIRMED__'.wp_json_encode(['tests'=>8,'source'=>'native_quality_engine','live_data_unchanged'=>true])."\n";
