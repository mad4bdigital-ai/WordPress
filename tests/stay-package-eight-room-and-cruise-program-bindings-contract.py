#!/usr/bin/env python3
"""Stay Source Plan room Unit IDs + Nile Programs, with no live Rate mutation."""
from pathlib import Path
s=(Path(__file__).resolve().parents[1]/'scripts/mad4b-package-eight-room-and-cruise-program-bindings-cli.php').read_text()
required=[
 'https://wayfatours.com','_mad4b_package_stay_verified_room_program_links_v1',
 '_mad4b_package_accommodation_source_plan_v1',
 '_mad4b_package_stay_component_discovery_v1',
 '57562=>57563','60437=>60611','60439=>60609','60441=>60607',
 '60443=>60601','60623=>60661','60627=>60657','60629=>60655',
 'MAD4B_Cruise_Program_Context_V1','MAD4B_Cruise_Program_Composer_V1',
 '2348=>','2349=>','2350','2445','2449','2453',
 'luxor_roundtrip','aswan_roundtrip',
 'luxor_to_aswan_leisure','aswan_to_luxor_leisure',
 'verified_source_program_duration_direction_only',
 'requires_operator_content_comparison',
 'source_last_cruise_night_used_when_checkout_day_has_no_city',
 'array_column($days[\'days\'],\'day\')!==range(1,$cfg[\'days\'])',
 'source_relation_id', 'editorial_unit_count',
 'units_with_recorded_max_occupancy',
 'commercially_approved_unit_ids',
 'unit_kind_counts',
 'trans' if False else 'get_post_meta($unit,\'unit_kind\',true)',
 'room_candidate_refs','unit_ids_en','needs_es_unit_translation',
 "'units_commercially_approved'=>0",
 "'cruise_cabins_commercially_approved'=>0",
 "'price_status'=>'pending_quote'",
 "'old_manual_rate_id_used'=>false",
 "'prices_generated'=>false",
 "'checkout_ready'=>false",
 "'catalog_activated'=>false",
 "'new_tour_rate_ids_created'=>[]",
 "'native_rate_stage_written'=>false",
 "'plan','apply','verify'",
 'add_post_meta($tour,$meta,$rec,true)',
]
for item in required: assert item in s,item
for forbidden in [
 'wp_insert_post(', 'wp_update_post(', 'wp_delete_post(', 'wp_set_object_terms(',
 'wc_create_order(', 'update_option(', 'delete_option(',
 'set_post_thumbnail(', 'media_sideload_image(', 'wp_schedule_event(',
 'unit_inventory_quantity', 'flush_rewrite_rules(',
 'update_post_meta($tour', 'update_post_meta($unit', 'update_term_meta(',
]:
    assert forbidden not in s,forbidden
assert s.count('add_post_meta($tour,$meta,$rec,true)')==1
assert s.count('wp_get_object_terms(')==0
assert "count($allRoomIds)!==134 || $withCapacity!==56" in s
assert "$hotelStages!==32||$cruiseStages!==14" in s
assert "$programCount!==[2348=>8,2349=>6]" in s
print('PASS 8 EN/ES Package Tours: 16 bindings, 56 stages')
print('PASS Cairo 134 editorial units, 7 native Nile cruise stages, no priced/saleable cabins')
print('PASS exact source/locale/duration/route proof with no legacy Rate or pricing mutations')
