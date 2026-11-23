<?php
/* Enforce JetEngine native catalog privacy. All saved values preserved. */
wp_set_current_user(1);
if(!current_user_can('manage_options'))throw new RuntimeException('rest_lock_access_denied');
$w=$GLOBALS['wpdb'];$record=[];
foreach([
 ['post_types','operational-services'],
 ['taxonomies','service-family'],
 ['taxonomies','service-type']
] as [$kind,$slug]){
 $table=$w->prefix.'jet_'.$kind;
 $rows=$w->get_results($w->prepare("SELECT * FROM $table WHERE slug=%s AND status='publish'",$slug),ARRAY_A);
 if(count((array)$rows)!==1)throw new RuntimeException('jet_registry_mismatch:'.$slug);
 $row=$rows[0];$args=maybe_unserialize($row['args']);
 if(!is_array($args))throw new RuntimeException('jet_schema_unreadable:'.$slug);
 $record[]=['table'=>$table,'row'=>$row];
}
$backup='/tmp/mad4b-operational-services-jet-registry-before-rest-lock.json';
if(file_put_contents($backup,wp_json_encode($record,JSON_UNESCAPED_UNICODE))===false)
 throw new RuntimeException('jet_registry_backup_write_failed');
$report=[];
foreach($record as $entry){
 $table=$entry['table'];$row=$entry['row'];$args=maybe_unserialize($row['args']);
 $args['show_in_rest']=false;
 $args['public']=false;
 $args['publicly_queryable']=false;
 $change=$w->update($table,['args'=>maybe_serialize($args)],['id'=>(int)$row['id']],['%s'],['%d']);
 if($change===false)throw new RuntimeException('native_jet_registry_write_failed:'.$row['id']);
 $saved=$w->get_var($w->prepare("SELECT args FROM $table WHERE id=%d",(int)$row['id']));
 $verify=maybe_unserialize($saved);
 if(!is_array($verify)||($verify['show_in_rest']??null)!==false)
   throw new RuntimeException('native_jet_rest_readback_failed:'.$row['id']);
 $report[]=['id'=>(int)$row['id'],'table'=>$table,'show_in_rest'=>false,'public'=>false];
}
echo 'JET_REST_PRIVACY_LOCK='.wp_json_encode([
  'site'=>home_url('/'),'native_registry'=>$report,'backup'=>$backup],
  JSON_UNESCAPED_UNICODE)."\n";
