<?php
/** Connection-local MySQL shadow rehearsal. Never grants live apply. */
if(!defined('WP_CLI') || !WP_CLI || !defined('ABSPATH')) throw new RuntimeException('wp_cli_required');
$old=getenv('MAD4B_EVOLVE_OLD'); $new=getenv('MAD4B_EVOLVE_NEW'); $expected=getenv('MAD4B_EVOLVE_EXACT_HOME');
if(!is_string($old)||!preg_match('/^[a-z][a-z0-9_]{2,60}$/D',$old)||
   !is_string($new)||!preg_match('/^[a-z][a-z0-9_]{2,60}$/D',$new)||
   $old===$new||$expected!==home_url()||wp_get_environment_type()!=='staging')
   throw new RuntimeException('exact_staging_binding_required');
global $wpdb;
$rows=[
 ['name'=>'definition','table'=>$wpdb->prefix.'jet_taxonomies','col'=>'slug','key'=>'id','from'=>$old,'to'=>$new],
 ['name'=>'terms','table'=>$wpdb->term_taxonomy,'col'=>'taxonomy','key'=>'term_taxonomy_id','from'=>$old,'to'=>$new],
 ['name'=>'wpml','table'=>$wpdb->prefix.'icl_translations','col'=>'element_type','key'=>'translation_id','from'=>'tax_'.$old,'to'=>'tax_'.$new]
];
$made=[];$result=['contract'=>'mad4b.taxonomy-evolution-native-shadow.v1','site'=>home_url(),'state'=>'BLOCKED','persistent_writes'=>false,'observed'=>[],'forward'=>[],'rollback_hash_verified'=>false];
try {
 foreach($rows as $i=>$r) {
   $tmp=$wpdb->prefix.'mad4b_tax_evo_tmp_'.($i+1);
   if($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s',$tmp))!==null)throw new RuntimeException('temporary_name_collision');
   if((int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$r['table']} WHERE {$r['col']}=%s",$r['to']))!==0)throw new RuntimeException('canonical_collision');
   $where=$wpdb->prepare("{$r['col']}=%s",$r['from']);
   if($wpdb->query("CREATE TEMPORARY TABLE $tmp AS SELECT * FROM {$r['table']} WHERE $where")===false)throw new RuntimeException('temporary_create_failed');
   $made[]=$tmp;
   $v=$wpdb->get_results("SELECT * FROM $tmp ORDER BY {$r['key']}",ARRAY_A);
   if(!is_array($v))throw new RuntimeException('shadow_read_failed');
   $rows[$i]['temp']=$tmp;$rows[$i]['count']=count($v);
   $rows[$i]['sha']=hash('sha256',wp_json_encode($v,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
   $result['observed'][$r['name']]=count($v);
 }
 if($rows[0]['count']!==1 || $rows[1]['count']===0)throw new RuntimeException('legacy_schema_incomplete');
 foreach($rows as $r) {
   $sql=$wpdb->prepare("UPDATE {$r['temp']} SET {$r['col']}=%s WHERE {$r['col']}=%s",$r['to'],$r['from']);
   if($wpdb->query($sql)===false)throw new RuntimeException('temporary_forward_failed');
   $n=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$r['temp']} WHERE {$r['col']}=%s",$r['to']));
   if($n!==$r['count'])throw new RuntimeException('temporary_forward_count_mismatch');
   $result['forward'][$r['name']]=$n;
 }
 foreach($rows as $r) {
   $sql=$wpdb->prepare("UPDATE {$r['temp']} SET {$r['col']}=%s WHERE {$r['col']}=%s",$r['from'],$r['to']);
   if($wpdb->query($sql)===false)throw new RuntimeException('temporary_reverse_failed');
   $v=$wpdb->get_results("SELECT * FROM {$r['temp']} ORDER BY {$r['key']}",ARRAY_A);
   if(!hash_equals($r['sha'],hash('sha256',wp_json_encode($v,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES))))throw new RuntimeException('rollback_hash_mismatch');
 }
 $result['state']='SHADOW_PASS';$result['rollback_hash_verified']=true;
} catch(Throwable $e) {$result['reason']=$e->getMessage();}
finally {foreach(array_reverse($made) as $tmp)$wpdb->query("DROP TEMPORARY TABLE IF EXISTS $tmp");}
echo wp_json_encode($result,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),PHP_EOL;
