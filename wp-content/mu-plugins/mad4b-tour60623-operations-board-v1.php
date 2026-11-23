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
   'Operations • 2027 Cruise & Sleeper Candidates (Unconfirmed)',
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
  echo '<p><strong>Editorial/Operations candidates only.</strong> '.
   'No 2027 ship cabin inventory, contract, net cost or sleeper berth is confirmed. '.
   'This does not assign the native Tour ship or authorize payment.</p>';
  echo '<table class="widefat striped"><thead><tr><th>Tour begins</th><th>Level</th><th>Ship / Property</th>'.
   '<th>Cabin / Unit</th><th>Verification</th></tr></thead><tbody>';
  foreach(['monday','wednesday'] as $day){
   $d=$record['sailing_assignments_by_departure_weekday'][$day]??[];
   foreach(['silver','gold'] as $level){
    $candidate=$d['level_candidates'][$level]??[];
    echo '<tr><td>'.esc_html(ucfirst($day)).' → '.esc_html((string)($d['cruise_embark_name']??'?')).'</td>'.
     '<td>'.esc_html(ucfirst($level)).'</td>'.
     '<td>'.self::reference_link(isset($candidate['property_id'])?(int)$candidate['property_id']:null,
         (string)($candidate['property_name']??'Not assigned')).'</td>'.
     '<td>'.self::reference_link(isset($candidate['unit_id'])?(int)$candidate['unit_id']:null,
         (string)($candidate['unit_name']??'Category pending')).'</td>'.
     '<td><strong>Pending</strong>: date-specific inventory, supplier cost, cabin occupancy, tier margin. '.
       esc_html((string)($candidate['sailing_pattern_status']??'')).'</td></tr>';
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
  echo '<p><em>Supplier cost receipts, actual occupancy and ferry/ship boarding cutoff must be verified '.
       'before an Operations order can be considered confirmed. Existing Tour rates and Form 6 are unchanged.</em></p>';
 }
 public static function init():void {
  add_action('add_meta_boxes_tours-and-activities',[self::class,'setup'],31);
 }
}
add_action('init',[MAD4B_Tour60623_Ops_Board_V1::class,'init'],18);
}

