<?php
/** Link every PDF Transportation to the vehicle terms actually priced in PDF. */
defined('ABSPATH')&&defined('WP_CLI')&&WP_CLI||exit(2);
$site=rtrim(home_url('/'),'/');
$allowed=['https://tours.dev.mad4b.com','https://wayfatours.com'];
if(!in_array($site,$allowed,true))WP_CLI::error('site_guard');
$apply=defined('MAD4B_LINK_PDF_VEHICLE_TERMS')&&MAD4B_LINK_PDF_VEHICLE_TERMS;
$c='MAD4B_Transport_Vehicle_Term_Profiles_V1';
if(!$c::active()||!taxonomy_exists($c::TAXONOMY))
 WP_CLI::error('term_cost_not_active');
$tax=$c::TAXONOMY;
$expectedTerms=[];
foreach($c::KEY_TO_SLUG as $key=>$slug){
 $t=get_term_by('slug',$slug,$tax);
 if(!$t)WP_CLI::error('missing_qualified_term_'.$key);
 $expectedTerms[$key]=(int)$t->term_id;
}
$ids=get_posts(['post_type'=>'transportations',
 'post_status'=>['draft','publish'],'posts_per_page'=>600,
 'fields'=>'ids','suppress_filters'=>true,
 'meta_key'=>'_mad4b_transfer_pdf_2026_sha256',
 'meta_value'=>MAD4B_Transport_Distance_Time_V1::SHA]);
$expectedCount=$site==='https://wayfatours.com'?102:306;
if(count($ids)!==$expectedCount)
 WP_CLI::error('pdf_coverage_changed');
$plan=[];$noVehicles=0;$relationshipChanges=0;
foreach($ids as $id){
 $price=get_post_meta($id,'_mad4b_transfer_pdf_2026_raw_vehicles',true);
 if(!is_array($price))WP_CLI::error('pdf_vehicle_data_invalid_'.$id);
 $target=[];
 foreach($price as $vehicle=>$amount)
  if(isset($expectedTerms[$vehicle])&&is_numeric($amount)&&(float)$amount>0)
   $target[]=$expectedTerms[$vehicle];
 sort($target,SORT_NUMERIC);
 $previous=wp_get_object_terms($id,$tax,['fields'=>'ids']);
 if(is_wp_error($previous))WP_CLI::error('term_assignment_read_failed_'.$id);
 $previous=array_map('intval',$previous);
 sort($previous,SORT_NUMERIC);
 if(!$target)$noVehicles++;
 if($target!==$previous)$relationshipChanges++;
 $plan[$id]=['before'=>$previous,'after'=>$target];
}
$backupKey='mad4b_transport_vehicle_relations_backup_20261120_v1';
$existing=get_option($backupKey,false);
if($existing!==false&&
 (!is_array($existing)||($existing['site']??'')!==$site))
 WP_CLI::error('relationship_backup_conflict');
if(!$apply){
 echo wp_json_encode(['checkpoint'=>'RELATION_PREFLIGHT',
  'site'=>$site,'source_posts'=>count($ids),
  'posts_without_priced_vehicle'=>$noVehicles,
  'pending_link_changes'=>$relationshipChanges,
  'new_checkout_prices'=>0])."\n";return;
}
if($existing===false){
 if(!add_option($backupKey,['site'=>$site,'at'=>gmdate('c'),
 'source_sha'=>MAD4B_Transport_Distance_Time_V1::SHA,
 'records'=>$plan],'',false))WP_CLI::error('backup_write_failed');
}else{
 // Idempotent retry is safe when current state equals either the
 // original before-image or the expected after-image for every record.
 if(count($existing['records']??[])!==count($plan))
  WP_CLI::error('backup_record_count_changed');
 foreach($plan as $id=>$r){
  $saved=$existing['records'][$id]??null;
  if(!is_array($saved)||$saved['after']!==$r['after']||
     ($r['before']!==$saved['before']&&$r['before']!==$saved['after']))
   WP_CLI::error('unexpected_existing_assignment_'.$id);
 }
}
$updated=0;
foreach($plan as $id=>$row){
 if($row['before']===$row['after'])continue;
 $assigned=wp_set_object_terms((int)$id,$row['after'],$tax,false);
 if(is_wp_error($assigned))WP_CLI::error('assign_failed_'.$id);
 $read=wp_get_object_terms((int)$id,$tax,['fields'=>'ids']);
 if(is_wp_error($read))WP_CLI::error('readback_failed_'.$id);
 $read=array_map('intval',$read);sort($read,SORT_NUMERIC);
 if($read!==$row['after'])WP_CLI::error('relation_readback_mismatch_'.$id);
 $updated++;
}
echo wp_json_encode(['checkpoint'=>'PDF_VEHICLE_TERMS_LINKED',
 'site'=>$site,'source_posts'=>count($ids),
 'updated_post_links'=>$updated,
 'without_qualifying_costs'=>$noVehicles,
 'existing_transport_rate_reassigned'=>false,
 'commercial_prices_modified'=>false,'backup'=>$backupKey])."\n";
