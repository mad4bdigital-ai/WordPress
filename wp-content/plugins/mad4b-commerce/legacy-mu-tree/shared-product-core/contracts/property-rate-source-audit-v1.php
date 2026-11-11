<?php
/** Read-only audit of saved Property Rate source data; no publish or price grants. */
defined('ABSPATH') || exit;
if(!function_exists('mad4b_property_rate_source_audit_v1')){
function mad4b_property_rate_source_audit_v1(int $limit=200){
 if($limit<1||$limit>500)return new WP_Error('property_rate_audit_limit_invalid');
 $ids=get_posts(['post_type'=>'property-rates','post_status'=>['draft','pending','private','publish'],
   'posts_per_page'=>$limit+1,'fields'=>'ids','orderby'=>'ID','order'=>'DESC','suppress_filters'=>true]);
 if(count($ids)>$limit)return new WP_Error('property_rate_audit_limit_reached');
 $stats=['total'=>0,'draft'=>0,'published'=>0,'qa_only'=>0,
 'invalid_property'=>0,'unit_not_published'=>0,'missing_explicit_stop_sale'=>0,
 'price_sources_eligible_for_authoring'=>0];
 $rows=[];
 foreach($ids as $id){
  $id=(int)$id;$status=get_post_status($id);
  $property=(int)get_post_meta($id,'prop_related_property_id',true);
  $unit=(int)get_post_meta($id,'prop_related_unit_id',true);
  $stop=(string)get_post_meta($id,'stop_sale',true);
  $qa=(bool)preg_match('/\bQA\s+ONLY\b/i',(string)get_the_title($id));
  $blockers=[];
  if($status!=='publish')$blockers[]='rate_not_published';
  if($qa)$blockers[]='synthetic_test_fixture';
  if(get_post_type($property)!=='properties'||get_post_status($property)!=='publish')
   $blockers[]='property_identity_not_published';
  if(get_post_type($unit)!=='units'||get_post_status($unit)!=='publish')
   $blockers[]='unit_identity_not_published';
  if($stop==='')$blockers[]='explicit_stop_sale_missing';
  elseif(in_array(strtolower($stop),['1','true','yes','on'],true))$blockers[]='stop_sale';
  else if(!in_array(strtolower($stop),['0','false','no','off'],true))$blockers[]='stop_sale_value_invalid';
  $amount=get_post_meta($id,'base_amount',true);
  $currency=(string)get_post_meta($id,'currency',true);
  if(!is_numeric($amount)||(float)$amount<=0||!preg_match('/^[A-Z]{3}$/D',$currency))
   $blockers[]='amount_or_currency_invalid';
  $from=(string)get_post_meta($id,'valid_from',true);
  $to=(string)get_post_meta($id,'valid_to',true);
  if(!$from||!$to||$to<$from)$blockers[]='rate_window_invalid';
  $stats['total']++;
  if($status==='draft')$stats['draft']++;
  if($status==='publish')$stats['published']++;
  if($qa)$stats['qa_only']++;
  if(in_array('property_identity_not_published',$blockers,true))$stats['invalid_property']++;
  if(in_array('unit_identity_not_published',$blockers,true))$stats['unit_not_published']++;
  if(in_array('explicit_stop_sale_missing',$blockers,true))$stats['missing_explicit_stop_sale']++;
  // This is deliberately advisory, even when the source row looks complete.
  $rows[]=['rate_id'=>$id,'title'=>get_the_title($id),'status'=>$status,
   'property_id'=>$property,'unit_id'=>$unit,'source_amount'=>is_numeric($amount)?(float)$amount:null,
   'currency'=>$currency,'price_basis'=>(string)get_post_meta($id,'prop_price_unit',true),
   'valid_from'=>$from,'valid_to'=>$to,'qa_only'=>$qa,
   'blockers'=>$blockers,'quote_authority'=>false,'checkout_authority'=>false];
 }
 return ['contract'=>'mad4b.property-rate-source-audit.v1','counts'=>$stats,'rows'=>$rows,
  'scope'=>'read_only','rate_authority'=>false,'checkout_authority'=>false];
}
}
