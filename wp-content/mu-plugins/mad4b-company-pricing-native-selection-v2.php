<?php
/**
 * Plugin Name: MAD4B Policy Native Rate Selection
 * Description: Safe auto-choice of an unambiguous published Tour Rate before native Booking v2 price-only previews.
 * Version: 2.1.1
 */
defined('ABSPATH') || exit;
final class MAD4B_Policy_Native_Rate_Selection_V2 {
    const MAX_CANDIDATES = 50;

    private static function date($raw): bool {
        if (!is_string($raw) || !preg_match('/^20[0-9]{2}-[0-9]{2}-[0-9]{2}$/D',$raw)) return false;
        $d=DateTimeImmutable::createFromFormat('!Y-m-d',$raw,new DateTimeZone('UTC'));
        return $d && $d->format('Y-m-d')===$raw;
    }
    private static function error(string $reason,array $info=[]): WP_Error {
        do_action('mad4b/policy_rate_selection/gap', $reason, $info);
        return new WP_Error($reason,'An unambiguous native Rate could not be selected',$info);
    }
    /**
     * Only price-only / inquiry previews can request an automatic Rate.
     * Every published candidate is revalidated and previewed by the existing
     * Booking v2 authority. Never generate a price, reserve stock or allow checkout.
     */
    public static function select(array $payload): array|WP_Error {
        if(($payload['source']??null)!=='rate')return self::error('mad4b_rate_source_not_rate');
        if(!empty($payload['rate_id']))return self::error('mad4b_rate_already_selected');
        $intent=(string)($payload['booking_intent']??'quote');
        if(!in_array($intent,['quote','inquiry'],true))return self::error('mad4b_rate_explicit_required_for_booking');
        $tour=absint($payload['tour_id']??0);
        $date=$payload['travel_date']??'';
        if(!$tour||get_post_type($tour)!=='tours-and-activities'||get_post_status($tour)!=='publish'
            ||!self::date($date))return self::error('mad4b_rate_context_invalid');
        if(!function_exists('shared_product_booking_v2_preview')||
           !function_exists('shared_product_booking_v2_validate')||
           !function_exists('shared_product_booking_v2_rate_covers_date'))
            return self::error('mad4b_rate_native_provider_unavailable');
        $selections=$payload['selections']??[];
        if(!is_array($selections))return self::error('mad4b_rate_selections_invalid');
        $package=absint($selections['package_term_id']??0);
        $season=absint($selections['season_term_id']??0);
        $programIds=wp_get_post_terms($tour,'programs',['fields'=>'ids']);
        $program=(is_array($programIds) && count($programIds)===1)?(int)$programIds[0]:0;
        $policy=class_exists('MAD4B_Company_Policy_Registry_V2')
            ?MAD4B_Company_Policy_Registry_V2::pricing($tour,absint($selections['property_id']??0),0,$program,$season)
            :null;
        $ids=get_posts(['post_type'=>'tour-rates','post_status'=>'publish','fields'=>'ids',
            'posts_per_page'=>self::MAX_CANDIDATES+1,'suppress_filters'=>true,
            'meta_query'=>[['key'=>'related_tour_id','value'=>(string)$tour,'compare'=>'=']]]);
        if(count($ids)>self::MAX_CANDIDATES)return self::error('mad4b_rate_candidates_limit',['tour_id'=>$tour]);
        $valid=[];$excluded=[];
        foreach($ids as $rawId) {
            $rate=(int)$rawId;
            if(!shared_product_booking_v2_rate_covers_date($rate,$date)){
                $excluded['date']=($excluded['date']??0)+1;continue;
            }
            if(in_array(strtolower((string)get_post_meta($rate,'stop_sale',true)),['1','true','yes','on'],true)
               ||(string)get_post_meta($rate,'avilabile_status',true)==='0'){
                $excluded['stop_sale']=($excluded['stop_sale']??0)+1;continue;
            }
            if(function_exists('shared_product_rate_locale_decision')){
                $locale=shared_product_rate_locale_decision($rate,$tour);
                if(is_array($locale)&&($locale['allowed']??false)!==true){
                    $excluded['locale']=($excluded['locale']??0)+1;continue;
                }
            }
            $ratePackage=absint(get_post_meta($rate,'related_package_term_id',true));
            if($package && $ratePackage && $ratePackage!==$package){
                $excluded['package']=($excluded['package']??0)+1;continue;
            }
            $seasonMeta=absint(get_post_meta($rate,'_shared_generation_season_term_id',true));
            $seasons=wp_get_post_terms($rate,'season',['fields'=>'ids']);
            if(is_wp_error($seasons))$seasons=[];
            $explicitSeasons=array_unique(array_merge($seasonMeta?[$seasonMeta]:[],array_map('intval',$seasons)));
            if($season && $explicitSeasons && !in_array($season,$explicitSeasons,true)){
                $excluded['season']=($excluded['season']??0)+1;continue;
            }
            $proposal=$payload;$proposal['rate_id']=$rate;
            $native=shared_product_booking_v2_preview($proposal);
            if(is_wp_error($native)){
                $code=preg_replace('/[^a-z0-9_]/','_',strtolower($native->get_error_code()));
                $excluded[$code]=($excluded[$code]??0)+1;continue;
            }
            $value=$native['total']??null;$currency=$native['currency']??null;
            if(!is_numeric($value)||!is_finite((float)$value)||(float)$value<=0
               ||!is_string($currency)||!preg_match('/^[A-Z]{3}$/D',$currency)
               ||($native['checkout_ready']??null)!==false){
                $excluded['quote_contract']=($excluded['quote_contract']??0)+1;continue;
            }
            // Explicit package/season evidence dominates generic candidate.
            $score=($package && $ratePackage===$package?20:0)
                  +($season && in_array($season,$explicitSeasons,true)?40:0);
            $valid[]=['rate_id'=>$rate,'score'=>$score,'currency'=>$currency,'amount'=>(float)$value];
        }
        if(!$valid)return self::error('mad4b_rate_no_eligible_candidate',[
            'tour_id'=>$tour,'travel_date'=>$date,'excluded'=>$excluded]);
        usort($valid,static fn($a,$b)=>($b['score']<=>$a['score'])?:($a['rate_id']<=>$b['rate_id']));
        $best=$valid[0];
        $ties=array_values(array_filter($valid,static fn($c)=>$c['score']===$best['score']));
        if(count($ties)>1)return self::error('mad4b_rate_ambiguous',[
            'tour_id'=>$tour,'travel_date'=>$date,'candidate_count'=>count($ties),
            'candidate_ids'=>array_column($ties,'rate_id'),
            'reason'=>'multiple_equally_specific_native_valid_rates',
        ]);
        $receipt=[
            'contract'=>'mad4b.policy-native-rate-choice.v2','status'=>'selected_native_price_only',
            'tour_id'=>$tour,'rate_id'=>$best['rate_id'],'travel_date'=>$date,
            'currency'=>$best['currency'],'amount'=>$best['amount'],
            'policy_strategy'=>$policy['strategy']??'native','policy_receipts'=>$policy['decision_receipts']??[],
            'candidate_count'=>count($valid),'excluded_counts'=>$excluded,'score'=>$best['score'],
            'native_quote_authority'=>'shared_product_booking_v2_preview',
            'checkout_ready'=>false,'published_amount_changed'=>false,
        ];
        do_action('mad4b/policy_rate_selection/selected',$receipt);
        return ['rate_id'=>$best['rate_id'],'receipt'=>$receipt];
    }
    /** Property Rates are a separate component; never replace a Tour base Rate. */
    public static function select_hotel(array $input): array|WP_Error {
        $property=absint($input['property_id']??0);
        $unit=absint($input['unit_id']??0);
        $checkin=$input['checkin']??'';
        $checkout=$input['checkout']??'';
        if(!$property||!$unit||get_post_status($property)!=='publish'
           ||get_post_type($property)!=='properties'||get_post_type($unit)!=='units'
           ||get_post_status($unit)!=='publish'||!self::date($checkin)
           ||!self::date($checkout)||$checkout<=$checkin)
            return self::error('mad4b_hotel_context_invalid');
        if(!function_exists('abk_accommodation_shadow_quote'))
            return self::error('mad4b_hotel_provider_unavailable');
        $policy=class_exists('MAD4B_Company_Policy_Registry_V2')
            ?MAD4B_Company_Policy_Registry_V2::pricing(0,$property,0,0)
            :null;
        $strategy=(string)($policy['hotel_component_strategy']??'fixed_seasonal');
        if(!in_array($strategy,['dynamic_hotel_rates','hybrid'],true))
            return self::error('mad4b_hotel_not_dynamic_policy',['property_id'=>$property]);
        $ids=get_posts(['post_type'=>'property-rates','post_status'=>'publish',
            'fields'=>'ids','posts_per_page'=>self::MAX_CANDIDATES+1,'suppress_filters'=>true,
            'meta_query'=>[['key'=>'prop_related_property_id',
                            'value'=>(string)$property,'compare'=>'=']]]);
        if(count($ids)>self::MAX_CANDIDATES)
            return self::error('mad4b_hotel_rate_limit',['property_id'=>$property]);
        $valid=[];$excluded=[];
        foreach($ids as $rawId){
            $id=(int)$rawId;
            if((int)get_post_meta($id,'prop_related_unit_id',true)!==$unit){$excluded['unit']=($excluded['unit']??0)+1;continue;}
            if(in_array(strtolower((string)get_post_meta($id,'stop_sale',true)),['1','true','yes','on'],true)){
                $excluded['stop_sale']=($excluded['stop_sale']??0)+1;continue;
            }
            $native=abk_accommodation_shadow_quote([
                'rate_id'=>$id,'checkin'=>$checkin,'checkout'=>$checkout,
                'rooms'=>(int)($input['rooms']??1),'adults'=>(int)($input['adults']??2),
                'children'=>(int)($input['children']??0),'availability_mode'=>'price_only',
            ]);
            if(is_wp_error($native)){
                $code=$native->get_error_code();$excluded[$code]=($excluded[$code]??0)+1;continue;
            }
            $amount=$native['total']??null;$currency=$native['currency']??null;
            if(!is_array($native)||($native['checkout_ready']??null)!==false
               ||!is_numeric($amount)||!is_finite((float)$amount)||(float)$amount<=0
               ||!is_string($currency)||!preg_match('/^[A-Z]{3}$/D',$currency)){
                $excluded['price_contract']=($excluded['price_contract']??0)+1;continue;
            }
            $valid[]=['rate_id'=>$id,'amount'=>(float)$amount,'currency'=>$currency];
        }
        if(!$valid)return self::error('mad4b_hotel_no_valid_rate',['property_id'=>$property,'excluded'=>$excluded]);
        if(count($valid)!==1)
            return self::error('mad4b_hotel_rate_ambiguous',['property_id'=>$property,
                'candidate_ids'=>array_column($valid,'rate_id')]);
        $v=$valid[0];
        return ['contract'=>'mad4b.policy-hotel-rate-choice.v2','status'=>'native_hotel_component_selected',
            'property_id'=>$property,'unit_id'=>$unit,'rate_id'=>$v['rate_id'],
            'total'=>$v['amount'],'currency'=>$v['currency'],
            'pricing_strategy'=>$strategy,'decision_receipts'=>$policy['decision_receipts']??[],
            'native_provider'=>'abk_accommodation_shadow_quote',
            'component_only'=>true,'booking_total_changed'=>false,
            'inventory_reserved'=>false,'checkout_ready'=>false];
    }
}
function mad4b_policy_auto_rate_select_v2(array $payload): array|WP_Error {
    return MAD4B_Policy_Native_Rate_Selection_V2::select($payload);
}

function mad4b_policy_hotel_rate_select_v2(array $input): array|WP_Error {
    return MAD4B_Policy_Native_Rate_Selection_V2::select_hotel($input);
}
