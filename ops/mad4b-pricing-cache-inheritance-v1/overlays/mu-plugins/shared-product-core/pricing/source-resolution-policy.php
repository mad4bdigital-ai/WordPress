<?php
/** Source priority policy. Never combines direct and rate amounts. Runtime-gated. */
if (!defined('ABSPATH'))return;
$mad4bSourcePolicyEnabled=(defined('MAD4B_COMMERCE_RUNTIME_ENABLED') && MAD4B_COMMERCE_RUNTIME_ENABLED===true)
 || (defined('MAD4B_PRESENTATION_RUNTIME_ENABLED') && MAD4B_PRESENTATION_RUNTIME_ENABLED===true);
if (!$mad4bSourcePolicyEnabled)return;
function shared_product_price_source_policy($mode='',$cache_context=[]) {
 if(class_exists('MAD4B_Pricing_Cache_Inheritance_V1',false))
  return MAD4B_Pricing_Cache_Inheritance_V1::memoize_source_policy((string)$mode,
    static fn()=>shared_product_price_source_policy_uncached($mode),is_array($cache_context)?$cache_context:[]);
 return shared_product_price_source_policy_uncached($mode);
}
function shared_product_price_source_policy_uncached($mode='') {
 $keys=['direct_weight','rate_weight','tie_break','fallback','visibility'];
 $default=['direct_weight'=>50,'rate_weight'=>50,'tie_break'=>'rate','fallback'=>'next_valid','visibility'=>'winner'];
 foreach($keys as $key){$v=shared_product_jet_policy_raw('shared_price_source_'.$key);if(is_wp_error($v)){if($v->get_error_code()!=='policy_not_configured')return $v;continue;}$default[$key]=$v;}
 $rows=shared_product_jet_policy_raw('shared_price_source_mode_rows');
 if(!is_wp_error($rows)){
  if(!is_array($rows))return new WP_Error('invalid_source_mode_rows');$found=false;
  foreach($rows as $row){if(!is_array($row))return new WP_Error('invalid_source_mode_row');if(($row['mode']??'')!==$mode)continue;if($found)return new WP_Error('duplicate_source_mode_policy');$found=true;
   foreach($keys as $key)if(isset($row[$key])&&$row[$key]!=='')$default[$key]=$row[$key];
  }
 }elseif($rows->get_error_code()!=='policy_not_configured')return $rows;
 foreach(['direct_weight','rate_weight'] as $key){$weight=filter_var($default[$key],FILTER_VALIDATE_INT);if($weight===false||$weight<0||$weight>100)return new WP_Error('invalid_source_weight');$default[$key]=$weight;}
 foreach(['tie_break'=>['direct','rate'],'fallback'=>['next_valid','strict'],'visibility'=>['winner','both']] as $key=>$allowed)if(!in_array($default[$key],$allowed,true))return new WP_Error('invalid_source_policy_choice');
 return $default;
}
function shared_product_resolve_price_sources($candidates,$mode='',$cache_context=[]) {
 if(!is_array($candidates)||array_diff(array_keys($candidates),['direct','rate']))return new WP_Error('invalid_source_candidates');
 $policy=shared_product_price_source_policy($mode,$cache_context);if(is_wp_error($policy))return $policy;
 $rank=['direct','rate'];usort($rank,static function($a,$b)use($policy){$wa=$policy[$a.'_weight'];$wb=$policy[$b.'_weight'];return $wa===$wb?($a===$policy['tie_break']?-1:1):($wa>$wb?-1:1);});
 $valid=[];foreach($rank as $source){$v=$candidates[$source]??null;if(is_array($v)&&isset($v['amount'],$v['currency'])&&is_numeric($v['amount'])&&is_finite((float)$v['amount'])&&(float)$v['amount']>=0&&is_string($v['currency'])&&preg_match('/^[A-Z]{3}$/D',$v['currency']))$valid[$source]=$v;}
 $preferred=$rank[0];if(!isset($valid[$preferred])&&$policy['fallback']==='strict')return new WP_Error('preferred_price_source_unavailable');
 $chosen=isset($valid[$preferred])?$preferred:($rank[1]??null);if(!$chosen||!isset($valid[$chosen]))return new WP_Error('no_valid_price_source');
 return ['source'=>$chosen,'amount'=>(float)$valid[$chosen]['amount'],'currency'=>$valid[$chosen]['currency'],'visible_sources'=>$policy['visibility']==='both'?array_keys($valid):[$chosen],'policy'=>$policy,'status'=>'preview_only_not_checkout_authority'];
}
