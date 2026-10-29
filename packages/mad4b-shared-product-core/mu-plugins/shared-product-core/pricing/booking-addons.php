<?php
/** Shared Product Core: composable optional extras for a parent tour/cruise booking. Lab only. */
if (!defined('ABSPATH') || !defined('SHARED_PRODUCT_LAB_ONLY') || !SHARED_PRODUCT_LAB_ONLY) return;
function shared_product_addon_catalog($parent_rate_id) {
    $context=shared_product_tour_context($parent_rate_id);
    if(is_wp_error($context))return $context;
    $raw=get_post_meta($context['tour_id'],'shared_product_addon_catalog',true);
    if(!is_string($raw)||$raw==='')return [];
    $config=json_decode($raw,true);
    if(!is_array($config)||($config['schema']??null)!==1||!is_array($config['items']??null)||count($config['items'])>100)return new WP_Error('invalid_addon_catalog');
    return $config['items'];
}
function shared_product_addon_quote($parent_rate_id,$selections,$persons=1,$currency=null) {
    if(!is_array($selections)||count($selections)>30)return new WP_Error('invalid_addon_selection');
    $persons=filter_var($persons,FILTER_VALIDATE_INT,['options'=>['min_range'=>1,'max_range'=>999]]);
    if($persons===false||$persons===null)return new WP_Error('invalid_person_count');
    $context=shared_product_tour_context($parent_rate_id);if(is_wp_error($context))return $context;
    $catalog=shared_product_addon_catalog($parent_rate_id);if(is_wp_error($catalog))return $catalog;
    $allowed=shared_product_allowed_optional_tour_ids($parent_rate_id);if(is_wp_error($allowed))return $allowed;
    $indexed=[];
    foreach($catalog as $item){
        if(!is_array($item)||!isset($item['tour_id'])||!is_numeric($item['tour_id']))return new WP_Error('invalid_addon_catalog_item');
        $id=(int)$item['tour_id'];if(isset($indexed[$id]))return new WP_Error('duplicate_addon_catalog_item');
        $indexed[$id]=$item;
    }
    $target=strtoupper($currency?:pricing_display_currency());$currencies=get_option('_wcml_settings',[]);
    if(!isset($currencies['currency_options'][$target]))return new WP_Error('unsupported_currency');
    $lines=[];$sum=0;$seen=[];
    foreach($selections as $selection){
        if(!is_array($selection))return new WP_Error('invalid_addon_selection');
        $id=absint($selection['tour_id']??0);if(!$id||!isset($indexed[$id])||$id===$context['tour_id']||!in_array($id,$allowed,true))return new WP_Error('addon_not_allowed_for_parent');
        if(isset($seen[$id]))return new WP_Error('duplicate_addon_selection');$seen[$id]=true;
        if(get_post_type($id)!=='tours-and-activities'||get_post_status($id)!=='publish')return new WP_Error('addon_not_published');
        $item=$indexed[$id];
        $basis=$item['basis']??'';if(!in_array($basis,['per_booking','per_person','per_unit'],true))return new WP_Error('invalid_addon_basis');
        $amount=$item['amount']??null;$source=$item['currency']??'';
        if(!is_numeric($amount)||!is_finite((float)$amount)||(float)$amount<0||!is_string($source)||!preg_match('/^[A-Z]{3}$/D',$source))return new WP_Error('invalid_addon_price');
        $quantity=$basis==='per_person'?$persons:($basis==='per_booking'?1:filter_var($selection['quantity']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1,'max_range'=>999]]));
        if($quantity===false||$quantity===null)return new WP_Error('invalid_addon_quantity');
        $date=$selection['date']??null;
        if($date!==null && (!is_string($date)||!preg_match('/^\d{4}-\d{2}-\d{2}$/D',$date)||!checkdate((int)substr($date,5,2),(int)substr($date,8,2),(int)substr($date,0,4))))return new WP_Error('invalid_addon_date');
        if(!empty($item['require_date'])&&$date===null)return new WP_Error('addon_date_required');
        if($date!==null){
            $timestamp=strtotime($date.' 12:00:00 UTC');$start=(int)get_post_meta($parent_rate_id,'tour_rate_start_date',true);$end=(int)get_post_meta($parent_rate_id,'tour_rate_end_date',true);
            if(!$timestamp||($start&&$timestamp<$start)||($end&&$timestamp>$end))return new WP_Error('addon_date_outside_parent_rate');
        }
        $total_source=(float)$amount*$quantity;
        $converted=$total_source===0?0:pricing_currency_convert($total_source,$source,$target);
        if($converted===null||!is_finite((float)$converted))return new WP_Error('addon_fx_missing');
        $converted=round((float)$converted,2);$sum+=$converted;
        $lines[]=['tour_id'=>$id,'parent_tour_id'=>$context['tour_id'],'basis'=>$basis,'quantity'=>$quantity,'date'=>$date,'source_currency'=>$source,'source_total'=>$total_source,'currency'=>$target,'amount'=>$converted];
    }
    return ['parent_rate_id'=>(int)$parent_rate_id,'parent_tour_id'=>$context['tour_id'],'currency'=>$target,'lines'=>$lines,'addons_total'=>round($sum,2),'status'=>'preview_only_not_checkout_authority'];
}

