<?php
/** Accommodation services classification: descriptive taxonomy is not a price catalog. */
if(!defined('ABSPATH')||(!defined('MAD4B_COMMERCE_RUNTIME_ENABLED') || !MAD4B_COMMERCE_RUNTIME_ENABLED))return;
function lab_accommodation_services_catalog($unit_id){
 $unit_id=absint($unit_id);
 if(get_post_type($unit_id)!=='units')return new WP_Error('stay_services_unit_invalid');
 $included=[];
 foreach(['amenities','board-type'] as $tax){
  $terms=wp_get_post_terms($unit_id,$tax);
  if(is_wp_error($terms))return $terms;
  foreach($terms as $term)$included[]=['taxonomy'=>$tax,'term_id'=>(int)$term->term_id,'label'=>$term->name,'classification'=>'descriptive_unpriced'];
 }
 $optional=get_post_meta($unit_id,'unit_optional_services',true);
 if($optional===''||$optional===null)$optional=[];
 if(!is_array($optional))return new WP_Error('stay_services_catalog_invalid');
 $verified=[];
 foreach($optional as $row){
  if(!is_array($row)||!preg_match('/^[a-z0-9_-]{2,60}$/',(string)($row['unit_service_code']??'')))return new WP_Error('stay_service_code_invalid');
  if(!in_array($row['unit_charge_basis']??null,['per_stay','per_night','per_person','per_person_night'],true))return new WP_Error('stay_service_basis_invalid');
  if(!is_numeric($row['unit_price']??null)||(float)$row['unit_price']<0||!preg_match('/^[A-Z]{3}$/',(string)($row['unit_currency']??'')))return new WP_Error('stay_service_price_invalid');
  $verified[]=['code'=>$row['unit_service_code'],'amount'=>(float)$row['unit_price'],'currency'=>$row['unit_currency'],'basis'=>$row['unit_charge_basis'],'status'=>'catalog_only_not_added_to_quote'];
 }
 return ['unit_id'=>$unit_id,'descriptive'=>$included,'optional_services'=>$verified,'pricing_activation'=>false,'checkout_ready'=>false];
}
