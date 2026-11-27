<?php
if(!defined('WP_CLI')||!WP_CLI||wp_get_environment_type()!=='staging')exit(9);
global $wpdb;
$table='mad4b_adaptive_test_'.substr(hash('sha256',wp_generate_uuid4()),0,12);
$source=$wpdb->prefix.'mad4b_pd_edges';
if($wpdb->query("CREATE TEMPORARY TABLE $table LIKE $source")===false)throw new RuntimeException('isolated table unavailable');
try{
 $hash=str_repeat('a',64);$expiry=gmdate('Y-m-d H:i:s',time()+600);
 $deps=[];for($i=1;$i<=140;$i++)$deps['post:'.$i]=true;
 $t=hrtime(true);$q=$wpdb->num_queries;
 foreach(array_keys($deps) as $dep)if($wpdb->query($wpdb->prepare("INSERT INTO $table (dep_hash,page_hash,dep_key,expires_at) VALUES (%s,%s,%s,%s)",hash('sha256',$dep),$hash,$dep,$expiry))!==1)throw new RuntimeException('legacy insert failed');
 $old=['ms'=>round((hrtime(true)-$t)/1e6,2),'sql'=>$wpdb->num_queries-$q];
 $before=$wpdb->get_results("SELECT * FROM $table ORDER BY dep_hash",ARRAY_A);
 $wpdb->query("DELETE FROM $table");
 $m=new ReflectionMethod('MAD4B_Page_Dependency_Cache','insert_edges');
 $q=$wpdb->num_queries;$t=hrtime(true);
 if(!$m->invoke(null,$table,$hash,$deps,$expiry))throw new RuntimeException('batched insert failed');
 $new=['ms'=>round((hrtime(true)-$t)/1e6,2),'sql'=>$wpdb->num_queries-$q];
 $after=$wpdb->get_results("SELECT * FROM $table ORDER BY dep_hash",ARRAY_A);
 if($before!==$after||count($after)!==140)throw new RuntimeException('edge parity failed');
 echo wp_json_encode(['old'=>$old,'new'=>$new,'exact_rows'=>count($after),'isolated_temporary_table'=>true,'production_rows_changed'=>false]).PHP_EOL;
}finally{$wpdb->query("DROP TEMPORARY TABLE IF EXISTS $table");}
