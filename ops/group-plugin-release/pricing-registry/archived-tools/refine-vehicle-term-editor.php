<?php
/** Update JetEngine field descriptions and labels without touching term values. */
defined('ABSPATH')&&defined('WP_CLI')&&WP_CLI||exit(2);
$site=rtrim(home_url('/'),'/');
if(!in_array($site,['https://tours.dev.mad4b.com','https://wayfatours.com'],true))
 WP_CLI::error('site_guard');
$apply=defined('MAD4B_VEHICLE_TERM_EDITOR_APPLY')&&MAD4B_VEHICLE_TERM_EDITOR_APPLY;
$names=MAD4B_Transport_Vehicle_Term_Profiles_V1::META;
$titles=[
 'fixed'=>'Vehicle BASE — Included Minimum (EGP)',
 'per_km'=>'Operating Cost / KM (EGP)',
 'per_drive_min'=>'Driving Cost / Minute (EGP)',
 'per_wait_min'=>'Waiting Cost / Minute (EGP)',
 'overnight'=>'Overnight Vehicle Cost (EGP)',
 'min_fare'=>'Minimum Fare (EGP)',
 'deadhead_pct'=>'Empty Return Distance (%)'
];
$hint=[
 'fixed'=>'BASE is a minimum INCLUDED in movement cost, not an extra surcharge.',
 'per_km'=>'Operating-cost coefficient. Source PDF total costs are actual EGP; split is a proposal.',
 'per_drive_min'=>'Driving-minute operating coefficient. Does not include waiting.',
 'per_wait_min'=>'Additional WAITING operating cost; distinct from driving.',
 'overnight'=>'Vehicle operating cost per overnight stay.',
 'min_fare'=>'Separate minimum fare before the approved selling-price workflow.',
 'deadhead_pct'=>'Empty return distance percent. This is a percentage, not EGP.'
];
global $wpdb;
$table=$wpdb->prefix.'jet_taxonomies';
$row=$wpdb->get_row($wpdb->prepare("SELECT id,meta_fields FROM {$table} WHERE slug=%s",
 MAD4B_Transport_Vehicle_Term_Profiles_V1::TAXONOMY),ARRAY_A);
if(!$row)WP_CLI::error('native_taxonomy_missing');
$fields=(array)maybe_unserialize($row['meta_fields']);
if(count($fields)!==10)WP_CLI::error('unexpected_field_count');
$expected=array_values($names);
$got=array_map(static fn($f)=>$f['name']??'',$fields);
if(array_slice($got,0,7)!==$expected)WP_CLI::error('core_field_keys_changed');
$old=$row['meta_fields'];
foreach($fields as &$field){
 $key=array_search($field['name'],$names,true);
 if($key===false)continue;
 if(($field['type']??'')!=='number')WP_CLI::error('type_changed_'.$key);
 $field['title']=$titles[$key];
 $field['description']=$hint[$key];
 $field['width']='50%';
}
unset($field);
$new=serialize($fields);
$backupKey='mad4b_vehicle_term_editor_schema_backup_20261120_v1';
$backup=get_option($backupKey,false);
if($backup!==false&&(!is_array($backup)||($backup['site']??'')!==$site||
  ($backup['expected_after']??'')!==hash('sha256',$new)))
 WP_CLI::error('schema_backup_conflict');
if(!$apply){
 echo wp_json_encode(['checkpoint'=>'EDITOR_PREFLIGHT','site'=>$site,
  'fields'=>count($fields),'changes_needed'=>$old!==$new,
  'term_values_unchanged'=>true])."\n";return;
}
if($backup===false)
 if(!add_option($backupKey,['site'=>$site,'before'=>$old,
  'expected_after'=>hash('sha256',$new),'at'=>gmdate('c')],'',false))
  WP_CLI::error('backup_create_failed');
if($old!==$new)
 if($wpdb->update($table,['meta_fields'=>$new],['id'=>(int)$row['id']],
  ['%s'],['%d'])!==1)WP_CLI::error('schema_edit_failed');
$read=$wpdb->get_var($wpdb->prepare("SELECT meta_fields FROM {$table} WHERE id=%d",
 (int)$row['id']));
if(hash('sha256',(string)$read)!==hash('sha256',$new))
 WP_CLI::error('editor_schema_readback_mismatch');
echo wp_json_encode(['checkpoint'=>'EDITOR_NATIVE_FIELDS_READY','site'=>$site,
 'field_count'=>10,'term_cost_data_touched'=>false,'backup'=>$backupKey])."\n";
