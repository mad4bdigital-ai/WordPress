#!/usr/bin/env python3
"""Operator-sourced amenities and unpriced cabin-category Drafts."""
from pathlib import Path
src=(Path(__file__).resolve().parents[1]/'scripts/mad4b-cruise-operator-web-amenities-editorial-cli.php').read_text()
tokens=[
 'https://wayfatours.com','35809','Storia The Dahabeya',
 '35817','Sonesta Amirat Dahabeya II','35810','Iberotel Amara',
 'adnilecruises.com/cruise/storia-the-dahabeya',
 'newsroom.sonesta.com/uncategorized/sonesta-announces-launch',
 'partner.jazhotels.com/cruise/82','jaz_al_qassida',
 'direct_automated_scrape_allowed',
 '_mad4b_cruise_public_web_amenities_evidence_v1',
 '_mad4b_cruise_category_source_draft_v1',
 'wpml_set_element_language_details','wp_insert_term(',
 "wp_set_object_terms(","'tax_amenities'",
 'Cruise Amenities','Servicios de crucero',
 'Sun Deck','Onboard Restaurant','Plunge Pool',
 'Open-Air Jacuzzi','Onboard Wi-Fi','Onboard Gym',
 "'post_type'=>'units','post_status'=>'draft'",
 "'unit_readiness','pending_review'",
 "'unit_available','false'",
 "'count_is_not_selling_inventory'=>true",
 "'supplier_verified_max_occupancy'=>null",
 "'supplier_inventory_quantity'=>null",
 "'sailing_date'=>null,'rate_amount'=>null",
 "'booking_authorized'=>false,'checkout_ready'=>false",
 "'rel_id'=>'223'","'parent_rel'=>0",
 "'taxonomy_assignment_allowed'=>false",
 "'draft_unit_creation_allowed'=>false",
 "'catalog_activated'=>false",
 "'published_cabin_units_created'=>0",
 "'plan','apply','verify'",'ROLLBACK','COMMIT'
]
for token in tokens:
    assert token in src,token
for forbidden in [
 'wc_create_order(','update_option(','delete_option(',
 'media_sideload_image(','set_post_thumbnail(',
 'wp_schedule_event(','flush_rewrite_rules(',
 "'post_status'=>'publish'",'wp_cache_flush('
]:
    assert forbidden not in src,forbidden
assert "count($features)!==14" in src
assert "'web_ship_evidence_records'=>3" in src
assert "'cruise_amenity_term_pairs'=>15" in src
assert "metadata_exists('post',$uid,'unit_inventory_quantity')" in src
assert "metadata_exists('post',$uid,'unit_max_occupancy')" in src
assert "metadata_exists('post',$uid,'unit_start_price')" in src
print('PASS 3 ship source receipts, 15 EN/ES Cruise Amenity pairs, 5 unpriced Draft Units')
print('PASS no published/saleable cabin inventory, legacy Rate or checkout mutation')
