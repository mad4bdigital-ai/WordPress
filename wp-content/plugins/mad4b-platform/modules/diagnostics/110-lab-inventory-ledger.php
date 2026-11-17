<?php
/** Local-only prototype of atomic inventory holds. No WooCommerce hooks. */
if(!defined('ABSPATH')||wp_get_environment_type()!=='local')return;
function etg_lab_inventory_tables(){global $wpdb;return [$wpdb->prefix.'etg_lab_inventory_slots',$wpdb->prefix.'etg_lab_inventory_holds'];}
function etg_lab_inventory_install(){global $wpdb;[$slots,$holds]=etg_lab_inventory_tables();$charset=$wpdb->get_charset_collate();
 $a=$wpdb->query("CREATE TABLE IF NOT EXISTS `$slots` (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, resource_key VARCHAR(150) NOT NULL, slot_date DATE NOT NULL, capacity INT UNSIGNED NOT NULL, sold INT UNSIGNED NOT NULL DEFAULT 0, held INT UNSIGNED NOT NULL DEFAULT 0, UNIQUE KEY unique_resource_date (resource_key,slot_date)) ENGINE=InnoDB $charset");
 $b=$wpdb->query("CREATE TABLE IF NOT EXISTS `$holds` (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, hold_token VARCHAR(80) NOT NULL, resource_key VARCHAR(150) NOT NULL, slot_date DATE NOT NULL, quantity INT UNSIGNED NOT NULL, state VARCHAR(16) NOT NULL, created_at DATETIME NOT NULL, UNIQUE KEY unique_hold (hold_token), KEY resource_state (resource_key,slot_date,state)) ENGINE=InnoDB $charset");
 return ($a===false||$b===false)?new WP_Error('inventory_schema_failed',$wpdb->last_error):true;
}
function etg_lab_inventory_hold($resource,$date,$qty,$token){
 global $wpdb;[$slots,$holds]=etg_lab_inventory_tables();
 if(!preg_match('/^(unit|sailing):[0-9]+(:[0-9]+)?$/',$resource)||!preg_match('/^\d{4}-\d{2}-\d{2}$/',$date)||!preg_match('/^[A-Za-z0-9_-]{12,80}$/',$token)||!is_int($qty)||$qty<1)return new WP_Error('inventory_invalid_hold');
 $wpdb->query('START TRANSACTION');
 try{
  $existing=$wpdb->get_row($wpdb->prepare("SELECT * FROM `$holds` WHERE hold_token=%s FOR UPDATE",$token),ARRAY_A);
  if($existing)throw new Exception('inventory_token_reused');
  $slot=$wpdb->get_row($wpdb->prepare("SELECT * FROM `$slots` WHERE resource_key=%s AND slot_date=%s FOR UPDATE",$resource,$date),ARRAY_A);
  if(!$slot)throw new Exception('inventory_slot_missing');
  if((int)$slot['capacity']-(int)$slot['sold']-(int)$slot['held']<$qty)throw new Exception('inventory_capacity_exceeded');
  if($wpdb->update($slots,['held'=>(int)$slot['held']+$qty],['id'=>$slot['id']])!==1)throw new Exception('inventory_hold_write_failed');
  if($wpdb->insert($holds,['hold_token'=>$token,'resource_key'=>$resource,'slot_date'=>$date,'quantity'=>$qty,'state'=>'held','created_at'=>current_time('mysql',true),'expires_at'=>gmdate('Y-m-d H:i:s',time()+900)])!==1)throw new Exception('inventory_hold_insert_failed');
  $wpdb->query('COMMIT');return ['token'=>$token,'state'=>'held','resource'=>$resource,'date'=>$date,'quantity'=>$qty,'checkout_ready'=>false];
 }catch(Throwable $e){$wpdb->query('ROLLBACK');return new WP_Error($e->getMessage());}
}
function etg_lab_inventory_transition($token,$target){
 global $wpdb;[$slots,$holds]=etg_lab_inventory_tables();
 if(!in_array($target,['confirmed','released'],true))return new WP_Error('inventory_transition_invalid');
 $wpdb->query('START TRANSACTION');
 try{
  $h=$wpdb->get_row($wpdb->prepare("SELECT * FROM `$holds` WHERE hold_token=%s FOR UPDATE",$token),ARRAY_A);
  if(!$h||$h['state']!=='held')throw new Exception('inventory_hold_not_active');
  if(empty($h['expires_at'])||$h['expires_at']<=gmdate('Y-m-d H:i:s'))throw new Exception('inventory_hold_expired');
  $s=$wpdb->get_row($wpdb->prepare("SELECT * FROM `$slots` WHERE resource_key=%s AND slot_date=%s FOR UPDATE",$h['resource_key'],$h['slot_date']),ARRAY_A);
  if(!$s||(int)$s['held']<(int)$h['quantity'])throw new Exception('inventory_slot_inconsistent');
  $delta=(int)$h['quantity'];$new=['held'=>(int)$s['held']-$delta];if($target==='confirmed')$new['sold']=(int)$s['sold']+$delta;
  if($wpdb->update($slots,$new,['id'=>$s['id']])!==1||$wpdb->update($holds,['state'=>$target],['id'=>$h['id']])!==1)throw new Exception('inventory_transition_write_failed');
  $wpdb->query('COMMIT');return ['token'=>$token,'state'=>$target,'checkout_ready'=>false];
 }catch(Throwable $e){$wpdb->query('ROLLBACK');return new WP_Error($e->getMessage());}
}
/** Atomic hold of an entire stay: one transaction, one stable token per night. */
function etg_lab_inventory_hold_stay($resource,$checkin,$checkout,$qty,$token){
 global $wpdb;[$slots,$holds]=etg_lab_inventory_tables();
 if(!preg_match('/^unit:[0-9]+$/',$resource)||!preg_match('/^[A-Za-z0-9_-]{12,60}$/',$token)||!is_int($qty)||$qty<1||$qty>99)return new WP_Error('inventory_stay_invalid_input');
 $a=DateTimeImmutable::createFromFormat('!Y-m-d',(string)$checkin);$b=DateTimeImmutable::createFromFormat('!Y-m-d',(string)$checkout);
 if(!$a||!$b||$a->format('Y-m-d')!==$checkin||$b->format('Y-m-d')!==$checkout||$b<=$a||$a->diff($b)->days>60)return new WP_Error('inventory_stay_invalid_dates');
 $dates=[];foreach(new DatePeriod($a,new DateInterval('P1D'),$b) as $day)$dates[]=$day->format('Y-m-d');
 if($wpdb->query('START TRANSACTION')===false)return new WP_Error('inventory_transaction_unavailable');
 try{
  // Retried request must exactly match every previously recorded night and quantity.
  $existing=[];
  foreach($dates as $date){
   $night_token=$token.'_'.str_replace('-','',$date);
   $prev=$wpdb->get_row($wpdb->prepare("SELECT * FROM `$holds` WHERE hold_token=%s FOR UPDATE",$night_token),ARRAY_A);
   if($prev)$existing[]=$prev;
  }
  if($existing){
   if(count($existing)!==count($dates))throw new Exception('inventory_stay_partial_token_conflict');
   foreach($existing as $prev)if($prev['resource_key']!==$resource||(int)$prev['quantity']!==$qty||$prev['state']!=='held'||empty($prev['expires_at'])||$prev['expires_at']<=gmdate('Y-m-d H:i:s'))throw new Exception('inventory_stay_retry_conflict');
   $wpdb->query('COMMIT');return ['token'=>$token,'nights'=>count($dates),'quantity'=>$qty,'state'=>'held','idempotent'=>true,'checkout_ready'=>false];
  }
  $locked=[];
  foreach($dates as $date){
   $night_token=$token.'_'.str_replace('-','',$date);
   $dupe=$wpdb->get_var($wpdb->prepare("SELECT id FROM `$holds` WHERE hold_token=%s",$night_token));
   if($dupe)throw new Exception('inventory_token_reused');
   $slot=$wpdb->get_row($wpdb->prepare("SELECT * FROM `$slots` WHERE resource_key=%s AND slot_date=%s FOR UPDATE",$resource,$date),ARRAY_A);
   if(!$slot)throw new Exception('inventory_slot_missing');
   if((int)$slot['capacity']-(int)$slot['sold']-(int)$slot['held']<$qty)throw new Exception('inventory_capacity_exceeded');
   $locked[]=[$slot,$date,$night_token];
  }
  foreach($locked as [$slot,$date,$night_token]){
   if($wpdb->update($slots,['held'=>(int)$slot['held']+$qty],['id'=>$slot['id']])!==1)throw new Exception('inventory_hold_write_failed');
   if($wpdb->insert($holds,['hold_token'=>$night_token,'resource_key'=>$resource,'slot_date'=>$date,'quantity'=>$qty,'state'=>'held','created_at'=>current_time('mysql',true),'expires_at'=>gmdate('Y-m-d H:i:s',time()+900)])!==1)throw new Exception('inventory_hold_insert_failed');
   if(defined('ETG_LAB_INVENTORY_FAULT_AFTER_FIRST_NIGHT') && ETG_LAB_INVENTORY_FAULT_AFTER_FIRST_NIGHT && $date===$dates[0])throw new Exception('inventory_injected_failure');
  }
  $groups=$wpdb->prefix.'etg_lab_inventory_groups';
  if($wpdb->insert($groups,['group_token'=>$token,'resource_key'=>$resource,'checkin'=>$checkin,'checkout'=>$checkout,'quantity'=>$qty,'created_at'=>gmdate('Y-m-d H:i:s'),'expires_at'=>gmdate('Y-m-d H:i:s',time()+900)])!==1)throw new Exception('inventory_group_insert_failed');
  if($wpdb->query('COMMIT')===false)throw new Exception('inventory_commit_failed');
  return ['token'=>$token,'nights'=>count($dates),'quantity'=>$qty,'state'=>'held','checkout_ready'=>false];
 }catch(Throwable $e){$wpdb->query('ROLLBACK');return new WP_Error($e->getMessage());}
}
/** Multi-night state transition under one transaction; repeated same target returns prior result. */
function etg_lab_inventory_transition_stay($resource,$checkin,$checkout,$token,$target){
 global $wpdb;[$slots,$holds]=etg_lab_inventory_tables();
 if(!preg_match('/^unit:[0-9]+$/',(string)$resource)||!preg_match('/^[A-Za-z0-9_-]{12,60}$/',(string)$token)||!in_array($target,['confirmed','released'],true))return new WP_Error('inventory_stay_transition_invalid');
 $a=DateTimeImmutable::createFromFormat('!Y-m-d',(string)$checkin);$b=DateTimeImmutable::createFromFormat('!Y-m-d',(string)$checkout);
 if(!$a||!$b||$a->format('Y-m-d')!==$checkin||$b->format('Y-m-d')!==$checkout||$b<=$a||$a->diff($b)->days>60)return new WP_Error('inventory_stay_invalid_dates');
 $days=[];foreach(new DatePeriod($a,new DateInterval('P1D'),$b) as $d)$days[]=$d->format('Y-m-d');
 if($wpdb->query('START TRANSACTION')===false)return new WP_Error('inventory_transaction_unavailable');
 try{
  $entries=[];
  foreach($days as $day){
   $night_token=$token.'_'.str_replace('-','',$day);
   $h=$wpdb->get_row($wpdb->prepare("SELECT * FROM `$holds` WHERE hold_token=%s FOR UPDATE",$night_token),ARRAY_A);
   if(!$h||$h['resource_key']!==$resource||$h['slot_date']!==$day)throw new Exception('inventory_stay_hold_missing');
   $entries[]=$h;
  }
  $states=array_unique(array_column($entries,'state'));
  if(count($states)!==1)throw new Exception('inventory_stay_state_inconsistent');
  if($states[0]===$target){$wpdb->query('COMMIT');return ['state'=>$target,'idempotent'=>true,'nights'=>count($days),'checkout_ready'=>false];}
  if($states[0]!=='held')throw new Exception('inventory_stay_transition_conflict');
  if($target==='confirmed')foreach($entries as $entry)if(empty($entry['expires_at'])||$entry['expires_at']<=gmdate('Y-m-d H:i:s'))throw new Exception('inventory_hold_expired');
  foreach($entries as $h){
   $s=$wpdb->get_row($wpdb->prepare("SELECT * FROM `$slots` WHERE resource_key=%s AND slot_date=%s FOR UPDATE",$resource,$h['slot_date']),ARRAY_A);
   if(!$s||(int)$s['held']<(int)$h['quantity'])throw new Exception('inventory_slot_inconsistent');
   $delta=(int)$h['quantity'];$new=['held'=>(int)$s['held']-$delta];if($target==='confirmed')$new['sold']=(int)$s['sold']+$delta;
   if($wpdb->update($slots,$new,['id'=>$s['id']])!==1||$wpdb->update($holds,['state'=>$target],['id'=>$h['id']])!==1)throw new Exception('inventory_transition_write_failed');
  }
  if($wpdb->query('COMMIT')===false)throw new Exception('inventory_commit_failed');
  return ['state'=>$target,'idempotent'=>false,'nights'=>count($days),'checkout_ready'=>false];
 }catch(Throwable $e){$wpdb->query('ROLLBACK');return new WP_Error($e->getMessage());}
}

