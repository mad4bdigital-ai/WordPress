<?php
/** MAD4B commercial components: read-only shadow projection of existing authoritative previews. */
if (!defined('ABSPATH')) return;
if (!function_exists('shared_product_component_contract_v1')) {
function shared_product_component_contract_v1(array $row) {
    $kinds=['tour','accommodation','transportation','extra_tour','service_modifier','property_service'];
    $inclusions=['base','optional','included','alternative'];
    $kind=$row['kind']??'';
    $mode=$row['inclusion_mode']??'';
    if(!in_array($kind,$kinds,true)||!in_array($mode,$inclusions,true))
        return new WP_Error('component_contract_invalid_type');
    $source=$row['source']??null;
    if(!is_array($source)||!in_array($source['type']??null,['direct','rate','modifier','unresolved'],true))
        return new WP_Error('component_contract_invalid_source');
    $amount=$row['amount']??null;
    if($amount!==null && (!is_numeric($amount)||!is_finite((float)$amount)||(float)$amount<0))
        return new WP_Error('component_contract_invalid_amount');
    $currency=$row['currency']??null;
    if(!is_string($currency)||!preg_match('/^[A-Z]{3}$/D',$currency))
        return new WP_Error('component_contract_invalid_currency');
    $readiness=$row['readiness']??'unpriced';
    if(!in_array($readiness,['preview_priced','unpriced','requires_inventory','requires_unit_quote'],true))
        return new WP_Error('component_contract_invalid_readiness');
    if($readiness!=='preview_priced' && $amount!==null)
        return new WP_Error('component_contract_unapproved_amount');
    if($readiness==='preview_priced' && $amount===null)
        return new WP_Error('component_contract_missing_amount');
    $provider=$row['provider_id']??'self';
    if(!is_string($provider)||!preg_match('/^[a-zA-Z0-9_:-]{1,100}$/D',$provider))
        return new WP_Error('component_contract_invalid_provider');
    $identity=(int)($row['product_id']??0);
    if($identity<0)return new WP_Error('component_contract_invalid_product');
    $unitId=isset($row['unit_id'])?(int)$row['unit_id']:null;
    if($unitId!==null && $unitId<=0)return new WP_Error('component_contract_invalid_unit');
    $unitKind=$row['unit_kind']??null;
    $allowedKinds=['room','cabin','bed','seat','table','hall','space','ticket','slot','equipment','vehicle','other'];
    if($unitKind!==null && (!is_string($unitKind)||!in_array($unitKind,$allowedKinds,true)))
        return new WP_Error('component_contract_invalid_unit_kind');
    $propertyKind=$row['property_kind']??null;
    $allowedProperties=['accommodation','restaurant','museum','exhibition','conference_hall','event_venue','attraction','other'];
    if($propertyKind!==null&&(!is_string($propertyKind)||!in_array($propertyKind,$allowedProperties,true)))
        return new WP_Error('component_contract_invalid_property_kind');
    if($kind==='property_service'&&$propertyKind===null)
        return new WP_Error('component_contract_property_kind_required');
    return [
      'contract'=>'mad4b.commercial-component.shadow.v1',
      'component_key'=>(string)($row['component_key']??''),
      'kind'=>$kind,'product_id'=>$identity,
      'rate_id'=>isset($row['rate_id'])?(int)$row['rate_id']:null,
      'unit_id'=>$unitId,'unit_kind'=>$unitKind,'property_kind'=>$propertyKind,
      'provider_id'=>$provider,'provider_authority'=>'inferred_self_unless_verified',
      'source'=>$source,'inclusion_mode'=>$mode,'price_unit'=>$row['price_unit']??null,
      'amount'=>$amount===null?null:round((float)$amount,2),'currency'=>$currency,
      'readiness'=>$readiness,'checkout_authority'=>false
    ];
}
}
if (!function_exists('shared_product_composite_component_shadow_v1')) {
function shared_product_composite_component_shadow_v1($rate_id,$audience,$occupancy,array $options=[]) {
    if(!function_exists('shared_product_composite_quote'))
        return new WP_Error('component_shadow_composite_unavailable');
    $legacy=shared_product_composite_quote($rate_id,$audience,$occupancy,$options);
    if(is_wp_error($legacy))return $legacy;
    $currency=$legacy['currency']??null;
    $rows=[];
    $rows[]=['component_key'=>'base:'.(int)$rate_id,'kind'=>'tour','product_id'=>0,'rate_id'=>(int)$rate_id,
      'source'=>['type'=>'rate','rate_id'=>(int)$rate_id], 'inclusion_mode'=>'base',
      'price_unit'=>$legacy['base_price_unit']??null,'amount'=>$legacy['base_amount']??null,
      'currency'=>$currency,'readiness'=>'preview_priced'];
    foreach(($legacy['optional_rate_lines']??[]) as $line){
      $id=(int)($line['rate_id']??0);
      $rows[]=['component_key'=>'optional_rate:'.$id,'kind'=>'extra_tour',
        'product_id'=>(int)($line['tour_id']??0),'rate_id'=>$id,
        'source'=>['type'=>'rate','rate_id'=>$id],'inclusion_mode'=>'optional',
        'price_unit'=>$line['price_unit']??null,'amount'=>$line['amount']??null,
        'currency'=>$currency,'readiness'=>'preview_priced'];
    }
    foreach(($legacy['modifier_lines']??[]) as $i=>$line) {
      // Existing booking-modifiers contract supplies the converted line amount.
      $valid=isset($line['amount']) && is_numeric($line['amount']) && is_finite((float)$line['amount']) && (float)$line['amount']>=0;
      $rows[]=['component_key'=>'modifier:'.$i,'kind'=>'service_modifier','product_id'=>0,
        'source'=>['type'=>'modifier'],'inclusion_mode'=>'optional',
        'amount'=>$valid?$line['amount']:null,'currency'=>$currency,
        'readiness'=>$valid?'preview_priced':'unpriced'];
    }
    $components=[];$seen=[];$sum=0.0;$unknown=0;
    foreach($rows as $row){
      $c=shared_product_component_contract_v1($row);
      if(is_wp_error($c))return $c;
      if($c['component_key']===''||isset($seen[$c['component_key']]))return new WP_Error('component_shadow_duplicate_key');
      $seen[$c['component_key']]=true;$components[]=$c;
      if($c['amount']!==null)$sum+=$c['amount'];else $unknown++;
    }
    $legacyTotal=$legacy['preview_total']??null;
    $totalIsComparable=$unknown===0;
    $parity=$totalIsComparable && is_numeric($legacyTotal) && abs(round($sum,2)-round((float)$legacyTotal,2))<=0.01;
    return ['contract'=>'mad4b.composite-shadow.v1','parent_rate_id'=>(int)$rate_id,
      'currency'=>$currency,'components'=>$components,'component_count'=>count($components),
      'unpriced_component_count'=>$unknown,'priced_component_sum'=>round($sum,2),
      'legacy_preview_total'=>$legacyTotal,
      'parity_status'=>$totalIsComparable?($parity?'match':'mismatch'):'not_comparable_unpriced_modifier',
      'status'=>'shadow_only_no_checkout_authority','original_quote_unchanged'=>true];
}
}

