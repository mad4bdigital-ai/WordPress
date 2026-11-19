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
# Native preservation path for installed legacy v1 consumers.
# Never converts v2/Adaptive QA evidence into a v1 certificate.
def publish_installed_legacy_v1() -> None:
    import fcntl, stat
    def deny(reason):
        raise SystemExit('LEGACY_V1_PUBLISH_DENIED:'+reason)
    if not target.is_file() or target.is_symlink() or not storage.is_dir() or storage.is_symlink():
        deny('MANIFEST_OR_STORAGE_UNAVAILABLE')
    if old.get('version')!=1 or old.get('site')!=host or not isinstance(old.get('entries'),dict):
        deny('NOT_AN_ENROLLED_V1_MANIFEST')
    consumer = ROOT/'mu-plugins/415-mad4b-critical-css-orchestrator.php'
    expected = os.environ.get('MAD4B_CSS_LEGACY_CONSUMER_SHA256','')
    if not re.fullmatch(r'[a-f0-9]{64}',expected) or not consumer.is_file() or consumer.is_symlink():
        deny('EXACT_NATIVE_CONSUMER_REQUIRED')
    if SHA(consumer)!=expected:
        deny('NATIVE_CONSUMER_DRIFT')
    code=consumer.read_text()
    if ("($decoded['version'] ?? null) !== 1" not in code
        or 'media="print" fetchpriority="low"' not in code):
        deny('CONSUMER_NOT_PROVEN_V1_LOW_NO_PRELOAD')
    if (not isinstance(qa.get('tested'),int) or qa['tested'] < 8
        or not isinstance(qa.get('results'),list)
        or any(not isinstance(row,dict) or row.get('pass') is not True
               for row in qa['results'])):
        deny('QA_UNCERTIFIED')
    rows=[]
    for item in candidates['candidates']:
        if not isinstance(item,dict):
            deny('INVALID_CANDIDATE')
        lang=item.get('lang')
        route=item.get('route')
        if not isinstance(lang,str) or not re.fullmatch(r'[a-z]{2}',lang):
            deny('INVALID_LOCALE')
        if not isinstance(route,str) or not re.fullmatch(r'/(?:[a-z]{2}/)?',route):
            deny('NOT_A_LANGUAGE_HOMEPAGE')
        if route!='/' and route!='/' + lang + '/':
            deny('ROUTE_LOCALE_MISMATCH')
        gate=qa.get('gates',{}).get(lang,{})
        if (not isinstance(gate,dict)
            or gate.get('decision')!='passed_evidence'
            or not {320,390,414,768}.issubset(set(gate.get('widths',[])))
            or gate.get('cssPriority')!='low'
            or gate.get('fullCssPreload') is not False
            or gate.get('adaptiveMaxWidth',0) not in (0,None)
            or gate.get('hero900') is not False
            or gate.get('deferCity') is not False):
            deny('LEGACY_V1_QA_STRATEGY_MISMATCH:'+lang)
        observed=[x for x in qa['results'] if x.get('lang')==lang]
        if (len(observed) < 4 or
            not {320,390,414,768}.issubset({x.get('width') for x in observed}) or
            any(x.get('pass') is not True for x in observed)):
            deny('LOCALE_EVIDENCE_INCOMPLETE:'+lang)
        original_css = ROOT/'litespeed/css'/item['css_file']
        critical_css = OUT/item['critical_file']
        if (original_css.is_symlink() or not original_css.is_file()
            or original_css.stat().st_size!=item['source_bytes']
            or SHA(original_css)!=item['source_sha']):
            deny('LIVE_CSS_SOURCE_DRIFT:'+lang)
        if (critical_css.is_symlink() or not critical_css.is_file()
            or critical_css.stat().st_size!=item['critical_bytes']
            or not 16000 <= critical_css.stat().st_size <= 1048576
            or SHA(critical_css)!=item['critical_sha']
            or b'</style' in critical_css.read_bytes().lower()):
            deny('CRITICAL_CSS_ASSET_DRIFT:'+lang)
        if item['source_sha'] in old['entries']:
            deny('EXISTING_V1_ENTRY_MUST_NOT_BE_OVERWRITTEN:'+lang)
        rows.append((lang,item,critical_css))
    if len(rows)!=len(set(lang for lang,*_ in rows)) or len(rows)<1:
        deny('LOCALE_COLLISION')
    manifest={**old,'entries':dict(old['entries'])}
    for lang,item,_ in rows:
        manifest['entries'][item['source_sha']]={
            'file':item['critical_file'],
            'critical_sha':item['critical_sha'],
            'source_css_bytes':item['source_bytes'],
            'critical_bytes':item['critical_bytes'],
            'gate_status':'pass',
            'languages':[lang],
            'devices':['mobile'],
            'coverage':'original-v1-renderer-exact-low-no-preload',
            'qa_evidence':'mad4b.mobile-critical.qa.v1',
            'qa_evidence_sha256':SHA(OUT/'verification.json'),
            'evidence_verified_utc':datetime.now(timezone.utc).isoformat(),
            'gate_viewports':[320,390,414,768],
            'original313_signed':False,
        }
    data=(json.dumps(manifest,indent=2)+'\n').encode()
    if len(data)>32768:
        deny('LEGACY_V1_MANIFEST_EXCEEDS_NATIVE_32KB')
    original_sha=SHA(target)
    status={'contract':'mad4b.mobile-critical-promotion.v1','host':host,
            'format':'v1-native-consumer','epoch':EPOCH,'dry_run':DRY_RUN,
            'promoted':[{'language':lang,'route':item['route'],
                         'css_sha':item['source_sha'],'critical_sha':item['critical_sha']}
                        for lang,item,_ in rows],
            'holds':[],'manifest_entries':len(manifest['entries']),
            'native_consumer_sha256':expected,
            'manifest_before_sha256':original_sha,
            'original313_grant_issued':False}
    (OUT/'promotion-readback.json').write_text(json.dumps(status,indent=2)+'\n')
    if not DRY_RUN:
        # The native publisher owns this exact content-addressed manifest.
        # An installed consumer's SHA is a compatibility fence, not a signed
        # capability enrollment for arbitrary PHP code publication.
        for lang,item,css in rows:
            dest=storage/item['critical_file']
            if dest.is_symlink() or (dest.exists() and SHA(dest)!=item['critical_sha']):
                deny('ASSET_CONFLICT:'+lang)
            if not dest.exists():
                tmp=storage/('.asset-'+item['critical_sha'])
                fd=os.open(tmp,os.O_WRONLY|os.O_CREAT|os.O_EXCL|os.O_NOFOLLOW,0o644)
                try:
                    with os.fdopen(fd,'wb') as stream:
                        stream.write(css.read_bytes())
                        stream.flush()
                        os.fsync(stream.fileno())
                    os.replace(tmp,dest)
                finally:
                    if tmp.exists():tmp.unlink()
        backup=OUT/'manifest-before-promotion.json'
        backup.write_bytes(target.read_bytes())
        previous=target.stat()
        next_file=storage/'.manifest-verified-next.json'
        if next_file.exists() or next_file.is_symlink():
            deny('STAGING_MANIFEST_ALREADY_PRESENT')
        fd=os.open(next_file,os.O_WRONLY|os.O_CREAT|os.O_EXCL|os.O_NOFOLLOW,0o600)
        try:
            with os.fdopen(fd,'wb') as stream:
                stream.write(data);stream.flush();os.fsync(stream.fileno())
            if SHA(target)!=original_sha:
                deny('CONCURRENT_MANIFEST_CHANGE')
            os.chmod(next_file,stat.S_IMODE(previous.st_mode))
            os.chown(next_file,previous.st_uid,previous.st_gid)
            os.replace(next_file,target)
        finally:
            if next_file.exists():next_file.unlink()
        if SHA(target)!=hashlib.sha256(data).hexdigest():
            deny('POST_PUBLISH_MANIFEST_CAS_FAILURE')
    print(json.dumps(status,ensure_ascii=False))

if os.environ.get('MAD4B_CSS_NATIVE_V1_COMPAT','0') == '1':
    publish_installed_legacy_v1()
    raise SystemExit(0)

# The published CSS manifest is a runtime data format, not an authorization
# to upgrade PHP. Refuse v2 until the actual installed consumer implements its
# route/locale/Elementor/CSS-epoch verifier. This checks compatibility only;
# independently signed Original313 remains necessary for any PHP code release.
consumer=ROOT/'mu-plugins/415-mad4b-critical-css-orchestrator.php'
if not consumer.is_file() or consumer.is_symlink():
    raise SystemExit('V2_RUNTIME_CONSUMER_MISSING')
consumer_source=consumer.read_text()
if ("private static function verified_variant(" not in consumer_source
    or "mad4b.mobile-critical-css.v2" not in consumer_source
    or "elementor_doc_fingerprint" not in consumer_source):
    raise SystemExit('V2_RUNTIME_CONSUMER_NOT_ENROLLED')

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
