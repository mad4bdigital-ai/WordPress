<?php
/**
 * Plugin Name: MAD4B Rail Fare — WooCommerce Settings Bridge
 * Version: 0.1.0
 * Description: Rail supplier quote settings in the existing Booking Experience tab. No price or checkout write.
 */
defined('ABSPATH')||exit;

final class MAD4B_Rail_Woo_Settings_V1 {
 const OPTION='mad4b_rail_woo_policy_v1';
 static function defaults(): array {
  return [
   'display_unverified'=>'on_request',
   'currency_conflict'=>'hold_for_review',
   'supplier_pricing_rule_kind'=>'not_configured',
   'supplier_pricing_rule_value'=>'',
   'supplier_proof'=>'required',
   'auto_publish'=>'never',
   'auto_checkout'=>'never'
  ];
 }
 static function policy(): array {
  $p=get_option(self::OPTION,[]);
  if(!is_array($p))$p=[];
  $s=array_replace(self::defaults(),array_intersect_key($p,self::defaults()));
  foreach(self::defaults() as $k=>$def){
    if($k==='supplier_pricing_rule_value')continue;
    if(!in_array($s[$k],match($k){
      'display_unverified'=>['on_request','hide'],
      'currency_conflict'=>['hold_for_review','original_only'],
      'supplier_pricing_rule_kind'=>['not_configured','markup_percent','target_margin_percent','fixed_profit'],
      'supplier_proof'=>['required'],'auto_publish'=>['never'],
      'auto_checkout'=>['never']},true))$s[$k]=$def;
  }
  $kind=$s['supplier_pricing_rule_kind'];
  $value=(string)($s['supplier_pricing_rule_value']??'');
  $good=$kind!=='not_configured' &&
    preg_match('/^(?:0|[1-9][0-9]{0,6})(?:\.[0-9]{1,4})?$/D',$value) &&
    (float)$value>0 &&
    ($kind==='fixed_profit'||(float)$value<=300) &&
    ($kind!=='target_margin_percent'||(float)$value<99);
  if(!$good){
    $s['supplier_pricing_rule_kind']='not_configured';
    $s['supplier_pricing_rule_value']='';
  }
  return $s;
 }
 static function is_staging(): bool {
  return home_url()==='https://tours.dev.mad4b.com' &&
   wp_get_environment_type()==='staging';
 }
 static function settings() {
  if(!self::is_staging()||!current_user_can('manage_woocommerce'))return;
  $s=self::policy();
  $base=strtoupper((string)get_option('woocommerce_currency','USD'));
  $display=function_exists('get_woocommerce_currency')?get_woocommerce_currency():$base;
  $tax=get_option('woocommerce_calc_taxes','no');
  $requests=(array)get_option('mad4b_rail_fare_quote_requests_v1',[]);
  $proofs=(array)get_option('mad4b_rail_supplier_quote_proofs_v1',[]);
  echo '<hr><h2>Rail Fare — Supplier Quotes &amp; WooCommerce</h2>';
  echo '<p>The Rail Fare engine uses WooCommerce settings for reporting and review; it never replaces the official operator quote or the approved Transport Rate.</p>';
  echo '<table class="widefat striped" style="max-width:970px"><tbody>';
  foreach([
    'WooCommerce base currency'=>$base,
    'Current WooCommerce presentation currency'=>$display,
    'Taxes enabled'=>$tax==='yes'?'Yes':'No',
    'Rail Quote Requests'=>count($requests),
    'Supplier Evidence Records'=>count($proofs),
    'Automatic Rate approval'=>'Disabled',
    'Automatic Woo Cart / Checkout'=>'Disabled'
   ] as $k=>$v)echo '<tr><th style="width:260px">'.esc_html($k).'</th><td>'.esc_html((string)$v).'</td></tr>';
  echo '</tbody></table>';
  wp_nonce_field('mad4b_rail_woo_policy','mad4b_rail_woo_nonce');
  echo '<table class="form-table"><tbody><tr><th>Unverified fares on frontend</th><td>';
  echo '<select name="mad4b_rail_woo[display_unverified]">';
  foreach(['on_request'=>'Show On request only','hide'=>'Hide fares entirely'] as $k=>$v)
    echo '<option value="'.esc_attr($k).'" '.selected($s['display_unverified'],$k,false).'>'.esc_html($v).'</option>';
  echo '</select><p class="description">No annual tariff or unapproved Rail Fare amount enters Woo pricing.</p></td></tr>';
  echo '<tr><th>Different supplier currency</th><td><select name="mad4b_rail_woo[currency_conflict]">';
  foreach(['hold_for_review'=>'Hold: conversion / approved quote required',
      'original_only'=>'Keep original currency in review, never checkout'] as $k=>$v)
   echo '<option value="'.esc_attr($k).'" '.selected($s['currency_conflict'],$k,false).'>'.esc_html($v).'</option>';
  echo '</select><p class="description">Store currency remains '.esc_html($base).
    '. Exchange rates, taxes and market pricing are delegated to existing Shared Product Core / WCML.</p></td></tr>';
  echo '<tr><th>Supplier cost → proposed selling price</th><td><select name="mad4b_rail_woo[supplier_pricing_rule_kind]">';
  foreach(['not_configured'=>'Not configured (block draft generation)',
    'markup_percent'=>'Markup % on supplier cost',
    'target_margin_percent'=>'Target profit margin % of selling price',
    'fixed_profit'=>'Fixed profit in supplier currency'] as $k=>$v)
   echo '<option value="'.esc_attr($k).'" '.selected($s['supplier_pricing_rule_kind'],$k,false).'>'.esc_html($v).'</option>';
  echo '</select> <input type="number" min="0.0001" max="9999999" step="0.0001" name="mad4b_rail_woo[supplier_pricing_rule_value]" value="'.esc_attr($s['supplier_pricing_rule_value']).'" style="width:120px">';
  echo '<p class="description">Explicit commercial pricing policy required before any verified supplier quote generates a Draft. No implicit zero-profit passthrough; percent margin must be less than 99.</p></td></tr>';
  echo '<tr><th>Supplier proof / Rate publication / Checkout</th><td>';
  echo '<strong>Supplier proof required; automatic approval, publication and checkout denied.</strong>';
  echo '<p class="description">These are safety invariants, not settings that can be disabled here.</p></td></tr>';
  echo '</tbody></table><p><a href="'.esc_url(admin_url('tools.php?page=mad4b-rail-fares')).'">Open Rail Fare Requests</a> | ';
  echo '<a href="'.esc_url(admin_url('tools.php?page=mad4b-rail-proofs')).'">Review supplier proof submissions</a> | ';
  echo '<a href="'.esc_url(admin_url('tools.php?page=mad4b-abela-2026')).'">Abela 2026 reference only</a></p>';
 }
 static function save() {
  if(!self::is_staging()||!current_user_can('manage_woocommerce'))return;
  $raw=$_POST['mad4b_rail_woo']??null;
  if(!is_array($raw))return;
  $nonce=(string)($_POST['mad4b_rail_woo_nonce']??'');
  if(!wp_verify_nonce(sanitize_text_field(wp_unslash($nonce)),'mad4b_rail_woo_policy'))
   return;
  $display=sanitize_key(wp_unslash((string)($raw['display_unverified']??'')));
  $currency=sanitize_key(wp_unslash((string)($raw['currency_conflict']??'')));
  if(!in_array($display,['on_request','hide'],true)||
     !in_array($currency,['hold_for_review','original_only'],true)){
    if(class_exists('WC_Admin_Settings'))WC_Admin_Settings::add_error('Rail Fare policy not saved: invalid setting.');
    return;
  }
  $policy=self::defaults();
  $policy['display_unverified']=$display;
  $policy['currency_conflict']=$currency;
  $kind=sanitize_key(wp_unslash((string)($raw['supplier_pricing_rule_kind']??'not_configured')));
  $value=sanitize_text_field(wp_unslash((string)($raw['supplier_pricing_rule_value']??'')));
  if(!in_array($kind,['not_configured','markup_percent','target_margin_percent','fixed_profit'],true)||
     ($kind==='not_configured' && $value!=='')||
     ($kind!=='not_configured' &&
      (!preg_match('/^(?:0|[1-9][0-9]{0,6})(?:\.[0-9]{1,4})?$/D',$value)||
       (float)$value<=0 ||
       ($kind!=='fixed_profit' && (float)$value>300)||
       ($kind==='target_margin_percent' && (float)$value>=99)))){
    if(class_exists('WC_Admin_Settings'))WC_Admin_Settings::add_error('Rail supplier pricing policy invalid or not configured.');
    return;
  }
  $policy['supplier_pricing_rule_kind']=$kind;
  $policy['supplier_pricing_rule_value']=$value;
  update_option(self::OPTION,$policy,false);
  if(self::policy()!==$policy&&class_exists('WC_Admin_Settings'))
    WC_Admin_Settings::add_error('Rail Fare policy readback mismatch.');
 }
 static function evaluate($supplierAmount,$supplierCurrency,$supplierVerified=false,$rateApproved=false) {
  $p=self::policy();
  $shop=strtoupper((string)get_option('woocommerce_currency','USD'));
  $display=function_exists('get_woocommerce_currency')?get_woocommerce_currency():$shop;
  $currency=strtoupper(trim((string)$supplierCurrency));
  $hasAmount=is_numeric($supplierAmount)&&(float)$supplierAmount>0;
  $currencyMismatch=$currency!==''&&$currency!==$shop;
  return [
   'contract'=>'mad4b.rail-woocommerce-eligibility.v1',
   'supplier_quote_reference_required'=>true,
   'base_store_currency'=>$shop,
   'current_presentation_currency'=>$display,
   'supplier_currency'=>$currency,
   'supplier_currency_differs'=>$currencyMismatch,
   'exchange_quote_needed'=>$currencyMismatch,
   'tax_policy'=>get_option('woocommerce_calc_taxes','no')==='yes'?'wc_tax_resolver_required':'wc_taxes_disabled',
   'supplier_verified'=>(bool)$supplierVerified,
   'native_rate_approved'=>(bool)$rateApproved,
   'review_amount_exists'=>$hasAmount,
   'customer_display'=>$hasAmount&&$supplierVerified&&$rateApproved&&!$currencyMismatch?
       'native_rate_provider_only':($p['display_unverified']==='hide'?'hidden':'on_request'),
   'woo_order_creation_authorized'=>false,
   'woo_checkout_authorized'=>false,
   'source_price_write_authorized'=>false
  ];
 }
}
add_action('woocommerce_settings_tabs_booking_experience',
 ['MAD4B_Rail_Woo_Settings_V1','settings'],70);
add_action('woocommerce_update_options_booking_experience',
 ['MAD4B_Rail_Woo_Settings_V1','save'],70);
