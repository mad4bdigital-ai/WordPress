<?php
/** Read-only supplier readiness; no sellable currency/capacity invented. */
defined('ABSPATH')||exit(2);
$site=rtrim(home_url('/'),'/');
if(!in_array($site,['https://tours.dev.mad4b.com','https://wayfatours.com'],true))return;
wp_set_current_user(1);
$c='MAD4B_Transport_Source_Operations_Readiness_V1';
$checks=[];
$check=static function($name,$ok)use(&$checks){$checks[$name]=(bool)$ok;};
$check('readiness_service_available',class_exists($c));
$langs=$site==='https://wayfatours.com'?['en','es']:['ar','de','en','es','fr','it'];
foreach($langs as $lang){
 $catalog=MAD4B_Transport_Self_Pricing_Controls_V1::catalog('',$lang);
 $check('source_rows_'.$lang,count($catalog['items']??[])===51);
 $ready=0;$missing=[];
 foreach($catalog['items'] as $r){
  $o=$r['operations']??[];
  if(($o['source_trusted']??false)!==true||
     ($o['tour_component_opt_in']??false)!==true||
     ($o['standalone_publication_required']??true)!==false||
     ($o['supplier_availability']??'')!=='on_request_unconfirmed'||
     ($o['source_price_certified']??true)!==false||
     ($o['approved_native_rate_present']??true)!==false||
     ($o['pickup_point_verified']??true)!==false||
     ($o['dropoff_point_verified']??true)!==false||
     ($o['capacity_supplier_confirmed']??true)!==false||
     ($o['checkout_authorized']??true)!==false)
      $missing[]=$r['key'];
  if($o['booking_ready']??false)$ready++;
 }
 $check('all_51_source_statuses_fail_closed_'.$lang,!$missing);
 $check('no_pdf_checkout_ready_'.$lang,$ready===0);
}
$invalid=$c::inspect(1,[]);
$check('non_source_rejected',($invalid['source_trusted']??true)===false &&
 ($invalid['booking_ready']??true)===false);
$policy=MAD4B_Transport_Self_Pricing_Controls_V1::current();
$check('source_currency_not_fabricated',($policy['currency']??null)==='');
$check('source_amount_role_reference_only',($policy['price_role']??null)==='reference_only');
$check('company_uses_tour_only',service_channel_mode('transportations')==='tour_only');
$check('standalone_pages_not_available',!service_channel_allowed('transportations','standalone'));
$failed=array_keys(array_filter($checks,static fn($ok)=>!$ok));
echo wp_json_encode(['site'=>$site,'tests'=>count($checks),
 'passed'=>count($checks)-count($failed),'failed'=>$failed,
 'source_languages'=>count($langs),'source_operations_per_language'=>51,
 'rates_approved'=>0,'availability'=>'on_request_unconfirmed',
 'price_state'=>'reference_only','checkout_ready'=>false])."\n";
if($failed)exit(1);
