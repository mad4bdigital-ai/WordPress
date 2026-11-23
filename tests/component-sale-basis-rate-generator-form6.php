<?php
defined('ABSPATH')||exit;
$r=['site'=>home_url(),'rates_proposal'=>[],'form6_previews'=>[],'policies'=>[]];
foreach([57562,60623,60629] as $id){
 $r['policies'][(string)$id]=MAD4B_Component_Selling_Basis_V1::resolve($id);
 $input=[
 'tour_id'=>$id,'source'=>'direct','rate_id'=>0,'travel_date'=>'2027-01-15',
 'adults'=>2,'children'=>0,'infants'=>0,'occupancy'=>'double','extras'=>[]
 ];
 $q=shared_presentation_form6_price_quote($input);
 $r['form6_previews'][(string)$id]=is_wp_error($q)
 ?['status'=>'rejected','code'=>$q->get_error_code()]
 :['status'=>$q['status'],'currency'=>$q['currency']??null,
   'display_amount'=>$q['display_amount']??null,'total'=>$q['total']??null,
   'checkout_ready'=>$q['checkout_ready']??null,
   'order_created'=>$q['order_created']??null];
 if(!is_wp_error($q) && (!empty($q['checkout_ready'])||!empty($q['order_created'])))
   throw new RuntimeException('nontransactional_form6_preview_changed_to_checkout');
}
$cases=[
 'tour_60629'=>[
  'kind'=>'tour','parent_id'=>60629,'amount'=>'1780','currency'=>'','price_unit'=>'',
  'valid_from'=>'2027-02-01','valid_to'=>'2027-02-28',
  'accommodation_assignment_mode'=>'manual'
 ],
 'hotel_36084'=>[
  'kind'=>'accommodation','parent_id'=>36084,'amount'=>'300','currency'=>'',
  'price_unit'=>'','valid_from'=>'2027-02-01','valid_to'=>'2027-02-28'
 ],
 'transport_70830'=>[
  'kind'=>'transport','parent_id'=>70830,'amount'=>'100','currency'=>'',
  'price_unit'=>'','valid_from'=>'2027-02-01','valid_to'=>'2027-02-28'
 ],
];
foreach($cases as $label=>$payload){
 if(get_post_status($payload['parent_id'])!=='publish'){
  $r['rates_proposal'][$label]=['status'=>'parent_not_published','type'=>get_post_type($payload['parent_id'])];
  continue;
 }
 $p=Shared_Product_Rate_Generation::prepare($payload);
 $r['rates_proposal'][$label]=is_wp_error($p)?
  ['status'=>'rejected','error'=>$p->get_error_code()]:
  ['status'=>'draft_proposal','kind'=>$p['kind'],
   'currency'=>$p['meta']['base_currency']??$p['meta']['currency']??null,
   'unit'=>$p['meta']['unit_type']??$p['meta']['prop_price_unit']??$p['meta']['pricing_type']??null,
   'stop_sale'=>$p['meta']['stop_sale']??null];
 if(!is_wp_error($p)&&(($p['meta']['stop_sale']??null)!=='1'&&($p['meta']['stop_sale']??null)!=='true'))
   throw new RuntimeException('generated_rate_not_stop_sale');
}
$currency=(string)get_option('woocommerce_currency','');
if($currency!=='USD')throw new RuntimeException('unexpected_store_base_currency');
if(MAD4B_Component_Selling_Basis_V1::currency(57562)!==$currency)
 throw new RuntimeException('woo_currency_fallback_missing');
if(MAD4B_Component_Selling_Basis_V1::unit(78050)!=='per_cabin_night')
 throw new RuntimeException('cruise_cabin_unit_not_preserved');
echo wp_json_encode([
 'contract'=>'mad4b.form6-rate-generation-inherited-basis-qa.v1',
 'site'=>home_url(),'travel_date_tested'=>'2027-01-15',
 'rate_window'=>'2027-02-01..2027-02-28',
 'form6_previews'=>$r['form6_previews'],'rate_generator_draft_proposals'=>$r['rates_proposal'],
 'booking_setting'=>get_option('etg-dynamic-pricing-controls_booking_enabled'),
 'no_rate_or_order_written'=>true,'checkout_activated'=>false
],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES).PHP_EOL;