/** Expire an entire multi-night hold atomically; never expire confirmed stock. */
function etg_lab_inventory_expire_stay($resource,$checkin,$checkout,$token){
 global $wpdb;[$slots,$holds]=etg_lab_inventory_tables();
 if(!preg_match('/^unit:[0-9]+$/',(string)$resource)||!preg_match('/^[A-Za-z0-9_-]{12,60}$/',(string)$token))return new WP_Error('inventory_stay_invalid_input');
 $a=DateTimeImmutable::createFromFormat('!Y-m-d',(string)$checkin);$b=DateTimeImmutable::createFromFormat('!Y-m-d',(string)$checkout);
 if(!$a||!$b||$a->format('Y-m-d')!==$checkin||$b->format('Y-m-d')!==$checkout||$b<=$a||$a->diff($b)->days>60)return new WP_Error('inventory_stay_invalid_dates');
 if($wpdb->query('START TRANSACTION')===false)return new WP_Error('inventory_transaction_unavailable');
 try{
  $rows=[];foreach(new DatePeriod($a,new DateInterval('P1D'),$b) as $day){
   $date=$day->format('Y-m-d');$night=$token.'_'.str_replace('-','',$date);
   $h=$wpdb->get_row($wpdb->prepare("SELECT * FROM `$holds` WHERE hold_token=%s FOR UPDATE",$night),ARRAY_A);
   if(!$h||$h['resource_key']!==$resource||$h['slot_date']!==$date)throw new Exception('inventory_stay_hold_missing');$rows[]=$h;
  }
  $states=array_unique(array_column($rows,'state'));
  if(count($states)!==1)throw new Exception('inventory_stay_state_inconsistent');
  if($states[0]==='expired'){$wpdb->query('COMMIT');return ['state'=>'expired','idempotent'=>true,'checkout_ready'=>false];}
  if($states[0]!=='held')throw new Exception('inventory_stay_transition_conflict');
  foreach($rows as $h)if(empty($h['expires_at'])||$h['expires_at']>gmdate('Y-m-d H:i:s'))throw new Exception('inventory_stay_not_expired');
  foreach($rows as $h){
   $slot=$wpdb->get_row($wpdb->prepare("SELECT * FROM `$slots` WHERE resource_key=%s AND slot_date=%s FOR UPDATE",$resource,$h['slot_date']),ARRAY_A);
   if(!$slot||(int)$slot['held']<(int)$h['quantity'])throw new Exception('inventory_slot_inconsistent');
   if($wpdb->update($slots,['held'=>(int)$slot['held']-(int)$h['quantity']],['id'=>$slot['id']])!==1||$wpdb->update($holds,['state'=>'expired'],['id'=>$h['id']])!==1)throw new Exception('inventory_expire_write_failed');
  }
  if($wpdb->query('COMMIT')===false)throw new Exception('inventory_commit_failed');
  return ['state'=>'expired','idempotent'=>false,'checkout_ready'=>false];
 }catch(Throwable $e){$wpdb->query('ROLLBACK');return new WP_Error($e->getMessage());}
}
