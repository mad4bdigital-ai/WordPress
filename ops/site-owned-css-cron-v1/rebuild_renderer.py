#!/usr/bin/env python3
"""Build one immutable site renderer from tracked source plus exact v14 pins.

Does not install to a live location, run WordPress, generate CSS or touch Cron.
"""
import hashlib,json,os,shutil,stat,sys
from pathlib import Path
HERE=Path(__file__).resolve().parent
WORKTREE=HERE.parents[1]
BASE=WORKTREE/'ops/dynamic-critical-css-20261116'
OVERRIDES=HERE/'renderer-v10-overrides'
MANIFEST=HERE/'renderer-v14-manifest.json'

def tree_digest(root):
    h=hashlib.sha256()
    names=[]
    for file in root.rglob('*'):
        if file.is_symlink():raise ValueError('renderer_symlink')
        if file.is_file():names.append(file)
        elif not file.is_dir():raise ValueError('renderer_unsupported_file')
    for file in sorted(names,key=lambda f:f.relative_to(root).as_posix()):
        key=file.relative_to(root).as_posix().encode()
        data=file.read_bytes()
        h.update(len(key).to_bytes(4,'big')+key+len(data).to_bytes(8,'big')+data)
    return h.hexdigest()

def build(out):
    out=Path(out)
    if out.exists() or out.is_symlink() or not out.is_absolute():
        raise ValueError('renderer_target_must_be_fresh_absolute_path')
    if out.parent.is_symlink():
        raise ValueError('renderer_parent_symlink')
    ledger=json.loads(MANIFEST.read_text())
    if ledger['contract']!='mad4b.css.site-owned-renderer.v1':
        raise ValueError('renderer_manifest_contract')
    records=ledger['files']
    if len(records)!=119:raise ValueError('renderer_manifest_file_count')
    out.mkdir(mode=0o700)
    try:
        for name,expected in sorted(records.items()):
            path=Path(name)
            if path.is_absolute() or '..' in path.parts or not path.parts:
                raise ValueError('renderer_unsafe_relative_file')
            original=OVERRIDES/path if (OVERRIDES/path).is_file() else BASE/path
            if path.as_posix()=='run-site.sh':
                text=original.read_text()
                anchor='fi\nGATE_STATUS=hold\nGATE_REASON=source_unavailable'
                patch='''fi
if [[ "${MAD4B_CSS_PROBE_ONLY:-0}" == 1 ]];then
  # Probe-only can ACK an unchanged previously certified source above.
  # Changed CSS requires ordinary CPU/Chromium QA; never issue a
  # certificate, start a browser, or publish a manifest in probe mode.
  echo "DEFER $SITE CSS source changed; full resource-admitted run required"
  exit 75
fi
GATE_STATUS=hold
GATE_REASON=source_unavailable'''
                if text.count(anchor)!=1:raise ValueError('renderer_probe_patch_source_mismatch')
                raw=text.replace(anchor,patch).encode()
            else:
                if original.is_symlink() or not original.is_file():
                    raise ValueError('renderer_source_missing_or_symlink')
                raw=original.read_bytes()
            if hashlib.sha256(raw).hexdigest()!=expected:
                raise ValueError('renderer_file_sha_mismatch:'+name)
            dest=out/path
            dest.parent.mkdir(exist_ok=True,parents=True)
            dest.write_bytes(raw)
            dest.chmod(0o555 if name.endswith('.sh') else 0o444)
        for folder in sorted((p for p in out.rglob('*') if p.is_dir()),reverse=True):
            folder.chmod(0o555)
        out.chmod(0o555)
        digest=tree_digest(out)
        if digest!=ledger['tree_sha256']:
            raise ValueError('renderer_tree_sha_mismatch')
        print('SITE_RENDERER_BUILD_CERTIFIED',len(records),digest)
    except Exception:
        shutil.rmtree(out)
        raise

if __name__=='__main__':
    if len(sys.argv)!=2:raise SystemExit('usage: rebuild_renderer.py /absolute/fresh/target')
    build(sys.argv[1])
