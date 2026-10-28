<?php
/** Generic JetEngine glossary-backed price unit resolution. Lab only. */
if (!defined('ABSPATH') || !defined('SHARED_PRODUCT_LAB_ONLY') || !SHARED_PRODUCT_LAB_ONLY) return;
function shared_product_unit_glossary($post_type, $field_name) {
    // Backward-compatible lookup API; the editor field has moved to Transport Rates.
    if($post_type==='transportations' && $field_name==='trans_unit_type')
        return shared_product_unit_glossary('transport-rates','pricing_type');
    if (!function_exists('jet_engine') || !isset(jet_engine()->cpt->data,jet_engine()->glossaries->data)) return new WP_Error('unit_source_unavailable');
    $glossary_id = null;
    foreach ((array)jet_engine()->cpt->data->get_items() as $item) {
        if (($item['slug']??'')!==$post_type) continue;
        foreach ((array)maybe_unserialize($item['meta_fields']??[]) as $field) {
            if (($field['name']??'')===$field_name && ($field['options_source']??'')==='glossary') $glossary_id=(int)($field['glossary_id']??0);
        }
    }
    if (!$glossary_id) return new WP_Error('unit_field_not_glossary_backed');
    foreach ((array)jet_engine()->glossaries->data->get_items() as $item) {
        if ((int)($item['id']??0)!==$glossary_id) continue;
        $allowed=[];
        foreach ((array)maybe_unserialize($item['meta_fields']??[]) as $entry) {
            $key=$entry['value']??null;
            if(is_string($key)&&$key!==''&&sanitize_key($key)===$key)$allowed[$key]=(string)($entry['label']??$key);
        }
        if (!$allowed) return new WP_Error('unit_glossary_empty');
        return ['id'=>$glossary_id,'values'=>$allowed];
    }
    return new WP_Error('unit_glossary_missing');
}
function shared_product_resolve_price_unit($post_id) {
    $type=get_post_type($post_id);
    $map=['tour-rates'=>'unit_type','transportations'=>'trans_unit_type','transport-rates'=>'pricing_type'];
    if(!isset($map[$type]))return new WP_Error('unit_post_type_unsupported');
    // Parent product retains a read-only legacy unit during migration; the
    // glossary is now owned by Transport Rates, never by the parent editor.
    $schema_type=$type==='transportations'?'transport-rates':$type;
    $schema_field=$type==='transportations'?'pricing_type':$map[$type];
    $glossary=shared_product_unit_glossary($schema_type,$schema_field);
    if(is_wp_error($glossary))return $glossary;
    $value=get_post_meta($post_id,$map[$type],true);
    if(!is_string($value)||!isset($glossary['values'][$value]))return new WP_Error('unit_unconfigured_or_invalid');
    return ['unit'=>$value,'label'=>$glossary['values'][$value],'glossary_id'=>$glossary['id'],'field'=>$map[$type]];
}
/** Multiply a documented unit charge; never infer a number of rooms/cabins from traveler count. */
function shared_product_unit_extended_amount($unit_price,$unit,$quantities) {
    if (!is_numeric($unit_price)||!is_finite((float)$unit_price)||(float)$unit_price<0||!is_array($quantities))return new WP_Error('invalid_unit_price');
    $field=['per_person'=>'persons','per_cabin'=>'cabins','per_room'=>'rooms','per_vehicle'=>'vehicles','per_day'=>'days','per_trip'=>'trips','table'=>'tables'][$unit]??null;
    if (!$field) return new WP_Error('unit_requires_defined_strategy');
    $count=filter_var($quantities[$field]??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1,'max_range'=>999]]);
    if ($count===false||$count===null)return new WP_Error('unit_quantity_missing_or_invalid');
    return ['unit'=>$unit,'quantity'=>$count,'amount'=>round((float)$unit_price*$count,2)];
}
