<?php
/** Run with `wp eval-file` inside an authorized, read-only WordPress context. */
if (!defined('WP_CLI') || !WP_CLI || !class_exists('MAD4B_Tour_Photo_Quality_V1'))
    throw new RuntimeException('REQUIRES_WP_CLI_LIVE_ORIGINAL_ENGINE');
$path = getenv('MAD4B_GEOMETRY_CANDIDATE_SOURCE') ?: '/tmp/mad4b-photo-geometry-candidate.php';
$source = file_get_contents($path);
$start = strpos($source,'final class MAD4B_Tour_Photo_Quality_V1 {');
$end = strpos($source,"\nadd_filter('mad4b/content_readiness/external_issues'");
if ($start===false || $end===false || $end<=$start) throw new RuntimeException('CANDIDATE_CLASS_SOURCE_NOT_FOUND');
$body = substr($source,$start,$end-$start);
$body = str_replace('MAD4B_Tour_Photo_Quality_V1','MAD4B_Tour_Photo_Geometry_Candidate',$body);
eval($body);
function proof($truth,$label){if(!$truth)throw new RuntimeException('FAIL '.$label);echo 'PASS '.$label."\n";}
$c='MAD4B_Tour_Photo_Geometry_Candidate';
$original=$c::cover_crop_geometry(900,1350,1.0);
proof($original['status']==='ok'&&abs($original['visible_source_fraction']-.6667)<.0002,'lower-bound grid retains no more than 67% of portrait pixels');
$display=$c::cover_crop_geometry(900,1350,1.48);
proof(abs($display['visible_source_fraction']-.4505)<.001,'desktop 1.48 frame retains about 45%');
proof($display['cropped_axis']==='vertical'&&abs($display['estimated_cropped_fraction']-.5495)<.001,'cover must clip about 55% vertically');
$landscape=$c::cover_crop_geometry(1500,1000,1.5);
proof($landscape['visible_source_fraction']===1.0&&$landscape['estimated_cropped_fraction']===0.0,'matching landscape source has no cover loss');
proof($c::cover_crop_geometry(0,1350,1.5)['status']==='invalid_geometry','invalid image dimensions fail closed');
proof($c::cover_crop_geometry(900,1350,0.0)['status']==='invalid_geometry','invalid frame ratio fails closed');
$old=MAD4B_Tour_Photo_Quality_V1::audit(60439);
$oldMatches=array_values(array_filter($old['issues']??[],static fn($r)=>($r['code']??'')==='media_hero_crop_geometry_review_pending'));
proof(!method_exists('MAD4B_Tour_Photo_Quality_V1','cover_crop_geometry') || count($oldMatches)>=1,'the original production engine may already contain the upgrade');
$live=$c::audit(60439);
$matches=array_values(array_filter($live['issues']??[],static fn($r)=>($r['code']??'')==='media_hero_crop_geometry_review_pending' && ($r['field']??'')==='grid_image_1_middle_upper'));
proof(count($matches)===1,'new native quality flags exactly the offending frame');
$warning=$matches[0];
proof(($warning['current_focal_meta_exists']??false)===true,'a valid focal point does not suppress geometry risk');
proof(($warning['frame_ratio_basis']??'')==='landscape_grid_lower_bound_1_to_1','unknown frame ratio is explicitly conservative');
proof(($warning['minimum_estimated_crop_fraction']??0)>.33,'actual original image crop loss has a lower bound');
proof(($warning['photographic_source_or_rights_not_auto_verified']??false)===true,'geometry never creates a source authenticity claim');
$plan=$c::selection_loop(60439);
proof(($plan['withheld_severe_grid_crop_candidates']??0)>=1,'selection loop rejects severe portrait in landscape grid');
proof(($plan['ready_to_auto_apply']??null)===false,'candidate plan does not authorize media publication');
echo '__GEOMETRY_LIVE_CANDIDATE_VERDICT__'.wp_json_encode(['tour'=>60439,'target_field'=>$warning['field'],'image'=>[$warning['width'],$warning['height']],'minimum_crop_fraction'=>$warning['minimum_estimated_crop_fraction'],'realistic_1_48_ratio_crop_fraction'=>$display['estimated_cropped_fraction'],'held_severe_candidates'=>$plan['withheld_severe_grid_crop_candidates'],'site_modified'=>false])."\n";
