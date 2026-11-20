<?php
/** Wayfatours Booking v2 read-only fail-closed acceptance. No order or rate writes. */
if(!defined('ABSPATH')||!defined('WP_CLI'))return;
if(home_url()!=='https://wayfatours.com')WP_CLI::error('site_mismatch');
if(!function_exists('shared_product_booking_v2_validate')||
 !function_exists('shared_product_booking_v2_preview'))WP_CLI::error('booking_runtime_missing');
$ids=[57562,60437,60439,60441,60443,60623,60627,60629];
global $wpdb;
$transactionSnapshot=static function()use($wpdb):array {
  $hpos=class_exists('Automattic\WooCommerce\Utilities\OrderUtil') &&
     \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
  if ($hpos) {
    $table=$wpdb->prefix.'wc_orders';
    $rows=$wpdb->get_results("SELECT id,type,status,creation_via,customer_id,date_created_gmt,date_updated_gmt FROM {$table} ORDER BY id",ARRAY_A);
  } else {
    $rows=$wpdb->get_results("SELECT ID,post_type,post_status,post_modified_gmt,post_date_gmt FROM {$wpdb->posts} WHERE post_type IN ('shop_order','shop_order_refund') ORDER BY ID",ARRAY_A);
  }
  $fluentTable=$wpdb->prefix.'fluentform_submissions';
  $fluent=$wpdb->get_results("SELECT id,form_id,status,created_at,updated_at FROM {$fluentTable} ORDER BY id",ARRAY_A);
  $rateStates=$wpdb->get_results("SELECT ID,post_type,post_status,post_modified_gmt FROM {$wpdb->posts} WHERE post_type IN ('tour-rates','property-rates','transport-rates') ORDER BY post_type,ID",ARRAY_A);
  return ['hpos'=>$hpos,'orders_count'=>count($rows),
    'orders_sha256'=>hash('sha256',wp_json_encode($rows)),
    'fluent_count'=>count($fluent),
    'fluent_sha256'=>hash('sha256',wp_json_encode($fluent)),
    'rates_sha256'=>hash('sha256',wp_json_encode($rateStates))];
};
$before=$transactionSnapshot();
$rateCounts=[];foreach(['tour-rates','property-rates','transport-rates'] as $t)
 $rateCounts[$t]=(int)(wp_count_posts($t)->publish??0);
$cases=[];$summary=['total'=>0,'input_valid'=>0,'blocked_in_validator'=>0,
 'preview_success_indicative_only'=>0,'preview_blocked'=>0,
 'instant_checkout_ready'=>0,'child_infant_pricing_certified'=>0];
$syntheticAge=[
 'child'=>['version'=>'age-request.v1','children'=>1,'infants'=>0,'stay_children'=>1,
  'child_ages'=>[8],'infant_ages'=>[],'source'=>'synthetic_test','pricing_authorized'=>false],
 'infant'=>['version'=>'age-request.v1','children'=>0,'infants'=>1,'stay_children'=>1,
  'child_ages'=>[1],'infant_ages'=>[1],'source'=>'synthetic_test','pricing_authorized'=>false],
];
foreach($ids as $tour){
 if(get_post_type($tour)!=='tours-and-activities'||get_post_status($tour)!=='publish')
 WP_CLI::error('source_tour_unavailable_'.$tour);
 foreach(['adult','child','infant'] as $audience){
  foreach(['quote','request','instant'] as $intent){
   $payload=[
    'schema_version'=>2,'tour_id'=>$tour,'source'=>'direct',
    'rate_id'=>null,'travel_date'=>'2027-01-15','audience'=>'general',
    'occupancy'=>'double',
    'quantities'=>['persons'=>$audience==='adult'?2:3],
    'extras'=>[],'selections'=>[],'booking_intent'=>$intent
   ];
   if($audience!=='adult')$payload['age_context']=$syntheticAge[$audience];
   $validated=shared_product_booking_v2_validate($payload);
   $vErr=is_wp_error($validated)?$validated->get_error_code():null;
   $status=$vErr?'validation_blocked':'input_valid';
   $previewErr=null;$previewStatus=null;
   if(!$vErr){
    $summary['input_valid']++;
    if($audience!=='adult' &&
       ($validated['age_policy_receipt']['age_pricing_status']??'')==='not_certified'){
      // No age pricing receipt may be treated as commercial authority.
      $previewStatus='age_policy_not_certified';
    }
    $preview=shared_product_booking_v2_preview($payload);
    if(is_wp_error($preview)){
     $previewErr=$preview->get_error_code();
     $summary['preview_blocked']++;
    }else{
     if(!is_array($preview)||($preview['checkout_ready']??true)!==false)
       WP_CLI::error('unapproved_checkout_open_'.$tour.'_'.$audience.'_'.$intent);
     $summary['preview_success_indicative_only']++;
    }
   }else{$summary['blocked_in_validator']++;}
   if($audience!=='adult' && !$vErr && (($validated['age_policy_receipt']['pricing_authorized']??true)!==false))
    WP_CLI::error('child_age_price_authority_forged');
   $summary['total']++;
   $cases[]=['tour'=>$tour,'passengers'=>$audience,'intent'=>$intent,
    'validation'=>$status,'validation_code'=>$vErr,
    'preview_code'=>$previewErr,'age_receipt_status'=>$previewStatus,
    'checkout_ready'=>false,'order_created'=>false];
  }
 }
}
$after=$transactionSnapshot();
if($before!==$after)WP_CLI::error('orders_fluent_or_rates_changed_during_test');
foreach($rateCounts as $t=>$n)if((int)(wp_count_posts($t)->publish??0)!==$n)
 WP_CLI::error('published_rate_state_changed_'.$t);
$out=[
 'schema'=>'mad4b.package8.booking-v2-failclosed-test.v1','site'=>home_url(),
 'input_type'=>'synthetic_noncustomer_age_only','read_only'=>true,
 'currency_source'=>'existing_woocommerce_do_not_mutate',
 'signed_supplier_fx_receipts_verified'=>false,
 'supplier_cost_qualified'=>false,'company_policy_active'=>false,
 'financial_execution_attempted'=>false,
 'saved_order_count_unchanged'=>true,'fluent_submissions_unchanged'=>true,
 'woocommerce_hpos_enabled'=>$before['hpos'],'before_after_transaction_snapshot'=>$before,
 'all_order_statuses_unchanged'=>true,'all_rate_post_states_unchanged'=>true,
 'published_rates_unchanged'=>true,
 'summary'=>$summary,'cases'=>$cases];
WP_CLI::line(wp_json_encode($out,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));
