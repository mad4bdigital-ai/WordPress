<?php
/**
 * Lab-only, read-only shared quote envelope and accommodation preview.
 * Keeps accommodation and tour pricing sources/units separate; no WC writes.
 */
if (!defined('ABSPATH') || !defined('MAD4B_COMMERCE_RUNTIME_ENABLED') || !MAD4B_COMMERCE_RUNTIME_ENABLED) return;

/** Read-only diagnostic: never adds a WooCommerce price-writing hook. */
function shared_lab_cart_authority_audit($domain) {
 if (!in_array($domain,['tour','accommodation'],true))return new WP_Error('shared_cart_domain_invalid');
 $hook='woocommerce_before_calculate_totals';$priorities=[99999,100000,100001];
 $counts=[];global $wp_filter;
 foreach ($priorities as $priority) {
  $callbacks=$wp_filter[$hook]->callbacks[$priority]??[];
  $counts[(string)$priority]=count($callbacks);
 }
 return ['domain'=>$domain,'cart_authority'=>'not_certified',
  'legacy_pricing_callback_counts'=>$counts,
  'legacy_pricing_collision_requires_reconciliation'=>($counts['99999']>0&&$counts['100000']>0),
  'needs_native_cart_quote_adaptor'=>true,
  'commercial_writes_permitted'=>false,
  'checkout_ready'=>false];
}

/** Validate price component arithmetic and expose one non-authoritative presentation contract. */
function shared_lab_quote_envelope($domain, $quote) {
 if (!in_array($domain, ['tour','accommodation'], true) || !is_array($quote))
  return new WP_Error('shared_quote_invalid_domain_or_payload');
 $currency=$quote['currency']??null;
 $total=$quote['total']??null;
 $partial=$domain==='tour'
  && ($quote['status']??null)==='partial_price_preview_only'
  && ($quote['display_kind']??null)==='adults_only_reference'
  && array_key_exists('total',$quote) && $total===null
  && ($quote['checkout_ready']??null)===false;
 if ($domain==='tour' && ($quote['status']??null)==='partial_price_preview_only' && !$partial)
  return new WP_Error('shared_quote_partial_contract_invalid');
 $display=$partial?($quote['display_amount']??null):$total;
 if (!is_string($currency) || !preg_match('/^[A-Z]{3}$/D',$currency)
     || !is_numeric($display) || !is_finite((float)$display) || (float)$display < 0
     || (!$partial && (!is_numeric($total) || !is_finite((float)$total))))
  return new WP_Error('shared_quote_invalid_money');
 if ($partial && (isset($quote['base_preview_amount'])
   && ( !is_numeric($quote['base_preview_amount'])
     || abs((float)$quote['base_preview_amount']-(float)$display)>0.009 )))
  return new WP_Error('shared_quote_partial_base_mismatch');
 $components=[];$sum=0.0;
 if ($domain==='tour') {
  $source=$partial?($quote['lines']??null):($quote['components']??null);
  if (!is_array($source) || !$source)
   return new WP_Error('shared_quote_components_missing');
  foreach ($source as $item) {
   if (!is_array($item) || (($item['currency']??$currency)!==$currency))
    return new WP_Error('shared_quote_component_currency_mismatch');
   $components[]=['kind'=>(string)($item['kind']??'tour_component'),'amount'=>$item['amount']??null];
  }
 } else {
  if (($quote['status']??null)!=='shadow_preview_only' || ($quote['checkout_ready']??null)!==false)
   return new WP_Error('shared_quote_stay_not_shadow_only');
  $base=$quote['base_total']??$quote['total'];
  $components[]=['kind'=>'accommodation_base','amount'=>$base];
  if (isset($quote['add_on_receipt'])) {
   $addon=$quote['add_on_receipt'];
   if (!is_array($addon) || ($addon['currency']??null)!==$currency
       || !is_array($addon['lines']??null))
    return new WP_Error('shared_quote_addons_invalid');
   $addons_sum=0.0;
   foreach ($addon['lines'] as $item) {
    if (!is_array($item) || !is_string($item['kind']??null))
     return new WP_Error('shared_quote_addon_line_invalid');
    $components[]=['kind'=>'accommodation_'. $item['kind'],'amount'=>$item['amount']??null];
    if (!is_numeric($item['amount']??null) || !is_finite((float)$item['amount']))
     return new WP_Error('shared_quote_addon_money_invalid');
    $addons_sum+=(float)$item['amount'];
   }
   if (!is_numeric($addon['addon_total']??null)
       || abs(round($addons_sum,2)-(float)$addon['addon_total'])>0.009)
    return new WP_Error('shared_quote_addon_total_mismatch');
  }
 }
 foreach ($components as &$item) {
  if (!is_numeric($item['amount']) || !is_finite((float)$item['amount'])
      || (float)$item['amount']<0)
   return new WP_Error('shared_quote_component_amount_invalid');
  $item['amount']=round((float)$item['amount'],2);
  $sum += $item['amount'];
 }
 unset($item);
 if (abs(round($sum,2)-round((float)$display,2))>0.009)
  return new WP_Error('shared_quote_components_not_balanced');
 return [
  'schema_version'=>1,
  'domain'=>$domain,
  'quantity_model'=>$domain==='tour'?'traveler_booking':'stay_room_night_guest',
  'resource'=> $domain==='tour'
   ? ['tour_id'=>(int)($quote['tour_id']??0)]
   : ['property_id'=>(int)($quote['property_id']??0),
      'unit_id'=>(int)($quote['unit_id']??0),'rate_id'=>(int)($quote['rate_id']??0)],
  'currency'=>$currency,'total'=>$partial?null:round((float)$total,2),
  'display_amount'=>round((float)$display,2),
  'display_kind'=>$partial?'adults_only_reference':'nonbinding_price_preview',
  'components'=>$components,
  'sum_verified'=>true,
  'quantity_summary'=>$domain==='tour'
    ? ['model'=>'travelers','tour_id'=>(int)($quote['tour_id']??0)]
    : ['model'=>'room_nights_and_guests','nights'=>(int)($quote['nights']??0),
       'rooms'=>(int)($quote['rooms']??0),'adults'=>(int)($quote['adults']??0),
       'children'=>(int)($quote['children']??0),'price_basis'=>$quote['price_basis']??null],
  'cart_readiness'=>shared_lab_cart_authority_audit($domain),
  'checkout_ready'=>false,
  'cart_writes'=>false,
  'quote_status'=>$partial?'partial_price_preview_only':'preview_only',
  'pricing_scope'=>$partial?'adults_only_excludes_children_infants':'preview_only',
  'readiness'=> $domain==='tour'
    ? ($quote['financial_readiness']??['status'=>'not_certified'])
    : ['status'=>'accommodation_shadow_only',
       'inventory_status'=>$quote['inventory_status']??'not_checked',
       'availability_is_not_sale_authority'=>true],
 ];
}

