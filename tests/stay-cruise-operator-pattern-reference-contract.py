#!/usr/bin/env python3
"""Source guard: informational vessel patterns cannot become approval."""
from pathlib import Path
p=Path(__file__).resolve().parents[1]/'scripts/mad4b-cruise-operator-pattern-reference-cli.php'
src=p.read_text()
required=[
 "home_url()!=='https://wayfatours.com'",
 "'_mad4b_cruise_operator_pattern_reference_v1'",
 "57562=>57563","60437=>60611","60439=>60609","60441=>60607",
 "60443=>60601","60623=>60661","60629=>60655",
 "Sonesta Sun Goddess","Sonesta St. George I",
 "'same_day_west_07:00_then_east_15:00'",
 "'west_day_3_then_east_day_4_after_disembarkation'",
 "'east_day_1_then_west_day_2'",
 "'operator_source_type'=>'public_operator_itinerary_reference_only'",
 "'supplier_written_excursion_approval'=>false",
 "'selected_vessel_property_id'=>null",
 "'travel_date_specific_sailing_verified'=>false",
 "'native_program_modified'=>false",
 "'original_tour_itinerary_changed'=>false",
 "'pricing_cost_authorized'=>false",
 "'rates_generated'=>false",
 "'checkout_ready'=>false",
 "get_post_meta($id,'_mad4b_cruise_program_day_sequence_source_audit_v1'",
 "if(!add_post_meta($id,KEY,$doc,true))",
 "if(!$isNew && $old!==$doc)",
 "'plan','apply','verify'",
 "count($results)!==14",
]
for token in required:
 assert token in src,token
for prohibited in [
 "wp_update_post(","update_post_meta(","wp_insert_post(",
 "wp_set_object_terms(","delete_post_meta(",
 "wp_delete_post(","wc_create_order(","wc_get_order(",
 "update_option(","delete_option("
]:
 assert prohibited not in src,prohibited
print("PASS 7 EN/ES vessel-pattern families, 14 private-only receipts, three operator examples, no commerce or itinerary mutation")
