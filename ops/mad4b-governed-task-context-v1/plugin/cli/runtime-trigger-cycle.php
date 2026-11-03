<?php
defined('ABSPATH') || exit;
if(!defined('WP_CLI')||!WP_CLI||PHP_SAPI!=='cli')
 throw new RuntimeException('CLI only');
if(wp_get_environment_type()!=='staging' ||
   !current_user_can('manage_options') ||
   !MAD4B_Hub_Dynamic_Runtime_Resolver::writable())
 throw new RuntimeException('Authorized Staging WP-CLI user required');
$repair=MAD4B_Hub_Trigger_Bus::sweep(15);
if(is_wp_error($repair))throw new RuntimeException($repair->get_error_code());
global $wpdb;
$b=$wpdb->prefix.'mad4b_hub_bulk_batches';
$t=$wpdb->prefix.'mad4b_hub_bulk_tasks';
$ids=$wpdb->get_col("SELECT DISTINCT b.batch_id FROM $b b
 INNER JOIN $t q ON q.batch_id=b.batch_id
 WHERE b.state='ACTIVE' AND b.batch_key LIKE 'event.%'
 AND q.operation='runtime_resolve' AND q.state IN ('QUEUED','RETRY')
 AND q.available_at<=UTC_TIMESTAMP()
 ORDER BY b.batch_id ASC LIMIT 4");
$results=[];
foreach((array)$ids as $id){
 if(!preg_match('/^[a-f0-9-]{36}$/D',$id))continue;
 $run=MAD4B_Hub_Bulk_VPS_Runner::tick(1,$id);
 $results[]=['batch_id'=>$id,
   'status'=>is_wp_error($run)?$run->get_error_code():'processed',
   'handled'=>is_wp_error($run)?0:(int)($run['processed']??0)];
}
echo wp_json_encode([
 'contract'=>'mad4b.hub.runtime-cycle.v1',
 'sweep'=>$repair,'runtime_resolver_batches'=>$results,
 'auto_provider_dispatch_performed'=>false,
 'runtime_stage_work_automatic_execution_requested'=>false,
 'site'=>home_url('/'),'checked_at_utc'=>gmdate('c')
],JSON_UNESCAPED_SLASHES)."\n";
