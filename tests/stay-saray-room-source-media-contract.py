#!/usr/bin/env python3
"""Saray 3 current official room references and 7 legacy-only catalog classes; no selling mutations."""
from pathlib import Path
import re
s=(Path(__file__).resolve().parents[1]/'scripts/mad4b-stay-saray-room-source-media-evidence-v1-cli.php').read_text()
required=[
 'https://wayfatours.com','_mad4b_saray_room_crosswalk_v1','_mad4b_unit_official_room_photo_reference_v1',
 '_mad4b_saray_room_source_media_review_v1','Saray Pyramids & Museum View Hotel',
 'family_route_name_conflict','prior_seven_live_page_now_displays_new_template',
 'fact_sheet_verified_room_capacities',"'new_legacy_only_rooms_allowed'=>false",
 "'photo_import_allowed'=>false",'external_source_reference_only_not_licensed_for_copy',
 "'gallery_import_allowed'=>false",'images_verified_for_booking',
 "'unit_available'", "'unit_readiness'",'mad4b_stay_editorial_publish_v1',
 'property-rates','223','71731','71733','71735','sha256',"'plan','apply','verify'",
]
for token in required: assert token in s, token
for legacy in [
'Standard Double Room','Deluxe Double Room','Superior Room Pyramids View',
'Family Suite','Family Suite Terrace & Pyramids View',
'Junior Suite Pyramids View','Jacuzzi Suite Pyramids View',
]:assert legacy in s,legacy
assert s.count("'gallery_url_count'=>") == 5
assert s.count("'image_url'=>'https://sarayhotels.com/storage/") == 3
assert len(set(re.findall(r"'sha256'=>'([a-f0-9]{64})'",s)))==3
for forbidden in ['wp_insert_post(', 'wp_update_post(', 'wp_delete_post(', 'wp_set_post_terms(',
                  'wp_set_object_terms(', 'wc_create_order(', 'unit_inventory_quantity',
                  'unit_start_price','unit_max_occupancy','set_post_thumbnail(', 'media_sideload_image(',
                  'update_option(', 'flush_rewrite_rules(']:
 assert forbidden not in s,forbidden
assert s.index('if($errors)WP_CLI::error')<s.index('if($mode===\'apply\')')
print('PASS 3 exact current Saray official photo references, 7 legacy classes and zero commerce writes')
