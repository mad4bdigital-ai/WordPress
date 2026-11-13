"""Source-only guard of unchanged UAEL/Elementor/gallery renderer boundaries."""
from pathlib import Path
R=Path(__file__).resolve().parents[2]
A=(R/'wp-content/mu-plugins/mad4b-uael-tour-gallery-addon.php').read_text()
D=(R/'wp-content/mu-plugins/mad4b-uael-server-defer-v1.php').read_text()
assert "'timeline_plan'=>$timeline_plan" in A
assert 'self::category_label(\n         $section,$locale,$timeline_plan,$is_stage,$show_timeline)' in A
assert "MAD4B_Gallery_Stage_Policy_V1::enabled($canonical)" in A
assert "'uael-image-gallery' && $widget->get_id()==='1e96a81'" in A
assert "'gallery_style','masonry'" in A
assert "'click_action','lightbox'" in A
assert "'uael_image_gallery_tabs'" in A
assert 'data-mad4b-timeline=' in A
assert 'wp_cache_get($cached_key' in D and 'wp_cache_set($cached_key' in D
assert "$label_code_sha]" in D
assert "hash_file('sha256',$gallery_addon)" in D
assert 'wp_cache_flush(' not in D
assert 'litespeed_purge_all' not in D
assert 'purge_all' not in D.lower()
print('UAEL_NATIVE_RENDERER_AND_SCOPED_AJAX_CACHE_CONTRACT_PASS')
