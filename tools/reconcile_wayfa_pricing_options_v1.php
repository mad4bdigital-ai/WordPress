<?php
/**
 * Controlled Wayfatours options reconciliation for source-defined pricing policies.
 * Use via: wp eval-file ... (read-only by default).
 * Apply requires MAD4B_WAYFA_PRICING_PARITY_APPLY=yes after independent backup.
 *
 * No price/meta/rate, orders, WPML translations, WooCommerce/Stripe settings
 * or customer data is changed. Every preflight must pass before any writes.
 */
defined('ABSPATH') || exit;
$apply=getenv('MAD4B_WAYFA_PRICING_PARITY_APPLY')==='yes';
$deny=static function($reason)use($apply){
    echo wp_json_encode(['status'=>'BLOCKED','reason'=>$reason,
        'applied'=>false,'requested_apply'=>$apply]).PHP_EOL;
    return;
};
$allowedSite='a3a7cc36-db7e-4a14-8456-b41857def05c';
if(untrailingslashit(home_url('/'))!=='https://wayfatours.com'
    || wp_get_environment_type()!=='production'
    || !class_exists('MAD4B_SCP_Site_Profile',false)
    || !MAD4B_SCP_Site_Profile::configured()
    || MAD4B_SCP_Site_Profile::site_uuid()!==$allowedSite
    || !class_exists('MAD4B_Tenant_Pricing_Options_Parity_V1',false)
    || !MAD4B_Tenant_Pricing_Options_Parity_V1::eligible()
    || !function_exists('shared_product_pricing_setting')
    || !class_exists('MAD4B_Unified_Booking_Fulfillment_V2',false)
    || !class_exists('MAD4B_Pricing_Currency_Configuration_V1',false)
    || !class_exists('MAD4B_JetEngine_Pricing_Dimensions_V1',false)){
    $deny('site_identity_or_required_runtime_missing');return;
}
$pages=jet_engine()->options_pages->registered_pages??[];
$p=$pages['etg-dynamic-pricing-controls']??null;
if(!is_object($p)||$p->storage_type!=='separate'
    ||count((array)$p->page['fields'])!==37
    ||!isset($p->page['fields']['booking_fulfillment_mode'])){
    $deny('native_pricing_page_schema_not_ready');return;
}
$finance=[
    'tax_price_basis'=>'inclusive',
    'discount_tax_order'=>'before_tax',
    'payment_fee_taxable'=>'no',
    'payment_fee_bearer'=>'merchant',
    'discount_scope'=>'base_only',
    'refund_allocation'=>'line_based',
    'refund_payment_fee'=>'nonrefundable',
];
$subFields=(array)($p->page['fields']['shared_mode_policy_rows']['fields']??[]);
foreach($finance as $key=>$value){
    $choices=(array)($subFields[$key]['options']??[]);
    if(!array_key_exists($value,$choices)){
        $deny('unsupported_source_financial_policy_'.$key);return;
    }
}
$rowsOption=$p->get_separate_option_name('shared_mode_policy_rows');
$currentRows=get_option($rowsOption,null);
if(!is_array($currentRows)||count($currentRows)!==4){
    $deny('unexpected_policy_rows');return;
}
$normalized=[];
$seen=[];
foreach($currentRows as $row){
    if(!is_array($row)){$deny('malformed_mode_row');return;}
    $mode=$row['mode']??'';
    if(!in_array($mode,['tour','activity','extra_trip','cruise_tour'],true)||isset($seen[$mode])){
        $deny('duplicate_or_unknown_mode');return;
    }
    $seen[$mode]=true;
    if(($row['enabled']??null)!=='1' || ($row['strategy']??null)!=='rate_occupancy'){
        $deny('unrecognized_mode_strategy');return;
    }
    foreach($finance as $key=>$value){
        if(array_key_exists($key,$row)&&$row[$key]!==$value){
            $deny('existing_financial_policy_mismatch_'.$key);return;
        }
        if(!array_key_exists($key,$row))$row[$key]=$value;
    }
    $normalized[]=$row;
}
if(count($seen)!==4){$deny('incomplete_mode_coverage');return;}
$boolDefaults=[
    'pricing_engine_enabled'=>'false',
    'booking_enabled'=>'false',
    'require_availability'=>'false',
    'allow_manual_overrides'=>'false',
    'stop_sale_global'=>'false'
];
$allDefaults=$boolDefaults+[
    'booking_fulfillment_mode'=>'inquiry_only',
    'shared_price_card_date_mode'=>'active_today',
];
$checks=[];
$changes=[];
foreach($allDefaults as $field=>$value){
    $option=$p->get_separate_option_name($field);
    $old=get_option($option,null);
    if($old!==null && $old!==$value){
        $deny('existing_explicit_option_conflict_'.$field);return;
    }
    $checks[$option]=['old'=>$old,'new'=>$value];
    if($old===null)$changes[$option]=$value;
}
$existingUnits=get_option('abk-selling-options',null);
$desiredUnits=['per_person','per_room_night','per_cabin_night','per_person_night','per_stay'];
if($existingUnits!==null
    && (!is_array($existingUnits) || ($existingUnits['allowed_price_units']??null)!==$desiredUnits)){
    $deny('existing_tenant_selling_units_conflict');return;
}
$currencyKey='mad4b_pricing_currency_configuration_v1';
$currencyExisting=get_option($currencyKey,null);
if($currencyExisting!==null
    &&(!is_array($currencyExisting)
      ||($currencyExisting['mode']??null)!=='inherit_woocommerce'
      ||($currencyExisting['picker']??null)!=='wcml_only'
      ||($currencyExisting['allowed']??null)!==[])){
    $deny('existing_tenant_currency_authoring_config_must_be_preserved');return;
}
$currency=MAD4B_Pricing_Currency_Configuration_V1::wcml();
if(($currency['base_currency']??'')!=='USD'
    || !($currency['enabled']??false)
    || $currency['currencies']!==['USD','EUR','EGP','GBP']){
    $deny('market_currencies_not_matching_tenant_contract');return;
}
$mode=MAD4B_Booking_Fulfillment_Policy_V1::global_mode();
$stripe=get_option('woocommerce_stripe_settings',[]);
if(!is_array($stripe)||($stripe['enabled']??'no')==='yes'||$mode!=='inquiry_only'){
    $deny('booking_or_payment_precondition_not_safe');return;
}
$expectedRowsHash='4f8458dd11f83602656a233ca5f5002ef3233e8716f371ca1b2777a49ad965f1';
$actualRowsHash=hash('sha256',serialize($currentRows));
if($actualRowsHash!==$expectedRowsHash && hash('sha256',serialize($normalized))!==$actualRowsHash){
    $deny('source_reconciliation_concurrency_or_unreviewed_policy');return;
}
$planned=array_keys($changes);
if($currentRows!==$normalized)$planned[]=$rowsOption;
if($existingUnits===null)$planned[]='abk-selling-options';
if($currencyExisting===null)$planned[]=$currencyKey;
$result=[
    'contract'=>'mad4b.wayfa-pricing-controls-reconciliation.v1',
    'status'=>$apply?'applying':'dry_run_ready',
    'site_uuid'=>$allowedSite,'mode'=>$mode,
    'pricing_engine_enabled_before'=>get_option($p->get_separate_option_name('pricing_engine_enabled'),null),
    'target_fields'=>count($p->page['fields']),
    'source_financial_defaults'=>$finance,
    'planned_option_keys'=>$planned,
    'preserve_source_mode_rows'=>count($currentRows),
    'source_currencies'=>$currency['currencies'],
    'source_dimension_mode'=>MAD4B_JetEngine_Pricing_Dimensions_V1::current()['mode'],
    'options_changed'=>false,
    'pricing_changed'=>false,'checkout_activated'=>false
];
if(!$apply){echo wp_json_encode($result,JSON_UNESCAPED_SLASHES).PHP_EOL;return;}
foreach($changes as $option=>$value)
    if(!add_option($option,$value,'',false)){$deny('add_option_failed_'.$option);return;}
