#!/usr/bin/env python3
"""Promote evidence-backed mobile CSS variants. No CSS generated at request time."""
import hashlib
import json
import os
import re
import pathlib
import shutil
import sys
from datetime import datetime, timezone

ROOT = pathlib.Path(os.environ.get('MAD4B_WP_CONTENT', '/var/www/html/wp-content'))
OUT = pathlib.Path(os.environ.get('MAD4B_OUTPUT', '/out'))
EPOCH = int(os.environ.get('MAD4B_CSS_EPOCH', '-1'))
DRY_RUN = os.environ.get('MAD4B_DRY_RUN', '1') != '0'
SHA = lambda p: hashlib.sha256(p.read_bytes()).hexdigest()
assert EPOCH >= 0, 'CSS epoch must be supplied by WordPress'
assert ROOT.is_dir() and OUT.is_dir()
candidates = json.loads((OUT/'candidates.json').read_text())
qa = json.loads((OUT/'verification.json').read_text())
assert qa['contract'] == 'mad4b.mobile-critical.qa.v1'
assert candidates['contract'] == 'mad4b.mobile-critical.candidates.v1'
host = candidates['host']
assert re.fullmatch(r'[a-z0-9][a-z0-9.-]{3,180}',host), 'Invalid company host'
assert os.environ.get('MAD4B_TARGET_HOST','') == host, 'Host identity required'
storage = ROOT/'mad4b-critical-css-v1'
target = storage/'manifest.json'
old = json.loads(target.read_text()) if target.exists() else {}
assert old.get('site',host)==host, 'Cross-site manifest forbidden'
manifest = {'version':2, 'contract':'mad4b.mobile-critical-css.v2',
            'site':host, 'updated_utc':datetime.now(timezone.utc).isoformat(),
            'entries':{}}
# Keep only verified v2 entries for this same site and current CSS epoch.
if old.get('version') == 2 and old.get('contract') == manifest['contract']:
    for k,v in old.get('entries',{}).items():
        variants={vkey: row for vkey,row in (v.get('variants') or {}).items()
                  if row.get('gate_status')=='pass' and row.get('css_epoch')==EPOCH}
        if variants:manifest['entries'][k]={**v,'variants':variants}
