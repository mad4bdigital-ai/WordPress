#!/usr/bin/env python3
"""Guard Saray-specific editorial-only identity and room creation routine."""
from pathlib import Path
s=(Path(__file__).resolve().parents[1]/'scripts/mad4b-stay-saray-owner-identity-and-rooms-cli.php').read_text()
for text in [
    'https://wayfatours.com','Saray Pyramids & Museum View Hotel',
    'Saray Pyramids & Museum View Hotel —' if False else "'name'=>'Standard Room'",
    "'name'=>'Deluxe Room'", "'name'=>'Family Room'",
    'https://sarayhotels.com/rooms/saray-pyramids-museum-view/',
    '_mad4b_property_identity_verified_v3','explicit_owner_confirmation_and_official_crosscheck',
    "'legal_supplier_contract_verified'=>false",
    "'commercial_authority_granted'=>false",
    "'catalog_activated'=>false",
    "'source_confidence'=>'official_current_page'",
    "'unit_available','false'",
    "'unit_readiness','pending_review'",
    "'max_occupancy_verified'=>false",
    "'prices'" if False else "'rates_verified'=>false",
    "'unit_kind','room'",
    "'plan','apply','verify'",
    "'source_type'=>'units'",
]:
    assert text in s,text
assert s.count("'slug'=>'") == 3
assert 'esUnwantedAllowed' in s
assert 'wp_update_term_count_now' in s
assert 'failed_to_remove_wrong_language_es_term' in s
for forbidden in [
    'wp_create_user(', 'wc_create_order(', 'wp_schedule_event(',
    "update_post_meta($uid,'unit_start_price'", 'unit_inventory_quantity',
    'wp_delete_post(', 'flush_rewrite_rules(', "update_post_meta($uid,'unit_max_occupancy'",
]:
    assert forbidden not in s,forbidden
assert "($plan['catalog_activated']??true)!==false" in s
assert "(int)(wp_count_posts('property-rates')->publish??0)!==0" in s
assert s.index("$existing=$wpdb->get_results") < s.index("$uid=wp_insert_post(")
print('PASS Saray exact-3 source-backed EN units, WPML, identity evidence, no commerce activation')