/** The admission gate never accepts monetary fields, arbitrary modifiers or checkout input. */
function shared_lab_stay_quote_from_input($input) {
 if (!is_array($input) || array_diff(array_keys($input),
      ['rate_id','checkin','checkout','adults','children'])) return new WP_Error('shared_stay_unknown_input');
 $args=[];
 foreach (['rate_id'=>[1,PHP_INT_MAX],'adults'=>[1,50],'children'=>[0,50]] as $key=>$range) {
  $v=$input[$key]??($key==='children'?0:null);
  if (!is_scalar($v) || is_bool($v)) return new WP_Error('shared_stay_invalid_'.$key);
  $n=filter_var($v,FILTER_VALIDATE_INT,['options'=>['min_range'=>$range[0],'max_range'=>$range[1]]]);
  if ($n===false) return new WP_Error('shared_stay_invalid_'.$key);
  $args[$key]=$n;
 }
 foreach (['checkin','checkout'] as $key) {
  $value=$input[$key]??null;
  if (!is_string($value) || !preg_match('/^\d{4}-\d{2}-\d{2}$/D',$value))
   return new WP_Error('shared_stay_invalid_'.$key);
  $dt=DateTimeImmutable::createFromFormat('!Y-m-d',$value,new DateTimeZone('UTC'));
  if (!$dt || $dt->format('Y-m-d')!==$value) return new WP_Error('shared_stay_invalid_'.$key);
  $args[$key]=$value;
 }
 if ($args['checkout']<=$args['checkin'])return new WP_Error('shared_stay_invalid_range');
 if (!function_exists('BookingExperience\\unified_accommodation_preview')) return new WP_Error('shared_stay_engine_unavailable');
 // Unified pricing inherits native Property/Unit/Rate validation. Inventory
 // intentionally remains separate from the financial calculation.
 $preview=\BookingExperience\unified_accommodation_preview($args+['rooms'=>1]);
 if (is_wp_error($preview))return $preview;
 return shared_lab_quote_envelope('accommodation',$preview['provider_quote']);
}

