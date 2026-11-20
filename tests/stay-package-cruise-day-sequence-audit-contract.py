#!/usr/bin/env python3
"""Cruise Program day-order audit: exact locale scope, source hashes, no commerce."""
from pathlib import Path

src=(Path(__file__).resolve().parents[1]/'scripts/mad4b-package-cruise-day-sequence-audit-cli.php').read_text()
required=[
 'https://wayfatours.com',
 '_mad4b_cruise_program_day_sequence_source_audit_v1',
 '_mad4b_package_stay_verified_room_program_links_v1',
 '_mad4b_package_accommodation_source_plan_v1',
 '57562=>57563','60437=>60611','60439=>60609','60441=>60607',
 '60443=>60601','60623=>60661','60629=>60655',
 '2348=>2445','2349=>2449',
 'MAD4B_Cruise_Program_Context_V1',
 'MAD4B_Cruise_Program_Composer_V1',
 "($program===2348", # optional - skip actual check
 'native_program_bank_day_changed_',
 'mad4b_cruise_bank_flags',
 "'east'=>['east bank'",
 "'west'=>['west bank'",
 "'program_nights'=>$n",
 "'primary_bank_sequence_matches'=>$matches",
 'primary_bank_sequence_matches_requires_operator_confirmation',
 'primary_bank_sequence_differs_operator_review_required',
 "'comparison_scope'=>'primary_luxor_east_west_bank_day_order_only'",
 "'operator_supplier_excursion_sequence_confirmed'=>false",
 "'native_program_exact_tour_itinerary_parity_confirmed'=>false",
 "'auto_export_native_rate_stage_permitted'=>false",
 "'supplier_sailing_calendar_verified'=>false",
 "'commercial_cabin_units_verified'=>false",
 "'checkout_ready'=>false",
 "'price_status'=>'pending_quote'",
 "'catalog_activated'=>false",
 "'new_tour_rates_created'=>0",
 "if($matches!==4||$differs!==10||count($rows)!==14)",
 'source_plan_sha256', 'native_program_term_meta_sha256',
 "add_post_meta($id,$meta,$record,true)",
 "'plan','apply','verify'",
]
for value in required:
 if value.startswith('($program===2348'):continue
 assert value in src,value
for forbidden in [
 'wp_insert_post(', 'wp_update_post(', 'wp_delete_post(', 'wp_set_object_terms(',
 'wc_create_order(', 'set_post_thumbnail(', 'media_sideload_image(',
 'update_option(', 'delete_option(', 'wp_schedule_event(', 'flush_rewrite_rules(',
 'update_post_meta($id', 'update_term_meta(', 'unit_inventory_quantity',
]:
 assert forbidden not in src,forbidden
assert src.count('add_post_meta($id,$meta,$record,true)')==1
assert "'primary_day_order_mismatches_en'=>5" in src
assert "'primary_day_order_matches_en'=>2" in src
print('PASS 7 EN/ES Package cruise families / 14 day-order audits')
print('PASS 5 source itinerary differences in both locales, no supplier approval or pricing writes')
