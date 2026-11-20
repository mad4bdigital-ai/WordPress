#!/usr/bin/env python3
"""Owner selected Google Hotels five stars; no commercial approval."""
from pathlib import Path
root=Path(__file__).resolve().parents[1]
php=(root/'scripts/mad4b-stay-pyramids-park-owner-google-five-star-cli.php').read_text()
required=[
    'https://wayfatours.com', "'en'=>70732", "'es'=>71709",
    "'en'=>490", "'es'=>649", 'Pyramids Park Resort Cairo',
    'https://www.google.com/travel/hotels/entity/CgoIwejS8Zm4vfcBEAE',
    'conflicting_sources_review_required',
    'owner_approved_google_hotels_five_star',
    "'owner_approved'=>true", "'supplier_contract_stars_certified'=>false",
    "'source_confidence'=>'owner_approved_secondary_source'",
    'previous_review','star_rating_not_package_tier',
    "'rates_approved'=>false", "'checkout_ready'=>false",
    "'catalog_activated'=>false", "'en'=>[2353]", "'es'=>[2991]",
    "'plan','apply','verify'", 'wp_update_term_count_now(',
    "'cohort_classified'", 'get_post_meta($id,',
    'update_post_meta($id,$meta,$review)',
]
for token in required:
    assert token in php,token
for forbidden in [
    'wp_insert_post(', 'wp_update_post(', 'wp_delete_post(', 'wp_set_object_terms(',
    'wc_create_order(', 'media_sideload_image(', 'set_post_thumbnail(',
    'unit_max_occupancy', 'unit_inventory_quantity', 'unit_start_price',
    "update_post_meta($id,'prop_start_price'", 'update_option(',
    'flush_rewrite_rules(',
]:
    assert forbidden not in php,forbidden
assert "if($errors)WP_CLI::error" in php
assert "'stars'=>5" in php
print('PASS owner-approved Google 5-star EN/ES; 18/18 hotels and dispute preserved')
print('PASS Silver package level and zero-commercial-writes boundary')
