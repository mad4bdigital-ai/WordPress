<?php
/**
 * CP4: selective update to original WPCPT train No86 and No87 on Wayfatours only.
 * The five verified stops replace stale 29-row template placeholders.
 * Never writes any cost, rate, inventory, payment or customer booking.
 */
if (!defined('WP_CLI') || !WP_CLI) throw new RuntimeException('WP_CLI_REQUIRED');
if (home_url()!=='https://wayfatours.com' || get_current_user_id()!==1) throw new RuntimeException('WRONG_TENANT_OR_IDENTITY');
$station=get_post(68922);
if (!$station || $station->post_type!=='stations' || $station->post_status!=='publish' ||
    get_post_meta(68922,'station_code',true)!=='UPPER-EGYPT-BASHTEEL')
    throw new RuntimeException('UPPER_EGYPT_STATION_NOT_VERIFIED');
foreach ([54232,54256,54257,54258] as $id) {
 $p=get_post($id);
 if (!$p || $p->post_type!=='stations' || $p->post_status!=='publish')
  throw new RuntimeException('INTERMEDIATE_STATION_MISSING_'.$id);
}
$layout=[
 54552=>[
    'train_no'=>'86','old_from'=>'54231','old_to'=>'54258',
    'from'=>'68922','to'=>'54258','departure'=>'19:45','arrival'=>'09:25',
    'stops'=>[
      'item-0'=>['station_id'=>'68922','sort_num'=>'1','time'=>'19:45'],
      'item-1'=>['station_id'=>'54232','sort_num'=>'2','time'=>'20:15'],
      'item-2'=>['station_id'=>'54256','sort_num'=>'3','time'=>'01:05'],
      'item-3'=>['station_id'=>'54257','sort_num'=>'4','time'=>'06:10'],
      'item-4'=>['station_id'=>'54258','sort_num'=>'5','time'=>'09:25'],
    ],
  ],
 54553=>[
    'train_no'=>'87','old_from'=>'54258','old_to'=>'54231',
    'from'=>'54258','to'=>'68922','departure'=>'17:15','arrival'=>'06:25',
    'stops'=>[
      'item-0'=>['station_id'=>'54258','sort_num'=>'1','time'=>'17:15'],
      'item-1'=>['station_id'=>'54257','sort_num'=>'2','time'=>'20:35'],
      'item-2'=>['station_id'=>'54256','sort_num'=>'3','time'=>'01:15'],
      'item-3'=>['station_id'=>'54232','sort_num'=>'4','time'=>'06:05'],
      'item-4'=>['station_id'=>'68922','sort_num'=>'5','time'=>'06:25'],
    ],
  ],
];
$keys=['trans_from_station_id','trans_to_station_id','trans_departure_time','trans_arrival_time','trans_train_stops','trans_train_operator'];
$preimage=[];
foreach($layout as $id=>$row){
 $p=get_post($id);
 if(!$p || $p->post_type!=='transportations' || $p->post_status!=='publish' ||
    get_post_meta($id,'trans_train_number',true)!==$row['train_no'])throw new RuntimeException('TRAIN_RECORD_GUARD_'.$id);
 $prior=[];foreach($keys as $key)$prior[$key]=get_post_meta($id,$key,true);
 if($prior['trans_from_station_id']!==$row['old_from'] ||
    $prior['trans_to_station_id']!==$row['old_to'] ||
    $prior['trans_departure_time']!==$row['departure'] ||
    !is_array($prior['trans_train_stops']) || count($prior['trans_train_stops'])!==29)
    throw new RuntimeException('CONCURRENT_CHANGE_ABORT_'.$id);
 $preimage[$id]=$prior;
}
echo 'PREIMAGE '.wp_json_encode(['site'=>home_url(),'post_ids'=>array_keys($preimage),'train_keys'=>array_keys(reset($preimage)),'preimage'=>$preimage],JSON_UNESCAPED_SLASHES).PHP_EOL;
if(getenv('MAD4B_CP4_APPLY')!=='1'){echo "PREFLIGHT_ONLY\n";return;}
$applied=[];$failed='';
try {
 foreach($layout as $id=>$row){
   $fields=[
    'trans_from_station_id'=>$row['from'],'trans_to_station_id'=>$row['to'],
    'trans_departure_time'=>$row['departure'],'trans_arrival_time'=>$row['arrival'],
    'trans_train_stops'=>$row['stops'],'trans_train_operator'=>'Abela Trains',
   ];
   foreach($fields as $key=>$value) {
     if(get_post_meta($id,$key,true)!==$value && !update_post_meta($id,$key,$value))throw new RuntimeException('META_UPDATE_NOT_APPLIED_'.$id.'_'.$key);
   }
   $applied[]=$id;
 }
 foreach($layout as $id=>$row){
  if(get_post_meta($id,'trans_from_station_id',true)!==$row['from']||
     get_post_meta($id,'trans_to_station_id',true)!==$row['to']||
     get_post_meta($id,'trans_arrival_time',true)!==$row['arrival']||
     get_post_meta($id,'trans_train_stops',true)!==$row['stops']||
     get_post_meta($id,'trans_use_in_tour_rate_generation',true)!=='0')
   throw new RuntimeException('TRAIN_POSTREADBACK_MISMATCH_'.$id);
 }
} catch(Throwable $e){
 $failed=$e->getMessage();
 foreach($preimage as $id=>$original){
  foreach($original as $key=>$value)update_post_meta($id,$key,$value);
 }
 throw new RuntimeException('PATCH_ROLLED_BACK_'.$failed);
}
echo 'CP4_TRAIN_ROUTE_DONE '.wp_json_encode(['site'=>home_url(),'train_86_station_id'=>68922,'train_86_departure'=>'19:45','train_86_arrival_next_day'=>'09:25','train_87_station_id'=>68922,'train_87_departure'=>'17:15','train_87_arrival_next_day'=>'06:25','stops_per_train'=>5,'supplier_cost_approved'=>false,'commercial_generation_activated'=>false]);
