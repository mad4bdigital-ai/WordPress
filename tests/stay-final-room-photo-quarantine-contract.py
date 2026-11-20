#!/usr/bin/env python3
"""Static, hermetic safeguards for the 19 Nov room-photo final triage."""
from pathlib import Path
root=Path(__file__).resolve().parents[1]
folder=root/'scripts'
sofitel=(folder/'mad4b-stay-sofitel-executive-gallery-source-v2.php').read_text()
invalid=(folder/'mad4b-stay-invalid-import-quarantine-36260-cli.php').read_text()
gaps=(folder/'mad4b-stay-unresolved-official-room-photo-gaps-cli.php').read_text()
assert '70820' in sofitel and 'HCM_P_1942495' in sofitel
assert 'C3J2/KGE.room.html' in sofitel
assert 'mad4b.stay-photo-source-reviewed-v2' in sofitel
assert 'original_v1_reference_preserved' in sofitel
assert 'MAD4B_STAY_ROOM_REFERENCE_OWNER_APPROVED' in sofitel
assert 'rights_independently_verified' in sofitel
assert '_mad4b_stay_imported_property_quarantine_v1' in invalid
assert '36260' in invalid and '37553' in invalid and '37554' in invalid and '37555' in invalid
assert 'Hotel Stanford' in invalid and 'New York' in invalid
assert 'native_jetengine_relation_changed' in invalid
assert 'wp_update_post' in invalid and "'post_status'=>'draft'" in invalid
assert 'rollback-preview' in invalid
assert 'MAD4B_STAY_IDENTITY_QUARANTINE_OWNER_APPROVED' in invalid
assert 'original_post_status' in invalid
assert 'wp_delete_post' not in invalid and 'wp_delete_attachment' not in invalid
assert '_mad4b_stay_room_photo_source_gap_v1' in gaps
assert all(str(x) in gaps for x in [70905,70893,70873])
assert 'needs_distinct_verified_official_photo' in gaps
assert 'original_ref_preserved' in gaps
assert 'MAD4B_STAY_IMAGE_GAP_OWNER_APPROVED' in gaps
assert 'wp_update_post' not in gaps and 'wp_update_post' not in sofitel
# Sonesta replacement photo is only a per-Unit partner exception.
source=(folder/'mad4b-stay-sonesta-partner-exact-room-source-v2.php').read_text()
resolution=(folder/'mad4b-stay-sonesta-photo-gap-resolution-cli.php').read_text()
curator=(folder/'mad4b-stay-unit-photo-curation-cli.php').read_text()
assert '70873' in source and '70733' in source
assert 'verified_partner_room_caption' in source
assert 'partner_provenance_not_hotel_official' in source
assert 'original_v1_reference_preserved' in source
assert 'source_partner' in source
assert 'rights_independently_verified' in source
assert '70873' in curator and '70733' in curator
assert '$sonestaPartner=' in curator and '!$sonestaPartner' in curator
assert 'source_partner_not_official' in curator
assert '_mad4b_stay_room_photo_gap_resolution_v1' in resolution
assert 'original_gap_retained_for_history' in resolution
assert 'MAD4B_STAY_PHOTO_GAP_RESOLUTION_OWNER_APPROVED' in resolution
assert 'rights_independently_verified' in resolution
assert 'wp_update_post' not in source+resolution
print('PASS room-photo v2, exact invalid-import quarantine, scoped source-gap contracts')