/** Project a certified domain quote; never infer its rate amount or inventory. */
if (!function_exists('shared_product_component_domain_shadow_v1')) {
function shared_product_component_domain_shadow_v1(array $input) {
    $kind=$input['kind']??null;
    if(!in_array($kind,['accommodation','transportation'],true))
        return new WP_Error('component_domain_kind_invalid');
    $id=filter_var($input['product_id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
    $currency=$input['currency']??null;
    if(!$id||!is_string($currency)||!preg_match('/^[A-Z]{3}$/D',$currency))
        return new WP_Error('component_domain_identity_invalid');
    $mode=$input['inclusion_mode']??'optional';
    if(!in_array($mode,['included','optional','alternative'],true))
        return new WP_Error('component_domain_inclusion_invalid');
    $rate=isset($input['rate_id'])?filter_var($input['rate_id'],FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]):null;
    if($rate===false)return new WP_Error('component_domain_rate_invalid');
    $unitId=isset($input['unit_id'])?filter_var($input['unit_id'],FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]):null;
    if($unitId===false)return new WP_Error('component_domain_unit_invalid');
    if($kind==='accommodation' && $rate && $unitId===null)
        return new WP_Error('component_domain_accommodation_unit_required');
    // For real WP records, validate the recorded parent property and unit on a saved rate.
    if($kind==='accommodation' && $rate && function_exists('get_post_type')) {
        if(get_post_type($rate)!=='property-rates'
           || (int)get_post_meta($rate,'prop_related_property_id',true)!==(int)$id
           || (int)get_post_meta($rate,'prop_related_unit_id',true)!==(int)$unitId)
            return new WP_Error('component_domain_property_unit_rate_mismatch');
    }
    $quote=$input['domain_quote']??null;
    $state='requires_unit_quote';$amount=null;
    if($quote!==null) {
        if(!is_array($quote)||!in_array($quote['status']??null,
            ['preview_only_not_checkout_authority','nonbinding_transport_extra_preview'],true)
            ||($quote['currency']??null)!==$currency
            ||!isset($quote['amount'])||!is_numeric($quote['amount'])
            ||!is_finite((float)$quote['amount'])||(float)$quote['amount']<0)
            return new WP_Error('component_domain_quote_not_certified');
        $amount=$quote['amount'];$state='preview_priced';
    }
    $source=['type'=>$rate?'rate':'unresolved'];
    if($rate)$source['rate_id']=$rate;
    return shared_product_component_contract_v1([
        'component_key'=>$kind.':'.$id.':'.($rate?:0),
        'kind'=>$kind,'product_id'=>(int)$id,'unit_id'=>$unitId?:null,'rate_id'=>$rate?:null,
        'source'=>$source,'inclusion_mode'=>$mode,'amount'=>$amount,
        'currency'=>$currency,'price_unit'=>$quote['basis']??null,
        'readiness'=>$state
    ]);
}
}

