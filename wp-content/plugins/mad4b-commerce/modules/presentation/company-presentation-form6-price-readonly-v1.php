<?php
/** Source-native presentation-only price preview for tenants without Commerce runtime.
 * Delegates monetary figures to original Shared Product Core providers; never
 * creates carts, orders, reservations, payment intents or supplier requests.
 */
defined('ABSPATH') || exit;
final class MAD4B_Form6_Readonly_Price_Preview_V1 {
    public const CONTRACT = 'mad4b.form6-readonly-price-preview.v1';
    private static function enabled(): bool {
        if (!defined('MAD4B_PRESENTATION_RUNTIME_ENABLED') || MAD4B_PRESENTATION_RUNTIME_ENABLED !== true ||
            (defined('MAD4B_COMMERCE_RUNTIME_ENABLED') && MAD4B_COMMERCE_RUNTIME_ENABLED === true)) return false;
        if (!class_exists('MAD4B_SCP_Site_Profile',false)) return false;
        $p=MAD4B_SCP_Site_Profile::status();
        return !empty($p['configured']) && !empty($p['origin_match']) &&
            !empty($p['environment_match']) && !empty($p['deployment_binding_match']);
    }
    public static function status(): array {
        $f=['shared_product_resolve_price_sources','shared_product_price_source_policy',
            'shared_product_quote_snapshot','shared_product_strategy_quote',
            'shared_product_resolve_price_unit','shared_product_unit_extended_amount',
            'shared_product_extra_source_quote','shared_product_booking_v2_rate_covers_date',
            'pricing_display_currency','pricing_currency_convert'];
        $results=[];foreach($f as $n)$results[$n]=function_exists($n);
        return ['contract'=>self::CONTRACT,'enabled'=>self::enabled(),
            'all_readonly_dependencies'=>!in_array(false,$results,true),
            'functions'=>$results,'commerce_runtime_activated'=>false];
    }
    private static function id($value): int {
        if ((!is_int($value) && !is_string($value)) ||
            !preg_match('/^[1-9][0-9]{0,10}$/D',(string)$value)) return 0;
        return (int)$value;
    }
    private static function fail(string $code): WP_Error {return new WP_Error($code,'Price preview unavailable',['status'=>422]);}
    public static function quote($raw) {
        if (!self::enabled() || !self::status()['all_readonly_dependencies'])
            return self::fail('form6_presentation_dependencies_unavailable');
        if (!is_array($raw) || array_diff(array_keys($raw),
            ['tour_id','source','rate_id','travel_date','adults','children','infants','occupancy','extras','child_ages','infant_ages']))
            return self::fail('form6_preview_invalid_fields');
        $tour=self::id($raw['tour_id']??null);
        $post=$tour?get_post($tour):null;
        if (!$post || $post->post_type!=='tours-and-activities' || $post->post_status!=='publish' ||
            $post->post_password!=='' || get_post_meta($tour,'_booking_experience_qa_pricing_fixture',true)!=='')
            return self::fail('form6_preview_tour_unavailable');
        $source=$raw['source']??null;
        if (!in_array($source,['rate','direct'],true))return self::fail('form6_preview_source_invalid');
        $rate=self::id($raw['rate_id']??null);
        $occupancy=$raw['occupancy']??null;
        if (!in_array($occupancy,['single','double','triple'],true))return self::fail('form6_preview_occupancy_invalid');
        $counts=[];
        foreach(['adults','children','infants'] as $key){
            $value=$raw[$key]??null;
            if ((!is_int($value)&&!is_string($value)) ||
                !preg_match('/^(0|[1-9][0-9]{0,2})$/D',(string)$value))
                return self::fail('form6_preview_travellers_invalid');
            $counts[$key]=(int)$value;
        }
        if ($counts['adults']<1 || array_sum($counts)>100)
            return self::fail('form6_preview_travellers_invalid');
        $date=$raw['travel_date']??null;
        if (!is_string($date)||!preg_match('/^\d{4}-\d{2}-\d{2}$/D',$date)||
            !($d=DateTimeImmutable::createFromFormat('!Y-m-d',$date,new DateTimeZone('UTC')))||
            $d->format('Y-m-d')!==$date || $date < gmdate('Y-m-d'))
            return self::fail('form6_preview_date_invalid');
        $extras=$raw['extras']??[];
        if (!is_array($extras)||!array_is_list($extras)||count($extras)>15)
            return self::fail('form6_preview_extras_invalid');
        if (!empty($raw['child_ages']) || !empty($raw['infant_ages'])) {
            // Age-aware quotes require the original certified finance-grade policy.
            // Return adults-only reference with explicit warning below.
            if (!is_array($raw['child_ages']??[]) || !is_array($raw['infant_ages']??[]))
                return self::fail('form6_preview_ages_invalid');
        }
        $currency=pricing_display_currency();
        $wcml=get_option('_wcml_settings',[]);
        if (!is_string($currency)||!preg_match('/^[A-Z]{3}$/D',$currency)||
            !isset($wcml['currency_options'][$currency]))
            return self::fail('form6_preview_currency_unavailable');
        $qty=['persons'=>$counts['adults']];
        $mode=(string)get_post_meta($tour,'tour_mode',true);
        $lines=[];$baseAmount=0.0;
        if ($source==='rate') {
            $p=$rate?get_post($rate):null;
            if (!$p||$p->post_type!=='tour-rates'||$p->post_status!=='publish'||$p->post_password!==''||
                get_post_meta($rate,'_booking_experience_qa_pricing_fixture',true)!==''||
                in_array(strtolower((string)get_post_meta($rate,'stop_sale',true)),['1','yes','true'],true))
                return self::fail('form6_preview_rate_unavailable');
            $owner=(int)get_post_meta($rate,'related_tour_id',true);
            $siblings=class_exists('ETG_Tour_Rate_Display') ? ETG_Tour_Rate_Display::language_ids($tour) : [$tour];
            if (!in_array($owner,array_map('intval',(array)$siblings),true) ||
                !shared_product_booking_v2_rate_covers_date($rate,$date))
                return self::fail('form6_preview_rate_not_eligible');
            $quote=shared_product_strategy_quote($rate,'general',$occupancy,$currency);
            if (is_wp_error($quote)||!is_numeric($quote['display_amount']??null)||
                ($quote['display_currency']??'')!==$currency)
                return self::fail('form6_preview_rate_price_unavailable');
            $unit=shared_product_resolve_price_unit($rate);
            if (is_wp_error($unit))return self::fail('form6_preview_rate_unit_unavailable');
            $extended=shared_product_unit_extended_amount($quote['display_amount'],$unit['unit'],$qty);
            if (is_wp_error($extended))return self::fail('form6_preview_rate_quantity_unavailable');
            $baseAmount=(float)$extended['amount'];
            $lines[]=['kind'=>'base','tour_id'=>$tour,'rate_id'=>$rate,'source'=>'rate',
                'amount'=>$baseAmount,'currency'=>$currency,'unit'=>$unit['unit'],'quantity'=>$extended['quantity']];
        }else{
            if ($rate)return self::fail('form6_preview_direct_rate_invalid');
            $policy=shared_product_price_source_policy($mode);
            if (is_wp_error($policy))return self::fail('form6_preview_source_policy_unavailable');
            $ratePreferred=$policy['rate_weight']>$policy['direct_weight'] ||
                ($policy['rate_weight']===$policy['direct_weight']&&$policy['tie_break']==='rate');
            if ($ratePreferred){
                $ids=get_posts(['post_type'=>'tour-rates','post_status'=>'publish','posts_per_page'=>101,
                    'fields'=>'ids','meta_key'=>'related_tour_id','meta_value'=>$tour]);
                if(count($ids)>100)return self::fail('form6_preview_rate_discovery_limit');
                foreach($ids as $candidate)if(shared_product_booking_v2_rate_covers_date((int)$candidate,$date))
                    return self::fail('form6_preview_requires_rate_resolution');
            }
            $unit=(string)get_post_meta($tour,'tour_price_unit',true);
            $amount=get_post_meta($tour,'start_price',true);
            $original=strtoupper((string)get_post_meta($tour,'tour_base_currency',true));
            if (!in_array($unit,['per_person','per_trip','per_day','per_room','per_cabin','per_vehicle'],true)||
                !is_numeric($amount)||!is_finite((float)$amount)||(float)$amount<=0||
                !preg_match('/^[A-Z]{3}$/D',$original))
                return self::fail('form6_preview_direct_price_unavailable');
            $extended=shared_product_unit_extended_amount($amount,$unit,$qty);
            if (is_wp_error($extended))return self::fail('form6_preview_direct_unit_unavailable');
            $decision=shared_product_resolve_price_sources(['direct'=>['amount'=>$extended['amount'],'currency'=>$original]],$mode);
            if (is_wp_error($decision)||($decision['source']??'')!=='direct')
                return self::fail('form6_preview_direct_policy_rejected');
            $converted=pricing_currency_convert((float)$extended['amount'],$original,$currency);
            if (!is_numeric($converted)||!is_finite((float)$converted)||(float)$converted<=0)
                return self::fail('form6_preview_direct_fx_unavailable');
            $baseAmount=round((float)$converted,2);
            $lines[]=['kind'=>'base','tour_id'=>$tour,'rate_id'=>null,'source'=>'direct',
                'amount'=>$baseAmount,'currency'=>$currency,'unit'=>$unit,'quantity'=>$extended['quantity']];
        }
        $total=$baseAmount;$extraPrices=[];$seen=[];
        foreach($extras as $selection) {
            if (!is_array($selection)||array_diff(array_keys($selection),['tour_id','persons']))
                return self::fail('form6_preview_extra_shape_invalid');
            $extraId=self::id($selection['tour_id']??null);
            $persons=$selection['persons']??null;
            if (!$extraId||isset($seen[$extraId])||(!is_string($persons)&&!is_int($persons))||
                !preg_match('/^[1-9][0-9]{0,2}$/D',(string)$persons)||
                (int)$persons>$counts['adults'])return self::fail('form6_preview_extra_count_invalid');
            $seen[$extraId]=true;
            $line=shared_product_extra_source_quote($tour,['tour_id'=>$extraId],
                'general',$occupancy,['persons'=>(int)$persons],$date,$currency);
            if (is_wp_error($line)||($line['currency']??'')!==$currency||
                !is_numeric($line['amount']??null)||!is_finite((float)$line['amount'])||
                (float)$line['amount']<0)return self::fail('form6_preview_extra_price_unavailable');
            $total+=(float)$line['amount'];$lines[]=$line;
            if (!empty($line['unit']) && !empty($line['quantity']) && is_numeric($line['quantity'])){
                $extraPrices[(string)$extraId]=[
                    'amount'=>round((float)$line['amount']/(float)$line['quantity'],2),
                    'currency'=>$currency,'unit'=>$line['unit'],'status'=>'server_price_preview_only'];
            }
        }
        $total=round($total,2);
        if ($total<=0||!is_finite($total))return self::fail('form6_preview_total_unavailable');
        $minors=$counts['children']>0||$counts['infants']>0;
        return ['contract'=>self::CONTRACT,'status'=>$minors?'partial_price_preview_only':'price_preview_only',
            'tour_id'=>$tour,'rate_id'=>$rate?:null,'source'=>$source,'currency'=>$currency,
            'total'=>$minors?null:$total,'display_amount'=>$total,
            'display_kind'=>$minors?'adults_only_reference':'nonbinding_price_preview',
            'children_count'=>$counts['children'],'infants_count'=>$counts['infants'],
            'lines'=>$lines,'extra_prices'=>$extraPrices,
            'component_adjustments_certified'=>false,
            'pricing_scope'=>$minors?'adults_only_reference_without_minor_charges':'base_tour_and_verified_extras_only',
            'availability'=>'not_checked','checkout_ready'=>false,'order_created'=>false];
    }
    public static function register(): void {
        if (!self::enabled())return;
        register_rest_route('shared-product/v1','/tour-form-price-preview',[
            'methods'=>WP_REST_Server::CREATABLE,
            'permission_callback'=>'__return_true',
            'callback'=>static function(WP_REST_Request $request){
                $raw=$request->get_json_params();
                $result=self::quote($raw);
                $response=is_wp_error($result)?new WP_Error($result->get_error_code(),
                    'Price preview unavailable',['status'=>422]):rest_ensure_response($result);
                if($response instanceof WP_REST_Response){
                    $response->header('Cache-Control','private, no-store, max-age=0');
                    $response->header('X-Robots-Tag','noindex, nofollow');
                }
                return $response;
            },
        ]);
    }
}
add_action('rest_api_init',[MAD4B_Form6_Readonly_Price_Preview_V1::class,'register'],25);
