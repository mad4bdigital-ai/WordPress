"""A catalog entry must refer to a tracked immutable Git blob, not just a live file.

Prevents a merged branch dropping a registered module while leaving its release
catalog in place (as happened to Composite Media Integrity on Wayfatours).
"""
import json
from pathlib import Path
import subprocess

ROOT=Path(__file__).resolve().parents[1]
CATALOG=Path('ops/company-code-release-v1/catalog/mad4b-release-components.json')


def git(*args):
    return subprocess.run(['git','-C',str(ROOT),*args],capture_output=True,text=True,check=True).stdout


def test_catalog_files_have_one_tracked_source():
    catalog=json.loads((ROOT/CATALOG).read_text())
    assert catalog['contract']=='mad4b.company-release-components.v1'
    seen={}
    for component in catalog['components']:
        assert 1 <= len(component['files']) <= 24
        for path in component['files']:
            assert path not in seen,(path,seen.get(path),component['id'])
            seen[path]=component['id']
            assert path.startswith('wp-content/') and '..' not in Path(path).parts
            assert (ROOT/path).is_file(),f'MISSING_CATALOG_SOURCE:{path}'
            line=git('ls-files','--stage','--',path).strip()
            assert line.startswith(('100644 ','100755 ')),f'UNTRACKED_CATALOG_SOURCE:{path}'
    assert len(seen)>0
    assert 'wp-content/mu-plugins/mad4b-unified-media-apply.php' in seen
    assert 'wp-content/mu-plugins/mad4b-media-composite-integrity.php' in seen
    assert seen['wp-content/mu-plugins/mad4b-media-rights-extraction-intake.php']=='mad4b-media-rights-intake'


if __name__=='__main__':
    test_catalog_files_have_one_tracked_source()
    print('PASS original release catalog files are all tracked, single-owned and present')
