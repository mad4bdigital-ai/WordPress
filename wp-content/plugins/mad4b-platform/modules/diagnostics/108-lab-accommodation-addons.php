<?php
/** Read-only accommodation add-on composer, never payment authority. */
if(!defined('ABSPATH')||(!defined('MAD4B_COMMERCE_RUNTIME_ENABLED') || !MAD4B_COMMERCE_RUNTIME_ENABLED))return;
function lab_stay_addons($quote,$selections){
 if(!is_array($quote)||!is_array($selections))return new WP_Error('stay_addons_invalid_input');
 $unit=absint($quote['unit_id']??0);$rate=absint($quote['rate_id']??0);
 $catalog=lab_accommodation_services_catalog($unit);if(is_wp_error($catalog))return $catalog;
 $currency=$quote['currency'];$nights=(int)$quote['nights'];$guests=(int)$quote['adults']+(int)$quote['children'];
 $lines=[];$seen=[];
 $included_board=absint(get_post_meta($rate,'prop_board_type',true));
 $board=absint($selections['board_type']??0);
 if($board){
  $term=get_term($board,'board-type');if(!$term||is_wp_error($term))return new WP_Error('stay_board_term_unknown');
  if($board===$included_board){$lines[]=['kind'=>'meal','code'=>'board:'.$board,'amount'=>0,'source'=>'included_in_rate'];}
  else{
   $plans=get_post_meta($rate,'prop_meal_plans',true);
   if(!is_array($plans))return new WP_Error('stay_meal_plan_missing');
   $matches=array_values(array_filter($plans,static fn($p)=>is_array($p)&&(int)($p['prop_board_term_id']??0)===$board));
   if(count($matches)!==1)return new WP_Error('stay_meal_plan_missing_or_ambiguous');
   $plan=$matches[0];
   if(($plan['prop_currency']??'')!==$currency||!is_numeric($plan['prop_price']??null)||(float)$plan['prop_price']<0)return new WP_Error('stay_meal_plan_currency_or_amount_invalid');
   if(!in_array($plan['prop_charge_basis']??null,['per_stay','per_night','per_person','per_person_night'],true))return new WP_Error('stay_meal_plan_basis_invalid');
   $mult=match($plan['prop_charge_basis']){'per_stay'=>1,'per_night'=>$nights,'per_person'=>$guests,'per_person_night'=>$guests*$nights};
   $lines[]=['kind'=>'meal','code'=>'board:'.$board,'amount'=>round((float)$plan['prop_price']*$mult,2),'source'=>'rate_meal_plan'];
  }
 }
 $extras=$selections['extras']??[];if(!is_array($extras)||count($extras)>20)return new WP_Error('stay_extras_invalid');
 foreach($extras as $code){
  if(!is_string($code)||isset($seen[$code]))return new WP_Error('stay_extras_duplicate_or_invalid');$seen[$code]=true;
  $match=array_values(array_filter($catalog['optional_services'],static fn($r)=>$r['code']===$code));
  if(count($match)!==1)return new WP_Error('stay_extra_unknown_or_ambiguous');
  $extra=$match[0];if($extra['currency']!==$currency)return new WP_Error('stay_extra_currency_mismatch');
  $mult=match($extra['basis']){'per_stay'=>1,'per_night'=>$nights,'per_person'=>$guests,'per_person_night'=>$guests*$nights};
  $lines[]=['kind'=>'extra','code'=>$code,'amount'=>round($extra['amount']*$mult,2),'source'=>'unit_optional_services'];
 }
 return ['lines'=>$lines,'addon_total'=>round(array_sum(array_column($lines,'amount')),2),'currency'=>$currency,'status'=>'shadow_addons_only','checkout_ready'=>false];
}
