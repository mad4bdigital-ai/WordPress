<?php
/**
 * Plugin Name: MAD4B Package Draft Web-Offer Evidence V1
 * Version: 1.0.0
 * Description: Source-backed planning offers in the existing Pricing Lab. Never promotes web offers to supplier costs or sale prices.
 */
defined('ABSPATH')||exit;
if(!class_exists('MAD4B_Package_Indicative_Offer_V1',false)){
final class MAD4B_Package_Indicative_Offer_V1{
  const WEB='_mad4b_resort_public_offer_reference_v1';
  const CONTRACT='mad4b.resort-public-web-offer.planning-reference.v1';
  const STATUS='pending_reservations_cost_review';
  const CODE='mad4b.package-indicative-offer-draft.v1';
  private static function ids(int $tour):array{
    $tier=class_exists('MAD4B_Company_Package_Levels_V1',false)
      ?MAD4B_Company_Package_Levels_V1::resolve($tour):[];
    return array_values(array_map('intval',(array)($tier['effective_tier_ids']??[])));
  }
  public static function pool(int $tour,int $tier,array $stage):array{
    if(get_post_type($tour)!=='tours-and-activities'||
       (string)($stage['lodging_kind']??'')!=='resort'||
       (string)($stage['destination_key']??'')!=='sharm')return [];
    if(!in_array($tier,self::ids($tour),true))return [];
    $q=get_posts([
      'post_type'=>'properties','post_status'=>'publish','fields'=>'ids',
      'posts_per_page'=>100,'suppress_filters'=>true,
      'tax_query'=>['relation'=>'AND',[
        'taxonomy'=>'destinations','field'=>'slug',
        'terms'=>['sharm-el-sheikh'],'operator'=>'IN',
        'include_children'=>false
      ],[
        'taxonomy'=>'property-types','field'=>'slug',
        'terms'=>['resort'],'operator'=>'IN',
        'include_children'=>false
      ]]
    ]);
    $out=[];
    foreach($q as $id){
      $id=(int)$id;
      $en=(int)apply_filters('wpml_object_id',$id,'properties',false,'en');
      if($en && $en!==$id)continue;
      $m=get_post_meta($id,self::WEB,true);
      if(!is_array($m)||($m['contract']??'')!==self::CONTRACT||
         ($m['property_id']??0)!==$id||
         ($m['tour_id']??0)!==$tour||
         ($m['destination']??'')!=='sharm-el-sheikh'||
         ($m['accepted_for_commercial_rate']??true)!==false||
         ($m['accepted_for_checkout']??true)!==false||
         ($m['accepted_for_cost_receipt']??true)!==false)continue;
      $tiers=wp_get_object_terms($id,'package_levels',['fields'=>'ids']);
      $tiers=is_wp_error($tiers)?[]:array_map('intval',$tiers);
      $assigned=in_array($tier,$tiers,true);
      $amount=(float)($m['amount_usd']??0);
      if(!is_finite($amount)||$amount<=0)continue;
      $nights=max(1,min(30,(int)($stage['nights']??1)));
      $meal=(string)($m['meal_plan_evidence']??'unknown');
      $ai=in_array($meal,['all_inclusive_operator'],true);
      $out[]=[
         'property_id'=>$id,'title'=>wp_strip_all_tags(get_the_title($id)),
         'web_offer_usd_per_night'=>$amount,
         'three_night_illustration_usd'=>round($amount*$nights,2),
         'stage_night_count'=>$nights,
         'currency'=>'USD',
         'web_offer_date'=>(string)($m['quote_stay_date']??''),
         'tax_basis'=>(string)($m['taxes']??''),
         'room_basis'=>(string)($m['guest_room_basis']??''),
         'source_freshness'=>(string)($m['observed_rate_class']??''),
         'meal_evidence'=>$meal,
         'ai_confirmed_for_preview'=>$ai,
         'target_tier_explicitly_assigned'=>$assigned,
         'planning_eligible_by_operator_tier'=>$assigned,
         'status'=>$assigned?'editorial_candidate_reservations_review_pending':
                       'operator_tier_assignment_required',
         'source_url'=>esc_url_raw((string)($m['source_url']??'')),
         'review_sheet_url'=>esc_url_raw((string)($m['spreadsheet']??'')),
         'review_sheet_row'=>(int)($m['spreadsheet_row']??0),
         'supplier_cost_usd'=>null,
         'unit_inventory_verified'=>false,
         'same_dates_and_occupancy_verified'=>false,
         'web_offer_is_cost'=>false,
         'validated_for_full_package_price'=>false,
         'commercial_rate_authorized'=>false,
         'checkout_authorized'=>false,
      ];
    }
    usort($out,static function($a,$b){
      if($a['target_tier_explicitly_assigned']!==$b['target_tier_explicitly_assigned'])
         return $a['target_tier_explicitly_assigned']?-1:1;
      return $a['web_offer_usd_per_night']<=>$b['web_offer_usd_per_night'];
    });
    return $out;
  }
  public static function inspect(int $tour,int $tier):array{
    $out=[
      'contract'=>self::CODE,'tour_id'=>$tour,'tier_id'=>$tier,
      'status'=>'draft_estimate_requires_reservations_review',
      'coverage_status'=>'incomplete_sources_no_sale_price',
      'quote_can_be_prepared_for_staff'=>true,
      'customer_price_confirmed'=>false,
      'indicative_complete_package_total'=>null,
      'starting_from_amount'=>null,'supplier_cost_total'=>null,
      'sale_price_total'=>null,'checkout_authorized'=>false,
      'booking_and_deposit_authorized'=>false,
      'read_only'=>true,'live_inventory_verified'=>false,
      'all_components_same_date_and_unit'=>false,
      'missing_components'=>[],
      'market_offer_stages'=>[],
      'stage_count'=>0,
      'reservations_review_status'=>self::STATUS,
      'reservations_review_sheet_url'=>'https://docs.google.com/spreadsheets/d/1PxrMcF2CuZrRGZ6R-2ZEeuqHXoqL6dT150XkhZk04-c/edit#gid=20261122',
      'note'=>'Public market offers, not supplier cost or confirmed sale prices; never sum unmatched dates, units, nationality or guest counts.'
    ];
    if($tour<1||get_post_type($tour)!=='tours-and-activities'||
       !in_array($tier,self::ids($tour),true)||
       !current_user_can('edit_post',$tour)){
       $out['status']='unavailable_for_user_or_tier';return $out;
    }
    $plan=get_post_meta($tour,'_mad4b_package_accommodation_source_plan_v1',true);
    if(!is_array($plan)||!is_array($plan['stages']??null)){
       $out['status']='stage_plan_unavailable';return $out;
    }
    $missing=[];
    foreach(array_slice($plan['stages'],0,40) as $st){
       if(!is_array($st))continue;
       $kind=(string)($st['lodging_kind']??'');
       $key=(string)($st['stage_key']??'');
       if(!in_array($kind,['hotel','resort','sleeper_train','cruise'],true))
          continue;
       $count=max(1,(int)($st['nights']??1));
       $out['stage_count']++;
       $row=['stage_key'=>$key,'kind'=>$kind,'destination'=>(string)($st['destination_key']??''),
           'nights'=>$count,'status'=>'awaiting_approved_supplier_rate',
           'web_offer_examples'=>[],'full_stage_quoted_amount'=>null,
           'cost_confirmed'=>false,'guest_count_verified'=>false,
           'stage_allotment_verified'=>false];
       if($kind==='resort' && (string)($st['destination_key']??'')==='sharm'){
          $candidates=self::pool($tour,$tier,$st);
          $row['web_offer_examples']=$candidates;
          $row['status']=$candidates?'market_offer_examples_only_not_comparable_or_confirmed':
                                'no_resort_market_offer_record';
          $row['operator_tier_candidate_count']=count(array_filter($candidates,
             static fn($p)=>$p['target_tier_explicitly_assigned']===true));
          $row['resort_public_market_offer_count']=count($candidates);
          $missing[]=['stage_key'=>$key,'reason'=>'no_same_date_room_guest_meal_tax_or_approved_supplier_net_rate',
             'reservations_owner'=>true,'tier_assignment_review_required'=>$row['operator_tier_candidate_count']===0];
       }elseif($kind==='sleeper_train'){
          $r=class_exists('MAD4B_Package_Stage_Sourcing_V1',false)
              ?MAD4B_Package_Stage_Sourcing_V1::inspect($tour,$st,$tier):[];
          $rail=(array)($r['operational_route_proposal']??[]);
          $train=(int)($rail['train_id']??0);
          $tariff=$train?get_post_meta($train,
              '_mad4b_abela_public_tariff_evidence_v1',true):[];
          $row['proposed_train_number']=$rail['train_number']??null;
          $row['public_train_tariff_reference_available']=is_array($tariff)&&
              ($tariff['contract']??'')==='mad4b.abela.public-tariff-evidence.v1';
          $row['public_reference_year']=is_array($tariff)?($tariff['applicable_year']??null):null;
          $row['status']='date_cabin_nationality_supplier_quote_and_train_inventory_missing';
          $missing[]=['stage_key'=>$key,'reason'=>'rail_public_ticket_prices_do_not_replace_cabin_quote_or_contract',
              'reservations_owner'=>true];
       }elseif($kind==='hotel'){
          $missing[]=['stage_key'=>$key,'reason'=>'hotel_room_rate_and_occupancy_need_verified_quote','reservations_owner'=>true];
       }else{
          $missing[]=['stage_key'=>$key,'reason'=>'cruise_sailing_unit_allotment_and_cost_not_verified','reservations_owner'=>true];
       }
       $out['market_offer_stages'][]=$row;
    }
    $out['missing_components']=$missing;
    $out['required_non_overnight_services_not_fully_priced']=true;
    $out['stage_total_missing']=count($missing);
    return $out;
  }
  public static function addToLab($report,int $tour,int $tier){
    if(!is_array($report))return $report;
    $report['indicative_offer_draft']=self::inspect($tour,$tier);
    return $report;
  }
}
add_filter('mad4b/package_lab/inspected_report',
          [MAD4B_Package_Indicative_Offer_V1::class,'addToLab'],40,3);
}