if($currentRows!==$normalized && !update_option($rowsOption,$normalized,false)){
    $deny('mode_policy_update_failed');return;
}
if($existingUnits===null && !add_option('abk-selling-options',
    ['allowed_price_units'=>$desiredUnits],'',false)){
    $deny('selling_unit_option_create_failed');return;
}
if($currencyExisting===null
    && !add_option($currencyKey,[
        'mode'=>'inherit_woocommerce',
        'picker'=>'wcml_only',
        'allowed'=>[],
        'revision'=>1,
        'updated_at'=>gmdate('c'),
        'scope'=>'new_pricing_drafts_only',
    ],'',false)){
    $deny('currency_authoring_option_create_failed');return;
}
foreach($allDefaults as $key=>$v){
    if(get_option($p->get_separate_option_name($key),null)!==$v){
        $deny('postcondition_mismatch_'.$key);return;
    }
}
if(get_option($rowsOption,null)!==$normalized
    || get_option('abk-selling-options',null)['allowed_price_units']!==$desiredUnits
    || MAD4B_Pricing_Currency_Configuration_V1::current()['picker']!=='wcml_only'
    || MAD4B_Booking_Fulfillment_Policy_V1::global_mode()!=='inquiry_only'){
    $deny('postcondition_policy_or_authoring_mismatch');return;
}
$result['status']=$planned?'applied_and_readback_verified':'already_applied_and_verified';
$result['options_changed']=(bool)$planned;
$result['current_mode_rows_hash']=hash('sha256',serialize($normalized));
$result['currency_new_draft_choice_count']=count(
    MAD4B_Pricing_Currency_Configuration_V1::authoring_choices());
$result['booking_enabled_effective']=shared_product_pricing_setting('booking_enabled');
$result['pricing_enabled_effective']=shared_product_pricing_setting('pricing_engine_enabled');
echo wp_json_encode($result,JSON_UNESCAPED_SLASHES).PHP_EOL;
