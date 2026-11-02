<?php
/** Hermetic source-level guards. End-to-end WPML create/cleanup runs on Staging. */
$root=dirname(__DIR__).'/mu-plugins/shared-product-core/';
$generation=(string)file_get_contents($root.'pricing/rate-generation.php');
$admin=(string)file_get_contents($root.'admin/rate-generation.php');
$batch=(string)file_get_contents($root.'pricing/rate-source-batch.php');
$assignment=(string)file_get_contents($root.'pricing/accommodation-assignment.php');
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
 'admin_exposes_locale_policy'=>str_contains($admin,'Independent amount + currency per Tour language'),
 'admin_post_passes_locale_mode'=>str_contains($admin,"$"."payload['pricing_locale_mode']='independent'"),
 'csv_locale_mode'=>str_contains($batch,"'pricing_locale_mode'"),
 'csv_locale_language'=>str_contains($batch,"'pricing_language'"),
 'admin_csv_guidance'=>str_contains($admin,'optional pricing_locale_mode=independent'),
 'assignment_runtime_grant'=>str_contains($assignment,'MAD4B_COMMERCE_RUNTIME_ENABLED'),
 'assignment_no_local_only_return'=>!str_contains($assignment,"if(!defined('ABSPATH')||!defined('SHARED_PRODUCT_LAB_ONLY')")
];
$failed=array_keys(array_filter($checks,static fn($v)=>!$v));
echo 'RATE_LOCALIZED_SOURCE_CONTRACT '.($failed?'FAIL '.implode(',',$failed):'PASS').' / '.count($checks)." checks\n";
exit($failed?1:0);
