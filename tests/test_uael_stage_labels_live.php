<?php
/** Candidate-only regression test; never alters stored tour/category data. */
if(!defined('WP_CLI')||!WP_CLI)throw new RuntimeException('REQUIRES_WP_CLI');
$s=file_get_contents('/tmp/mad4b-uael-stage-candidate.php');
$begin=strpos($s,'final class MAD4B_UAEL_Tour_Gallery_Addon_V1 {');
$end=strpos($s,"\nadd_action('elementor/element/uael-image-gallery/section_content_images/after_section_end'");
if($begin===false||$end===false||$end<=$begin)throw new RuntimeException('UNEXPECTED_UAEL_SOURCE');
$body=substr($s,$begin,$end-$begin);
$body=str_replace('MAD4B_UAEL_Tour_Gallery_Addon_V1','MAD4B_UAEL_Stage_Candidate_Test',$body);
eval($body);
function assert_uael($cond,$name){if(!$cond)throw new RuntimeException('FAILED '.$name);echo 'PASS '.$name."\n";}
$cls='MAD4B_UAEL_Stage_Candidate_Test';
$locale=['tour'=>'Tour Photos','days'=>'Days','day'=>'Day'];
$timeline=['days'=>[['day'=>1],['day'=>3]],'days_plural'=>'stops','day_label'=>'stop'];
$sec=['id'=>17,'title'=>'Gallery at Luxor','days'=>[1,2,3]];
assert_uael($cls::category_label($sec,$locale,$timeline,true,true)==='Stops 1, 3 · Gallery at Luxor','stage_uses_only_verified_stop_indexes');
assert_uael($cls::category_label($sec,$locale,$timeline,false,true)==='Days 1, 2, 3 · Gallery at Luxor','multi_day_stays_days');
assert_uael($cls::category_label($sec,$locale,$timeline,true,false)==='Gallery at Luxor','timeline_disabled_has_plain_category');
assert_uael($cls::category_label(['id'=>17,'title'=>'Luxor','days'=>[2]],$locale,$timeline,true,true)==='Luxor','unverified_stop_not_fabricated');
assert_uael($cls::category_label(['id'=>'tour','title'=>'Ignore','days'=>[]],$locale,$timeline,true,true)==='Tour Photos','tour_category_not_fabricated');
assert_uael($cls::category_label(['id'=>17,'title'=>'Luxor','days'=>[1]],$locale,$timeline,true,true)==='Stop 1 · Luxor','singular_stop_correct');
assert_uael($cls::category_label(['id'=>17,'title'=>'Luxor','days'=>[0,-2]],$locale,$timeline,false,true)==='Luxor','invalid_itinerary_indexes_ignored');
echo '__UAEL_NATIVE_STAGE_LABEL_VERDICT__'.wp_json_encode(['cases'=>7,'writes'=>false])."\n";
