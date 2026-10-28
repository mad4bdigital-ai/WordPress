<?php
/** Generic pricing strategy registry and resolved rate-to-tour mode, lab only. */
if (!defined('ABSPATH') || !defined('SHARED_PRODUCT_LAB_ONLY') || !SHARED_PRODUCT_LAB_ONLY) return;
function shared_product_tour_context($rate_id) {
    $rate_id=absint($rate_id);
    if (get_post_type($rate_id)!=='tour-rates' || get_post_status($rate_id)!=='publish') return new WP_Error('invalid_rate');
    $raw=get_post_meta($rate_id,'related_tour_id',true);
    if (!is_scalar($raw) || !ctype_digit((string)$raw)) return new WP_Error('missing_tour_relation');
    $tour_id=absint($raw);
    if(get_post_type($tour_id)!=='tours-and-activities' || get_post_status($tour_id)!=='publish')return new WP_Error('invalid_related_tour');
    $raw_mode=get_post_meta($tour_id,'tour_mode',true);
    if (!is_string($raw_mode) || $raw_mode==='') return new WP_Error('tour_mode_unconfigured');
    $mode=sanitize_key($raw_mode);
    if ($mode!==$raw_mode) return new WP_Error('invalid_tour_mode');
    return ['rate_id'=>$rate_id,'tour_id'=>$tour_id,'tour_mode'=>$mode];
}
function shared_product_strategy_types(){
    $types=['group_size_tiered'=>'Group Size Tiered','seasonal_occupancy'=>'Seasonal Occupancy','accommodation_matrix'=>'Accommodation Matrix','rate_occupancy'=>'Rate Occupancy'];
    return apply_filters('shared_product_pricing_strategy_types',$types);
}
function shared_product_strategy_policy($mode){
    $policies=shared_product_mode_policies();
    $policy=is_array($policies)?($policies[$mode]??null):null;
    if (!is_array($policy)) return new WP_Error('mode_policy_not_configured');
    $types=shared_product_strategy_types();
    $strategy=$policy['strategy']??'';
    if(!isset($types[$strategy]))return new WP_Error('strategy_not_supported');
    if(empty($policy['enabled']))return new WP_Error('mode_policy_disabled');
    return ['strategy'=>$strategy,'enabled'=>true,'minimum_travelers'=>(int)($policy['minimum_travelers']??1),'maximum_travelers'=>(int)($policy['maximum_travelers']??999),'tax_percent_override'=>$policy['tax_percent_override']??null,'service_fee_percent_override'=>$policy['service_fee_percent_override']??null];
}
function shared_product_resolve_strategy($rate_id){
    $context=shared_product_tour_context($rate_id);
    if(is_wp_error($context))return $context;
    $policy=shared_product_strategy_policy($context['tour_mode']);
    if(is_wp_error($policy))return $policy;
    return array_merge($context,$policy);
}
/** Administration now lives exclusively in the JetEngine Options Page. */
/** Strategy evaluation strictly delegates to authoritative pricing rules. */
function shared_product_strategy_quote($rate_id,$audience,$occupancy,$currency=null){
    $resolved=shared_product_resolve_strategy($rate_id);
    if(is_wp_error($resolved))return $resolved;
    if($resolved['strategy']!=='rate_occupancy')return new WP_Error('strategy_evaluator_not_certified');
    if(!function_exists('shared_product_quote_snapshot'))return new WP_Error('quote_resolver_unavailable');
    $quote=shared_product_quote_snapshot($rate_id,$audience,$occupancy,$currency);
    if(is_wp_error($quote))return $quote;
    return array_merge($quote,['tour_id'=>$resolved['tour_id'],'tour_mode'=>$resolved['tour_mode'],'pricing_strategy'=>$resolved['strategy']]);
}
