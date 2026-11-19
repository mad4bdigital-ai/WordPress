#!/usr/bin/env python3
"""Deterministic contract for the approved Unit photo importer and rollback.
No media download, database or upload mutation during CI."""
from pathlib import Path
import re
p=Path(__file__).resolve().parents[1]/'scripts'
source=(p/'mad4b-stay-unit-photo-curation-cli.php').read_text()
rollback=(p/'mad4b-stay-unit-photo-rollback-cli.php').read_text()
stock=(Path(__file__).resolve().parents[1]/'wp-content/mu-plugins/021-mad4b-stay-unmanaged-stock.php').read_text()
four_seasons=(p/'mad4b-stay-fourseasons-exact-room-source-admission.php').read_text()
assert "press.fourseasons.com" in four_seasons
assert "www.fourseasons.com" in four_seasons
assert "_mad4b_unit_official_room_photo_reference_v1" in four_seasons
assert "in_array(get_post_status($property),['publish','draft'],true)" in four_seasons
assert "get_post_status($id)!=='draft'" in four_seasons
assert "_mad4b_official_room_photo_source_observation_v1" in four_seasons
assert "rights_independently_verified'=>false" in four_seasons
assert 'press.fourseasons.com' in source
assert 'MAD4B_STAY_MEDIA_USER_APPROVED' in source
assert 'MAD4B_STAY_MEDIA_UNIT_IDS' in source
assert 'count($allow)>20' in source
assert 'add_option($lock' in source and 'delete_option($lock)' in source
assert 'self::validate((array)$row,$folder,$secondSeen)' in source
assert "get_post_status($unit)!=='draft'" in source
assert 'get_post_thumbnail_id($unit)' in source and "'unit_gellery'" in source
assert 'get_post_type($property)' in source and 'rel_id=%d' in source
assert 'hash_file(' in source and 'hash_equals' in source
assert 'getimagesize(' in source and 'in_array($mime,' in source
assert 'owner_attested_public_use' in source
assert "'rights_independently_verified'=>false" in source
assert "'original_rights_status'" in source and "'source_room_page'" in source
assert 'wp_insert_attachment' in source and 'wp_generate_attachment_metadata' in source
assert 'wp_delete_attachment($attachment,true)' in source
assert 'wp_delete_attachment($aid,true)' in rollback
assert 'changed_since_import_rollback_blocked' in rollback
assert 'MAD4B_STAY_MEDIA_ROLLBACK_OWNER_CONFIRMED' in rollback
assert "get_post_status($id)==='draft'" in rollback
admission=(p/'mad4b-stay-triumph-exact-room-source-admission.php').read_text()
gallery=(p/'mad4b-stay-triumph-room-gallery-cli.php').read_text()
assert "_mad4b_unit_official_room_photo_reference_v1" in admission
assert "source_registered" in admission and "official_room_image_observation" not in admission
assert "source_page_or_room_mismatch" in gallery
assert "base_photo_or_gallery_changed" in gallery and "gallery_compare_and_swap_failed_reverted" in gallery
assert "gallery_changed_since_apply" in gallery and "rollback_eligible" in gallery
assert "MAD4B_STAY_GALLERY_OWNER_APPROVED" in gallery
assert "wp_delete_attachment" in gallery and "add_option($lock" in gallery
assert "_mad4b_unit_room_gallery_extension_v1" in gallery
assert not re.search(r'\b(?:wp_update_post|wp_publish_post|wc_create_order|wc_reduce_stock_levels)\s*\(',admission + gallery)
assert not re.search(r'\b(?:wp_update_post|wp_publish_post|wc_create_order|wc_reduce_stock_levels)\s*\(',source + rollback)
assert "'accommodation_only'" in stock
assert "'keep_occupancy_capacity_check'=>true" in stock
assert "'checkout_ready'=>false" in stock
print('PASS owner-approved unit photo import/rollback source contracts; stock is accommodation-only')
