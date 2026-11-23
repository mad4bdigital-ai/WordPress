<?php
/**
 * Plugin Name: MAD4B Package Hotel Stage Cost Preview
 * Description: Native Pricing Lab stage-key pricing context; read-only, no duplicate rate store or Rate writer.
 * Version: 1.0.0
 */
defined('ABSPATH') || exit;
if(!class_exists('MAD4B_Package_Hotel_Stage_Cost_Preview_V1',false)){
final class MAD4B_Package_Hotel_Stage_Cost_Preview_V1 {
    public const CONTRACT='mad4b.package-hotel-stage-cost-preview.v1';
    private static function date($value):?DateTimeImmutable {
        if(!is_string($value)||!preg_match('/^20\d{2}-\d{2}-\d{2}$/D',$value))return null;
        $d=DateTimeImmutable::createFromFormat('!Y-m-d',$value,new DateTimeZone('UTC'));
        return $d&&$d->format('Y-m-d')===$value?$d:null;
    }
    private static function number($raw,int $min,int $max):?int {
        if(!is_scalar($raw)||is_bool($raw))return null;
        $v=filter_var($raw,FILTER_VALIDATE_INT,
            ['options'=>['min_range'=>$min,'max_range'=>$max]]);
        return $v===false?null:(int)$v;
    }
    private static function context():array {
        // The original native Pricing Lab has already validated user, nonce,
        // source Tour and tier. Never trust these public inputs as an approval.
        $raw=static fn($k)=>isset($_POST[$k])&&is_scalar($_POST[$k])
            ?wp_unslash($_POST[$k]):null;
        $start=self::date($raw('travel_date'));
        $currency=strtoupper((string)($raw('currency')?:'USD'));
        return ['start'=>$start,
            'rooms'=>self::number($raw('rooms')??'1',1,30),
            'adults'=>self::number($raw('adults')??'2',1,60),
            'children'=>self::number($raw('children')??'0',0,30),
            'infants'=>self::number($raw('infants')??'0',0,20),
            'currency'=>in_array($currency,['EGP','USD','EUR','GBP'],true)?$currency:''];
    }
    /** Basis is never inferred from a source-sheet's Double/Triple label. */
    public static function math(string $basis,float $amount,int $nights,int $rooms,int $adults,
        int $children=0,int $infants=0):array {
        if(!is_finite($amount)||$amount<=0||$nights<1||$nights>40||
            $rooms<1||$rooms>30||$adults<1||$adults>60||$children<0||$infants<0)
            return ['amount'=>null,'reason'=>'invalid_cost_dimensions'];
        if($children>0||$infants>0)return ['amount'=>null,'reason'=>'children_infant_cost_policy_required'];
        $factor=match($basis){
            'per_room_night'=>$rooms*$nights,
            'per_person_night'=>$adults*$nights,
            default=>null,
        };
        if($factor===null)return ['amount'=>null,'reason'=>'source_cost_basis_requires_review'];
        return ['amount'=>round($amount*$factor,2),'factor'=>$factor,
            'reason'=>'preliminary_cost_not_supplier_approved'];
    }
    private static function rateWindow(int $rate):?array {
        if(class_exists('MAD4B_Stay_Layered_Pricing_V1',false)){
            $window=MAD4B_Stay_Layered_Pricing_V1::effectiveRateWindow($rate);
            if(is_array($window)&&!empty($window['valid'])&&
                self::date($window['start']??null)&&self::date($window['end']??null))
                return [$window['start'],$window['end']];
        }
        $start=get_post_meta($rate,'valid_from',true);
        $end=get_post_meta($rate,'valid_to',true);
        return self::date($start)&&self::date($end)&&$start<=$end?[$start,$end]:null;
    }
    private static function sourceRate(int $property,int $unit,string $start,string $last,
        string $currency,string $occupancy='',string $meal=''):array {
        $rates=get_posts(['post_type'=>'property-rates',
            'post_status'=>['publish','draft','pending'],
            'fields'=>'ids','posts_per_page'=>201,'no_found_rows'=>true,
            'suppress_filters'=>true,
            'meta_key'=>'prop_related_property_id','meta_value'=>(string)$property]);
        if(count($rates)>200)return ['status'=>'rate_search_truncated','rate_id'=>null];
        $matches=['unit'=>[],'property'=>[]];
        foreach($rates as $raw){
            $rate=(int)$raw;
            if(get_post_status($rate)!=='publish')continue;
            if(get_post_meta($rate,'_booking_experience_qa_pricing_fixture',true)||
                get_post_meta($rate,'_mad4b_is_test_data',true)||
                preg_match('/\bQA\s+ONLY\b/i',(string)get_the_title($rate)))continue;
            if((string)get_post_meta($rate,'mad4b_rate_price_kind_v1',true)!=='cost')continue;
            if((int)get_post_meta($rate,'prop_related_property_id',true)!==$property)continue;
            $proposal=get_post_meta($rate,'_mad4b_supplier_sheet_proposal_v1',true);
            if($occupancy!==''&&is_array($proposal) &&
                ($proposal['occupancy']??'')!==$occupancy)continue;
            if($meal!==''&&is_array($proposal) &&
                strtoupper((string)($proposal['meal_plan']??''))!==$meal)continue;
            $related=(int)get_post_meta($rate,'prop_related_unit_id',true);
            if($related>0&&($unit<=0||$related!==$unit))continue;
            $scope=$related>0?'unit':'property';
            if($related===0&&(string)get_post_meta($rate,'mad4b_rate_scope_v1',true)!=='property')continue;
            $actualCurrency=(string)get_post_meta($rate,'currency',true);
            if($actualCurrency!==$currency)continue; // no silent FX substitution
            $basis=(string)get_post_meta($rate,'prop_price_unit',true);
            // The owner-approved Hotels template validates the normalized unit,
            // not the historical raw per_person cell. Fail closed if stale.
            if($scope==='property'&&$basis==='per_person_night' &&
                (!class_exists('MAD4B_Hotel_Nightly_Cost_Basis_V1',false) ||
                 !MAD4B_Hotel_Nightly_Cost_Basis_V1::effective($rate)))continue;
            $amount=get_post_meta($rate,'base_amount',true);
            if(!is_numeric($amount)||!is_finite((float)$amount)||(float)$amount<=0)continue;
            $window=self::rateWindow($rate);
            if(!$window||$window[0]>$start||$window[1]<$last)continue;
            $matches[$scope][]=$rate;
        }
        foreach(['unit','property'] as $scope){
            if(count($matches[$scope])>1)
                return ['status'=>'ambiguous_'.$scope.'_rates','rate_id'=>null,
                    'scope'=>$scope,'count'=>count($matches[$scope])];
            if(count($matches[$scope])===1){
                $rate=$matches[$scope][0];
                return ['status'=>'preliminary_cost_reference','rate_id'=>$rate,
                    'scope'=>$scope,'basis'=>$basis,
                    'occupancy'=>$occupancy,'meal_plan'=>$meal,
                    'amount'=>(float)get_post_meta($rate,'base_amount',true),
                    'currency'=>$currency,
                    'stop_sale'=>(string)get_post_meta($rate,'stop_sale',true)!=='0',
                    'availability_status'=>(string)get_post_meta($rate,'avilabile_status',true),
                    'supplier_cost_receipt_verified'=>false,
                    'source_reference_only'=>true];
            }
        }
        return ['status'=>'no_matching_dated_property_cost_rate','rate_id'=>null];
    }
    public static function extendReport(array $report,int $tour,int $tier):array {
        if(!current_user_can('commerce_manage_pricing')||!current_user_can('edit_post',$tour))return $report;
        $ctx=self::context();
        $bindings=get_post_meta($tour,'_mad4b_package_stay_verified_room_program_links_v1',true);
        $index=[];
        foreach((array)($bindings['bindings']??[]) as $b)
            if(is_array($b)&&!empty($b['stage_key']))$index[(string)$b['stage_key']]=$b;
        $plan=get_post_meta($tour,'_mad4b_package_accommodation_source_plan_v1',true);
        $byKey=[];$duplicate=false;
        foreach((array)($plan['stages']??[]) as $s){
            $key=(string)($s['stage_key']??'');if(!$key)continue;
            if(isset($byKey[$key]))$duplicate=true;
            $byKey[$key]=$s;
        }
        $items=[];$covered=0;$sum=0.0;$totalStages=0;$seenKeys=[];
        foreach((array)($report['stages']??[]) as $row){
            if(!is_array($row)||!in_array($row['kind']??'',['hotel','resort'],true))continue;
            $key=(string)($row['key']??'');
            if($key===''||isset($seenKeys[$key])){
                $duplicate=true;continue; // One source cost inclusion per canonical stage_key.
            }
            $seenKeys[$key]=true;$totalStages++;
            $saved=$index[$key]??[];
            $nights=(int)($row['nights']??0);$day=(int)($row['day_start']??0);
            $property=(int)($saved['selected_property_id']??0);
            $units=array_values(array_unique(array_filter(array_map('absint',
                (array)($saved['bookable_unit_ids']??[])))));
            $unit=count($units)===1?$units[0]:0;
            $item=['stage_key'=>$key,'kind'=>$row['kind'],
                'destination'=>$row['destination']??'',
                'nights'=>$nights,'selected_property_id'=>$property?:null,
                'selected_unit_id'=>$unit?:null,'unit_required'=>false,
                'candidate_count'=>count((array)($row['candidates']??[])),
                'status'=>'review_required','reason'=>'start_date_required',
                'source'=>'not_selected','rate_id'=>null,'preliminary_cost'=>null,
                'supplier_cost_receipt_verified'=>false,'commercially_approved'=>false];
            if(!$ctx['start']||!$ctx['rooms']||!$ctx['adults']||$ctx['children']===null||
                $ctx['infants']===null||$ctx['currency']===''){
                $item['reason']=$ctx['start']?'invalid_party_rooms_or_currency':'start_date_required';
                $items[]=$item;continue;
            }
            if($day<1||$nights<1||!isset($byKey[$key])){
                $item['reason']='stage_source_geometry_unavailable';$items[]=$item;continue;
            }
            $first=$ctx['start']->modify('+'.($day-1).' days');
            $last=$first->modify('+'.($nights-1).' days');
            $item['from']=$first->format('Y-m-d');
            $item['to']=$last->format('Y-m-d');
            // Property Rates are occupancy-specific. Never mix Double and Triple
            // source rows or multiply an unknown per-person basis by party size.
            $perRoom=$ctx['rooms']>0&&$ctx['adults']%$ctx['rooms']===0
                ?intdiv($ctx['adults'],$ctx['rooms']):0;
            $occupancy=match($perRoom){1=>'single',2=>'double',3=>'triple',default=>''};
            $meal=strtoupper((string)($row['board']??''));
            $item['occupancy']=$occupancy?:null;
            $item['meal_plan']=$meal?:null;
            $item['candidate_costs']=[];
            if($occupancy===''){
                $item['reason']='occupancy_requires_explicit_room_allocation';
                $items[]=$item;continue;
            }
            $candidateCosts=[];
            foreach(array_slice((array)($row['candidates']??[]),0,30) as $candidate){
                $pid=(int)($candidate['id']??$candidate['property_id']??0);
                if($pid<=0||get_post_type($pid)!=='properties'||
                    get_post_status($pid)!=='publish')continue;
                if(class_exists('MAD4B_Package_Seasonal_Assignment_V1',false)){
                    $season=MAD4B_Package_Seasonal_Assignment_V1::decision($tour,$key,$tier,
                        $pid,$ctx['start']->format('Y-m-d'),$ctx['start']->format('Y-m-d'),
                        $day-1,$nights);
                    if(empty($season['eligible']))continue;
                }
                $source=self::sourceRate($pid,0,$item['from'],$item['to'],
                    $ctx['currency'],$occupancy,$meal);
                if(($source['status']??'')!=='preliminary_cost_reference')continue;
                $cost=self::math((string)$source['basis'],(float)$source['amount'],
                    $nights,$ctx['rooms'],$ctx['adults'],$ctx['children'],$ctx['infants']);
                if(!is_numeric($cost['amount']??null))continue;
                $candidateCosts[]=[
                    'property_id'=>$pid,'property_name'=>wp_strip_all_tags(get_the_title($pid)),
                    'rate_id'=>(int)$source['rate_id'],'scope'=>'property',
                    'preliminary_cost'=>(float)$cost['amount'],'currency'=>$ctx['currency'],
                    'basis'=>$source['basis'],'occupancy'=>$occupancy,
                    'stop_sale'=>$source['stop_sale'],
                    'availability_confirmed'=>false,
                    'cost_receipt_approved'=>false,
                    'price_status'=>'read_only_reference_not_sellable'];
            }
            usort($candidateCosts,static fn($a,$b)=>
                ($a['preliminary_cost']<=>$b['preliminary_cost']) ?:
                ($a['property_id']<=>$b['property_id']));
            $item['candidate_costs']=$candidateCosts;
            $item['lowest_indicative_cost_property_id']=$candidateCosts[0]['property_id']??null;
            if($property<=0){
                $item['reason']=$candidateCosts
                    ?'review_and_assign_property_per_stage_tier'
                    :'no_eligible_dated_cost_candidate';
                $items[]=$item;continue;
            }
            $eligibleIds=array_values(array_map('intval',array_map(
                static fn($c)=>(int)($c['id']??$c['property_id']??0),
                (array)($row['candidates']??[]))));
            if(!in_array($property,$eligibleIds,true)||get_post_type($property)!=='properties'||
                get_post_status($property)!=='publish'){
                $item['reason']='selected_property_not_eligible_for_destination_tier';$items[]=$item;continue;
            }
            if(class_exists('MAD4B_Package_Seasonal_Assignment_V1',false)){
                $season=MAD4B_Package_Seasonal_Assignment_V1::decision($tour,$key,$tier,
                    $property,$ctx['start']->format('Y-m-d'),$ctx['start']->format('Y-m-d'),
                    $day-1,$nights);
                if(empty($season['eligible'])){
                    $item['reason']='property_season_not_eligible';$items[]=$item;continue;
                }
            }
            if(count($units)>1){
                $item['reason']='multiple_selected_units_require_explicit_allocation';
                $items[]=$item;continue;
            }
            if($unit>0){
                // Original relation 223 and exact stage Property must agree.
                $eligibleUnits=[];
                foreach((array)($row['candidates']??[]) as $candidate){
                    if((int)($candidate['id']??$candidate['property_id']??0)===$property){
                        $eligibleUnits=array_map('intval',(array)($candidate['unit_ids']??[]));
                        foreach((array)($candidate['units']??[]) as $entry)
                            if(is_array($entry)&&!empty($entry['id']))
                                $eligibleUnits[]=(int)$entry['id'];
                        $eligibleUnits=array_values(array_unique($eligibleUnits));
                    }
                }
                global $wpdb;
                $relTable=$wpdb->prefix.'jet_rel_default';
                $linked=(int)$wpdb->get_var($wpdb->prepare(
                    "SELECT COUNT(*) FROM {$relTable} WHERE rel_id=%d AND parent_object_id=%d AND child_object_id=%d",
                    223,$property,$unit));
                if(get_post_type($unit)!=='units'||get_post_status($unit)!=='publish'||
                   !in_array($unit,$eligibleUnits,true)||$linked!==1){
                    $item['reason']='selected_unit_parent_or_status_unverified';$items[]=$item;continue;
                }
                $capacity=(int)get_post_meta($unit,'unit_max_occupancy',true);
                if($capacity>0&&($ctx['adults']+$ctx['children'])>$capacity*$ctx['rooms']){
                    $item['reason']='selected_unit_occupancy_exceeds_capacity';$items[]=$item;continue;
                }
            }
            $chosen=self::sourceRate($property,$unit,$item['from'],$item['to'],
                $ctx['currency'],$occupancy,$meal);
            $item['rate_id']=$chosen['rate_id']??null;
            $item['source']=$chosen['scope']??'none';
            if(($chosen['status']??'')!=='preliminary_cost_reference'){
                $item['reason']=$chosen['status']??'source_unavailable';$items[]=$item;continue;
            }
            $calculation=self::math((string)$chosen['basis'],(float)$chosen['amount'],
                $nights,$ctx['rooms'],$ctx['adults'],$ctx['children'],$ctx['infants']);
            $item['basis']=$chosen['basis'];$item['currency']=$ctx['currency'];
            $item['unit_amount']=$chosen['amount'];
            $item['stop_sale']=$chosen['stop_sale'];
            $item['availability_status']=$chosen['availability_status'];
            if($calculation['amount']===null){
                $item['reason']=$calculation['reason'];$items[]=$item;continue;
            }
            $item['preliminary_cost']=$calculation['amount'];
            $item['cost_factor']=$calculation['factor'];
            $item['status']='preliminary_needs_supplier_cost_receipt';
            $item['reason']=$chosen['stop_sale']?'source_stop_sale_or_availability_review':'source_cost_requires_signed_supplier_receipt';
            $item['meal_plan_status']='source_board_and_meal_inclusion_requires_verification';
            $sum+=$item['preliminary_cost'];$covered++;
            $items[]=$item;
        }
        // Existing company-wide policy is the ONE margin owner. Never apply
        // per Property/Unit, and never add margin to already-sold Tour Rates.
        // Unassigned candidate comparison is not a selection, a total
        // Package cost, a supplier confirmation or a payment quote.
        $hypotheticalHotelCost=0.0;$hypotheticalStageCount=0;
        foreach($items as $part){
            $candidate=$part['candidate_costs'][0]??null;
            if(is_array($candidate)){
                $hypotheticalHotelCost+=(float)$candidate['preliminary_cost'];
                $hypotheticalStageCount++;
            }
        }
        $policy=class_exists('MAD4B_Company_Global_Profit_V1',false)
            ?MAD4B_Company_Global_Profit_V1::current()
            :new WP_Error('company_global_profit_policy_missing');
        $profitStatus=is_wp_error($policy)?$policy->get_error_code():'company_policy_active';
        $profitPolicy=is_wp_error($policy)?null:array_intersect_key($policy,
            array_flip(['kind','percent','scope','policy_id','policy_revision']));
        $hotelComponentPreview=null;
        if(!is_wp_error($policy)&&$sum>0&&$ctx['currency']!==''){
            $q=MAD4B_Company_Global_Profit_V1::preview((int)round($sum*100),$ctx['currency']);
            if(!is_wp_error($q)&&($q['status']??'')==='illustrative_preview_only'&&
                ($q['checkout_authorized']??true)===false&&
                ($q['rate_created']??true)===false){
                $hotelComponentPreview=[
                    'status'=>'hotel_component_partial_only_not_tour_price',
                    'cost'=>round($sum,2),
                    'indicative_sale_component'=>round((int)$q['indicative_selling_price_minor']/100,2),
                    'estimated_margin'=>round((int)$q['profit_minor']/100,2),
                    'currency'=>$ctx['currency'],
                    'margin_applied_once'=>true,
                    'all_package_components_included'=>false,
                    'cost_receipts_verified'=>false,
                    'approved_price'=>null,
                    'checkout_ready'=>false];
            }
        }
        $candidateHotelPreview=null;
        if(!is_wp_error($policy)&&$hypotheticalStageCount===$totalStages&&
           $totalStages>0&&$hypotheticalHotelCost>0&&$ctx['currency']!==''){
            $candidateProfit=MAD4B_Company_Global_Profit_V1::preview(
                (int)round($hypotheticalHotelCost*100),$ctx['currency']);
            if(!is_wp_error($candidateProfit)){
                $candidateHotelPreview=[
                    'status'=>'unassigned_hotel_alternatives_illustration_only',
                    'hotel_stage_count'=>$hypotheticalStageCount,
                    'candidate_cost_total'=>round($hypotheticalHotelCost,2),
                    'hypothetical_hotel_component_with_margin'=>round(
                        (int)$candidateProfit['indicative_selling_price_minor']/100,2),
                    'company_profit_applied_once'=>true,
                    'currency'=>$ctx['currency'],
                    'no_property_selection_written'=>true,
                    'no_other_package_costs_included'=>true,
                    'approved_price'=>null,'checkout_ready'=>false];
            }
        }
        $report['hotel_cost_preview']=[
            'candidate_comparison_margin_preview'=>$candidateHotelPreview,
            'company_profit_status'=>$profitStatus,
            'company_profit_policy'=>$profitPolicy,
            'hotel_cost_component_margin_preview'=>$hotelComponentPreview,
            'profit_applied_to_existing_selling_rates'=>false,
            'contract'=>self::CONTRACT,
            'status'=>$duplicate?'duplicate_stage_keys_review_required':
                ($ctx['start']?'read_only_reference_costs':'travel_date_required'),
            'start_date'=>$ctx['start']?$ctx['start']->format('Y-m-d'):null,
            'currency'=>$ctx['currency'],'rooms'=>$ctx['rooms'],'adults'=>$ctx['adults'],
            'children'=>$ctx['children'],'infants'=>$ctx['infants'],
            'hotel_resort_stage_count'=>$totalStages,
            'preliminary_stage_count'=>$covered,
            'unpriced_stage_count'=>$totalStages-$covered,
            'preliminary_partial_total'=>$covered>0?round($sum,2):null,
            'complete_cost_total'=>null,'approved_price'=>null,
            'cost_receipt_qualified_count'=>0,'commercially_approved'=>false,
            'checkout_ready'=>false,'currency_conversion_automatic'=>false,
            'stages'=>$items,
            'notice'=>'Reference-only: original scoped costs, no unverified FX or child policy, no commercial Rate writer, no supplier signature inferred.'];
        return $report;
    }
    public static function boot():void {
        add_filter('mad4b/package_lab/inspected_report',[self::class,'extendReport'],30,3);
    }
}
MAD4B_Package_Hotel_Stage_Cost_Preview_V1::boot();
}
