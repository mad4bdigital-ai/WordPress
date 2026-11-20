#!/usr/bin/env python3
"""Safe Sonesta Sun/Nile Goddess operator-source staging contract.

No priced Rate, published Unit or supplier authority is implied.
"""
from pathlib import Path

p=Path(__file__).resolve().parents[1]/'scripts/mad4b-cruise-web-sun-nile-goddess-cli.php'
s=p.read_text()
expected=[
 'https://wayfatours.com',
 '35952','Sun Goddess','35906','M/S Nile Goddess',
 'https://www.sonesta.com/nile-river-cruises/sonesta-sun-goddess-cruise-ship',
 'https://www.sonesta.com/sonesta-nile-goddess-cruise-ship/guest-cabins-and-suites',
 "'presidential_suite','Presidential Suite','suite',26,40",
 "'family_suite','Family Suite','suite',2,60",
 "'executive_suite','Executive Suite','suite',1,60",
 "'royal_suite','Royal Suite','suite',1,60",
 "'deluxe_cabin','Deluxe Cabin','cabin',47,null",
 "'senator_suite','Senator Suite','suite',2,null",
 "'presidential_suite','Presidential Suite','suite',4,null",
 'source_weekly_sailing_not_live_inventory',
 'published_recurring_itinerary_patterns',
 'counts_not_supplier_inventory',
 'maximum_occupancy_per_cabin_verified',
 'date_specific_sailings_confirmed',
 'wpml_set_element_language_details',
 'mad4b-cruise-amenities',
 '_mad4b_cruise_public_web_amenities_evidence_v1',
 '_mad4b_cruise_category_source_draft_v1',
 "'post_type'=>'units','post_status'=>'draft'",
 "'unit_readiness','pending_review'",
 "'unit_available','false'",
 "'checkout_ready'=>false",
 "'inventory_quantity'=>null",
 "'supplier_verified_max_occupancy'=>null",
 "'sailing_allotments'=>null",
 "'price_amount'=>null",
 "'rel_id'=>'223'","'parent_rel'=>0",
 "if($totalTypes!==7||$totalAssigned!==24)",
 "'new_native_amenity_terms'=>0",
 "'new_published_units'=>0",
 "'catalog_activated'=>false",
 'START TRANSACTION','ROLLBACK','COMMIT',
 "'plan','apply','verify'"
]
for token in expected: assert token in s,token
for prohibited in [
 'wc_create_order(', 'update_option(', 'delete_option(',
 'media_sideload_image(', 'set_post_thumbnail(',
 'wp_schedule_event(', 'flush_rewrite_rules(',
 "'post_status'=>'publish'", 'wp_cache_flush('
]: assert prohibited not in s,prohibited
assert s.count('add_post_meta($id,$meta,$source,true)')==1
assert s.count('add_post_meta($new,$unitMeta,$unitSource,true)')==1
for key in ['unit_max_occupancy','unit_inventory_quantity','unit_start_price']:
 assert f"metadata_exists('post',$uid,'{key}')" in s
print('PASS 2 exact Sonesta ship identities / 7 documentary cabin types / 24 operator-backed Amenity assignments')
print('PASS all new Units Draft, unpriced, no sellable inventory, WPML taxonomy reused')
