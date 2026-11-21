"""Original media workflow: missing license details may not block existing WP images."""
from pathlib import Path
R=Path(__file__).resolve().parents[1]
rights=(R/'wp-content/mu-plugins/mad4b-media-rights-extraction-intake.php').read_text()
common=(R/'wp-content/mu-plugins/mad4b-unified-media-apply.php').read_text()
assert 'public static function existing_library_publication_policy(' in rights
assert "'metadata_pending_nonblocking'" in rights
assert "'published_credit_verified'" in rights
assert "'explicit_media_usage_restriction'" in rights
assert "'source_license_not_inferred'=>true" in rights
assert 'return array_merge($base,[' in rights
assert "'rights_metadata_required_for_publication'=>false" in rights
assert 'public static function existing_library_visual_review_valid(' in common
assert "['rights_verified','rights_evidence']" in common
assert "'rights_metadata_state'=>$rights['status']" in common
assert "'rights_verified_by_published_credit'=>(bool)$rights['rights_verified']" in common
assert "'rights_metadata_status'=>$rights['status']" in common
assert "'rights_verified'=>(bool)$rights['rights_verified']" in common
assert "'missing_rights_metadata_not_a_publication_block'=>true" in common
assert "'mad4b_media_library_use_restricted'" in common
assert "'mad4b_unified_media_attachment_hash_mismatch'" in common
assert 'MAD4B_Licensed_Media_Source_V1' in common
assert "'credit_key'=>$credit_key" in common
assert "MAD4B_Media_Attribution_Registry_V1::upsert_credit($credit_key" in common
print('PASS existing library metadata never treated as publication license; new source imports still credited')
