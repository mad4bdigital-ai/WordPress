"""Quick Preview and UAEL are the original renderers, with separate releases."""
from pathlib import Path
import json
R=Path(__file__).resolve().parents[2]
root=R/'wp-content/mu-plugins'
php=(root/'mad4b-extra-quick-preview-v1.php').read_text()
parent=(root/'mad4b-extra-quick-preview/quick-preview.js').read_text()
frame=(root/'mad4b-extra-quick-preview/quick-preview-frame.js').read_text()
css=(root/'mad4b-extra-quick-preview/quick-preview.css').read_text()
catalog=json.loads((R/'ops/company-code-release-v1/catalog/mad4b-release-components.json').read_text())
assert 'data-mad4b-extra-preview-title' in php
assert 'get_the_title($child)' in php
assert "getAttribute('data-mad4b-extra-preview-title')" in parent
assert "dialog.classList.toggle('mad4b-extra-quick-preview-gallery-active'" in parent
assert 'event.source!==frame.contentWindow' in parent
assert 'event.origin!==location.origin' in parent
assert 'data-mad4b-uael-tour="1"' in frame
assert 'window.parent.postMessage({type:GALLERY,open},location.origin);' in frame
assert 'quick-preview-gallery-active' in css
assert 'elementor-popup-modal:has([data-mad4b-uael-tour="1"])' in php
assert 'elementor/frontend/builder_content_data' in php
assert 'class MAD4B_UAEL_Tour_Gallery' not in php
assert 'wp_cache_flush' not in php+parent+frame+css
assert 'litespeed_purge_all' not in php+parent+frame+css
match={x['id']:x['files'] for x in catalog['components']}
assert match['mad4b-extra-quick-preview-native-gallery-interop']==[
 'wp-content/mu-plugins/mad4b-extra-quick-preview-v1.php',
 'wp-content/mu-plugins/mad4b-extra-quick-preview/quick-preview.js',
 'wp-content/mu-plugins/mad4b-extra-quick-preview/quick-preview-frame.js',
 'wp-content/mu-plugins/mad4b-extra-quick-preview/quick-preview.css']
assert match['mad4b-uael-tour-gallery-tabs']==[
 'wp-content/mu-plugins/mad4b-uael-tour-gallery-addon.php',
 'wp-content/mu-plugins/mad4b-uael-server-defer-v1.php']
assert set(match['mad4b-extra-quick-preview-native-gallery-interop']).isdisjoint(match['mad4b-uael-tour-gallery-tabs'])
print('QUICKVIEW_ORIGINAL_GALLERY_SCOPE_AND_RELEASE_CATALOG_SEPARATION_PASS')
