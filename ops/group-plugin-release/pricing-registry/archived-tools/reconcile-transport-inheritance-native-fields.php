<?php
/** Reuse native transport fields, add only missing cost overrides to JetEngine CPTs. */
defined('ABSPATH')&&defined('WP_CLI')&&WP_CLI||exit(2);
$site=rtrim(home_url('/'),'/');
if(!in_array($site,['https://tours.dev.mad4b.com','https://wayfatours.com'],true))
 WP_CLI::error('site_not_allowed');
$apply=defined('MAD4B_COST_INHERITANCE_APPLY')&&MAD4B_COST_INHERITANCE_APPLY;
$rollback=defined('MAD4B_COST_INHERITANCE_ROLLBACK')&&MAD4B_COST_INHERITANCE_ROLLBACK;
if($apply&&$rollback)WP_CLI::error('conflicting_modes');
global $wpdb;
$table=$wpdb->prefix.'jet_post_types';
$rows=[];
foreach(['transportations'=>[173,70], 'transport-rates'=>[358,37],
 'tour-rates'=>[328,53]] as $name=>[$id,$count]){
 $row=$wpdb->get_row($wpdb->prepare("SELECT id,status,meta_fields FROM {$table} WHERE slug=%s",$name),ARRAY_A);
 if(!$row||(int)$row['id']!==$id||$row['status']!=='publish')
  WP_CLI::error('cpt_identity_'.$name);
 $fields=(array)maybe_unserialize($row['meta_fields']);
 if(!in_array(count($fields),[$count,$count+($name==='transportations'?1:10)],true))
  WP_CLI::error('cpt_count_conflict_'.$name.'_'.count($fields));
 $rows[$name]=['row'=>$row,'fields'=>$fields,'names'=>array_column($fields,'name')];
}
$required=[
 'transportations'=>['trans_included_km','trans_route_estimated_duration',
   'trans_use_in_tour_rate_generation','trans_from_station_id','trans_to_station_id'],
 'transport-rates'=>['source_post_id','trans_vehicle_category_key','trans_cost_amount',
   'trans_price_role','trans_supplier_discount_pct','trans_extra_km_price_base',
   'trans_overtime_price_per_hour_base','trans_review_status'],
 'tour-rates'=>['related_transportations_id','_mad4b_transport_stages_v1',
   'transport_markup_override_pct','transport_price_policy']
];
foreach($required as $type=>$names)
 foreach($names as $name)
  if(!in_array($name,$rows[$type]['names'],true))
   WP_CLI::error('missing_native_field_'.$type.'_'.$name);
$choices=static fn($a)=>array_map(static fn($s)=>['key'=>$s,'value'=>ucwords(str_replace('_',' ',$s))],$a);
$vehicles=['limousine','luxury_7_seats','h1','hiace','coaster','bus'];
$select=static fn($name,$title,$values,$id,$default='inherit')=>[
 'name'=>$name,'title'=>$title,'type'=>'select','object_type'=>'field',
 'options_source'=>'manual','options'=>$choices($values),
 'default_val'=>$default,'id'=>$id,'width'=>'50%'
];
$num=static fn($name,$title,$id)=>[
 'name'=>$name,'title'=>$title,'type'=>'number','object_type'=>'field',
 'options_source'=>'manual','default_val'=>'','id'=>$id,'width'=>'33.333%',
 'min_value'=>0,'max_value'=>200000,'step_value'=>0.01,
 'description'=>'Optional operating COST override in EGP; blank inherits, zero overrides. Not a selling rate.'
];
$metrics=[
 'fixed'=>'Vehicle call-out BASE (EGP; included minimum)',
 'per_km'=>'Operating kilometer EGP',
 'per_drive_min'=>'Driving minute EGP',
 'per_wait_min'=>'Waiting minute EGP',
 'overnight'=>'Overnight charge EGP',
 'min_fare'=>'Minimum fare EGP',
 'deadhead_pct'=>'Empty return distance (%)'
];
$child=[
 $select('vehicle_key','Vehicle category',$vehicles,931101,''),
 $select('mode','Cost override mode',['inherit','override'],931102),
 $select('dispatch_mode','Vehicle call-out BASE activation',
   ['inherit','enabled','disabled'],931120)
];
$i=931103;
foreach($metrics as $key=>$title)$child[]=$num($key,$title,$i++);
$sourceField=[
 'name'=>'trans_vehicle_cost_overrides_v1','title'=>'Cost Overrides by Vehicle',
 'type'=>'repeater','object_type'=>'field','options_source'=>'manual',
 'width'=>'100%','default_val'=>'','repeater_save_separate'=>false,
 'repeater_title_field'=>'vehicle_key','repeater_collapsed'=>true,
 'isNested'=>false,'id'=>931100,'repeater-fields'=>$child,
 'description'=>'Optional one row per vehicle; blank metrics inherit the company vehicle profile. Does not publish or approve a Rate.'
];
$rateFields=[
 $select('trans_cost_model_mode','Cost model mode',['inherit','override'],931110),
 $select('trans_dispatch_mode','Vehicle call-out BASE policy',
   ['inherit','enabled','disabled'],931119)
];
$i=931111;
foreach($metrics as $key=>$title)
 $rateFields[]=$num('trans_cost_'.$key.'_override',$title,$i++);
