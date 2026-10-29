<?php
/** Extensible, explicit rate matrix evaluators. Never infer missing prices. */
if (!defined('ABSPATH') || !defined('SHARED_PRODUCT_LAB_ONLY') || !SHARED_PRODUCT_LAB_ONLY) return;
function shared_product_rate_matrix($rate_id) {
    $raw=get_post_meta($rate_id,'shared_product_rate_matrix',true);
    if(!is_string($raw) || trim($raw)==='')return new WP_Error('rate_matrix_not_configured');
    $matrix=json_decode($raw,true);
    if(json_last_error()!==JSON_ERROR_NONE || !is_array($matrix) || count($matrix)>100)return new WP_Error('invalid_rate_matrix');
    return $matrix;
}
function shared_product_matrix_quote($rate_id,$audience,$occupancy,$currency,$context=[]) {
    $resolved=shared_product_resolve_strategy($rate_id);
    if(is_wp_error($resolved))return $resolved;
    $strategy=$resolved['strategy'];
    if($strategy==='rate_occupancy'){
        $q=shared_product_quote_snapshot($rate_id,$audience,$occupancy,$currency);
        return is_wp_error($q)?$q:array_merge($q,['tour_id'=>$resolved['tour_id'],'tour_mode'=>$resolved['tour_mode'],'pricing_strategy'=>$strategy]);
    }
    $matrix=shared_product_rate_matrix($rate_id);
    if(is_wp_error($matrix))return $matrix;
    if(($matrix['schema']??null)!==1 || ($matrix['strategy']??'')!==$strategy || !isset($matrix['currency']) || !preg_match('/^[A-Z]{3}$/D',$matrix['currency']))return new WP_Error('rate_matrix_schema_mismatch');
    $base=$matrix['currency'];$amount=null;$lookup='';
    if($strategy==='group_size_tiered'){
        $size=filter_var($context['group_size']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1,'max_range'=>999]]);
        if($size===false || $size===null || $size<$resolved['minimum_travelers'] || $size>$resolved['maximum_travelers'])return new WP_Error('invalid_group_size');
        foreach(($matrix['tiers']??[]) as $tier){
            if(!is_array($tier))continue;
            $min=filter_var($tier['min']??null,FILTER_VALIDATE_INT);$max=filter_var($tier['max']??null,FILTER_VALIDATE_INT);
            if($min===false||$max===false||$min===null||$max===null||$max<$min)continue;
            if($size>=$min && $size<=$max){if($amount!==null)return new WP_Error('ambiguous_group_tier');$amount=$tier['price_per_person']??null;$lookup="$min-$max";}
        }
    } elseif($strategy==='seasonal_occupancy'){
        $season=sanitize_key($context['season']??'');
        if(!$season||!isset(($matrix['seasons']??[])[$season]))return new WP_Error('season_not_configured');
        $amount=$matrix['seasons'][$season][$occupancy]??null;$lookup=$season.'/'.$occupancy;
    } elseif($strategy==='accommodation_matrix'){
        $season=sanitize_key($context['season']??'');$level=sanitize_key($context['accommodation']??'');
        if(!$season||!$level)return new WP_Error('missing_accommodation_selection');
        $amount=$matrix['accommodations'][$level][$season][$occupancy]??null;$lookup=$level.'/'.$season.'/'.$occupancy;
    } else return new WP_Error('strategy_evaluator_not_certified');
    if(!is_scalar($amount)||!is_numeric($amount)||!is_finite((float)$amount)||(float)$amount<=0)return new WP_Error('rate_matrix_price_missing');
    // Keep rate availability and audience checks authoritative; never use a disabled rate.
    $availability=pricing_audience_quote($rate_id,$audience,$occupancy);
    if(($availability['status']??'')!=='available')return new WP_Error('rate_unavailable');
    $target=strtoupper($currency?:pricing_display_currency());
    $wcml=get_option('_wcml_settings',[]);
    if(!isset($wcml['currency_options'][$target]))return new WP_Error('unsupported_currency');
    $converted=pricing_currency_convert((float)$amount,$base,$target);
    if($converted===null||!is_finite($converted)||$converted<=0)return new WP_Error('fx_unavailable');
    return ['schema'=>1,'rate_id'=>$rate_id,'tour_id'=>$resolved['tour_id'],'tour_mode'=>$resolved['tour_mode'],
      'pricing_strategy'=>$strategy,'lookup'=>$lookup,'audience'=>$audience,'occupancy'=>$occupancy,
      'source_currency'=>$base,'source_amount'=>(float)$amount,'display_currency'=>$target,'display_amount'=>$converted,
      'group_size'=>isset($size)?$size:null,'quoted_at_utc'=>gmdate('c'),'status'=>'matrix_quote_not_financial_checkout_authority'];
}

/** Manage rate matrices without editing code or vendor plugins. */
add_action('add_meta_boxes_tour-rates',static function(){
 add_meta_box('shared-product-rate-matrix',__('Pricing Strategy Matrix','woocommerce'),static function($post){
   wp_nonce_field('shared_product_rate_matrix_save','shared_product_matrix_nonce');
   $value=get_post_meta($post->ID,'shared_product_rate_matrix',true);
   echo '<p>'.esc_html__('Optional JSON price matrix. The parent tour determines the strategy. Invalid or missing matrices cannot produce prices.','woocommerce').'</p>';
   echo '<textarea name="shared_product_rate_matrix" rows="12" style="width:100%;font-family:monospace" spellcheck="false">'.esc_textarea($value).'</textarea>';
   echo '<p class="description">Schema 1; group_size_tiered: tiers[min,max,price_per_person]; seasonal_occupancy: seasons; accommodation_matrix: accommodations. Currency is required.</p>';
 },'tour-rates','normal','default');
});
add_action('save_post_tour-rates',static function($id,$post){
 if(!isset($_POST['shared_product_matrix_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['shared_product_matrix_nonce'])),'shared_product_rate_matrix_save'))return;
 if(defined('DOING_AUTOSAVE')&&DOING_AUTOSAVE)return;
 if(!current_user_can('edit_post',$id))return;
 $raw=wp_unslash($_POST['shared_product_rate_matrix']??'');
 if(!is_string($raw))return;
 if(trim($raw)===''){delete_post_meta($id,'shared_product_rate_matrix');return;}
 if(strlen($raw)>60000)return;
 $matrix=json_decode($raw,true);
 if(json_last_error()!==JSON_ERROR_NONE||!is_array($matrix)||($matrix['schema']??null)!==1||!isset(shared_product_strategy_types()[$matrix['strategy']??'']))return;
 if(!isset($matrix['currency'])||!is_string($matrix['currency'])||!preg_match('/^[A-Z]{3}$/D',$matrix['currency']))return;
 update_post_meta($id,'shared_product_rate_matrix',wp_json_encode($matrix,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
},20,2);
