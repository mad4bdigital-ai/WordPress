<?php
/** Reusable JetEngine Options Page provider. No website-specific slugs or prefixes. */
if (!defined('ABSPATH')) exit;
function shared_product_pricing_options_page() {
    if (!function_exists('jet_engine') || !isset(jet_engine()->options_pages->data)) return null;
    $matches=[];
    foreach ((array) jet_engine()->options_pages->data->get_items() as $page) {
        $fields=maybe_unserialize($page['meta_fields']??[]);
        if (!is_array($fields)) continue;
        $names=array_column($fields,'name');
        if (in_array('tax_percent',$names,true) && in_array('service_fee_percent',$names,true)
            && in_array('pricing_engine_enabled',$names,true)) $matches[]=$page;
    }
    return count($matches)===1?$matches[0]:null;
}
function shared_product_pricing_setting($key) {
    $allowed=['tax_percent','service_fee_percent','default_currency','pricing_engine_enabled','booking_enabled','require_availability','stop_sale_global','quote_ttl_minutes'];
    if (!in_array($key,$allowed,true)) return new WP_Error('setting_not_allowed');
    $page=shared_product_pricing_options_page();
    if (!$page) return new WP_Error('pricing_page_missing_or_ambiguous');
    $args=maybe_unserialize($page['args']??[]);
    $slug=$page['slug'];
    $separate=($args['storage_type']??'')==='separate';
    $option_key=$separate ? (!empty($args['option_prefix'])?$slug.'_'.$key:$key) : $slug;
    $sentinel='__shared_product_option_not_set__';
    $stored=get_option($option_key,$sentinel);
    $value=$separate?$stored:(is_array($stored)?($stored[$key]??$sentinel):$sentinel);
    if ($value===$sentinel || $value===null || $value==='') return new WP_Error('pricing_setting_not_configured');
    if (in_array($key,['tax_percent','service_fee_percent'],true)) {
        if (!is_numeric($value) || !is_finite((float)$value) || (float)$value<0 || (float)$value>100) return new WP_Error('invalid_percentage');
        return (float)$value;
    }
    if ($key==='quote_ttl_minutes') {
        if(!is_numeric($value)|| (int)$value<1 || (int)$value>1440) return new WP_Error('invalid_ttl');
        return (int)$value;
    }
    if ($key==='default_currency') {
        if(!is_string($value) || !preg_match('/^[A-Z]{3}$/D',strtoupper($value)))return new WP_Error('invalid_currency');
        return strtoupper($value);
    }
    return in_array($value,['1',1,true,'true','on'],true);
}
