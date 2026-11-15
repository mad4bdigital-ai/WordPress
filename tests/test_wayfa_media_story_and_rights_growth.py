"""Wayfatours-native photo story and rights intake regression contracts.

Source policy is tenant deployed only after independent environment parity.
"""
from pathlib import Path

BASE = Path(__file__).resolve().parents[1] / 'wp-content' / 'mu-plugins'


def test_timed_single_day_stops_do_not_expand_into_days():
    s = (BASE / 'mad4b-tour-story-gallery.php').read_text()
    assert "$single_day_stop_sequence=$stops>=2&&$withTimes===$stops" in s
    assert "$number=$single_day_stop_sequence?1:(int)($row['number']??0)" in s
    assert "$mode==='activity'||$declaredDays===1" in s
    assert "'itinerary_stops'=>count($it)" in s
    assert "'itinerary_days'=>count(array_unique" in s


def test_logistics_locations_do_not_generate_guaranteed_coverage():
    s = (BASE / 'mad4b-tour-story-gallery.php').read_text()
    assert "pickup|pick.?up|drop.?off|drive|begin your journey" in s
    assert "'minimum'=>$day_ids?max(2,min(6" in s
    assert "visit_guaranteed" in s
    assert "program_day_verified" in s


def test_copyright_metadata_only_opens_draft_rights_review():
    s = (BASE / 'mad4b-media-rights-extraction-intake.php').read_text()
    assert "private static function has_embedded_rights_attribution" in s
    assert "mad4b/media_rights/intake/embedded_attribution_review" in s
    assert "!$has_external_provenance&&!$embeddedAttribution" in s
    assert "pending_embedded_credit_review" in s
    assert "'photo_rights_verified'=>false" in s
    assert "'cct_status'=>'draft'" in s
    assert "$published>0" in s
    assert "No use without permission" not in s  # no hardcoded vendor exceptions


def test_embedded_rights_do_not_create_fabricated_url():
    s = (BASE / 'mad4b-media-rights-extraction-intake.php').read_text()
    assert "'source_url'=>$source" in s
    assert "'license_url'=>''" in s
    assert "'license_label'=>''" in s
    assert "self::confirmed_source_url($attachment_id)" in s
    assert "embedded_creator_claim_is_not_license" in s


if __name__=='__main__':
    for k,v in sorted(globals().copy().items()):
        if k.startswith('test_') and callable(v):
            v()
            print('PASS',k)
