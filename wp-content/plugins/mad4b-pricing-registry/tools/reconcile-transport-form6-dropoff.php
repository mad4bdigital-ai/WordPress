<?php
/** Native Fluent Form #6 optional drop-off details, exact site-local rollback. */
defined('ABSPATH')&&defined('WP_CLI')&&WP_CLI||exit(2);
$site=rtrim(home_url('/'),'/');
$isWayfa=$site==='https://wayfatours.com';
if(!in_array($site,['https://tours.dev.mad4b.com','https://wayfatours.com'],true))
 WP_CLI::error('site_guard');
if($isWayfa&&!(defined('MAD4B_FORM6_DROPOFF_WAYFA_APPROVED')&&MAD4B_FORM6_DROPOFF_WAYFA_APPROVED))
 WP_CLI::error('wayfa_approval_required');
$apply=defined('MAD4B_FORM6_DROPOFF_APPLY')&&MAD4B_FORM6_DROPOFF_APPLY;
$rollback=defined('MAD4B_FORM6_DROPOFF_ROLLBACK')&&MAD4B_FORM6_DROPOFF_ROLLBACK;
if($apply&&$rollback)WP_CLI::error('conflicting_mode');
global $wpdb;
$table=$wpdb->prefix.'fluentform_forms';
$record=$wpdb->get_row($wpdb->prepare(
 "SELECT id,title,status,form_fields FROM {$table} WHERE id=%d",6),ARRAY_A);
if(!$record||$record['status']!=='published'||
    $record['title']!=='Single Tour Order Form (#6)')
 WP_CLI::error('fluent_form_identity_changed');
$raw=(string)$record['form_fields'];
$obj=json_decode($raw,true);
if(!is_array($obj)||!isset($obj['fields'])||!is_array($obj['fields']))
 WP_CLI::error('form_fields_invalid');
$fields=$obj['fields'];
$names=[];
foreach($fields as $i=>$f){$name=$f['attributes']['name']??null;
 if(is_string($name)){$names[$name]=$i;}}
$pickup='mad4b_pickup_hotel_address';$after='description';
if(!isset($names[$pickup],$names[$after])||$names[$after]!==$names[$pickup]+1)
 WP_CLI::error('form6_original_pickup_field_sequence_changed');
$extra=['mad4b_dropoff_mode','mad4b_dropoff_place_name',
 'mad4b_dropoff_city','mad4b_dropoff_address'];
$present=array_values(array_filter($extra,static fn($n)=>isset($names[$n])));
if($present&&count($present)!==count($extra))
 WP_CLI::error('partial_form6_dropoff_schema');
if($present && array_slice(array_keys($names),$names[$pickup]+1,4)!==$extra)
 WP_CLI::error('form6_dropoff_field_order_changed');
$backupKey='mad4b_form6_transport_dropoff_backup_20261120_v1';
$backup=get_option($backupKey,false);
if($rollback){
 if(!is_array($backup)||!$present||
    hash('sha256',$raw)!==($backup['after_sha256']??''))
    WP_CLI::error('rollback_form6_schema_conflict');
 $old=$backup['before_raw']??null;
 if(!is_string($old)||!is_array(json_decode($old,true)))
    WP_CLI::error('rollback_form6_backup_invalid');
 if($wpdb->update($table,['form_fields'=>$old],['id'=>6],['%s'],['%d'])!==1)
    WP_CLI::error('rollback_form_write_failed');
 echo wp_json_encode(['status'=>'form6_dropoff_rolled_back','site'=>$site])."\n";return;
}
$select=$fields[$names['mad4b_pickup_booking_status']];
$select['attributes']['name']=$extra[0];
$select['settings']['label']='Final drop-off preference';
$select['settings']['admin_field_label']='Transport final drop-off preference';
$select['settings']['help_message']='This is a request for itinerary coordination, not a confirmed transfer or additional charge.';
$select['settings']['container_class']='mad4b-transport-dropoff-field';
$select['settings']['placeholder']='Choose final drop-off or request confirmation';
$select['settings']['advanced_options']=[
 ['label'=>'Same place as pickup (if the route returns)','value'=>'same_as_pickup','id'=>0],
 ['label'=>'Hotel or cruise ship','value'=>'hotel','id'=>1],
 ['label'=>'Airport','value'=>'airport','id'=>2],
 ['label'=>'Railway station','value'=>'rail_station','id'=>3],
 ['label'=>'Port or marina','value'=>'port','id'=>4],
 ['label'=>'Meeting point / other','value'=>'meeting_point','id'=>5],
 ['label'=>'I will confirm later','value'=>'to_confirm','id'=>6]
];
$select['uniqElKey']=$extra[0];
$select['settings']['validation_rules']['required']['value']=false;
$inputBase=$fields[$names['mad4b_pickup_hotel_address']];
$labels=[
 [$extra[1],'Final drop-off place name','Hotel, airport, station or meeting point name',180],
 [$extra[2],'Final drop-off city or area','City or area',100],
 [$extra[3],'Final drop-off address / map details (optional)','Address, terminal or map details',500]
];
$additional=[$select];
foreach($labels as [$name,$label,$placeholder,$limit]){
 $f=$inputBase;
 $f['attributes']['name']=$name;
 $f['attributes']['placeholder']=$placeholder;
 $f['attributes']['maxlength']=(string)$limit;
 $f['settings']['label']=$label;
 $f['settings']['admin_field_label']=$label;
 $f['settings']['container_class']='mad4b-transport-dropoff-field';
 $f['settings']['validation_rules']['required']['value']=false;
 $f['uniqElKey']=$name;
 $additional[]=$f;
}
if(!$present)array_splice($obj['fields'],$names[$pickup]+1,0,$additional);
$afterRaw=wp_json_encode($obj,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
if(!is_string($afterRaw)||!is_array(json_decode($afterRaw,true)))
 WP_CLI::error('form6_after_json_invalid');
if(!$apply){
 echo wp_json_encode(['status'=>'dry_run','site'=>$site,
  'field_count_before'=>count($fields),'field_count_after'=>count($obj['fields']),
  'original_hash'=>hash('sha256',$raw),'new_names'=>$extra,
  'existing_booking_fields_preserved'=>true,'checkout_changed'=>false])."\n";
 return;
}
if($backup===false){
 if($present)WP_CLI::error('schema_already_present_without_backup');
 if(!add_option($backupKey,[
    'site'=>$site,'at'=>gmdate('c'),'before_raw'=>$raw,
    'before_sha256'=>hash('sha256',$raw),
    'after_sha256'=>hash('sha256',$afterRaw)
 ],'',false))WP_CLI::error('form6_backup_write_failed');
}elseif(!$present)WP_CLI::error('backup_exists_without_current_fields');
if(!$present && $wpdb->update($table,['form_fields'=>$afterRaw],['id'=>6],
 ['%s'],['%d'])!==1)WP_CLI::error('form6_write_failed');
$readback=(string)$wpdb->get_var($wpdb->prepare(
 "SELECT form_fields FROM {$table} WHERE id=%d",6));
if(hash('sha256',$readback)!==hash('sha256',$afterRaw))
 WP_CLI::error('form6_readback_conflict');
echo wp_json_encode(['status'=>'form6_dropoff_fields_ready',
 'site'=>$site,'field_count'=>count($obj['fields']),'new_fields'=>$extra,
 'backup'=>$backupKey,'shipping_or_booking_modified'=>false])."\n";
