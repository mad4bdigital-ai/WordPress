"""Original MAD4B media router expansion; no generic uploading or policy bypass."""
from pathlib import Path

ROOT=Path(__file__).resolve().parents[1]/'wp-content/mu-plugins'
ROUTER=(ROOT/'mad4b-unified-media-apply.php').read_text()
SOURCE=ROUTER

def test_exact_original_router_reuse():
    assert "require_once __DIR__.'/mad4b-unified-media-apply/licensed-source.php'" not in ROUTER
    assert ROUTER.count("final class MAD4B_Licensed_Media_Source_V1") == 1
    assert "'licensed_tour_source_image'" in ROUTER
    assert "MAD4B_Licensed_Media_Source_V1::run($payload,$check['target'],$action)" in ROUTER
    assert "private static function signed_ai()" in ROUTER
    assert "self::signed_ai()" in ROUTER
    assert "empty($site['write_enabled'])" in ROUTER
    assert "($review['production_authorized']??null)!==true" not in ROUTER
    assert "!empty($sourceSite['authority_ready'])" in ROUTER
    assert "!empty($sourcePolicy['ready'])" in ROUTER
    assert "MAD4B_SCP_Host_Agent_Bridge::binding_ready()" in ROUTER
    assert "signed_media_source_reviewer" in ROUTER

def test_bounded_immutable_source_cas():
    assert "const STAGING='/tmp/mad4b-licensed-media-queue/'" in SOURCE
    assert "hash_equals($sha.'.jpg',$file)" in SOURCE
    assert "hash_equals($sha,(string)hash_file('sha256',$path))" in SOURCE
    assert "getimagesize($path)" in SOURCE
    assert "($info[2]??null)!==IMAGETYPE_JPEG" in SOURCE
    assert "expected_gallery_sha256" in SOURCE
    assert "'wpml_en_es_family_not_verified'" in SOURCE
    assert "'rights_review_approval'=>false" in SOURCE

def test_review_cannot_bypass_signed_authority_or_rights():
    assert "MAD4B_Universal_Media_Apply_V1::signed_media_source_reviewer()" in SOURCE
    assert "('decision'" not in SOURCE  # decision must be exact compare, not truthy
    assert "($review['decision']??'')!=='approve'" in SOURCE
    assert "($review['rights_verified']??false)!==true" in SOURCE
    assert "($review['visual_match_verified']??false)!==true" in SOURCE
    assert "rights_evidence" in SOURCE
    assert "MAD4B_SCP_Audit::record('mad4b/signed-licensed-source-photo-review'" in SOURCE
    assert "fresh_signed_source_review_required" in SOURCE

def test_native_library_wpml_rights_and_no_tour_change():
    assert "wp_upload_bits(" in SOURCE
    assert "wp_insert_attachment(" in SOURCE
    assert "wp_generate_attachment_metadata(" in SOURCE
    assert "WPML_Media_Attachments_Duplication" in SOURCE
    assert "->create_duplicate_attachment($id,0,'es')" in SOURCE
    assert "MAD4B_Media_Attribution_Registry_V1::upsert_credit" in SOURCE
    assert "MAD4B_Media_Attribution_Registry_V1::restore_credit" in SOURCE
    assert "MAD4B_SCP_Distributed_Lock::acquire($lock)" in SOURCE
    assert "MAD4B_SCP_Distributed_Lock::release($lock)" in SOURCE
    assert "'tour_hero_changed'=>false" in SOURCE
    assert "'tour_gallery_changed'=>false" in SOURCE
    assert "update_post_meta($target['id']" not in SOURCE
    assert "update_post_meta($target[\"id\"]" not in SOURCE
    assert "'rollback_supported'=>true" in SOURCE

def test_source_license_and_no_fake_ship_assignment():
    assert "wp_parse_url((string)$asset['source_url'],PHP_URL_HOST)!=='commons.wikimedia.org'" in SOURCE
    assert "wp_parse_url((string)$asset['license_url'],PHP_URL_HOST)!=='creativecommons.org'" in SOURCE
    assert "illustrative_nile_cruise_vessel_not_guaranteed" in SOURCE
    assert "rtrim((string)$asset['license_url'],'/').'/'" in SOURCE
    assert "no_guaranteed_vessel" not in SOURCE or "illustrative_nile_cruise" in SOURCE
    assert "image_imported'=>false" in SOURCE
    assert "public static function run(array $payload,array $target,string $action)" in SOURCE

if __name__=='__main__':
    for name,fn in sorted(globals().copy().items()):
        if name.startswith('test_') and callable(fn):
            fn()
            print('PASS',name)
