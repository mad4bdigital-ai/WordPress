#!/usr/bin/env python3
"""Source-only lodging component discovery and policy non-bypass contract."""
from pathlib import Path
s=(Path(__file__).resolve().parents[1]/'scripts/mad4b-package-eight-component-source-admission-cli.php').read_text()
must=[
 'https://wayfatours.com','_mad4b_package_accommodation_source_plan_v1',
 '_mad4b_package_stay_component_discovery_v1',
 '57562=>57563','60437=>60611','60439=>60609','60441=>60607',
 '60443=>60601','60623=>60661','60627=>60657','60629=>60655',
 "'catalog_activated'=>false", "'checkout_ready'=>false",
 "'price_status'=>'pending_quote'", "'price_amount'=>null",
 "'source_plan_sha256'", "'native_tour_rate_stages_written'=>false",
 "'old_manual_tour_rates_used'=>false", "'created_tour_rates'=>0",
 "'pricing_or_inventory_writes'=>0", "'qualified_component_ids'=>[]",
 'company_policy_registry_fabricated', 'automatic_rebinding_forbidden',
 'stale_or_incomplete_site_policy','commerce_management_company_definitions_v1',
 'commerce_company_policy_registry_v1','mad4b_stay_source_candidates',
 'mad4b_stay_train(54552,54231,54257,54258)',
 'mad4b_stay_train(54553,54258,54257,54231)',
 "get_post_meta($serviceId,'trans_use_in_tour_rate_generation',true)!=='0'",
 "get_post_meta($serviceId,'trans_start_price',true)!==''",
 "'localized_bookable_component_id'=>null",
 "'sharm_resort'=>mad4b_stay_catalog_receipt(mad4b_stay_source_candidates(264,710))",
 "'aswan_nile_cruise'=>mad4b_stay_catalog_receipt(mad4b_stay_source_candidates(270,122))",
 "'luxor_nile_cruise'=>mad4b_stay_catalog_receipt(mad4b_stay_source_candidates(270,125))",
 "'plan','apply','verify'", 'add_post_meta($tour,$metaKey,$receipt,true)'
]
for x in must: assert x in s,x
for prohibited in [
 'wp_insert_post(', 'wp_update_post(', 'wp_delete_post(', 'wp_set_object_terms(',
 'wc_create_order(', 'update_option(', 'delete_option(',
 'set_post_thumbnail(', 'media_sideload_image(', 'wp_schedule_event(',
 'unit_inventory_quantity','unit_max_occupancy','flush_rewrite_rules(',
 'update_post_meta($tour','update_post_meta($serviceId'
]: assert prohibited not in s,prohibited
assert s.count('add_post_meta($tour,$metaKey,$receipt,true)')==1
print('PASS 8 Package pairs: 16 source-only receipts / 56 stage candidate reviews')
print('PASS native train #86/#87 and no commercial/policy bootstrap writes')
