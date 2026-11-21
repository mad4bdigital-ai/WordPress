<?php
/**
 * Plugin Name: MAD4B Accommodation Layered Pricing
 * Description: Additive self-provider price-source preview over existing Properties, Units and Property Rates.
 * Version: 0.1.0
 */
defined('ABSPATH') || exit;
if (!class_exists('MAD4B_Stay_Layered_Pricing_V1', false)) {
final class MAD4B_Stay_Layered_Pricing_V1 {
    const BASES=['per_person','per_room_night','per_cabin_night','per_person_night','per_stay'];
    // Explicit dated rates outrank undated direct starting prices.
    // Cost reference selection is a distinct lane and never becomes a selling price.
    const PRIORITY=['unit_rate','property_rate','unit_direct','property_direct'];
    const COST_PRIORITY=['unit_rate','property_rate'];

    private static function money($v) {
        return is_scalar($v) && !is_bool($v) && preg_match('/^(?:0|[1-9][0-9]{0,7})(?:\.[0-9]{1,4})?$/D',(string)$v)
            && (float)$v>0 ? round((float)$v,4) : false;
    }
    private static function date_valid($v):bool {
        if(!is_string($v)||!preg_match('/^20[0-9]{2}-[0-9]{2}-[0-9]{2}$/D',$v))return false;
        $d=DateTimeImmutable::createFromFormat('!Y-m-d',$v,new DateTimeZone('UTC'));
        return $d&&$d->format('Y-m-d')===$v;
    }
    /**
     * One source of Season dates: existing approved Term Meta.
     * The sheet imports carry a Term reference and anchor year, NOT duplicate
     * valid_from/valid_to fields. Explicit complete Rate dates take precedence.
     */
    public static function effectiveRateWindow(int $rate):array {
        if(get_post_type($rate)!=='property-rates')
            return ['valid'=>false,'source'=>null,'reason'=>'rate_type_mismatch'];
        $from=(string)get_post_meta($rate,'valid_from',true);
        $to=(string)get_post_meta($rate,'valid_to',true);
        if($from!==''||$to!==''){
            if(!self::date_valid($from)||!self::date_valid($to)||$from>$to)
                return ['valid'=>false,'source'=>'explicit','reason'=>'partial_or_invalid_custom_dates'];
            return ['valid'=>true,'source'=>'explicit','start'=>$from,'end'=>$to];
        }
        $slug=(string)get_post_meta($rate,'_mad4b_supplier_sheet_season_slug_v1',true);
        $term=(int)get_post_meta($rate,'_mad4b_supplier_sheet_season_term_id_v1',true);
        $year=(int)get_post_meta($rate,'_mad4b_supplier_sheet_season_anchor_year_v1',true);
        if(!in_array($slug,['summer','winter'],true)||$term<1||
           $year<2020||$year>2100 ||
           !class_exists('MAD4B_Package_Seasonal_Assignment_V1',false))
            return ['valid'=>false,'source'=>null,'reason'=>'date_and_source_season_missing'];
        $seasons=MAD4B_Package_Seasonal_Assignment_V1::seasons();
        $policy=$seasons[$slug]??null;
        if(!is_array($policy)||(int)$policy['policy_term_id']!==$term)
            return ['valid'=>false,'source'=>'season_term_meta','reason'=>'season_term_identity_or_policy_changed'];
        $sm=(int)$policy['sm'];$sd=(int)$policy['sd'];
        $em=(int)$policy['em'];$ed=(int)$policy['ed'];
        $cross=!empty($policy['cross']);
        $endYear=$year+($cross?1:0);
        if(!checkdate($sm,$sd,$year)||!checkdate($em,$ed,$endYear))
            return ['valid'=>false,'source'=>'season_term_meta','reason'=>'season_term_dates_invalid'];
        $start=sprintf('%04d-%02d-%02d',$year,$sm,$sd);
        $end=sprintf('%04d-%02d-%02d',$endYear,$em,$ed);
        if($start>$end)
            return ['valid'=>false,'source'=>'season_term_meta','reason'=>'season_window_reversed'];
        return ['valid'=>true,'source'=>'season_term_meta','start'=>$start,'end'=>$end,
            'policy_term_id'=>$term,'season_slug'=>$slug,'anchor_year'=>$year];
    }
    private static function source(int $id,string $type,int $property,int $unit) {
        if($type==='property_direct')
            $keys=['prop_start_price','prop_base_currency','prop_direct_price_unit','prop_direct_price_kind'];
        elseif($type==='unit_direct')
            $keys=['unit_start_price','unit_base_currency','unit_direct_price_unit','unit_direct_price_kind'];
        else
            $keys=['base_amount','currency','prop_price_unit','mad4b_rate_price_kind_v1'];
        [$priceKey,$currencyKey,$basisKey,$kindKey]=$keys;
        $raw=get_post_meta($id,$priceKey,true);
        if($raw===''||$raw===false)return null;
        $amount=self::money($raw);
        if($amount===false)return new WP_Error('stay_layer_amount_invalid',$type);
        $currency=strtoupper(trim((string)get_post_meta($id,$currencyKey,true)));
        $basis=(string)get_post_meta($id,$basisKey,true);
        $kind=(string)get_post_meta($id,$kindKey,true);
        if($kind==='')$kind='sell';
        if(!preg_match('/^[A-Z]{3}$/D',$currency)||!in_array($basis,self::BASES,true)
            ||!in_array($kind,['sell','cost'],true))return new WP_Error('stay_layer_terms_missing',$type);
        if(function_exists('get_woocommerce_currencies')
            &&!array_key_exists($currency,(array)get_woocommerce_currencies()))
            return new WP_Error('stay_layer_currency_unknown',$type);
        if(function_exists('abk_effective_price_units')){
            $allowed=abk_effective_price_units($property,$unit);
            if(is_wp_error($allowed))return $allowed;
            if(!in_array($basis,$allowed,true))return new WP_Error('stay_layer_basis_disallowed',$type);
        }
        return ['source'=>$type,'id'=>$id,'amount'=>$amount,'currency'=>$currency,
            'price_unit'=>$basis,'price_kind'=>$kind,'provider'=>'self'];
    }
    private static function unit_link(int $property,int $unit) {
        if(get_post_type($unit)!=='units'||get_post_status($unit)!=='publish')
            return new WP_Error('stay_layer_unit_not_published');
        if(!function_exists('abk_entity_map'))return new WP_Error('stay_layer_relation_provider_missing');
        $map=abk_entity_map();
        if(is_wp_error($map))return $map;
        global $wpdb;
        $table=$wpdb->prefix.$map['relation_table_suffix'];
        $num=(int)$wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE rel_id=%d AND parent_object_id=%d AND child_object_id=%d",
            (int)$map['relation_id'],$property,$unit));
        return $num===1 ? true : new WP_Error('stay_layer_unit_relation_missing');
    }
    private static function rate_sources(int $property,int $unit,string $date,int $nights,int $adults,int $children,string $board) {
        $out=['unit_rate'=>[],'property_rate'=>[]];
        if($date==='')return $out;
        $ids=get_posts(['post_type'=>'property-rates','post_status'=>'publish','fields'=>'ids',
            'posts_per_page'=>101,'suppress_filters'=>true,
            'meta_query'=>[['key'=>'prop_related_property_id','value'=>(string)$property,'compare'=>'=']]]);
        if(count($ids)>100)return new WP_Error('stay_layer_rate_limit');
        foreach($ids as $id){
            $id=(int)$id;$linked=(int)get_post_meta($id,'prop_related_unit_id',true);
            $scope=(string)get_post_meta($id,'mad4b_rate_scope_v1',true);
            $type=null;
            if($unit&&$linked===$unit&&in_array($scope,['','unit'],true))$type='unit_rate';
            elseif(!$linked&&$scope==='property')$type='property_rate';
            if(!$type)continue;
            // Self-provider rates must not relabel supplier proposals as company prices.
            $supplierProposal=get_post_meta($id,'_shared_generation_supplier_proposal',true);
            $simulated=get_post_meta($id,'_mad4b_simulated_supplier_evidence',true);
            if(($supplierProposal!==''&&$supplierProposal!==false&&$supplierProposal!==null)
                ||($simulated!==''&&$simulated!==false&&$simulated!==null))continue;
            $sheetProposal=get_post_meta($id,'_mad4b_supplier_sheet_proposal_v1',true);
            if(is_array($sheetProposal) && (
                empty($sheetProposal['supplier_contract_verified'])||
                empty($sheetProposal['nightly_basis_verified'])||
                empty($sheetProposal['cost_receipt_approved'])))continue;
            if((string)get_post_meta($id,'_mad4b_supplier_sheet_quote_unapproved_v1',true)==='1')
                continue;
            $foreign=false;
            foreach(['provider_id','supplier_id','_mad4b_provider_id'] as $ownerKey){
                $owner=(string)get_post_meta($id,$ownerKey,true);
                if($owner!==''&&$owner!=='self'){$foreign=true;break;}
            }
            if($foreign)continue;
            if(preg_match('/\bQA\s+ONLY\b/i',(string)get_the_title($id))
                || in_array((string)get_post_meta($id,'_mad4b_is_test_data',true),['1','yes','true'],true)
                || in_array((string)get_post_meta($id,'_booking_experience_qa_pricing_fixture',true),['1','yes','true'],true))
                continue;
            $stop=strtolower((string)get_post_meta($id,'stop_sale',true));
            // Match Accommodation Booking Core, which treats any non-empty value
            // other than "0" as Stop Sale. Unknown states must not imply availability.
            if($stop!=='0')continue;
            $window=self::effectiveRateWindow($id);
            if(empty($window['valid']))continue;
            $from=$window['start'];$to=$window['end'];
            if($date<$from||$date>$to)continue;
            $quotedOcc=(string)get_post_meta($id,'_mad4b_supplier_sheet_occupancy_v1',true);
            if($quotedOcc!==''){
                if(($quotedOcc==='double'&&($adults!==2||$children!==0))||
                    ($quotedOcc==='triple'&&($adults!==3||$children!==0))||
                    !in_array($quotedOcc,['double','triple'],true))continue;
            }
            $checkout=(new DateTimeImmutable($date,new DateTimeZone('UTC')))->modify('+'.$nights.' days');
            // Native abk_accommodation_shadow_quote requires checkout <= valid_to.
            // Do not preview a rate that the existing quote provider would reject.
            if(!$checkout||$checkout->format('Y-m-d')>$to)continue;
            $min=(int)get_post_meta($id,'prop_min_nights',true);
            $max=(int)get_post_meta($id,'prop_max_nights',true);
            $maxAdults=(int)get_post_meta($id,'prop_max_adults',true);
            $maxChildren=get_post_meta($id,'prop_max_children',true);
            if(($min&&$nights<$min)||($max&&$nights>$max)
               ||($maxAdults&&$adults>$maxAdults)||($maxChildren!==''&&$children>(int)$maxChildren))continue;
            $boardId=(int)get_post_meta($id,'prop_board_type',true);
            if($boardId){
                $term=get_term($boardId,'board-type');
                if(!($term instanceof WP_Term)||$term->slug!==$board)continue;
            }else continue; // Rate meal inclusion must be explicit; do not invent it.
            $row=self::source($id,$type,$property,$unit);
            if(is_wp_error($row))return $row;
            if(!$row)continue;
            $row['valid_from']=$from;$row['valid_to']=$to;$row['board_type']=$term->slug;
            $out[$type][]=$row;
        }
        return $out;
    }
    /**
     * In-memory source arbitration. The cost lane is reference-only until
     * the original supplier-approval and native Unit booking providers certify it.
     * An explicit Rate must not receive occupancy/room-grade weights a second time.
     */
    public static function choose(array $sources) {
        if(array_diff(array_keys($sources),self::PRIORITY))
            return new WP_Error('stay_layer_unknown_price_source');
        $selling=null;$cost=null;$counts=[];$directCostIgnored=false;
        foreach(self::PRIORITY as $key){
            $rows=$sources[$key]??[];
            if(!is_array($rows)||count($rows)>1)
                return new WP_Error('stay_layer_overlapping_rates',$key);
            $counts[$key]=count($rows);
            if(!$rows)continue;
            $source=$rows[0];
            if(!is_array($source)||
               !in_array($source['price_kind']??null,['sell','cost'],true))
                return new WP_Error('stay_layer_source_kind_invalid');
            if($source['price_kind']==='sell' && $selling===null)
                $selling=$source;
            if($source['price_kind']==='cost'&&
               in_array($key,self::COST_PRIORITY,true)&&$cost===null)
                $cost=$source;
            if($source['price_kind']==='cost'&&
               !in_array($key,self::COST_PRIORITY,true))
                $directCostIgnored=true;
        }
        return ['sell'=>$selling,'cost_reference'=>$cost,'source_counts'=>$counts,
           'sell_source_priority'=>self::PRIORITY,'cost_source_priority'=>self::COST_PRIORITY,
           'direct_cost_not_authoritative'=>$directCostIgnored,
           'cost_authority'=>false,'checkout_authority'=>false,
           'source_rule'=>'unit_rate_then_property_rate_then_direct_for_indicative_sell'];
    }
    public static function preview(array $input) {
        if(array_diff(array_keys($input),['property_id','unit_id','checkin','nights','adults','children','board_type']))
            return new WP_Error('stay_layer_unknown_field');
        foreach($input as $value)if(!is_scalar($value)||is_bool($value))
            return new WP_Error('stay_layer_input_type_invalid');
        $property=filter_var($input['property_id']??null,FILTER_VALIDATE_INT,
            ['options'=>['min_range'=>1]]);
        $unit=filter_var($input['unit_id']??0,FILTER_VALIDATE_INT,
            ['options'=>['min_range'=>0]]);
        if($property===false||$unit===false)return new WP_Error('stay_layer_entity_id_invalid');
        if(get_post_type($property)!=='properties'||get_post_status($property)!=='publish')
            return new WP_Error('stay_layer_property_not_published');
        if($unit){$link=self::unit_link($property,$unit);if(is_wp_error($link))return $link;}
        $date=(string)($input['checkin']??'');
        if($date!==''&&!self::date_valid($date))return new WP_Error('stay_layer_date_invalid');
        $nights=filter_var($input['nights']??1,FILTER_VALIDATE_INT,['options'=>['min_range'=>1,'max_range'=>365]]);
        $adults=filter_var($input['adults']??2,FILTER_VALIDATE_INT,['options'=>['min_range'=>1,'max_range'=>50]]);
        $children=filter_var($input['children']??0,FILTER_VALIDATE_INT,['options'=>['min_range'=>0,'max_range'=>50]]);
        if($nights===false||$adults===false||$children===false)return new WP_Error('stay_layer_occupancy_invalid');
        if($unit){
            $capacity=(int)get_post_meta($unit,'unit_max_occupancy',true);
            if($capacity>0&&$adults+$children>$capacity)
                return new WP_Error('stay_layer_unit_capacity_exceeded');
        }
        if(!class_exists('MAD4B_Hotel_Meal_Base_Policy_V1'))
            return new WP_Error('stay_layer_meal_policy_unavailable');
        $policy=MAD4B_Hotel_Meal_Base_Policy_V1::resolve($property);
        if(is_wp_error($policy))return $policy;
        $inheritedBoard=(string)$policy['base_board'];
        $board=(string)($input['board_type']??'');
        if($board!==''){
            if(!preg_match('/^[a-z0-9-]{2,50}$/D',$board)||!get_term_by('slug',$board,'board-type'))
                return new WP_Error('stay_layer_board_invalid');
        }else $board=$inheritedBoard;
        $rates=self::rate_sources($property,$unit,$date,$nights,$adults,$children,$board);
        if(is_wp_error($rates))return $rates;
        $sources=['unit_rate'=>$rates['unit_rate'],'unit_direct'=>[],
            'property_rate'=>$rates['property_rate'],'property_direct'=>[]];
        $diagnostics=[];
        if($unit){
            $direct=self::source($unit,'unit_direct',$property,$unit);
            if(is_wp_error($direct))$diagnostics['unit_direct']=$direct->get_error_code();
            elseif($direct)$sources['unit_direct'][]=$direct;
        }
        $direct=self::source($property,'property_direct',$property,$unit);
        if(is_wp_error($direct))$diagnostics['property_direct']=$direct->get_error_code();
        elseif($direct)$sources['property_direct'][]=$direct;
        $choice=self::choose($sources);
        if(is_wp_error($choice))return $choice;
        $chosen=$choice['sell'];
        $cost=$choice['cost_reference'];
        return [
            'contract'=>'mad4b.stay-layered-price.v1','provider_id'=>'self',
            'property_id'=>$property,'unit_id'=>$unit?:null,'checkin'=>$date?:null,
            'nights'=>$nights,'adults'=>$adults,'children'=>$children,
            'meal_base'=>$inheritedBoard,'requested_board'=>$board,
            'selected_source'=>$chosen['source']??null,'price'=>$chosen,
            'source_counts'=>$choice['source_counts'],'source_priority'=>self::PRIORITY,
            'cost_source_priority'=>self::COST_PRIORITY,
            'cost_reference'=>$cost?[
                'source'=>$cost['source'],'rate_id'=>$cost['id'],
                'amount'=>$cost['amount'],'currency'=>$cost['currency'],
                'price_unit'=>$cost['price_unit'],
                'approval_status'=>'supplier_cost_receipt_and_unit_quote_required',
                'cost_authority'=>false,'checkout_authority'=>false
            ]:null,
            'rate_cost_preferred_over_direct'=>$cost!==null,
            'direct_cost_not_authoritative'=>$choice['direct_cost_not_authoritative'],
            'cost_reference_is_not_selling_price'=>true,
            'invalid_direct_source_diagnostics'=>$diagnostics,
            'price_status'=>$chosen?'indicative':'unpriced_or_cost_only',
            'meal_base_explicit_in_rate'=>$chosen&&in_array($chosen['source'],['unit_rate','property_rate'],true),
            'meal_inclusion_verified'=>false,
            'availability_verified'=>false,'checkout_ready'=>false,
            'quote_authorized'=>false,'mutation_performed'=>false,
        ];
    }
}
}
function mad4b_stay_layered_price_preview_v1($input) {
    return MAD4B_Stay_Layered_Pricing_V1::preview((array)$input);
}
add_filter('mad4b/pricing_registry/definitions',static function($entries){
    $entries['accommodation_layered']=[
        'domain'=>'accommodation','capabilities'=>['dated','unit','property','price_only'],
        'dependency'=>'mad4b_stay_layered_price_preview_v1','source_type'=>'policy'];
    return $entries;
});
add_filter('mad4b/pricing_registry/quote_handlers',static function($handlers){
    $handlers['accommodation_layered']=[
        'domain'=>'accommodation','callback'=>'mad4b_stay_layered_price_preview_v1','argument'=>'array'];
    return $handlers;
});
