<?php
/** Group tier quote is preview-only, never alters Cart. */
if(!defined('ABSPATH')||!defined('SHARED_PRODUCT_LAB_ONLY')||!SHARED_PRODUCT_LAB_ONLY)return;
function shared_product_group_tier_preview($tour,$rate,$tier,$people,$base){
 $term=get_term((int)$tier,'group-size-tiers');
 if(!$term||is_wp_error($term))return new WP_Error('group_tier_unknown');
 if(get_post_type($rate)!=='tour-rates'||(int)get_post_meta($rate,'related_tour_id',true)!==(int)$tour)return new WP_Error('group_tier_rate_mismatch');
 $min=(int)get_term_meta($tier,'tier_min',true);$max=(int)get_term_meta($tier,'tier_max',true);
 if($min<1||$max<$min||$people<$min||$people>$max)return new WP_Error('group_tier_capacity_invalid');
 if(!get_term_meta($tier,'tier_enabled',true))return new WP_Error('group_tier_disabled');
 $selected=wp_get_post_terms($tour,'group-size-tiers',['fields'=>'ids']);
 if(is_wp_error($selected)||!in_array((int)$tier,array_map('intval',$selected),true))return new WP_Error('group_tier_unavailable_for_tour');
 // Reject overlapping active tier ranges: client must not choose whichever is cheaper.
 foreach(array_unique(array_map('intval',$selected)) as $other){
  if($other===(int)$tier)continue;
  $oterm=get_term($other,'group-size-tiers');
  if(!$oterm||is_wp_error($oterm)||!get_term_meta($other,'tier_enabled',true))continue;
  $lo=(int)get_term_meta($other,'tier_min',true);$hi=(int)get_term_meta($other,'tier_max',true);
  if($lo>0&&$hi>=$lo&&$people>=$lo&&$people<=$hi)return new WP_Error('group_tier_overlapping_capacity');
 }
 $rules=shared_product_jet_policy_raw('shared_group_tier_pricing_rows');
 if(is_wp_error($rules)||!is_array($rules))return new WP_Error('group_tier_pricing_not_configured');
 $matching=array_values(array_filter($rules,static fn($r)=>is_array($r)&&(int)($r['rate_id']??0)===(int)$rate&&(int)($r['tier_id']??0)===(int)$tier&&!empty($r['enabled'])));
 if(count($matching)!==1)return new WP_Error('group_tier_rule_missing_or_ambiguous');
 $percentage=$matching[0]['percent']??null;
 if(!is_numeric($percentage)||$percentage<0||$percentage>300||!is_numeric($base)||$base<=0)return new WP_Error('group_tier_invalid_price');
 return ['rate_id'=>(int)$rate,'tier_id'=>(int)$tier,'travelers'=>(int)$people,'percent'=>(float)$percentage,'amount'=>round($base*$percentage/100,2),'status'=>'preview_only'];
}


function shared_product_group_tier_from_taxonomy($tour,$people){
 $ids=wp_get_post_terms((int)$tour,'group-size-tiers',['fields'=>'ids']);
 if(is_wp_error($ids))return $ids;
 $match=[];
 foreach($ids as $id){
  $t=get_term((int)$id,'group-size-tiers');
  if(!$t||is_wp_error($t)||!get_term_meta($id,'tier_enabled',true))continue;
  $min=(int)get_term_meta($id,'tier_min',true);$max=(int)get_term_meta($id,'tier_max',true);
  if($min>0&&$max>=$min&&$people>=$min&&$people<=$max)$match[]=(int)$id;
 }
 if(count($match)!==1)return new WP_Error($match?'group_tiers_overlap':'group_tier_not_configured');
 return $match[0];
}
