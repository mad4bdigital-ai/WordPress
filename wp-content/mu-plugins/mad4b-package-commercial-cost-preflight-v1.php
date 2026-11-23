<?php
/**
 * Plugin Name: MAD4B Package Commercial Cost Preflight
 * Version: 1.0.0
 * Description: Read-only complete-stage Cost Composition gap ledger; never promotes indicative Property References or historical Tour Rates into a sale price.
 */
defined('ABSPATH') || exit;
if(!class_exists('MAD4B_Package_Commercial_Cost_Preflight_V1',false)){
final class MAD4B_Package_Commercial_Cost_Preflight_V1 {
    public const CONTRACT='mad4b.package-commercial-cost-preflight.v1';
    /** Pure stage-key join. No draft/selling Rate or supplier signature inferred. */
    public static function join(array $planStages,array $hotelPreview,array $savedBindings=[]):array {
        $lookup=[];$invalid=[];
        foreach((array)($hotelPreview['stages']??[]) as $h){
            if(!is_array($h))continue;
            $key=(string)($h['stage_key']??'');
            if(!$key || isset($lookup[$key])){$invalid[]='duplicate_or_missing_hotel_preview_key';continue;}
            $lookup[$key]=$h;
        }
        $bindings=[];
        foreach($savedBindings as $b){
            if(!is_array($b))continue;
            $key=(string)($b['stage_key']??'');
            if(!$key || isset($bindings[$key])){$invalid[]='duplicate_or_missing_binding_key';continue;}
            $bindings[$key]=$b;
        }
        $rows=[];$seen=[];$subtotal=0.0;$costed=0;
        foreach($planStages as $p){
            if(!is_array($p)){$invalid[]='invalid_plan_stage';continue;}
            $key=(string)($p['stage_key']??'');
            if(!$key || isset($seen[$key])){$invalid[]='duplicate_or_missing_stage_key';continue;}
            $seen[$key]=true;
            $kind=(string)($p['lodging_kind']??'');
            $row=['stage_key'=>$key,'kind'=>$kind,'destination'=>$p['destination_key']??null,
                'nights'=>(int)($p['nights']??0),'source'=>'none',
                'pricing_property_id'=>null,'cost_rate_id'=>null,
                'reference_cost'=>null,'currency'=>null,
                'rate_ready'=>false,'supplier_receipt_qualified'=>false,
                'availability_confirmed'=>false,'commercial_sale_authorized'=>false,
                'missing'=>[]];
            $binding=$bindings[$key]??[];
            $receipt=$binding['supplier_cost_receipt']??null;
            $row['supplier_receipt_recorded']=is_array($receipt)&&
                ($receipt['status']??'')==='approved';
            // A recorded `status=approved` is not a cryptographic approval.
            // Cost Receipt Gate + signer must still admit each receipt.
            if(in_array($kind,['hotel','resort'],true)){
                $h=$lookup[$key]??null;
                if(!is_array($h)||($h['kind']??'')!==$kind||
                    (int)($h['nights']??0)!==$row['nights']){
                    $row['missing'][]='hotel_stage_preview_source_geometry_missing';
                }else{
                    $cost=$h['preliminary_cost']??null;
                    $auto=(int)($h['pricing_property_id']??0);
                    $rate=(int)($h['rate_id']??0);
                    if($auto>0&&$rate>0&&is_numeric($cost)&&
                       is_finite((float)$cost)&&(float)$cost>0&&
                       ($h['commercially_approved']??false)===false){
                        $row['pricing_property_id']=$auto;
                        $row['cost_rate_id']=$rate;
                        $row['source']='dated_property_cost_reference_only';
                        $row['reference_cost']=round((float)$cost,2);
                        $row['currency']=$h['currency']??null;
                        $subtotal+=(float)$row['reference_cost'];$costed++;
                    }else $row['missing'][]='qualified_dated_property_cost_reference_missing';
                }
                $row['missing'][]='signed_supplier_cost_receipt_and_allotment_required';
            }elseif($kind==='cruise'){
                $row['missing'][]='cruise_vessel_sailing_program_and_cost_receipt_required';
            }elseif($kind==='sleeper_train'){
                $row['missing'][]='sleeper_train_dated_route_class_and_cost_receipt_required';
            }else{
                $row['missing'][]='unknown_source_stage_kind_requires_component_provider';
            }
            $rows[]=$row;
        }
        foreach(array_keys($bindings) as $k)if(!isset($seen[$k]))$invalid[]='orphan_source_binding';
        foreach(array_keys($lookup) as $k)if(!isset($seen[$k]))$invalid[]='orphan_hotel_cost_preview';
        return ['stages'=>$rows,'source_errors'=>array_values(array_unique($invalid)),
            'stage_count'=>count($rows),'hotel_costed_stage_count'=>$costed,
            'preliminary_hotel_reference_subtotal'=>$costed>0?round($subtotal,2):null,
            'complete_qualified_stage_cost_minor'=>null,
            'all_stage_keys_covered'=>!$invalid&&count($rows)===count($planStages),
            'all_supplier_receipts_qualified'=>false];
    }
    public static function extendReport(array $report,int $tour,int $tier):array {
        $out=['contract'=>self::CONTRACT,'tour_id'=>$tour,'tier_id'=>$tier,
            'status'=>'source_stage_plan_unavailable','stage_count'=>0,
            'stage_costs_qualified'=>0,'hotel_costed_stage_count'=>0,
            'preliminary_hotel_reference_subtotal'=>null,
            'complete_supplier_cost_total'=>null,'approved_price'=>null,
            'new_tour_rate_ready'=>false,'form6_final_price_ready'=>false,
            'woo_advance_allowed_by_this_source'=>false,
            'no_legacy_tour_rate_cost_fallback'=>true,
            'no_property_assignment_written'=>true,
            'missing_groups'=>['source_stage_plan'],
            'stages'=>[],'source_errors'=>[]];
        if(!current_user_can('commerce_manage_pricing')||!current_user_can('edit_post',$tour)){
            $out['status']='pricing_admin_permission_required';
            $report['commercial_cost_preflight']=$out;return $report;
        }
        $plan=get_post_meta($tour,'_mad4b_package_accommodation_source_plan_v1',true);
        $saved=get_post_meta($tour,'_mad4b_package_stay_verified_room_program_links_v1',true);
        if(!is_array($plan)||!is_array($plan['stages']??null)||
           !is_array($saved)||!is_array($saved['bindings']??null)||
           ($saved['schema']??'')!=='mad4b.package-stay-unit-program-bindings.v1'||
           (int)($saved['tour_id']??0)!==$tour){
            $report['commercial_cost_preflight']=$out;return $report;
        }
        $expected=hash('sha256',wp_json_encode($plan,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));
        if(!hash_equals($expected,(string)($saved['source_plan_sha256']??''))){
            $out['status']='source_plan_bindings_stale';
            $out['source_errors'][]='source_digest_mismatch';
            $report['commercial_cost_preflight']=$out;return $report;
        }
        $hotel=(array)($report['hotel_cost_preview']??[]);
        if(($hotel['contract']??null)!=='mad4b.package-hotel-stage-cost-preview.v1'){
            $out['status']='original_hotel_cost_preview_missing';
            $report['commercial_cost_preflight']=$out;return $report;
        }
        $joined=self::join($plan['stages'],$hotel,$saved['bindings']);
        $out=array_replace($out,$joined);
        $out['currency']=$hotel['currency']??null;
        $out['start_date']=$hotel['start_date']??null;
        $out['company_profit_policy']=$hotel['company_profit_policy']??null;
        $out['existing_tour_rates_ignored']=true;
        $out['missing_groups']=['signed_stage_cost_receipts','supplier_inventory_or_allotment',
            'included_transfers_and_transport','guide_and_required_visit_services',
            'complete_same_occupancy_cost_composition','commercial_approval_for_new_tour_rate'];
        $out['status']=$joined['source_errors']?'source_stage_join_review_required':
            ($out['start_date']===null?'travel_date_required':
            'preliminary_references_only_commercial_cost_missing');
        // A hotel-only reference is NEVER a full Package cost. Profit preview
        // stays isolated in hotel_cost_preview and is not re-applied here.
        $report['commercial_cost_preflight']=$out;
        return $report;
    }
    public static function boot():void {
        add_filter('mad4b/package_lab/inspected_report',[self::class,'extendReport'],50,3);
    }
}
MAD4B_Package_Commercial_Cost_Preflight_V1::boot();
}
