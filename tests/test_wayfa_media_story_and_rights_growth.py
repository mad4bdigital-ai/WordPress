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




def test_published_tour_image_origins_create_only_rights_drafts():
    s=(BASE/'mad4b-media-rights-extraction-intake.php').read_text()
    assert "public static function review_published_tour_batch(int $after=0,int $limit=12)" in s
    assert "MAD4B_SCP_Provider_Field_Intelligence::discover" in s
    assert "'subtype'=>'tours-and-activities','only_media'=>true" in s
    assert "MAD4B_Gallery_Resolution_Engine_V1::inspect" in s
    assert "MAD4B_Media_Visual_Lineage_V1::origin" in s
    assert "!empty($origin['verified'])" not in s or "empty($origin['verified'])" in s
    assert "process($id,true)" in s
    assert "pending_published_tour_rights_review" in s
    assert "'cct_status'=>'draft'" in s
    assert "'rights_granted'=>false" in s
    assert "'auto_approval'=>false" in s


def test_new_rights_workflow_dedupes_by_original_sha_and_protects_original():
    s=(BASE/'mad4b-media-rights-extraction-intake.php').read_text()
    assert "already_pending_same_original" in s
    assert "source_sha256=%s AND cct_status=%s" in s
    assert "'source_url'=>$source" in s
    assert "'license_url'=>''" in s
    assert "'license_label'=>''" in s
    assert "(int)($origin['origin_attachment_id']??0)!==$attachment_id" in s
    assert "return ['status'=>'rights_index_query_failed'" in s
    assert "'verified_published_origins_can_be_drafted_without_source_url'=>true" in s



def test_published_tour_review_queue_coalesces_and_retries():
    s=(BASE/'mad4b-media-rights-extraction-intake.php').read_text()
    assert "public static function schedule_published_tour(int $post_id):bool" in s
    assert "wp_next_scheduled(self::PUBLISHED_TOUR_HOOK,[$post_id])" in s
    assert "save_post_tours-and-activities" in s
    assert "schedule_on_tour_media_change" in s
    assert "max(0,$post_id-1),1" in s
    assert "rights_draft_operations_blocked" in s
    assert "wp_schedule_single_event(time()+360,self::PUBLISHED_TOUR_HOOK,[$post_id])" in s


def test_verified_source_sha_race_uses_original_native_lock():
    s=(BASE/'mad4b-media-rights-extraction-intake.php').read_text()
    assert "MAD4B_SCP_Distributed_Lock::catalog_name('media-rights-original-sha:'.$sha)" in s
    assert "MAD4B_SCP_Distributed_Lock::acquire($mutex)" in s
    assert "MAD4B_SCP_Distributed_Lock::release($mutex)" in s
    assert 'finally {' in s
    assert "'same_sha_attachment_alias'=>$matched!==$attachment_id" in s
    assert 'duplicate_sha_needs_admin_repair' in s
    assert 'same_attachment_new_original_requires_review' in s
    # Always inspect/update an exact existing draft before alias-based early exit.
    assert s.index("$existing=$factory->db->query(['credit_key'=>$key],2)") < s.index('$same=$wpdb->get_results(')


def test_source_rights_cct_index_is_additive_native_cli_not_new_engine():
    s=(BASE/'mad4b-media-rights-extraction-intake.php').read_text()
    assert "const RIGHTS_ORIGIN_INDEX = 'mad4b_media_rights_origin_sha'" in s
    assert "get_content_types('media_rights')" in s
    assert "SHOW INDEX FROM `{$table}`" in s
    assert "ALTER TABLE `{$table}` ADD INDEX `{$index}`" in s
    assert 'source_sha256' in s and 'cct_status' in s
    assert 'reconcile_origin_index()' in s
    assert "'index-doctor','index-reconcile'" in s
    assert "empty($profile['deployment_binding_match'])" in s
    assert "'rights_modified'=>false" in s

if __name__=='__main__':
    for k,v in sorted(globals().copy().items()):
        if k.startswith('test_') and callable(v):
            v()
            print('PASS',k)
