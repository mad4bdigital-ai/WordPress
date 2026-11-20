#!/usr/bin/env python3
'''Source guard: original 18 Wayfatours hotel cohort remains read-only.'''
from pathlib import Path
import re
source=(Path(__file__).resolve().parents[1]/'scripts/mad4b-stay-original-18-hotel-publication-audit.php').read_text()
expected={
 'Silver':[70732,36475,36316,36197,36209],
 'Gold':[36479,36473,36474,36477,36140,70733],
 'Diamond':[36355,70734,70735,70736,70737,70738,70739],
}
assert len(set(sum(expected.values(),[])))==18
for name,ids in expected.items():
    pattern=r"'"+name+r"'\s*=>\s*\["+r"\s*,\s*".join(map(str,ids))+r"\]"
    assert re.search(pattern,source), (name,pattern)
for term in [
 'https://wayfatours.com', 'WP_CLI',
 'post_properties', 'package_levels', 'icl_translations',
 'mad4b_stay_editorial_publish_v1', 'catalog_activated',
 'property_rates_published', 'original_18_hotel_publication_audit_failed',
 'linked_units_published', 'spanish_missing', 'without_units',
 'quarantined_import_statuses'
]:
    assert term in source,term
for keyword in [
 'wp_update_post(', 'wp_insert_post(', 'wp_delete_post(', 'wp_set_object_terms(',
 'wp_set_post_terms(', 'update_option(', 'add_option(', 'flush_rewrite_rules(',
 'wc_create_order(', 'wp_create_user(',
]:
    assert keyword not in source,keyword
print('PASS exact 18 published Property tier cohorts, WPML observations and no-write audit')
