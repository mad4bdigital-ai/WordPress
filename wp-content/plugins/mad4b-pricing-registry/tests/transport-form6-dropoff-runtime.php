<?php
/** Read-only runtime and synthetic normalization; no real submission. */
defined('ABSPATH')||exit(2);
$site=rtrim(home_url('/'),'/');
$c='MAD4B_Transport_Form6_Dropoff_V1';
$checks=[];
$test=static function($name,$value)use(&$checks){$checks[$name]=(bool)$value;};
$test('class_exists',class_exists($c));
$test('form_hook_priority',has_filter('fluentform/filter_insert_data',[$c,'persist'])===55);
global $wpdb;
$raw=$wpdb->get_var("SELECT form_fields FROM {$wpdb->prefix}fluentform_forms WHERE id=6");
$fields=(array)(json_decode($raw,true)['fields']??[]);
$names=array_map(static fn($f)=>$f['attributes']['name']??'', $fields);
$at=array_search('mad4b_pickup_hotel_address',$names,true);
$expected=['mad4b_dropoff_mode','mad4b_dropoff_place_name','mad4b_dropoff_city','mad4b_dropoff_address'];
$test('native_field_count',count($fields)===22);
$test('dropoff_after_existing_pickup',$at!==false&&array_slice($names,$at+1,4)===$expected);
$test('existing_inquiry_preserved',$at!==false&&($names[$at+5]??'')==='description');
$backup=get_option('mad4b_form6_transport_dropoff_backup_20261120_v1',false);
$test('rollback_backup_readback',is_array($backup)&&($backup['site']??'')===$site&&
($backup['after_sha256']??'')===hash('sha256',$raw));
$pickup=['status'=>'HOTEL_SUPPLIED','hotel_name'=>'Test Hotel',
 'hotel_city'=>'Luxor','hotel_address'=>'sample'];
$a=$c::normalize(['mad4b_dropoff_mode'=>'same_as_pickup'],$pickup);
$test('pickup_request_copy_unconfirmed',$a['status']==='POINT_REQUESTED_UNCONFIRMED'&&
 $a['city']==='Luxor'&&$a['checkout_authorized']===false);
$b=$c::normalize(['mad4b_dropoff_mode'=>'same_as_pickup'],[]);
$test('missing_pickup_no_invented_destination',$b['status']==='PICKUP_POINT_STILL_PENDING'&&$b['city']==='');
$d=$c::normalize(['mad4b_dropoff_mode'=>'airport','mad4b_dropoff_place_name'=>'CAI',
 'mad4b_dropoff_city'=>'Cairo'],[]);
$test('traveler_airport_is_not_supplier_verified',$d['status']==='POINT_REQUESTED_UNCONFIRMED'&&
 !$d['route_point_verified']&&!$d['availability_checked']);
$e=$c::normalize(['mad4b_dropoff_mode'=>'to_confirm','mad4b_dropoff_city'=>'Old'],[]);
$test('unconfirmed_clears_stale_data',$e['city']==='');
$test('invalid_mode_not_certified',$c::normalize(['mad4b_dropoff_mode'=>'invalid'],[])['status']==='invalid_mode');
$test('oversized_address_rejected',$c::normalize(['mad4b_dropoff_mode'=>'hotel',
 'mad4b_dropoff_address'=>str_repeat('X',501)],[])['status']==='invalid_length');
$sample=$site==='https://wayfatours.com'?60661:64946;
$test('original_published_tour_eligible',$c::permitted_context($sample,0));
$test('missing_tour_rejected',!$c::permitted_context(0,0));
$test('standalone_transport_off',!service_channel_allowed('transportations','standalone'));
$fail=array_keys(array_filter($checks,static fn($ok)=>!$ok));
echo wp_json_encode(['site'=>$site,'tests'=>count($checks),'passed'=>count($checks)-count($fail),
 'failed'=>$fail,'real_submissions_created'=>0,'checkout_enabled'=>false])."\n";
if($fail)exit(1);
