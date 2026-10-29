<?php
/** Dynamic tour rate facets: read-only Lab endpoint, never asserts inventory or checkout authority. */
if (!defined('ABSPATH') || !defined('SHARED_PRODUCT_LAB_ONLY') || !SHARED_PRODUCT_LAB_ONLY) return;
function shared_product_tour_rate_search($tour_id,$choices=[]){
 $tour_id=absint($tour_id);
 if(get_post_type($tour_id)!=='tours-and-activities'||get_post_status($tour_id)!=='publish')return new WP_Error('tour_not_available');
 if(!current_user_can('edit_posts') && (get_post_field('post_password',$tour_id)!=='' || (function_exists('service_channel_allowed') && !service_channel_allowed('tours','standalone'))))return new WP_Error('tour_not_available');
 if(!is_array($choices)||array_diff(array_keys($choices),['date','season','program_id','audience','occupancy','property_id','transport_id','extra_tour_id','package_term_id']))return new WP_Error('invalid_search_choices');
 $date=$choices['date']??'';
 if($date!=='' && (!is_string($date)||!Shared_Product_Date_Service::parse($date)))return new WP_Error('invalid_search_date');
 $season=isset($choices['season'])?sanitize_title($choices['season']):'';
 $program=absint($choices['program_id']??0);
 if($program && (!function_exists('shared_product_tour_program_allowed_v1')
    || !shared_product_tour_program_allowed_v1($tour_id,$program)))
    return new WP_Error('program_not_linked_to_cruise');
 if($season && !term_exists($season,'season'))return new WP_Error('invalid_season');
 $a=$choices['audience']??'';$o=$choices['occupancy']??'';
 if(($a!==''&&!in_array($a,['general','egyptians'],true))||($o!==''&&!in_array($o,['single','double','triple'],true)))return new WP_Error('invalid_price_filter');
 $ids=get_posts(['post_type'=>'tour-rates','post_status'=>'publish','posts_per_page'=>-1,'fields'=>'ids','meta_key'=>'related_tour_id','meta_value'=>(string)$tour_id,'suppress_filters'=>true]);
 // Shared retail eligibility owns status, Stop Sale, date validity,
 // occupancy availability, rate policy and display currency. The optional
 // finder must not advertise a Rate forbidden by Query #310 / #313.
 $eligible_ids=function_exists('shared_product_eligible_tour_rate_ids')
  ?array_fill_keys(shared_product_eligible_tour_rate_ids($tour_id),true):[];
 $requested_package=absint($choices['package_term_id']??0);
 $items=[];$facets=['seasons'=>[],'programs'=>[],'units'=>[]];
 foreach($ids as $id){
  if(!isset($eligible_ids[(int)$id]))continue;
  if((string)get_post_meta($id,'stop_sale',true)==='1')continue;
  $accommodation=shared_product_package_accommodation_options($id,$requested_package?:null);
  if(is_wp_error($accommodation)){if($requested_package)continue;$accommodation=null;}
  if($requested_package && (!$accommodation||$accommodation['package_term_id']!==$requested_package))continue;
  $start=(int)get_post_meta($id,'tour_rate_start_date',true);$end=(int)get_post_meta($id,'tour_rate_end_date',true);
  if($start<=0||$end<$start)continue;
  if($date){$stamp=Shared_Product_Date_Service::parse($date)->getTimestamp();if($stamp<$start||$stamp>$end)continue;}
  $p=absint(get_post_meta($id,'related_program_term_id',true));
  $cruise=shared_product_program_mode_supported(get_post_meta($tour_id,'tour_mode',true));
  if($cruise && (!$p || !shared_product_tour_program_allowed_v1($tour_id,$p)))continue;
  if($program && shared_product_program_canonical_term_v1($p)!==shared_product_program_canonical_term_v1($program))continue;
  if($season && !has_term($season,'season',$id))continue;
  if($a!==''&&$o!==''&&is_wp_error(shared_product_quote_snapshot($id,$a,$o)))continue;
  $unit=shared_product_resolve_price_unit($id);if(is_wp_error($unit))continue;
  foreach(wp_get_post_terms($id,'season') as $term)if(!is_wp_error($term))$facets['seasons'][$term->slug]=$term->name;
  if($cruise && $p){
    $canonical=shared_product_program_canonical_term_v1($p);
    $t=get_term($canonical,'programs');
    if($t instanceof WP_Term)
     $facets['programs'][$canonical]=shared_product_rate_facet_term_label($t);
  }
  $facets['units'][$unit['unit']]=$unit['label'];
  $items[]=['rate_id'=>(int)$id,'name'=>get_the_title($id),'unit'=>$unit['unit'],'unit_label'=>$unit['label'],'from'=>gmdate('Y-m-d',$start),'to'=>gmdate('Y-m-d',$end),'package_term_id'=>$accommodation['package_term_id']??null,'package_source'=>$accommodation['package_source']??'unresolved'];
 }
 foreach($facets as &$values)$values=array_map(static fn($key,$label)=>['value'=>(string)$key,'label'=>$label],array_keys($values),array_values($values));unset($values);
 return apply_filters('shared_product_tour_rate_search_results',['tour_id'=>$tour_id,'filters'=>$choices,'results'=>$items,'count'=>count($items),'facets'=>$facets,'availability'=>'rate_date_coverage_only','confirmed_inventory'=>false],$tour_id,$choices);
}
add_action('rest_api_init',static function(){register_rest_route('shared-product/v1','/tour-rate-search',[
 'methods'=>'GET','permission_callback'=>'__return_true',
 'args'=>['tour_id'=>['required'=>true,'type'=>'integer','minimum'=>1], 'date'=>['type'=>'string'],'season'=>['type'=>'string'],'program_id'=>['type'=>'integer'],'audience'=>['type'=>'string'],'occupancy'=>['type'=>'string'],'property_id'=>['type'=>'integer'],'transport_id'=>['type'=>'integer'],'extra_tour_id'=>['type'=>'integer'],'package_term_id'=>['type'=>'integer']],
 'callback'=>static function($req){$r=shared_product_tour_rate_search((int)$req['tour_id'],array_filter([
  'date'=>$req['date']??'','season'=>$req['season']??'','program_id'=>$req['program_id']??0,'audience'=>$req['audience']??'','occupancy'=>$req['occupancy']??'','property_id'=>$req['property_id']??0,'transport_id'=>$req['transport_id']??0,'extra_tour_id'=>$req['extra_tour_id']??0,'package_term_id'=>$req['package_term_id']??0
 ],static fn($v)=>$v!==''&&$v!==0));return is_wp_error($r)?new WP_Error($r->get_error_code(),'Rate search cannot be completed.',['status'=>$r->get_error_code()==='tour_not_available'?404:422]):rest_ensure_response($r);}
]);});
/** Opt-in Elementor Shortcode widget for dependent preview; does not mutate existing JetSmartFilters. */
add_shortcode('shared_product_tour_rate_finder',static function($atts){
 $a=shortcode_atts(['tour_id'=>0],$atts);
 $id=absint($a['tour_id']);if(!$id)$id=get_the_ID();
 if(get_post_type($id)!=='tours-and-activities'||get_post_status($id)!=='publish')return '';
 $uid='shared-rate-finder-'.wp_generate_uuid4();
 $endpoint=rest_url('shared-product/v1/tour-rate-search');
 ob_start(); ?>
 <section id="<?php echo esc_attr($uid); ?>" class="shared-rate-finder" data-tour="<?php echo esc_attr($id); ?>" data-endpoint="<?php echo esc_url($endpoint); ?>">
  <div class="shared-rate-finder-fields">
   <label><?php esc_html_e('Travel date','woocommerce'); ?><input name="date" type="date"></label>
   <label><?php esc_html_e('Season','woocommerce'); ?><select name="season"><option value=""><?php esc_html_e('All seasons','woocommerce'); ?></option></select></label>
   <?php if (shared_product_program_mode_supported(get_post_meta($id,'tour_mode',true))
   && shared_product_cruise_assigned_programs_v1($id)): ?>
  <label><?php esc_html_e('Program','woocommerce'); ?><select name="program_id"><option value=""><?php esc_html_e('All programs','woocommerce'); ?></option></select></label>
  <?php endif; ?>
   <label><?php esc_html_e('Audience','woocommerce'); ?><select name="audience"><option value=""><?php esc_html_e('All audiences','woocommerce'); ?></option><option value="general">General</option><option value="egyptians">Egyptians</option></select></label>
   <label><?php esc_html_e('Occupancy','woocommerce'); ?><select name="occupancy"><option value=""><?php esc_html_e('All occupancies','woocommerce'); ?></option><option value="single">Single</option><option value="double">Double</option><option value="triple">Triple</option></select></label>
  </div>
  <div class="shared-rate-visual-options" aria-label="Related booking choices"></div>
  <div class="shared-rate-finder-status" role="status" aria-live="polite"></div>
  <ul class="shared-rate-finder-results"></ul>
 </section>
 <style>.shared-rate-finder-fields{display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:12px}.shared-rate-finder-fields label{display:grid;gap:5px}.shared-rate-finder-fields input,.shared-rate-finder-fields select{width:100%;min-width:0}.shared-rate-finder-results{display:grid;gap:8px;margin:12px 0;padding-inline-start:18px}.shared-rate-visual-options{display:grid;gap:12px;margin-top:16px}.shared-visual-group{display:flex;gap:8px;overflow-x:auto;padding:6px}.shared-visual-choice{min-width:145px;max-width:175px;display:grid;gap:5px;border:1px solid #ccc;border-radius:10px;padding:8px;background:transparent;cursor:pointer;text-align:start}.shared-visual-choice[aria-pressed=true]{outline:2px solid #608b00}.shared-visual-choice img{width:100%;height:90px;object-fit:cover;border-radius:6px}.shared-rate-finder-status{margin-top:12px}.shared-rate-finder-results>li{overflow-wrap:anywhere;padding:10px 0}.shared-rate-option-group{font-size:.92em;margin-top:5px}@media(max-width:600px){.shared-rate-finder-fields{grid-template-columns:1fr}}</style>
 <script>(function(){const root=document.getElementById(<?php echo wp_json_encode($uid); ?>);if(!root)return;const fields=Object.fromEntries(['date','season','program_id','audience','occupancy'].map(k=>[k,root.querySelector('[name="'+k+'"]')]).filter(([,el])=>!!el));let version=0;const chosen={property_id:'',transport_id:'',extra_tour_id:''};const visual=root.querySelector('.shared-rate-visual-options');const status=root.querySelector('.shared-rate-finder-status'),list=root.querySelector('.shared-rate-finder-results');
 function drawOptions(data){visual.replaceChildren();for(const [field,label,group] of [['property_id','Hotels','properties'],['transport_id','Transportation','transportations'],['extra_tour_id','Extra activities','extras']]){const entries=data.visual_options?.[group]||[];if(!entries.length && !chosen[field])continue;const heading=document.createElement('strong');heading.textContent=label;visual.append(heading);const cards=document.createElement('div');cards.className='shared-visual-group';if(chosen[field]){const clear=document.createElement('button');clear.type='button';clear.className='shared-visual-choice';clear.textContent='Clear '+label;clear.addEventListener('click',()=>{chosen[field]='';window.dispatchEvent(new CustomEvent('shared-booking-visual-change',{detail:{tourId:root.dataset.tour,field,value:''}}));refresh();});cards.append(clear);}for(const value of entries){const btn=document.createElement('button');btn.type='button';btn.className='shared-visual-choice';btn.dataset.optionType=field;btn.dataset.optionId=value.id;btn.setAttribute('aria-pressed',String(chosen[field]===String(value.id)));if(value.image){const img=document.createElement('img');img.src=value.image;img.alt='';img.loading='lazy';btn.append(img);}const name=document.createElement('span');name.textContent=value.name;btn.append(name);btn.addEventListener('click',()=>{chosen[field]=chosen[field]===String(value.id)?'':String(value.id);window.dispatchEvent(new CustomEvent('shared-booking-visual-change',{detail:{tourId:root.dataset.tour,field,value:chosen[field]}}));refresh();});cards.append(btn);}visual.append(cards);}}
 window.addEventListener('shared-booking-visual-change',event=>{const d=event.detail||{};if(d.tourId!==root.dataset.tour)return;if(!(d.field in chosen))return;if(chosen[d.field]===d.value)return;chosen[d.field]=d.value;refresh();});function updateSelect(el,options,blank){const wanted=el.value;el.replaceChildren(new Option(blank,''));for(const opt of options||[])el.add(new Option(opt.label,opt.value));if([...el.options].some(o=>o.value===wanted))el.value=wanted;else el.value='';}
 async function refresh(){const n=++version;const params=new URLSearchParams({tour_id:root.dataset.tour});for(const [k,el] of Object.entries(fields))if(el.value)params.set(k,el.value);for(const [k,v] of Object.entries(chosen))if(v)params.set(k,v);status.textContent='Checking rate date coverage…';try{const res=await fetch(root.dataset.endpoint+'?'+params,{credentials:'same-origin'});if(!res.ok)throw Error('invalid_filter');const data=await res.json();if(n!==version)return;updateSelect(fields.season,data.facets?.seasons,'All seasons');if(fields.program_id)updateSelect(fields.program_id,data.facets?.programs,'All programs');drawOptions(data);list.replaceChildren();for(const item of data.results||[]){const li=document.createElement('li');li.dataset.rateId=item.rate_id;const title=document.createElement('strong');title.textContent=item.name+' · '+item.unit_label+' · '+item.from+' – '+item.to;li.append(title);const options=item.related_options||{};const groups=[['Hotels',options.properties],['Extra tours',options.optional_tours],...Object.entries(options.transportation||{}).map(([kind,items])=>['Transport: '+kind,items])];for(const [label,values] of groups){if(!Array.isArray(values)||!values.length)continue;const block=document.createElement('div');block.className='shared-rate-option-group';const heading=document.createElement('span');heading.textContent=label+': ';block.append(heading);values.forEach((value,i)=>{if(i)block.append(document.createTextNode(', '));const el=document.createElement('span');el.textContent=value.name;el.dataset.optionId=value.id;block.append(el);});li.append(block);}list.append(li);}status.textContent=data.count+' rate(s) cover these conditions; inventory is not confirmed.';}catch(e){if(n===version){status.textContent='Unable to load matching rates.';list.replaceChildren();}}}Object.values(fields).forEach(el=>el.addEventListener('change',refresh));refresh();})();</script>
 <?php return ob_get_clean();
});
