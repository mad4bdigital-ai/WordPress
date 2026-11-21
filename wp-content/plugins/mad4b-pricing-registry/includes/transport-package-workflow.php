<?php
/**
 * MAD4B native Tour Rate transportation assignment.
 * Route candidates and draft readiness are not supplier availability.
 * All writes are scoped to existing unpublished Tour Rates.
 */
defined('ABSPATH') || exit;

final class MAD4B_Transport_Package_Workflow_V1 {
 const CONTRACT='mad4b.transport-package-workflow.v1';
 const INPUTS_META='_mad4b_transport_component_cost_inputs_v1';
 const FIELDS=['transport_selection_mode','related_transportations_id',
  '_mad4b_transport_stages_v1','transport_price_policy',self::INPUTS_META];
 const STAGE_FIELDS=['key','label','day_offset','origin_term_id',
  'destination_term_id','return_to_origin','required','join',
  'service_kind','price_policy','transport_ids'];

 public static function permitted():bool {
  return class_exists('MAD4B_Transport_Tour_Rate_Routing_V1')
   && MAD4B_Transport_Tour_Rate_Routing_V1::permitted()
   && class_exists('MAD4B_Transport_Cost_Bridge_V1');
 }
 private static function editable(int $id):bool {
  return $id>0 && get_post_type($id)==='tour-rates'
   && current_user_can('edit_post',$id);
 }
 private static function snapshot_meta(int $id):array {
  $meta=[];
  foreach(self::FIELDS as $field)$meta[$field]=[
   'exists'=>metadata_exists('post',$id,$field),
   'value'=>get_post_meta($id,$field,true)];
  return $meta;
 }
 private static function hash(int $id,array $meta):string {
  return hash('sha256',wp_json_encode([
   'site'=>home_url('/'),'tour_rate_id'=>$id,'status'=>get_post_status($id),
   'parent_tour_id'=>get_post_meta($id,'related_tour_id',true),
   'meta'=>$meta]));
 }
 public static function snapshot(int $id) {
  if(!self::permitted()||!self::editable($id))
   return new WP_Error('transport_package_edit_permission_required');
  $meta=self::snapshot_meta($id);
  return ['contract'=>self::CONTRACT,'tour_rate_id'=>$id,
   'post_status'=>get_post_status($id),'parent_tour_id'=>
     (int)get_post_meta($id,'related_tour_id',true),
   'hash'=>self::hash($id,$meta),'mode'=>$meta['transport_selection_mode']['value']?:'simple',
   'simple_selected'=>get_post_meta($id,'related_transportations_id',true),
   'stages'=>get_post_meta($id,'_mad4b_transport_stages_v1',true),
   'cost_inputs'=>get_post_meta($id,self::INPUTS_META,true),
   'price_policy'=>$meta['transport_price_policy']['value']?:'inquiry',
   'writable'=>get_post_status($id)==='draft',
   'commercial_ready'=>false,'checkout_authorized'=>false];
 }
 private static function lang(int $id):string {
  $d=apply_filters('wpml_post_language_details',null,$id);
  return is_array($d)&&is_string($d['language_code']??null)
    ?sanitize_key($d['language_code']):'en';
 }
 /** Reuses existing route discovery, not a parallel matching implementation. */
 public static function candidates(array $stage,string $lang='en') {
  if(!self::permitted())return new WP_Error('transport_package_not_authorized');
  $found=MAD4B_Transport_Tour_Rate_Routing_V1::candidates($stage,$lang);
  if(is_wp_error($found))return $found;
  $groups=['draft_eligible'=>[],'needs_route_review'=>[]];
  foreach(['qualified_for_draft_composition'=>'draft_eligible',
           'needs_route_review'=>'needs_route_review'] as $key=>$group){
   foreach($found[$key] as $item){
    $id=(int)$item['post_id'];
    $costs=get_post_meta($id,'_mad4b_transfer_pdf_2026_raw_vehicles',true);
    $usable=[];
    if(is_array($costs)){
     foreach(MAD4B_Transport_Distance_Time_V1::VEHICLES as $vehicle){
      if(isset($costs[$vehicle])&&is_numeric($costs[$vehicle])&&
         (float)$costs[$vehicle]>0)$usable[$vehicle]=round((float)$costs[$vehicle],2);
     }
    }
    $status=$item['post_status'];
    $identity=$status==='publish'&&
     get_post_meta($id,'_mad4b_transfer_pdf_2026_provider_id',true)==='self'&&
     get_post_meta($id,'_mad4b_transfer_pdf_2026_sha256',true)===
      MAD4B_Transport_Distance_Time_V1::SHA&&
     get_post_meta($id,'trans_use_in_tour_rate_generation',true)==='1';
    $ready=$group==='draft_eligible'&&$identity&&count($usable)>0;
    $item['draft_eligible']=$ready;
    $item['available_vehicle_pdf_costs_egp']=$usable;
    $item['availability_confirmed']=
      get_post_meta($id,'trans_available_status',true)==='1';
    $item['draft_state']=$ready?'candidate_for_unpublished_rate':'requires_review';
    $item['supplier_quote_verified']=false;
    $item['route_pickup_dropoff_verified']=false;
    $item['rate_approved']=false;
    $item['checkout_authorized']=false;
    $item['commercial_ready']=false;
    $item['blocking_reasons']=array_values(array_unique(array_merge(
     $item['blocking_reasons'],
     $usable?[]:['no_positive_vehicle_reference_cost'],
     $identity?[]:['source_identity_or_publication_invalid'],
     ['supplier_date_availability_not_confirmed']
    )));
    $groups[$ready?'draft_eligible':'needs_route_review'][]=$item;
   }
  }
  return ['contract'=>self::CONTRACT,'stage'=>$found['stage'],'lang'=>$lang,
   'draft_eligible'=>$groups['draft_eligible'],
   'needs_route_review'=>$groups['needs_route_review'],
   'excluded_count'=>$found['excluded_count'],
   'draft_eligible_not_commercial'=>true,'availability_not_inferred'=>true,
   'checkout_authorized'=>false];
 }
 public static function composition(array $request) {
  if(!self::permitted())return new WP_Error('transport_package_not_authorized');
  $tour=absint($request['tour_rate_id']??0);
  if(!$tour||!self::editable($tour))
   return new WP_Error('transport_package_tour_not_editable');
  $lang=self::lang($tour);
  $mode=(string)($request['mode']??'simple');
  $stages=$request['stages']??null;
  if(!is_array($stages)||!array_is_list($stages)||!$stages||
     count($stages)>30||($mode==='simple'&&count($stages)!==1)||
     !in_array($mode,['simple','stages'],true))
   return new WP_Error('transport_package_composition_invalid');
  $output=[];$missing=false;$eligible=0;
  foreach($stages as $stage){
   if(!is_array($stage))return new WP_Error('transport_package_stage_invalid');
   $result=self::candidates($stage,$lang);
   if(is_wp_error($result))return $result;
   $count=count($result['draft_eligible']);$eligible+=$count;
   if(!empty($result['stage']['required'])&&$count===0)$missing=true;
   $output[]=['stage'=>$result['stage'],
    'join_operator'=>$result['stage']['join'],
    'required'=>$result['stage']['required'],
    'alternatives'=>$result['draft_eligible'],
    'review_queue'=>$result['needs_route_review'],
    'candidate_count'=>$count,
    'needs_review_count'=>count($result['needs_route_review']),
    'commercial_ready'=>false];
  }
  return ['contract'=>self::CONTRACT,'mode'=>$mode,'lang'=>$lang,
   'stages'=>$output,'draft_eligible_candidate_count'=>$eligible,
   'has_missing_required_stage'=>$missing,
   'availability_verified'=>false,'price_calculation_performed'=>false,
   'checkout_authorized'=>false];
 }
 private static function canonical_stages($stages,string $mode,string $lang) {
  if(!is_array($stages)||!array_is_list($stages)||!$stages||
     count($stages)>30||($mode==='simple'&&count($stages)!==1))
   return new WP_Error('transport_package_stage_count_invalid');
  $out=[];$selected=[];$joins=[];$stageKeys=[];
  foreach($stages as $original){
   if(!is_array($original)||array_diff(array_keys($original),self::STAGE_FIELDS))
    return new WP_Error('transport_package_stage_fields_invalid');
   $validated=MAD4B_Transport_Tour_Rate_Routing_V1::validate_stage($original);
   if(is_wp_error($validated))return $validated;
   $key=$validated['key'];
   if(isset($stageKeys[$key]))return new WP_Error('transport_package_stage_key_duplicate');
   $stageKeys[$key]=true;
   $ids=$validated['selected_transport_ids'];
   if(!$ids||count($ids)>20)
    return new WP_Error('transport_package_selected_source_count_invalid');
   if($mode==='simple'&&$validated['join']!=='OR')
    return new WP_Error('transport_package_simple_requires_or');
   if($validated['join']==='OR'&&count($ids)>1&&$mode==='stages')
    return new WP_Error('transport_package_or_stage_select_one_for_cost');
   $matching=self::candidates(array_merge($original,['transport_ids'=>[]]),$lang);
   if(is_wp_error($matching))return $matching;
   $allowed=[];
   foreach($matching['draft_eligible'] as $c)$allowed[(int)$c['post_id']]=true;
   foreach($ids as $id){
    if(!isset($allowed[$id]))return new WP_Error('transport_package_source_not_draft_qualified');
    $selected[$id]=true;
   }
   $row=[];
   foreach(self::STAGE_FIELDS as $field)
    if(array_key_exists($field,$original))$row[$field]=$original[$field];
   foreach(['key','label','day_offset','origin_term_id','destination_term_id',
      'return_to_origin','required','join','service_kind','price_policy'] as $k){
    if(!array_key_exists($k,$row)){
     if($k==='destination_term_id')$row[$k]=$validated['destination_term_id'];
     elseif(in_array($k,['return_to_origin','required','day_offset','origin_term_id'],true))
      $row[$k]=$validated[$k];
     else $row[$k]=$validated[$k];
    }
   }
   $row['transport_ids']=array_values($ids);
   $out[]=$row;
   $joins[$key]=['join'=>$validated['join'],'ids'=>$ids];
  }
  return ['stages'=>$out,'selected'=>array_keys($selected),'joins'=>$joins];
 }
 private static function checked_lines($raw,array $joins,array $selected) {
  if(!is_array($raw)||!array_is_list($raw)||count($raw)>30)
   return new WP_Error('transport_package_cost_lines_invalid');
  if(!$raw)return [];
  $selected=array_fill_keys($selected,true);
  $seen=[];$out=[];
  foreach($raw as $line){
   if(!is_array($line)||array_diff(array_keys($line),
      MAD4B_Transport_Cost_Bridge_V1::LINE_FIELDS))
    return new WP_Error('transport_package_cost_line_fields_invalid');
   $id=absint($line['transportation_id']??0);
   $key=(string)($line['stage_key']??'');
   if(!$id||!isset($selected[$id]))
    return new WP_Error('transport_package_cost_source_not_selected');
   $belongs=false;
   foreach($joins as $stage=>$group){
    if(!in_array($id,$group['ids'],true))continue;
    $validKey=$group['join']==='OR'?$stage:$stage.'_'.$id;
    if($key===$validKey){$belongs=true;break;}
   }
   if(!$belongs||isset($seen[$key]))
    return new WP_Error('transport_package_cost_stage_invalid');
   $seen[$key]=true;
   $out[]=$line;
  }
  $estimate=MAD4B_Transport_Cost_Bridge_V1::evaluate([
   'context'=>'planning','lines'=>$out]);
  if(is_wp_error($estimate))return $estimate;
  return $out;
 }
 private static function verify_meta(int $id,array $expected):bool {
  foreach($expected as $key=>$value){
   if($value===null){
    if(metadata_exists('post',$id,$key))return false;
   }elseif(wp_json_encode(get_post_meta($id,$key,true))!==wp_json_encode($value))
    return false;
  }
  return true;
 }
 private static function restore(int $id,array $before):bool {
  foreach($before as $key=>$prior){
   if(!$prior['exists'])delete_post_meta($id,$key);
   else update_post_meta($id,$key,$prior['value']);
  }
  foreach($before as $key=>$prior){
   if(metadata_exists('post',$id,$key)!==$prior['exists'])return false;
   if($prior['exists']&&wp_json_encode(get_post_meta($id,$key,true))!==
       wp_json_encode($prior['value']))return false;
  }
  return true;
 }
 /** Existing Draft only. Never changes a published commercial Tour Rate. */
 public static function save(array $request) {
  if(!self::permitted())return new WP_Error('transport_package_not_authorized');
  if(array_diff(array_keys($request),[
    'tour_rate_id','expected_hash','mode','stages','cost_lines']))
   return new WP_Error('transport_package_write_fields_unknown');
  $id=absint($request['tour_rate_id']??0);
  if(!self::editable($id))return new WP_Error('transport_package_rate_edit_denied');
  if(get_post_status($id)!=='draft')
   return new WP_Error('transport_package_unpublished_draft_required');
  $expected=$request['expected_hash']??null;
  if(!is_string($expected)||!preg_match('/^[a-f0-9]{64}$/D',$expected))
   return new WP_Error('transport_package_expected_hash_required');
  $mode=(string)($request['mode']??'');
  if(!in_array($mode,['simple','stages'],true))
   return new WP_Error('transport_package_mode_invalid');
  $parent=(int)get_post_meta($id,'related_tour_id',true);
  if($parent<=0||get_post_type($parent)!=='tours-and-activities')
   return new WP_Error('transport_package_tour_parent_missing');
  $pre=self::snapshot_meta($id);
  if(!hash_equals(self::hash($id,$pre),$expected))
   return new WP_Error('transport_package_stale_editor');
  $validated=self::canonical_stages($request['stages']??null,$mode,self::lang($id));
  if(is_wp_error($validated))return $validated;
  $costs=self::checked_lines($request['cost_lines']??[], $validated['joins'],$validated['selected']);
  if(is_wp_error($costs))return $costs;
  $fieldValues=[
   'transport_selection_mode'=>$mode,
   'related_transportations_id'=>$mode==='simple'?$validated['selected']:null,
   '_mad4b_transport_stages_v1'=>$mode==='stages'?$validated['stages']:null,
   'transport_price_policy'=>$mode==='simple'?
     $validated['stages'][0]['price_policy']:'inquiry',
   self::INPUTS_META=>$costs?:null
  ];
  $lock='mad4b_transport_package_lock_'.$id;
  $token=wp_generate_uuid4();
  if(!add_option($lock,['token'=>$token,'started'=>time()],'',false))
   return new WP_Error('transport_package_save_in_progress');
  try {
   $current=self::snapshot_meta($id);
   if(!hash_equals(self::hash($id,$current),$expected))
    return new WP_Error('transport_package_stale_editor');
   foreach($fieldValues as $key=>$value){
    if($value===null)delete_post_meta($id,$key);
    else update_post_meta($id,$key,$value);
   }
   if(!self::verify_meta($id,$fieldValues)){
    $restored=self::restore($id,$pre);
    return new WP_Error($restored?'transport_package_save_readback_failed_rolled_back':
       'transport_package_save_readback_failed_manual_recovery');
   }
   $saved=self::snapshot($id);
   if(is_wp_error($saved))return $saved;
   $saved['saved']=true;$saved['native_fields_only']=true;
   $saved['cost_inputs_saved_count']=count($costs);
   $saved['draft_eligible_selection_count']=count($validated['selected']);
   $saved['rate_generated']=false;$saved['checkout_authorized']=false;
   return $saved;
  } finally {
   $held=get_option($lock);
   if(is_array($held)&&($held['token']??null)===$token)delete_option($lock);
  }
 }
 public static function rest():void {
  register_rest_route('mad4b-pricing/v1','/transport-package-workflow',[
   'methods'=>'POST',
   'permission_callback'=>[__CLASS__,'permitted'],
   'callback'=>static function($request){
    $body=$request->get_json_params();
    if(!is_array($body))return new WP_Error('transport_package_json_invalid','Invalid JSON',['status'=>400]);
    $action=(string)($body['action']??'');
    if($action==='composition'){
     $result=self::composition($body);
    }elseif($action==='candidates'){
     $lang=sanitize_key((string)($body['lang']??'en'));
     $stage=$body['stage']??null;
     $result=is_array($stage)?self::candidates($stage,$lang):
       new WP_Error('transport_package_stage_missing');
    }elseif($action==='snapshot'){
     $result=self::snapshot(absint($body['tour_rate_id']??0));
    }elseif($action==='save'){
     unset($body['action']);
     $result=self::save($body);
    }else $result=new WP_Error('transport_package_action_unknown');
    return is_wp_error($result)?
      new WP_Error($result->get_error_code(),$result->get_error_message(),['status'=>400]):
      rest_ensure_response($result);
   }
  ]);
 }
 public static function boot():void {
  add_action('rest_api_init',[__CLASS__,'rest']);
 }
}
MAD4B_Transport_Package_Workflow_V1::boot();
