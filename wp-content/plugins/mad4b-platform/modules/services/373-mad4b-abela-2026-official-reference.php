<?php
/**
 * Plugin Name: MAD4B Abela Official 2026 Fare Reference
 * Description: Source-backed 2026 sleeper reference prices, not live bookable supplier quotes.
 * Version: 0.1.0
 */
defined('ABSPATH')||exit;
final class MAD4B_Abela_2026_Official_Reference_V1 {
 const CONTRACT='mad4b.abela-sleeper-official-tariff-2026.v1';
 const SOURCE_ID='abela_annual_tariff';
 const BASELINE_SHA256='bb851ca7063eb369ed03787e0275d651c2b9b28d90f3faa8ff6c0da36162a718';
 const OPERATOR='https://www.abelatrains.com/';
 const ALLOWED_TRAIN=54552; // train 86, source document Cairo -> Aswan
 static function source_status() {
  if(!class_exists('MAD4B_Rail_Source_Monitor_V1'))
    return new WP_Error('abela_source_registry_unavailable');
  $hold=get_option(MAD4B_Rail_Source_Monitor_V1::HOLD,[]);
  if(is_array($hold)&&($hold['source_id']??'')===self::SOURCE_ID)
    return new WP_Error('abela_official_source_changed_pending_financial_review');
  $monitor=MAD4B_Rail_Source_Monitor_V1::summary();
  $source=(array)($monitor['sources'][self::SOURCE_ID]??[]);
  $canonical=(array)(MAD4B_Rail_Source_Monitor_V1::registry()[self::SOURCE_ID]??[]);
  $url=(string)($canonical['url']??'');
  $sha=(string)($source['sha256']??'');
  $time=(string)($source['last_checked_at']??'');
  if(!$url||!preg_match('~^https://sleep\.abelatrains\.com/files/train_price_list_and_statins[a-zA-Z0-9_-]*\.pdf$~D',$url))
    return new WP_Error('abela_canonical_source_invalid');
  if(($source['source_url']??'')!==$url ||
     ($source['observed_url']??'')!==$url)
    return new WP_Error('abela_source_reference_link_pending_review');
  if((int)($source['version']??0)!==1)
    return new WP_Error('abela_tariff_source_version_requires_review');
  if(!hash_equals(self::BASELINE_SHA256,$sha))
    return new WP_Error('abela_tariff_document_changed_requires_review');
  if(!$time||strtotime($time)===false||time()-strtotime($time)>60*60*72)
    return new WP_Error('abela_source_observation_stale');
  return ['url'=>$url,'sha256'=>$sha,'last_checked_at'=>$time,
    'reference_only'=>true,'booking_authorized'=>false];
 }
 static function rates(): array {
  // All amounts are per passenger. Double is per person sharing a cabin.
  // Child: separate supplier age policy (4-9 years), not guaranteed occupancy.
  return [
   'egyptian'=>[
     'currency'=>'EGP',
     'off'=>['regular'=>['single'=>1875,'double'=>1250,'child'=>1150],
             'elite'=>['single'=>2700,'double'=>1800,'child'=>1700]],
     'peak'=>['regular'=>['single'=>2175,'double'=>1450,'child'=>1450],
              'elite'=>['single'=>3100,'double'=>2050,'child'=>2050]],
   ],
   'arab'=>[
     'currency'=>'EGP',
     'off'=>['regular'=>['single'=>2700,'double'=>1800,'child'=>1700],
             'elite'=>['single'=>3450,'double'=>2300,'child'=>2200]],
     'peak'=>['regular'=>['single'=>3000,'double'=>2000,'child'=>2000],
              'elite'=>['single'=>3750,'double'=>2500,'child'=>2500]],
   ],
   'foreign'=>[
     'currency'=>'USD',
     'off'=>['regular'=>['single'=>150,'double'=>110,'child'=>105],
             'elite'=>['single'=>170,'double'=>125,'child'=>120]],
     'peak'=>['regular'=>['single'=>165,'double'=>125,'child'=>125],
              'elite'=>['single'=>185,'double'=>135,'child'=>135]],
   ],
  ];
 }
 static function season($date,$market){
  if(!preg_match('/^2026-\d{2}-\d{2}$/D',$date))return new WP_Error('abela_tariff_2026_only');
  $d=DateTimeImmutable::createFromFormat('!Y-m-d',$date,new DateTimeZone('UTC'));
  if(!$d||$d->format('Y-m-d')!==$date)return new WP_Error('abela_invalid_date');
  $windows=$market==='foreign'?
   [['2026-02-20','2026-02-28'],['2026-10-20','2026-10-31'],['2026-12-10','2026-12-31']]:
   [['2026-01-22','2026-02-06'],['2026-03-12','2026-03-30'],
    ['2026-05-19','2026-06-07'],['2026-07-01','2026-08-31'],
    ['2026-12-10','2026-12-31']];
  foreach($windows as [$from,$to])if($date>=$from&&$date<=$to)return 'peak';
  return 'off';
 }
 static function lookup($transportId,$date,$market,$cabin) {
  $id=absint($transportId);
  if(!class_exists('MAD4B_Rail_Fare_Quote_Intake_V1')||
     !class_exists('MAD4B_SCP_Site_Profile')||
     home_url()!=='https://tours.dev.mad4b.com'||
     wp_get_environment_type()!=='staging')return new WP_Error('abela_site_not_authorized');
  if($id===54553)return new WP_Error('abela_return_direction_fare_not_approved');
  if($id!==self::ALLOWED_TRAIN)return new WP_Error('abela_train_not_covered_by_source');
  if(get_post_type($id)!=='transportations'||get_post_status($id)!=='publish'||
    (string)get_post_meta($id,'trans_train_number',true)!=='86'||
    (int)get_post_meta($id,'trans_from_station_id',true)!==68930||
    (int)get_post_meta($id,'trans_to_station_id',true)!==54258)
     return new WP_Error('abela_verified_route_changed');
  $evidence=self::source_status();
  if(is_wp_error($evidence))return $evidence;
  $catalog=self::rates();
  if(!isset($catalog[$market]))return new WP_Error('abela_market_invalid');
  $classes=[
    'single_cabin'=>['regular','single'],'double_cabin'=>['regular','double'],
    'elite_single'=>['elite','single'],'elite_double'=>['elite','double'],
    'child_regular'=>['regular','child'],'child_elite'=>['elite','child']
  ];
  if(!isset($classes[$cabin]))return new WP_Error('abela_cabin_invalid');
  $season=self::season($date,$market);
  if(is_wp_error($season))return $season;
  [$tier,$basis]=$classes[$cabin];
  $amount=$catalog[$market][$season][$tier][$basis]??null;
  if(!is_int($amount)||$amount<=0)return new WP_Error('abela_source_tariff_missing');
  return [
    'contract'=>self::CONTRACT,
    'transport_id'=>$id,'train_number'=>'86',
    'operator'=>'abela','travel_date'=>$date,'route'=>'Bashtel (Greater Cairo) → Aswan',
    'route_price_label_in_source'=>'Cairo - Aswan',
    'market'=>$market,'cabin'=>$cabin,'tier'=>$tier,
    'season'=>$season,'reference_amount'=>$amount,
    'currency'=>$catalog[$market]['currency'],'unit'=>'per_person',
    'child_age_range'=>$basis==='child'?'4-9':'not_applicable',
    'document_year'=>2026,'source_url'=>$evidence['url'],
    'source_sha256'=>$evidence['sha256'],
    'source_page'=>3,
    'status'=>'REFERENCE_ONLY_NOT_A_QUOTE',
    'source_fare_reference'=>true,'supplier_quote_verified'=>false,
    'train_inventory_verified'=>false,'booking_approved'=>false,
    'published_transport_rate_id'=>null,
    'notes'=>'Public annual operator tariff. Confirm train, departure, cabin, passenger market, availability and any fees with Abela before issuing a commercial quote.'
  ];
 }
 static function cli($args,$assoc){
  $op=$args[0]??'plan';
  if($op==='plan')$v=[
   'contract'=>self::CONTRACT,
   'source_url'=>(MAD4B_Rail_Source_Monitor_V1::registry()[self::SOURCE_ID]['url']??null),
   'source_status'=>is_wp_error(self::source_status())?self::source_status()->get_error_code():'current',
   'covered_train'=>54552,'return_train'=>54553,
   'return_fare_requires_operator_confirmation'=>true,
   'market_options'=>array_keys(self::rates()),'year'=>2026,
   'reference_only'=>true,'creates_approved_rate'=>false
  ];
  elseif($op==='lookup')$v=self::lookup(
   (int)($assoc['transport-id']??0),(string)($assoc['travel-date']??''),
   (string)($assoc['market']??''),(string)($assoc['cabin']??''));
  else WP_CLI::error('Use plan or lookup with transport-id, travel-date, market and cabin');
  if(is_wp_error($v))WP_CLI::error($v->get_error_code());
  WP_CLI::line(wp_json_encode($v,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));
 }
 static function menu(){
  add_management_page('Abela 2026 Fare Reference','Abela 2026 Fare Reference',
    'manage_options','mad4b-abela-2026',[__CLASS__,'page']);
 }
 static function page(){
  if(!current_user_can('manage_options'))wp_die('Forbidden');
  $market=sanitize_key(wp_unslash($_GET['market']??'egyptian'));
  $date=sanitize_text_field(wp_unslash($_GET['travel_date']??wp_date('Y-m-d')));
  $cabin=sanitize_key(wp_unslash($_GET['cabin']??'double_cabin'));
  $hasPreview=isset($_GET['preview']);
  $result=$hasPreview?self::lookup(54552,$date,$market,$cabin):null;
  echo '<div class="wrap"><h1>Abela 2026 Official Fare Reference</h1>';
  echo '<p>Annual published supplier price list only. No live availability, booking authority or verified final quote.</p>';
  $source=(array)(MAD4B_Rail_Source_Monitor_V1::registry()[self::SOURCE_ID]??[]);
  echo '<p><a href="'.esc_url($source['url']??'').'" target="_blank" rel="noopener noreferrer">View original 2026 PDF source (page 3: fares; page 4: schedule)</a></p>';
  echo '<form method="get" action="'.esc_url(admin_url('tools.php')).'">';
  echo '<input type="hidden" name="page" value="mad4b-abela-2026">';
  echo '<label>Travel date <input type="date" name="travel_date" value="'.esc_attr($date).'" min="2026-01-01" max="2026-12-31"></label> ';
  echo '<label>Passenger market <select name="market">';
  foreach(array_keys(self::rates()) as $v)echo '<option value="'.esc_attr($v).'" '.selected($market,$v,false).'>'.esc_html($v).'</option>';
  echo '</select></label> <label>Cabin <select name="cabin">';
  foreach(['single_cabin','double_cabin','elite_single','elite_double','child_regular','child_elite'] as $v)
    echo '<option value="'.esc_attr($v).'" '.selected($cabin,$v,false).'>'.esc_html(str_replace('_',' ',$v)).'</option>';
  echo '</select></label> <button class="button button-primary" type="submit" name="preview" value="1">Show reference price</button></form>';
  if($result instanceof WP_Error)echo '<p><strong>'.esc_html($result->get_error_code()).'</strong></p>';
  elseif(is_array($result)){
    echo '<h2>Supplier published reference: '.esc_html($result['reference_amount'].' '.$result['currency']).' per passenger</h2>';
    echo '<p>Season: '.esc_html($result['season']).'. Reference only; not a confirmed quote and not valid for train 87 automatically.</p>';
  }
  echo '<p><a href="'.esc_url(admin_url('tools.php?page=mad4b-rail-fares')).'">Return to dated Rail Fare Requests</a></p></div>';
 }
}
add_action('admin_menu',['MAD4B_Abela_2026_Official_Reference_V1','menu']);
if(defined('WP_CLI')&&WP_CLI)
 WP_CLI::add_command('mad4b abela-tariff',['MAD4B_Abela_2026_Official_Reference_V1','cli']);
