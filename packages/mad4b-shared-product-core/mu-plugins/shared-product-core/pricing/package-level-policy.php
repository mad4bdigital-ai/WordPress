<?php
/** Central package-level pricing, preview-only. */
if(!defined('ABSPATH')||!defined('SHARED_PRODUCT_LAB_ONLY')||!SHARED_PRODUCT_LAB_ONLY)return;
function shared_product_package_level_preview($tour,$rate,$level,$base){
 $tour=(int)$tour;$rate=$rate===null?null:(int)$rate;$level=(int)$level;
 $term=get_term($level,'package_levels');
 if(!$term||is_wp_error($term))return new WP_Error('package_level_unknown');
 if(get_post_type($tour)!=='tours-and-activities'||($rate!==null&&(get_post_type($rate)!=='tour-rates'||(int)get_post_meta($rate,'related_tour_id',true)!==$tour)))return new WP_Error('package_level_relation_invalid');
 if($rate!==null && (int)get_post_meta($rate,'related_package_term_id',true)===$level)
  return new WP_Error('package_price_already_rate_specific');
 $assigned=wp_get_post_terms($tour,'package_levels',['fields'=>'ids']);
 if(is_wp_error($assigned)||!in_array($level,array_map('intval',$assigned),true))return new WP_Error('package_level_not_offered');
 if(!get_term_meta($level,'package_pricing_enabled',true))return new WP_Error('package_level_pricing_disabled');
 $rows=shared_product_jet_policy_raw('shared_package_pricing_rows');
 if(is_wp_error($rows)||!is_array($rows))return new WP_Error('package_level_rules_unconfigured');
 $found=[];
 foreach($rows as $r){
  if(!is_array($r)||empty($r['enabled'])||(int)($r['package_term_id']??0)!==$level)continue;
  $scope=$r['scope']??'';
  if($scope==='global'&&(int)($r['tour_id']??0)===0&&(int)($r['rate_id']??0)===0)$priority=1;
  elseif($scope==='tour'&&(int)($r['tour_id']??0)===$tour&&(int)($r['rate_id']??0)===0)$priority=2;
  elseif($scope==='rate'&&$rate!==null&&(int)($r['rate_id']??0)===$rate&&(int)($r['tour_id']??0)===0)$priority=3;
  else continue;
  $found[$priority][]=$r;
 }
 if(!$found)return new WP_Error('package_level_rule_missing');
 $priority=max(array_keys($found));if(count($found[$priority])!==1)return new WP_Error('package_level_rule_ambiguous');
 $pct=$found[$priority][0]['percent']??null;
 if(!is_numeric($pct)||(float)$pct<0||(float)$pct>300||!is_numeric($base)||(float)$base<=0)return new WP_Error('package_level_invalid_financial_input');
 return ['level_id'=>$level,'scope'=>$found[$priority][0]['scope'],'percent'=>(float)$pct,'base_amount'=>(float)$base,'adjusted_amount'=>round((float)$base*(float)$pct/100,2),'status'=>'preview_only'];
}
