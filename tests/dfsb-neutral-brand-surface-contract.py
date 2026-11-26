#!/usr/bin/env python3
"""Neutral display copy; intentionally not a migration of historical wire keys."""
import re
from pathlib import Path
root=Path(__file__).resolve().parents[1]
plugin=root/'wp-content/plugins/dynamic-filter-seo-bridge'
brand=re.compile(r"\b(?:Egypt Tour Gates|Wayfa\s*Tours|All Royal Egypt)\b|(?:egypttourgates|wayfatours|allroyalegypt)\.com",re.I)
for path in plugin.rglob('*'):
    if path.is_file() and path.suffix in {'.php','.js','.css','.md','.json','.txt'}:
        assert not brand.search(path.read_text()),str(path)
# Only human-readable ETG labels, not etg-* or etg_* compatibility identifiers.
copy=re.compile(r"\bETG(?:[ ,]|'s )")
for path in [*(plugin/'includes/Admin').rglob('*.php'),*(plugin/'includes/Elementor').rglob('*.php'),*(plugin/'includes/Integration').rglob('*.php'),*(plugin/'includes/JetEngine').rglob('*.php'),*(plugin/'assets/js').rglob('*.js'),root/'wp-content/plugins/mad4b-site-control-plane/includes/adapters/class-mad4b-scp-dfsb-adapter.php']:
    assert not copy.search(path.read_text()),str(path)
assert "'ETG_DFSB_VERSION' => DFSB_VERSION" in (plugin/'dynamic-filter-seo-bridge.php').read_text()
assert "'etg_dfsb_settings' => 'dfsb_settings'" in (plugin/'includes/Runtime/LegacyOptionMirror.php').read_text()
print('DFSB_NEUTRAL_BRAND_SURFACE: PASS')
