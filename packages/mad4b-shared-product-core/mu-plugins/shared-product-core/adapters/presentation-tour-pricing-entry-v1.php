<?php
/** Local-Lab-only Tour creation pricing entry + seed-rate simulator (never prices or writes Rates). */
defined('ABSPATH') || exit;
if (wp_get_environment_type()!=='local'||!defined('SHARED_PRODUCT_LAB_ONLY')||!SHARED_PRODUCT_LAB_ONLY)return;
final class Shared_Tour_Pricing_Entry_v1 {
 const MODE='_shared_tour_price_entry_mode_v1';
 const FACTORS='_shared_tour_price_seed_factors_v1';
 const OPTIONS=['auto_by_type'=>'Auto from tour type','direct_per_person'=>'Direct selling price / person',
   'seed_rate_simulation'=>'Seed → simulate Single / Double / Triple',
   'existing_rates'=>'Use existing native Tour Rate(s)','inquiry'=>'Request / inquiry only'];
 const KEYS=['half_day'=>'shared_tour_entry_half_day','day'=>'shared_tour_entry_day',
  'activity'=>'shared_tour_entry_activity','extra_trip'=>'shared_tour_entry_extra_trip',
  'package'=>'shared_tour_entry_package',
  'cruise'=>'shared_tour_entry_cruise','other'=>'shared_tour_entry_other'];
 static function classify(int $id):string {
  $mode=(string)get_post_meta($id,'tour_mode',true);
  // Extra-trip is a real tour_mode, not an Activity taxonomy term.
  // Keep it distinct even when the optional excursion is also a day/half-day.
  if($mode==='extra_trip')return 'extra_trip';
  if(has_term('half-day','tour-types',$id)||has_term('half-day','duration-options',$id))return 'half_day';
  if(has_term('day-tours','tour-types',$id)||has_term('1-day','duration-options',$id))return 'day';
  if($mode==='activity')return 'activity';
  if($mode==='cruise_tour'||has_term('nile-cruises','tour-types',$id))return 'cruise';
  if(has_term('packages','tour-types',$id))return 'package';
  return 'other';
 }
 static function defaults():array {
  // Defaults are editable in the existing Service Activation JetEngine option page.
  return ['half_day'=>'seed_rate_simulation','day'=>'seed_rate_simulation',
    'activity'=>'seed_rate_simulation','extra_trip'=>'seed_rate_simulation',
    'package'=>'direct_per_person',
    'cruise'=>'direct_per_person','other'=>'direct_per_person'];
 }
 static function engine_defaults():array {
  $options=get_option('service-activation',[]);
  if(!is_array($options))$options=[];
  $defaults=self::defaults();$m=[];
  foreach(self::KEYS as $kind=>$key) {
   $v=(string)($options[$key]??$defaults[$kind]);
   $m[$kind]=isset(self::OPTIONS[$v])&&$v!=='auto_by_type'?$v:$defaults[$kind];
  }
  return $m;
 }
 static function chosen(int $id):array {
  $type=self::classify($id);
  $v=(string)get_post_meta($id,self::MODE,true);
  if(!isset(self::OPTIONS[$v]))$v='auto_by_type';
  $default=self::engine_defaults()[$type];
  return ['tour_type'=>$type,'override'=>$v,'resolved'=>$v==='auto_by_type'?$default:$v,
    'source'=>$v==='auto_by_type'?'engine_by_tour_type':'tour_override'];
 }
 static function factors(int $id):array {
  $v=get_post_meta($id,self::FACTORS,true);
  $out=['single'=>'1','double'=>'1','triple'=>'1'];
  if(is_array($v))foreach($out as $key=>$default){
   $f=(string)($v[$key]??'1');
   if(preg_match('/^(?:0|[1-9][0-9]{0,2})(?:\.[0-9]{1,4})?$/D',$f)
      &&(float)$f>0&&(float)$f<=100)$out[$key]=$f;
  }
  return $out;
 }
 static function inspect(int $id):array {
  if($id<1||get_post_type($id)!=='tours-and-activities')
   return ['ok'=>false,'code'=>'invalid_tour'];
  $mode=self::chosen($id);$factors=self::factors($id);
  $amount=get_post_meta($id,'start_price',true);
  $currency=(string)get_post_meta($id,'tour_base_currency',true);
  $basis=(string)get_post_meta($id,'tour_price_unit',true);
  $direct=is_numeric($amount)&&is_finite((float)$amount)&&(float)$amount>0&&
    preg_match('/^[A-Z]{3}$/D',$currency)&&array_key_exists($currency,get_woocommerce_currencies())&&$basis==='per_person';
  $rates=get_posts(['post_type'=>'tour-rates','post_status'=>['publish','draft','private','pending'],
    'posts_per_page'=>151,'fields'=>'ids','suppress_filters'=>true,
    'meta_query'=>[['key'=>'related_tour_id','value'=>(string)$id,'compare'=>'=']]]);
  if(count($rates)>=151)return ['ok'=>false,'code'=>'rate_limit_reached'];
  $published=[];$draft=[];$suspended=[];
  foreach($rates as $rate) {
   $rate=(int)$rate;
   if(get_post_status($rate)!=='publish'){$draft[]=$rate;continue;}
   if(in_array(strtolower((string)get_post_meta($rate,'stop_sale',true)),['1','yes','true'],true)||
      (string)get_post_meta($rate,'avilabile_status',true)==='0')$suspended[]=$rate;
   else $published[]=$rate;
  }
  $resolved=$mode['resolved'];
  // A Tour's explicit no-sale status cannot be bypassed by choosing Direct mode.
  if($suspended&&$resolved==='direct_per_person')$resolved='inquiry';
  if($published&&$resolved==='direct_per_person')$resolved='existing_rates';
  if($resolved==='existing_rates'&&!$published)$resolved=$suspended?'inquiry':
    ($direct?'direct_per_person':'inquiry');
  $preview=[];
  if($direct)foreach($factors as $occupancy=>$factor)
   $preview[$occupancy]=number_format((float)$amount*(float)$factor,2,'.','');
  return ['ok'=>true,'tour_id'=>$id,'type'=>$mode['tour_type'],
   'requested_mode'=>$mode['resolved'],'effective_mode'=>$resolved,'origin'=>$mode['source'],
   'retail_direct_available'=>(bool)$direct,'base_currency'=>$direct?$currency:null,
   'base_price_unit'=>$basis,'simulation'=>$preview,'factor_configuration'=>$factors,
   'rate_ids'=>$published,'draft_ids'=>$draft,'suspended_count'=>count($suspended),
   'seed_rate_id'=>count($published)===1?$published[0]:null,
   'variation_generation'=>['available'=>($resolved==='seed_rate_simulation'&&($direct||$published)),
     'method'=>'native_rate_generator_manual_draft','write_performed'=>false],
   'price_display_granted'=>false,'checkout_ready'=>false,'supplier_cost_required'=>false];
 }
}
add_filter('jet-engine/options-pages/raw-fields',static function($fields,$page){
 if(($page->slug??'')!=='service-activation')return $fields;
 foreach(Shared_Tour_Pricing_Entry_v1::KEYS as $kind=>$key) {
  $options=[];foreach(Shared_Tour_Pricing_Entry_v1::OPTIONS as $v=>$label){
   if($v==='auto_by_type')continue;
   $options[]=['key'=>$v,'value'=>$label];
  }
  $fields[]=['name'=>$key,'title'=>'Tour Pricing Default — '.ucwords(str_replace('_',' ',$kind)),
    'type'=>'select','object_type'=>'field','options_source'=>'manual','options'=>$options];
 }
 return $fields;
},35,2);
add_action('add_meta_boxes_tours-and-activities',static function(){
 add_meta_box('shared-tour-price-entry-v1','Pricing Entry · Per-person / Seed Rate',
 static function($post){
  $mode=Shared_Tour_Pricing_Entry_v1::chosen((int)$post->ID);
  $factors=Shared_Tour_Pricing_Entry_v1::factors((int)$post->ID);
  $inspect=Shared_Tour_Pricing_Entry_v1::inspect((int)$post->ID);
  wp_nonce_field('shared_tour_price_entry_'.$post->ID,'shared_tour_price_entry_nonce');
  echo '<p id="shared_tour_type_suggestion">Detected tour type: <strong>'.esc_html($mode['tour_type']).'</strong>. Engine default: '.
   esc_html(Shared_Tour_Pricing_Entry_v1::OPTIONS[Shared_Tour_Pricing_Entry_v1::engine_defaults()[$mode['tour_type']]]).'</p>';
  echo '<p><label for="shared_tour_price_entry_mode">Pricing starting point</label><br>';
  echo '<select id="shared_tour_price_entry_mode" name="shared_tour_price_entry_mode" style="width:100%;max-width:440px">';
  foreach(Shared_Tour_Pricing_Entry_v1::OPTIONS as $value=>$label)
   echo '<option value="'.esc_attr($value).'" '.selected($mode['override'],$value,false).'>'.
       esc_html($label).'</option>';
  echo '</select></p>';
  echo '<p>For <strong>Direct</strong>, enter your selling price in the existing JetEngine <code>start_price</code>, <code>tour_base_currency</code> and <code>tour_price_unit=per_person</code> fields. No Supplier Cost or Contract needed.</p>';
  echo '<fieldset><legend><strong>Seed Rate simulation — occupancy multipliers</strong></legend>';
  foreach($factors as $key=>$factor) {
    echo '<label>'.esc_html(ucfirst($key)).' <input type="number" name="shared_tour_seed_factors['.
      esc_attr($key).']" value="'.esc_attr($factor).
      '" step="0.0001" min="0.0001" max="100" style="width:100px"/></label> ';
  }
  echo '</fieldset>';
  if(class_exists('Shared_Tour_Rate_Seed_Bridge_v1'))
   Shared_Tour_Rate_Seed_Bridge_v1::render_fields($post);
  echo '<p id="shared_tour_seed_live_preview" aria-live="polite"><small>Enter/save a positive retail price and occupancy factors for local simulation.</small></p>';
  if(!empty($inspect['simulation'])){
    echo '<p><strong>Read-only example from saved direct selling price:</strong><br>';
    foreach($inspect['simulation'] as $key=>$price)echo esc_html(ucfirst($key).' '.$price.' '.$inspect['base_currency']).' · ';
    echo '</p>';
  }else echo '<p><em>Enter and save a positive direct price, ISO currency and per_person unit to enable the illustration; no Rate will be generated.</em></p>';
  if(!empty($inspect['seed_rate_id']))
    echo '<p>Linked seed Rate: <a href="'.esc_url(get_edit_post_link($inspect['seed_rate_id'])).
         '">#'.(int)$inspect['seed_rate_id'].'</a></p>';
  if(current_user_can('manage_woocommerce'))
    echo '<p><a href="'.esc_url(admin_url('admin.php?page=wc-settings&tab=rate_generation&source_tour_id='.(int)$post->ID)).
       '">Continue into native Rate Generator — preview or manually create Draft Variations</a></p>';
  echo '<p><small>Simulation factors follow the existing native Tour Rate Generator. Draft variations require explicit user action. This box does not publish Rates, confirm availability or enable checkout.</small></p>';
 },'tours-and-activities','normal','default');
});
add_action('save_post_tours-and-activities',static function($id){
 if(wp_is_post_revision($id)||!current_user_can('edit_post',$id))return;
 $nonce=$_POST['shared_tour_price_entry_nonce']??'';
 if(!is_string($nonce)||
    !wp_verify_nonce(sanitize_text_field(wp_unslash($nonce)),'shared_tour_price_entry_'.$id))return;
 $mode=$_POST['shared_tour_price_entry_mode']??'auto_by_type';
 if(!is_string($mode)||!isset(Shared_Tour_Pricing_Entry_v1::OPTIONS[$mode]))return;
 $raw=$_POST['shared_tour_seed_factors']??[];
 if(!is_array($raw)||array_diff(array_keys($raw),['single','double','triple']))return;
 $validated=[];
 foreach(['single','double','triple'] as $key){
  $v=(string)($raw[$key]??'1');
  if(!preg_match('/^(?:0|[1-9][0-9]{0,2})(?:\.[0-9]{1,4})?$/D',$v)||
       (float)$v<=0||(float)$v>100)return;
  $validated[$key]=$v;
 }
 update_post_meta($id,Shared_Tour_Pricing_Entry_v1::MODE,$mode);
 update_post_meta($id,Shared_Tour_Pricing_Entry_v1::FACTORS,$validated);
},24);
add_action('rest_api_init',static function(){
 register_rest_route('shared-product/v1','/tour-pricing-entry/(?P<id>\d+)',[
   'methods'=>WP_REST_Server::READABLE,
   'permission_callback'=>static fn()=>current_user_can('edit_posts'),
   'callback'=>static fn($request)=>rest_ensure_response(Shared_Tour_Pricing_Entry_v1::inspect(absint($request['id']))),
 ]);
});