/** Shadow-only sum of selected components; base already includes included components. */
if (!function_exists('shared_product_component_package_shadow_v1')) {
function shared_product_component_package_shadow_v1(array $components,array $selectedAlternatives=[]) {
    if(count($components)>100)return new WP_Error('package_shadow_too_many_components');
    $seen=[];$alternatives=[];$currency=null;$baseCount=0;$sum=0.0;$unpriced=[];$counted=[];
    foreach($components as $component){
        if(!is_array($component)||($component['contract']??null)!=='mad4b.commercial-component.shadow.v1'
            ||($component['checkout_authority']??null)!==false)
            return new WP_Error('package_shadow_untrusted_component');
        $key=$component['component_key']??'';
        if(!is_string($key)||$key===''||isset($seen[$key]))return new WP_Error('package_shadow_duplicate_component');
        $seen[$key]=true;
        $c=$component['currency']??null;
        if(!is_string($c)||!preg_match('/^[A-Z]{3}$/D',$c))return new WP_Error('package_shadow_invalid_currency');
        if($currency===null)$currency=$c;
        if($currency!==$c)return new WP_Error('package_shadow_currency_mismatch');
        $mode=$component['inclusion_mode']??null;
        if(!in_array($mode,['base','included','optional','alternative'],true))
            return new WP_Error('package_shadow_invalid_inclusion');
        if($mode==='base'&&++$baseCount>1)return new WP_Error('package_shadow_multiple_bases');
        if($mode==='alternative')$alternatives[$key]=true;
    }
    if(array_diff($selectedAlternatives,array_keys($alternatives))
       ||count($selectedAlternatives)!==count(array_unique($selectedAlternatives)))
        return new WP_Error('package_shadow_invalid_alternatives');
    // Multiple alternatives may exist in different groups. Their group-level selection
    // contract is not yet certified: reject combining multiple rather than guessing.
    if(count($selectedAlternatives)>1)return new WP_Error('package_shadow_alternatives_group_unverified');
    foreach($components as $component) {
        $key=$component['component_key'];$mode=$component['inclusion_mode'];
        if($mode==='included'||($mode==='alternative'&&!in_array($key,$selectedAlternatives,true)))continue;
        if(($component['readiness']??'')!=='preview_priced'||!is_numeric($component['amount']??null)
            ||!is_finite((float)$component['amount'])||(float)$component['amount']<0){
            $unpriced[]=$key;continue;
        }
        $sum+=(float)$component['amount'];$counted[]=$key;
    }
    return [
      'contract'=>'mad4b.package-shadow.v1','currency'=>$currency,
      'priced_subtotal'=>round($sum,2),'complete'=>count($unpriced)===0 && $baseCount===1,
      'unpriced_component_keys'=>$unpriced,'counted_component_keys'=>$counted,
      'base_count'=>$baseCount,'checkout_authority'=>false,
      'status'=>'shadow_only_not_checkout_authority'
    ];
}
}

