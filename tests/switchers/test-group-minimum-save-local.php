<?php
/** Local-only editor save test; restores all post-meta, nonce, user and request state. */
require '/var/www/html/wp-load.php';
if(wp_get_environment_type()!=='local')exit(21);
$tm='Travel_Booking_Traveller_Management';$id=66133;
$fields=[
 '_travel_booking_group_booking_minimum','_travel_booking_traveller_preset_id',
 '_travel_booking_departure_inventory_mode','_travel_booking_minimum_departure_participants',
 '_travel_booking_departure_max_capacity','_travel_booking_small_party_routing',
];
$before=[];
foreach($fields as $key)$before[$key]=[
 'exists'=>metadata_exists('post',$id,$key),'value'=>get_post_meta($id,$key,true)
];
$oldPost=$_POST;$oldUser=get_current_user_id();
$fail=0;
$test=static function($name,$ok)use(&$fail){echo "$name=".($ok?'PASS':'FAIL').PHP_EOL;if(!$ok)$fail++;};
try {
 $admins=get_users(['role'=>'administrator','number'=>1,'fields'=>'ID']);
 if(!$admins){echo "ADMIN_TEST_USER_UNAVAILABLE\n";exit(22);}
 wp_set_current_user((int)$admins[0]);
 if(!current_user_can('edit_post',$id)){echo "EDITOR_PERMISSION_UNAVAILABLE\n";exit(23);}
 $_POST=[
  '_travel_booking_tm_product_nonce'=>wp_create_nonce('travel_booking_tm_product_'.$id),
  'travel_booking_tm_product_preset'=>get_post_meta($id,$tm::META,true),
  'travel_booking_tm_group_minimum'=>'13',
  'travel_booking_tm_inventory_mode'=>$before['_travel_booking_departure_inventory_mode']['value']?:'untracked',
  'travel_booking_tm_departure_minimum'=>(string)$before['_travel_booking_minimum_departure_participants']['value'],
  'travel_booking_tm_departure_capacity'=>(string)$before['_travel_booking_departure_max_capacity']['value'],
  'travel_booking_tm_small_party_routing'=>(string)$before['_travel_booking_small_party_routing']['value']?:'offer_private',
 ];
 $tm::save_product($id);
 $test('ADMIN_CAN_SET_GROUP_MINIMUM_13',(int)get_post_meta($id,'_travel_booking_group_booking_minimum',true)===13);
 $test('RUNTIME_READS_ADMIN_OVERRIDE_13',$tm::group_booking_minimum($id)===13);
 $_POST['travel_booking_tm_group_minimum']='';
 $tm::save_product($id);
 $test('EDITOR_CAN_CLEAR_OVERRIDE',metadata_exists('post',$id,'_travel_booking_group_booking_minimum')
  &&get_post_meta($id,'_travel_booking_group_booking_minimum',true)==='');
 $test('BLANK_USES_TIER_OR_REPORTS_MISSING',is_int($tm::group_booking_minimum($id))
  ||is_wp_error($tm::group_booking_minimum($id)));
 $_POST['travel_booking_tm_group_minimum']='0';
 $tm::save_product($id);
 $test('INVALID_ZERO_IGNORED',get_post_meta($id,'_travel_booking_group_booking_minimum',true)==='');
} finally {
 $_POST=$oldPost;wp_set_current_user($oldUser);
 foreach($before as $key=>$snapshot){
  if($snapshot['exists'])update_post_meta($id,$key,$snapshot['value']);
  else delete_post_meta($id,$key);
 }
}
$restored=true;
foreach($before as $key=>$snapshot)
 if(metadata_exists('post',$id,$key)!==$snapshot['exists']||
   get_post_meta($id,$key,true)!==$snapshot['value'])$restored=false;
$test('ORIGINAL_TOUR_SETTINGS_RESTORED',$restored);
echo "GROUP_MINIMUM_SAVE_FAILURES=$fail\n";exit($fail?1:0);
