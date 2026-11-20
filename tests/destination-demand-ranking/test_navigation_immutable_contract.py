"""Source hermetic validation: navigation cache busting and release ownership.

Run with python3; no network, WordPress, or production changes.
"""
from pathlib import Path
import importlib.util
import json
import subprocess
import sys

ROOT=Path(__file__).resolve().parents[2]
BUILD=ROOT/'ops/destination-demand-ranking/build-navigation-asset.py'
COMPONENT=ROOT/'ops/company-code-release-v1/catalog/mad4b-release-components.json'
sys.path.insert(0,str(ROOT/'ops/company-code-release-v1'))
import company_code_release as release


def main():
    checked=subprocess.run([sys.executable,str(BUILD),'--check'],text=True,capture_output=True,check=True)
    result=json.loads(checked.stdout)
    manifest=json.loads(COMPONENT.read_text())
    entries=[x for x in manifest['components'] if x.get('id')==result['component']]
    assert len(entries)==1
    assert release.registered_component_files(entries[0])==result['release_paths']
    loader=(ROOT/result['release_paths'][0]).read_text()
    assert 'self::ASSET_SHA256' in loader
    assert "hash_equals($digest" in loader
    assert "?mad4b_asset_v" not in loader
    assert 'data-no-optimize' in loader
    assert 'jet-engine/listing/grid/lazy-load/options' in loader
    assert 'wp_enqueue_scripts' in loader
    assert not any((ROOT/'wp-content/plugins/mad4b-platform/legacy-mu-tree/assets/mad4b-static-js').glob('navigation-listings.*.min.js'))
    print('NAV_IMMUTABLE_RELEASE_PASS',result['asset'],'registered',len(entries),'cached_url_sha',result['code_sha256'][:12])


if __name__=='__main__':main()
