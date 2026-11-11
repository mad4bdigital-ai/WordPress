<?php
/** Generic rate identity and eligibility envelope. Read-only and domain-neutral. */
defined('ABSPATH') || exit;
if (!function_exists('mad4b_unit_rate_contract_v1')) {
function mad4b_unit_rate_contract_v1(array $row) {
    $int = static function($value) {
        $id=filter_var($value,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
        return $id===false||$id===null?null:(int)$id;
    };
    $property=$int($row['property_id']??null);
    $unit=$int($row['unit_id']??null);
    if(!$property||!$unit)return new WP_Error('unit_rate_identity_invalid');
    $kinds=['room','cabin','bed','seat','table','hall','space','ticket','slot','equipment','vehicle','other'];
    $unitKind=$row['unit_kind']??null;
    if(!is_string($unitKind)||!in_array($unitKind,$kinds,true))
        return new WP_Error('unit_rate_kind_invalid');
    $basis=$row['price_basis']??null;
    $bases=['per_room','per_room_night','per_cabin','per_cabin_night',
       'per_seat','per_table','per_ticket','per_slot','per_hall','per_space',
       'per_person','per_unit','per_use','per_day','per_hour','per_booking'];
    if(!is_string($basis)||!in_array($basis,$bases,true))
        return new WP_Error('unit_rate_basis_invalid');
    $quantity=$int($row['quantity']??null);
    if(!$quantity||$quantity>999)return new WP_Error('unit_rate_quantity_invalid');
    $rate=$row['rate_id']??null;
    if($rate!==null && ($rate=$int($rate))===null)return new WP_Error('unit_rate_id_invalid');
    $currency=$row['currency']??null;
    if(!is_string($currency)||!preg_match('/^[A-Z]{3}$/D',$currency))
        return new WP_Error('unit_rate_currency_invalid');
    $date=$row['service_date']??null;
    if(!is_string($date)||!preg_match('/^20\d{2}-\d{2}-\d{2}$/D',$date))
        return new WP_Error('unit_rate_date_invalid');
    $parsed=DateTimeImmutable::createFromFormat('!Y-m-d',$date,new DateTimeZone('UTC'));
    if(!$parsed || $parsed->format('Y-m-d')!==$date)return new WP_Error('unit_rate_date_invalid');
    $amount=$row['amount']??null;
    // Price is only a quotation receipt, not a raw database or browser-supplied value.
    if($amount!==null && (!is_numeric($amount)||!is_finite((float)$amount)||(float)$amount<0))
        return new WP_Error('unit_rate_amount_invalid');
    if($amount!==null && ($row['quote_authority']??null)!=='server_preview_verified')
        return new WP_Error('unit_rate_authority_missing');
    $availability=$row['availability']??'not_checked';
    if(!in_array($availability,['not_checked','unavailable','available_preview'],true))
        return new WP_Error('unit_rate_availability_invalid');
    return [
        'contract'=>'mad4b.unit-rate.shadow.v1',
        'property_id'=>$property,'unit_id'=>$unit,'unit_kind'=>$unitKind,
        'rate_id'=>$rate,'price_basis'=>$basis,'quantity'=>$quantity,
        'service_date'=>$date,'currency'=>$currency,'amount'=>$amount===null?null:round((float)$amount,2),
        'availability'=>$availability,'provider_id'=>$row['provider_id']??'self',
        'provider_authority'=>'inferred_self_unless_verified',
        'status'=>$amount===null?'requires_domain_quote':'preview_priced',
        'checkout_authority'=>false,'inventory_certified'=>false
    ];
}
}
if (!function_exists('mad4b_unit_rate_saved_property_link_v1')) {
function mad4b_unit_rate_saved_property_link_v1(int $propertyId,int $unitId,int $rateId) {
    if(!function_exists('get_post_type')||!function_exists('get_post_meta'))
        return new WP_Error('unit_rate_wp_context_unavailable');
    if(get_post_type($propertyId)!=='properties'||get_post_type($unitId)!=='units'
      ||get_post_type($rateId)!=='property-rates')
        return new WP_Error('unit_rate_post_type_mismatch');
    if((int)get_post_meta($rateId,'prop_related_property_id',true)!==$propertyId
       ||(int)get_post_meta($rateId,'prop_related_unit_id',true)!==$unitId)
        return new WP_Error('unit_rate_parent_mismatch');
    return ['property_id'=>$propertyId,'unit_id'=>$unitId,'rate_id'=>$rateId,
      'relation'=>'existing_property_rate','read_only'=>true];
}
}

/** Convert Unit Rate identity to a generic component, without asserting a sellable price. */
if (!function_exists('mad4b_unit_rate_component_shadow_v1')) {
function mad4b_unit_rate_component_shadow_v1(array $receipt,string $propertyKind='other',string $inclusion='optional') {
    if(($receipt['contract']??null)!=='mad4b.unit-rate.shadow.v1'
      ||($receipt['checkout_authority']??null)!==false
      ||($receipt['inventory_certified']??null)!==false)
        return new WP_Error('unit_rate_component_receipt_untrusted');
    if(!function_exists('shared_product_component_contract_v1'))
        return new WP_Error('unit_rate_component_contract_missing');
    if(!in_array($inclusion,['optional','included','alternative'],true))
        return new WP_Error('unit_rate_component_inclusion_invalid');
    // Never promote a projected unit-rate amount to a package total.
    // The eventual domain provider must certify pricing and inventory independently.
    return shared_product_component_contract_v1([
        'component_key'=>'unit-rate:'.$receipt['property_id'].':'.$receipt['unit_id'].':'.($receipt['rate_id']??0),
        'kind'=>'property_service','property_kind'=>$propertyKind,
        'product_id'=>$receipt['property_id'],'unit_id'=>$receipt['unit_id'],
        'unit_kind'=>$receipt['unit_kind'],'rate_id'=>$receipt['rate_id'],
        'source'=>['type'=>($receipt['rate_id']?'rate':'unresolved')],
        'inclusion_mode'=>$inclusion,'currency'=>$receipt['currency'],
        'price_unit'=>$receipt['price_basis'],'amount'=>null,
        'readiness'=>'requires_unit_quote'
    ]);
}
}
