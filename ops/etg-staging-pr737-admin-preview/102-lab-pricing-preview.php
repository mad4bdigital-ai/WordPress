<?php
/** Read-only Pricing Engine v2 preview on the original JetEngine Options Page. */
if (!defined('ABSPATH') || !defined('MAD4B_COMMERCE_RUNTIME_ENABLED') || !MAD4B_COMMERCE_RUNTIME_ENABLED) return;
function lab_pricing_preview_authorized(){return current_user_can('manage_options') && isset($_REQUEST['etg_pricing_preview']);}
add_action('wp_ajax_etg_lab_pricing_preview',static function(){
 if(!current_user_can('manage_options'))wp_send_json_error(['code'=>'forbidden'],403);
 check_ajax_referer('etg_lab_pricing_preview','nonce');
 $decoded=lab_preview_decode($_POST);
 if(is_wp_error($decoded))wp_send_json_error(['code'=>$decoded->get_error_code(),'field'=>$decoded->get_error_message()],422);
 $tour=absint($_POST['tour_id']??0);$rate=absint($_POST['rate_id']??0);
 $persons=absint($_POST['persons']??0);
 $source=isset($_POST['source'])?sanitize_key(wp_unslash($_POST['source'])):'rate';
 $date=isset($_POST['travel_date'])?sanitize_text_field(wp_unslash($_POST['travel_date'])):'';
 $audience=isset($_POST['audience'])?sanitize_key(wp_unslash($_POST['audience'])):'general';
 $occupancy=isset($_POST['occupancy'])?sanitize_key(wp_unslash($_POST['occupancy'])):'double';
 if($persons<1||$persons>50||!in_array($source,['rate','direct'],true))wp_send_json_error(['code'=>'invalid_input'],400);
 $config=['schema_version'=>2,'tour_id'=>$tour,'source'=>$source,'rate_id'=>$source==='rate'?$rate:null,'travel_date'=>$date!==''?$date:null,'audience'=>$audience,'occupancy'=>$occupancy,'quantities'=>['persons'=>$persons]];
 $mapped=lab_preview_booking_map($decoded);
 if(is_wp_error($mapped))wp_send_json_error(['code'=>$mapped->get_error_code()],422);
 $config=array_replace_recursive($config,$mapped);
 if(!function_exists('shared_product_pricing_quote_contract_v2'))wp_send_json_error(['code'=>'quote_engine_unavailable'],503);
 $quote=shared_product_pricing_quote_contract_v2($config);
 if(is_wp_error($quote))wp_send_json_error(['code'=>$quote->get_error_code(),'message'=>$quote->get_error_message()],422);
 $finance=null;if($source==='rate' && function_exists('shared_product_financial_policy_resolve')){
  $f=shared_product_financial_policy_resolve($rate);
  $finance=is_wp_error($f)?['error'=>$f->get_error_code()]:['effective'=>$f['effective'],'sources'=>$f['sources']];
 }
 $shared_receipt=function_exists('shared_lab_quote_envelope')?shared_lab_quote_envelope('tour',$quote):new WP_Error('shared_quote_bridge_unavailable');
 if(is_wp_error($shared_receipt))wp_send_json_error(['code'=>$shared_receipt->get_error_code()],422);
 wp_send_json_success(['shared_quote_receipt'=>$shared_receipt,'tour_id'=>$tour,'source'=>$source,'rate_id'=>$source==='rate'?$rate:null,'total'=>$quote['total'],'currency'=>$quote['currency'],'components'=>$quote['components'],'applied_rules'=>$quote['applied_rules'],'evaluator_receipts'=>$quote['evaluator_receipts'],'financial_readiness'=>$quote['financial_readiness'],'financial_policy'=>$finance,'status'=>$quote['status'],'note'=>'Saved policies only. Read-only preview: no cart, checkout, order, or policy mutation.']);
});
add_action('admin_footer',static function(){
 if(!current_user_can('manage_options')||($_GET['page']??'')!=='etg-dynamic-pricing-controls')return;
 $preview_schema=function_exists('lab_preview_schema')?lab_preview_schema():[];
 $nonce=wp_create_nonce('etg_lab_pricing_preview');$endpoint=admin_url('admin-ajax.php');
 ?>
 <style>
 #etg-quote-preview{margin:20px 0;background:#fff;border:1px solid #c3c4c7;border-left:4px solid #2271b1;padding:20px;max-width:1100px;border-radius:5px}
 #etg-quote-preview h2{margin:0 0 8px;font-size:20px}
 #etg-quote-preview .etg-quote-fields{display:grid;grid-template-columns:repeat(3,minmax(160px,1fr));gap:12px;margin:16px 0}
 #etg-quote-preview label{display:block;font-weight:600;margin-bottom:5px}
 #etg-quote-preview input,#etg-quote-preview select{width:100%;max-width:100%}
 #etg-quote-preview-output{margin-top:12px;padding:14px;background:#f6f7f7;border-radius:4px;white-space:normal}
 #etg-quote-preview .etg-muted{color:#646970}
 #etg-quote-preview table{width:100%;border-collapse:collapse;margin:8px 0}
 #etg-quote-preview td,#etg-quote-preview th{text-align:left;padding:7px;border-bottom:1px solid #dcdcde}
 @media(max-width:760px){#etg-quote-preview .etg-quote-fields{grid-template-columns:1fr}}
 </style>
 <section id="etg-quote-preview" aria-labelledby="etg-quote-heading">
 <h2 id="etg-quote-heading">Pricing preview · Live server quote</h2>
 <p class="etg-muted">Evaluate saved Pricing Engine policies against a published Tour and Rate. This is a read-only quote, not a booking or tax/fee settlement. Save policy changes above before previewing.</p>
 <form id="etg-quote-preview-form"><div class="etg-quote-fields">
 <div><label for="etg-prev-tour">Tour ID</label><input id="etg-prev-tour" name="tour_id" type="number" min="1" required placeholder="Published tour ID"></div>
 <div><label for="etg-prev-source">Price source</label><select id="etg-prev-source" name="source"><option value="rate">Tour Rate</option><option value="direct">Direct Tour price</option></select></div>
 <div><label for="etg-prev-rate">Tour Rate ID</label><input id="etg-prev-rate" name="rate_id" type="number" min="1" placeholder="Rate linked to Tour"></div>
 <div><label for="etg-prev-persons">Travelers</label><input id="etg-prev-persons" name="persons" type="number" min="1" max="50" value="3" required></div>
 <div><label for="etg-prev-date">Travel date (optional)</label><input id="etg-prev-date" name="travel_date" type="date"></div>
 <div><label for="etg-prev-audience">Audience</label><select id="etg-prev-audience" name="audience"><option value="general">General</option><option value="egyptians">Egyptians</option></select></div>
 <div><label for="etg-prev-occupancy">Occupancy</label><select id="etg-prev-occupancy" name="occupancy"><option value="double">Double</option><option value="single">Single</option><option value="triple">Triple</option></select></div>
 </div><button type="submit" class="button button-primary">Calculate preview</button> <span class="etg-muted" id="etg-prev-status" role="status" aria-live="polite"></span></form>
 <div id="etg-quote-preview-output" aria-live="polite">Select a Tour and Rate, then calculate.</div></section>
 <script>
 (function(){'use strict';
 const section=document.getElementById('etg-quote-preview'),body=document.querySelector('.wrap');if(body)body.appendChild(section);
 const form=document.getElementById('etg-quote-preview-form'),output=document.getElementById('etg-quote-preview-output'),status=document.getElementById('etg-prev-status');
 const schema=<?php echo wp_json_encode($preview_schema); ?>;
 const registryFactors=<?php echo wp_json_encode(function_exists('shared_product_pricing_rules_catalog_v2')?shared_product_pricing_rules_catalog_v2():[]); ?>;
 const fieldBox=document.querySelector('#etg-quote-preview .etg-quote-fields');
 if(fieldBox && schema && typeof schema==='object'){
  fieldBox.replaceChildren();
  for(const [key,def] of Object.entries(schema)){
   if(!/^[a-z][a-z0-9_]{1,63}$/.test(key)||!['number','select','checkbox','date','text'].includes(def.type))continue;
   const wrap=document.createElement('div'),label=document.createElement('label');label.textContent=def.label||key;
   const field=document.createElement(def.type==='select'?'select':'input');field.name=key;field.id='etg-prev-'+key.replaceAll('_','-');label.htmlFor=field.id;
   if(def.type==='select'){for(const [v,text] of Object.entries(def.options||{})){const option=document.createElement('option');option.value=v;option.textContent=text;field.appendChild(option);}}
   else{field.type=def.type; if(def.min!==undefined)field.min=def.min;if(def.max!==undefined)field.max=def.max;}
   if(def.default!==undefined)field.value=def.default;
   if(key==='tour_id'||key==='persons')field.required=true;
   if(key==='source')field.value='rate';
   if(def.show_if)wrap.dataset.showIf=JSON.stringify(def.show_if);
   wrap.append(label,field);fieldBox.append(wrap);
  }
  function refresh(){for(const child of fieldBox.children){const conditional=child.dataset.showIf;if(!conditional)continue;const rule=JSON.parse(conditional),visible=Object.entries(rule).every(([k,v])=>{const input=form.elements.namedItem(k);return input&&(input.type==='checkbox'?(input.checked?'1':'0'):input.value)===v;});child.hidden=!visible;for(const input of child.querySelectorAll('input,select'))input.disabled=!visible;}const rateInput=form.elements.namedItem('rate_id');if(rateInput)rateInput.required=!rateInput.disabled;}
  fieldBox.addEventListener('change',refresh);refresh();
 }

 const choiceFields=['tour_id','rate_id','tier_id','package_term_id','season_term_id','booking_mode','property_id','transport_id'];
 // Search the enrolled Tour CPT, not a hard-coded recent-ID fixture.
 const lookup=document.createElement('label');lookup.textContent='Find Tour (title or ID) ';
 const tourSearch=document.createElement('input');tourSearch.type='search';tourSearch.id='pricing-preview-tour-search';
 tourSearch.autocomplete='off';tourSearch.placeholder='Search any published Tour';lookup.appendChild(tourSearch);fieldBox.prepend(lookup);
 let searchTimer=null;tourSearch.addEventListener('input',()=>{if(searchTimer!==null)clearTimeout(searchTimer);searchTimer=setTimeout(()=>{searchTimer=null;refreshChoices();},250);});
 let choiceRevision=0;
 async function refreshChoices(){
  const revision=++choiceRevision,data=new FormData();data.set('action','etg_lab_preview_choices');data.set('nonce',<?php echo wp_json_encode($nonce); ?>);
  for(const key of ['tour_id','rate_id'])data.set(key,form.elements.namedItem(key)?.value||'');
  data.set('tour_query',tourSearch.value);
  try{
   const response=await fetch(<?php echo wp_json_encode($endpoint); ?>,{method:'POST',credentials:'same-origin',body:data,cache:'no-store'});
   const payload=await response.json();if(revision!==choiceRevision||!payload.success)return;
   for(const key of choiceFields){
    const old=form.elements.namedItem(key);if(!old)continue;
    let select=old;
    if(old.tagName!=='SELECT'){
     select=document.createElement('select');select.name=key;select.id=old.id;select.required=old.required;select.disabled=old.disabled;old.replaceWith(select);
    }
    const selected=select.value;select.replaceChildren();if(key!=='booking_mode'){const empty=document.createElement('option');empty.value='';empty.textContent='Select '+key.replaceAll('_',' ');select.appendChild(empty);}
    for(const item of payload.data.choices[key]||[]){const option=document.createElement('option');option.value=String(item.id);option.textContent=item.name+(key!=='booking_mode'&&item.id!==''?' (#'+item.id+')':'');select.appendChild(option);}
    if([...select.options].some(x=>x.value===selected))select.value=selected;
    else select.value='';
   }
   const rateField=form.elements.namedItem('rate_id');if(rateField)rateField.required=form.elements.namedItem('source')?.value==='rate';
  }catch(error){status.textContent='Linked choice lookup unavailable: '+error.message;}
 }
 form.addEventListener('change',event=>{if(['tour_id','rate_id'].includes(event.target.name))refreshChoices();});
 refreshChoices();
 const src=document.getElementById('etg-prev-source'),rate=document.getElementById('etg-prev-rate');
 if(src&&rate){src.addEventListener('change',()=>{rate.required=src.value==='rate';});rate.required=true;}
 function el(tag,text){const n=document.createElement(tag);n.textContent=String(text??'');return n;}
 function table(rows,heads){const t=document.createElement('table'),tr=document.createElement('tr');heads.forEach(h=>tr.appendChild(el('th',h)));t.appendChild(tr);rows.forEach(row=>{const r=document.createElement('tr');row.forEach(v=>r.appendChild(el('td',v)));t.appendChild(r);});return t;}
 form.addEventListener('submit',async e=>{e.preventDefault();if(!form.reportValidity())return;status.textContent='Calculating…';output.replaceChildren();const data=new FormData(form);data.set('action','etg_lab_pricing_preview');data.set('nonce',<?php echo wp_json_encode($nonce); ?>);if(src&&src.value==='direct')data.delete('rate_id');
 try{const response=await fetch(<?php echo wp_json_encode($endpoint); ?>,{method:'POST',credentials:'same-origin',body:data,cache:'no-store'});const j=await response.json();if(!j.success){output.appendChild(el('p','Quote unavailable: '+(j.data?.code||'unknown_error')+' '+(j.data?.message||'')));status.textContent='Preview unavailable';return;}
 const q=j.data;output.appendChild(el('h3','Total: '+q.total+' '+q.currency));output.appendChild(el('p','Read-only · Saved policies · No checkout authority'));
 output.appendChild(el('h4','Price components'));output.appendChild(table(q.components.map(x=>[x.kind,x.amount+' '+x.currency]),['Component','Amount']));
 if(q.financial_policy?.sources){output.appendChild(el('h4','Resolved financial policy & provenance'));output.appendChild(table(Object.entries(q.financial_policy.effective).map(([k,v])=>[k,v,q.financial_policy.sources[k]||'']),['Policy','Value','Resolved from']));}
 if(q.financial_policy?.error)output.appendChild(el('p','Financial policy: '+q.financial_policy.error));
 const readiness=q.financial_readiness;output.appendChild(el('h4','Readiness'));output.appendChild(el('p',readiness?.status||'Not measured'));if(readiness?.blockers?.length)output.appendChild(el('p','Blockers: '+readiness.blockers.join(', ')));
 output.appendChild(el('h4','Rule evaluations'));output.appendChild(el('pre',JSON.stringify(q.evaluator_receipts,null,2)));status.textContent='Calculated';
 }catch(err){output.appendChild(el('p','Network or response error: '+err.message));status.textContent='Preview unavailable';}
 });
 })();
 </script>
 <?php
});
