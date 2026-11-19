#!/usr/bin/env python3
"""Hermetic source guard for two owner-approved visual-only photo fallbacks."""
from pathlib import Path
import re
root=Path(__file__).resolve().parents[1]
text=(root/'scripts/mad4b-stay-relaxed-visual-coverage-v1-cli.php').read_text()
assert all(v in text for v in ['70905','70893','70737','70735','71364','70891'])
assert 'partner_nearest_room_with_pool_view' in text
assert 'shared_official_asset_across_room_categories' in text
assert 'exact_room_match_unverified' in text
assert 'original_source_gap_retained_for_history' in text
assert 'rights_independently_verified' in text
assert 'MAD4B_STAY_RELAXED_OWNER_APPROVED' in text
assert "MAD4B_RELAXED_META" in text
assert 'rollback-preview' in text
assert 'original_photo_reference_preserved' in text
assert 'get_post_thumbnail_id(70891)' in text
assert 'add_post_meta($id, \'_thumbnail_id\'' in text
assert "add_post_meta($id, 'unit_gellery'" in text
assert "official_shared_asset'" in text
assert "partner_approximate_photo'" in text
assert not re.search(r'\b(?:wp_update_post|wp_publish_post|wc_create_order|wc_reduce_stock_levels|wc_update_product_stock)\s*\(',text)
assert 'wp_delete_attachment($aid, true)' in text  # rollback only owned Hilton file
print('PASS two exact Unit visual-only source/rollback protections')
