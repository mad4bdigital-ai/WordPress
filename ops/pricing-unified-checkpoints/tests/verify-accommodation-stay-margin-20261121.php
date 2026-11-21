<?php
/** Live QA: original native finance stays independent of Property Rate writes.
 * Run via wp --user=1 eval-file; tests one known QA ONLY draft Property 67063,
 * then restores an all-Inherit financial policy. It never writes actual prices.
 */
defined('ABSPATH')||exit;
$margin='MAD4B_Accommodation_Stay_Margin_V1';
$ok=0;$failed=[];
$check=static function(bool $condition,string $name)use(&$ok,&$failed){
    $ok++;if(!$condition)$failed[]=$name;
};
$error=static function($value,string $code)use($check){
    $check(is_wp_error($value)&&$value->get_error_code()===$code,$code);
};
$check(class_exists($margin,false),'margin_class_loaded');
$global=$margin::read('global',0);
$check(is_array($global),'global_margin_readable');
$check(is_array($global)&&!$global['configured'],'global_does_not_claim_default_margin');
$valid=['schema'=>1,'mode'=>'preview_only','pricing_rule_kind'=>'target_margin_percent',
    'bands'=>[
        ['min_nights'=>1,'max_nights'=>2,'percent'=>'25'],
        ['min_nights'=>3,'max_nights'=>4,'percent'=>'22'],
        ['min_nights'=>5,'max_nights'=>7,'percent'=>'18'],
        ['min_nights'=>8,'max_nights'=>365,'percent'=>'15']]];
$v=$margin::validate($valid);
$check(is_array($v)&&count($v['bands'])===4,'valid_four_nights_bands');
$check(is_array($v)&&$v['bands'][0]['percent']==='25.00','normalized_25percent');
$error($margin::validate(array_replace($valid,['bands'=>[
    ['min_nights'=>1,'max_nights'=>5,'percent'=>'25'],
    ['min_nights'=>5,'max_nights'=>7,'percent'=>'18']]])), 'stay_margin_overlapping_bands');
$error($margin::validate(array_replace($valid,['bands'=>[
    ['min_nights'=>1,'max_nights'=>366,'percent'=>'20']]])), 'stay_margin_night_range_invalid');
$error($margin::validate(array_replace($valid,['bands'=>[
    ['min_nights'=>1,'max_nights'=>2,'percent'=>'81']]])), 'stay_margin_percent_out_of_range');
$error($margin::validate(array_replace($valid,['pricing_rule_kind'=>'inherit'])),
 'stay_margin_kind_bands_mismatch');
$error($margin::validate(array_replace($valid,['pricing_rule_kind'=>'fixed_profit'])),
 'stay_margin_kind_invalid');
$error($margin::authorize('invalid',0),'stay_margin_scope_invalid');
$qa=67063;$post=get_post($qa);
$check($post instanceof WP_Post&&$post->post_type==='properties'
    &&$post->post_status==='draft'&&str_contains($post->post_title,'QA ONLY'),
    'sandbox_draft_property_verified');
$before=$margin::read('property',$qa);
$check(is_array($before),'qa_margin_original_readable');
$ratesBefore=wp_count_posts('property-rates');
$toursBefore=wp_count_posts('tour-rates');
$inherited=$margin::resolve(36181,0,0,5);
$check(is_array($inherited)&&$inherited['status']==='margin_not_configured',
 'unconfigured_global_not_falsely_zero_percent');
$costInput=['property_id'=>36181,'unit_id'=>0,'rate_id'=>0,'nights'=>3,
   'source_kind'=>'manual_cost_reference','unit_cost_per_night'=>'100.00',
   'currency'=>'USD','cost_basis'=>'per_room_night'];
$unset=$margin::preview($costInput);
$check(is_array($unset)&&$unset['proposed_sell_price']===null,
 'no_invented_sell_price_without_margin_policy');
$explicit=$margin::preview(array_replace($costInput,['source_kind'=>'explicit_selling_price']));
$check(is_array($explicit)&&$explicit['status']==='native_selling_price_takes_precedence',
 'no_second_margin_on_explicit_selling_price');
$error($margin::preview(array_replace($costInput,['currency'=>'USD/EUR'])),
 'stay_margin_currency_invalid');
$error($margin::preview(array_replace($costInput,['cost_basis'=>'per_stay'])),
 'stay_margin_cost_basis_invalid');
