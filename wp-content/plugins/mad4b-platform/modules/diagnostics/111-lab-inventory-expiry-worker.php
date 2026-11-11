<?php
/** Local-only bounded on-demand worker. Never expose publicly or run automatically by default. */
if(!defined('ABSPATH')||wp_get_environment_type()!=='local')return;
function etg_lab_inventory_expiry_sweep($limit=20){
 global $wpdb;
 $limit=max(1,min(100,(int)$limit));$groups=$wpdb->prefix.'etg_lab_inventory_groups';
 $now=gmdate('Y-m-d H:i:s');
 $rows=$wpdb->get_results($wpdb->prepare("SELECT g.group_token,g.resource_key,g.checkin,g.checkout FROM `$groups` g JOIN `{$wpdb->prefix}etg_lab_inventory_holds` h ON h.hold_token=CONCAT(g.group_token,'_',DATE_FORMAT(g.checkin,'%%Y%%m%%d')) AND h.state='held' WHERE g.expires_at<=%s ORDER BY g.expires_at,g.id LIMIT %d",$now,$limit),ARRAY_A);
 if(!is_array($rows))return new WP_Error('inventory_expiry_scan_failed',$wpdb->last_error);
 $out=['scanned'=>count($rows),'expired'=>0,'already_processed'=>0,'failed'=>[],'scheduled'=>false];
 foreach($rows as $row){
  $result=etg_lab_inventory_expire_stay($row['resource_key'],$row['checkin'],$row['checkout'],$row['group_token']);
  if(is_wp_error($result)){
   // Terminal outcomes are intentionally retained as audit evidence, not removed.
   $out['failed'][]=['group_token'=>$row['group_token'],'code'=>$result->get_error_code()];continue;
  }
  if(!empty($result['idempotent']))$out['already_processed']++;else $out['expired']++;
 }
 return $out;
}
/** WP-Cron is traffic-dependent; this provides bounded lab-only automatic sweeps. */
add_filter('cron_schedules',static function($schedules){$schedules['etg_lab_inventory_five_minutes']=['interval'=>300,'display'=>'ETG Lab Inventory Expiration (5 minutes)'];return $schedules;});
add_action('etg_lab_inventory_expiry_tick',static function(){etg_lab_inventory_expiry_sweep(20);});
add_action('init',static function(){if(!wp_next_scheduled('etg_lab_inventory_expiry_tick'))wp_schedule_event(time()+300,'etg_lab_inventory_five_minutes','etg_lab_inventory_expiry_tick');},100);
