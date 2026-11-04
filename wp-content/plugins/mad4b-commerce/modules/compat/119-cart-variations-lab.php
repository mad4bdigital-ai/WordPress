<?php
/**
 * Local QA only: composable Elementor shortcode cart selector.
 * Disabled for visitors, no orders, no pricing approval, no writes.
 */
defined('ABSPATH') || exit;
if (wp_get_environment_type() !== 'local') return;
function cart_lab_allowed() { return current_user_can('manage_woocommerce'); }
function cart_lab_manifest() {
 $m=get_option('commerce_cart_lab_fixture_20261026_v1',[]);
 return is_array($m)&&($m['schema']??'')==='cart-lab-fixture-v1'?$m:[];
}
function cart_lab_ref($id,$kind='tour') {
 $m=cart_lab_manifest();$id=(int)$id;
 if($kind==='tour')return $id>0 && $id===(int)($m['tour_id']??0);
 if($kind==='rate')return $id>0 && in_array($id,array_map('intval',$m['rate_ids']??[]),true);
 return false;
}
function cart_lab_rates() {
 $m=cart_lab_manifest();$rows=[];
 foreach($m['rate_ids']??[] as $id){
   $p=get_post((int)$id);
   if(!$p||$p->post_status!=='publish'||$p->post_type!=='tour-rates')continue;
   $package=get_term((int)get_post_meta($id,'related_package_term_id',true),'package_levels');
   $seasons=wp_get_object_terms((int)$id,'season',['fields'=>'names']);
   $rows[]=['id'=>(int)$id,'title'=>wp_strip_all_tags($p->post_title),
    'package'=>($package instanceof WP_Term)?$package->name:'Unknown package',
    'season'=>!is_wp_error($seasons)?implode(' / ',$seasons):'Unassigned',
    'from'=>gmdate('Y-m-d',(int)get_post_meta($id,'tour_rate_start_date',true)),
    'to'=>gmdate('Y-m-d',(int)get_post_meta($id,'tour_rate_end_date',true)),
    'indicative_base'=>(float)get_post_meta($id,'double_price',true),
    'currency'=>'USD','published'=>true];
 }
 return $rows;
}
function cart_lab_catalog($rate_id) {
 $m=cart_lab_manifest();
 if(!cart_lab_ref($rate_id,'rate'))return new WP_Error('cart_lab_rate_unlisted','Not an allowed test Rate',['status'=>404]);
 $options=\BookingExperience\tour_stay_options((int)$rate_id);
 if(is_wp_error($options))return $options;
 // Decorate native Board term references for accessible selector labels.
 foreach($options['properties'] as &$property){
  foreach($property['units'] as &$unit)
   foreach($unit['rates'] as &$unitRate)
    foreach($unitRate['meal_options'] as &$meal){
     $term=get_term((int)($meal['term_id']??0),'board-type');
     $meal['label']=$term instanceof WP_Term?$term->name:'Meal plan';
    }
  unset($unit,$unitRate,$meal);
 }
 unset($property);
 $extras=[];
 foreach($m['extras']??[] as $extra){
  $tid=(int)($extra['tour_id']??0);$rid=(int)($extra['rate_id']??0);
  if(get_post_type($tid)!=='tours-and-activities'||get_post_type($rid)!=='tour-rates'
    || (int)get_post_meta($rid,'related_tour_id',true)!==$tid)continue;
  $extras[]=['id'=>$tid,'rate_id'=>$rid,'label'=>$extra['label'],
    'indicative_price'=>(float)get_post_meta($rid,'single_price',true),
    'price_unit'=>'per_person','currency'=>'USD'];
 }
 return ['schema'=>'cart-variation-qa-catalog.v1',
  'tour_id'=>(int)$m['tour_id'],'rate_id'=>(int)$rate_id,
  'rates'=>cart_lab_rates(),'stay_catalog'=>$options,'extras'=>$extras,
  'status'=>'QA_ONLY_NOT_BOOKABLE','checkout_ready'=>false,
  'marketing_consent'=>false];
}
add_action('rest_api_init',static function(){
 register_rest_route('cart-variation-lab/v1','/catalog',[
  'methods'=>'GET','permission_callback'=>'cart_lab_allowed',
  'callback'=>static function(WP_REST_Request $r){
    $catalog=cart_lab_catalog((int)$r['rate_id']);
    return is_wp_error($catalog)?$catalog:rest_ensure_response($catalog);
  }
 ]);
 register_rest_route('cart-variation-lab/v1','/preview',[
  'methods'=>'POST','permission_callback'=>'cart_lab_allowed',
  'callback'=>static function(WP_REST_Request $r){
    $body=$r->get_json_params();
    if(!is_array($body)||array_diff(array_keys($body),
      ['tour_id','rate_id','date','adults','children','child_ages','stays','extras','transport_ids']))
      return new WP_Error('cart_lab_invalid_input','Unsupported selection fields',['status'=>422]);
    $rate_id=absint($body['rate_id']??0);
    $catalog=cart_lab_catalog($rate_id);if(is_wp_error($catalog))return $catalog;
    $tour_id=(int)($body['tour_id']??0);
    if($tour_id!==$catalog['tour_id'])return new WP_Error('cart_lab_tour_mismatch','Tour mismatch',['status'=>422]);
    $date=(string)($body['date']??'');
    $dt=DateTimeImmutable::createFromFormat('!Y-m-d',$date);
    if(!$dt||$dt->format('Y-m-d')!==$date)return new WP_Error('cart_lab_date_required','Choose valid travel date',['status'=>422]);
    $selected=null;foreach($catalog['rates'] as $rate)if($rate['id']===$rate_id)$selected=$rate;
    if(!$selected||$date<$selected['from']||$date>$selected['to'])
      return new WP_Error('cart_lab_rate_outside_date','Rate does not cover date',['status'=>422]);
    foreach(['adults','children'] as $name){
      if(!is_int($body[$name]??null)||$body[$name]<($name==='adults'?1:0)||$body[$name]>20)
        return new WP_Error('cart_lab_occupancy_invalid','Invalid guest count',['status'=>422]);
    }
    $ages=$body['child_ages']??[];
    if(!is_array($ages)||count($ages)!==$body['children']||count($ages)>10)
      return new WP_Error('cart_lab_ages_required','One age per child is required',['status'=>422]);
    foreach($ages as &$age){
      if(!class_exists('MAD4B_Age_Occupancy_Registry'))return new WP_Error('cart_lab_age_policy_missing');
      $age=MAD4B_Age_Occupancy_Registry::normalize_age($age);
      if(is_wp_error($age)||$age>17.99)
       return new WP_Error('cart_lab_child_age_invalid','Invalid child age',['status'=>422]);
    }
    unset($age);
    $stages=$catalog['stay_catalog']['plan']['stages'];
    $provided=$body['stays']??[];
    if(!is_array($provided)||count($provided)!==count($stages))
      return new WP_Error('cart_lab_stages_incomplete','All stages required',['status'=>422]);
    $stageRows=[];$included=0.0;$additional=0.0;$inquiry=0.0;
    foreach($stages as $stage){
      $key=$stage['key'];$selection=$provided[$key]??null;
      if(!is_array($selection)||array_diff(array_keys($selection),['property_id','unit_id','unit_rate_id','rooms','meal_term_id']))
        return new WP_Error('cart_lab_stage_invalid','Invalid stage selection',['status'=>422]);
      $property=(int)($selection['property_id']??0);$unit=(int)($selection['unit_id']??0);
      $unitRate=(int)($selection['unit_rate_id']??0);$rooms=$selection['rooms']??null;
      if(!in_array($property,$stage['properties'],true)||!is_int($rooms)||$rooms<1||$rooms>8)
        return new WP_Error('cart_lab_stage_not_offered','Unlisted property / room',['status'=>422]);
      $propertyRow=$catalog['stay_catalog']['properties'][(string)$property]??null;
      $u=null;
      foreach($propertyRow['units']??[] as $item)if((int)$item['unit_id']===$unit)$u=$item;
      if(!$u)return new WP_Error('cart_lab_unit_not_offered','Unlisted unit',['status'=>422]);
      $matches=false;foreach($u['rates']??[] as $row)if((int)$row['rate_id']===$unitRate)$matches=true;
      if(!$matches)return new WP_Error('cart_lab_unit_rate_not_offered','Unlisted unit Rate',['status'=>422]);
      $mealId=(int)($selection['meal_term_id']??0);
      if($mealId){
       $allowedMealIds=[];
       foreach($u['rates'] as $row)if((int)$row['rate_id']===$unitRate)
        $allowedMealIds=array_map('intval',array_column($row['meal_options'],'term_id'));
       if(!in_array($mealId,$allowedMealIds,true))
        return new WP_Error('cart_lab_meal_not_offered','Meal choice does not belong to Rate',['status'=>422]);
      }
      $max=(int)get_post_meta($unit,'unit_max_occupancy',true);
      if($max && ($body['adults']+$body['children'])>$max*$rooms)
        return new WP_Error('cart_lab_unit_occupancy_exceeded','Unit capacity exceeded',['status'=>422]);
      $amount=(float)get_post_meta($unitRate,'base_amount',true);
      if($amount<0||!is_finite($amount)||get_post_meta($unitRate,'currency',true)!=='USD')
        return new WP_Error('cart_lab_unit_price_not_verified','Unit cost unavailable',['status'=>422]);
      $rowTotal=round($amount*(int)$stage['nights']*$rooms,2);
      $policy=$stage['price_policy'];
      if($policy==='included')$included+=$rowTotal;
      elseif($policy==='additional')$additional+=$rowTotal;
      else $inquiry+=$rowTotal;
      $stageRows[]=['key'=>$key,'property_id'=>$property,'unit_id'=>$unit,
       'unit_rate_id'=>$unitRate,'rooms'=>$rooms,'nights'=>(int)$stage['nights'],
       'meal_term_id'=>$mealId,'meal_upgrade_price_confirmed'=>false,
       'price_policy'=>$policy,'reference_total'=>$rowTotal,'currency'=>'USD'];
    }
    $transportIds=$body['transport_ids']??[];
    if(!is_array($transportIds)||count($transportIds)>10
       ||count($transportIds)!==count(array_unique($transportIds)))
      return new WP_Error('cart_lab_transports_invalid','Transfer selection invalid',['status'=>422]);
    $nativeIds=array_map('intval',array_column($catalog['stay_catalog']['internal_transportations'],'id'));
    foreach($transportIds as $id)if(!is_int($id)||!in_array($id,$nativeIds,true))
      return new WP_Error('cart_lab_transport_not_offered','Transfer not linked to this Tour',['status'=>422]);
    $extras=$body['extras']??[];
    if(!is_array($extras)||count($extras)>12||count($extras)!==count(array_unique($extras)))
      return new WP_Error('cart_lab_extras_invalid','Extra selection invalid',['status'=>422]);
    $extraPrice=0.0;
    foreach($extras as $id){
      if(!is_int($id))return new WP_Error('cart_lab_extra_invalid','Extra ID invalid',['status'=>422]);
      $found=null;foreach($catalog['extras'] as $extra)if($extra['id']===$id)$found=$extra;
      if(!$found)return new WP_Error('cart_lab_extra_not_offered','Extra not offered',['status'=>422]);
      $extraPrice+=$found['indicative_price']*($body['adults']+$body['children']);
    }
    $base=$selected['indicative_base']*$body['adults'];
    return rest_ensure_response([
      'schema'=>'cart-variation-qa-preview.v1','status'=>'INDICATIVE_ONLY',
      'currency'=>'USD','base_reference'=>round($base,2),
      'stay_included_reference'=>round($included,2),
      'stay_additional_reference'=>round($additional,2),
      'stay_inquiry_reference'=>round($inquiry,2),
      'extras_reference'=>round($extraPrice,2),
      'selected_transports'=>$transportIds,
      'transports_priced'=>false,
      'meal_upgrades_priced'=>false,
      'indicative_subtotal'=>round($base+$additional+$extraPrice,2),
      'stages'=>$stageRows,'rate_id'=>$rate_id,'date'=>$date,
      'price_authorized'=>false,'availability_confirmed'=>false,
      'checkout_ready'=>false,'booking_authorized'=>false,
      'writes_performed'=>false,
      'disclaimer'=>'QA comparison only. Rates are not approved seller quotes. Included/inquiry stay references are not charged again.'
    ]);
  }
 ]);
});
function cart_lab_enqueue_assets() {
 static $queued=false;
 if($queued || !cart_lab_allowed())return;
 $m=cart_lab_manifest();if(!$m)return;
 $queued=true;
 wp_enqueue_style('cart-variation-qa',content_url('mu-plugins/cart-variations-lab-assets/cart-lab.css'),[], '1.0.1');
 wp_enqueue_script('cart-variation-qa',content_url('mu-plugins/cart-variations-lab-assets/cart-lab.js'),[], '1.0.1',true);
 wp_add_inline_script('cart-variation-qa','window.CartLabConfig = '.wp_json_encode([
   // Relative URLs stay on the current Dev Tunnel origin, not localhost:8091.
   'api'=>wp_make_link_relative(rest_url('cart-variation-lab/v1/')),
   'nonce'=>wp_create_nonce('wp_rest'),
   'tourId'=>(int)$m['tour_id'],'rateId'=>(int)$m['default_rate_id'],
   'formId'=>6
 ],JSON_UNESCAPED_SLASHES).';','before');
}
function cart_lab_on_private_page(){
 $page=(int)get_option('commerce_cart_lab_elementor_page_20261026',0);
 return $page>0 && is_page($page) && get_post_status($page)==='private';
}
// Do not let WP canonical redirect the private query URL to an internal
// localhost hostname. This is local-only and limited to the isolated QA page.
add_filter('redirect_canonical',static function($redirect){
 return cart_lab_on_private_page()?false:$redirect;
},20);
// Assets generated with Local's internal home_url otherwise point a browser
// on Dev Tunnels at the visitor's own localhost. Scope to this ONE QA page.
foreach(['style_loader_src','script_loader_src'] as $hook){
 add_filter($hook,static function($src){
  if(!cart_lab_on_private_page()||!is_string($src))return $src;
  $siteHost=wp_parse_url(home_url(),PHP_URL_HOST);
  $srcHost=wp_parse_url($src,PHP_URL_HOST);
  if($srcHost && $siteHost && strtolower($srcHost)===strtolower($siteHost))
    return wp_make_link_relative($src);
  return $src;
 },90,1);
}
add_action('wp_enqueue_scripts',static function(){
 if(cart_lab_on_private_page())cart_lab_enqueue_assets();
},15);
add_shortcode('booking_variations_lab',static function($attrs){
 if(!cart_lab_allowed())return '';
 $m=cart_lab_manifest();if(!$m)return '';
 $attrs=shortcode_atts(['tour_id'=>(int)$m['tour_id'],'form_id'=>6],$attrs,'booking_variations_lab');
 if(!cart_lab_ref($attrs['tour_id']))return '';
 cart_lab_enqueue_assets();
 ob_start();?>
 <section class="cartlab" data-cart-lab aria-label="Trip configuration demo">
 <div class="cartlab-header"><div><span class="cartlab-eyebrow">INTERACTIVE TOUR BUILDER · QA DEMO</span>
 <h2>Build your trip</h2><p>One journey, flexible choices. Select your options just like product variations.</p></div><span class="cartlab-status">Preview only</span></div>
 <div class="cartlab-body">
  <div class="cartlab-main">
   <section class="cartlab-section" aria-labelledby="cartlab-rates-title"><div class="cartlab-section-title"><span class="cartlab-number">01</span><h3 id="cartlab-rates-title">Select your tour rate</h3></div>
    <div data-cartlab-rates class="cartlab-grid cartlab-rates" role="radiogroup" aria-label="Tour rates"></div></section>
   <section class="cartlab-section" aria-labelledby="cartlab-date-title"><div class="cartlab-section-title"><span class="cartlab-number">02</span><h3 id="cartlab-date-title">Dates & travelers</h3></div>
    <div class="cartlab-date-row"><label>Departure <input type="date" data-cartlab-date></label><div class="cartlab-stepper-group">
      <label>Adults <span class="cartlab-stepper"><button type="button" data-step="adults:-1" aria-label="Remove adult">−</button><output data-count="adults">2</output><button type="button" data-step="adults:1" aria-label="Add adult">+</button></span></label>
      <label>Children <span class="cartlab-stepper"><button type="button" data-step="children:-1" aria-label="Remove child">−</button><output data-count="children">0</output><button type="button" data-step="children:1" aria-label="Add child">+</button></span></label>
      </div></div><div data-cartlab-child-ages class="cartlab-child-ages"></div></section>
   <section class="cartlab-section" aria-labelledby="cartlab-stays-title"><div class="cartlab-section-title"><span class="cartlab-number">03</span><h3 id="cartlab-stays-title">Accommodation options</h3></div>
    <div data-cartlab-stages></div></section>
   <section class="cartlab-section" aria-labelledby="cartlab-extras-title"><div class="cartlab-section-title"><span class="cartlab-number">04</span><h3 id="cartlab-extras-title">Extra activities</h3></div>
    <div data-cartlab-extras class="cartlab-grid"></div></section>
   <section class="cartlab-section" aria-labelledby="cartlab-transports-title"><div class="cartlab-section-title"><span class="cartlab-number">05</span><h3 id="cartlab-transports-title">Optional transfers</h3></div>
    <div data-cartlab-transports class="cartlab-grid"></div><p>Transfer prices and times require separate supplier confirmation.</p></section>
  </div>
  <aside class="cartlab-summary" aria-labelledby="cartlab-summary-title"><span class="cartlab-eyebrow">YOUR SELECTIONS</span><h3 id="cartlab-summary-title">Trip summary</h3>
    <div data-cartlab-summary-items></div><div class="cartlab-totals"><span>Indicative subtotal</span><strong data-cartlab-total>—</strong></div>
    <button type="button" class="cartlab-primary" data-cartlab-preview>Preview selections</button>
    <output data-cartlab-message role="status" aria-live="polite">Choose your options to see a reference estimate.</output>
    <p class="cartlab-note">No price, inventory or booking is confirmed. This is an admin-only Local demo.</p>
  </aside>
 </div></section>
 <?php return ob_get_clean();
});
require_once WPMU_PLUGIN_DIR.'/cart-variations-lab-assets/hybrid-native-selection.inc.php';