promoted=[]
holds=[]
for item in candidates['candidates']:
    gate=qa.get('gates',{}).get(item['lang'],{})
    if gate.get('decision') != 'passed_evidence' or not {320,390,414,768}.issubset(set(gate.get('widths',[]))):
        holds.append({'language':item['lang'],'reason':'QA_'+gate.get('decision','missing')+'_or_incomplete_viewports'})
        continue
    # Adaptive QA is not a runtime release grant. The current certified
    # consumer has no media-breakpoint contract, so a seemingly passing
    # adaptive test must never become a regular all-width CSS manifest.
    # Remove this HOLD only after a separately reviewed native runtime
    # version and original admission prove that exact adaptive capability.
    if gate.get('adaptiveMaxWidth',0) not in (0,None):
        holds.append({'language':item['lang'],
                      'reason':'ADAPTIVE_RUNTIME_CAPABILITY_NOT_ENROLLED'})
        continue
    requires_hero = bool(gate.get('hero900',False))
    requires_city_deferred = bool(gate.get('deferCity',False))
    required_hero_path = os.environ.get('MAD4B_REQUIRED_HERO_PATH','') if requires_hero else ''
    if requires_hero:
        if not re.fullmatch(r'/wp-content/uploads/[a-zA-Z0-9/_~.%-]+[.]webp',required_hero_path):
            holds.append({'language':item['lang'],'reason':'HERO_POLICY_PATH_MISSING'})
            continue
        poster = ROOT/required_hero_path.removeprefix('/wp-content/')
        if not poster.is_file() or poster.is_symlink():
            holds.append({'language':item['lang'],'reason':'HERO_POSTER_ASSET_NOT_PRESENT'})
            continue
    if gate.get('fullCssPreload') is not True or gate.get('cssPriority') != 'high':
        holds.append({'language':item['lang'],'reason':'UNSUPPORTED_SOURCE_CSS_PRELOAD_STRATEGY'})
        continue
    cssfile = ROOT/'litespeed/css'/item['css_file']
    crit = OUT/item['critical_file']
    if cssfile.is_symlink() or not cssfile.is_file() or SHA(cssfile)!=item['source_sha']:
        holds.append({'language':item['lang'],'reason':'CSS_SOURCE_DRIFT'})
        continue
    if crit.is_symlink() or not crit.is_file() or SHA(crit)!=item['critical_sha'] or crit.stat().st_size>1048576:
        holds.append({'language':item['lang'],'reason':'CRITICAL_ASSET_DRIFT'})
        continue
    reg = manifest['entries'].setdefault(item['source_sha'], {
        'bundle_file': item['css_file'],
        'source_css_bytes':item['source_bytes'], 'variants':{}
    })
    if reg['bundle_file'] != item['css_file']:
        holds.append({'language':item['lang'],'reason':'BUNDLE_CONFLICT'})
        continue
    key=item['route']+'|'+item['lang']+'|mobile'
    reg['variants'][key]={
        'file':item['critical_file'], 'critical_sha':item['critical_sha'],
        'critical_bytes':item['critical_bytes'],
        'route_path':item['route'], 'language':item['lang'], 'device':'mobile',
        'page_id':item['page_id'],
        'css_epoch':EPOCH,
        'elementor_doc_fingerprint':item['dom_sha'],
        'gate_status':'pass',
        'css_fetch_priority':gate.get('cssPriority','low') if gate.get('cssPriority','low') in ('low','auto','high') else 'low',
        'css_full_preload':bool(gate.get('fullCssPreload',False)),
        'evidence':{
            'requires_mobile_hero_priority':requires_hero,
            'requires_city_preload_deferred':requires_city_deferred,
            'required_hero_path':required_hero_path,
            'decision':'passed_evidence',
            'tested_at':gate['testedAt'],
            'qa_sha256':SHA(OUT/'verification.json'),
            'viewports':gate['widths'],
            'min_fcp_gain_ms':gate['minimumFcpGainMs'],
            'max_lcp_regression_ms':gate['worstLcpRegMs'],
            'max_cls':gate['worstCls']
        }
    }
    promoted.append({'language':item['lang'],'route':item['route'],'css_sha':item['source_sha'],'critical_sha':item['critical_sha']})
status={'contract':'mad4b.mobile-critical-promotion.v1','host':host,'epoch':EPOCH,
        'dry_run':DRY_RUN,'promoted':promoted,'holds':holds,'manifest_entries':len(manifest['entries'])}
(OUT/'promotion-readback.json').write_text(json.dumps(status,indent=2)+'\n')
if not promoted:raise SystemExit('No certified CSS variant available')
newdata=(json.dumps(manifest,indent=2)+'\n').encode()
if len(newdata)>65536: raise SystemExit('Manifest too large')
if not DRY_RUN:
    storage.mkdir(mode=0o755,parents=True,exist_ok=True)
    for item in promoted:
        sourceitem=next(x for x in candidates['candidates'] if x['lang']==item['language'])
        src=OUT/sourceitem['critical_file']
        dest=storage/sourceitem['critical_file']
        if dest.exists() and SHA(dest)!=sourceitem['critical_sha']:
            raise SystemExit('Critical destination hash conflict')
        if not dest.exists():
            tmp=storage/('.asset-'+sourceitem['critical_sha'])
            tmp.write_bytes(src.read_bytes());tmp.chmod(0o644)
            tmp.replace(dest)
    if target.exists():
        shutil.copy2(target,OUT/'manifest-before-promotion.json')
        oldsha=SHA(target)
    else:oldsha=None
    tmp=storage/'.manifest-verified-next.json'
    tmp.write_bytes(newdata);tmp.chmod(0o644)
    if (SHA(target) if target.exists() else None)!=oldsha:
        tmp.unlink(missing_ok=True)
        raise SystemExit('CONCURRENT_MANIFEST_CHANGE')
    tmp.replace(target)
print(json.dumps(status,ensure_ascii=False))
