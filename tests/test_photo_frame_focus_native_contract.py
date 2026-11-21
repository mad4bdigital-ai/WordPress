"""Guard the native JetEngine → Elementor photo focus contract against regressions."""
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1] / "wp-content"


def source(path):
    return (ROOT / path).read_text(encoding="utf-8")


def test_focal_point_is_native_elementor_dynamic_tag_not_javascript_overlay():
    tag = source("mu-plugins/mad4b-tour-gallery-popup-layout/frame-focus-tag.php")
    gallery = source("mu-plugins/mad4b-tour-gallery-popup-layout.php")
    frames = source("mu-plugins/mad4b-tour-gallery-popup-layout/frames.js")
    assert "Elementor\\Core\\DynamicTags\\Tag" in tag
    assert "mad4b-jetengine-frame-focus" in tag
    assert "elementor/dynamic_tags/register" in gallery
    assert "{{WRAPPER}}" in gallery
    assert "background-position: {{VALUE}};" in gallery
    assert "background-size: {{VALUE}};" in gallery
    assert "MAD4B_TOUR_FRAME_FOCAL_V1" not in gallery + frames
    assert ".style.backgroundPosition" not in frames
    assert "_mad4b_media_focal_points_v1" not in gallery


def test_existing_jetengine_tour_page_options_owns_extensible_per_photo_fields():
    source_text = source("plugins/mad4b-commerce/modules/compat/tour-content-layout-control.php")
    assert "mad4b_tour_layout_page_options" in source_text
    assert "jet_engine_meta_boxes" in source_text
    assert "static function photo_slots()" in source_text
    assert "mad4b/tour_layout/media_frame_slots" in source_text
    assert "mad4b_media_frame_focus_v2" in source_text
    for field in ("focus_x", "focus_y", "fit", "source_sha256", "rebind_on_save"):
        assert f"'{field}'" in source_text
    assert "existing_repeater_extended" in source_text
    assert "'frame_aspect_ratio'" in source_text
    assert "foreach(self::frame_focus_field()['repeater-fields'] as $new)" in source_text
    assert "'new_fields'=>$added,'new_field'=>$added[0]" in source_text


def test_photo_identity_binding_fail_closed_and_cache_wpml_contract():
    gallery = source("mu-plugins/mad4b-tour-gallery-popup-layout.php")
    quality = source("mu-plugins/mad4b-tour-photo-quality.php")
    assert "hash_equals($sha,$rowSha)" in gallery
    assert "if($validOld&&!empty($row['rebind_on_save']))$old='';" in gallery
    assert "$fallback=['position'=>'','fit'=>'','source'=>'elementor_default'];" in gallery
    assert "MAD4B_Page_Dependency_Cache::invalidate" in gallery
    assert "wpml_get_element_translations" in gallery
    assert "mad4b_media_frame_focus_v2" in quality
    assert "hash_equals($old,(string)($o['origin_sha256']??''))" in quality
    assert "60439" not in gallery
    assert "60439" not in quality
    assert "cover_crop_geometry" in quality
    assert "media_hero_crop_geometry_review_pending" in quality
    assert "focal_cannot_restore_pixels_lost_to_cover" in quality
    assert "withheld_severe_grid_crop_candidates" in quality
    assert "minimum_estimated_crop_fraction" in quality

if __name__ == "__main__":
    for name, fn in sorted(globals().copy().items()):
        if name.startswith("test_") and callable(fn):
            fn()
            print(f"PASS {name}")
