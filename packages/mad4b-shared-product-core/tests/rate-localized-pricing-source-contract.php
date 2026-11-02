<?php
/** Hermetic source-level guards. End-to-end WPML create/cleanup runs on Staging. */
$root=dirname(__DIR__).'/mu-plugins/shared-product-core/';
$generation=(string)file_get_contents($root.'pricing/rate-generation.php');
$admin=(string)file_get_contents($root.'admin/rate-generation.php');
$batch=(string)file_get_contents($root.'pricing/rate-source-batch.php');
$assignment=(string)file_get_contents($root.'pricing/accommodation-assignment.php');
$bookingSource=(string)file_get_contents($root.'pricing/booking-configuration-v2.php');
$rateBatch=(string)file_get_contents($root.'pricing/rate-source-batch.php');
$sourcePriority=(string)file_get_contents($root.'pricing/source-resolution-policy.php');
$locale=(string)file_get_contents($root.'pricing/rate-locale-policy.php');
$resolver=(string)file_get_contents($root.'pricing/price-resolver.php');
$audience=(string)file_get_contents($root.'pricing/audience-resolver.php');
$booking=(string)file_get_contents($root.'pricing/booking-configuration-v2.php');
$loader=(string)file_get_contents(dirname($root).'/shared-product-core-loader.php');
$checks=[
 'mode_opt_in'=>str_contains($generation,"'pricing_locale_mode'"),
 'language_matches_parent'=>str_contains($generation,'rate_generator_locale_parent_mismatch'),
 'independent_language_fingerprint'=>str_contains($generation,"$"."params['pricing_language']"),
 'locale_amount_currency_provenance'=>str_contains($generation,'explicit_locale_amount_and_currency'),
 'draft_only'=>str_contains($generation,"'post_status'=>'draft'"),
 'stop_sale'=>str_contains($generation,"'stop_sale'=>'1'"),
 'no_implicit_translation_rate_copy'=>!str_contains($generation,'wpml_duplicate_generic_string'),
 'bind_wpml'=>str_contains($generation,"'wpml_set_element_language_details'"),
 'parent_readback'=>str_contains($generation,'rate_generator_locale_parent_binding_failed'),
 'locale_readback'=>str_contains($generation,'rate_generator_locale_binding_failed'),
 'rollback_failure'=>str_contains($generation,'wp_delete_post($created,true)'),
 'runtime_grant_create'=>str_contains($generation,'rate_generator_runtime_grant_required'),
 'no_local_only_creation'=>!str_contains($generation,'rate_generator_nonlab_denied'),
 'admin_default_inherit'=>str_contains($admin,'value="inherit" selected'),
 'admin_exposes_override'=>str_contains($admin,'Override: independent amount + currency for this Tour language'),
 'admin_post_default_omits_mode'=>str_contains($admin,"$"."mode=$"."raw['pricing_locale_mode']??'inherit'"),
 'legacy_fingerprint_safe'=>str_contains($generation,"$"."input['pricing_locale_mode']??'inherit'"),
 'explicit_inherit_mode'=>str_contains($generation,"['inherit','independent']"),
 'admin_post_passes_locale_mode'=>str_contains($admin,"$"."payload['pricing_locale_mode']='independent'"),
 'csv_locale_mode'=>str_contains($batch,"'pricing_locale_mode'"),
 'csv_locale_language'=>str_contains($batch,"'pricing_language'"),
 'admin_csv_guidance'=>str_contains($admin,'optional pricing_locale_mode=independent'),
 'shared_rate_policy_present'=>str_contains($locale,'function shared_product_rate_locale_decision('),
 'loader_invokes_shared_policy'=>str_contains($loader,"require_once $"."root.'pricing/rate-locale-policy.php'"),
 'presentation_resolver_shared'=>str_contains($resolver,'shared_product_rate_locale_decision('),
 'audience_woocommerce_shared'=>str_contains($audience,'shared_product_rate_locale_decision('),
 'booking_shared'=>str_contains($booking,'shared_product_rate_locale_decision('),
 'booking_runtime_grant'=>str_contains($bookingSource,'MAD4B_COMMERCE_RUNTIME_ENABLED'),
 'booking_not_lab_required'=>!str_contains($bookingSource,"if (!defined('ABSPATH') || !defined('SHARED_PRODUCT_LAB_ONLY')"),
 'batch_runtime_grant'=>str_contains($rateBatch,'MAD4B_COMMERCE_RUNTIME_ENABLED'),
 'batch_no_staging_local_rejection'=>!str_contains($rateBatch,"wp_get_environment_type()!=='local'"),
 'source_priority_runtime_grant'=>str_contains($sourcePriority,'MAD4B_COMMERCE_RUNTIME_ENABLED'),
 'source_priority_no_lab_dependency'=>!str_contains($sourcePriority,"if (!defined('ABSPATH') || !defined('SHARED_PRODUCT_LAB_ONLY')"),
 'assignment_runtime_grant'=>str_contains($assignment,'MAD4B_COMMERCE_RUNTIME_ENABLED'),
 'assignment_no_local_only_return'=>!str_contains($assignment,"if(!defined('ABSPATH')||!defined('SHARED_PRODUCT_LAB_ONLY')")
];
$failed=array_keys(array_filter($checks,static fn($v)=>!$v));
echo 'RATE_LOCALIZED_SOURCE_CONTRACT '.($failed?'FAIL '.implode(',',$failed):'PASS').' / '.count($checks)." checks\n";
exit($failed?1:0);
