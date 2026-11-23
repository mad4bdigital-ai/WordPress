<?php
/**
 * Apply evidence-backed candidate Tour departure weekdays to Original/Translations.
 * External operator published schedules; this is not actual ship/cabin availability.
 * Exact target: Tour 60623, EN, its existing WPML family only; Monday/Wednesday.
 */
defined('ABSPATH')||exit;
$site=home_url();
if(!in_array($site,['https://tours.dev.mad4b.com','https://wayfatours.com'],true))
 throw new RuntimeException('Unexpected company');
if(!current_user_can('manage_options'))throw new RuntimeException('Authorized WP admin required');
$expected=['en'=>60623,'es'=>60661];
if($site==='https://tours.dev.mad4b.com')$expected+=['fr'=>60637,'de'=>60649,'it'=>60677,'ar'=>64945];
$want=[230,232];$tax='schudle-days-of-weak';$rootId=60623;
$names=[230=>'Monday',232=>'Wednesday'];
foreach($names as $id=>$name){
 $term=get_term($id,$tax);
 if(!$term instanceof WP_Term||$term->name!==$name||$term->slug!==strtolower($name))
  throw new RuntimeException('Weekday term identity changed '.$id);
}
$family=MAD4B_Cruise_Program_Context_V1::family($rootId);
foreach($expected as $lang=>$id){
 if((int)($family[$lang]??0)!==$id)throw new RuntimeException('WPML Tour family changed '.$lang);
 $p=get_post($id);
 if(!$p||$p->post_type!=='tours-and-activities'||$p->post_status!=='publish')
  throw new RuntimeException('Expected Tour missing or unpublished '.$lang);
 $raw=wp_get_object_terms($id,$tax,['fields'=>'ids']);
 if(is_wp_error($raw)||$raw)throw new RuntimeException('Existing weekday assignments require manual merge '.$id);
 $present=get_post_meta($id,'_mad4b_operating_weekdays_external_evidence_v1',true);
 if($present)throw new RuntimeException('Evidence meta already exists, resolve duplicate '.$id);
}
$ref=[
 'contract'=>'mad4b.tour-60623.external-operating-pattern.v1',
 'recorded_utc'=>gmdate('c'),
 'tour_id'=>$rootId,
 'name'=>get_the_title($rootId),
 'type'=>'tours-and-activities',
 'source_policy'=>'operator_published_departure_patterns',
 'tour_departure_weekdays'=>[1,3],
 'tour_departure_day_names'=>['Monday','Wednesday'],
 'cruise_embarkation_offset_days'=>2,
 'cruise_embarkation_weekdays'=>[3,5],
 'sleeper_to_aswan_on_day'=>2,
 'sleeper_from_luxor_on_day'=>6,
 'sleeper_train_southbound'=>'Abela 86 daily 20:15 Giza to 09:25 Aswan',
 'sleeper_train_northbound'=>'Abela 87 daily 20:35 Luxor to 06:05 Giza',
 'train_schedule_reference_year'=>2026,
 'source_urls'=>[
  'https://www.sonesta.com/nile-river-cruises/sonesta-star-goddess-nile-cruise-ship',
  'https://www.sonesta.com/nile-river-cruises/sonesta-st-george-1-nile-cruise-ship',
  'https://www.jazcruises.com/cruise-ships/58-jaz-gloria',
  'https://www.seat61.com/cairo-to-luxor-and-aswan-by-sleeper-train.htm'
 ],
 'commercial_scope'=>'provisional_weekly_operating_pattern_for_request',
 'supplier_cruise_property_confirmed'=>false,
 'supplier_cabin_inventory_confirmed'=>false,
 'sleeper_train_berths_confirmed'=>false,
 'train_fare_or_cruise_supplier_cost_approved'=>false,
 'bookable_inventory_confirmed'=>false,
 'caution'=>'The schedule is based on public operator patterns; not company supplier allotment or a confirmed departure.',
 'mode'=>'on_request'
];
$dry=getenv('MAD4B_TOUR60623_APPLY')!=='yes';
$output=[
 'contract'=>$ref['contract'],'site'=>$site,'dry_run'=>$dry,
 'target_post_ids'=>$expected,'weekday_term_ids'=>$want,
 'evidence'=>$ref,'post_image'=>[],'transaction_result'=>'not_applied'
];
if(!$dry){
 global $wpdb;
 $wpdb->query('START TRANSACTION');
 try {
  foreach($expected as $lang=>$id){
   $tags=wp_set_object_terms($id,$want,$tax,false);
   if(is_wp_error($tags)||count($tags)!==2)
    throw new RuntimeException('weekday_taxonomy_write_failed_'.$id);
   $evidence=$ref;
   $evidence['wpml_language']=$lang;
   $ok=add_post_meta($id,'_mad4b_operating_weekdays_external_evidence_v1',$evidence,true);
   if(!$ok)throw new RuntimeException('external_evidence_meta_add_failed_'.$id);
  }
  if($wpdb->query('COMMIT')===false)throw new RuntimeException('database_commit_failed');
  $output['transaction_result']='committed';
 }catch(Throwable $e){$wpdb->query('ROLLBACK');throw $e;}
}
foreach($expected as $lang=>$id){
 clean_object_term_cache($id,'tours-and-activities');
 $raw=wp_get_object_terms($id,$tax,['fields'=>'ids']);
 $outDays=array_values(array_map('intval',is_wp_error($raw)?[]:$raw));sort($outDays);
 $localeBefore=apply_filters('wpml_current_language','en');
 do_action('wpml_switch_language',$lang);
 try{$decision=Shared_Product_Program_Schedule::schedule_decision($id);}
 finally{do_action('wpml_switch_language',$localeBefore);}
 $output['post_image'][$lang]=[
  'id'=>$id,'name'=>html_entity_decode(wp_strip_all_tags(get_the_title($id)),ENT_QUOTES|ENT_HTML5,'UTF-8'),
  'type'=>get_post_type($id),'term_ids'=>$outDays,
  'schedule_status'=>$decision['status'],'schedule_weekdays'=>$decision['weekdays'],
  'schedule_reason'=>$decision['reason_code'],
  'evidence_saved'=>(bool)get_post_meta($id,'_mad4b_operating_weekdays_external_evidence_v1',true)
 ];
 if(!$dry){
  if($outDays!==$want||$decision['weekdays']!==[1,3]||!$output['post_image'][$lang]['evidence_saved'])
   throw new RuntimeException('weekday_readback_mismatch_'.$lang);
 }
}
echo wp_json_encode($output,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES).PHP_EOL;

