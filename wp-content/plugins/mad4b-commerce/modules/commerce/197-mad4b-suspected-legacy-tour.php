<?php
/**
 * Plugin Name: MAD4B Suspected Migrated Demo Review (Staging)
 * Version: 0.1.0
 * Non-destructive provenance review for two exact old Luxor/Aswan tour candidates.
 * "Suspected" is NEVER equivalent to confirmed demo or ownership certification.
 */
defined('ABSPATH') || exit;
final class MAD4B_Suspected_Legacy_Tour_V1 {
 const CONTRACT='mad4b.tenant-source.suspected-migrated-demo.v1';
 const COMPANY='tourism-almallahgroup';
 const CANDIDATES=[15081=>60433,15941=>60435];

 public static function plan(bool $write=false): array {
  if(!class_exists('MAD4B_Hub_Nile_Publisher_V1') ||
     !MAD4B_Hub_Nile_Publisher_V1::enabled() ||
     !current_user_can('manage_options')) {
     throw new RuntimeException('Staging administrator and Content Hub authority required');
  }
  $snap=get_option(MAD4B_Central_Nile_Reference_V1::REGISTRY,[]);
  $items=(array)($snap['nile_cruise_catalog.read']['items']??[]);
  $sources=[];
  foreach($items as $row){
    if(!is_array($row))continue;
    $id=(int)($row['source_tour_id']??0);
    if(isset($sources[$id]))throw new RuntimeException('Duplicated source tour');
    $sources[$id]=$row;
  }
  $updates=[];
  foreach(self::CANDIDATES as $sourceId=>$nativeId){
    $row=$sources[$sourceId]??null;
    $post=get_post($nativeId);
    if(!is_array($row) || !$post || $post->post_type!=='tours-and-activities' ||
       $post->post_status!=='publish' ||
       ($row['source_company']??'')!==self::COMPANY ||
       ($row['translation_source']['language_code']??'')!=='en' ||
       ($row['source_slug']??'')!==$post->post_name ||
       ($row['title']??'')!==$post->post_title ||
       trim((string)($row['summary']??''))!==trim((string)$post->post_excerpt)) {
       throw new RuntimeException('Potential imported-demo identity not sufficiently matched');
    }
    $owner=(string)get_post_meta($nativeId,'_mad4b_source_company',true);
    if($owner!==''&&$owner!==self::COMPANY)throw new RuntimeException('Native owner conflict');
    $nativeOriginal=(string)get_post_meta($nativeId,'_mad4b_source_post_id',true);
    if($nativeOriginal!==''&&(int)$nativeOriginal!==$sourceId)
       throw new RuntimeException('Native source post ID conflicts with signed catalog');
    $hubs=get_posts([
        'post_type'=>'mad4b_hub_nile','post_status'=>'publish',
        'numberposts'=>50,'fields'=>'ids','suppress_filters'=>true,
        'meta_key'=>'_mad4b_hub_source_id','meta_value'=>(string)$sourceId,
    ]);
    $matches=[];
    foreach($hubs as $hubId){
      if(get_post_meta($hubId,'_mad4b_hub_source_kind',true)==='nile_tour' &&
         get_post_meta($hubId,'_mad4b_hub_source_company',true)===self::COMPANY)
         $matches[]=(int)$hubId;
    }
    if(count($matches)!==1 ||
       get_post_meta($matches[0],'_mad4b_hub_reuse_policy_state',true)!=='native_slug_collision_requires_owner_review')
       throw new RuntimeException('Unreviewed or ambiguous Hub source mapping');
    $labels=[
      '_mad4b_demo_review_state'=>'suspected_migrated_demo',
      '_mad4b_demo_review_company'=>self::COMPANY,
      '_mad4b_demo_review_source_tour_id'=>(string)$sourceId,
      '_mad4b_demo_review_source_hub_id'=>(string)$matches[0],
      '_mad4b_demo_review_provenance'=>'unverified',
      '_mad4b_demo_review_auto_merge'=>'no',
    ];
    $changes=0;
    foreach($labels as $key=>$value){
      $old=(string)get_post_meta($nativeId,$key,true);
      if($old!==''&&$old!==$value)throw new RuntimeException('Conflicting review metadata');
      if($old!==$value)$changes++;
    }
    $updates[]=['native_id'=>$nativeId,'source_id'=>$sourceId,'hub_id'=>$matches[0],
      'meta'=>$labels,'fields_to_write'=>$changes];
  }
  if($write){
    foreach($updates as $entry){
      foreach($entry['meta'] as $key=>$val) {
        $old=(string)get_post_meta($entry['native_id'],$key,true);
        if($old===$val)continue;
        if(!update_post_meta($entry['native_id'],$key,$val) &&
            (string)get_post_meta($entry['native_id'],$key,true)!==$val)
            throw new RuntimeException('Provisional review metadata write failed');
      }
    }
  }
  return [
    'contract'=>self::CONTRACT,'scope'=>'Staging_only',
    'suspected_record_count'=>count($updates),
    'confirmed_demo_count'=>0,
    'source_provenance_verified'=>false,
    'requested_write'=>$write,
    'metadata_fields_to_change'=>array_sum(array_column($updates,'fields_to_write')),
    'review_records'=>array_map(static fn($x)=>[
      'native_id'=>$x['native_id'],'source_id'=>$x['source_id'],
      'hub_id'=>$x['hub_id'],'fields_to_change'=>$x['fields_to_write']
    ],$updates),
    'public_status_modified'=>false,'native_content_modified'=>false,
    'automatic_merge_performed'=>false,'rates_or_orders_modified'=>false,
  ];
 }
}
