#!/usr/bin/env python3
"""Fail-closed supplier-document request receipt, never a commercial authorization."""
from pathlib import Path
s=(Path(__file__).resolve().parents[1]/'scripts/mad4b-stay-saray-supplier-evidence-request-v1-cli.php').read_text()
required=[
 'https://wayfatours.com', 'Saray Pyramids & Museum View Hotel',
 'info@sarayhotels.com','res.pyramids@sarayhotels.com',
 '1a1f814f43a054f5','_mad4b_saray_supplier_document_request_v1',
 'sent_awaiting_supplier_response',
 'legacy_7_to_current_3_category_mapping',
 'per_category_max_adults_children_infants_and_total_guests',
 'family_room_id_6_slug_vs_category_name_resolution',
 'image_rights_owner_credit_crops_languages_promotional_use_duration',
 "'supplier_response_received'=>false",
 "'supplier_max_occupancy_verified'=>false",
 "'media_publication_license_granted'=>false",
 "'media_assets_import_allowed'=>false",
 "'commercial_ready'=>false", "'catalog_activated'=>false",
 "'no_price_inventory_media_or_status_mutations'=>true",
 "'plan','apply','verify'", 'add_post_meta(', 'metadata_exists(',
 "get_post_meta($uid,'unit_available'", "get_post_meta($uid,'unit_readiness'",
 "get_post_meta($uid,'unit_max_occupancy'", "get_post_thumbnail_id($uid)",
 "wp_count_posts('property-rates')", 'mad4b_stay_editorial_publish_v1',
]
for word in required:assert word in s,word
for forbidden in ['wp_insert_post(', 'wp_update_post(', 'wp_delete_post(', 'wp_set_object_terms(',
 'update_post_meta(', 'wp_update_attachment_metadata(', 'set_post_thumbnail(',
 'media_sideload_image(', 'download_url(', 'wp_remote_post(',
 'unit_start_price', 'unit_inventory_quantity', 'wp_schedule_event(',
 'update_option(', 'flush_rewrite_rules(']:
 assert forbidden not in s,forbidden
assert s.index('if($errors)WP_CLI::error')<s.index("if($mode==='apply')")
print('PASS Saray Gmail supplier request evidence, exact 5 records, no occupancy/photo/commercial writes')
