<?php
/**
 * Plugin Name: MAD4B Tour 60623 Cruise + Rail Operations Board
 * Version: 1.0.0
 * Read-only editor view: candidate ships and source train timetable. Never authorizes booking.
 */
defined('ABSPATH')||exit;
if(!class_exists('MAD4B_Tour60623_Ops_Board_V1',false)){
final class MAD4B_Tour60623_Ops_Board_V1 {
 const CRUISE='_mad4b_60623_cruise_rail_candidates_2027_v1';
 const TRAIN='_mad4b_60623_train_2027_ops_reference_v1';
 public static function is_target(int $post):bool {
  if($post<1||get_post_type($post)!=='tours-and-activities')return false;
  if($post===60623)return true;
  if(class_exists('MAD4B_Cruise_Program_Context_V1',false)){
   $family=(array)MAD4B_Cruise_Program_Context_V1::family(60623);
   return in_array($post,array_map('intval',$family),true);
  }
  return false;
 }
 public static function setup($post):void {
  if(!$post instanceof WP_Post||!self::is_target($post->ID)||
     !current_user_can('edit_post',$post->ID))return;
  add_meta_box('mad4b-tour60623-candidate-cruise-rail',
   'Operations • Property First / Standard Rates + Sleeper Review',
   [self::class,'render'],'tours-and-activities','normal','high');
 }
 private static function reference_link(?int $id,string $label):string {
  $name=esc_html($label);
  if(!$id||!get_post($id))return $name.' <small>(no native ID)</small>';
  $url=get_edit_post_link($id,'raw');
  return '<a href="'.esc_url($url?:admin_url('post.php?post='.$id.'&action=edit')).'">'.
    esc_html((string)$id).' — '.$name.'</a>';
 }
 public static function render($post):void {
  $record=get_post_meta($post->ID,self::CRUISE,true);
  $train=get_post_meta($post->ID,self::TRAIN,true);
  if(!is_array($record)||!is_array($train)){
   echo '<p>Operations proposals have not been prepared for this translation.</p>';return;
  }
  echo '<p><strong>Property First policy:</strong> Choose a Cruise or Hotel as a Property, '.
    'not a cabin or room. Standard is the base Rate with factor <strong>1.00</strong> '.
    'when an eligible published retail Property Rate exists. Unit Rate is optional '.
    'for a later special category/upgrade.</p>';
  echo '<p><strong>These are operational choices, not confirmed inventory or a '.
    'complete package selling Rate.</strong> Unavailable Cruise service requires '.
    'the same-level alternative Cruise, or a separately priced revised hotel itinerary.</p>';
  if(!class_exists('MAD4B_Property_Standard_Base_V1',false)){
   echo '<p>Standard base pricing policy provider is unavailable.</p>';return;
  }
  $policy=MAD4B_Property_Standard_Base_V1::class;
  $standard=static function($id,$date,$nights)use($policy){
   if(!$id)return ['status'=>'external_property_not_in_catalog','amount'=>null,'rate_id'=>null];
   return $policy::preview($id,$date,$nights,2,0);
  };
  $status=static function($price){
   if(($price['status']??'')==='standard_retail_reference_available')
    return 'Standard Rate: '.(string)$price['rate_id'].' — '.
      (string)($price['display_base_amount']??'');
   return 'Standard Rate required (no eligible published selling Rate for these dates)';
  };
  echo '<h4>2027 Cruise options — select Property only, no cabin required for preview</h4>';
  echo '<table class="widefat striped"><thead><tr>'.
    '<th>Tour starts → Cruise starts</th><th>Tier</th><th>Cruise / Property</th>'.
    '<th>Standard base Rate</th><th>Operations action</th></tr></thead><tbody>';
  foreach(['monday','wednesday'] as $day){
   $d=$record['sailing_assignments_by_departure_weekday'][$day]??[];
   $checkin=$day==='monday'?'2027-01-13':'2027-01-15';
   foreach(['silver','gold'] as $level){
    $candidate=$d['level_candidates'][$level]??[];
    $id=absint($candidate['property_id']??0);
    $price=$standard($id,$checkin,3);
    echo '<tr><td>'.esc_html(ucfirst($day).' → '.($d['cruise_embark_name']??'?')).'</td>'.
      '<td>'.esc_html(ucfirst($level)).'</td>'.
      '<td>'.self::reference_link($id?:null,(string)($candidate['property_name']??'Property not assigned')).'</td>'.
      '<td>'.esc_html($status($price)).'</td>'.
      '<td>Request supplier sailing / tier suitability. '.
      esc_html((string)($candidate['sailing_pattern_status']??'')).'</td></tr>';
   }
  }
  echo '</tbody></table>';
  echo '<h4>Hotel options for existing Hotel stages — if first choice unavailable</h4>';
  $hotelStages=$policy::tour_hotel_stages((int)$post->ID);
  if(!$hotelStages)echo '<p>Original Hotel stage alternatives not configured.</p>';
  foreach($hotelStages as $stage){
   $nights=(int)$stage['nights'];
   $day=(int)$stage['day_offset'];
   $date=(new DateTimeImmutable('2027-01-11',new DateTimeZone('Africa/Cairo')))
       ->modify('+'.$day.' days')->format('Y-m-d');
   echo '<p><strong>'.esc_html((string)$stage['stage_key']).'</strong> — '.
     esc_html((string)$stage['destination']).', '.esc_html((string)$nights).' night(s).</p>';
   echo '<table class="widefat striped"><thead><tr>'.
     '<th>Tier</th><th>Hotel / Property</th><th>Standard base Rate</th></tr></thead><tbody>';
   foreach(['2353'=>'Silver','2354'=>'Gold'] as $tier=>$tierLabel){
    foreach($stage['tier_properties'][$tier]??[] as $hotel){
     $hotelId=(int)$hotel['id'];
     $amount=$standard($hotelId,$date,$nights);
     echo '<tr><td>'.esc_html($tierLabel).'</td><td>'.
       self::reference_link($hotelId,(string)$hotel['name']).'</td><td>'.
       esc_html($status($amount)).'</td></tr>';
    }
   }
   echo '</tbody></table>';
  }
  echo '<h4>If no Aswan–Luxor Cruise is available: hotels are a revised-program option</h4>';
  echo '<p>Hotel nights are not equivalent to a 3-night Nile Cruise. Operations must '.
    'revise visits, transportation, included meals, hotel nights and the Tour selling price '.
    'before offering this alternative as a substituted package.</p>';
  $revised=$policy::hotel_alternatives_for_revised_cruise_itinerary();
  echo '<table class="widefat striped"><thead><tr>'.
    '<th>City</th><th>Hotel / Property</th><th>Standard base Rate</th>'.
    '<th>Commercial status</th></tr></thead><tbody>';
  foreach($revised as $city=>$hotels){
   foreach($hotels as $hotel){
    $hotelId=(int)$hotel['id'];
    $price=$standard($hotelId,'2027-01-13',1);
    echo '<tr><td>'.esc_html($city).'</td><td>'.
      self::reference_link($hotelId,(string)$hotel['name']).'</td>'.
      '<td>'.esc_html($status($price)).'</td>'.
      '<td>Requires revised itinerary and price; not a confirmed substitute</td></tr>';
   }
  }
  echo '</tbody></table>';
  echo '<h4>Abela sleeper trains (2026 benchmark, recheck 2027)</h4>';
  echo '<table class="widefat striped"><thead><tr><th>Service</th><th>Stage</th><th>Timetable benchmark</th><th>2027 seats</th></tr></thead><tbody>';
  $services=(array)($train['train_services']??[]);
  foreach([86,87] as $no){
   $tr=$services[$no]??[];
   if(!$tr)continue;
   $info=$no===86?'Day 2 Cairo Upper Egypt/Giza → Aswan':'Day 6 Luxor → Giza/Cairo Upper Egypt';
   $hours=$no===86?'19:45 Bashteel / 20:15 Giza → 09:25 Aswan +1 day':
      '20:35 Luxor → 06:05 Giza / 06:25 Bashteel +1 day';
   echo '<tr><td>'.self::reference_link((int)($tr['id']??0),(string)($tr['name']??'Train')).'</td>'.
    '<td>'.esc_html($info).'</td><td>'.esc_html($hours).'</td>'.
    '<td><strong>Not verified</strong> — Abela date-specific booking required</td></tr>';
  }
  echo '</tbody></table>';
  echo '<p><strong>Example 2027 dates:</strong> 11 Jan → 13–16 Jan sailing; '.
       '13 Jan → 15–18 Jan sailing. Route 3 nights Aswan–Luxor.</p>';
  echo '<p><a href="https://abelatrains.com/" target="_blank" rel="noopener noreferrer">'.
       'Verify train 86/87 berths with Abela</a> · '.
       '<a href="https://staging.sonesta.com/sonesta-st-george-1-nile-cruise-ship/sail-dates" '.
       'target="_blank" rel="noopener noreferrer">Sonesta 2027 Friday sailings</a></p>';
  echo '<p><em>Property-first / Standard is approved as an authoring and preview hierarchy, '.
       'not an approval of missing supplier costs or Retail Rate amounts. Operations must '.
       'confirm named accommodation, available date and a total revised price before '.
       'booking; native Tour rates and Form 6 are unchanged.</em></p>';
 }
 public static function init():void {
  add_action('add_meta_boxes_tours-and-activities',[self::class,'setup'],31);
 }
}
add_action('init',[MAD4B_Tour60623_Ops_Board_V1::class,'init'],18);
}

