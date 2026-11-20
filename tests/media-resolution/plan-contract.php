<?php
if(!defined('ABSPATH'))exit(1);
if(!class_exists('MAD4B_Tour_Media_Resolution_Engine_V1',false))
 require_once dirname(__DIR__,2).'/wp-content/mu-plugins/mad4b-tour-media-resolution-engine-v1.php';
$engine=MAD4B_Tour_Media_Resolution_Engine_V1::class;
$bindings=$engine::hero_bindings();
foreach(['image_vertical','grid_image_1_middle_upper','grid_image_2','grid_image_3_middle_bottom','grid_image_4_'] as $slot) {
 if(empty($bindings[$slot]['fit_bound'])||empty($bindings[$slot]['position_bound']))
  throw new RuntimeException('MISSING_NATIVE_BINDING:'.$slot);
}
foreach([55239,56695] as $id)if(get_post_status($id)==='publish'){
 $p=$engine::plan($id,'grid_image_3_middle_bottom');
 $validChoice=($p['action']==='set_native_contain'&&$p['auto_eligible'])
     ||($p['action']==='none'&&in_array('native_contain_verified_for_original',$p['reasons'],true));
 if(!$validChoice||(int)$p['current']['image_id']!==55245)
  throw new RuntimeException('UNEXPECTED_CONTAIN_DECISION:'.wp_json_encode($p));
}
if($engine::verified_contain(55239,'grid_image_3_middle_bottom',60018))
  throw new RuntimeException('CONTAIN_MUST_NOT_CROSS_ORIGINAL_SHA');
$p=$engine::plan(55239,'grid_image_2');
if($p['action']!=='none')throw new RuntimeException('LANDSCAPE_SOURCE_UNNECESSARY_CHANGE');
$p=$engine::plan(55239,'unsupported_slot');
if($p['auto_eligible'])throw new RuntimeException('UNSUPPORTED_SLOT_ACCEPTED');
echo "PASS native bindings 5, portrait resolution, landscape no-op and unsupported slot rejection\n";