$rateFields[]=$num('trans_cost_markup_override_pct',
 'Transport Rate markup override (%)',931118);
$expected=[
 'transportations'=>[$sourceField],
 'transport-rates'=>$rateFields
];
$backupKey='mad4b_jetengine_transport_cost_inheritance_backup_20261120_v1';
$priorBackup=get_option($backupKey,false);
if($priorBackup!==false&&(!is_array($priorBackup)||($priorBackup['site']??'')!==$site))
 WP_CLI::error('backup_site_conflict');
$plan=[];$before=[];
foreach($expected as $type=>$newFields){
 $before[$type]=(string)$rows[$type]['row']['meta_fields'];
 $fields=$rows[$type]['fields'];
 $existing=array_column($fields,'name');
 $added=[];
 foreach($newFields as $f){
  if(in_array($f['name'],$existing,true))continue;
  $fields[]=$f;$added[]=$f['name'];
 }
 $plan[$type]=['id'=>(int)$rows[$type]['row']['id'],
  'previous'=>count($rows[$type]['fields']),'target'=>count($fields),
  'new_names'=>$added,'after'=>serialize($fields)];
 if($priorBackup!==false&&
   ($priorBackup['after_hash'][$type]??'')!==hash('sha256',$plan[$type]['after']))
  WP_CLI::error('previous_migration_schema_changed_'.$type);
}
if($rollback){
 if(!is_array($priorBackup)||($priorBackup['site']??'')!==$site)
  WP_CLI::error('rollback_backup_missing');
 // Verify every current schema before touching either CPT.
 foreach($plan as $type=>$info){
  if(hash('sha256',(string)$rows[$type]['row']['meta_fields'])!==
     ($priorBackup['after_hash'][$type]??''))
   WP_CLI::error('rollback_changed_schema_'.$type);
 }
 foreach($plan as $type=>$info){
  $before=$priorBackup['before'][$type]??null;
  if(!is_string($before)||!is_array(maybe_unserialize($before)))
   WP_CLI::error('rollback_before_image_invalid_'.$type);
  if($wpdb->update($table,['meta_fields'=>$before],['id'=>$info['id']],
       ['%s'],['%d'])!==1)
   WP_CLI::error('rollback_update_failed_'.$type);
  $read=(string)$wpdb->get_var($wpdb->prepare(
     "SELECT meta_fields FROM {$table} WHERE id=%d",$info['id']));
  if(hash('sha256',$read)!==hash('sha256',$before))
   WP_CLI::error('rollback_readback_failed_'.$type);
 }
 echo wp_json_encode(['status'=>'rolled_back','site'=>$site,
   'scope'=>'JetEngine CPT schemas only','post_meta_unchanged'=>true])."\n";
 return;
}
if(!$apply){
 echo wp_json_encode(['status'=>'preflight','site'=>$site,
  'types'=>array_map(static fn($r)=>[
   'previous'=>$r['previous'],'target'=>$r['target'],'added'=>$r['new_names']],$plan),
  'tour_rate_fields'=>count($rows['tour-rates']['fields']),
  'commercial_rates_modified'=>false])."\n";return;
}
if($priorBackup===false){
 $backup=['site'=>$site,'timestamp'=>gmdate('c'),'before'=>$before,
  'after_hash'=>array_map(static fn($r)=>hash('sha256',$r['after']),$plan)];
 if(!add_option($backupKey,$backup,'',false))
  WP_CLI::error('backup_create_failed');
}
foreach($plan as $type=>$item){
 if(!$item['new_names'])continue;
 if($wpdb->update($table,['meta_fields'=>$item['after']],
    ['id'=>$item['id']],['%s'],['%d'])!==1)
  WP_CLI::error('schema_update_failed_'.$type);
 $read=(string)$wpdb->get_var($wpdb->prepare(
   "SELECT meta_fields FROM {$table} WHERE id=%d",$item['id']));
 if(hash('sha256',$read)!==hash('sha256',$item['after']))
  WP_CLI::error('schema_readback_failed_'.$type);
}
echo wp_json_encode(['status'=>'schema_ready','site'=>$site,
 'types'=>array_map(static fn($r)=>['count'=>$r['target'],'added'=>$r['new_names']],$plan),
 'backup'=>$backupKey,'existing_post_meta_changed'=>false,'checkout_enabled'=>false])."\n";