/** Existing JetEngine CPT field is the authoritative allowlist, never a duplicate relation. */
function shared_product_allowed_optional_tour_ids($parent_rate_id) {
    $context=shared_product_tour_context($parent_rate_id);
    if(is_wp_error($context))return $context;
    return shared_product_optional_tour_ids_for_tour((int)$context['tour_id']);
}
/** Same native Extra allowlist for Rate-backed and Direct-backed parent previews. */
function shared_product_optional_tour_ids_for_tour($parent) {
    $parent=absint($parent);
    if(get_post_type($parent)!=='tours-and-activities'||get_post_status($parent)!=='publish')return new WP_Error('invalid_optional_parent_tour');
    if((string)get_post_meta($parent,'tour_extras_status',true)!=='1')return [];
    $raw=get_post_meta($parent,'tour_optional_extras',true);
    if($raw===''||$raw===null||$raw===[])return [];
    if(is_string($raw))$raw=maybe_unserialize($raw);
    if(is_string($raw))$raw=array_filter(array_map('trim',explode(',',$raw)));
    if(!is_array($raw)||count($raw)>100)return new WP_Error('invalid_optional_tours_meta');
    $ids=[];
    foreach($raw as $value){
      if(!is_scalar($value)||!ctype_digit((string)$value))return new WP_Error('invalid_optional_tour_id');
      $id=absint($value);
      if($id===$parent || get_post_type($id)!=='tours-and-activities'||get_post_status($id)!=='publish')return new WP_Error('invalid_optional_tour_reference');
      $mode=get_post_meta($id,'tour_mode',true);
      if(!in_array($mode,['activity','extra_trip'],true))return new WP_Error('invalid_optional_tour_mode');
      if((string)get_post_meta($id,'tour_public_status',true)==='0')return new WP_Error('optional_tour_not_public');
      $ids[$id]=$id;
    }
    return array_values($ids);
}
/** Rate-based standalone optional tour quotation, no client-supplied costs. */
function shared_product_optional_rate_quote($parent_rate_id,$child_rate_id,$audience,$occupancy,$currency=null){
  $ids=shared_product_allowed_optional_tour_ids($parent_rate_id);
  if(is_wp_error($ids))return $ids;
  $child=shared_product_tour_context($child_rate_id);
  if(is_wp_error($child))return $child;
  if(!in_array($child['tour_id'],$ids,true))return new WP_Error('optional_tour_not_linked');
  $quote=shared_product_matrix_quote($child_rate_id,$audience,$occupancy,$currency,[]);
  if(is_wp_error($quote))return $quote;
  return ['parent_rate_id'=>(int)$parent_rate_id,'child_rate_id'=>(int)$child_rate_id,'child_tour_id'=>$child['tour_id'],'quote'=>$quote,'status'=>'preview_only_not_checkout_authority'];
}
