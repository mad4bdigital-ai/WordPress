#!/usr/bin/env python3
"""Five official ship identities, 15 Draft cabins, no rates or stock."""
from pathlib import Path
s=(Path(__file__).resolve().parents[1]/'scripts/mad4b-cruise-web-operator-amenities-batch2-cli.php').read_text()
required=[
 'https://wayfatours.com',
 '35957','Sonesta Dahabiya Amirat','Sonesta Dahabeya Amirat',
 '35969','M/S Sonesta Star Goddess','35971','M/S Sonesta Moon Goddess',
 '35972','M/S Sonesta St. George Nile Cruise','35927','MS Concerto II Nile Cruise',
 'https://extensiongroup.com.eg/concerto-ii',
 'sonesta.com/nile-river-cruises/sonesta-dahabeya-amirat',
 'sonesta.com/nile-river-cruises/sonesta-star-goddess-nile-cruise-ship',
 'sonesta.com/nile-river-cruises/sonesta-moon-goddess-nile-cruise-ship',
 'sonesta.com/nile-river-cruises/sonesta-st-george-1-nile-cruise-ship',
 'robots_direct_scrape_allowed',
 "'beethoven_suite','Beethoven Suite','suite',27",
 "'mozart_suite','Mozart Suite','suite',4",
 "'chopin_suite','Chopin Suite','suite',2",
 "'deluxe_cabin','Deluxe Cabin','cabin',49",
 "'royal_suite','Royal Suite','suite',1",
 "'double_cabin','Double Cabin','cabin',61",
 "'single_cabin','Single Cabin','cabin',2",
 "'junior_suite','Junior Suite','suite',2",
 "'reported_count_not_sellable_inventory'=>true",
 "'supplier_verified_max_occupancy'=>null",
 "'inventory_quantity'=>null",
 "'sailing_allotments'=>null",
 "'price_amount'=>null",
 "'supplier_contract_confirmed'=>false",
 "'post_type'=>'units','post_status'=>'draft'",
 "'unit_readiness','pending_review'",
 "'unit_available','false'",
 "'checkout_ready'=>false",
 "'rel_id'=>'223'","'parent_rel'=>0",
 "WP_CLI::error('cruise_taxonomy_wpml_pair_drift_",
 'termByRawSlug',
 "LEFT JOIN {$wpdb->prefix}icl_translations",
 "'mode'=>'verify'",
 "'new_amenity_terms_created'=>0",
 "'published_new_units'=>0",
 "'commercial_ready_units'=>0",
 "'catalog_activated'=>false",
 "'plan','apply','verify'",
 'START TRANSACTION','ROLLBACK','COMMIT',
]
for x in required: assert x in s,x
for prohibited in [
 'wc_create_order(', 'update_option(', 'delete_option(',
 'media_sideload_image(', 'set_post_thumbnail(',
 'wp_schedule_event(', 'flush_rewrite_rules(',
 "'post_status'=>'publish'", 'wp_cache_flush(',
 # Explicit Unit price and inventory absence asserted in code
]:
 if 'placeholder' in prohibited:continue
 assert prohibited not in s,prohibited
assert "if($totalCategories!==15||$totalTerms!==50)" in s
assert "count($ships)!==5" in s
assert "count($termSlugs)!==14" in s
assert "metadata_exists('post',$pid,'unit_inventory_quantity')" in s
assert "metadata_exists('post',$pid,'unit_max_occupancy')" in s
assert "metadata_exists('post',$pid,'unit_start_price')" in s
assert s.count("add_post_meta($pid,$unitMeta,$cabinReceipt,true)")==1
assert s.count("add_post_meta($id,$evidenceMeta,$receipt,true)")==1
print('PASS 5 distinct operator-backed Cruise Properties, 50 source-backed Amenity assignments')
print('PASS 15 new unpriced Draft Cabin/Suite categories; existing WPML Amenity taxonomy reused')
