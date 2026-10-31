from pathlib import Path
import py_compile
root=Path(__file__).resolve().parents[4]
mu=(root/'wp-content/mu-plugins/301-mad4b-elementor-localization.php').read_text()
core=(root/'wp-content/plugins/mad4b-site-control-plane/includes/class-mad4b-scp-content-readiness-alerts.php').read_text()
assert 'mad4b/localization-jobs' in mu
assert 'mad4b/localization-claim' in mu
assert 'mad4b/localization-ack' in mu
assert 'elementor/editor/after_save' in mu
assert 'updated_post_meta' in mu
assert 'missing_widgets_copied' in mu and "=>false" in mu
assert 'translation_texts_overwritten' in mu
assert 'source_issue_still_visible' in mu
assert 'MAD4B_CLI_TARGET_SITE_UUID' in (root/'ops/qa/mad4b-elementor-localization/mad4b-localization-runner.py').read_text()
assert "'dynamic-filter-seo-bridge'" in core
assert "'mad4b-elementor-localization'" in core
assert "get_current_blog_id().'|'.$type.'|'.$object_id.'|'.$code" in core
for path in (root/'ops/qa/mad4b-elementor-localization').glob('*.py'):py_compile.compile(str(path),doraise=True)
print('PASS: Elementor/WPML integrity source contracts + Python syntax')
