<?php
/** Diagnostic only, never supplies missing commercial policy values. */
if (!defined('ABSPATH') || !defined('SHARED_PRODUCT_LAB_ONLY') || !SHARED_PRODUCT_LAB_ONLY) return;
function shared_product_financial_readiness_v2($tour_id, $rate_id=null) {
 $tour_id=absint($tour_id); $rate_id=$rate_id===null?null:absint($rate_id);
 if(get_post_type($tour_id)!=='tours-and-activities')return new WP_Error('readiness_invalid_tour');
 if($rate_id!==null && (get_post_type($rate_id)!=='tour-rates' || (int)get_post_meta($rate_id,'related_tour_id',true)!==$tour_id))return new WP_Error('readiness_rate_mismatch');
 $mode=(string)get_post_meta($tour_id,'tour_mode',true);
 $policies=shared_product_mode_policies(); if(is_wp_error($policies))return $policies;
 $entry=$policies[$mode]??null; $issues=[];
 if(!is_array($entry))$issues[]='missing_mode_pricing_policy';
 elseif(empty($entry['enabled']))$issues[]='mode_pricing_disabled';
 if(in_array($mode,(array)get_option('shared_lab_inferred_mode_policy_pending_approval_20261024',[]),true))$issues[]='inferred_mode_policy_pending_approval';
 $strategy=is_array($entry)?($entry['strategy']??null):null;
 $financial=$rate_id!==null?shared_product_financial_policy_resolve($rate_id):null;
 if(is_wp_error($financial))$issues[]='financial:'.$financial->get_error_code();
 $matrix_required=in_array($strategy,['group_size_tiered','seasonal_occupancy','accommodation_matrix'],true);
 if($rate_id!==null && $matrix_required){$matrix=shared_product_rate_matrix($rate_id);if(is_wp_error($matrix))$issues[]='matrix:'.$matrix->get_error_code();}
 return ['tour_id'=>$tour_id,'rate_id'=>$rate_id,'tour_mode'=>$mode,'strategy'=>$strategy,'matrix_required'=>$matrix_required,'blockers'=>array_values(array_unique($issues)),'checkout_ready'=>false,'status'=>$issues?'missing_authoritative_configuration':'diagnostics_passed_checkout_still_uncertified'];
}
