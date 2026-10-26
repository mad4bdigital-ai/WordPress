<?php
/** Reversible Local Lab-only integration. No order/checkout/price mutations. */
require '/var/www/html/wp-load.php';
if(wp_get_environment_type()!=='local')exit(10);
$t='Travel_Booking_Traveller_Management';$option=$t::OPTION;
$original=get_option($option,'__unset__');
$failures=0;
$test=static function($name,$ok)use(&$failures){echo "$name=".($ok?'PASS':'FAIL').PHP_EOL;if(!$ok)$failures++;};
try {
 delete_option($option);
 $initial=$t::state();
 $test('BUILTIN_FIVE_PRESETS',count($t::builtins())===5);
 $test('AUTOMATIC_DEFAULT',($initial['default']??'')==='automatic');
 $invalid=$t::mutate('save','tour',['label'=>'Tour','source'=>'unsafe','max'=>1000],0);
 $test('UNTRUSTED_PRESET_FIELDS_REJECTED',is_wp_error($invalid));
 $test('NO_REVISION_ON_INVALID_INPUT',(int)$t::state()['revision']===0);
 $clone=$t::mutate('clone','tour',['source_preset'=>'tour','new_id'=>'family_tour','label'=>'Family Tour'],0);
 $test('CREATE_NEW_CUSTOM_PRESET',!is_wp_error($clone)&&isset($t::presets()['family_tour']));
 $duplicate=$t::mutate('clone','tour',['source_preset'=>'tour','new_id'=>'family_tour'],1);
 $test('DUPLICATE_PRESET_DENIED',is_wp_error($duplicate));
 $edit=$t::mutate('save','family_tour',['label'=>'Family Private Tour','source'=>'verified','inherit'=>false,'nationality'=>'required','max'=>50],1);
 $test('EDIT_CUSTOM_PRESET',!is_wp_error($edit)&&$t::presets()['family_tour']['inherit']===false);
 $test('NO_ARTIFICIAL_20_CHECKOUT_CAP',$t::certified_limit(66139)===999);
 $stale=$t::mutate('global','family_tour',[],1);
 $test('OPTIMISTIC_REVISION_DENIES_STALE_WRITE',is_wp_error($stale));
 $global=$t::mutate('global','family_tour',[],2);
 $test('ASSIGN_GLOBAL_PRESET',!is_wp_error($global)&&$t::preset_for(66139)['id']==='family_tour');
 $reset=$t::mutate('reset','family_tour',[],3);
 $test('CUSTOM_RESET_TO_ORIGIN',!is_wp_error($reset)&&$t::presets()['family_tour']['inherit']===true);
 $override=$t::mutate('save','tour',['label'=>'Custom Tour','source'=>'verified','inherit'=>false,'nationality'=>'required','max'=>20],4);
 $test('EDIT_BUILTIN_OPTIONALLY',!is_wp_error($override)&&$t::presets()['tour']['label']==='Custom Tour');
 $restore=$t::mutate('reset','tour',[],5);
 $test('RESTORE_ORIGINAL_BUILTIN',!is_wp_error($restore)&&$t::presets()['tour']['label']==='Tours & Activities');
 $groupPreset=$t::mutate('save','tour',['label'=>'Optional Group Tour','source'=>'verified',
  'inherit'=>true,'nationality'=>'service','max'=>50,'group_capacity'=>'booking_engine_only'],6);
 $test('GROUP_CAPACITY_OPTIONAL_PRESET_SAVED',!is_wp_error($groupPreset)
   &&$t::presets()['tour']['group_capacity']==='booking_engine_only');
 $suggestion=$t::mutate('save','tour',['label'=>'Smart Passport Suggestions','source'=>'verified',
  'inherit'=>true,'nationality'=>'service','max'=>20,'suggestion'=>'all',
  'group_capacity'=>'taxonomy_when_group'],7);
 $test('SUGGEST_ALL_PRESET_SAVED',!is_wp_error($suggestion)
   &&$t::presets()['tour']['suggestion']==='all');
 $test('DEFAULT_PRESET_FIRST_ONLY',$t::builtins()['automatic']['suggestion']==='first');
 $groupReset=$t::mutate('reset','tour',[],8);
 $test('GROUP_CAPACITY_PRESET_RESET_TO_TAXONOMY',!is_wp_error($groupReset)
   &&$t::presets()['tour']['group_capacity']==='taxonomy_when_group');
 $quote=shared_product_booking_configuration_quote(66139,'general','double',['quantities'=>['persons'=>3],'options'=>[]]);
 $test('LIVE_SERVER_QUOTE',!is_wp_error($quote));
 $base=['product_id'=>66139,'quantity'=>1,'data'=>wc_get_product(66139),'shared_booking_configuration'=>$quote];
 $verified=$t::resolve($base);
 $test('SERVER_VERIFIED_COUNT_THREE',($verified['status']??'')==='verified'&&($verified['count']??0)===3);
 $ordinary=['product_id'=>66133,'quantity'=>5,'data'=>wc_get_product(66133)];
 $test('DRAFT_TOURS_NO_QUANTITY_INFERENCE',($t::resolve($ordinary)['count']??1)===0);
 $no=$base;unset($no['shared_booking_configuration']);
 $test('MISSING_QUOTE_FAIL_CLOSED',$t::resolve($no)['status']==='unverified');
 $shadow=$ordinary;$shadow['abk_quote']=['adults'=>3,'children'=>1,'checkout_ready'=>false];
 $test('SHADOW_ACCOM_COUNT_NOT_AUTHORIZED',($t::resolve($shadow)['count']??1)===0);
 $fake=$ordinary;$fake['shared_booking_v2']=['quantities'=>['persons'=>4],'status'=>'preview'];
 $test('V2_PREVIEW_COUNT_NOT_AUTHORIZED',($t::resolve($fake)['count']??1)===0);
 $allowedExtra=['product_id'=>66134,'quantity'=>1,'data'=>wc_get_product(66134)];
 $cart=['tour-main'=>$base,'extra-activity'=>$allowedExtra];
 $registry=$t::registry($cart);
 $test('ONE_ROOT_TRAVELLER_REGISTRY',count($registry['bookings'])===1&&count($registry['bookings']['tour-main']['people'])===3);
 $test('NO_AUTO_LINK_WITHOUT_AUTHORITY',count($registry['linked_services'])===0);
 $invalidLink=['authority'=>'unverified','contract'=>$t::CONTRACT,'service_key'=>'extra-activity','booking_key'=>'tour-main','traveller_indices'=>[0,2]];
 $hook=static function()use($invalidLink){return [$invalidLink];};
 add_filter('travel_booking_traveller_management/verified_service_links',$hook);
 $test('UNTRUSTED_SERVICE_RELATION_DENIED',count($t::registry($cart)['linked_services'])===0);
 remove_filter('travel_booking_traveller_management/verified_service_links',$hook);
 $valid=$invalidLink;$valid['authority']='server_verified';
 $hook=static function()use($valid){return [$valid];};
 add_filter('travel_booking_traveller_management/verified_service_links',$hook);
 $linked=$t::registry($cart)['linked_services']['extra-activity']??[];
 $test('LINKED_EXTRA_SUBSET_TWO_PEOPLE',($linked['count']??0)===2&&count(array_unique($linked['traveller_ids']??[]))===2);
 $test('NO_DUPLICATE_PERSON_RECORDS',count($t::registry($cart)['bookings']['tour-main']['people'])===3);
 remove_filter('travel_booking_traveller_management/verified_service_links',$hook);
 $unrelatedCart=$cart;$unrelatedCart['extra-activity']=$ordinary;
 $hook=static function()use($valid){return [$valid];};
 add_filter('travel_booking_traveller_management/verified_service_links',$hook);
 $test('UNRELATED_TOUR_IS_NOT_A_VERIFIED_EXTRA',count($t::registry($unrelatedCart)['linked_services'])===0);
 remove_filter('travel_booking_traveller_management/verified_service_links',$hook);
 $bad=$valid;$bad['traveller_indices']=[0,0];
 $hook=static function()use($bad){return [$bad];};
 add_filter('travel_booking_traveller_management/verified_service_links',$hook);
 $test('REPEATED_TRAVELLER_ASSIGNMENTS_REJECTED',count($t::registry($cart)['linked_services'])===0);
 remove_filter('travel_booking_traveller_management/verified_service_links',$hook);
 $future=static function($value,$line,$type)use($t){
  return ['contract'=>$t::COUNT_CONTRACT,'authority'=>'server_verified','product_id'=>(int)$line['product_id'],
   'cart_quantity'=>(int)$line['quantity'],'source'=>'booking_v2_certified','checkout_ready'=>true,
   'tour_id'=>(int)$line['product_id'],'booking_mode'=>'private','count'=>4];
 };
 add_filter('travel_booking_traveller_management/verified_count',$future,10,3);
 $futureResult=$t::resolve($ordinary);
 $test('FUTURE_CERTIFIED_ADAPTER_SHAPE',($futureResult['status']??'')==='verified'&&($futureResult['count']??0)===4);
 remove_filter('travel_booking_traveller_management/verified_count',$future,10);
 $wrong=static function($value,$line,$type)use($t){
  return ['contract'=>$t::COUNT_CONTRACT,'authority'=>'server_verified','product_id'=>(int)$line['product_id'],
   'cart_quantity'=>(int)$line['quantity'],'source'=>'transport_checkout_certified','checkout_ready'=>true,'count'=>3];
 };
 add_filter('travel_booking_traveller_management/verified_count',$wrong,10,3);
 $test('CROSS_PRODUCT_AUTHORITY_CONFUSION_BLOCKED',$t::resolve($ordinary)['status']==='unverified');
 remove_filter('travel_booking_traveller_management/verified_count',$wrong,10);
 $private=$t::assess_capacity(66133,'private',25);
 $test('PRIVATE_25_NOT_RESTRICTED_BY_GROUP_TIERS',!is_wp_error($private)&&$private['tier_id']===null
   &&$private['booking_v2_persons_supported']&&$private['checkout_capacity_certified']===false);
 $group=$t::assess_capacity(66133,'group',25);
 $test('GROUP_25_MISSING_TAXONOMY_REJECTED',is_wp_error($group)&&$group->get_error_code()==='group_tier_not_configured');
 $optout=$t::assess_capacity(66133,'group',25,null,'booking_engine_only');
 $test('GROUP_TIER_POLICY_OPTIONAL',!is_wp_error($optout)&&$optout['tier_id']===null);
 $tooLarge=$t::assess_capacity(66133,'private',51);
 $test('MORE_THAN_BOOKING_V2_MAX_NOT_MISREPRESENTED',!is_wp_error($tooLarge)&&$tooLarge['booking_v2_persons_supported']===false);
 $test('NO_MARKET_CURRENCY_MUTATION',get_woocommerce_currency()==='USD');
} finally {
 if($original==='__unset__')delete_option($option);
 else update_option($option,$original,false);
}
$test('OPTIONS_RESTORED_AFTER_TEST',get_option($option,'__unset__')===$original);
echo "UNIFIED_TRAVELLER_FAILURES=$failures\n";
exit($failures?1:0);
