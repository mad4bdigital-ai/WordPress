#!/usr/bin/env python3
'''Hermetic guard for Wayfatours post-status publication with catalog held off.'''
from pathlib import Path
s=(Path(__file__).resolve().parents[1]/'scripts/mad4b-stay-editorial-publication-v1-cli.php').read_text()
checks=[
    "https://wayfatours.com",
    "MAD4B_STAY_PUBLISH_OWNER_APPROVED",
    "MAD4B_STAY_PUBLISH_PLAN",
    "MAD4B_STAY_PUBLISH_RECEIPT",
    "MAD4B_STAY_PUBLISH_LOCK",
    "MAD4B_STAY_EXPECTED_UNIT_COUNT = 131",
    "MAD4B_STAY_INVALID_PROPERTY = 36260",
    "[70732,70733,70734,70735,70736,70737,70738,70739]",
    "rel_id=223",
    "get_post_thumbnail_id($id)",
    "get_attached_file($thumbnail)",
    "_mad4b_stay_visual_coverage_v1",
    "_mad4b_unit_photo_curation_v1",
    "qa_excluded_ids",
    "invalid_import_excluded_ids",
    "published_property_rates_must_remain_zero",
    "catalog_activated'=>false",
    "catalog_activation_performed'=>false",
    "wp_update_post(['ID'=>$id,'post_status'=>'publish']",
    "wp_update_post(['ID'=>$id,'post_status'=>'draft']",
    "post_modified_gmt_after",
    "rollback-preview",
]
for item in checks:
    assert item in s, item
for forbidden in [
    "register_post_type(", "flush_rewrite_rules(", "wp_delete_post(",
    "wc_create_order(", "wc_reduce_stock_levels(", "update_option('woocommerce_manage_stock'",
    "checkout_ready'=>true", "update_option('mad4b_catalog",
]:
    assert forbidden not in s, forbidden
assert s.count("'post_status'=>'publish'")==1
assert s.count("'post_status'=>'draft'")==1
print('PASS exact Wayfatours editorial status publication, catalog hold and reversible guard')
