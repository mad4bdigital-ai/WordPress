<?php
/** Add source/ops point descriptors to existing JetEngine Tour Rate stages. */
defined('ABSPATH')&&defined('WP_CLI')&&WP_CLI||exit(2);
$site=rtrim(home_url('/'),'/');
$wayfa=$site==='https://wayfatours.com';
if(!in_array($site,['https://tours.dev.mad4b.com','https://wayfatours.com'],true))
 WP_CLI::error('site_not_allowed');
if($wayfa&&!(defined('MAD4B_STAGE_POINTS_WAYFA_APPROVED')&&MAD4B_STAGE_POINTS_WAYFA_APPROVED))
 WP_CLI::error('wayfa_approval_required');
$apply=defined('MAD4B_STAGE_POINTS_APPLY')&&MAD4B_STAGE_POINTS_APPLY;
$rollback=defined('MAD4B_STAGE_POINTS_ROLLBACK')&&MAD4B_STAGE_POINTS_ROLLBACK;
if($apply&&$rollback)WP_CLI::error('conflicting_mode');
global $wpdb;
$table=$wpdb->prefix.'jet_post_types';
$row=$wpdb->get_row($wpdb->prepare("SELECT id,status,meta_fields FROM {$table} WHERE slug=%s",'tour-rates'),ARRAY_A);
if(!$row||$row['status']!=='publish'||(int)$row['id']!==328)WP_CLI::error('tour_rate_schema_identity');
$beforeRaw=(string)$row['meta_fields'];
$fields=(array)maybe_unserialize($beforeRaw);
if(count($fields)!==52||($fields[50]['name']??'')!=='_mad4b_transport_stages_v1')
 WP_CLI::error('tour_rate_field_order_changed');
$children=(array)($fields[50]['repeater-fields']??[]);
$expected=['key','label','day_offset','origin_term_id','destination_term_id',
 'return_to_origin','required','join','service_kind','price_policy','transport_ids'];
if(array_column(array_slice($children,0,11),'name')!==$expected)
 WP_CLI::error('existing_transport_stage_fields_changed');
$newFields=['pickup_point_type','pickup_point_ref_id','pickup_point_notes',
 'dropoff_point_type','dropoff_point_ref_id','dropoff_point_notes'];
$present=array_intersect($newFields,array_column($children,'name'));
if($present&&count($present)!==count($newFields))
 WP_CLI::error('partial_location_contract');
if($present&&array_slice(array_column($children,'name'),11,6)!==$newFields)
 WP_CLI::error('existing_location_schema_changed');
$choice=[
 ['key'=>'pending','value'=>'To confirm / pending'],
 ['key'=>'hotel','value'=>'Hotel or cruise-ship property'],
 ['key'=>'airport','value'=>'Airport'],
 ['key'=>'rail_station','value'=>'Railway station'],
 ['key'=>'marina','value'=>'Port or marina'],
 ['key'=>'meeting_point','value'=>'Meeting point'],
 ['key'=>'custom','value'=>'Other agreed location']
];
$makeType=static fn($name,$title,$id)=>[
 'name'=>$name,'title'=>$title,'type'=>'select','object_type'=>'field',
 'options_source'=>'manual','options'=>$choice,
 'default_val'=>'pending','id'=>$id
];
$makeNumber=static fn($name,$title,$id)=>[
 'name'=>$name,'title'=>$title,'type'=>'number','object_type'=>'field',
 'min_value'=>0,'step_value'=>1,'default_val'=>'0','id'=>$id,
 'description'=>'Optional native Properties/Stations post ID, never proof of supplier confirmation'
];
$makeNotes=static fn($name,$title,$id)=>[
 'name'=>$name,'title'=>$title,'type'=>'textarea','object_type'=>'field',
 'default_val'=>'','id'=>$id,
 'description'=>'Internal routing / meeting details, pending supplier validation'
];
$append=[
 $makeType('pickup_point_type','Pickup location kind',930112),
 $makeNumber('pickup_point_ref_id','Pickup Property / Station ID',930113),
 $makeNotes('pickup_point_notes','Pickup meeting instructions',930114),
 $makeType('dropoff_point_type','Drop-off location kind',930115),
 $makeNumber('dropoff_point_ref_id','Drop-off Property / Station ID',930116),
 $makeNotes('dropoff_point_notes','Drop-off meeting instructions',930117)
];
if(!$present)$fields[50]['repeater-fields']=array_merge($children,$append);
$afterRaw=serialize($fields);
$backupKey='mad4b_tour_rate_stage_points_schema_backup_20261120_v1';
$backup=get_option($backupKey,false);
if($rollback){
 if(!is_array($backup)||!$present||
    hash('sha256',$beforeRaw)!==($backup['after_hash']??''))
    WP_CLI::error('rollback_schema_has_changed');
 if($wpdb->update($table,['meta_fields'=>$backup['before_raw']],
    ['id'=>328],['%s'],['%d'])!==1)WP_CLI::error('rollback_db_update_failed');
 echo wp_json_encode(['status'=>'tour_stage_points_rolled_back','site'=>$site])."\n";
 return;
}
if(!$apply){
 echo wp_json_encode(['status'=>'dry_run','site'=>$site,
 'tour_rate_field_count'=>count($fields),
 'existing_stage_field_count'=>count($children),
 'new_stage_field_count'=>count($fields[50]['repeater-fields']),
 'published_rates_mutated'=>0,'existing_stage_values_mutated'=>0,
 'new_fields'=>$newFields])."\n";
 return;
}
if($backup===false){
 if($present)WP_CLI::error('schema_present_without_backup');
 if(!add_option($backupKey,['site'=>$site,'created_at'=>gmdate('c'),
    'before_raw'=>$beforeRaw,'before_hash'=>hash('sha256',$beforeRaw),
    'after_hash'=>hash('sha256',$afterRaw)],'',false))
      WP_CLI::error('backup_creation_failed');
}elseif(!$present)WP_CLI::error('backup_exists_schema_missing');
if(!$present&&$wpdb->update($table,['meta_fields'=>$afterRaw],
 ['id'=>328],['%s'],['%d'])!==1)WP_CLI::error('schema_write_failed');
$read=(string)$wpdb->get_var($wpdb->prepare(
 "SELECT meta_fields FROM {$table} WHERE id=%d",328));
if(hash('sha256',$read)!==hash('sha256',$afterRaw))
 WP_CLI::error('schema_readback_mismatch');
echo wp_json_encode(['status'=>'tour_stage_points_ready','site'=>$site,
 'tour_rate_field_count'=>52,'stage_subfields'=>17,
 'source_values_moved'=>0,'commercial_sales_enabled'=>false,
 'backup'=>$backupKey])."\n";
