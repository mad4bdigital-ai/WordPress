"""Hermetic fail-closed contract for a scoped and version-pinned vendor patch."""
import importlib.util
from pathlib import Path
root = Path(__file__).resolve().parents[2]
p = root / 'tools/performance/build_elementor_anchor_coalesced.py'
spec = importlib.util.spec_from_file_location('anchor_patch', p)
m = importlib.util.module_from_spec(spec)
spec.loader.exec_module(m)
assert m.transform(('prefix'+m.OLD+'suffix').encode(),enforce_sha=False) == ('prefix'+m.NEW+'suffix').encode()
for inp in ('missing original',m.OLD*2,m.NEW):
    try:m.transform(inp.encode(),enforce_sha=False)
    except ValueError:pass
    else:raise AssertionError('unrecognized vendor code accepted')
try:m.transform(m.OLD.encode(),enforce_sha=True)
except ValueError:pass
else:raise AssertionError('unknown vendor sha accepted')
src = (root/'wp-content/mu-plugins/439-mad4b-elementor-anchor-observer-coalesce.php').read_text()
for marker in ('script_loader_src', "'elementor-frontend'", "is_singular( 'tours-and-activities' )",m.ORIGINAL_SHA,m.PATCH_SHA,"isset( $_GET['elementor-preview'] )"):
    assert marker in src,marker
print('PASS: exact vendor SHA and observer contract, tour scope, editor exemption, fail-closed')
