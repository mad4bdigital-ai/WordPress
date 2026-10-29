#!/usr/bin/env python3
"""Guard the bounded, tenant-neutral JetSmartFilters consumer data path.

This test checks the *source contract*, not external PageSpeed performance.
Full behavior parity must also be verified against a company's own Staging data.
"""
from pathlib import Path
s=(Path(__file__).resolve().parents[2]/"wp-content/mu-plugins/shared-product-core/adapters/presentation-native-consumer-policy-v1.php").read_text()
assert "function shared_presentation_native_consumers_v1(): array" in s
assert "shared_presentation_native_facet_binding_v1" in s
assert "SELECT p.ID FROM" in s
assert "pm.meta_value LIKE %s ORDER BY p.ID ASC LIMIT 1001" in s
assert "if(!is_array($rows)||count($rows)>1000)" in s
assert "array_unique(array_map('intval',$rows))" in s
assert "SELECT post_id, meta_value FROM" in s
assert "WHERE meta_key='_elementor_data' AND post_id IN ($slots)" in s
assert "ORDER BY meta_id ASC" in s
assert "array_key_exists($postId,$payloads)" in s
assert "json_decode((string)($payloads[(int)$id]??''),true)" in s
assert "get_post_meta((int)$id,'_elementor_data',true)" not in s
assert "get_post_meta($id,'_source_taxonomy',true)" in s
assert "get_post_status($id)!=='publish'" in s
assert "set_transient('shared_presentation_native_consumers_v1'" in s
assert "5*MINUTE_IN_SECONDS" in s # no hidden TTL or invalidation change
assert "add_action('save_post','shared_presentation_native_consumer_invalidate_v1'" in s
assert "add_action('deleted_post','shared_presentation_native_consumer_invalidate_v1'" in s
assert "wpml_element_trid" in s and "wpml_get_element_translations" in s
for secret in ("staging.egypttourgates.com","tours.dev.mad4b.com","35138","67263","form_id = '8'"):
    assert secret not in s
for unsafe in ("update_option(","wp_update_post(","wp_insert_post(","delete_post(","DROP TABLE","ALTER TABLE"):
    assert unsafe not in s,unsafe
print("NATIVE_FILTER_META_BATCH_SOURCE_CONTRACT PASS")
