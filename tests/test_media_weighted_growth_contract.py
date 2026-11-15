"""Regression gates for MAD4B photo-quality native weighted, scalable coverage."""
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1] / "wp-content" / "mu-plugins"
ENGINE = ROOT / "mad4b-tour-photo-quality.php"
PROVIDER = ROOT / "mad4b-tour-photo-quality" / "weighted-coverage.php"


def test_native_engine_not_parallel_engine():
    engine = ENGINE.read_text()
    provider = PROVIDER.read_text()
    assert "require_once __DIR__.'/mad4b-tour-photo-quality/weighted-coverage.php'" in engine
    assert "use MAD4B_Tour_Photo_Weighted_Coverage_Trait" in engine
    assert "trait MAD4B_Tour_Photo_Weighted_Coverage_Trait" in provider
    assert "proposal_only_no_automatic_writes" in engine
    assert "auto_apply_authorized'=>false" in provider


def test_weights_are_company_policy_not_hardcoded_tour_ids():
    provider = PROVIDER.read_text()
    assert "get_option('mad4b_media_weighted_coverage_v1'" in provider
    assert "mad4b/tour_photo_quality/weighted_coverage_policy" in provider
    assert "'hero'=>['title'=>36" in provider
    assert "'gallery'=>['title'=>8" in provider
    assert "weight_renormalization_on_missing_data" in provider
    for forbidden in ["60439", "60609", "54877", "69801"]:
        assert forbidden not in provider


def test_registered_relationships_and_taxonomies_auto_discovered():
    p = PROVIDER.read_text()
    assert "get_object_taxonomies('tours-and-activities','objects')" in p
    assert "get_active_relations" in p
    assert "get_parents($tour,'ids')" in p
    assert "get_post_type_object($pt)" in p
    assert "get_term_meta($id)" in p
    assert "get_post_meta($id)" in p
    assert "image|gellery|gallery|photo|media|portrait|cover|thumbnail|featured" in p
    assert "registered_cpt_relation_media_review_only" in p
    assert "registered_taxonomy_media_review_only" in p
    assert "verified_itinerary_story_media_review_only" in p


def test_growth_is_bounded_paged_and_cached():
    p = PROVIDER.read_text()
    engine = ENGINE.read_text()
    for marker in ["related_objects", "term_objects", "media_per_object", "media_candidates", "gallery_additions"]:
        assert f"'{marker}'" in p
    assert "'next_page'=>" in p
    assert "wp_cache_get($cacheKey" in p
    assert "wp_cache_set($cacheKey" in p
    assert "'coverage-page':$r=self::coverage_page" in engine
    assert "registered_story_chapters" in engine
    assert "gallery_growth_target" in engine
    assert "gallery_existing_images_not_deleted'=>true" in engine


def test_hero_title_focus_not_broad_seo_alt_only():
    p = PROVIDER.read_text()
    engine = ENGINE.read_text()
    assert "count($altWords)<=12" in p
    assert "'hero_title_anchor'" in p
    assert "empty($entry['hero_title_anchor'])" in engine
    assert "needs_visual_and_rights_review" in engine
    assert "cross_tour_image_reuse_allowed'=>true" in engine


def test_related_media_never_bypasses_license():
    p = PROVIDER.read_text()
    engine = ENGINE.read_text()
    assert "rights_verified'=>false" in p
    assert "visual_verified'=>false" in p
    assert "auto_apply_authorized'=>false" in p
    assert "visual_and_rights_review_required'=>true" in engine
    assert "self::preflight" not in p  # only the original engine validates an apply
    assert "wp_insert_post" not in p and "update_post_meta" not in p




def test_rate_options_are_resolved_from_native_tour_rate_contract():
    p = PROVIDER.read_text()
    assert "shared_product_assignment_legacy_links($tour)" in p
    assert "shared_product_assignment_tour_siblings($tour)" in p
    assert "get_post_meta($rate,'related_tour_id'" in p
    assert "get_post_meta($rate,'has_properties'" in p
    assert "get_post_meta($rate,'related_package_term_id'" in p
    assert "'source_rate_ids'=>array_keys($rates)" in p
    assert "'rate_options'=>$rateOptions" in p
    assert "'stop_sale'=>" in p
    assert "'multi_stage'=>" in p
    assert "'property_role'=>$propertyRole" in p
    assert "if($pt==='tours-and-activities'||$pt==='properties')continue" in p
    assert "available_accommodation_option_not_guaranteed_vessel" in p


def test_unreviewed_rate_images_and_held_origins_fail_closed():
    p = PROVIDER.read_text()
    e = ENGINE.read_text()
    assert "coverage_property_subject_hint($id,(int)$aid)" in p
    assert "property_media_subject_unverified" in p
    assert "'options_missing_media'" in p
    assert "mad4b/tour_photo_quality/property_photo_match" in p
    assert "mad4b_media_editorial_holds_v1" in e
    assert "origin_sha256" in e and "contact_sheet_sha256" in e
    assert "hero_original_editorial_hold" in e
    assert "gallery_original_editorial_hold" in e
    assert "media_visual_editorial_hold" in e
    assert "featured_original_requires_subject_and_geography_review" in e
    assert "media_rate_property_photo_coverage_gap" in e
    assert "not_a_synthetic_or_rights_determination" in e
    assert "update_post_meta" not in p




def test_wpml_canonical_subject_evidence_requires_native_signature():
    e = ENGINE.read_text()
    assert "MAD4B_Tour_Media_Association_V1::verified_ids($primary,$family)" in e
    assert "($candidate['canonical_post_id']??0)===$primary" in e
    assert "($candidate['wpml_family']??[])===$family" in e
    assert "$subject=get_post_meta($associationPost,'_mad4b_media_subject_evidence_v1',true);" in e
    assert "($slot['origin_sha256']??'')===$photo['sha']" in e
    assert "generic_tour_review_is_not_per_image_subject_proof" in e
    assert "($association['contract']??'')!=='mad4b.tour-media-association.v1'" in e

if __name__ == "__main__":
    for name, function in sorted(globals().copy().items()):
        if name.startswith('test_') and callable(function):
            function()
            print('PASS', name)