/** Local admin only: instantly preview changed TYPE + factor inputs in the existing CPT editor.
 * This JavaScript never writes a Rate or changes any price outside the editing form. */
add_action('admin_footer',static function(){
 if(!in_array(get_current_screen()->base??'',['post'],true)||
   (get_current_screen()->post_type??'')!=='tours-and-activities')return;
 $defaults=Shared_Tour_Pricing_Entry_v1::engine_defaults();
 echo '<script>(function(){
const el=document.getElementById("shared_tour_seed_live_preview"),hint=document.getElementById("shared_tour_type_suggestion");
if(!el)return;
const defaults='.wp_json_encode($defaults).';
function get(name){const e=document.querySelector("[name="+JSON.stringify(name)+"]")||document.getElementById(name);return e?e.value:"";}
function kind(){
 const mode=get("tour_mode");
 const half=document.querySelector("input[name^=\"tax_input[duration-options]\"][value=\"2441\"]:checked,input[name^=\"tax_input[tour-types]\"][value=\"2469\"]:checked");
 const day=document.querySelector("input[name^=\"tax_input[duration-options]\"][value=\"318\"]:checked,input[name^=\"tax_input[tour-types]\"][value=\"192\"]:checked");
 if(half)return "half_day";if(day)return "day";
 if(mode==="activity"||mode==="extra_trip")return "activity";
 if(mode==="cruise_tour")return "cruise";
 if(document.querySelector("input[name^=\"tax_input[tour-types]\"][value=\"194\"]:checked"))return "package";
 return "other";
}
function update(){
 const value=Number(get("start_price")),cur=get("tour_base_currency");
 const amount=Number.isFinite(value)&&value>0&&/^[A-Z]{3}$/.test(cur)?value:null;
 const vals=[];
 ["single","double","triple"].forEach(k=>{
  const f=Number(get("shared_tour_seed_factors["+k+"]"));
  if(amount!==null&&Number.isFinite(f)&&f>0&&f<=100)vals.push(k+": "+(amount*f).toFixed(2)+" "+cur);
 });
 el.textContent=vals.length?"Draft-only occupancy simulation · "+vals.join(" · "):"Enter a positive start_price and 3-letter currency; calculations are staff-only and never publish Rates.";
 const k=kind();if(hint){const label=(defaults[k]||"direct_per_person");hint.textContent="Current type: "+k+" · engine default: "+label+" · the selected override takes precedence.";}
}
document.addEventListener("input",update,true);document.addEventListener("change",update,true);update();
})();</script>';
});
