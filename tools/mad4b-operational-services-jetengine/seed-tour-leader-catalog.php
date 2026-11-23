<?php
/* Idempotent first-party native JetEngine content seed; no commercial activation. */
wp_set_current_user(1);
if(!current_user_can('manage_options'))throw new RuntimeException('capability_not_authorized');
$cls='MAD4B_Operational_Services_V1';
if(!class_exists($cls,false)||!post_type_exists($cls::CPT)||
   !taxonomy_exists($cls::FAMILY)||!taxonomy_exists($cls::TYPE)||
   get_option('mad4b_operational_services_jet_owner_v1')!==true)
 throw new RuntimeException('jetengine_catalog_not_ready');
$w=$GLOBALS['wpdb'];
$table=$w->prefix.'jet_post_types';
$count=(int)$w->get_var($w->prepare("SELECT COUNT(*) FROM $table WHERE slug=%s AND status='publish'",$cls::CPT));
if($count!==1)throw new RuntimeException('jetengine_native_owner_count_invalid');
$defined=$cls::SERVICES;
$notes=[
 'airport-meet-assist'=>'Greet the traveler on arrival, identify traveler and coordinate arrival handoff. Standard arrival waiting belongs to this service unless a separately approved standby policy applies.',
 'airport-waiting-standby'=>'Exceptional waiting or flight-delay standby. Chargeable time starts only after a documented included-wait threshold and operational authorization. Not automatically included in airport welcome.',
 'airport-departure-assistance'=>'Airport departure support, traveler handover and check-in assistance; separate from the driver transfer service.',
 'meeting-point-group-assembly'=>'Meet the travelers at the agreed point, verify group, give orientation and coordinate group assembly.',
 'train-station-assistance'=>'Meet, assist or hand over travelers at train station or sleeper train boarding and disembarkation.',
 'cruise-embark-disembark'=>'Assist on embarkation, check-in, disembarkation, port meeting and handover; separate from Egyptologist guiding.',
 'hotel-check-in-assistance'=>'Assist at reception, rooming and hotel check-in or check-out when specifically required.',
 'intercity-handover'=>'Coordinate handover between travel cities or operations teams; do not duplicate a transport line.',
 'tour-leader-escort'=>'On-trip operational leader/escort for coordination and logistics; distinct from tourist guide and driver.'
];
$do=getenv('MAD4B_SEED_EXECUTE')==='YES';
$existing=get_posts(['post_type'=>$cls::CPT,'post_status'=>'any','fields'=>'ids',
 'posts_per_page'=>-1,'suppress_filters'=>true]);
$index=[];
foreach($existing as $id){
 $terms=wp_get_post_terms($id,$cls::TYPE,['fields'=>'slugs']);
 if(!is_wp_error($terms))foreach($terms as $slug)$index[$slug][]=$id;
}
$summary=['site'=>home_url('/'),'mode'=>$do?'execute':'preflight',
 'already_exists'=>array_keys($index),'items'=>[]];
if(!$do){
 foreach($defined as $slug=>$entry)
  $summary['items'][]=['slug'=>$slug,'existing_id'=>count($index[$slug]??[])===1?$index[$slug][0]:null,
    'title'=>$entry[0],'basis'=>$entry[1]];
 echo 'JET_TOUR_LEADER_SEED_PREFLIGHT='.wp_json_encode($summary,JSON_UNESCAPED_UNICODE)."\n";
 return;
}
$familyTerm=term_exists($cls::FAMILY_SLUG,$cls::FAMILY);
if(!$familyTerm)$familyTerm=wp_insert_term('Tour Leader',$cls::FAMILY,
 ['slug'=>$cls::FAMILY_SLUG,'description'=>'Operations coordination and on-trip traveler handover, not tourist guide or driver.']);
if(is_wp_error($familyTerm))throw new RuntimeException('family_term_creation_failed:'.$familyTerm->get_error_code());
$familyId=(int)(is_array($familyTerm)?$familyTerm['term_id']:$familyTerm);
foreach($defined as $slug=>$entry){
 if(count($index[$slug]??[])>1)throw new RuntimeException('duplicate_existing_service_type:'.$slug);
 $term=term_exists($slug,$cls::TYPE);
 if(!$term)$term=wp_insert_term($entry[0],$cls::TYPE,['slug'=>$slug]);
 if(is_wp_error($term))throw new RuntimeException('service_type_term_failed:'.$slug);
 $termId=(int)(is_array($term)?$term['term_id']:$term);
 $id=(int)($index[$slug][0]??0);
 if($id===0){
   // Narrow, catalog-only write. Never create Woo products or a price.
   $id=wp_insert_post(['post_type'=>$cls::CPT,'post_status'=>'publish',
       'post_title'=>$entry[0],'post_name'=>$slug,
       'post_content'=>$notes[$slug]."\n\nInternal source only. Pricing, supplier booking and customer billing require separate approval."],true);
   if(is_wp_error($id))throw new RuntimeException('service_post_failed:'.$slug.':'.$id->get_error_code());
   update_post_meta($id,'_mad4b_os_service_unit',$entry[1]);
   update_post_meta($id,'_mad4b_os_supplier_mode','self');
   update_post_meta($id,'_mad4b_os_supplier_id','self');
   update_post_meta($id,'_mad4b_os_cost_state','unpriced');
 }
 $assigned=wp_set_object_terms($id,[$familyId],$cls::FAMILY,false);
 if(is_wp_error($assigned))throw new RuntimeException('family_assignment_failed:'.$slug);
 $assigned=wp_set_object_terms($id,[$termId],$cls::TYPE,false);
 if(is_wp_error($assigned))throw new RuntimeException('type_assignment_failed:'.$slug);
 $source=$cls::source($id);
 if(is_wp_error($source)||$source['cost_approved']||$source['retail_price_authorized']||
    $source['supplier_mode']!=='self'||$source['cost_state']!=='unpriced'||
    !in_array($slug,$source['type_slugs'],true))
    throw new RuntimeException('catalog_readback_failed:'.$slug);
 $summary['items'][]=['slug'=>$slug,'id'=>$id,'term_id'=>$termId,
   'source_basis'=>$source['service_unit'],'cost_state'=>$source['cost_state']];
}
$summary['family_term_id']=$familyId;
$summary['published_count']=(int)(wp_count_posts($cls::CPT)->publish??0);
if($summary['published_count']<count($defined))throw new RuntimeException('catalog_missing_records');
echo 'JET_TOUR_LEADER_SEED_EXECUTED='.wp_json_encode($summary,JSON_UNESCAPED_UNICODE)."\n";