add_action('wp_ajax_shared_lab_stay_quote',static function(){
 if (!current_user_can('manage_options'))wp_send_json_error(['code'=>'forbidden'],403);
 check_ajax_referer('shared_lab_stay_quote','nonce');
 $allowed=['action','nonce','rate_id','checkin','checkout','adults','children'];
 if (array_diff(array_keys($_POST),$allowed))wp_send_json_error(['code'=>'shared_stay_unknown_input'],422);
 $input=[];
 foreach (['rate_id','checkin','checkout','adults','children'] as $key) {
  if (isset($_POST[$key])) {
   if (!is_string($_POST[$key]))wp_send_json_error(['code'=>'shared_stay_invalid_field'],422);
   $input[$key]=sanitize_text_field(wp_unslash($_POST[$key]));
  }
 }
 $receipt=shared_lab_stay_quote_from_input($input);
 if(is_wp_error($receipt))wp_send_json_error(['code'=>$receipt->get_error_code()],422);
 wp_send_json_success($receipt);
});

/** Preview is a peer to Tours but deliberately uses a separate accommodation-specific form. */
add_action('admin_footer',static function(){
 if (!current_user_can('manage_options') || ($_GET['page']??'')!=='etg-dynamic-pricing-controls')return;
 $nonce=wp_create_nonce('shared_lab_stay_quote');$url=admin_url('admin-ajax.php');
 ?>
 <section id="shared-lab-stay-preview" style="padding:20px;margin:20px 0;background:#fff;border:1px solid #c3c4c7;border-left:4px solid #2271b1;max-width:1060px" aria-labelledby="shared-lab-stay-title">
  <h2 id="shared-lab-stay-title">Accommodation · Unit / Rate quote preview</h2>
  <p>Independent accommodation pricing. Rooms, nights and guests are not Tour traveler quantities. A shadow quote is not availability confirmation or permission to sell.</p>
  <form id="shared-lab-stay-form">
  <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:12px">
   <label>Property Rate ID <input name="rate_id" type="number" min="1" required style="width:100%"></label>
   <label>Check-in <input name="checkin" type="date" required style="width:100%"></label>
   <label>Check-out <input name="checkout" type="date" required style="width:100%"></label>
   <label>Adults <input name="adults" type="number" min="1" max="50" value="2" required style="width:100%"></label>
   <label>Children <input name="children" type="number" min="0" max="50" value="0" required style="width:100%"></label>
  </div>
  <p>Single-room preview only. Multi-room allocations, add-ons and payment remain outside this form until certified.</p>
  <button class="button button-primary" type="submit">Preview accommodation quote</button>
  <span id="shared-lab-stay-status" role="status" aria-live="polite"></span>
  </form>
  <div id="shared-lab-stay-result" aria-live="polite" style="margin-top:10px"></div>
 </section>
 <script>
 (function(){'use strict';
 const section=document.getElementById('shared-lab-stay-preview');
 const tourPreview=document.getElementById('etg-quote-preview');
 if(tourPreview&&tourPreview.parentElement)tourPreview.insertAdjacentElement('afterend',section);
 else document.querySelector('.wrap')?.appendChild(section);
 const form=document.getElementById('shared-lab-stay-form'),
 status=document.getElementById('shared-lab-stay-status'),
 output=document.getElementById('shared-lab-stay-result');
 const text=(tag,value)=>{const e=document.createElement(tag);e.textContent=String(value);return e;};
 form.addEventListener('submit',async(event)=>{
  event.preventDefault();if(!form.reportValidity())return;
  status.textContent=' Checking saved data…';output.replaceChildren();
  const data=new FormData(form);
  data.set('action','shared_lab_stay_quote');
  data.set('nonce',<?php echo wp_json_encode($nonce); ?>);
  try{
   const response=await fetch(<?php echo wp_json_encode($url); ?>,{method:'POST',body:data,credentials:'same-origin',cache:'no-store'});
   const payload=await response.json();
   if(!payload.success){output.appendChild(text('p','Quote unavailable: '+(payload.data?.code||'unknown_error')));status.textContent=' Rejected safely';return;}
   const q=payload.data;
   output.appendChild(text('h3','Total: '+q.total+' '+q.currency));
   output.appendChild(text('p','Shadow preview only · Checkout blocked · '+q.readiness.inventory_status));
   const table=document.createElement('table');table.style.width='100%';
   for(const c of q.components){const tr=document.createElement('tr');tr.append(text('td',c.kind),text('td',c.amount+' '+q.currency));table.appendChild(tr);}
   output.appendChild(table);status.textContent=' Preview calculated';
  }catch(e){output.appendChild(text('p','Request failed; no booking created'));status.textContent=' Unavailable';}
 });
 })();
 </script>
 <?php
});
