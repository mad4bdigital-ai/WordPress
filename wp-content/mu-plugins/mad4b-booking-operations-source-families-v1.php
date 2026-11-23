<?php
/** Native, read-only service-family discovery for MAD4B Woo Order Operations. */
defined('ABSPATH')||exit;
final class MAD4B_Booking_Operations_Source_Families_V1 {
 public const CONTRACT='mad4b.booking-ops-source-families.v1';
 public static function types():array{
  $types=class_exists('MAD4B_Unified_Booking_Fulfillment_V2',false)
   ?array_keys(MAD4B_Unified_Booking_Fulfillment_V2::TYPES):[];
  $types=array_merge($types,['tours-and-activities','transportations','properties','units','property-rates','tour-rates','transport-rates','product']);
  foreach(get_post_types(['show_ui'=>true],'names') as $type){
   $owner=function_exists('service_channel_owner')?service_channel_owner($type):null;
   if(is_string($owner)&&$owner!==''&&$owner!=='none')$types[]=$type;
  }
  $types=apply_filters('mad4b/booking_operations/service_types',$types);
  $out=[];
  foreach((array)$types as $type){
   if(!is_string($type)||!post_type_exists($type)||in_array($type,['post','page','attachment','elementor_library','help-center','jet-form-builder'],true))continue;
   $obj=get_post_type_object($type);
   if($obj&&$obj->show_ui)$out[$type]=['type'=>$type,'label'=>(string)$obj->labels->singular_name];
  }
  return array_values($out);
 }
 public static function allowed(string $type):bool{return in_array($type,array_column(self::types(),'type'),true);}
 public static function ids($data,string $type):array{
  if(is_string($data)){$u=maybe_unserialize($data);$data=$u!==$data?$u:preg_split('/[,|]/',$data);}
  if(!is_array($data))$data=[$data];$out=[];
  foreach($data as $v)if(is_scalar($v)&&ctype_digit((string)$v)&&get_post_type((int)$v)===$type)$out[(int)$v]=(int)$v;
  return array_values($out);
 }
 public static function row(string $identity,string $title,string $role,int $day,string $type,int $id,string $stage,string $family,array $proof=[]):array{
  return compact('identity','title','role','day','type','id','stage','family','proof');
 }
 public static function search(string $q,int $page,string $type=''):array|WP_Error{
  if(!is_user_logged_in()||!(current_user_can('manage_woocommerce')||current_user_can('edit_shop_orders')))return new WP_Error('operations_catalog_access_denied');
  $types=array_column(self::types(),'type');if($type!==''&&!in_array($type,$types,true))return new WP_Error('operations_catalog_type_invalid');
  $args=['post_type'=>$type?:$types,'post_status'=>['publish','draft','pending'],'paged'=>max(1,$page),'posts_per_page'=>25,'fields'=>'ids','orderby'=>'title','order'=>'ASC','suppress_filters'=>true,'no_found_rows'=>false];
  $q=mb_substr(trim(wp_strip_all_tags($q)),0,180);
  if($q!==''){
   if(preg_match('/^#?([1-9][0-9]*)$/D',$q,$m))$args['post__in']=[(int)$m[1]];
   else $args['s']=$q;
  }
  $w=new WP_Query($args);$rows=[];
  foreach($w->posts as $id)$rows[]=['id'=>(int)$id,'title'=>wp_strip_all_tags(get_the_title($id)),
   'type'=>get_post_type($id),'status'=>get_post_status($id),'reference_only'=>true];
  return ['rows'=>$rows,'types'=>self::types(),'page'=>max(1,$page),'pages'=>(int)$w->max_num_pages,'total'=>(int)$w->found_posts];
 }
 public static function compose(WC_Order $order,int $tour,int $rate,int $program):array{
  $rows=[];$add=static function(array $row)use(&$rows){$rows[$row['identity']]=$row;};
  $plan=get_post_meta($tour,'_mad4b_package_accommodation_source_plan_v1',true);
  $bindings=get_post_meta($tour,'_mad4b_package_stay_verified_room_program_links_v1',true);
  $byStage=[];
  foreach((array)($bindings['bindings']??[]) as $b)if(is_array($b)&&!empty($b['stage_key']))$byStage[(string)$b['stage_key']]=$b;
  foreach((array)($plan['stages']??[]) as $i=>$stage){
   if(!is_array($stage))continue;
   $key=(string)($stage['stage_key']??'stage-'.$i);$b=$byStage[$key]??[];
   $kind=sanitize_key((string)($stage['lodging_kind']??'lodging'));
   $where=sanitize_key((string)($stage['destination_key']??''));
   $day=max(0,(int)($stage['source_day_start']??0));$n=max(0,(int)($stage['nights']??0));
   $id=(int)($b['selected_property_id']??0);
   if(get_post_type($id)!=='properties')$id=0;
   $add(self::row('stay|'.$tour.'|'.$key,ucwords(str_replace('_',' ',$kind)).' — '.$where.' · '.$n.' nights',
    'editorial_stay_plan_not_rate_inclusion',$day,$id?'properties':'stay-stage',$id,$key,'accommodation',[
    'lodging_kind'=>$kind,'nights'=>$n,'destination'=>$where,
    'selected_property_id'=>$id,'unit_ids'=>self::ids($b['bookable_unit_ids']??[],'units'),
    'source_plan_state'=>(string)($stage['component_resolution']??''),
    'binding_status'=>(string)($b['commercial_readiness']??''),
    'selected_vessel_id'=>(int)($b['selected_vessel_id']??0),
    'selected_cabin_unit_id'=>(int)($b['selected_cabin_unit_id']??0),
    'cruise_program_source'=>$b['cruise_program']??null,
    'original_sailing_date'=>(string)($b['sailing_date']??''),
    'inventory_verified'=>false,'rate_inclusion_verified'=>false]));
  }
  $stayRate=$rate?get_post_meta($rate,'_booking_experience_stay_stages_v1',true):[];
  if(is_array($stayRate)&&is_array($stayRate['stages']??null))$stayRate=$stayRate['stages'];
  foreach((array)$stayRate as $i=>$entry){
   if(!is_array($entry))continue;
   $key=(string)($entry['key']??'stay-'.$i);
   $included=filter_var($entry['required']??false,FILTER_VALIDATE_BOOLEAN);
   $role=$included&&get_post_status($rate)==='publish'?'rate_stay_included_configured':'rate_stay_reference_only';
   $add(self::row('rate-stay|'.$rate.'|'.$key.'|'.$i,(string)($entry['label']??'Rate stay'),$role,
     max(0,(int)($entry['source_day_start']??$entry['day_offset']??0)),
     'stay-stage',0,$key,'accommodation',['rate_id'=>$rate,'original_stage'=>$entry,
      'inventory_verified'=>false,'cost_approved'=>false]));
  }
  foreach(self::ids($rate?get_post_meta($rate,'related_properties_id',true):[],'properties') as $id)
   $add(self::row('property-option|'.$rate.'|'.$id,(string)get_the_title($id),'rate_property_option_only',0,
     'properties',$id,'','accommodation',['rate_id'=>$rate,'source_status'=>get_post_status($rate),
      'property_stage_assignment_verified'=>false]));
  $guide=get_post_meta($tour,'_mad4b_guide_day_coverage_v1',true);
  $guideRate=$rate?get_post_meta($rate,'_mad4b_guide_rate_inclusion_v1',true):[];
  $n=0;
  foreach((array)($guide['statuses']??[]) as $key=>$status){
   ++$n;$state=sanitize_key((string)$status);
   if($state===''||in_array($state,['none','excluded','not_required'],true))continue;
   $add(self::row('guide|'.$tour.'|'.$key,'Guide · Day '.$n.' · '.$state,
    $state==='optional'?'guide_optional_source':'guide_day_source_not_rate_inclusion',
    $n,'guide-source',0,(string)$key,'guide',[
    'day_status'=>$state,'tour_guide_review_hash'=>(string)($guide['source_hash']??''),
    'rate_guide_status'=>is_array($guideRate)?(string)($guideRate['state']??$guideRate['status']??''):'' ,
    'supplier_approved'=>false,'price_authorized'=>false]));
  }
  $schedule=get_post_meta($tour,'_mad4b_optional_extras_schedule_v1',true);$scheduled=[];
  foreach((array)$schedule as $i=>$entry)if(is_array($entry)){
   $id=(int)($entry['canonical_extra']??(is_numeric($i)?$i:0));
   if(get_post_type($id)==='tours-and-activities')$scheduled[$id]=true;
  }
  foreach(self::ids(get_post_meta($tour,'tour_optional_extras',true),'tours-and-activities') as $id){
   if(isset($scheduled[$id]))continue;
   $add(self::row('extra-option|'.$tour.'|'.$id,(string)get_the_title($id),
    'tour_optional_catalog_only',0,'tours-and-activities',$id,'','extra_trip',[
    'customer_selected'=>false,'supplier_confirmation'=>false,'commercial_price_authorized'=>false]));
  }
  foreach(['vehicle'=>'vehicles_transport_services','train'=>'train_transport_services',
    'air'=>'air_transport_services'] as $mode=>$field){
   $flag=['vehicle'=>'tour_vehicles_transport_status','train'=>'tour_train_transport_status',
       'air'=>'tour_air_transport_status'][$mode];
   if((string)get_post_meta($tour,$flag,true)!=='1')continue;
   foreach(self::ids(get_post_meta($tour,$field,true),'transportations') as $id)
    $add(self::row('transport-option|'.$tour.'|'.$mode.'|'.$id,(string)get_the_title($id),
      'tour_transport_catalog_option_only',0,'transportations',$id,$mode,'transportation',[
      'customer_selected'=>false,'included_in_rate_unverified'=>true]));
  }
  // 278/279 retain all financial authority. This is only an existing-line pointer.
  foreach((array)$order->get_meta('_mad4b_trip_amendments_v1',true) as $i=>$financial){
   if(!is_array($financial))continue;
   $key=sanitize_text_field((string)($financial['id']??'item-'.$i));
   $type=sanitize_key((string)($financial['kind']??'custom'));
   $id=(int)($financial['source_id']??0);
   if($id&&!self::allowed((string)get_post_type($id)))$id=0;
   $label=sanitize_text_field((string)($financial['label']??$financial['title']??'Commercial amendment'));
   $add(self::row('commercial|'.$order->get_id().'|'.$key,$label,
    'existing_commercial_amendment_reference',0,$id?(string)get_post_type($id):$type,
    $id,$key,'existing_commercial_amendment',[
     'commercial_line_id'=>$key,'financial_owner'=>'MAD4B_Trip_Order_Amendments_V1',
     'not_new_billable_item'=>true,'price_and_invoice_are_owned_elsewhere'=>true]));
  }
  // Published tour inclusions are textual source promises, NOT verified
  // supplier line prices or stage allocations. Preserve one row per real
  // original repeater item so Operations can reconcile missing service links.
  $includedText=get_post_meta($tour,'includes',true);
  foreach((array)$includedText as $itemKey=>$item){
   if(!is_array($item)||!is_scalar($item['included_item']??null))continue;
   $literal=trim(wp_strip_all_tags(html_entity_decode((string)$item['included_item'],ENT_QUOTES|ENT_HTML5,'UTF-8')));
   if($literal==='')continue;
   $add(self::row('tour-inclusion|'.$tour.'|'.$itemKey,$literal,
      'published_inclusion_text_needs_rate_mapping',0,'editorial-inclusion',0,
      (string)$itemKey,'source_inclusion_text',[
      'original_field'=>'includes.included_item',
      'requires_component_assignment'=>true,
      'supplier_cost_not_verified'=>true,
      'no_duplicate_customer_charge'=>true]));
  }
  // Separate, source-backed Tour Leader review. Never re-label an
  // Egyptologist guide, vehicle driver or tour rate as a Tour Leader.
  // No supplier booking or financial amount is created by this discovery.
  $tourLeaderCandidateCount=0;
  if(class_exists('MAD4B_Tour_Leader_Itinerary_Audit_V1',false)){
    foreach(MAD4B_Tour_Leader_Itinerary_Audit_V1::serviceRows($tour,$program) as $candidate){
       $add($candidate);
       ++$tourLeaderCandidateCount;
    }
  }
  // Native JetEngine many-to-many relation means service eligibility only.
  // Relation data does not assign an itinerary day, supplier slot or a price.
  $eligibleNativeOperationalServices=0;
  if(function_exists('jet_engine') && is_object(jet_engine()->relations??null)){
    foreach(jet_engine()->relations->get_active_relations() as $relation){
      if($relation->get_args('parent_object')!=='posts::tours-and-activities' ||
         $relation->get_args('child_object')!=='posts::operational-services')
         continue;
      foreach((array)$relation->get_children($tour,'ids') as $sourceId){
        $sourceId=(int)$sourceId;
        if($sourceId<1||get_post_type($sourceId)!=='operational-services'||
          get_post_status($sourceId)!=='publish')continue;
        ++$eligibleNativeOperationalServices;
        $matched=false;
        foreach($rows as &$row){
          if(($row['family']??'')!=='tour_leader' ||
             (int)($row['id']??0)!==$sourceId)continue;
          $row['proof']['jetengine_tour_service_eligible']=true;
          $row['proof']['jetengine_relation_id']=$relation->get_id();
          $row['proof']['eligibility_is_rate_inclusion']=false;
          $matched=true;
        }
        unset($row);
        if(!$matched)$add(self::row(
          'jet-eligible-service|'.$tour.'|'.$sourceId,
          (string)get_the_title($sourceId),
          'jet_relation_eligible_needs_day_assignment',
          0,'operational-services',$sourceId,'','tour_leader',[
             'jetengine_relation_id'=>$relation->get_id(),
             'jetengine_tour_service_eligible'=>true,
             'source_kind'=>'relation_not_itinerary_commitment',
             'eligibility_is_rate_inclusion'=>false,
             'supplier_cost_verified'=>false,
             'commercial_price_authorized'=>false
          ]));
      }
    }
  }
  $options=function_exists('shared_product_cruise_assigned_programs_v1')?
    shared_product_cruise_assigned_programs_v1($tour):[];
  $programChoices=[];
  foreach($options as $id=>$name)$programChoices[]=['id'=>(int)$id,'name'=>sanitize_text_field($name),
    'rate_assigned'=>(int)$id===$program];
  return ['rows'=>array_values($rows),'source'=>[
   'contract'=>self::CONTRACT,'program'=>$program,'program_choices'=>$programChoices,
   'plan_stages'=>count((array)($plan['stages']??[])),
   'reviewed_guide_rows'=>count((array)($guide['statuses']??[])),
   'tour_leader_review_candidates'=>$tourLeaderCandidateCount,
   'tour_leader_review_contract'=>'mad4b.tour-leader-itinerary-review.v1',
   'jetengine_eligible_operational_services'=>$eligibleNativeOperationalServices,
   'service_row_count'=>count($rows),'financial_billing_owner'=>'278_279',
   'commercial_rates_untouched'=>true,'source_scope'=>'original_native_data']];
 }
}
