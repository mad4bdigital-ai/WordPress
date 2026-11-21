<?php
/** Read-only property references audit: disallow attachment IDs as bookable properties. */
defined('ABSPATH') || exit;
if(!function_exists('mad4b_component_property_references_shadow_v1')){
function mad4b_component_property_references_shadow_v1(int $tourRateId) {
 if($tourRateId<1||!function_exists('get_post_type')||get_post_type($tourRateId)!=='tour-rates')
   return new WP_Error('property_relation_invalid_tour_rate');
 $raw=get_post_meta($tourRateId,'related_properties_id',true);
 if($raw===''||$raw===null||$raw===[])return ['tour_rate_id'=>$tourRateId,'valid'=>[],'invalid'=>[],'status'=>'no_property_links','checkout_authority'=>false];
 if(is_string($raw))$raw=maybe_unserialize($raw);
 if(is_string($raw))$raw=explode(',',$raw);
 if(!is_array($raw)||count($raw)>100)return new WP_Error('property_relation_invalid_shape');
 $valid=[];$invalid=[];
 foreach($raw as $item) {
   if(!is_scalar($item)||!ctype_digit(trim((string)$item))||(int)$item<1)
     return new WP_Error('property_relation_invalid_id');
   $id=(int)$item;
   if(get_post_type($id)==='properties'&&get_post_status($id)==='publish')$valid[$id]=$id;
   else $invalid[$id]=['id'=>$id,'post_type'=>get_post_type($id)?:'missing','status'=>get_post_status($id)?:'missing'];
 }
 return ['contract'=>'mad4b.property-relations.shadow.v1','tour_rate_id'=>$tourRateId,
   'valid'=>array_values($valid),'invalid'=>array_values($invalid),
   'status'=>$invalid?'needs_reference_reconciliation':'references_valid',
   'price_source_authority'=>false,'checkout_authority'=>false];
}
}