/** Generic property unit/service shadow, independent of accommodation assumptions. */
if (!function_exists('shared_product_property_service_shadow_v1')) {
function shared_product_property_service_shadow_v1(array $input) {
    $property=filter_var($input['property_id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
    $unit=isset($input['unit_id'])?filter_var($input['unit_id'],FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]):null;
    $rate=isset($input['rate_id'])?filter_var($input['rate_id'],FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]):null;
    if(!$property||$unit===false||$rate===false)return new WP_Error('property_service_identity_invalid');
    $quote=$input['domain_quote']??null;
    // Generic property kinds have no certified pricing adapter yet. Preserve identity only.
    if(($input['property_kind']??null)!=='accommodation' && $quote!==null)
        return new WP_Error('property_service_quote_adapter_missing');
    $amount=null;$ready='requires_unit_quote';
    if($quote!==null) {
        if(!is_array($quote)||!in_array($quote['status']??null,
            ['preview_only_not_checkout_authority','nonbinding_transport_extra_preview'],true)
            ||($quote['currency']??null)!==($input['currency']??null)
            ||!isset($quote['amount'])||!is_numeric($quote['amount'])
            ||!is_finite((float)$quote['amount'])||(float)$quote['amount']<0)
            return new WP_Error('property_service_quote_not_certified');
        $amount=$quote['amount'];$ready='preview_priced';
    }
    // A real property-rate is an accommodation-only contract today.
    // Non-accommodation property services have no certified native rate adapter yet.
    if($rate && ($input['property_kind']??null)!=='accommodation')
        return new WP_Error('property_service_rate_adapter_missing');
    if($rate && function_exists('get_post_type')) {
        if(get_post_type($rate)!=='property-rates'
            || (int)get_post_meta($rate,'prop_related_property_id',true)!==(int)$property
            || (int)get_post_meta($rate,'prop_related_unit_id',true)!==(int)$unit)
            return new WP_Error('property_service_rate_link_mismatch');
    }
    return shared_product_component_contract_v1([
        'component_key'=>'property:'.$property.':'.($unit?:0).':'.($rate?:0),
        'kind'=>'property_service','property_kind'=>$input['property_kind']??null,
        'unit_kind'=>$input['unit_kind']??null,'product_id'=>(int)$property,
        'unit_id'=>$unit?:null,'rate_id'=>$rate?:null,
        'provider_id'=>$input['provider_id']??'self',
        'source'=>['type'=>$rate?'rate':'unresolved'],
        'inclusion_mode'=>$input['inclusion_mode']??'optional',
        'amount'=>$amount,'currency'=>$input['currency']??null,
        'price_unit'=>$quote['basis']??null,'readiness'=>$ready
    ]);
}
}
