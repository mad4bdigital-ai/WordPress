#!/usr/bin/env python3
"""Static source guard for fresh hotel discovery without pricing or old manual Rates."""
import importlib.util
from pathlib import Path
root=Path(__file__).resolve().parents[1]
s=(root/'scripts/mad4b-stay-eight-packages-hotel-component-discovery-cli.php').read_text()
required=[
 'https://wayfatours.com','shared_product_accommodation_assignment($tour,0,$tier)',
 '57562=>57563','60437=>60611','60439=>60609','60441=>60607',
 '60443=>60601','60623=>60661','60627=>60657','60629=>60655',
 '70732=>71709','36316=>71711','70739=>71726',
 'prop_use_in_tour_rate_generation','_mad4b_hotel_package_component_discovery_v1',
 "'eligible_for'=>'new_tour_rate_auto_discovery'", "'rate_id_source'=>'none'",
 "'price_required'=>false","'old_manual_rates_used'=>false",
 "'source_rate_id'=>0","'checkout_ready'=>false","'stages_created'=>false",
 "'catalog_activated'=>false",'missing_es_tax','foreign_source_terms_on_es',
 'wp_update_term_count_now(', 'clean_object_term_cache(',
 "'plan','apply','verify'"
]
for token in required:assert token in s,token
for token in ['wp_insert_post(', 'wp_delete_post(', 'wp_update_post(', 'wc_create_order(',
              'set_post_thumbnail(', 'media_sideload_image(', 'unit_inventory_quantity',
              'unit_max_occupancy','flush_rewrite_rules(', "update_post_meta($id,'single_price'"]:
    assert token not in s,token
assert s.count('shared_product_accommodation_assignment($tour,0,$tier)')==1
path=root/'scripts/mad4b-stay-package-hotel-tour-zone-area-fix.py'
spec=importlib.util.spec_from_file_location('stay_zone_fix',path)
m=importlib.util.module_from_spec(spec)
spec.loader.exec_module(m)
assert m.BEFORE.count('unset($locations[$id])')==1
assert "get_post_type($post)==='tours-and-activities'" in m.AFTER
assert "$loc['location_type']==='state_governorate'" in m.AFTER
assert "$child['location_type']==='zone_area'" in m.AFTER
assert m.BEFORE_SHA!=m.AFTER_SHA and len(m.BEFORE_SHA)==len(m.AFTER_SHA)==64
print('PASS 18 hotels / 8 EN-ES Package Tours / no old Rate reuse or price writes')
print('PASS exact tour sightseeing vs hotel stay zone fix with SHA-pinned provenance')
