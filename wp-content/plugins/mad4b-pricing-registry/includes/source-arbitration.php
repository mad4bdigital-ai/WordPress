<?php
if (!defined('ABSPATH')) exit;
/** Read-only adapter; canonical Shared Product Core remains the policy authority. */
final class MAD4B_Pricing_Source_Arbitration {
 public static function inspect($mode='') {
  if (!function_exists('shared_product_price_source_policy')) return new WP_Error('pricing_registry_policy_unavailable');
  $policy=shared_product_price_source_policy($mode);
  if (is_wp_error($policy)) return $policy;
  return ['contract'=>'mad4b.pricing-arbitration-inspection.v1','mode'=>$mode,'policy'=>$policy,'authority'=>'shared_product_price_source_policy','mutation_performed'=>false];
 }
 public static function choose(array $candidates,$mode='') {
  if (!function_exists('shared_product_resolve_price_sources')) return new WP_Error('pricing_registry_resolver_unavailable');
  $result=shared_product_resolve_price_sources($candidates,$mode);
  if (is_wp_error($result)) return $result;
  if (!is_array($result)||!in_array($result['source']??null,['direct','rate'],true)) return new WP_Error('pricing_registry_source_result_invalid');
  return ['contract'=>'mad4b.pricing-arbitration-result.v1','result'=>$result,'authority'=>'shared_product_resolve_price_sources','checkout_authorized'=>false];
 }
}
