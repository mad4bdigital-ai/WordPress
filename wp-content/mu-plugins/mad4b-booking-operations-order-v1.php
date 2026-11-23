<?php
/**
 * Plugin Name: MAD4B Booking Operations — WooCommerce Order Workspace
 * Description: Original-source, revisioned, nonfinancial operations planning directly inside the native WooCommerce order editor (HPOS/legacy).
 * Version: 1.3.0
 */
defined('ABSPATH') || exit;
if (!class_exists('MAD4B_Booking_Operations_Order_V1',false)) {
final class MAD4B_Booking_Operations_Order_V1 {
 const CONTRACT='mad4b.booking-operations-order.v1';
 const META='_mad4b_booking_ops_workspace_v1';
 const HISTORY='_mad4b_booking_ops_history_v1';
 const ACTION='mad4b_booking_ops_order_v1';
 public static function allowed():bool{
   return is_user_logged_in()&&(current_user_can('manage_woocommerce')||current_user_can('edit_shop_orders'));
 }
 private static function problem(string $code):WP_Error{return new WP_Error($code);}
 private static function canonical(int $id):int{
   if(get_post_type($id)!=='tours-and-activities')return 0;
   $en=apply_filters('wpml_object_id',$id,'tours-and-activities',false,'en');
   return is_numeric($en)&&get_post_type((int)$en)==='tours-and-activities'?(int)$en:$id;
 }
 private static function fields($raw,string $type):array{
   if(is_string($raw)){$json=json_decode($raw,true);$raw=is_array($json)?$json:preg_split('/[,|]/',$raw);}
   if(!is_array($raw))$raw=[$raw];
   $ids=[];
   foreach($raw as $id)if(is_scalar($id)&&ctype_digit((string)$id)&&
     (int)$id>0&&get_post_type((int)$id)===$type)$ids[(int)$id]=(int)$id;
   return array_values($ids);
 }
 /** An original Form 6 optional-Extra preference is not a commercial booking. */
 public static function correlateExtraPreferences(array $rows,int $tour,$prefs):array{
   $status='not_recorded';$chosen=[];
   if(is_array($prefs)){
     $declared=(int)($prefs['tour_id']??0);
     $correct=($prefs['contract']??'')==='mad4b.extra-preferences.v1' &&
        $declared>0 && self::canonical($declared)===self::canonical($tour) &&
        is_array($prefs['extra_ids']??null) && array_is_list($prefs['extra_ids']);
     if(!$correct)$status='invalid_source_pref_requires_review';
     else{
       $allowed=self::fields(get_post_meta($tour,'tour_optional_extras',true),'tours-and-activities');
       $valid=array_fill_keys($allowed,true);$status='verified_original_selection_scope';
       foreach($prefs['extra_ids'] as $extra){
          if(!is_int($extra)||!isset($valid[$extra])||isset($chosen[$extra])){
             $status='invalid_source_pref_requires_review';$chosen=[];break;
          }
          $chosen[$extra]=true;
       }
     }
   }
   if($status==='verified_original_selection_scope'){
      foreach($chosen as $extra=>$_){
        $found=false;
        foreach($rows as &$line){
          if((int)($line['source_post_id']??0)!==$extra ||
             ($line['source_kind']??'')!=='tours-and-activities' ||
             !in_array($line['source_role']??'',[
               'extra_request_only','tour_optional_catalog_only'],true))continue;
          $line['source_role']='customer_requested_optional_extra';
          $line['source_evidence']['selected_in_saved_form6']=true;
          $line['source_evidence']['commercially_booked']=false;
          $found=true;
        }unset($line);
        if(!$found){
           $row=self::base('form6-selected-extra|'.$tour.'|'.$extra,
            (string)get_the_title($extra),'customer_requested_optional_extra',0,
            'tours-and-activities',$extra,'form6-extra');
           $row['source_family']='extra_trip';
           $row['source_evidence']=['selected_in_saved_form6'=>true,
              'commercially_booked'=>false,'supplier_confirmation_required'=>true];
           $rows[]=$row;
        }
      }
   }
   return ['rows'=>$rows,'status'=>$status,'chosen_ids'=>array_keys($chosen)];
 }
 private static function base(string $identity,string $title,string $role,int $day,
                              string $kind,int $sourceId=0,string $stage=''):array{
   return [
     'key'=>substr(hash('sha256',$identity),0,24),
     'native_title'=>sanitize_text_field($title),'source_role'=>$role,
     'source_kind'=>$kind,'source_post_id'=>$sourceId,'day'=>$day,
     'stage_key'=>$stage,'source_family'=>'unspecified','source_evidence'=>[],
     'origin'=>'original',
     'custom_title'=>'','group_label'=>'','scope_change'=>'keep_source',
     'service_quantity'=>null,'service_quantity_unit'=>'',
     'selected_transport_id'=>null,'vehicle_category'=>'','fleet_reference'=>'','supplier_reference'=>'',
     'vehicles'=>null,'actual_seats'=>null,'actual_large_bags_capacity'=>null,
     'adults'=>null,'children'=>null,'infants'=>null,'large_bags'=>null,
     'child_seats_confirmed'=>null,'child_restraint_reference'=>'','pickup_at'=>'','service_date'=>'',
     'meeting_point'=>'','destination_note'=>'',
     'cost_estimate'=>null,'currency'=>'','confirmation_reference'=>'',
     'notes'=>'','operation_state'=>'draft','operator_verified'=>false,
     'commercial_price_authorized'=>false,'supplier_stock_verified'=>false
   ];
 }
 private static function order(int $id):WC_Order|WP_Error{
   if(!self::allowed())return self::problem('operations_permission_denied');
   if(!function_exists('wc_get_order')||$id<=0)return self::problem('operations_order_required');
   $order=wc_get_order($id);
   if(!($order instanceof WC_Order))return self::problem('operations_order_not_found');
   if((int)$order->get_meta('_request_form_id')!==6||
       (int)$order->get_meta('_request_submission_id')<1||
       (int)$order->get_meta('_request_source_id')<1)
      return self::problem('operations_not_original_form6_order');
   return $order;
 }
 public static function source(int $orderId):array|WP_Error{
   $order=self::order($orderId);
   if(is_wp_error($order))return $order;
   if(!class_exists('MAD4B_Transport_Request_Party_Context_V1',false))
     return self::problem('operations_original_party_adapter_unavailable');
   $party=MAD4B_Transport_Request_Party_Context_V1::fromOrder($orderId);
   if(is_wp_error($party))return $party;
   $tour=(int)($party['source_tour_id']??0);
   $nativeTour=self::canonical($tour);
   if(!$nativeTour)return self::problem('operations_original_tour_unverified');
   $rate=(int)$order->get_meta('_request_rate_id');
   $rateStatus='not_assigned';
   if($rate){
     if(get_post_type($rate)!=='tour-rates' ||
       self::canonical((int)get_post_meta($rate,'related_tour_id',true))!==$nativeTour)
       return self::problem('operations_order_tour_rate_mismatch');
     $rateStatus=(string)get_post_status($rate);
   }
   $program=$rate?(int)get_post_meta($rate,'related_program_term_id',true):0;
   $programTerm=$program?get_term($program,'programs'):null;
   $submission=(int)$order->get_meta('_request_submission_id');
   $context=call_user_func(['Commerce\\Composition\\RequestAdvancePayments','submissionContext'],6,$submission);
   if(is_wp_error($context))return self::problem('operations_form6_intent_unavailable');
   $travelDate=(string)($context['intent']['travel_date']??'');
   if($travelDate!==''&&!preg_match('/^\d{4}-\d{2}-\d{2}$/D',$travelDate))$travelDate='';
   $itinerarySource=class_exists('MAD4B_Guide_Service_Composition_V1',false)?
       MAD4B_Guide_Service_Composition_V1::source($nativeTour,$program):
       self::problem('operations_itinerary_adapter_unavailable');
   $itinerary=[];$itineraryProof=['status'=>'unavailable'];
   if(!is_wp_error($itinerarySource)){
     $raw=($itinerarySource['source_kind']??'')==='term'
       ?get_term_meta((int)$itinerarySource['source_id'],'program_itinerary',true)
       :get_post_meta((int)$itinerarySource['source_id'],'itinerary_item',true);
     if(is_array($raw))$itinerary=$raw;
     $itineraryProof=[
       'status'=>'source_verified','kind'=>(string)($itinerarySource['source_kind']??''),
       'owner_id'=>(int)($itinerarySource['source_id']??0),
       'source_hash'=>(string)($itinerarySource['source_hash']??''),
       'day_unit_basis'=>(string)($itinerarySource['day_unit_basis']??'')
     ];
   }else $itineraryProof['status']=$itinerarySource->get_error_code();
   $rateStages=$rate?get_post_meta($rate,'_mad4b_transport_stages_v1',true):[];
   if(is_array($rateStages)&&isset($rateStages['stages'])&&is_array($rateStages['stages']))
      $rateStages=$rateStages['stages'];
   if(!is_array($rateStages))$rateStages=[];
   $extraSchedule=get_post_meta($nativeTour,'_mad4b_optional_extras_schedule_v1',true);
   if(!is_array($extraSchedule))$extraSchedule=[];
   $rows=[];$hints=[];$verifiedIncluded=0;$sourceOptional=0;
   $oneDay=in_array($itineraryProof['day_unit_basis']??'',[
      'explicit_one_day_aggregated_stages','explicit_single_day_duration_taxonomy'],true);
   foreach($itinerary as $key=>$day){
     if(!is_array($day))continue;
     $need=(string)($day['mad4b_transport_need_v1']??'review');
     $n=$oneDay?1:count($hints)+1;
     $hints[]=[
       'day'=>$n,'key'=>(string)$key,'title'=>sanitize_text_field((string)($day['title']??'')),
       'need'=>$need,'mode'=>(string)($day['mad4b_transport_mode_v1']??'auto'),
       'kind'=>(string)($day['mad4b_transport_service_v1']??'any'),
       'from_term'=>(int)($day['mad4b_transport_from_term_v1']??0),
       'to_term'=>(int)($day['mad4b_transport_to_term_v1']??0),
       'vehicle_preference'=>(string)($day['mad4b_transport_vehicle_v1']??'auto'),
       'suggested_source_ids'=>self::fields($day['mad4b_transport_sources_v1']??[],
                                           'transportations')
     ];
     if(in_array($need,['required','optional'],true)){
       // Itinerary is a request for investigation, never inclusion/price authority.
       $rows[]=self::base('itinerary|'.$nativeTour.'|'.$key,
          (string)($day['title']??'Transport need'),'itinerary_unlinked',$n,
          (string)($day['mad4b_transport_mode_v1']??'auto'),0,(string)$key);
     }
   }
   foreach($rateStages as $index=>$stage){
     if(!is_array($stage))continue;
     $key=(string)($stage['key']??('stage-'.$index));
     $choices=self::fields($stage['transport_ids']??[],'transportations');
     $required=filter_var($stage['required']??false,FILTER_VALIDATE_BOOLEAN);
     $role=$required?'rate_included':'rate_optional';
     $row=self::base('rate|'.$rate.'|'.$key.'|'.$index,
        (string)($stage['label']??'Transport stage'),' '.$role,
        isset($stage['day_offset'])?max(0,(int)$stage['day_offset'])+1:0,
        'transportations',count($choices)===1?$choices[0]:0,$key);
     $row['source_role']=$role;
     $row['native_choices']=$choices;
     $row['native_join']=(string)($stage['join']??'OR');
     $rows[]=$row;
     if($required)$verifiedIncluded++;else $sourceOptional++;
   }
   foreach($extraSchedule as $key=>$entry){
     if(!is_array($entry))continue;
     $id=(int)($entry['canonical_extra']??(is_numeric($key)?$key:0));
     if(get_post_type($id)!=='tours-and-activities')continue;
     $row=self::base('extra|'.$nativeTour.'|'.$id,
         (string)get_the_title($id),'extra_request_only',(int)($entry['day']??0),
         'tours-and-activities',$id);
     $row['schedule_supplier_confirmation']=!empty($entry['supplier_cost_approved']) &&
                                             !empty($entry['reservation_confirmed']);
     $rows[]=$row;
   }
   // Source-backed service composition is read-only; it does not duplicate the
   // commercial line-item or Tour Rate authorities from the existing 278/279 UI.
   $families=class_exists('MAD4B_Booking_Operations_Source_Families_V1',false)
     ?MAD4B_Booking_Operations_Source_Families_V1::compose($order,$nativeTour,$rate,$program)
     :['rows'=>[],'source'=>['status'=>'native_service_families_adapter_missing']];
   foreach($families['rows'] as $sourceRow){
     $row=self::base('family|'.$sourceRow['identity'],
       (string)$sourceRow['title'],(string)$sourceRow['role'],
       (int)$sourceRow['day'],(string)$sourceRow['type'],
       (int)$sourceRow['id'],(string)$sourceRow['stage']);
     $row['source_family']=(string)$sourceRow['family'];
     $row['source_evidence']=(array)($sourceRow['proof']??[]);
     $rows[]=$row;
   }
   $extrasEvidence=self::correlateExtraPreferences($rows,$nativeTour,
      $context['data']['_mad4b_extra_preferences_v1']??null);
   $rows=$extrasEvidence['rows'];
   $receipt=[
     'tour'=>$nativeTour,'source_tour'=>$tour,'rate'=>$rate,'rate_status'=>$rateStatus,'program'=>$program,
     'party'=>array_intersect_key($party,['counts'=>1,'source_tour_id'=>1,'status'=>1]),
     'itinerary_source'=>$itineraryProof,'itinerary'=>$itinerary,
     'rate_stages'=>$rateStages,'optional_extras'=>$extraSchedule,
     'service_families'=>$families,
     'original_form6_extra_selection'=>[$extrasEvidence['status'],$extrasEvidence['chosen_ids']]
   ];
   return [
      'contract'=>self::CONTRACT,
      'order_id'=>$orderId,'order_status'=>$order->get_status(),
      'order_closed'=>in_array($order->get_status(),['cancelled','refunded','failed','trash'],true),
      'tour_id'=>$tour,'canonical_tour_id'=>$nativeTour,'tour_title'=>get_the_title($tour),
      'rate_id'=>$rate,'rate_status'=>$rateStatus,'program_id'=>$program,
      'rate_edit_url'=>$rate&&current_user_can('edit_post',$rate)?
          (string)(get_edit_post_link($rate,'raw')?:''):'',
      'program_title'=>$programTerm instanceof WP_Term?$programTerm->name:'',
      'itinerary_source_status'=>$itineraryProof['status'],
      'travel_date'=>$travelDate,
      'form_id'=>6,'submission_id'=>$submission,
      'party'=>$party['counts'],
      'itinerary'=>$hints,'native_service_rows'=>$rows,
      'native_service_families'=>$families['source'],
      'form6_extra_selection_status'=>$extrasEvidence['status'],
      'form6_selected_extra_count'=>count($extrasEvidence['chosen_ids']),
      'catalog_types'=>class_exists('MAD4B_Booking_Operations_Source_Families_V1',false)?
          MAD4B_Booking_Operations_Source_Families_V1::types():[],
      'included_source_stages'=>$verifiedIncluded,'optional_source_stages'=>$sourceOptional,
      'source_fingerprint'=>hash('sha256',wp_json_encode($receipt)),
      'commercial_rate_status'=>'not_approved_by_operations_workspace',
      'payment_mutations_allowed'=>false,'booking_confirmation_allowed'=>false
   ];
 }
 public static function read(int $orderId):array|WP_Error{
   $source=self::source($orderId);
   if(is_wp_error($source))return $source;
   $order=wc_get_order($orderId);
   $stored=$order->get_meta(self::META,true);
   if(!is_array($stored))$stored=[];
   $native=[];$current=[];
   foreach($source['native_service_rows'] as $r)$native[$r['key']]=$r;
   foreach((array)($stored['lines']??[]) as $r){
     if(is_array($r)&&isset($r['key'])&&is_string($r['key']))$current[$r['key']]=$r;
   }
   $merged=[];
   foreach($native as $key=>$base){
     $v=$current[$key]??[];
     $editable=array_intersect_key((array)$v,array_flip(self::EDITABLE));
     $merged[]=array_replace($base,$editable);
   }
   foreach($current as $key=>$row){
      if(isset($native[$key]))continue;
      if(($row['origin']??'')==='manual')$merged[]=$row;
   }
   $source['lines']=$merged;
   $source['revision']=(int)($stored['revision']??0);
   $source['saved_source_fingerprint']=(string)($stored['source_fingerprint']??'');
   $source['source_changed_since_save']=!empty($stored['revision']) &&
      $source['source_fingerprint']!==$source['saved_source_fingerprint'];
   $source['last_editor_id']=(int)($stored['editor_id']??0);
   $source['last_edit_utc']=(string)($stored['at_utc']??'');
   return $source;
 }
 public const EDITABLE=[
    'custom_title','group_label','scope_change','service_quantity','service_quantity_unit',
    'selected_transport_id','vehicle_category','fleet_reference',
    'supplier_reference','vehicles','actual_seats','actual_large_bags_capacity',
    'adults','children','infants','large_bags','child_seats_confirmed',
    'child_restraint_reference','pickup_at','service_date','meeting_point','destination_note',
    'cost_estimate','currency','confirmation_reference','notes','operation_state',
    'operator_verified'
 ];
 private static function integerOrNull($v,string $name):int|null|WP_Error{
    if($v===null||$v==='')return null;
    if(is_bool($v)||!is_scalar($v)||!preg_match('/^(0|[1-9]\d*)$/D',(string)$v))
      return self::problem('operations_invalid_integer_'.$name);
    $n=filter_var((string)$v,FILTER_VALIDATE_INT,['options'=>['min_range'=>0]]);
    if($n===false)return self::problem('operations_integer_out_of_range_'.$name);
    return $n;
 }
 public static function validate(array $data,array $source):array|WP_Error{
   if(!array_is_list($data))return self::problem('operations_lines_must_be_array');
   $allowed=array_keys(self::readLineTemplate());$native=[];
   foreach($source['native_service_rows'] as $line)$native[$line['key']]=$line;
   $keys=[];$out=[];
   foreach($data as $r){
     if(!is_array($r)||!is_string($r['key']??null)||
       !preg_match('/^[a-f0-9]{24}$/D',$r['key'])||isset($keys[$r['key']]))
       return self::problem('operations_duplicate_or_invalid_line_key');
     $keys[$r['key']]=true;
     $base=$native[$r['key']]??null;
     $manual=$base===null;
     if($manual){
       if(($r['origin']??'')!=='manual')return self::problem('operations_source_row_forgery');
       $base=self::base('manual|'.$r['key'],'','manual_request',0,'manual');
       $base['key']=$r['key'];$base['origin']='manual';
       $sid=(int)($r['source_post_id']??0);
       if($sid){
         if(!class_exists('MAD4B_Booking_Operations_Source_Families_V1',false)||
          !MAD4B_Booking_Operations_Source_Families_V1::allowed((string)get_post_type($sid)))
            return self::problem('operations_manual_reference_unknown');
         $base['source_post_id']=$sid;
         $base['source_kind']=get_post_type($sid);
         $base['native_title']=(string)get_the_title($sid);
       }
       $day=self::integerOrNull($r['day']??0,'day');
       if(is_wp_error($day))return $day;
       $base['day']=$day??0;
     }
     $line=$base;
     foreach(self::EDITABLE as $field){
       if(!array_key_exists($field,$r))continue;
       $val=$r[$field];
       if(in_array($field,['selected_transport_id','vehicles','actual_seats','actual_large_bags_capacity','adults',
         'children','infants','large_bags','child_seats_confirmed'],true)){
          $x=self::integerOrNull($val,$field);
          if(is_wp_error($x))return $x;
          $line[$field]=$x;
       }elseif($field==='service_quantity'){
          if($val===null||$val==='')$line[$field]=null;
          elseif(!is_scalar($val)||is_bool($val)||!is_numeric($val)||
                 !is_finite((float)$val)||(float)$val<0)
             return self::problem('operations_service_quantity_invalid');
          else $line[$field]=(float)$val;
       }elseif($field==='cost_estimate'){
          if($val===null||$val==='')$line[$field]=null;
          elseif(!is_numeric($val)||!is_finite((float)$val)||(float)$val<0)
             return self::problem('operations_cost_estimate_invalid');
          else $line[$field]=round((float)$val,2);
       }elseif($field==='operator_verified')$line[$field]=$val===true;
       else {
         if(!is_scalar($val)||is_bool($val))return self::problem('operations_invalid_'.$field);
         $line[$field]=mb_substr(sanitize_textarea_field((string)$val),0,
             $field==='notes'?8000:($field==='custom_title'?500:1200));
       }
     }
     if($line['selected_transport_id']!==null &&
       get_post_type((int)$line['selected_transport_id'])!=='transportations')
        return self::problem('operations_selected_transport_not_found');
     if(!in_array($line['scope_change'],['keep_source','additional_service',
       'replace_included','not_required','request_upgrade'],true))
       return self::problem('operations_scope_change_invalid');
     if(!in_array($line['operation_state'],['draft','needs_review','fleet_confirmed','supplier_confirmed'],true))
       return self::problem('operations_state_invalid');
     if($line['currency']!==''&&!preg_match('/^[A-Z]{3}$/D',$line['currency']))
       return self::problem('operations_currency_invalid');
     if($line['service_date']!==''&&!preg_match('/^\d{4}-\d{2}-\d{2}$/D',$line['service_date']))
       return self::problem('operations_service_date_invalid');
     if($manual&&$line['custom_title']===''&&$line['source_post_id']===0)
       return self::problem('operations_custom_service_title_required');
     $ad=$line['adults'];$ch=$line['children'];$in=$line['infants'];
     $p=$source['party'];
     if($ad!==null||$ch!==null||$in!==null){
       if($ad===null||$ch===null||$in===null)return self::problem('operations_partial_party_split');
       if($ad>$p['adults']||$ch>$p['children']||$in>$p['infants']||
         ($ad+$ch+$in)>$p['total'])return self::problem('operations_party_exceeds_request');
     }
     if($line['operation_state']==='supplier_confirmed'){
       // A generic confirmation is not a backdoor around transport seats,
       // child restraints, actual fleet or capacity evidence.
       if($source['order_closed'])return self::problem('operations_closed_order_cannot_confirm');
       if($line['source_kind']==='transportations'||
          ($line['source_family']??'')==='transportation')
          return self::problem('operations_transport_requires_fleet_confirmation');
       if(trim((string)$line['supplier_reference'])===''||
          trim((string)$line['confirmation_reference'])==='')
          return self::problem('operations_supplier_confirmation_evidence_required');
       $line['operator_verified']=true;
     }
     if($line['scope_change']==='not_required' && trim((string)$line['notes'])==='')
       return self::problem('operations_exclusion_requires_reason');
     if($line['operation_state']==='fleet_confirmed'){
       if($source['order_closed'])return self::problem('operations_closed_order_cannot_confirm');
       if(empty($line['confirmation_reference'])||empty($line['fleet_reference'])||
          !$line['vehicles']||!$line['actual_seats'])
          return self::problem('operations_fleet_evidence_required');
       $assigned=$ad===null?$p['total']:$ad+$ch+$in;
       if($assigned>$line['vehicles']*$line['actual_seats'])
         return self::problem('operations_confirmed_fleet_capacity_inadequate');
       if(($ch===null?$p['children']+$p['infants']:$ch+$in)>0 &&
          ($line['child_seats_confirmed']===null||
            $line['child_seats_confirmed']<
             ($ch===null?$p['children']+$p['infants']:$ch+$in)))
          return self::problem('operations_child_seat_evidence_required');
       if(($ch===null?$p['children']+$p['infants']:$ch+$in)>0 &&
          trim((string)$line['child_restraint_reference'])==='')
          return self::problem('operations_child_restraint_reference_required');
       if($line['large_bags']!==null&&$line['large_bags']>0 &&
         (!$line['actual_large_bags_capacity'] ||
           $line['large_bags']>$line['vehicles']*$line['actual_large_bags_capacity']))
          return self::problem('operations_actual_luggage_inadequate');
       $line['operator_verified']=true;
     }elseif($line['operation_state']!=='supplier_confirmed')
         $line['operator_verified']=false;
     $line['commercial_price_authorized']=false;
     $line['supplier_stock_verified']=false; // native stock is a separate authority
     $out[]=$line;
   }
   // Native items cannot disappear by omission. Choose "not_required" instead.
   foreach($native as $key=>$b)if(!isset($keys[$key]))
       return self::problem('operations_native_service_row_must_be_preserved');
   return $out;
 }
 private static function readLineTemplate():array{
   return self::base('template','','',0,'');
 }
 public static function save(int $id,int $expected,string $fingerprint,array $lines):array|WP_Error{
   $source=self::source($id);
   if(is_wp_error($source))return $source;
   $order=wc_get_order($id);
   $current=$order->get_meta(self::META,true);
   if(!is_array($current))$current=[];
   if((int)($current['revision']??0)!==$expected)
      return self::problem('operations_revision_conflict_reload_order');
   if(!hash_equals($source['source_fingerprint'],$fingerprint))
      return self::problem('operations_original_data_changed_reload_order');
   $valid=self::validate($lines,$source);
   if(is_wp_error($valid))return $valid;
   $rev=$expected+1;
   $receipt=[
     'contract'=>self::CONTRACT,'revision'=>$rev,'source_fingerprint'=>$fingerprint,
     'lines'=>$valid,'editor_id'=>get_current_user_id(),'at_utc'=>gmdate('c'),
     'commercial_price_authorized'=>false,'payment_changed'=>false,
     'booking_confirmed'=>false
   ];
   $order->update_meta_data(self::META,$receipt);
   $history=$order->get_meta(self::HISTORY,true);
   if(!is_array($history))$history=[];
   $history[]=['revision'=>$rev,'actor'=>get_current_user_id(),
      'at_utc'=>$receipt['at_utc'],'source_fingerprint'=>$fingerprint,
      'lines_digest'=>hash('sha256',wp_json_encode($valid)),
      'confirmed_fleet_lines'=>count(array_filter($valid,
         static fn($v)=>$v['operation_state']==='fleet_confirmed')),
      'commercial_authority'=>false
   ];
   $order->update_meta_data(self::HISTORY,$history);
   $order->save();
   $order->add_order_note('MAD4B Operations planning revision '.$rev.
       ' saved by operator #'.get_current_user_id().
       '. This does not approve prices, booking, supplier stock or payment.',0,true);
   return ['saved'=>true,'revision'=>$rev,'lines'=>count($valid),
      'commercial_approved'=>false,'order_status_unchanged'=>true];
 }
 public static function search(string $term,int $page,string $type=''):array|WP_Error{
   if(!self::allowed())return self::problem('operations_access_denied');
   if(!class_exists('MAD4B_Booking_Operations_Source_Families_V1',false))
       return self::problem('operations_service_registry_unavailable');
   return MAD4B_Booking_Operations_Source_Families_V1::search($term,$page,$type);
 }
 public static function ajax():void{
   if(!self::allowed())wp_send_json_error(['code'=>'operations_permission_denied'],403);
   check_ajax_referer(self::ACTION,'nonce');
   $task=sanitize_key((string)($_POST['task']??''));
   $id=absint($_POST['order_id']??0);
   if($task==='search'){
     $q=sanitize_text_field(wp_unslash((string)($_POST['q']??'')));
     $type=sanitize_key((string)($_POST['type']??''));
     $r=self::search($q,absint($_POST['page']??1),$type);
   }elseif($task==='read')$r=self::read($id);
   elseif($task==='save'){
     $raw=wp_unslash((string)($_POST['payload']??''));
     if(strlen($raw)>1048576)wp_send_json_error(['code'=>'operations_payload_too_large'],413);
     $data=json_decode($raw,true);
     if(!is_array($data)||!is_array($data['lines']??null))
        wp_send_json_error(['code'=>'operations_payload_invalid'],422);
     $r=self::save($id,(int)($data['revision']??-1),
       (string)($data['source_fingerprint']??''),$data['lines']);
   }else $r=self::problem('operations_task_unknown');
   if(is_wp_error($r))wp_send_json_error(['code'=>$r->get_error_code()],422);
   wp_send_json_success($r);
 }
 public static function registerBox():void{
   if(!self::allowed())return;
   // Already embedded inside 279's original Woo order window. Only use a
   // standalone meta box on sites without the established financial editor.
   if(class_exists('MAD4B_Trip_Order_UI_V1',false)&&
      class_exists('MAD4B_Trip_Order_Amendments_V1',false))return;
   $hpos=class_exists('Automattic\\WooCommerce\\Utilities\\OrderUtil') &&
        \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
   add_meta_box('mad4b_booking_operations_order_v1',
       'MAD4B Operations — Included / Optional / Dispatch',
       [self::class,'render'],$hpos?'woocommerce_page_wc-orders':'shop_order',
       'normal','high');
 }
 public static function render($object):void{
   if(!self::allowed())return;
   $id=$object instanceof WC_Order?$object->get_id():
       ($object instanceof WP_Post?$object->ID:0);
   if(!$id)return;
   $o=wc_get_order($id);
   if(!$o || (int)$o->get_meta('_request_form_id')!==6){
       echo '<p>Original Fluent Form 6 linked Order required. This workspace never invents a traveler source.</p>';
       return;
   }
   $r=self::read($id);
   if(is_wp_error($r)){
     echo '<p><strong>Operations source verification pending:</strong> '.
          esc_html($r->get_error_code()).'</p>';
     return;
   }
   echo '<div class="mad4b-ops-root" data-order="'.esc_attr($id).
        '"><p>Loading the original order, itinerary, Tour Rate and request…</p></div>';
 }
 public static function enqueue():void{
   $screen=function_exists('get_current_screen')?get_current_screen():null;
   if(!$screen||!in_array($screen->id,['shop_order','woocommerce_page_wc-orders'],true)||
      !self::allowed())return;
   $url=content_url('mu-plugins/mad4b-booking-operations-order-v1.js');
   wp_enqueue_style('mad4b-booking-operations-order-v1',
      content_url('mu-plugins/mad4b-booking-operations-order-v1.css'),[],'1.1.0');
   wp_enqueue_script('mad4b-booking-operations-order-v1',$url,[],'1.3.0',true);
   wp_localize_script('mad4b-booking-operations-order-v1','MAD4BOperationsOrder',[
      'ajax'=>admin_url('admin-ajax.php'),'nonce'=>wp_create_nonce(self::ACTION),
   ]);
 }
 public static function boot():void{
   add_action('add_meta_boxes_shop_order',[self::class,'registerBox'],94);
   add_action('add_meta_boxes_woocommerce_page_wc-orders',[self::class,'registerBox'],94);
   add_action('admin_enqueue_scripts',[self::class,'enqueue']);
   add_action('wp_ajax_'.self::ACTION,[self::class,'ajax']);
   add_action('mad4b/booking_operations/render_on_order',[self::class,'render'],10,1);
 }
}
MAD4B_Booking_Operations_Order_V1::boot();
}
