<?php
/** Align stale flattened legacy repeater mirrors to canonical 2026 first/last stops. */
if(!defined('WP_CLI')||!WP_CLI||get_current_user_id()!==1)throw new RuntimeException('CLI_AUTH_REQUIRED');
$site=home_url();
if($site==='https://tours.dev.mad4b.com')$map=[54552=>68930,54553=>68930,54558=>68930,54559=>68930];
elseif($site==='https://wayfatours.com')$map=[54552=>68922,54553=>68922];
else throw new RuntimeException('SITE_NOT_ALLOWED');
$rows=[];$approved=[54231,54337];
foreach($map as $id=>$canonical){
 $number=(string)get_post_meta($id,'trans_train_number',true);
 $x=get_post($id);
 if(!$x ||$x->post_type!=='transportations'||$x->post_status!=='publish'||!in_array($number,['86','87'],true))
 throw new RuntimeException('TRAIN_IDENTITY_MISMATCH_'.$id);
 $start=(int)get_post_meta($id,$number==='86'?'trans_from_station_id':'trans_to_station_id',true);
 if($start!==$canonical || get_post_meta($canonical,'station_code',true)!=='UPPER-EGYPT-BASHTEEL')
 throw new RuntimeException('NATIVE_ENDPOINT_NOT_VERIFIED_'.$id);
 $prev=[
  'trans_train_stops_station_id'=>get_post_meta($id,'trans_train_stops_station_id',true),
  'trans_train_stops_sort_num'=>get_post_meta($id,'trans_train_stops_sort_num',true),
  'trans_train_stops_time'=>get_post_meta($id,'trans_train_stops_time',true),
 ];
 if(!in_array((int)$prev['trans_train_stops_station_id'],$approved,true))
 throw new RuntimeException('LEGACY_MIRROR_DRIFT_'.$id);
 $rows[$id]=['train_no'=>$number,'canonical'=>$canonical,'previous'=>$prev,
 'next'=>['trans_train_stops_station_id'=>(string)$canonical,
   'trans_train_stops_sort_num'=>$number==='86'?'1':'5',
   'trans_train_stops_time'=>$number==='86'?'19:45':'06:25']];
}
echo 'MIRROR_PREIMAGE '.wp_json_encode(['site'=>$site,'records'=>$rows],JSON_UNESCAPED_SLASHES).PHP_EOL;
if(getenv('MAD4B_CP4_APPLY')!=='1')return;
foreach($rows as $id=>$plan)foreach($plan['next'] as $key=>$value){
  if(get_post_meta($id,$key,true)!==$plan['previous'][$key])throw new RuntimeException('MIRROR_CHANGED_BEFORE_WRITE_'.$id);
  if(get_post_meta($id,$key,true)!==$value&&!update_post_meta($id,$key,$value))
    throw new RuntimeException('MIRROR_WRITE_FAILURE_'.$id.'_'.$key);
}
foreach($rows as $id=>$plan)foreach($plan['next'] as $key=>$value){
 if(get_post_meta($id,$key,true)!==$value)throw new RuntimeException('MIRROR_READBACK_FAILURE_'.$id);
}
echo 'CP4_LEGACY_MIRRORS_ALIGNED '.wp_json_encode(['site'=>$site,'train_records'=>array_keys($rows),'destination_ids'=>array_column($rows,'canonical')]).PHP_EOL;
