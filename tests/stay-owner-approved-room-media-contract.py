#!/usr/bin/env python3
"""Deterministic contract for the approved Unit photo importer and rollback.
No media download, database or upload mutation during CI."""
from pathlib import Path
import re
p=Path(__file__).resolve().parents[1]/'scripts'
source=(p/'mad4b-stay-unit-photo-curation-cli.php').read_text()
rollback=(p/'mad4b-stay-unit-photo-rollback-cli.php').read_text()
stock=(Path(__file__).resolve().parents[1]/'wp-content/mu-plugins/021-mad4b-stay-unmanaged-stock.php').read_text()
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
assert not re.search(r'\b(?:wp_update_post|wp_publish_post|wc_create_order|wc_reduce_stock_levels)\s*\(',source + rollback)
assert "'accommodation_only'" in stock
assert "'keep_occupancy_capacity_check'=>true" in stock
assert "'checkout_ready'=>false" in stock
print('PASS owner-approved unit photo import/rollback source contracts; stock is accommodation-only')
