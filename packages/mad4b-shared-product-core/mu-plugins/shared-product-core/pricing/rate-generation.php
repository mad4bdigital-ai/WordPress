<?php
/** Existing CPT rate generator: no new price store, no implicit publication. */
if (!defined('ABSPATH') || wp_get_environment_type() !== 'local' || !defined('SHARED_PRODUCT_LAB_ONLY') || !SHARED_PRODUCT_LAB_ONLY) return;

require_once __DIR__.'/accommodation-assignment.php';

final class Shared_Product_Rate_Generation {
 const SCHEMA='shared-rate-generation.v1';
 const META='_shared_generated_rate_key';

 public static function units($kind) {
  if($kind==='accommodation'){ $result=shared_product_unit_glossary('property-rates','prop_price_unit'); return is_wp_error($result)?[]:$result['values']; }
  $a=$kind==='tour'?['tour-rates','unit_type']:($kind==='transport'?['transport-rates','pricing_type']:null);
  if(!$a)return [];
  $result=shared_product_unit_glossary($a[0],$a[1]);
  return is_wp_error($result)?[]:$result['values'];
 }
 private static function money($value) {
  if(!is_scalar($value)||is_bool($value)||!preg_match('/^(?:0|[1-9][0-9]{0,7})(?:\.[0-9]{1,4})?$/D',(string)$value)||
     (float)$value<=0)return new WP_Error('rate_generator_amount_invalid');
  return number_format((float)$value,4,'.','');
 }
 private static function day($value) {
  if(!is_string($value)||!preg_match('/^20[0-9]{2}-[0-9]{2}-[0-9]{2}$/D',$value))return false;
  $d=DateTimeImmutable::createFromFormat('!Y-m-d',$value,new DateTimeZone('UTC'));
  return $d&&$d->format('Y-m-d')===$value;
 }
 public static function prepare($input) {
  if(!is_array($input))return new WP_Error('rate_generator_input_invalid');
  $allowed=['kind','parent_id','unit_id','amount','currency','price_unit','valid_from','valid_to',
   'single_factor','double_factor','triple_factor','package_term_id','program_term_id','season_term_id','seed_origin','seed_source_rate_id','rate_scope',
   'min_nights','max_nights','max_adults','max_children','inventory_policy',
   'supplier_sku','supplier_cost','pricing_rule_kind','pricing_rule_value',
   'accommodation_assignment_mode','accommodation_stage_source_rate_id'];
  if(array_diff(array_keys($input),$allowed))return new WP_Error('rate_generator_fields_unknown');
  $kind=$input['kind']??null;
  $types=['tour'=>'tour-rates','accommodation'=>'property-rates','transport'=>'transport-rates'];
  $parents=['tour'=>'tours-and-activities','accommodation'=>'properties','transport'=>'transportations'];
  if(!is_string($kind)||!isset($types[$kind]))return new WP_Error('rate_generator_kind_invalid');
  $id=filter_var($input['parent_id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
  if($id===false||$id===null||get_post_type($id)!==$parents[$kind]||get_post_status($id)!=='publish')
   return new WP_Error('rate_generator_parent_invalid');
  $hasPrice=isset($input['amount'])&&$input['amount']!=='';
  $hasCost=isset($input['supplier_cost'])&&$input['supplier_cost']!=='';
  $money=$hasPrice?self::money($input['amount']):null;
  if(is_wp_error($money))return $money;
  $currency=$input['currency']??null;
  if(!is_string($currency)||!preg_match('/^[A-Z]{3}$/D',$currency)||
      !in_array($currency,array_keys(get_woocommerce_currencies()),true))
   return new WP_Error('rate_generator_currency_invalid');
  $from=$input['valid_from']??null;$to=$input['valid_to']??null;
  if(!self::day($from)||!self::day($to)||$to<$from)return new WP_Error('rate_generator_dates_invalid');
  $supplierProposal=null;$supplierContext=null;
  if($hasCost){
   $supplierCost=self::money($input['supplier_cost']);if(is_wp_error($supplierCost))return $supplierCost;
   $sku=$input['supplier_sku']??'';
   if(!is_string($sku)||!preg_match('/^[A-Za-z0-9._:-]{1,96}$/D',$sku))
    return new WP_Error('rate_generator_supplier_sku_invalid');
   $ruleKind=$input['pricing_rule_kind']??null;
   if(!in_array($ruleKind,['markup_percent','target_margin_percent','fixed_profit'],true))
    return new WP_Error('rate_generator_pricing_rule_kind_invalid');
   $ruleValue=self::money($input['pricing_rule_value']??null);
   if(is_wp_error($ruleValue))return new WP_Error('rate_generator_pricing_rule_value_invalid');
   if(!function_exists('BookingExperience\supplier_price_proposal'))
    return new WP_Error('rate_generator_supplier_engine_unavailable');
   $domain=match($kind){'tour'=>'tour','accommodation'=>'accommodation','transport'=>'transportation'};
   $supplierProposal=BookingExperience\supplier_price_proposal([
    'supplier_sku'=>$sku,'product_type'=>$domain,'product_id'=>(int)$id,
    'currency'=>$currency,'travel_date'=>$from,'cost'=>$supplierCost
   ],[['id'=>'rate-generation-base','kind'=>$ruleKind,'value'=>(float)$ruleValue]]);
   if(is_wp_error($supplierProposal))return $supplierProposal;
   if(($supplierProposal['status']??'')!=='pending_approval'||
      ($supplierProposal['published']??true)!==false)
    return new WP_Error('rate_generator_supplier_proposal_not_governed');
   $proposed=self::money($supplierProposal['proposed_sell_price']??null);
   if(is_wp_error($proposed))return $proposed;
   if($money!==null&&abs((float)$money-(float)$proposed)>0.009)
    return new WP_Error('rate_generator_manual_supplier_price_conflict');
   $money=$proposed;
   $supplierContext=['supplier_sku'=>$sku,'supplier_cost'=>$supplierCost,
    'pricing_rule_kind'=>$ruleKind,'pricing_rule_value'=>$ruleValue,
    'source_quote_status'=>'pending_approval'];
  }elseif(isset($input['supplier_sku'])||isset($input['pricing_rule_kind'])||
         isset($input['pricing_rule_value']))
   return new WP_Error('rate_generator_supplier_fields_incomplete');
  if($money===null)return new WP_Error('rate_generator_amount_or_supplier_cost_required');
  $unit=$input['price_unit']??null;
  $supported=self::units($kind);
  if(!is_string($unit)||!isset($supported[$unit]))return new WP_Error('rate_generator_unit_invalid');
  $rateType=$types[$kind];
  $meta=[];$params=['kind'=>$kind,'parent_id'=>(int)$id,'price_unit'=>$unit,
    'amount'=>$money,'currency'=>$currency,'valid_from'=>$from,'valid_to'=>$to];
  if($kind==='tour'){
   $factors=[];
   foreach(['single','double','triple'] as $tier){
    $raw=$input[$tier.'_factor']??'1';
    if(!is_scalar($raw)||is_bool($raw)||!preg_match('/^(?:0|[1-9][0-9]{0,2})(?:\.[0-9]{1,4})?$/D',(string)$raw)
        ||(float)$raw<=0||(float)$raw>100)return new WP_Error('rate_generator_factor_invalid');
    $factors[$tier]=number_format((float)$raw,4,'.','');
   }
   $package=absint($input['package_term_id']??0);
   if($package && !term_exists($package,'package_levels'))return new WP_Error('rate_generator_package_term_invalid');
   $params['factors']=$factors;$params['package_term_id']=$package;
   // Optional relations from the Tour CPT Seed. Native rate discovery reads the
   // Program ID from postmeta and Season from the actual WordPress taxonomy.
   $program=absint($input['program_term_id']??0);
   $season=absint($input['season_term_id']??0);
   if($program&&!term_exists($program,'programs'))return new WP_Error('rate_generator_program_term_invalid');
   if($season&&!term_exists($season,'season'))return new WP_Error('rate_generator_season_term_invalid');
   if($program){$params['program_term_id']=$program;}
   if($season){$params['season_term_id']=$season;}
   // An explicit Seed from the Tour editor must remain auditable. Original
   // unrelated generator callers omit these keys and keep their fingerprints.
   if(array_key_exists('seed_origin',$input)){
    $origin=$input['seed_origin'];
    if(!in_array($origin,['tour_cpt','tour_rate'],true))
      return new WP_Error('rate_generator_seed_origin_invalid');
    $source=absint($input['seed_source_rate_id']??0);
    if($origin==='tour_rate'){
      if(!$source||get_post_type($source)!=='tour-rates'||
        (int)get_post_meta($source,'related_tour_id',true)!==(int)$id||
        !in_array(get_post_status($source),['draft','publish'],true))
        return new WP_Error('rate_generator_seed_rate_not_related');
      $snapshot=[];
      foreach(['single_price','double_price','triple_price','unit_type','base_currency',
        'related_package_term_id','related_program_term_id','tour_rate_start_date',
        'tour_rate_end_date'] as $key)$snapshot[$key]=get_post_meta($source,$key,true);
    }else{
      if($source)return new WP_Error('rate_generator_seed_origin_source_conflict');
      if((string)get_post_meta($id,'start_price',true)!==(string)$input['amount']||
        (string)get_post_meta($id,'tour_base_currency',true)!==$currency||
        (string)get_post_meta($id,'tour_price_unit',true)!=='per_person')
        return new WP_Error('rate_generator_seed_direct_snapshot_mismatch');
      $snapshot=['start_price'=>get_post_meta($id,'start_price',true),
        'tour_base_currency'=>$currency,'tour_price_unit'=>'per_person'];
    }
    $params['seed_origin']=$origin;
    if($source)$params['seed_source_rate_id']=$source;
    $params['seed_source_hash']=hash('sha256',wp_json_encode($snapshot));
    $seedLineage=[
      'origin'=>$origin,'source_tour_id'=>(int)$id,
      'source_rate_id'=>$source?:null,'source_hash'=>$params['seed_source_hash']];
   }
   $meta=['related_tour_id'=>(int)$id,'avilabile_status'=>'0','stop_sale'=>'1',
    'tour_rate_start_date'=>(string)strtotime($from.' 00:00:00 UTC'),
    'tour_rate_end_date'=>(string)strtotime($to.' 00:00:00 UTC'),
    'base_currency'=>$currency,'unit_type'=>$unit,'is_on_sale'=>'0'];
   foreach($factors as $tier=>$factor)$meta[$tier.'_price']=number_format((float)$money*(float)$factor,2,'.','');
   if($package)$meta['related_package_term_id']=$package;
   if($program)$meta['related_program_term_id']=$program;
   if($season)$meta['_shared_generation_season_term_id']=$season;
   if(isset($seedLineage))$meta['_shared_generated_seed_lineage']=$seedLineage;
   $assignmentMode=$input['accommodation_assignment_mode']??'auto';
   if(!in_array($assignmentMode,['manual','auto'],true))return new WP_Error('accommodation_assignment_mode_invalid');
   $source=filter_var($input['accommodation_stage_source_rate_id']??0,FILTER_VALIDATE_INT,['options'=>['min_range'=>0]]);
   if($source===false)return new WP_Error('accommodation_assignment_source_rate_invalid');
   if($assignmentMode==='manual'&&$source)return new WP_Error('accommodation_assignment_manual_source_conflict');
   if($assignmentMode==='auto'){
    $assignment=shared_product_accommodation_assignment((int)$id,(int)$source,(int)$package);if(is_wp_error($assignment))return $assignment;
    $params['accommodation_assignment']=$assignment;
    $meta['accommodation_assignment_mode']='auto';
    $meta['has_multiple_accommodation_stages']='0';
    $meta['_accommodation_assignment_status']=$assignment['status'];
    $meta['_accommodation_assignment_receipt']=$assignment;
    $meta['_accommodation_assignment_diagnostics']=['status'=>$assignment['status'],'city_groups'=>$assignment['groups'],'source_rate_id'=>(int)$source,'inventory_confirmed'=>false];
    if($assignment['status']==='ready'){
     $meta['has_properties']='1';$meta['related_properties_id']=$assignment['property_ids'];
     if($assignment['stages']){$meta['_booking_experience_stay_stages_v1']=$assignment['stages'];$meta['has_multiple_accommodation_stages']='1';}
    }
   }

  }elseif($kind==='accommodation'){
   $unitId=filter_var($input['unit_id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
   if($unitId===false||$unitId===null||get_post_type($unitId)!=='units'||get_post_status($unitId)!=='publish')
     return new WP_Error('rate_generator_accommodation_unit_invalid');
   if(!function_exists('BookingExperience\\booking_experience_stay_context'))
     return new WP_Error('rate_generator_stay_relation_provider_missing');
   $related=BookingExperience\booking_experience_stay_context($id,$unitId);
   if(is_wp_error($related))return $related;
   $params['unit_id']=(int)$unitId;
   $meta=['prop_related_property_id'=>(int)$id,'prop_related_unit_id'=>(int)$unitId,
    'prop_price_unit'=>$unit,'base_amount'=>$money,'currency'=>$currency,
    'valid_from'=>$from,'valid_to'=>$to,'stop_sale'=>'1'];
   // Optional complete-stay terms from the same small supplier source.
   // Missing fields remain missing; never infer a supplier commitment.
   $limits=[];
   foreach(['min_nights','max_nights','max_adults','max_children'] as $field){
    if(!array_key_exists($field,$input)||$input[$field]==='')continue;
    $min=$field==='max_children'?0:1;
    $v=filter_var($input[$field],FILTER_VALIDATE_INT,
      ['options'=>['min_range'=>$min,'max_range'=>365]]);
    if($v===false)return new WP_Error('rate_generator_'.$field.'_invalid');
    $limits[$field]=(int)$v;
   }
   if(isset($limits['min_nights'],$limits['max_nights'])&&
      $limits['max_nights']<$limits['min_nights'])
    return new WP_Error('rate_generator_stay_night_limits_reversed');
   $maxUnit=absint(get_post_meta($unitId,'unit_max_occupancy',true));
   if($maxUnit>0&&isset($limits['max_adults'])&&$limits['max_adults']>$maxUnit)
    return new WP_Error('rate_generator_adults_exceed_unit_capacity');
   if($maxUnit>0&&isset($limits['max_children'])&&$limits['max_children']>$maxUnit)
    return new WP_Error('rate_generator_children_exceed_unit_capacity');
   $fields=['min_nights'=>'prop_min_nights','max_nights'=>'prop_max_nights',
    'max_adults'=>'prop_max_adults','max_children'=>'prop_max_children'];
   foreach($limits as $key=>$value)$meta[$fields[$key]]=$value;
   if($limits)$params['stay_limits']=$limits;
   if(isset($input['inventory_policy'])&&$input['inventory_policy']!==''){
    $inventory=(string)$input['inventory_policy'];
    if(!in_array($inventory,['on_request','allotment'],true))
     return new WP_Error('rate_generator_inventory_policy_invalid');
    $meta['prop_inventory_policy']=$inventory;
    $params['inventory_policy']=$inventory;
   }
  }else{
   $scope=$input['rate_scope']??'both';
   if(!in_array($scope,['both','standalone','tour_component'],true))return new WP_Error('rate_generator_transport_scope_invalid');
   $params['rate_scope']=$scope;
   $meta=['source_post_id'=>(int)$id,'currency'=>$currency,'base_amount'=>$money,
    'pricing_type'=>$unit,'valid_from'=>$from,'valid_to'=>$to,
    'rate_scope'=>$scope,'is_on_sale'=>'false','stop_sale'=>'true'];
  }
  if($supplierContext!==null){
   $params['supplier_pricing']=$supplierContext;
   $meta['_shared_generation_supplier_proposal']=$supplierProposal;
  }
  $key=hash('sha256',wp_json_encode(['schema'=>self::SCHEMA,'parameters'=>$params]));
  $meta[self::META]=$key;
  $meta['_shared_generation_schema']=self::SCHEMA;
  $meta['_shared_generation_source']='parameterized_proposal';
  if($kind==='transport')$meta['rate_code']='generated-'.substr($key,0,18);
  $title=__('Draft generated rate','woocommerce').' · '.get_the_title($id).' · '.$from.' – '.$to;
  return ['schema'=>self::SCHEMA,'kind'=>$kind,'post_type'=>$rateType,'parent_id'=>(int)$id,
    'fingerprint'=>$key,'parameters'=>$params,'meta'=>$meta,'post_title'=>$title,
    'post_status'=>'draft','commercial_ready'=>false,'checkout_ready'=>false,
    'requires_review'=>true,'warnings'=>['Rates are drafts with stop_sale enabled','Availability, inventory and supplier approval are not inferred']];
 }
 /**
  * Shared, read-only Rate-to-Rate comparison for saved CPTs and unsaved CSV rows.
  * Unknown date bounds mean potential overlap, not permission to select a rate.
  */
 public static function overlap_pair($left,$right){
  if(!is_array($left)||!is_array($right)||empty($left['meta'])||empty($right['meta']))
   return new WP_Error('rate_overlap_pair_invalid');
  $type=$left['post_type']??'';
  $keys=['tour-rates'=>'related_tour_id','property-rates'=>'prop_related_unit_id',
    'transport-rates'=>'source_post_id'];
  if(!isset($keys[$type])||!isset($keys[$right['post_type']??'']))
   return new WP_Error('rate_overlap_type_invalid');
  $not=['overlap'=>false,'certainty'=>'distinct_scope','review_required'=>false,
    'source_selection_performed'=>false];
  if($type!==$right['post_type'])return $not;
  $lm=$left['meta'];$rm=$right['meta'];$relation=$keys[$type];
  $lparent=absint($lm[$relation]??0);$rparent=absint($rm[$relation]??0);
  if(!$lparent||!$rparent)return new WP_Error('rate_overlap_parent_invalid');
  if($lparent!==$rparent)return $not;
  if($type==='property-rates'&&
     absint($lm['prop_related_property_id']??0)!==absint($rm['prop_related_property_id']??0))
   return $not;
  if($type==='tour-rates'){
   $a=absint($lm['related_package_term_id']??0);$b=absint($rm['related_package_term_id']??0);
   if($a&&$b&&$a!==$b)return $not;
  }elseif($type==='property-rates'){
   foreach(['prop_price_unit','prop_rate_board'] as $field){
    $a=(string)($lm[$field]??'');$b=(string)($rm[$field]??'');
    if($a!==''&&$b!==''&&$a!==$b)return $not;
   }
  }else{
   $a=(string)($lm['rate_scope']??'both');$b=(string)($rm['rate_scope']??'');
   if($a!==''&&$b!==''&&$a!=='both'&&$b!=='both'&&$a!==$b)return $not;
   $a=(string)($lm['pricing_type']??'');$b=(string)($rm['pricing_type']??'');
   if($a!==''&&$b!==''&&$a!==$b)return $not;
  }
  $parse=static function($meta,$start)use($type){
   $key=$type==='tour-rates'?($start?'tour_rate_start_date':'tour_rate_end_date'):
                                  ($start?'valid_from':'valid_to');
   $v=$meta[$key]??null;
   if($v===null||$v==='')return '';
   if($type==='tour-rates'){
    if(!is_numeric($v)||(float)$v<=0)return null;
    return gmdate('Y-m-d',(int)$v);
   }
   if(!is_string($v)||!preg_match('/^20[0-9]{2}-[0-9]{2}-[0-9]{2}$/D',$v))return null;
   $d=DateTimeImmutable::createFromFormat('!Y-m-d',$v,new DateTimeZone('UTC'));
   return $d&&$d->format('Y-m-d')===$v?$v:null;
  };
  $fromA=$parse($lm,true);$toA=$parse($lm,false);
  $fromB=$parse($rm,true);$toB=$parse($rm,false);
  if(in_array(null,[$fromA,$toA,$fromB,$toB],true)||
     ($fromA!==''&&$toA!==''&&$fromA>$toA)||
     ($fromB!==''&&$toB!==''&&$fromB>$toB))
   return new WP_Error('rate_overlap_dates_invalid');
  if($fromA!==''&&$toB!==''&&$fromA>$toB)return $not;
  if($fromB!==''&&$toA!==''&&$fromB>$toA)return $not;
  $certainty=($fromA!==''&&$toA!==''&&$fromB!==''&&$toB!=='')
    ?'overlapping_dates':'potential_open_window';
  return ['overlap'=>true,'certainty'=>$certainty,'review_required'=>true,
    'source_selection_performed'=>false];
 }
 /**
  * Read-only Rate window conflict preflight, never a price selector or publishing authority.
  * Compare only equivalent product/Unit, rate basis and package/scope when known.
  */
 public static function overlap_review($candidate,$exclude_id=0) {
  if(!is_array($candidate)||empty($candidate['post_type'])||empty($candidate['meta']))
   return new WP_Error('rate_overlap_candidate_invalid');
  $type=$candidate['post_type'];$meta=$candidate['meta'];
  $keys=['tour-rates'=>'related_tour_id','property-rates'=>'prop_related_unit_id',
   'transport-rates'=>'source_post_id'];
  if(!isset($keys[$type]))return new WP_Error('rate_overlap_type_invalid');
  $link=$keys[$type];$parent=absint($meta[$link]??0);
  if(!$parent)return new WP_Error('rate_overlap_parent_invalid');
  $from=$type==='tour-rates'
   ?(isset($meta['tour_rate_start_date'])?gmdate('Y-m-d',(int)$meta['tour_rate_start_date']):'')
   :(string)($meta['valid_from']??'');
  $to=$type==='tour-rates'
   ?(isset($meta['tour_rate_end_date'])?gmdate('Y-m-d',(int)$meta['tour_rate_end_date']):'')
   :(string)($meta['valid_to']??'');
  if(!$from||!$to||$from>$to)return new WP_Error('rate_overlap_dates_invalid');
  $posts=get_posts(['post_type'=>$type,'post_status'=>['draft','publish','private','pending'],
   'numberposts'=>101,'fields'=>'ids','suppress_filters'=>true,
   'meta_query'=>[['key'=>$link,'value'=>(string)$parent,'compare'=>'=']]]);
  if(count($posts)>=101)return new WP_Error('rate_overlap_source_limit_reached');
  $items=[];
  foreach($posts as $id){
   $id=(int)$id;if($id===(int)$exclude_id)continue;
   $other=['post_type'=>$type,'meta'=>[]];
   foreach(['related_tour_id','related_package_term_id','tour_rate_start_date','tour_rate_end_date',
     'prop_related_property_id','prop_related_unit_id','prop_price_unit','prop_rate_board',
     'source_post_id','valid_from','valid_to','pricing_type','rate_scope'] as $field){
    $value=get_post_meta($id,$field,true);
    if($value!=='')$other['meta'][$field]=$value;
   }
   $comparison=self::overlap_pair($candidate,$other);
   if(is_wp_error($comparison))return $comparison;
   if(!$comparison['overlap'])continue;
   $s=$type==='tour-rates'?(!empty($other['meta']['tour_rate_start_date'])
     ?gmdate('Y-m-d',(int)$other['meta']['tour_rate_start_date']):''):(string)($other['meta']['valid_from']??'');
   $e=$type==='tour-rates'?(!empty($other['meta']['tour_rate_end_date'])
     ?gmdate('Y-m-d',(int)$other['meta']['tour_rate_end_date']):''):(string)($other['meta']['valid_to']??'');
   $items[]=['rate_id'=>$id,'post_status'=>get_post_status($id),
    'valid_from'=>$s?:null,'valid_to'=>$e?:null,
    'certainty'=>$comparison['certainty'],
    'requires_owner_review'=>true];
  }
  usort($items,static fn($a,$b)=>$a['rate_id']<=>$b['rate_id']);
  return ['candidate'=>$candidate['fingerprint']??null,'rate_type'=>$type,
    'scope_parent_id'=>$parent,'valid_from'=>$from,'valid_to'=>$to,
    'matches'=>$items,'count'=>count($items),'has_published_overlap'=>count(array_filter($items,
     static fn($x)=>$x['post_status']==='publish'))>0,
    'source_selection_performed'=>false,'publication_authorized'=>false];
 }
 /**
  * Operator-only Rate selection inspection. Reuse each domain's pricing/eligibility
  * providers; never grant publication, inventory or Checkout authority.
  */
 public static function selection_inspect($input){
  if(!current_user_can('manage_woocommerce'))return new WP_Error('rate_choice_permission_denied');
  if(wp_get_environment_type()!=='local'||!is_array($input))return new WP_Error('rate_choice_context_invalid');
  $allowed=['kind','parent_id','unit_id','service_date','checkout','rooms','adults','children',
    'audience','occupancy','package_term_id','rate_scope','rate_id'];
  if(array_diff(array_keys($input),$allowed))return new WP_Error('rate_choice_context_fields_unknown');
  $kind=$input['kind']??null;
  $map=['tour'=>['tour-rates','tours-and-activities','related_tour_id'],
    'accommodation'=>['property-rates','properties','prop_related_property_id'],
    'transport'=>['transport-rates','transportations','source_post_id']];
  if(!is_string($kind)||!isset($map[$kind]))return new WP_Error('rate_choice_domain_invalid');
  [$type,$parentType,$link]=$map[$kind];
  $parent=filter_var($input['parent_id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
  if(!$parent||get_post_type($parent)!==$parentType||get_post_status($parent)!=='publish')
   return new WP_Error('rate_choice_parent_invalid');
  $day=$input['service_date']??null;
  $dateValid=static function($date){
   if(!is_string($date)||!preg_match('/^20[0-9]{2}-[0-9]{2}-[0-9]{2}$/D',$date))return false;
   $d=DateTimeImmutable::createFromFormat('!Y-m-d',$date,new DateTimeZone('UTC'));
   return $d&&$d->format('Y-m-d')===$date;
  };
  if(!$dateValid($day))return new WP_Error('rate_choice_service_date_invalid');
  $rateId=isset($input['rate_id'])&&$input['rate_id']!==''
    ?filter_var($input['rate_id'],FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]):0;
  if(array_key_exists('rate_id',$input)&&$input['rate_id']!==''&&$rateId===false)
   return new WP_Error('rate_choice_rate_id_invalid');
  $packageId=absint($input['package_term_id']??0);
  if($kind==='tour'){
   $occupancy=$input['occupancy']??'double';
   if(!in_array($occupancy,['single','double','triple'],true))return new WP_Error('rate_choice_occupancy_invalid');
   $audience=$input['audience']??'general';
   if(!in_array($audience,['general','egyptians'],true))return new WP_Error('rate_choice_audience_invalid');
   if($packageId&&(!term_exists($packageId,'package_levels')))
    return new WP_Error('rate_choice_package_invalid');
  }elseif($kind==='accommodation'){
   $unit=absint($input['unit_id']??0);
   if(!$unit||get_post_type($unit)!=='units'||get_post_status($unit)!=='publish')
    return new WP_Error('rate_choice_unit_invalid');
   $checkout=$input['checkout']??null;
   if(!$dateValid($checkout)||$checkout<=$day)return new WP_Error('rate_choice_checkout_invalid');
   $rooms=(int)($input['rooms']??1);
   $adults=(int)($input['adults']??2);
   $children=(int)($input['children']??0);
   if($rooms<1||$rooms>50||$adults<1||$adults>50||$children<0||$children>50)
    return new WP_Error('rate_choice_guests_invalid');
  }else{
   $scope=$input['rate_scope']??'tour_component';
   if(!in_array($scope,['standalone','tour_component'],true))
    return new WP_Error('rate_choice_transport_scope_invalid');
   if(function_exists('service_channel_allowed')&&!service_channel_allowed('transportations',$scope))
    return new WP_Error('rate_choice_transport_service_channel_disabled');
  }
  $posts=get_posts(['post_type'=>$type,'post_status'=>['publish','draft','pending','private'],
    'posts_per_page'=>101,'fields'=>'ids','suppress_filters'=>true,
    'meta_query'=>[['key'=>$link,'value'=>(string)$parent,'compare'=>'=']]]);
  if(count($posts)>100)return new WP_Error('rate_choice_source_limit_reached');
  $eligible=[];$excluded=[];$found=false;
  foreach($posts as $id){
   $id=(int)$id;if($id===$rateId)$found=true;
   $error=null;$sourceStatus=get_post_status($id);
   if($sourceStatus!=='publish')$error='rate_not_published';
   elseif($kind==='tour'){
    $candidatePackage=absint(get_post_meta($id,'related_package_term_id',true));
    if($packageId&&$candidatePackage&&$packageId!==$candidatePackage)
     $error='rate_package_mismatch';
    elseif(!function_exists('shared_product_booking_v2_rate_covers_date')||
           !shared_product_booking_v2_rate_covers_date($id,$day))$error='rate_outside_service_dates';
    elseif(in_array(strtolower((string)get_post_meta($id,'stop_sale',true)),['1','yes','true'],true)||
       (string)get_post_meta($id,'avilabile_status',true)==='0')$error='rate_stop_sale_or_unavailable';
    elseif($audience==='egyptians'&&(string)get_post_meta($id,'has_egyptians_price',true)!=='1')
     $error='rate_audience_unavailable';
    else{
     $field=($audience==='egyptians'?'egyptians_':'').$occupancy.'_price';
     $v=get_post_meta($id,$field,true);
     if(!is_numeric($v)||(float)$v<=0)$error='rate_occupancy_price_missing';
     $currency=(string)get_post_meta($id,$audience==='egyptians'?'egyptians_currency':'base_currency',true);
     if(!$error&&(!preg_match('/^[A-Z]{3}$/D',$currency)||
       !array_key_exists($currency,get_woocommerce_currencies())))$error='rate_currency_invalid';
    }
   }elseif($kind==='accommodation'){
    if((int)get_post_meta($id,'prop_related_unit_id',true)!==$unit)$error='rate_unit_mismatch';
    elseif(!function_exists('abk_accommodation_shadow_quote'))$error='stay_quote_provider_missing';
    else{
     $args=['rate_id'=>$id,'checkin'=>$day,'checkout'=>$checkout,'rooms'=>$rooms,
       'adults'=>$adults,'children'=>$children,'availability_mode'=>'price_only'];
     $quote=abk_accommodation_shadow_quote($args);
     if(is_wp_error($quote))$error=$quote->get_error_code();
     elseif(($quote['checkout_ready']??null)!==false)$error='stay_quote_safety_contract_invalid';
    }
   }else{
    if(!function_exists('BookingExperience\transport_rate_eligible'))$error='transport_source_provider_missing';
    else{
     $src=BookingExperience\transport_rate_eligible($id,$day,$scope);
     if(is_wp_error($src))$error=$src->get_error_code();
    }
   }
   if($error)$excluded[]=['rate_id'=>$id,'reason'=>$error,'source_status'=>$sourceStatus];
   else $eligible[]=['rate_id'=>$id,'source_status'=>$sourceStatus,
     'kind'=>$kind,'date'=>$day,'availability_checked'=>false,'checkout_ready'=>false];
  }
  usort($eligible,static fn($a,$b)=>$a['rate_id']<=>$b['rate_id']);
  usort($excluded,static fn($a,$b)=>$a['rate_id']<=>$b['rate_id']);
  if($rateId&&(!$found||!in_array($rateId,array_column($eligible,'rate_id'),true)))
   return new WP_Error('rate_choice_explicit_rate_ineligible','Explicit Rate is not eligible for this context',
    ['rate_id'=>$rateId,'excluded'=>$excluded]);
  $selected=$rateId?:null;
  $status=$rateId?'explicit_rate_accepted_for_quote':
    (count($eligible)===0?'no_eligible_rate':
      (count($eligible)===1?'one_rate_candidate':'explicit_rate_selection_required'));
  return ['schema'=>'shared-rate-choice-inspection.v1','kind'=>$kind,'parent_id'=>(int)$parent,
   'service_date'=>$day,'status'=>$status,'selected_rate_id'=>$selected,
   'eligible'=>$eligible,'excluded'=>$excluded,'candidate_count'=>count($eligible),
   'operator_review_required'=>count($eligible)!==1||!$selected,
   'native_quote_owner'=>['tour'=>'shared_product_booking_v2_preview',
     'accommodation'=>'abk_accommodation_shadow_quote',
     'transport'=>'BookingExperience\transport_rate_preview'][$kind],
   'rate_to_rate_auto_selection'=>false,
   'availability_checked'=>false,'finance_approved'=>false,
   'publication_authorized'=>false,'checkout_ready'=>false];
 }
 /** Recheck explicit Rate against native owner immediately before a price-only preview. */
 public static function quote_for_explicit_rate($input){
  if(!current_user_can('manage_woocommerce'))return new WP_Error('rate_quote_permission_denied');
  if(wp_get_environment_type()!=='local'||!is_array($input))return new WP_Error('rate_quote_context_invalid');
  $allowed=['kind','parent_id','rate_id','service_date','checkout','unit_id','rooms',
   'adults','children','persons','audience','occupancy','package_term_id','rate_scope'];
  if(array_diff(array_keys($input),$allowed))return new WP_Error('rate_quote_unexpected_fields');
  $rate=filter_var($input['rate_id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
  if($rate===false)return new WP_Error('rate_quote_explicit_rate_required');
  $persons=filter_var($input['persons']??2,FILTER_VALIDATE_INT,['options'=>['min_range'=>1,'max_range'=>99]]);
  if($persons===false)return new WP_Error('rate_quote_persons_invalid');
  $inspectInput=$input;unset($inspectInput['persons']);
  $check=self::selection_inspect($inspectInput);
  if(is_wp_error($check))return $check;
  if(($check['status']??'')!=='explicit_rate_accepted_for_quote'||
    (int)($check['selected_rate_id']??0)!==$rate)return new WP_Error('rate_quote_rate_not_selected');
  $kind=$check['kind'];$parent=$check['parent_id'];$day=$check['service_date'];
  if($kind==='tour'){
   if(!function_exists('shared_product_booking_v2_preview'))return new WP_Error('rate_quote_tour_provider_missing');
   $selection=[];
   if(!empty($input['package_term_id']))$selection['package_term_id']=absint($input['package_term_id']);
   $native=shared_product_booking_v2_preview([
    'schema_version'=>2,'tour_id'=>$parent,'source'=>'rate','rate_id'=>$rate,
    'travel_date'=>$day,'audience'=>$input['audience']??'general',
    'occupancy'=>$input['occupancy']??'double','quantities'=>['persons'=>$persons],
    'extras'=>[],'selections'=>$selection,'booking_intent'=>'quote']);
   $total=is_array($native)?($native['total']??null):null;
   $currency=is_array($native)?($native['currency']??null):null;
   $availability='not_checked_price_only';
  }elseif($kind==='accommodation'){
   if(!function_exists('abk_accommodation_shadow_quote'))return new WP_Error('rate_quote_stay_provider_missing');
   $native=abk_accommodation_shadow_quote([
    'rate_id'=>$rate,'checkin'=>$day,'checkout'=>$input['checkout'],
    'rooms'=>(int)($input['rooms']??1),'adults'=>(int)($input['adults']??2),
    'children'=>(int)($input['children']??0),'availability_mode'=>'price_only']);
   $total=is_array($native)?($native['total']??null):null;
   $currency=is_array($native)?($native['currency']??null):null;
   $availability=is_array($native)?($native['inventory_status']??'not_checked_price_only'):'not_checked_price_only';
  }else{
   if(!function_exists('BookingExperience\\transport_rate_preview'))return new WP_Error('rate_quote_transport_provider_missing');
   $native=BookingExperience\transport_rate_preview($parent,['persons'=>$persons],$day,
     $rate,$input['rate_scope']??'tour_component');
   $total=is_array($native)?($native['preview_price']??null):null;
   $currency=is_array($native)?($native['currency']??null):null;
   $availability=is_array($native)?($native['availability_status']??'not_checked_price_only'):'not_checked_price_only';
  }
  if(is_wp_error($native))return $native;
  if(!is_array($native)||($native['checkout_ready']??null)!==false||
     !is_numeric($total)||!is_finite((float)$total)||(float)$total<=0||
     !is_string($currency)||!preg_match('/^[A-Z]{3}$/D',$currency))
   return new WP_Error('rate_quote_native_contract_unverified');
  $priceApproval='domain_price_approval_not_verified';
  $priceApprovalRevision=0;
  if($kind==='transport'){
   if(!function_exists('BookingExperience\\transport_rate_approval_verified')||
      !BookingExperience\transport_rate_approval_verified($rate))
    return new WP_Error('rate_quote_transport_price_approval_stale');
   $priceApprovalRevision=(int)($native['approval_revision']??0);
   if($priceApprovalRevision<1)return new WP_Error('rate_quote_transport_price_approval_receipt_missing');
   $priceApproval='transport_price_source_approved';
  }
  // Receiving a booking request and confirming supplier inventory are separate.
  // Use the existing Tour request owner; other domains need their own intake adapter.
  $requestPreflight='domain_booking_request_intake_not_connected';
  $requestWithoutStock=null;
  $requestModeVerified=null;
  if($kind==='tour'&&class_exists('Travel_Booking_Traveller_Management')){
   $request=Travel_Booking_Traveller_Management::assess_booking_request($parent,$persons,'group');
   if(!is_wp_error($request)){
    $requestPreflight=(string)($request['request_status']??'unverified');
    $requestWithoutStock=($request['request_allowed_without_stock']??null)===true;
    $requestModeVerified=($request['booking_mode_verified']??null)===true;
   }else $requestPreflight='tour_request_preflight_'.$request->get_error_code();
  }
  $provider=(string)($check['native_quote_owner']??'');
  $sourceFingerprint=hash('sha256',wp_json_encode([
    'post_id'=>$rate,'modified_gmt'=>get_post_field('post_modified_gmt',$rate),
    'rate_meta'=>get_post_meta($rate),'context'=>$input,'quote_status'=>$native['status']??null,
    'quote_total'=>$total,'currency'=>$currency]));
  return ['contract'=>'shared-rate-choice-quote-handoff.v1','kind'=>$kind,
    'parent_id'=>$parent,'rate_id'=>$rate,'travel_date'=>$day,
    'amount'=>round((float)$total,2),'currency'=>$currency,
    'native_provider'=>$provider,'native_status'=>(string)($native['status']??'unknown'),
    'inventory_status'=>$availability,
    'rate_source_sha256'=>$sourceFingerprint,
    'price_only'=>true,'native_price_approval_state'=>$priceApproval,
    'native_price_approval_revision'=>$priceApprovalRevision,
    'supplier_approval_verified'=>false,
    'finance_approval_verified'=>false,'inventory_reserved'=>false,
    // Inventory is NOT a prerequisite to submitting a booking request.
    // Request creation still depends on the owner intake/financial contracts.
    'inventory_precheck_required_for_request'=>false,
    'native_request_without_stock_supported'=>$requestWithoutStock,
    'native_booking_mode_verified'=>$requestModeVerified,
    'request_intake_preflight'=>$requestPreflight,
    'booking_request_created'=>false,
    'supplier_confirmation_received'=>false,
    'booking_confirmed'=>false,'publication_authorized'=>false,
    'checkout_ready'=>false,'mutation_performed'=>false];
 }
 /** Existing source readback, never an authority to publish or take payment. */
 public static function readiness($id){
  $id=absint($id);$post=get_post($id);
  $types=['tour-rates'=>'tour','property-rates'=>'accommodation','transport-rates'=>'transport'];
  if(!$post||!isset($types[$post->post_type]))return new WP_Error('rate_generator_readiness_source_invalid');
  $kind=$types[$post->post_type];$blockers=[];$warnings=[];
  if($post->post_status!=='publish')$blockers[]='rate_not_published';
  $stopped=strtolower(trim((string)get_post_meta($id,'stop_sale',true)));
  if(in_array($stopped,['1','true','yes','on'],true))$blockers[]='rate_stop_sale';
  if($kind==='tour'){
   if((string)get_post_meta($id,'accommodation_assignment_mode',true)==='auto'&&
      (string)get_post_meta($id,'_accommodation_assignment_status',true)!=='ready')
    $blockers[]='accommodation_assignment_'.((string)get_post_meta($id,'_accommodation_assignment_status',true)?:'unverified');
   if((string)get_post_meta($id,'avilabile_status',true)!=='1')
    $blockers[]='tour_rate_not_available';
   foreach(['single_price','double_price','triple_price'] as $field)
    if(!is_numeric(get_post_meta($id,$field,true)))$warnings[]=$field.'_missing';
   if($post->post_status==='publish'&&function_exists('pricing_rate_presentation')){
    $pricing=pricing_rate_presentation($id);
    if(($pricing['status']??'')!=='available')$blockers[]='tour_price_projection_unavailable';
   }
  }elseif($kind==='accommodation'){
   $meta=['prop_min_nights','prop_max_nights','prop_max_adults','prop_max_children'];
   foreach($meta as $field)
    if(get_post_meta($id,$field,true)==='')$blockers[]=$field.'_required';
   $inventory=(string)get_post_meta($id,'prop_inventory_policy',true);
   if(!in_array($inventory,['on_request','allotment'],true))
    $blockers[]='accommodation_inventory_policy_required';
   if(function_exists('abk_meta')){
    $unit=(int)abk_meta($id,'related_unit');
    $property=(int)abk_meta($id,'related_property');
    if($unit<1||$property<1)$blockers[]='accommodation_parent_relation_missing';
   }
   $warnings[]='accommodation_board_and_supplier_policy_review';
  }else{
   if(function_exists('BookingExperience\transport_rate_approval_verified')&&
      !BookingExperience\transport_rate_approval_verified($id))
    $blockers[]='transport_price_approval_missing_or_stale';
   $warnings[]='transport_schedule_and_supplier_capacity_review';
  }
  if(is_array(get_post_meta($id,'_shared_generation_supplier_proposal',true)))
   $warnings[]='supplier_cost_sell_price_proposal_pending_finance_review';
  $overlap=null;
  if((string)get_post_meta($id,self::META,true)!==''){
   $candidate=['post_type'=>$post->post_type,'fingerprint'=>get_post_meta($id,self::META,true),
    'meta'=>[]];
   foreach(['related_tour_id','related_package_term_id','tour_rate_start_date','tour_rate_end_date',
     'prop_related_property_id','prop_related_unit_id','prop_price_unit','prop_rate_board',
     'source_post_id','valid_from','valid_to','pricing_type','rate_scope'] as $field){
    $value=get_post_meta($id,$field,true);
    if($value!=='')$candidate['meta'][$field]=$value;
   }
   $overlap=self::overlap_review($candidate,$id);
   if(is_wp_error($overlap))$warnings[]='overlap_inspection_failed_'.$overlap->get_error_code();
   elseif($overlap['count']>0)$warnings[]='potential_rate_window_overlap_review_required';
  }
  $warnings[]='supplier_cost_and_margin_policy_not_verified';
  $warnings[]='checkout_inventory_and_hpos_acceptance_required';
  return ['post_id'=>$id,'kind'=>$kind,'post_type'=>$post->post_type,
   'commercial_ready'=>false,'checkout_ready'=>false,
   'blocking_conditions'=>array_values(array_unique($blockers)),
   'review_notes'=>array_values(array_unique($warnings)),
   'overlap_review'=>$overlap,
   'status'=>empty($blockers)?'source_requires_commercial_approval':'incomplete_draft_or_rate'];
 }
 public static function create($input) {
  if(!current_user_can('manage_woocommerce'))return new WP_Error('rate_generator_permission_denied');
  if(wp_get_environment_type()!=='local')return new WP_Error('rate_generator_nonlab_denied');
  $plan=self::prepare($input);if(is_wp_error($plan))return $plan;
  // Unique wp_options key is an atomic mutex across PHP processes.
  // Locks never authorize publication; stale locks fail closed for operator review.
  $lockKey='shared_rate_generation_lock_'.$plan['fingerprint'];
  $token=wp_generate_uuid4();
  if(!add_option($lockKey,['token'=>$token,'created_at'=>time()],'',false)){
   $held=get_option($lockKey);
   if(is_array($held)&&isset($held['created_at'])&&
      time()-(int)$held['created_at']>120)return new WP_Error('rate_generator_source_lock_stale');
   return new WP_Error('rate_generator_source_busy');
  }
  try {
  // Same input, same Rate; do not replicate CPT records on retries.
  $existing=get_posts(['post_type'=>$plan['post_type'],'post_status'=>'any','numberposts'=>2,
   'suppress_filters'=>true,'fields'=>'ids','meta_key'=>self::META,'meta_value'=>$plan['fingerprint']]);
  if(count($existing)>1)return new WP_Error('rate_generator_duplicate_fingerprint');
  if($existing){
   $found=(int)$existing[0];
   if(get_post_status($found)!=='draft')return new WP_Error('rate_generator_existing_rate_changed');
   foreach($plan['meta'] as $k=>$v){
    $current=get_post_meta($found,$k,true);
    if(is_array($v)||is_array($current)){
     if(wp_json_encode($current)!==wp_json_encode($v))return new WP_Error('rate_generator_existing_rate_modified');
    }elseif((string)$current!==(string)$v)return new WP_Error('rate_generator_existing_rate_modified');
   }
   if(!empty($plan['parameters']['season_term_id'])&&
      !has_term((int)$plan['parameters']['season_term_id'],'season',$found))
      return new WP_Error('rate_generator_existing_season_modified');
   return ['status'=>'reused','post_id'=>$found,'plan'=>$plan];
  }
  $created=wp_insert_post(['post_type'=>$plan['post_type'],'post_title'=>$plan['post_title'],
   'post_status'=>'draft','post_author'=>get_current_user_id(),'meta_input'=>$plan['meta']],true);
  if(is_wp_error($created))return $created;
  if($plan['kind']==='tour'&&!empty($plan['parameters']['season_term_id'])){
   $assigned=wp_set_object_terms($created,[(int)$plan['parameters']['season_term_id']],'season',false);
   if(is_wp_error($assigned)||!has_term((int)$plan['parameters']['season_term_id'],'season',$created)){
    wp_delete_post($created,true);
    return new WP_Error('rate_generator_season_save_failed');
   }
  }
  if(get_post_type($created)!==$plan['post_type']||get_post_status($created)!=='draft'||
     get_post_meta($created,self::META,true)!==$plan['fingerprint']){
   wp_delete_post($created,true);
   return new WP_Error('rate_generator_storage_readback_failed');
  }
  return ['status'=>'created','post_id'=>(int)$created,'plan'=>$plan];
  } finally {
   $held=get_option($lockKey);
   if(is_array($held)&&($held['token']??null)===$token)delete_option($lockKey);
  }
 }
}
