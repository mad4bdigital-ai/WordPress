#!/usr/bin/env python3
"""Fail closed if a preserved, exact enrolled Portfolio Manager file is changed.

This is source integrity, not a signed artifact, permission or live execution.
Files in ops/mad4b-portfolio-signed-binding-v1 are a limited isolated patch
and must not be treated as the full runnable plugin replacement.
"""
from pathlib import Path
import hashlib,json,re,sys

ROOT=Path(__file__).resolve().parents[2]
PLUGIN=ROOT/'wp-content/plugins/mad4b-company-portfolio-manager'
POLICY=Path(__file__).with_name('company-portfolio-live-snapshot-20261117.json')

def abort(reason):raise SystemExit('PORTFOLIO_LIVE_SOURCE_INTEGRITY_DENIED:'+reason)

def run():
    policy=json.loads(POLICY.read_text())
    if policy.get('contract')!='mad4b.portfolio-source-from-enrolled-live-20261117.v1':abort('contract')
    if policy.get('release_authorized') is not False or policy.get('original305_hold_closed') is not False:abort('authority')
    if not PLUGIN.is_dir() or PLUGIN.is_symlink():abort('source_root')
    expected={r['path']:r for r in policy['files']}
    if len(expected)!=len(policy['files']) or len(expected)!=85:abort('source_manifest')
    actual={}
    for f in PLUGIN.rglob('*'):
        if f.is_symlink():abort('symlink:'+str(f))
        if not f.is_file():continue
        rel=f.relative_to(PLUGIN).as_posix()
        if rel not in expected:abort('unexpected_file:'+rel)
        raw=f.read_bytes()
        actual[rel]=hashlib.sha256(raw).hexdigest()
        if actual[rel]!=expected[rel]['sha256'] or len(raw)!=expected[rel]['bytes']:
            abort('source_drift:'+rel)
        if b'-----BEGIN PRIVATE KEY-----' in raw or b'-----BEGIN RSA PRIVATE KEY-----' in raw:
            abort('private_key:'+rel)
    if set(actual)!=set(expected):abort('source_missing')
    if sum(r['bytes'] for r in expected.values())!=policy['runtime_bytes']:abort('size')
    digest=hashlib.sha256(''.join(k+'\0'+actual[k]+'\n' for k in sorted(actual)).encode()).hexdigest()
    if digest!=policy['manifest_sha256']:abort('snapshot_digest')
    main=(PLUGIN/'mad4b-company-portfolio-manager.php').read_text()
    if 'Plugin Name: MAD4B Company Management' not in main or 'MAD4B_Company_Portfolio_Manager_V1' not in main:abort('plugin_identity')
    if not (PLUGIN/'includes/class-mad4b-company-repair-jobs.php').is_file():abort('operational_modules_missing')
    print(json.dumps({'contract':'mad4b.pr814-company-portfolio-full-live-source.integrity.v1',
      'status':'SOURCE_PRESERVED_NOT_RELEASE_CERTIFIED',
      'files_verified':len(actual),'bytes_verified':policy['runtime_bytes'],
      'snapshot_sha256':digest,'complete_live_replacement_source':True,
      'original305_hold_closed':False,'release_authorized':False},sort_keys=True))

if __name__=='__main__':run()
