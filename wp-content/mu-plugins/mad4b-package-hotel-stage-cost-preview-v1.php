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
        string $currency):array {
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
            $related=(int)get_post_meta($rate,'prop_related_unit_id',true);
            if($related>0&&($unit<=0||$related!==$unit))continue;
            $scope=$related>0?'unit':'property';
            if($related===0&&(string)get_post_meta($rate,'mad4b_rate_scope_v1',true)!=='property')continue;
            $actualCurrency=(string)get_post_meta($rate,'currency',true);
            if($actualCurrency!==$currency)continue; // no silent FX substitution
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
                    'scope'=>$scope,'basis'=>(string)get_post_meta($rate,'prop_price_unit',true),
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
            if($property<=0){$item['reason']='select_property_for_this_stage_and_tier';$items[]=$item;continue;}
            $eligibleIds=array_map('intval',array_column((array)($row['candidates']??[]),'property_id'));
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
                    if((int)($candidate['property_id']??0)===$property)
                        $eligibleUnits=array_map('intval',(array)($candidate['unit_ids']??[]));
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
            $chosen=self::sourceRate($property,$unit,$item['from'],$item['to'],$ctx['currency']);
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
        $report['hotel_cost_preview']=[
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
