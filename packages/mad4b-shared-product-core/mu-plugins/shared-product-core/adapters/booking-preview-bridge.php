<?php
/** Lab-only booking quote bridge. No cart, order, payment or inventory mutation. */
if (!defined('ABSPATH') || !defined('SHARED_PRODUCT_LAB_ONLY') || !SHARED_PRODUCT_LAB_ONLY) return;
add_action('rest_api_init',static function(){register_rest_route('shared-product/v1','/booking-preview',[
 'methods'=>'POST','permission_callback'=>'__return_true',
 'callback'=>static function(WP_REST_Request $req){
  $rate=absint($req->get_param('rate_id'));$tour=absint($req->get_param('tour_id'));
  if (!$rate||!$tour||get_post_type($tour)!=='tours-and-activities'||absint(get_post_meta($rate,'related_tour_id',true))!==$tour)return new WP_Error('booking_rate_tour_mismatch','Rate and tour mismatch',['status'=>422]);
  if (get_post_status($rate)!=='publish' || (string)get_post_meta($rate,'stop_sale',true)==='1' || (string)get_post_meta($rate,'avilabile_status',true)!=='1' || (int)get_post_meta($rate,'tour_rate_end_date',true)<=time())return new WP_Error('booking_rate_not_currently_eligible','Rate is not currently eligible',['status'=>422]);
  $payload_early=$req->get_json_params();if(!is_array($payload_early))return new WP_Error('invalid_booking_payload','JSON object required',['status'=>422]);
  // Booking Preview accepts exactly one base Tour Rate; optional Extra Rates belong inside Extras.
  foreach(['rate_ids','tour_rate_ids','selected_rate_ids','base_rate_ids'] as $field)
   if(array_key_exists($field,$payload_early))return new WP_Error('multiple_base_rates_not_supported','Select exactly one base Tour Rate',['status'=>422]);
  foreach(['total','amount','price','currency','base_amount','exchange_rate','approved_total'] as $field)
   if(array_key_exists($field,$payload_early))return new WP_Error('client_amount_not_accepted','Client monetary values are not permitted',['status'=>422]);
  $audience=sanitize_key((string)$req->get_param('audience'));$occupancy=sanitize_key((string)$req->get_param('occupancy'));
  $persons=absint($req->get_param('persons'));$package=absint($req->get_param('package_term_id'));$property=absint($req->get_param('property_id'));$guide=absint($req->get_param('guide_language_term_id'));
  if(!$persons||$persons>999||!$package)return new WP_Error('booking_preview_invalid_selection','Invalid preview selection',['status'=>422]);
  if($property&&function_exists('service_channel_allowed')&&!service_channel_allowed('properties','tour_component'))
   return new WP_Error('booking_preview_property_service_disabled','Accommodation selection is disabled for tours',['status'=>422]);
  $date=$req->get_param('travel_date');
  $date=$date===null||$date===''?null:$date;
  $mode=$req->get_param('booking_mode');
  $mode=$mode===null||$mode===''?null:$mode;
  $season_term=$req->get_param('season_term_id');
  $season_term=$season_term===null||$season_term===''?null:$season_term;
  $visual=['package_term_id'=>(string)$package];if($property)$visual['property_id']=(string)$property;
  $base_quantities=['persons'=>$persons];
  $base_fields=['rooms','cabins','vehicles','days','trips','tables'];
  foreach($base_fields as $field){
    $value=$payload_early[$field]??null;
    if($value===null||$value==='')continue;
    $count=filter_var($value,FILTER_VALIDATE_INT,['options'=>['min_range'=>1,'max_range'=>999]]);
    if($count===false)return new WP_Error('invalid_base_unit_quantity','Invalid booking unit quantity',['status'=>422]);
    $base_quantities[$field]=$count;
  }
  // Fail fast on duplicate or malformed Extras before expensive base quoting.
  $early_extras=$payload_early['extras']??[];
  if(!is_array($early_extras)||count($early_extras)>30)return new WP_Error('invalid_booking_extras','Invalid Extras selection',['status'=>422]);
  $early_seen=[];$early_rates=[];
  foreach($early_extras as $extra){
   if(!is_array($extra)||array_diff(array_keys($extra),['extra_tour_id','extra_rate_id','quantities']))return new WP_Error('invalid_extra_selection','Unsupported Extra fields',['status'=>422]);
   $eid=filter_var($extra['extra_tour_id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
   if($eid===false||isset($early_seen[$eid]))return new WP_Error('invalid_or_duplicate_extra','Duplicate or invalid Extra',['status'=>422]);
   $early_seen[$eid]=true;
   $rid=filter_var($extra['extra_rate_id']??0,FILTER_VALIDATE_INT,['options'=>['min_range'=>0]]);
   if($rid===false)return new WP_Error('invalid_extra_rate','Invalid Extra rate',['status'=>422]);
   if($rid){
    if(isset($early_rates[$rid]))return new WP_Error('duplicate_extra_rate','A Tour Rate cannot be used twice',['status'=>422]);
    if(get_post_type($rid)!=='tour-rates'||(int)get_post_meta($rid,'related_tour_id',true)!==$eid)return new WP_Error('extra_rate_mismatch','Extra Rate does not belong to selected Extra',['status'=>422]);
    $early_rates[$rid]=true;
   }
  }
  // Enforce the same taxonomies, date and private-price authority as Pricing Engine v2.
  $selected=['package_term_id'=>$package];if($guide)$selected['guide_language_term_id']=$guide;
  if($property)$selected['property_id']=$property;
  if($season_term!==null)$selected['season_term_id']=$season_term;
  $canonical_config=['schema_version'=>2,'tour_id'=>$tour,'source'=>'rate','rate_id'=>$rate,
    'travel_date'=>$date,'audience'=>$audience,'occupancy'=>$occupancy,
    'quantities'=>$base_quantities,'selections'=>$selected,'booking_mode'=>$mode];
  if(array_key_exists('traveller_nationalities',$payload_early)){
   if(!is_array($payload_early['traveller_nationalities']))return new WP_Error('booking_passport_list_not_array','Invalid passenger list',['status'=>422]);
   $canonical_config['traveller_nationalities']=$payload_early['traveller_nationalities'];
  }
  if(array_key_exists('traveller_genders',$payload_early)){
   if(!is_array($payload_early['traveller_genders']))
    return new WP_Error('booking_gender_list_not_array','Invalid passenger gender list',['status'=>422]);
   $canonical_config['traveller_genders']=$payload_early['traveller_genders'];
  }
  $validated=shared_product_booking_v2_validate($canonical_config);
  if(is_wp_error($validated))return new WP_Error($validated->get_error_code(),'Taxonomy pricing selection not eligible',['status'=>422]);
  $selection=['quantities'=>$base_quantities,'options'=>['visual_selection'=>$visual]];
  $quote=shared_product_booking_configuration_quote($rate,$audience,$occupancy,$selection);
  if(is_wp_error($quote))return new WP_Error($quote->get_error_code(),'Selection cannot be quoted',['status'=>422]);
  $canonical_quote=shared_product_booking_v2_preview($canonical_config);
  if(is_wp_error($canonical_quote))return new WP_Error($canonical_quote->get_error_code(),'Pricing selection cannot be quoted',['status'=>422]);
  if($canonical_quote['currency']!==$quote['currency'])
   return new WP_Error('booking_preview_currency_parity','Pricing sources disagree',['status'=>422]);
  $private_receipt=$canonical_quote['lines'][0]['private_pricing']??null;
  $market_receipt=$canonical_quote['lines'][0]['market_pricing']??null;
  $market_delta=is_array($market_receipt)?
    (float)$market_receipt['adjusted_amount']-(float)$market_receipt['base_amount']:0.0;
  $preset_receipts=$canonical_quote['lines'][0]['strategy_presets']??[];
  $preset_delta=0.0;
  foreach($preset_receipts as $rule_receipt)
   $preset_delta+=(float)$rule_receipt['adjusted_amount']-(float)$rule_receipt['base_amount'];
  $group_receipt=$canonical_quote['lines'][0]['group_tier']??null;
  $group_delta=is_array($group_receipt)?
   (float)$group_receipt['adjusted_amount']-(float)$group_receipt['base_amount']:0.0;
  $package_receipt=$canonical_quote['lines'][0]['package_level']??null;
  $package_delta=is_array($package_receipt)?
   (float)$package_receipt['adjusted_amount']-(float)$package_receipt['base_amount']:0.0;
  $unadjusted_total=(float)$canonical_quote['total']-$market_delta-$preset_delta-$group_delta-$package_delta;
  if($mode!=='private' && abs($unadjusted_total-(float)$quote['total'])>0.009)
   return new WP_Error('booking_preview_base_parity','Pricing sources disagree',['status'=>422]);
  // All IDs and counts are input selections, never prices or exchange rates.
  $payload=$req->get_json_params();
  if(!is_array($payload))return new WP_Error('booking_preview_invalid_payload','JSON object required',['status'=>422]);
  foreach(['total','amount','price','currency','base_amount','exchange_rate','approved_total'] as $prohibited)if(array_key_exists($prohibited,$payload))return new WP_Error('client_amount_not_accepted','Client money values are not accepted',['status'=>422]);
  $extras=$payload['extras']??[];
  if(!is_array($extras)||count($extras)>30)return new WP_Error('invalid_booking_extras','Invalid Extras selection',['status'=>422]);
  $allowed=shared_product_allowed_optional_tour_ids($rate);
  if(is_wp_error($allowed))return new WP_Error($allowed->get_error_code(),'Extras unavailable',['status'=>422]);
  $base_amount=(float)$canonical_quote['lines'][0]['amount'];
  if($base_amount<=0)return new WP_Error('booking_preview_base_amount_invalid','Invalid base price',['status'=>422]);
  $lines=[['kind'=>'base_tour_rate','tour_id'=>$tour,'rate_id'=>$rate,'source'=>'rate',
   'currency'=>$quote['currency'],'unit'=>$quote['unit'],'quantity'=>$quote['unit_quantity'],'amount'=>$base_amount]];
  $totals=[$quote['currency']=>round((float)$canonical_quote['total'],2)];
  $seen=[];
  foreach($extras as $item){
    if(!is_array($item)||array_diff(array_keys($item),['extra_tour_id','extra_rate_id','quantities']))return new WP_Error('invalid_extra_selection','Unsupported Extra fields',['status'=>422]);
    $eid=filter_var($item['extra_tour_id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
    $erate=filter_var($item['extra_rate_id']??0,FILTER_VALIDATE_INT);
    if(!$eid||!in_array($eid,$allowed,true)||isset($seen[$eid])||$erate===false||$erate<0)return new WP_Error('invalid_or_duplicate_extra','Extra must be linked and unique',['status'=>422]);
    $seen[$eid]=true;
    $quant=$item['quantities']??[];
    $permitted=['persons','rooms','cabins','vehicles','days','trips','tables'];
    if(!is_array($quant)||array_diff(array_keys($quant),$permitted))return new WP_Error('invalid_extra_quantities','Invalid quantity fields',['status'=>422]);
    foreach($quant as $v)if(filter_var($v,FILTER_VALIDATE_INT,['options'=>['min_range'=>1,'max_range'=>999]])===false)return new WP_Error('invalid_extra_quantity','Invalid quantity',['status'=>422]);
    // Inherit by matching unit only. Persons never imply rooms, cabins or vehicles.
    $quant=array_merge($base_quantities,$quant);
    if(isset($quant['persons']) && $quant['persons']>$persons)return new WP_Error('extra_persons_exceed_booking','Extra travelers exceed booked travelers',['status'=>422]);
    $candidates=[];
    $direct=shared_product_direct_extra_quote($rate,$eid,$audience,$quant);
    if(!is_wp_error($direct))$candidates['direct']=['amount'=>$direct['total'],'currency'=>$direct['currency'],'unit'=>$direct['unit'],'quantity'=>$direct['quantity']];
    if($erate){
      $extra_season=shared_product_taxonomy_season_context($eid,$erate,$date);
      if(is_wp_error($extra_season))return new WP_Error($extra_season->get_error_code(),'Optional Tour Rate season invalid',['status'=>422]);
      $rq=shared_product_optional_rate_quote($rate,$erate,$audience,$occupancy);
      if(is_wp_error($rq)||(int)($rq['child_tour_id']??0)!==$eid)return new WP_Error('extra_rate_mismatch','Rate is not linked to selected Extra',['status'=>422]);
      $ru=shared_product_resolve_price_unit($erate);
      if(is_wp_error($ru))return new WP_Error($ru->get_error_code(),'Extra rate unit invalid',['status'=>422]);
      $extended=shared_product_unit_extended_amount($rq['quote']['display_amount']??null,$ru['unit'],$quant);
      if(!is_wp_error($extended))$candidates['rate']=['amount'=>$extended['amount'],'currency'=>$rq['quote']['display_currency'],'unit'=>$ru['unit'],'quantity'=>$extended['quantity']];
    }
    $choice=shared_product_resolve_price_sources($candidates,(string)get_post_meta($eid,'tour_mode',true));
    if(is_wp_error($choice))return new WP_Error($choice->get_error_code(),'Extra cannot be priced under active policy',['status'=>422]);
    $chosen=$candidates[$choice['source']];$ccy=$chosen['currency'];
    $totals[$ccy]=round(($totals[$ccy]??0)+(float)$chosen['amount'],2);
    $lines[]=['kind'=>'optional_extra','tour_id'=>$eid,'rate_id'=>$choice['source']==='rate'?$erate:null,'source'=>$choice['source'],'visible_sources'=>$choice['visible_sources'],'currency'=>$ccy,'unit'=>$chosen['unit'],'quantity'=>$chosen['quantity'],'amount'=>$chosen['amount']];
  }
  // No implicit FX. A scalar total is available only when every line shares one currency.
  $one=count($totals)===1;
  return rest_ensure_response(['rate_id'=>$rate,'tour_id'=>$tour,'configuration'=>$selection,'lines'=>$lines,'totals_by_currency'=>$totals,'currency'=>$one?array_key_first($totals):null,'total'=>$one?reset($totals):null,'mixed_currency'=>!$one,'unit_quantity'=>$quote['unit_quantity'],'taxonomy_pricing'=>['booking_mode'=>$mode,'allowed_modes'=>$validated['booking_taxonomy']['modes'],
   'season'=>$validated['travel_season'],'guide_language_term_id'=>$guide?:null,
   'guide_language_tier'=>$guide&&function_exists('BookingExperience\\pricing_preset_language_tier')?\BookingExperience\pricing_preset_language_tier($guide):null,
   'group_tier'=>$group_receipt,'package_level'=>$package_receipt,
   'private_pricing'=>$private_receipt,
     'market_pricing'=>$market_receipt,'strategy_presets'=>$preset_receipts,
     'market_context'=>shared_product_market_current_context()],
   'availability_status'=>$canonical_quote['availability_status']??'not_checked_price_only',
   'inventory_required_for_price'=>false,'checkout_ready'=>false,
   'status'=>'lab_preview_only_no_cart_or_payment']);

 }]);});
add_shortcode('rate_booking_preview',static function($atts){
 $a=shortcode_atts(['tour_id'=>0],$atts);$tour=absint($a['tour_id']);if(!$tour)$tour=get_the_ID();
 if(get_post_type($tour)!=='tours-and-activities')return '';
 $available_modes=shared_product_booking_modes_from_taxonomy($tour);
 $available_modes=is_wp_error($available_modes)?[]:$available_modes['modes'];
 $uid='rate-booking-preview-'.wp_generate_uuid4();$endpoint=rest_url('shared-product/v1/booking-preview');$season_map=[];foreach(get_terms(['taxonomy'=>'season','hide_empty'=>false]) as $term)if($term instanceof WP_Term)$season_map[$term->term_id]=$term->slug;
 ob_start();?>
 <section id="<?php echo esc_attr($uid); ?>" class="rate-lab-booking-preview" data-tour="<?php echo esc_attr($tour); ?>" data-endpoint="<?php echo esc_url($endpoint); ?>" data-season-map="<?php echo esc_attr(wp_json_encode($season_map)); ?>">
 <h3>Booking price preview</h3><p>Choose a Tour Rate from the results above. Lab preview only; checkout is disabled.</p>
 <label>Rate <select name="rate_id"><option value="">Select a rate</option></select></label>
 <label>Package <select name="package_term_id"><option value="">Select package</option></select></label>
 <label>Guide language <select name="guide_language_term_id"><option value="">Base guide / no preference</option>
 <?php $assigned_guides=wp_get_post_terms($tour,'guide-languages');
 if(is_array($assigned_guides))foreach($assigned_guides as $g): ?>
 <option value="<?php echo (int)$g->term_id; ?>"><?php echo esc_html($g->name); ?></option>
 <?php endforeach; ?></select></label>
 <label>Hotel <select name="property_id"><option value="">Select hotel</option></select></label>
 <label>Travelers <input name="persons" type="number" min="1" max="999" value="2"></label>
 <details class="market-passport-selection"><summary>Traveller passport nationalities (needed for nationality-specific pricing)</summary>
 <p>Choose each traveller's declared passport country. Passport eligibility must be verified before checkout; this is a price preview only.</p>
 <div class="market-passport-fields">
 <?php if(class_exists('ETG_Traveller_Nationality_Switcher'))echo ETG_Traveller_Nationality_Switcher::selector('traveller_nationalities[0]','',false); ?>
 </div></details>
 <details class="traveller-gender-selection"><summary>Gender (optional; traveller details)</summary>
 <p>Optional self-declared information; does not affect prices or booking eligibility.</p><div class="traveller-gender-fields">
 <label>Traveller 1 <select name="traveller_genders[0]" aria-label="Traveller 1 gender">
 <?php foreach(shared_product_booking_gender_choices() as $key=>$label): ?>
 <option value="<?php echo esc_attr($key); ?>"<?php echo selected($key,'unspecified',false); ?>><?php echo esc_html($label); ?></option>
 <?php endforeach; ?></select></label></div></details>
 <p class="shared-booking-arrival-source" aria-live="polite">Arrival Date: <strong data-arrival-source-value>Choose Arrival Date in the booking form</strong></p>
 <input name="travel_date" type="hidden" value="">
 <label>Booking mode <select name="booking_mode" aria-label="Booking mode"><option value="">Automatic (base Rate)</option>
 <?php foreach($available_modes as $available_mode): ?>
 <option value="<?php echo esc_attr($available_mode); ?>"><?php echo esc_html(ucfirst($available_mode)); ?></option>
 <?php endforeach; ?>
 </select></label>
 <details><summary>Other booking units (only when needed)</summary>
 <?php foreach(['rooms'=>'Rooms','cabins'=>'Cabins','vehicles'=>'Vehicles','days'=>'Days','trips'=>'Trips','tables'=>'Tables'] as $key=>$caption): ?>
 <label><?php echo esc_html($caption); ?> <input name="<?php echo esc_attr($key); ?>" type="number" min="1" max="999" placeholder="Not selected"></label>
 <?php endforeach; ?></details>
 <label>Audience <select name="audience"><option value="general">General</option><option value="egyptians">Egyptians</option></select></label>
 <label>Occupancy <select name="occupancy"><option value="double">Double</option><option value="single">Single</option><option value="triple">Triple</option></select></label>
 <button type="button" class="rate-preview-quote">Preview server price</button><output aria-live="polite"></output>
 </section>
 <script>(function(){const root=document.getElementById(<?php echo wp_json_encode($uid); ?>);if(!root)return;const by=n=>root.querySelector('[name="'+n+'"]');const out=root.querySelector('output');let items=[];let requestVersion=0;let quoteVersion=0;let quoteTimer=null;
 const stateKey='shared-lab-booking-v2:'+root.dataset.tour;
 // The existing Fluent Form #6 field named datetime is the SINGLE arrival
 // authority for this read-only price preview. No Departure Date UI exists.
 let activeArrivalForm=null;
 const arrivalLabel=root.querySelector('[data-arrival-source-value]');
 const fluentForms=()=>[...document.querySelectorAll('form.fluent_form_6')];
 function fluentArrivalISO(raw){
  const text=String(raw??'').trim();if(!text)return '';
  let year,month,day;
  let parts=/^([0-9]{4})-([0-9]{2})-([0-9]{2})$/.exec(text);
  if(parts){year=Number(parts[1]);month=Number(parts[2]);day=Number(parts[3]);}
  else{
   parts=/^([0-9]{1,2})-([a-zA-Z]{3})-([0-9]{2,4})$/.exec(text);
   if(!parts)return '';
   const months={jan:1,feb:2,mar:3,apr:4,may:5,jun:6,jul:7,aug:8,sep:9,oct:10,nov:11,dec:12};
   month=months[parts[2].toLowerCase()]||0;
   day=Number(parts[1]);year=Number(parts[3]);
   if(parts[3].length===2)year+=2000;
  }
  if(year<2000||year>2100||month<1||month>12||day<1||day>31)return '';
  const iso=[year,String(month).padStart(2,'0'),String(day).padStart(2,'0')].join('-');
  const date=new Date(Date.UTC(year,month-1,day));
  return date.getUTCFullYear()===year&&date.getUTCMonth()===month-1&&date.getUTCDate()===day?iso:'';
 }
 function syncArrivalFromFluent(){
  const candidates=fluentForms();
  if(!candidates.length){by('travel_date').value='';if(arrivalLabel)arrivalLabel.textContent='Booking form unavailable';return false;}
  const form=activeArrivalForm?.isConnected?activeArrivalForm:
   candidates.find(f=>String(f.querySelector('[name="datetime"]')?.value||'').trim())||candidates[0];
  const raw=form.querySelector('[name="datetime"]')?.value||'';
  const parsed=fluentArrivalISO(raw);
  const changed=by('travel_date').value!==parsed;
  by('travel_date').value=parsed;
  if(arrivalLabel)arrivalLabel.textContent=parsed?parsed:(raw?'Invalid Arrival Date in booking form':'Choose Arrival Date in booking form');
  // Keep the preview people count aligned with the same Fluent Form instance.
  const names=['no_of_adults','no_of_children','no_of_infants'];
  const counts=names.map(name=>String(form.querySelector('[name="'+name+'"]')?.value??''));
  if(counts.every(v=>/^(0|[1-9][0-9]{0,2})$/.test(v))){
   const people=counts.reduce((sum,value)=>sum+Number(value),0);
   if(people>=1&&people<=50&&Number(by('persons').value)!==people){
    by('persons').value=String(people);syncPassports();syncGenders();renderExtras();
   }
  }
  return changed;
 }
 function onFluentArrivalChange(event){
  const form=event.target?.closest?.('form.fluent_form_6');
  if(!form)return;
  if(!['datetime','no_of_adults','no_of_children','no_of_infants'].includes(event.target?.name))return;
  activeArrivalForm=form;
  syncArrivalFromFluent();
  quote();
 }
 document.addEventListener('input',onFluentArrivalChange);
 document.addEventListener('change',onFluentArrivalChange);
 document.addEventListener('focusin',event=>{
  const form=event.target?.closest?.('form.fluent_form_6');
  if(form)activeArrivalForm=form;
 });
 const passportFields=root.querySelector('.market-passport-fields');
 function syncPassports(){
  if(!passportFields)return;
  const count=Math.min(20,Math.max(1,Number(by('persons').value)||1));
  const template=passportFields.querySelector('select');
  if(!template)return;
  while(passportFields.querySelectorAll('select').length<count){
   const clone=template.cloneNode(true);clone.value='';passportFields.appendChild(clone);
  }
  while(passportFields.querySelectorAll('select').length>count){
   passportFields.lastElementChild?.remove();
  }
  passportFields.querySelectorAll('select').forEach((select,i)=>{
   select.name='traveller_nationalities['+i+']';
   select.setAttribute('aria-label','Traveller '+(i+1)+' passport country');
  });
 }
 syncPassports();
 function passportChoices(){
  if(!passportFields)return null;
  const people=Number(by('persons').value);
  if(!Number.isInteger(people)||people<1||people>20)return null;
  const selectors=[...passportFields.querySelectorAll('select')];
  if(selectors.length!==people||selectors.some(x=>!x.value))return null;
  return selectors.map(x=>x.value);
 }

 const genderFields=root.querySelector('.traveller-gender-fields');
 function syncGenders(){
  const template=genderFields?.querySelector('select');if(!template)return;
  const count=Math.min(20,Math.max(1,Number(by('persons').value)||1));
  while(genderFields.querySelectorAll('select').length<count){
   const label=document.createElement('label'),field=template.cloneNode(true);
   field.value='unspecified';label.append(field);genderFields.append(label);
  }
  while(genderFields.querySelectorAll('select').length>count)genderFields.lastElementChild?.remove();
  genderFields.querySelectorAll('select').forEach((field,i)=>{
   field.name='traveller_genders['+i+']';field.setAttribute('aria-label','Traveller '+(i+1)+' gender');
   const label=field.parentElement;label.replaceChildren(document.createTextNode('Traveller '+(i+1)+' '),field);
  });
 }
 function genderChoices(){
  const count=Number(by('persons').value);
  if(!genderFields||!Number.isInteger(count)||count<1||count>20)return null;
  const fields=[...genderFields.querySelectorAll('select')];
  if(fields.length!==count)return null;
  const values=fields.map(field=>field.value||'unspecified');
  return values.some(value=>value!=='unspecified')?values:null;
 }
 syncGenders();
 const fieldNames=['persons','booking_mode','rooms','cabins','vehicles','days','trips','tables','audience','occupancy','package_term_id','property_id','guide_language_term_id'];
 let restoration=null;let restoring=false;
 try{
  const raw=sessionStorage.getItem(stateKey);
  if(raw){const data=JSON.parse(raw);if(data&&data.schema===2&&data.tour===Number(root.dataset.tour)&&Date.now()-data.savedAt<86400000)restoration=data;else sessionStorage.removeItem(stateKey);}
 }catch(e){restoration=null;}
 const saveState=()=>{
  if(restoring)return;
  try{const fields={};fieldNames.forEach(n=>{fields[n]=by(n).value;});
   sessionStorage.setItem(stateKey,JSON.stringify({schema:2,tour:Number(root.dataset.tour),savedAt:Date.now(),rateId:by('rate_id').value,fields,extras:Array.from(extrasSelected,([id,item])=>({id,persons:item.persons,units:item.units,rateId:item.rateId}))}));
  }catch(e){}
 };
 const restoreFields=()=>{
  if(!restoration)return;
  for(const n of fieldNames){if(n==='package_term_id'||n==='property_id')continue;
   const v=restoration.fields?.[n];const el=by(n);if(v===undefined||!el)continue;
   if(el.tagName==='SELECT'){if([...el.options].some(o=>o.value===v))el.value=v;}
   else if(v===''||Number.isInteger(Number(v))&&Number(v)>=1&&Number(v)<=(999))el.value=v;
  }
 };

 const quote=()=>{
   syncArrivalFromFluent();
   quoteVersion++;if(quoteTimer!==null)clearTimeout(quoteTimer);
   saveState();out.textContent='';
   document.dispatchEvent(new CustomEvent('shared-booking-price-preview',{detail:{tourId:Number(root.dataset.tour),paymentPreview:null}}));
   root.querySelectorAll('.extra-server-line').forEach(x=>x.remove());
   if(Number(by('rate_id').value)>0 && Number(by('package_term_id').value)>0 &&
      !!by('travel_date').value &&
      Number.isInteger(Number(by('persons').value)) && Number(by('persons').value)>=1 && Number(by('persons').value)<=50)
     quoteTimer=setTimeout(()=>{quoteTimer=null;root.querySelector('.rate-preview-quote').click();},200);
 };
 function fill(select,rows,placeholder){const val=select.value;select.replaceChildren(new Option(placeholder,''));rows.forEach(r=>select.add(new Option(r.name||r.label,String(r.id||r.value))));if([...select.options].some(o=>o.value===val))select.value=val;}
 function getFilters(){const filters={};const page=root.closest('.elementor')||document;const read=name=>page.querySelector('.jet-smart-filters-select select[name="'+name+'"], [data-filter-id] select[name="'+name+'"]')?.value||'';const tier=read('related_package_term_id');const program=read('related_program_term_id');const season=read('season');if(tier)filters.package_term_id=tier;if(program)filters.program_id=program;if(season){const mapped=JSON.parse(root.dataset.seasonMap||"{}");filters.season=mapped[season]||season;}return filters;}
 async function load(){const request=++requestVersion;const p=new URLSearchParams({tour_id:root.dataset.tour,...getFilters()});try{const res=await fetch(<?php echo wp_json_encode(rest_url('shared-product/v1/tour-rate-search')); ?>+'?'+p);if(!res.ok)throw Error('rates');const data=await res.json();if(request!==requestVersion)return;items=(data.results||[]).filter(x=>x.to && x.to>=new Date().toISOString().slice(0,10));fill(by('rate_id'),items.map(x=>({id:x.rate_id,name:x.name+' (#'+x.rate_id+')'})) ,'Select a rate');
 if(restoration){
   restoring=true;restoreFields();
   const target=String(restoration.rateId||'');
   if(items.some(x=>String(x.rate_id)===target)){
     by('rate_id').value=target;
     const allowedExtras=new Set((items.find(x=>String(x.rate_id)===target)?.related_options?.optional_tours||[]).map(x=>Number(x.id)));
     for(const x of (Array.isArray(restoration.extras)?restoration.extras:[])){
       if(!Number.isSafeInteger(x.id)||x.id<1||!allowedExtras.has(x.id)||extrasSelected.has(x.id))continue;
       const validPerson=x.persons===null||(Number.isInteger(x.persons)&&x.persons>=1&&x.persons<=Number(by('persons').value));
       const units={};for(const k of ['rooms','cabins','vehicles','days','trips','tables'])if(Number.isInteger(x.units?.[k])&&x.units[k]>=1&&x.units[k]<=999)units[k]=x.units[k];
       extrasSelected.set(x.id,{persons:validPerson?x.persons:null,units,rateId:0,parentRate:target});
       document.dispatchEvent(new CustomEvent('shared-extra-selection-change',{detail:{extraTourId:x.id,selected:true,source:'booking-preview-restore'}}));
     }
   }else{extrasSelected.clear();by('rate_id').value='';}
   restoration=null;restoring=false;
 }
 updateRate();}catch(e){if(request===requestVersion){items=[];fill(by('rate_id'),[],'Select a rate');updateRate();out.textContent='Unable to load matching rates.';}}}
 function updateRate(){
 const active=by('rate_id').value;
 const activeEntry=items.find(x=>String(x.rate_id)===active);
 const eligible=new Set((activeEntry?.related_options?.optional_tours||[]).map(x=>Number(x.id)));
 extrasSelected.forEach((item,id)=>{
   if(!eligible.has(id)){
     extrasSelected.delete(id);
     document.dispatchEvent(new CustomEvent('shared-extra-selection-change',{detail:{extraTourId:id,selected:false,source:'booking-preview'}}));
   }else if(item.parentRate!==active){item.rateId=0;item.parentRate=active;}
 });
 renderExtras();const item=items.find(x=>String(x.rate_id)===by('rate_id').value);const ids=item?.package_term_id?[{id:item.package_term_id,name:item.package_name||item.package_label||('Package '+item.package_term_id)}]:[];fill(by('package_term_id'),ids,'Select package');fill(by('property_id'),item?.related_options?.properties||[],'Select hotel');
 if(restoration&&restoration.rateId===active){for(const n of ['package_term_id','property_id']){const v=restoration.fields?.[n];if(v&&[...by(n).options].some(o=>o.value===v))by(n).value=v;}}
 quote();syncRateButtons();}
 const extrasSelected=new Map();
 const extrasPanel=document.createElement('div');extrasPanel.className='shared-booking-extra-lines';extrasPanel.setAttribute('aria-live','polite');out.after(extrasPanel);
 function renderExtras(){extrasPanel.replaceChildren();extrasSelected.forEach((item,id)=>{
 const line=document.createElement('div');line.textContent='Extra #'+id+' — ';
 const label=document.createElement('label');label.textContent='Travelers for this Extra (optional) ';
 const inp=document.createElement('input');inp.type='number';inp.min='1';inp.max='50';inp.placeholder='Inherits booked travelers';inp.value=String(item.persons===null?by('persons').value:item.persons);inp.dataset.inheritance=item.persons===null?'auto':'manual';inp.title=item.persons===null?'Automatic: follows booking travelers':'Manual override';
 inp.addEventListener('change',()=>{const n=Number(inp.value),parent=Number(by('persons').value);
 if(Number.isInteger(n)&&n>=1&&n<=parent){item.persons=n;inp.dataset.inheritance='manual';inp.title='Manual override';quote();}
 else {inp.value=String(item.persons===null?parent:item.persons);out.textContent='Extra travelers must be from 1 to booked travelers.';}
 });const auto=document.createElement('button');auto.type='button';auto.textContent='Use booking count';auto.title='Restore automatic inheritance';auto.addEventListener('click',()=>{item.persons=null;renderExtras();quote();});line.append(auto);
 label.append(inp);line.append(label);
 const remove=document.createElement('button');remove.type='button';remove.className='shared-extra-remove';remove.textContent='Remove Extra';remove.setAttribute('aria-label','Remove Extra #'+id);
 remove.addEventListener('click',()=>{
   if(!extrasSelected.has(id))return;
   extrasSelected.delete(id);
   document.dispatchEvent(new CustomEvent('shared-extra-selection-change',{detail:{extraTourId:id,selected:false,source:'booking-preview'}}));
   renderExtras();quote();
   if(!(Number(by('rate_id').value)>0&&Number(by('package_term_id').value)>0))
     out.textContent='Extra removed. Select a Tour Rate and package to calculate the updated total.';
 });
 line.append(remove);
 const rateLabel=document.createElement('label');rateLabel.textContent=' Tour Rate ';
 const rates=document.createElement('select');rates.add(new Option('No Tour Rate selected (Direct or policy fallback)', '0'));rates.value=String(item.rateId);
 rates.addEventListener('change',()=>{item.rateId=Number(rates.value)||0;quote();});rateLabel.append(rates);line.append(rateLabel);
 const more=document.createElement('details');more.innerHTML='<summary>Override other unit quantities</summary>';
 ['rooms','cabins','vehicles','days','trips','tables'].forEach(key=>{const wrap=document.createElement('label');wrap.textContent=key+' ';const field=document.createElement('input');field.type='number';field.min='1';field.max='999';field.placeholder='Inherit from booking';field.value=item.units[key]??'';field.addEventListener('change',()=>{if(!field.value){delete item.units[key];quote();return;}const n=Number(field.value);if(Number.isInteger(n)&&n>0&&n<=999){item.units[key]=n;quote();}else out.textContent='Invalid unit quantity.';});wrap.append(field);more.append(wrap);});line.append(more);extrasPanel.append(line);
 const url=new URL(<?php echo wp_json_encode(rest_url('shared-product/v1/tour-rate-search')); ?>);url.searchParams.set('tour_id',String(id));
 fetch(url).then(r=>r.ok?r.json():Promise.reject()).then(data=>{if(extrasSelected.get(id)!==item)return;(data.results||[]).forEach(r=>{if(Number.isSafeInteger(Number(r.rate_id))&&Number(r.rate_id)>0)rates.add(new Option((r.name||'Rate')+' (#'+r.rate_id+')',String(r.rate_id)));});rates.value=String(item.rateId);}).catch(()=>{rateLabel.title='Rates unavailable';});
 });}
 document.addEventListener('shared-extra-selection-change',e=>{const id=Number(e.detail?.extraTourId);if(!Number.isSafeInteger(id)||id<1)return;if(e.detail?.tourId&&String(e.detail.tourId)!==root.dataset.tour)return;if(e.detail.selected){if(!extrasSelected.has(id))extrasSelected.set(id,{persons:null,units:{},rateId:0,parentRate:by('rate_id').value});}else extrasSelected.delete(id);renderExtras();quote();});
 const observed=Array.from(document.querySelectorAll('[data-filter-id]'));observed.forEach(el=>el.addEventListener('change',()=>{by('rate_id').value='';load();}));
 document.addEventListener('jet-smart-filters/inited',load);
 function syncRateButtons(){const selectedId=by('rate_id').value;document.querySelectorAll('[data-rate-actions]').forEach(group=>{const selected=!!selectedId&&group.dataset.rateActions===selectedId;group.dataset.selected=selected?'true':'false';const select=group.querySelector('[data-rate-booking-rate-id]'),remove=group.querySelector('[data-rate-remove-rate-id]');if(select){select.hidden=selected;select.setAttribute('aria-pressed',String(selected));}if(remove)remove.hidden=!selected;});}
 document.addEventListener('jet-filter-content-rendered',()=>{syncRateButtons();renderExtras();});
 document.addEventListener('jet-smart-filters/after-filtering',()=>{load();});
 document.addEventListener('rate-selection-request',e=>{const id=String(e.detail?.rateId||'');if(!items.some(x=>String(x.rate_id)===id))return;if(e.detail.remove && by('rate_id').value!==id)return;by('rate_id').value=e.detail.remove?'':id;updateRate();if(!e.detail.remove)root.scrollIntoView({behavior:'smooth',block:'nearest'});});
 by('rate_id').addEventListener('change',updateRate);
 by('persons').addEventListener('change',()=>{const n=Number(by('persons').value);
 if(!Number.isInteger(n)||n<1||n>50){out.textContent='Booking travelers must be between 1 and 50.';return;}
 extrasSelected.forEach(item=>{if(item.persons!==null&&item.persons>n)item.persons=n;});syncPassports();syncGenders();renderExtras();quote();});
 ['package_term_id','property_id','guide_language_term_id','rooms','cabins','vehicles','days','trips','tables','audience','occupancy','travel_date','booking_mode'].forEach(n=>by(n).addEventListener('change',()=>{renderExtras();quote();}));
 passportFields?.addEventListener('change',quote);
 genderFields?.addEventListener('change',quote);
 root.querySelector('button').addEventListener('click',async()=>{
 syncArrivalFromFluent();
 if(!by('travel_date').value){out.textContent='Select a valid Arrival Date in Fluent Form before previewing the price.';return;}
 const rate_id=Number(by('rate_id').value),package_term_id=Number(by('package_term_id').value),persons=Number(by('persons').value);if(!rate_id||!package_term_id||!Number.isInteger(persons)||persons<1||persons>999){out.textContent='Select an eligible rate, package and traveler count.';return;}out.textContent='Calculating…';const quoteRequest=++quoteVersion;try{const res=await fetch(root.dataset.endpoint,{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({tour_id:Number(root.dataset.tour),rate_id,package_term_id,property_id:Number(by('property_id').value)||0,guide_language_term_id:Number(by('guide_language_term_id').value)||0,persons,
  travel_date:by('travel_date').value||null,booking_mode:by('booking_mode').value||null,
  ...(passportChoices()?{traveller_nationalities:passportChoices()}:{}),
  ...(genderChoices()?{traveller_genders:genderChoices()}:{}),
  audience:by('audience').value,occupancy:by('occupancy').value,...Object.fromEntries(['rooms','cabins','vehicles','days','trips','tables'].filter(k=>by(k).value!=='').map(k=>[k,Number(by(k).value)])),extras:Array.from(extrasSelected,([id,item])=>({extra_tour_id:id,extra_rate_id:item.rateId,quantities:{...item.units,...(item.persons===null?{}:{persons:item.persons})}}))})});const data=await res.json();if(quoteRequest!==quoteVersion)return;out.textContent=res.ok?(Object.entries(data.totals_by_currency||{}).map(([c,v])=>c+' '+Number(v).toFixed(2)).join(' | ')
  +(data.taxonomy_pricing?.season?.slug?' · Season: '+data.taxonomy_pricing.season.slug:'')
  +(data.taxonomy_pricing?.booking_mode?' · '+data.taxonomy_pricing.booking_mode:'')
  +(data.mixed_currency?' — currencies not converted':'')+' — Read-only quote'):('Unavailable: '+(data.code||'invalid selection'));if(res.ok){
  document.dispatchEvent(new CustomEvent('shared-booking-price-preview',{detail:{
   tourId:Number(root.dataset.tour),paymentPreview:data.payment_preview||null
  }}));
  extrasPanel.querySelectorAll('.extra-server-line').forEach(x=>x.remove());(data.lines||[]).filter(x=>x.kind==='optional_extra').forEach(x=>{const el=document.createElement('p');el.className='extra-server-line';el.textContent='Extra #'+x.tour_id+' • '+x.source+' • '+x.quantity+' × '+x.unit+' • '+x.currency+' '+Number(x.amount).toFixed(2);extrasPanel.append(el);});}}catch(e){if(quoteRequest===quoteVersion)out.textContent='Unable to calculate preview.';}});syncArrivalFromFluent();load().catch(()=>{out.textContent='Unable to load rates.';});})();</script>
 <?php return ob_get_clean();
});
