"""Non-mutating media crop queue is bounded, SHA-bound, WPML-aware and conservative."""
from pathlib import Path

src=(Path(__file__).resolve().parents[1]/'wp-content/mu-plugins/mad4b-tour-photo-quality.php').read_text()
assert 'public static function review_queue():array' in src
queue=src.split('public static function review_queue():array',1)[1].split('public static function scan_batch(',1)[0]

def test_source_review_is_dynamic_and_canonical():
    assert "($issue['code']??'')" not in queue or "media_hero_crop_geometry_review_pending" in queue
    assert "media_hero_crop_geometry_review_pending" in queue
    assert "apply_filters('wpml_object_id'" in queue
    assert "'wpml_family'" in queue
    assert "'canonical_post_id'" in queue
    assert "'origin_sha256'" in queue
    assert 'hash_equals($sha' in queue
    assert "frame_crop_review_count" in queue
    assert "needs_editorially_reviewed_landscape_source" in queue
    assert "frame_crop_same_original_candidates_count" in queue

def test_cannot_publish_unreviewed_images_or_bypass_original_rights():
    assert "'source_is_in_signed_tour_review'" in queue
    assert "'original_hash_matches_disk'" in queue
    assert 'hash_file(\'sha256\',$imageFile)' in queue
    assert "'auto_media_write_authorized'=>false" in queue
    assert "'copyright_or_photography_authenticity_auto_approved'=>false" in queue
    assert "'executed_writes'=>false" in queue
    for mutation in ['update_post_meta(', 'update_option(', 'wp_insert_attachment(', '$wpdb->query(', 'wp_delete_attachment(', 'file_put_contents(']:
        assert mutation not in queue,mutation

def test_no_specific_posts_or_sources_are_hardcoded_as_admission():
    for fixed in ['60439','60629','60655','60018','17030','16992']:
        assert fixed not in queue
    assert 'get_posts([' in queue
    assert 'post_status' in queue

if __name__ == '__main__':
    for key,method in sorted(globals().copy().items()):
        if key.startswith('test_') and callable(method):method();print('PASS',key)
