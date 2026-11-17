<?php
/**
 * Plugin Name: MAD4B Operational Pricing Presets — Staging Simulation
 * Description: Admin-only, read-only simulations based on actual staged source records. NEVER writes commercial authority.
 */
defined('ABSPATH') || exit;
if (wp_get_environment_type() !== 'staging' || wp_parse_url(home_url(),PHP_URL_HOST) !== 'tours.dev.mad4b.com') return;
final class MAD4B_Operational_Pricing_Presets_V1 {
 const CONTRACT='mad4b.operational-pricing-presets.v1';
 const PAGE='mad4b-pricing-simulation-presets';
 public static function catalog():array {
  $r=[
   'tour-double-20261120'=>['title'=>'Tour • 2 adults / 20 Nov 2026','domain'=>'tour','source'=>'Tour 60627 / Rate 61140','kind'=>'existing published native Tour Rate'],
   'tour-single-20261120'=>['title'=>'Tour • single occupancy / 20 Nov 2026','domain'=>'tour','source'=>'Tour 60627 / Rate 61140','kind'=>'published single occupancy field'],
   'property-eileen-qa'=>['title'=>'Hotel • 3-night room QA fixture','domain'=>'accommodation','source'=>'Property 36362 / Unit 68927 / Rate 68928','kind'=>'QA-only draft: synthetic fixture'],
   'transport-expired-future'=>['title'=>'Car • expired Rate on future travel date','domain'=>'transport','source'=>'Transport 39707 / Rate 66570','kind'=>'historical published Rate, approval missing'],
   'transport-historical-replay'=>['title'=>'Car • historical rate replay (not bookable)','domain'=>'transport','source'=>'Transport 39707 / Rate 66570','kind'=>'past-date scenario, not bookable'],
   'rail-no-native-rate'=>['title'=>'Train • missing approved Rate','domain'=>'transport','source'=>'Transport 54555','kind'=>'published transport record'],
   'tax-policy-missing'=>['title'=>'Tax • policy and table unconfigured','domain'=>'tax','source'=>'WooCommerce native tax settings','kind'=>'current Staging configuration'],
   'journey-2adults-qa'=>['title'=>'Full journey • tour + hotel + transport + market + tax','domain'=>'journey','source'=>'Tour 60627 / Hotel 36362 / Transport 39707 / Market qa-eg-en','kind'=>'composite what-if: no booking, inventory or publish'],
  ];
  if(class_exists('MAD4B_SCP_Market_Profiles'))foreach(MAD4B_SCP_Market_Profiles::records() as $id=>$record){
   if(!str_starts_with((string)$id,'qa-')&&!str_starts_with((string)$id,'demo-'))continue;
   $r['market-'.$id]=['title'=>'Market • '.(string)($record['label']??$id),
    'domain'=>'market','source'=>'Market Profile '.$id,'kind'=>str_starts_with((string)$id,'demo-')?'site-bound candidate draft, not approved':'copied local QA record, not approved'];
  }
  if(class_exists('MAD4B_Company_Market_Portfolio_V1')){
   $saved=get_option(MAD4B_Company_Market_Portfolio_V1::REGIONS,[]);
   foreach((array)$saved as $key=>$region){
    if(!is_array($region)||($region['status']??'')!=='candidate_draft')continue;
    $r['region-'.$key]=['title'=>'Market region • '.(string)($region['state_label']??$key).
      ' / '.strtoupper((string)($region['country']??'')),
      'domain'=>'market_region','source'=>'Region '.$key.' / parent '.(string)($region['parent_profile_id']??''),
      'kind'=>'dynamic WooCommerce subregion candidate, not approved'];
   }
  }
  return $r;
 }
 private static function gate(bool $pass,string $reason):array {
  return ['passed'=>$pass,'detail'=>$reason];
 }
 private static function amount($value):?float {
  return is_numeric($value)&&is_finite((float)$value)&&(float)$value>0?round((float)$value,2):null;
 }
 private static function base(string $id,array $catalog):array {
  return ['contract'=>self::CONTRACT,'simulation_only'=>true,'preset_id'=>$id,
   'title'=>$catalog[$id]['title'],'source'=>$catalog[$id]['source'],
   'kind'=>$catalog[$id]['kind'],'evaluated_at_utc'=>gmdate('c'),
   'commercially_authorized'=>false,'checkout_ready'=>false,'order_created'=>false,
   'approval_mutated'=>false,'native_price_modified'=>false,
   'pricing'=>['amount'=>null,'currency'=>null,'method'=>'not_quoted'],
   'gates'=>[],'evidence'=>[],'blockers'=>[]];
 }
 private static function validate_dates(string $from,string $to,string $date):bool {
  return ($from===''||$date>=$from)&&($to===''||$date<=$to);
 }
 private static function finalise(array $result):array {
  $blocked=[];foreach($result['gates'] as $id=>$gate)if(empty($gate['passed']))$blocked[]=$id;
  $result['blockers']=$blocked;
  $result['simulation_verdict']=$blocked?'BLOCKED_FROM_COMMERCIAL_USE':'PRICING_INPUTS_VALID_ONLY';
  $result['source_sha256']=hash('sha256',wp_json_encode([
   'preset'=>$result['preset_id'],'evidence'=>$result['evidence'],
   'gates'=>$result['gates'],'pricing'=>$result['pricing']],JSON_UNESCAPED_SLASHES));
  return $result;
 }
 public static function evaluate(string $id) {
  $catalog=self::catalog();if(!isset($catalog[$id]))return new WP_Error('pricing_simulation_preset_unknown');
  $r=self::base($id,$catalog);
  $r['gates']['staging_only']=self::gate(true,'Read-only Staging environment; no rates/orders/approvals are written');
  if(str_starts_with($id,'market-')){
   $key=substr($id,7);$record=MAD4B_SCP_Market_Profiles::records()[$key]??[];
   $valid=(bool)MAD4B_SCP_Market_Profiles::approved($key);
   $matches=($record['site_uuid']??'')===MAD4B_SCP_Market_Profiles::site_uuid();
   $r['evidence']=['profile_id'=>$key,'country'=>$record['country']??null,'language'=>$record['language']??null,
    'stored_status'=>$record['status']??null,'stored_environment'=>$record['environment']??null,
    'site_uuid_matches'=>$matches,'native_approved'=>$valid];
   $r['gates']['site_binding']=self::gate($matches,'Original record must match current Staging site UUID');
   $r['gates']['environment_binding']=self::gate(($record['environment']??'')==='staging','Record must belong to Staging, not prior local lab');
   $r['gates']['native_market_approval']=self::gate($valid,'Native approved() is authoritative, stored status alone is insufficient');
  }elseif(str_starts_with($id,'region-')){
   $key=substr($id,7);
   $all=get_option(MAD4B_Company_Market_Portfolio_V1::REGIONS,[]);
   $saved=is_array($all)?($all[$key]??[]):[];
   $cc=(string)($saved['country']??'');$ll=(string)($saved['language']??'');
   $state=(string)($saved['state_code']??'');$parent=(string)($saved['parent_profile_id']??'');
   $states=function_exists('WC')&&WC()->countries?(array)(WC()->countries->get_states($cc)?:[]):[];
   $native=MAD4B_SCP_Market_Profiles::records()[$parent]??null;
   $targetable=MAD4B_SCP_Market_Profiles::is_targetable($cc,$ll);
   $source=['country'=>$cc,'language'=>$ll,'state'=>$state,'state_label'=>$states[$state]??'',
     'parent_draft'=>$parent,'source'=>['WooCommerce_states','WPML','CLDR_advisory']];
   $fingerprint=MAD4B_Company_Market_Portfolio_V1::sha($source);
   $r['evidence']=['region_key'=>$key,'country'=>$cc,'language'=>$ll,'subregion'=>$state,
     'subregion_label'=>$saved['state_label']??null,'parent_profile_id'=>$parent,
     'site_bound'=>($saved['site_uuid']??'')===MAD4B_SCP_Market_Profiles::site_uuid(),
     'source_current_sha256'=>$fingerprint,'source_saved_sha256'=>$saved['source_sha256']??null,
     'status'=>$saved['status']??null];
   $r['gates']['current_subregion_source']=self::gate(isset($states[$state])&&
     hash_equals((string)($saved['source_sha256']??''),$fingerprint),
     'Subregion must still exist unchanged in native WooCommerce country states');
   $r['gates']['parent_site_bound']=self::gate(is_array($native)&&
     ($native['site_uuid']??'')===MAD4B_SCP_Market_Profiles::site_uuid()&&
     ($saved['site_uuid']??'')===MAD4B_SCP_Market_Profiles::site_uuid(),
     'Draft and region must match the current site identity');
   $r['gates']['targeting_allowed']=self::gate($targetable,
     'Country/language must be eligible under current policy and WPML visibility');
   $r['gates']['native_market_approval']=self::gate(false,
     'Subregion is a research candidate, never a market or checkout approval');
  }elseif(str_starts_with($id,'tour-')){
   $rate=61140;$tour=60627;$date='2026-11-20';
   $single=$id==='tour-single-20261120';$persons=$single?1:2;
   $value=self::amount(get_post_meta($rate,$single?'single_price':'double_price',true));
   $from=(int)get_post_meta($rate,'tour_rate_start_date',true);
   $to=(int)get_post_meta($rate,'tour_rate_end_date',true);
   $start=$from?gmdate('Y-m-d',$from):'';$end=$to?gmdate('Y-m-d',$to):'';
   $currency=strtoupper((string)get_post_meta($rate,'base_currency',true));
   $stop=in_array(strtolower((string)get_post_meta($rate,'stop_sale',true)),['1','true','yes','on'],true);
   $entry=class_exists('Shared_Tour_Pricing_Entry_v1')?Shared_Tour_Pricing_Entry_v1::inspect($tour):[];
   $rateActive=isset($entry['rate_ids'])&&in_array($rate,(array)$entry['rate_ids'],true);
   $r['evidence']=['tour_id'=>$tour,'tour_title'=>get_the_title($tour),'rate_id'=>$rate,
    'rate_post_status'=>get_post_status($rate),'rate_parent'=>(int)get_post_meta($rate,'related_tour_id',true),
    'date'=>$date,'adults'=>$persons,'occupancy'=>$single?'single':'double',
    'source_unit_price'=>$value,'source_currency'=>$currency,'valid_from'=>$start,'valid_to'=>$end,
    'native_effective_mode'=>$entry['effective_mode']??null,'stop_sale'=>$stop];
   if($value!==null&&$currency==='USD')$r['pricing']=['amount'=>round($value*$persons,2),'currency'=>$currency,
    'method'=>'source_field_x_adults_not_payment_quote','basis'=>'per_person'];
   $r['gates']['published_rate']=self::gate(get_post_status($rate)==='publish','Rate must be published');
   $r['gates']['native_rate_relation']=self::gate((int)get_post_meta($rate,'related_tour_id',true)===$tour&&$rateActive,'Rate must belong to tour and appear in native source resolver');
   $r['gates']['valid_travel_date']=self::gate(self::validate_dates($start,$end,$date),'Requested date must be within Rate period');
   $r['gates']['not_stop_sale']=self::gate(!$stop,'Stop Sale must be respected');
   $r['gates']['valid_source_price']=self::gate($value!==null&&$currency==='USD','Uses actual source price fields; no invented supplier cost');
   $r['gates']['market_approved']=self::gate(count(MAD4B_SCP_Market_Profiles::approved_list())>0,'Native site-bound market approval required');
   $r['gates']['supplier_inventory_confirmed']=self::gate(false,'No supplier capacity proof; quote cannot become commercial booking');
  }elseif(str_starts_with($id,'property-')){
   $rate=68928;$unit=68927;$property=36362;$date='2026-11-20';$nights=3;
   $price=self::amount(get_post_meta($rate,'base_amount',true));
   $currency=strtoupper((string)get_post_meta($rate,'currency',true));
   $unitValue=(string)get_post_meta($rate,'prop_price_unit',true);
   $r['evidence']=['property_id'=>$property,'property_title'=>get_the_title($property),
    'unit_id'=>$unit,'unit_status'=>get_post_status($unit),'rate_id'=>$rate,'rate_status'=>get_post_status($rate),
    'rate_title'=>get_the_title($rate),'rate_parent'=>(int)get_post_meta($rate,'prop_related_property_id',true),
    'rate_unit'=>(int)get_post_meta($rate,'prop_related_unit_id',true),
    'date'=>$date,'nights'=>$nights,'unit_price'=>$price,'currency'=>$currency,'unit_type'=>$unitValue,
    'valid_from'=>(string)get_post_meta($rate,'valid_from',true),
    'valid_to'=>(string)get_post_meta($rate,'valid_to',true)];
   if($price!==null&&$currency==='USD'&&$unitValue==='per_room_night')$r['pricing']=['amount'=>round($price*$nights,2),'currency'=>'USD',
    'method'=>'qa_fixture_unit_price_x_nights_not_supplier_quote','basis'=>'per_room_night'];
   $r['gates']['rate_relation']=self::gate((int)get_post_meta($rate,'prop_related_property_id',true)===$property&&(int)get_post_meta($rate,'prop_related_unit_id',true)===$unit,'QA fixture correctly linked to real parent property');
   $r['gates']['valid_dates']=self::gate(self::validate_dates((string)get_post_meta($rate,'valid_from',true),(string)get_post_meta($rate,'valid_to',true),$date),'QA fixture dates cover selected scenario');
   $r['gates']['rate_published']=self::gate(get_post_status($rate)==='publish','Source is intentionally QA draft; cannot be used for checkout');
   $r['gates']['unit_published']=self::gate(get_post_status($unit)==='publish','QA fixture room is draft/not for sale');
   $r['gates']['supplier_rate_verified']=self::gate(false,'Synthetic QA rate is not a supplier quote');
   $r['gates']['supplier_inventory_confirmed']=self::gate(false,'No certified room inventory or availability');
  }elseif(str_starts_with($id,'transport-')){
   $rate=66570;$transport=39707;
   $date=$id==='transport-historical-replay'?'2026-02-10':'2026-11-20';$days=2;
   $base=self::amount(get_post_meta($rate,'base_amount',true));
   $sale=self::amount(get_post_meta($rate,'sale_price',true));
   $onSale=in_array(strtolower((string)get_post_meta($rate,'is_on_sale',true)),['yes','true','on','1'],true);
   $price=$onSale&&$sale!==null?$sale:$base;
   $currency=strtoupper((string)get_post_meta($rate,'currency',true));
   $unit=(string)get_post_meta($rate,'pricing_type',true);
   $approved=function_exists('BookingExperience\\transport_rate_approval_verified')
    ?(bool)call_user_func('BookingExperience\\transport_rate_approval_verified',$rate):false;
   $start=(string)get_post_meta($rate,'valid_from',true);$end=(string)get_post_meta($rate,'valid_to',true);
   $r['evidence']=['transport_id'=>$transport,'transport_title'=>get_the_title($transport),
    'rate_id'=>$rate,'rate_status'=>get_post_status($rate),'source_parent'=>(int)get_post_meta($rate,'source_post_id',true),
    'date'=>$date,'days'=>$days,'currency'=>$currency,'price_per_day'=>$price,'source_regular_price'=>$base,
    'source_sale_price'=>$sale,'is_on_sale'=>$onSale,'pricing_type'=>$unit,'valid_from'=>$start,
    'valid_to'=>$end,'native_finance_approval'=>$approved];
   if($price!==null&&$unit==='per_day'&&$currency==='USD')$r['pricing']=['amount'=>round($days*$price,2),'currency'=>'USD',
    'method'=>'historical_source_field_replay_not_current_quote','basis'=>'per_day'];
   $r['gates']['rate_relation']=self::gate((int)get_post_meta($rate,'source_post_id',true)===$transport,'Transport Rate parent must match');
   $r['gates']['date_eligible']=self::gate(self::validate_dates($start,$end,$date),'Travel date must lie inside historical Rate window');
   $r['gates']['future_travel_date']=self::gate($date>=gmdate('Y-m-d'),'Historical replay can never be bookable');
   $r['gates']['native_financial_approval']=self::gate($approved,'Native source hash and approval revision are mandatory');
   $r['gates']['supplier_availability']=self::gate(false,'Supplier transport capacity is not verified');
  }elseif($id==='rail-no-native-rate'){
   $transport=54555;
   $rates=get_posts(['post_type'=>'transport-rates','post_status'=>'publish','fields'=>'ids','posts_per_page'=>200,
    'suppress_filters'=>true,'meta_query'=>[['key'=>'source_post_id','value'=>(string)$transport]]]);
   $r['evidence']=['transport_id'=>$transport,'transport_title'=>get_the_title($transport),
    'status'=>get_post_status($transport),'native_published_rate_ids'=>$rates];
   $r['gates']['transport_published']=self::gate(get_post_status($transport)==='publish','Transport parent is published');
   $r['gates']['approved_rate_available']=self::gate(false,'No qualified approved future Transport Rate in this scenario');
   $r['gates']['supplier_availability']=self::gate(false,'Train inventory provider not confirmed');
  }elseif($id==='journey-2adults-qa'){
   $ids=['tour-double-20261120','property-eileen-qa','transport-expired-future','market-qa-eg-en','tax-policy-missing'];
   $components=[];
   foreach($ids as $child){
    $part=self::evaluate($child);
    if(is_wp_error($part))continue;
    $components[$child]=['source'=>$part['source'],'amount'=>$part['pricing']['amount'],
      'currency'=>$part['pricing']['currency'],'blockers'=>$part['blockers'],
      'source_sha256'=>$part['source_sha256']];
    $r['gates']['component_'.$child]=self::gate(!$part['blockers'],
      $part['blockers']?'Blocked: '.implode(', ',$part['blockers']):'Pricing inputs covered');
   }
   $r['evidence']=['scenario_date'=>'2026-11-20','adults'=>2,
     'components'=>$components,'amounts_not_combined'=>true,
     'reason'=>'Mixed QA/supplier/expired sources cannot form a payable package total'];
   $r['gates']['commercial_total_available']=self::gate(false,
     'No valid commercial total, currency conversion, supplier confirmation or payment permission');
  }elseif($id==='tax-policy-missing'){
   global $wpdb;
   $table=$wpdb->prefix.'woocommerce_tax_rates';
   $exists=$wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s',$table))===$table;
   $rules=$exists?(int)$wpdb->get_var("SELECT COUNT(*) FROM {$table}"):0;
   $enabled=get_option('woocommerce_calc_taxes')==='yes';
   $r['evidence']=['calc_taxes'=>get_option('woocommerce_calc_taxes'),'tax_rule_count'=>$rules,
    'prices_include_tax'=>get_option('woocommerce_prices_include_tax'),
    'tax_display_shop'=>get_option('woocommerce_tax_display_shop')];
   $r['gates']['woo_tax_enabled']=self::gate($enabled,'WooCommerce tax calculations currently disabled');
   $r['gates']['jurisdiction_rates_configured']=self::gate($rules>0,'No native tax rate table entries; cannot infer statutory tax');
  }
  if(!str_starts_with($id,'market-') && !str_starts_with($id,'region-') && $id!=='tax-policy-missing') {
   if(!isset($r['gates']['market_approved']))
    $r['gates']['market_approved']=self::gate(count(MAD4B_SCP_Market_Profiles::approved_list())>0,
      'Site-bound Market Profile required; legacy local-QA approvals cannot authorize Staging');
   $r['gates']['tax_policy_configured']=self::gate(get_option('woocommerce_calc_taxes')==='yes',
     'Staging WooCommerce currently has tax calculations disabled; no statutory value assumed');
  }
  return self::finalise($r);
 }
 public static function export():void {
  if(!current_user_can('manage_woocommerce'))wp_die('Not authorized',403);
  check_admin_referer('mad4b_pricing_simulation_export');
  $items=[];
  foreach(self::catalog() as $key=>$descriptor){
   $result=self::evaluate((string)$key);
   if(!is_wp_error($result))$items[$key]=$result;
  }
  nocache_headers();
  header('Content-Type: application/json; charset=utf-8');
  header('Content-Disposition: attachment; filename="mad4b-staging-operational-pricing-presets.json"');
  echo wp_json_encode(['contract'=>self::CONTRACT,'environment'=>'staging',
   'exported_at_utc'=>gmdate('c'),'simulation_only'=>true,
   'commercial_authorization'=>false,'presets'=>$items],
   JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
  exit;
 }
 public static function menu():void {
  add_submenu_page('woocommerce','Operational Pricing Presets','Pricing Simulation Presets','manage_woocommerce',self::PAGE,[self::class,'page']);
 }
 public static function page():void {
  if(!current_user_can('manage_woocommerce'))wp_die('Not authorized',403);
  $cat=self::catalog();$selected=sanitize_key((string)($_GET['sim_preset']??''));
  if(!isset($cat[$selected]))$selected='tour-double-20261120';
  $result=self::evaluate($selected);
  echo '<div class="wrap"><h1>Operational Pricing Presets</h1>';
  echo '<p><strong>STAGING / SIMULATION ONLY</strong> · Live source references, read-only computation. No market approvals, posted Rates, tax writes, supplier availability, orders or checkout authorization.</p>';
  echo '<p><a href="'.esc_url(admin_url('admin.php?page=mad4b-pricing-workbench')).'">Back to Pricing Workbench</a></p>';
  echo '<p><a class="button" href="'.esc_url(wp_nonce_url(admin_url('admin-post.php?action=mad4b_pricing_sim_export'),'mad4b_pricing_simulation_export')).'">Export all current Presets as JSON</a></p>';
  echo '<form method="get" action="'.esc_url(admin_url('admin.php')).'">';
  echo '<input type="hidden" name="page" value="'.esc_attr(self::PAGE).'"><label for="mad4b_sim_select"><strong>Select operational preset</strong></label> ';
  echo '<select id="mad4b_sim_select" name="sim_preset">';
  foreach($cat as $id=>$item)echo '<option value="'.esc_attr($id).'" '.selected($selected,$id,false).'>'.esc_html($item['title']).'</option>';
  echo '</select> <button class="button button-primary" type="submit">Run read-only simulation</button></form>';
  if(is_wp_error($result)){echo '<p>Invalid preset</p></div>';return;}
  echo '<h2>'.esc_html($result['title']).'</h2>';
  echo '<p><strong>Verdict: '.esc_html($result['simulation_verdict']).'</strong> · No financial or supplier approval granted.</p>';
  echo '<p>Source: <code>'.esc_html($result['source']).'</code> · '.esc_html($result['kind']).'</p>';
  if($result['pricing']['amount']!==null){
   echo '<p><strong>Indicative simulation calculation:</strong> '.esc_html(number_format((float)$result['pricing']['amount'],2).' '.$result['pricing']['currency']).'</p>';
   echo '<p><em>'.esc_html($result['pricing']['method']).' — not payable or commercially bookable.</em></p>';
  }else echo '<p><strong>No numerical tax/price inference</strong> from incomplete or non-qualifying source.</p>';
  echo '<h3>Operational gates</h3><table class="widefat striped"><thead><tr><th>Gate</th><th>Simulation result</th><th>Explanation</th></tr></thead><tbody>';
  foreach($result['gates'] as $gate=>$value)echo '<tr><td><code>'.esc_html($gate).'</code></td><td>'.($value['passed']?'PASS':'BLOCKED').'</td><td>'.esc_html($value['detail']).'</td></tr>';
  echo '</tbody></table><h3>Read-only source evidence</h3><table class="widefat striped"><tbody>';
  foreach($result['evidence'] as $key=>$value)echo '<tr><th>'.esc_html($key).'</th><td><code>'.esc_html(is_array($value)?wp_json_encode($value):($value===true?'true':($value===false?'false':(string)$value))).'</code></td></tr>';
  echo '</tbody></table><p>Source fingerprint: <code>'.esc_html($result['source_sha256']).'</code></p>';
  echo '<p>Presets are evaluated against the current native records on every page load. They never write to the database. A changed source creates a new fingerprint.</p></div>';
 }
}
add_action('admin_menu',['MAD4B_Operational_Pricing_Presets_V1','menu']);
add_action('admin_post_mad4b_pricing_sim_export',['MAD4B_Operational_Pricing_Presets_V1','export']);