$error($margin::preview(array_replace($costInput,['unit_cost_per_night'=>'-20'])),
 'stay_margin_cost_invalid');
$error($margin::resolve(36181,0,0,366),'stay_margin_nights_invalid');
$error($margin::resolve(36181,71874,0,2),'accommodation_weight_post_invalid');
$qa_saved=false;
try{
 if(!$failed){
    $saved=$margin::save('property',$qa,$valid,$before['revision']);
    $qa_saved=is_array($saved)&&($saved['written']??false);
    $check($qa_saved,'qa_4band_save');
    $read=$margin::read('property',$qa);
    $check(is_array($read)&&$read['policy']['bands'][0]['percent']==='25.00',
        'qa_4band_readback');
    $stale=$margin::save('property',$qa,$valid,$before['revision']);
    $error($stale,'stay_margin_stale_editor_revision');
    foreach([[1,'25.00'],[2,'25.00'],[3,'22.00'],[4,'22.00'],
             [5,'18.00'],[7,'18.00'],[8,'15.00'],[365,'15.00']] as [$n,$pct]){
        $effective=$margin::resolve($qa,0,0,$n);
        $check(is_array($effective)&&($effective['effective']['band']['percent']??null)===$pct,
            'correct_nights_'.$n);
        $check(is_array($effective)&&($effective['effective']['scope']??'')==='property',
            'winning_qa_property_'.$n);
    }
    $two=$margin::preview(array_replace($costInput,['property_id'=>$qa,'nights'=>2]));
    $check(is_array($two)&&$two['proposed_sell_price']==='266.6667',
        'cost_200_at_25pct_target_margin');
    $check(is_array($two)&&$two['actual_gross_margin_percent']==='25.00',
        'actual_margin_25pct');
    $five=$margin::preview(array_replace($costInput,['property_id'=>$qa,'nights'=>5]));
    $check(is_array($five)&&$five['proposed_sell_price']==='609.7561',
        'cost_500_at_18pct_target_margin');
    $check(is_array($five)&&$five['checkout_ready']===false &&
       $five['price_written']===false,'no_checkout_or_sale');
    $registry=$margin::inventory();
    $check(is_array($registry)&&($registry['configured_count']??0)>=1,
        'configured_policy_visible_in_inventory');
    $markup=array_replace($valid,[
        'pricing_rule_kind'=>'markup_percent',
        'bands'=>[['min_nights'=>1,'max_nights'=>365,'percent'=>'25']]]);
    $stored=$margin::save('property',$qa,$markup,$read['revision']);
    $check(is_array($stored)&&($stored['written']??false),
        'qa_markup_switch_saved');
    $marked=$margin::preview(array_replace($costInput,['property_id'=>$qa,'nights'=>2]));
    $check(is_array($marked)&&$marked['proposed_sell_price']==='250.0000',
        'cost_200_25pct_markup_250_sale');
    $check(is_array($marked)&&$marked['actual_gross_margin_percent']==='20.00',
        '25pct_markup_is_20pct_gross_margin');
 }
} finally {
 $current=$margin::read('property',$qa);
 if(is_array($current)&&$current['policy']!==$before['policy']){
    $restored=$margin::save('property',$qa,$before['policy'],$current['revision']);
    $check(is_array($restored)&&($restored['written']??false),
        'qa_previous_policy_restored');
 }
 $now=$margin::read('property',$qa);
 $check(is_array($now)&&$now['policy']===$before['policy'],
    'qa_previous_policy_exact_readback');
}
$ratesAfter=wp_count_posts('property-rates');
$toursAfter=wp_count_posts('tour-rates');
foreach(['publish','draft','pending'] as $status){
 $check((int)($ratesBefore->$status??0)===(int)($ratesAfter->$status??0),
    'property_rates_unchanged_'.$status);
 $check((int)($toursBefore->$status??0)===(int)($toursAfter->$status??0),
    'tour_rates_unchanged_'.$status);
}
echo wp_json_encode(['status'=>$failed?'FAIL':'PASS','site'=>home_url(),
 'checks'=>$ok,'failed'=>$failed,'qa_property_id'=>$qa,
 'global_margin_still_unconfigured'=>is_array($global)&&!$global['configured'],
 'original_rate_writes'=>0,'checkout_writes'=>0,
 'restored_previous_qa_policy'=>true],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
