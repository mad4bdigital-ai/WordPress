<?php
/**
 * MAD4B Hub: source-native price and rate discovery.
 *
 * Read-only provider adapters. This intentionally does not calculate a
 * WooCommerce payable price, import rates, or change provider definitions.
 */
defined('ABSPATH') || exit;
final class MAD4B_Hub_Price_Sources_V1 {
    const CONTRACT='mad4b.hub.pricing-sources.v1';
    const MAX_RATE_ROWS=100;
    const MAX_AMOUNT=1000000000;
    private static function fail($reason,$status=422) {
        return new WP_Error('mad4b_hub_price_'.$reason,$reason,
            array('status'=>$status,'contract'=>self::CONTRACT));
    }
    private static function money($raw) {
        if(!is_int($raw)&&!is_float($raw)&&!is_string($raw))return null;
        $s=trim((string)$raw);
        if(!preg_match('/^[0-9]{1,10}(?:\.[0-9]{1,4})?$/D',$s))return null;
        $x=(float)$s;
        return is_finite($x)&&$x>0&&$x<=self::MAX_AMOUNT?$s:null;
    }
    private static function currency($raw) {
        if(!is_string($raw))return null;
        // Historic Tourism saved lower-case ISO codes. Normalize CODE CASE
        // only; never perform exchange or substitute an unknown currency.
        $v=strtoupper(trim($raw));
        return preg_match('/^[A-Z]{3}$/D',$v)?$v:null;
    }
    private static function descriptors() {
        $builtin=array(
            'tours-and-activities'=>array(
                'direct'=>array('start_price','egyptians_start_price'),
                'currency'=>array('tour_base_currency','tour_egyptians_currency'),
                'rates'=>array('post_type'=>'tour-rates','parent_field'=>'related_tour_id',
                    'amount_fields'=>array('single_price','double_price','triple_price'),
                    'currency_field'=>'base_currency',
                    'start_field'=>'tour_rate_start_date','end_field'=>'tour_rate_end_date')),
            'properties'=>array(
                'direct'=>array('prop_start_price'),'currency'=>array('prop_base_currency','currency'),
                'rates'=>array('post_type'=>'property-rates','parent_field'=>'prop_related_property_id',
                    'amount_fields'=>array('base_amount'),'currency_field'=>'currency',
                    'start_field'=>'valid_from','end_field'=>'valid_to')),
            'transportations'=>array(
                'direct'=>array('trans_start_price','trans_sale_start_price'),
                'currency'=>array('trans_base_currency'),
                'rates'=>array('post_type'=>'transport-rates','parent_field'=>'source_post_id',
                    'amount_fields'=>array('base_amount'),'currency_field'=>'currency',
                    'start_field'=>'valid_from','end_field'=>'valid_to')),
            'product'=>array(
                'direct'=>array('_price','_regular_price'),
                'currency'=>array(), 'rates'=>null)
        );
        $rows=apply_filters('mad4b_hub_pricing_source_descriptors',$builtin);
        if(!is_array($rows))return $builtin;
        // An adapter extension must explicitly name safe price fields and
        // CPT rate owner relation. It cannot inject arbitrary SQL/callbacks.
        $valid=array();
        foreach(array_slice($rows,0,35,true) as $type=>$d){
            if(!is_string($type)||!preg_match('/^[a-z][a-z0-9_-]{1,49}$/D',$type)||
                !is_array($d))continue;
            $direct=self::keys($d['direct']??array());
            $curr=self::keys($d['currency']??array());
            if($direct===false||$curr===false)continue;
            $rates=$d['rates']??null;
            if($rates!==null){
                if(!is_array($rates) ||
                   !in_array('post_type',array_keys($rates),true) ||
                   !is_string($rates['post_type']) ||
                   !preg_match('/^[a-z][a-z0-9_-]{1,49}$/D',$rates['post_type']))
                    continue;
                foreach(array('parent_field','currency_field','start_field','end_field') as $k){
                    if(!is_string($rates[$k]??null)||
                       !preg_match('/^[a-z_][a-z0-9_]{1,69}$/D',$rates[$k]))continue 2;
                }
                $amount=self::keys($rates['amount_fields']??null);
                if($amount===false||!$amount)continue;
                $rates['amount_fields']=$amount;
            }
            $valid[$type]=array('direct'=>$direct,'currency'=>$curr,'rates'=>$rates);
        }
        return $valid;
    }
    private static function keys($list){
        if(!is_array($list)||count($list)>20)return false;
        $out=array();
        foreach($list as $key){
            if(!is_string($key)||!preg_match('/^[_a-z][a-z0-9_]{1,69}$/D',$key)||
               preg_match('/password|secret|token|private|customer|billing/i',$key))return false;
            $out[$key]=true;
        }
        return array_keys($out);
    }
    public static function discover() {
        $descriptors=self::descriptors();$types=array();
        foreach($descriptors as $name=>$cfg){
            $obj=get_post_type_object($name);
            $rate=$cfg['rates'];
            $types[]=array('post_type'=>$name,
              'registered'=>!!$obj,'public'=>!!($obj&&$obj->public),
              'direct_price_fields'=>$cfg['direct'],
              'currency_fields'=>$cfg['currency'],
              'rate_post_type'=>$rate['post_type']??null,
              'rate_price_fields'=>$rate['amount_fields']??array(),
              'adapter_provenance'=>'allowlisted_native_wordpress_meta');
        }
        return array('contract'=>self::CONTRACT,
          'pricing_registry_present'=>class_exists('MAD4B_Pricing_Source_Registry'),
          'native_pricing_provider_registry'=>class_exists('MAD4B_Pricing_Source_Registry')?
             MAD4B_Pricing_Source_Registry::snapshot():null,
          'supported_type_descriptors'=>$types,
          'pricing_data_tenant_policy'=>'read_only',
          'source_rates_validated_current'=>false,
          'checkout_authorized'=>false);
    }
    private static function rateRow($row,$cfg,$currencyHint) {
        $currency=self::currency(get_post_meta($row->ID,$cfg['currency_field'],true));
        if(!$currency)$currency=$currencyHint;
        $prices=array();
        foreach($cfg['amount_fields'] as $key){
            $v=self::money(get_post_meta($row->ID,$key,true));
            if($v!==null && $currency!==null)$prices[$key]=$v;
        }
        if(!$prices)return null;
        $blocked=(string)get_post_meta($row->ID,'stop_sale',true);
        $fixture=(string)get_post_meta($row->ID,'_booking_experience_qa_pricing_fixture',true);
        $test=(string)get_post_meta($row->ID,'_mad4b_is_test_data',true);
        return array('source_rate_id'=>(int)$row->ID,
          'source_rate_post_type'=>$row->post_type,'currency'=>$currency,
          'price_fields'=>$prices,'valid_from'=>strval(get_post_meta($row->ID,$cfg['start_field'],true)),
          'valid_until'=>strval(get_post_meta($row->ID,$cfg['end_field'],true)),
          'stop_sale'=>in_array(strtolower($blocked),array('1','yes','true','on'),true),
          'is_test_data'=>in_array(strtolower($test),array('1','yes','true','on'),true) ||
             in_array(strtolower($fixture),array('1','yes','true','on'),true),
          'authority'=>'native_provider_unverified_reference',
          'bookable'=>false);
    }
    public static function local($postId,$requestedType) {
        if(!is_int($postId)||$postId<1||!is_string($requestedType))
            return self::fail('product_identity_invalid');
        $definitions=self::descriptors();
        $d=$definitions[$requestedType]??null;
        $post=get_post($postId);$obj=get_post_type_object($requestedType);
        if(!$d||!$post||$post->post_type!==$requestedType ||
           $post->post_status!=='publish'||$post->post_password!=='' ||
           !$obj||!$obj->public)
            return self::fail('product_not_public_or_provider_missing',404);
        $curr=null;
        foreach($d['currency'] as $k){
            $curr=self::currency(get_post_meta($postId,$k,true));
            if($curr)break;
        }
        if($requestedType==='product'&&function_exists('get_woocommerce_currency')){
            $curr=self::currency(get_woocommerce_currency());
        }
        $direct=array();
        foreach($d['direct'] as $field){
            $value=self::money(get_post_meta($postId,$field,true));
            $fieldCurrency=$curr;
            if($requestedType==='tours-and-activities' &&
               $field==='egyptians_start_price'){
                $fieldCurrency=self::currency(get_post_meta($postId,
                    'tour_egyptians_currency',true));
            }
            if($value!==null&&$fieldCurrency!==null){
                $direct[]=array('field'=>$field,'amount'=>$value,'currency'=>$fieldCurrency,
                    'origin'=>'native_product_price_field',
                    'source_reference_only'=>true);
            }
        }
        $rates=array();$total=0;$cfg=$d['rates'];
        if($cfg && post_type_exists($cfg['post_type'])){
            $obj=get_post_type_object($cfg['post_type']);
            // Rate CPTs are frequently private data models even when
            // their parent product is public. Only the authenticated Hub
            // administrator can inspect their published rate references.
            if($obj && current_user_can('manage_options')){
                $ids=get_posts(array('post_type'=>$cfg['post_type'],
                 'post_status'=>'publish','fields'=>'ids','posts_per_page'=>self::MAX_RATE_ROWS+1,
                 'orderby'=>'ID','order'=>'ASC','suppress_filters'=>true,
                 'meta_query'=>array(array('key'=>$cfg['parent_field'],
                     'value'=>(string)$postId,'compare'=>'='))));
                $total=count($ids);
                if($total>self::MAX_RATE_ROWS)
                    return self::fail('rate_limit_exceeded',409);
                foreach($ids as $rateId){
                    $rp=get_post($rateId);
                    if(!$rp||$rp->post_status!=='publish')continue;
                    $rate=self::rateRow($rp,$cfg,$curr);
                    if($rate!==null)$rates[]=$rate;
                }
            }
        }
        return array('contract'=>self::CONTRACT,'source_mode'=>'native_product',
            'post_type'=>$requestedType,'source_post_id'=>$postId,
            'direct_starting_prices'=>$direct,'rates'=>$rates,
            'rate_count'=>count($rates),'total_matched_rates'=>$total,
            'no_valid_price_candidate'=>!$direct&&!$rates,
            'all_rates_current_certified'=>false,
            'checkout_authorized'=>false);
    }
    public static function signedHistorical($sourceCompany,$sourceTourId) {
        if($sourceCompany!=='tourism-almallahgroup'||
           !is_int($sourceTourId)||$sourceTourId<1)
           return self::fail('signed_historical_identity');
        // This snapshot was fetched via P-256 signed reference transport and
        // has 364 preexisting historic rate records. It is NOT live inventory.
        $data=get_option('mad4b_nile_source_reference_v1',array());
        $catalog=$data['nile_cruise_catalog.read']['items']??array();
        $exists=false;
        foreach($catalog as $item){
            if(($item['source_tour_id']??null)===$sourceTourId &&
               ($item['source_company']??null)===$sourceCompany){
                $exists=true;break;
            }
        }
        if(!$exists)return self::fail('signed_catalog_product_not_found',404);
        $history=$data['nile_rate_history.read']['items']??array();
        if(!is_array($history)||count($history)>1000)
            return self::fail('signed_rate_snapshot_unavailable',409);
        $rates=array();
        foreach($history as $item){
            if(($item['source_tour_id']??null)!==$sourceTourId ||
               ($item['source_company']??null)!==$sourceCompany)continue;
            if(count($rates)>=self::MAX_RATE_ROWS)
                return self::fail('historical_product_rate_limit_exceeded',409);
            if(($item['reference_only']??false)!==true ||
                ($item['for_sale']??true)!==false ||
                ($item['checkout_enabled']??true)!==false ||
                ($item['validated_current_price']??true)!==false)
                return self::fail('historical_source_status_unexpected',409);
            $fields=array();
            $raw=$item['occupancy_price_reference']??array();
            if(!is_array($raw))return self::fail('historical_prices_malformed',409);
            $allowed=array('single_price','double_price','triple_price',
                'egyptians_single_price','egyptians_double_price','egyptians_triple_price');
            foreach($allowed as $k){
                $v=self::money($raw[$k]??null);
                if($v!==null)$fields[$k]=$v;
            }
            $currency=self::currency($item['currency']??null);
            $egyptian=self::currency($item['egyptians_currency']??null);
            if(!$currency&&!$egyptian)continue;
            $rates[]=array('source_rate_id'=>$item['source_rate_id'],
                'source_rate_post_type'=>'signed_historical_nile_rate',
                'currency'=>$currency,
                'egyptians_currency'=>$egyptian,
                'price_fields'=>$fields,
                'valid_from'=>array_values((array)($item['source_date_values']??array())),
                'valid_until'=>$item['source_end_date']??null,
                'rate_plan_references'=>$item['rate_plan_references']??array(),
                'authority'=>'signed_source_historical_reference_only',
                'validated_current_price'=>false,
                'requires_manual_update'=>true,
                'bookable'=>false);
        }
        return array('contract'=>self::CONTRACT,
            'source_mode'=>'signed_historical_nile_snapshot',
            'source_company'=>$sourceCompany,'source_post_id'=>$sourceTourId,
            'direct_starting_prices'=>array(),
            'rates'=>$rates,'rate_count'=>count($rates),
            'direct_prices_remote_contract_missing'=>true,
            'all_rates_current_certified'=>false,
            'checkout_authorized'=>false);
    }
}
