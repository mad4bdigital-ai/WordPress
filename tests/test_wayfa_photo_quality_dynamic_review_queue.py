"""Contract guards for source-lineage review backlog in original MAD4B quality engine."""
from pathlib import Path
import re

SOURCE=(Path(__file__).resolve().parents[1]/'wp-content/mu-plugins/mad4b-tour-photo-quality.php').read_text()
START='public static function review_queue():array {'
END='    public static function scan_batch(int $limit=12):array {'
assert SOURCE.count(START)==1 and SOURCE.count(END)==1
METHOD=SOURCE.split(START,1)[1].split(END,1)[0]

def test_original_engine_single_owner_and_live_scan():
    assert 'self::audit($tour)' in METHOD
    assert 'self::hero_fields($tour)' in METHOD
    assert 'MAD4B_Gallery_Resolution_Engine_V1::inspect' in METHOD
    assert 'self::origin($id)' in METHOD
    assert 'unique_original_photos_requiring_evidence' in METHOD
    assert 'cross_tour_image_reuse_not_a_violation' in METHOD
    assert "case 'review-queue':$r=self::review_queue();break;" in SOURCE

def test_relations_are_tour_rate_native_and_not_unsafe_property_guesses():
    assert 'shared_product_assignment_legacy_links($tour)' in METHOD
    assert "source_rate_ids" in METHOD
    assert "get_post_type($pid)!=='properties'" in METHOD
    assert 'photo_assignment_not_guaranteed_stay' in METHOD
    assert 'no_unreviewed_generic_photo_substitution' in METHOD
    assert 'dynamic_tour_rate_relations' in METHOD

def test_bounded_dynamic_prioritization_and_no_new_writes():
    assert 'posts_per_page' in METHOD and '1001' in METHOD
    assert 'bounded_catalog_overflow' in METHOD
    assert 'count($slots)-(int)$slotRank[$field]' in METHOD
    assert 'slot_priority_score' in METHOD
    assert 'auto_authenticity_or_rights_grants' in METHOD
    assert 'executed_writes' in METHOD
    for expression in ['update_post_meta(', 'update_option(', 'wp_insert_', 'wp_delete_', '$wpdb->query(', 'file_put_contents(', 'set_post_thumbnail(']:
        assert expression not in METHOD,expression
    assert re.search(r"preg_match\('/\^\[a-f0-9\]\{64\}\$",METHOD)

if __name__=='__main__':
    for name,fn in sorted(globals().copy().items()):
        if name.startswith('test_') and callable(fn): fn();print('PASS',name)
