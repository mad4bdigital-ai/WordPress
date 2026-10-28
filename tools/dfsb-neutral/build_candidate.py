#!/usr/bin/env python3
"""Build a deterministic, unsigned DFSB review package from exact Git source.

This is *not* release certification. The release authority must subsequently
verify provenance, external approvals, installed bytes and runtime evidence.
"""
import argparse
import hashlib
import json
from pathlib import Path
import subprocess
import sys
import zipfile

SRC_PREFIX = 'wp-content/plugins/dynamic-filter-seo-bridge/'
PLUGIN = 'dynamic-filter-seo-bridge'
EXCLUDE = {'build-identity.json', 'installed-content-manifest.json', 'etg-dfsb-provenance.txt'}


def git(repo, *args):
    return subprocess.check_output(['git', '-C', str(repo), *args]).decode().strip()


def build(repo, output):
    repo = repo.resolve()
    output = output.resolve()
    current = git(repo, 'rev-parse', 'HEAD')
    tree = git(repo, 'rev-parse', 'HEAD^{tree}')
    assert len(current) == 40 and len(tree) == 40
    tracked = subprocess.check_output(['git', '-C', str(repo), 'ls-files', '-z', '--', SRC_PREFIX]).decode().split('\0')
    files = sorted(t[len(SRC_PREFIX):] for t in tracked if t)
    if not files or any(f in EXCLUDE for f in files):
        raise RuntimeError('no source files, or generated files committed')
    # CI checks out the exact PR head. Never mix unstaged sources into the package.
    status = git(repo, 'status', '--porcelain', '--', SRC_PREFIX)
    if status:
        raise RuntimeError('source checkout is dirty')
    source = repo / SRC_PREFIX
    data = {}
    for f in files:
        path = source / f
        if not path.is_file() or path.is_symlink() or path.resolve().is_relative_to(source.resolve()) is False:
            raise RuntimeError('invalid source path '+f)
        disk = path.read_bytes()
        committed = subprocess.check_output(['git', '-C', str(repo), 'show', f'HEAD:{SRC_PREFIX}{f}'])
        if disk != committed:
            raise RuntimeError('source bytes do not match commit '+f)
        data[f] = disk
    main = data.get('dynamic-filter-seo-bridge.php', b'').decode()
    import re
    match = re.search(r'\* Version:\s*([^\s]+)', main)
    if not match:
        raise RuntimeError('no WordPress version header')
    version = match.group(1)
    if f"define( 'DFSB_VERSION', '{version}' );" not in main:
        raise RuntimeError('version header and runtime constant differ')
    if '-local.' not in version:
        raise RuntimeError('review source must be an explicitly held -local candidate')
    identity = {'contract': 'etg.dfsb.embedded-build-identity.v1',
                'git_sha': current, 'tree_sha': tree, 'plugin_version': version}
    manifest = {'contract': 'etg.dfsb.installed-content-manifest.v1',
                'algorithm': 'sha256',
                'files': {f: hashlib.sha256(data[f]).hexdigest() for f in files}}
    data['build-identity.json'] = (json.dumps(identity, indent=2)+'\n').encode()
    data['installed-content-manifest.json'] = (json.dumps(manifest, indent=2)+'\n').encode()
    output.parent.mkdir(parents=True, exist_ok=True)
    with zipfile.ZipFile(output, 'w', compression=zipfile.ZIP_DEFLATED, compresslevel=9) as z:
        for name in sorted(data):
            zi = zipfile.ZipInfo(f'{PLUGIN}/{name}', (2026, 10, 28, 0, 0, 0))
            zi.compress_type = zipfile.ZIP_DEFLATED
            zi.external_attr = 0o100644 << 16
            z.writestr(zi, data[name], compress_type=zipfile.ZIP_DEFLATED, compresslevel=9)
    report = {'status': 'PASS', 'unsigned': True, 'certified': False, 'source_sha': current,
              'root_tree_sha': tree, 'files': len(files), 'version': version,
              'zip_sha256': hashlib.sha256(output.read_bytes()).hexdigest()}
    print(json.dumps(report))


if __name__ == '__main__':
    ap = argparse.ArgumentParser()
    ap.add_argument('--repo', type=Path, default=Path(__file__).resolve().parents[2])
    ap.add_argument('--output', type=Path, required=True)
    args = ap.parse_args()
    try:
        build(args.repo, args.output)
    except Exception as err:
        print(f'candidate build rejected: {err}', file=sys.stderr)
        sys.exit(1)
