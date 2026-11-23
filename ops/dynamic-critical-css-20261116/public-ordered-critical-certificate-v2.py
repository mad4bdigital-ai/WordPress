#!/usr/bin/env python3
"""Source-owned v2 critical CSS certificate publisher for the existing 422 runtime.

Never issues a certificate from compiler output alone: it needs original
first-party WPML catalog route, current event generations, fully pinned
manifest and five-viewport *real-CSS-URL* performance/admission evidence.
Dry run does not touch WordPress. Publishing is separately gated by exact
deployed 422 + companion hashes and a release-specific opt-in.
"""
from __future__ import annotations
import hashlib
import importlib.util
import json
import os
import pathlib
import re
import stat
import sys
import tempfile
import time
from pathlib import Path

HERE=Path(__file__).resolve().parent
def module(filename,name):
    spec=importlib.util.spec_from_file_location(name,HERE/filename)
    obj=importlib.util.module_from_spec(spec)
    spec.loader.exec_module(obj)
    return obj
admission=module('public-ordered-critical-admission.py','mad4b_multicss_admission')
ordered=module('public-css-dependency-manifest.py','mad4b_multicss_manifest')
legacy=module('public-route-certificate.py','mad4b_original_queried_object')
CONTRACT='mad4b.public-critical-css.route-certificate.v2'
VIEWS=[320,390,414,767,768]
LIVE_ROOTS={
 '/opt/hostinger-migrations/hosting-3/etg-commerce-staging/site-copy/wp-content',
 '/opt/wayfa-rebuild-20261102/site-copy/wp-content'
}
def sha(data):return hashlib.sha256(data).hexdigest()
def data(file,limit=2_000_000):
    p=Path(file)
    if p.is_symlink() or not p.is_file() or p.stat().st_size>limit:raise ValueError('invalid-immutable-evidence')
    return json.loads(p.read_text())
def guard_int(value,name):
    if not isinstance(value,int) or isinstance(value,bool) or value<0:
        raise ValueError('untrusted-'+name)
    return value
def source_release_proof(job):
    """Admission of immutable CSS source/build inputs, NOT browser performance."""
    folder=Path(job)
    original=data(folder/'multi-css-source-report.json')
    manifest=data(folder/'css-dependencies.json')
    candidate=data(folder/'ordered-critical-candidate.json')
    compiled=data(folder/'compiled-chunks.json')
    html_file=folder/'source.html'
    key=manifest.get('manifest_sha256')
    if (manifest.get('contract')!=ordered.CONTRACT
        or not isinstance(key,str)
        or not re.fullmatch(r'[a-f0-9]{64}',key)
        or sha(ordered.canonical({k:v for k,v in manifest.items()
                                  if k!='manifest_sha256'}))!=key):
        raise ValueError('invalid-source-only-manifest')
    if (original.get('contract')!='mad4b.public-css.manifest-observer.v1'
        or original.get('host')!=manifest.get('host')
        or original.get('manifest_sha256')!=key
        or not original.get('css_source_proven')
        or original.get('public_css_route_certificate_issued') is not False
        or original.get('fastcgi_ready_for_promotion') is not False
        or html_file.is_symlink() or not html_file.is_file()
        or sha(html_file.read_bytes())!=original.get('original_html_sha256')):
        raise ValueError('untrusted-original-css-or-html-snapshot')
    if (candidate.get('contract')!='mad4b.public-css.ordered-critical-candidate.v1'
        or candidate.get('manifest_sha256')!=key
        or candidate.get('host')!=original.get('host')
        or candidate.get('path')!=original.get('path')
        or candidate.get('viewports')!=[320,390,414,767,768]
        or candidate.get('critical_viewports')!=[320,390,414,767]
        or candidate.get('desktop_validation_viewports')!=[768]
        or candidate.get('observation_count')!=5
        or candidate.get('source_cascade_slots')!=original.get('cascade_slots')
        or candidate.get('public_route_certificate_issued') is not False
        or not isinstance(candidate.get('critical_total_bytes'),int)
        or not 16000<=candidate['critical_total_bytes']<=1048576):
        raise ValueError('critical-build-source-proof-invalid')
    src=compiled.get('chunks')
    slots=candidate.get('critical_slots')
    if (compiled.get('manifest_sha256')!=key or not isinstance(src,list)
        or not isinstance(slots,list) or not src or len(src)!=len(slots)
        or sum(x.get('critical_bytes',0) for x in slots)!=candidate['critical_total_bytes']):
        raise ValueError('source-chunk-accounting-invalid')
    for source,critical in zip(src,slots):
        if (source.get('slot')!=critical.get('slot')
            or source.get('sha256')!=critical.get('source_sha256')
            or source.get('bytes')!=critical.get('source_bytes')
            or not isinstance(critical.get('critical_bytes'),int)
            or critical['critical_bytes']<0
            or not isinstance(critical.get('critical_sha256'),str)
            or not re.fullmatch(r'[a-f0-9]{64}',critical['critical_sha256'])):
            raise ValueError('critical-provenance-invalid')
        if critical['critical_bytes']:
            name=critical.get('critical_file')
            if name!=critical['critical_sha256']+'.css':
                raise ValueError('critical-asset-name-untrusted')
            asset=folder/name
            if asset.is_symlink() or not asset.is_file() or asset.stat().st_size!=critical['critical_bytes']:
                raise ValueError('critical-asset-unavailable')
            if sha(asset.read_bytes())!=critical['critical_sha256']:
                raise ValueError('critical-asset-digest-untrusted')
        elif critical.get('critical_file') is not None:
            raise ValueError('empty-critical-asset-must-be-absent')
    return {'decision':'source_verified_for_post_release_qa',
            'host':original['host'],'path':original['path'],
            'language':original['language'],
            'market_country':original['market_country'],
            'currency':original['currency'],
            'source_manifest_sha256':key}

def certificate(catalog,route,state,job,wp_content,runtime,consumer,*,release_first=False):
    decision=(source_release_proof(job) if release_first else admission.proof(job))
    if not release_first and decision['decision']!='qualified_for_delivery_review':
        raise ValueError('multi-css-FCP-LCP-or-interaction-qualification-HOLD')
    # Only the legacy pre-QA test path requires fresh performance observations.
    # Live release-first does NOT require their existence; they run afterward.
    qa_file=Path(job)/'ordered-native-critical-qa.json'
    if not release_first:
        if qa_file.is_symlink() or not qa_file.is_file():
            raise ValueError('missing-private-browser-QA')
        attested_at=qa_file.stat().st_mtime
        age=time.time()-attested_at
        if age < -300 or age > 6*3600:
            raise ValueError('stale-multi-css-browser-performance-QA')
    else:
        attested_at=0
    original=data(Path(job)/'multi-css-source-report.json')
    source_manifest=data(Path(job)/'css-dependencies.json')
    candidate=data(Path(job)/'ordered-critical-candidate.json')
    native=(data(Path(job)/'ordered-native-critical-qa.json')
            if not release_first else None)
    full_html=(Path(job)/'source.html').read_text()
    if (catalog.get('contract')!='mad4b.public-critical-css.routes.v1'
        or route not in catalog.get('routes',[])
        or state.get('critical_css')!='on'
        or state.get('site_host')!=catalog.get('host')
        or catalog.get('host')!=decision['host']
        or route.get('path')!=decision['path']
        or route.get('language')!=decision['language']
        or route.get('type') not in legacy.ALLOWED_PUBLIC
        or route.get('id') in (None,False)
        or route.get('path') in ('/','/'+str(route.get('language'))+'/')
        or original.get('route_key')!=route.get('route_key')
        or not isinstance(route.get('revision'),int)
        or isinstance(route['revision'],bool)):
        raise ValueError('untrusted-WordPress-catalog-route-binding')
    if (original['market_country']!=decision['market_country']
        or original['currency']!=decision['currency']
        or state.get('market_country')!=decision['market_country']
        or state.get('currency')!=decision['currency']
        or route['language'] not in state.get('wpml_languages',[])):
        raise ValueError('trusted-country-currency-or-WPML-language-drift')
    runtime=Path(runtime);consumer=Path(consumer)
    if (runtime.is_symlink() or consumer.is_symlink()
        or not runtime.is_file() or not consumer.is_file()):
        raise ValueError('original-runtime-or-v2-consumer-missing')
    runtime_sha=sha(runtime.read_bytes());consumer_sha=sha(consumer.read_bytes())
    if state.get('runtime_sha')!=runtime_sha or state.get('consumer_v2_sha')!=consumer_sha:
        raise ValueError('native-422-and-Companion-V2-release-SHAs-not-admitted')
    if guard_int(state.get('css_epoch'),'css-epoch')<1 or state.get('native_breakpoint')!=767:
        raise ValueError('untrusted-breakpoint-or-CSS-epoch')
    global_gen=guard_int(state.get('global_generation'),'global-generation')
    listing_gen=guard_int(state.get('listing_generation'),'listing-generation')
    route_gen=guard_int(route.get('revision'),'source-route-generation')
    if (state.get('route_generation',route_gen)!=route_gen):
        raise ValueError('WordPress-post-route-generation-changed')
    # Independent source/DOM checks from the original v1 publisher,
    # without inheriting its single LiteSpeed stylesheet assumption.
    legacy.verify_queried_object(route,full_html)
    body=full_html[full_html.lower().find('<body'):]
    docs=sorted({int(i) for i in re.findall(
        r'(?<![a-z0-9_-])elementor-(\d+)\b',body,re.I)})
    graph=sha(','.join(map(str,docs)).encode())
    if 'id="mad4b-public-critical-css-v1"' in full_html or 'data-mad4b-ccss-v2-rest' in full_html:
        raise ValueError('already-optimized-original-HTML')
    market_sensitive=(route['type'] not in {'page','post'} or any(
        marker in body for marker in ['jet-listing-grid','woocommerce-Price-amount',
              'mad4b-pricing','mad4b-rate','tour-rate','data-price=']))
    if market_sensitive and not (
       decision['market_country']=='base' or re.fullmatch('[A-Z]{2}',decision['market_country'])):
        raise ValueError('dynamic-market-unverified')
    if not market_sensitive and decision['market_country']!='static':
        raise ValueError('static-page-market-mismatch')
    # Recompile the source in the exact same site root and verify the
    # browser-captured source chunk SHA. No stale CSS can produce v2.
    rebuilt=ordered.compile_manifest(source_manifest,wp_content)
    originals=data(Path(job)/'compiled-chunks.json')
    original_chunks=originals.get('chunks',[])
    if (len(rebuilt['chunks'])!=len(candidate['critical_slots'])
        or len(rebuilt['chunks'])!=len(original_chunks)
        or rebuilt['manifest_sha256']!=decision['source_manifest_sha256']):
        raise ValueError('CSS-source-compiler-source-drift')
    output=[]
    for calculated,observed,critical in zip(rebuilt['chunks'],original_chunks,candidate['critical_slots']):
        if (calculated['sha256']!=observed.get('sha256')
            or calculated['slot']!=critical.get('slot')
            or calculated['sha256']!=critical.get('source_sha256')
            or calculated['bytes']!=critical.get('source_bytes')):
            raise ValueError('critical-chunk-provenance-drift')
        output.append({'slot':critical['slot'],
           'source_sha256':calculated['sha256'],'source_bytes':calculated['bytes'],
           'critical_sha256':critical['critical_sha256'],
           'critical_file':critical['critical_file'],
           'critical_bytes':critical['critical_bytes']})
    values=[] if release_first else native['viewport_results']
    if not release_first and (len(values)!=5 or [v['width'] for v in values]!=VIEWS):
        raise ValueError('native-URLs-viewports-not-complete')
    issued=int(time.time())
    evidence=({'decision':'pending_post_release','tested':0,'viewports':[],
               'performance_source':'post-release-real-original-css-urls',
               'verified_at':0}
              if release_first else
              {'decision':'passed_evidence','tested':5,'viewports':VIEWS,
               'performance_source':'real-original-css-urls',
               'qa_sha256':sha(qa_file.read_bytes()),
               'minimum_mobile_fcp_gain_ms':min(v['fcp_gain_ms'] for v in values[:4]),
               'worst_lcp_reg_ms':max(v['lcp_reg_ms'] for v in values),
               'verified_at':int(attested_at)})
    return {'contract':CONTRACT,
       'status':'released_pending_qa' if release_first else 'certified',
       'released_at':issued,'post_release_deadline':issued+6*3600 if release_first else 0,
       'host':decision['host'],
       'path':route['path'],'language':route['language'],'type':route['type'],
       'object_id':route['id'],'market_country':decision['market_country'],
       'currency':decision['currency'],'css_epoch':state['css_epoch'],
       'runtime_sha':runtime_sha,'consumer_sha':consumer_sha,
       'critical_cap_px':767,'native_mobile_breakpoint':767,
       'source_manifest':source_manifest,
       'source_manifest_sha256':source_manifest['manifest_sha256'],
       'critical_slots':output,'critical_total_bytes':candidate['critical_total_bytes'],
       'elementor_doc_fingerprint':graph,
       'dependencies':{'global_generation':global_gen,
          'route_generation':route_gen,'listing_generation':listing_gen,
          'contains_dynamic_listing':market_sensitive},
       'evidence':evidence,
       'certified_at':issued if not release_first else 0}

def publish(cert,job,wp_content):
    wp=Path(wp_content)
    if str(wp) not in LIVE_ROOTS or wp.is_symlink():
        raise ValueError('non-enrolled-live-WordPress-site')
    # Deploying only a Git clone cannot authorize publication into WP.
    runtime=wp/'mu-plugins/422-mad4b-public-critical-css-runtime.php'
    companion=wp/'mu-plugins/422-mad4b-public-critical-css-v2-consumer.php'
    if (not runtime.is_file() or runtime.is_symlink()
        or not companion.is_file() or companion.is_symlink()
        or sha(runtime.read_bytes())!=cert['runtime_sha']
        or sha(companion.read_bytes())!=cert['consumer_sha']
        or os.environ.get('MAD4B_MULTI_CSS_V2_RELEASE_APPROVED')!='1'):
        raise ValueError('live-native-release-and-approval-not-proven')
    base=wp/'mad4b-public-critical-css-v1'
    paths=[base,base/'assets',base/'certs-v2']
    for directory in paths:
        if directory.is_symlink():raise ValueError('public-v2-directory-symlink')
        directory.mkdir(mode=0o755,exist_ok=True)
        if directory.is_symlink() or not directory.is_dir():raise ValueError('invalid-v2-directory')
        permissions=stat.S_IMODE(directory.stat().st_mode)
        if permissions&0o022:raise ValueError('unsafe-world-writable-directory')
        directory.chmod(0o755)
    # Copy all immutable source-proven critical chunks. Certificate goes last.
    for slot in cert['critical_slots']:
        if not slot['critical_bytes']:continue
        name=slot['critical_file']
        src=Path(job)/name
        dest=base/'assets'/name
        if src.is_symlink() or not src.is_file() or src.stat().st_size!=slot['critical_bytes']:
            raise ValueError('critical-source-bounds')
        if sha(src.read_bytes())!=slot['critical_sha256']:
            raise ValueError('critical-byte-SHA-changed-during-publish')
        if dest.is_symlink():raise ValueError('unsafe-existing-critical-asset')
        if dest.exists():
            if sha(dest.read_bytes())!=slot['critical_sha256']:
                raise ValueError('content-addressed-asset-collision')
        else:_atomic(dest,src.read_bytes())
    routekey=sha((cert['host']+'|'+cert['language']+'|'+cert['path']+'|'+
       cert['market_country']+'|'+cert['currency']).encode())
    final=base/'certs-v2'/(routekey+'.json')
    if final.is_symlink():raise ValueError('certificate-path-symlink')
    payload=(json.dumps(cert,indent=2,sort_keys=True,ensure_ascii=True)+'\n').encode()
    _atomic(final,payload)
    if data(final)!=cert:raise ValueError('certificate-publish-readback-mismatch')
    return str(final)
def _private_staged_path(cert,wp_content):
    """No candidate marker or CSS is exposed inside the public document root."""
    wp=Path(wp_content)
    if str(wp) not in LIVE_ROOTS or wp.is_symlink():
        raise ValueError('unenrolled-private-v2-stage')
    base=wp.parent.parent/'operations/css-cron-v1/candidates-v2'
    for parent in [base.parent,base]:
        if parent.is_symlink():raise ValueError('unsafe-staging-parent')
    base.mkdir(parents=True,mode=0o700,exist_ok=True)
    st=base.stat()
    if st.st_uid!=0 or stat.S_IMODE(st.st_mode)!=0o700:
        raise ValueError('private-stage-must-be-root-only')
    identity=sha((cert['host']+'|'+cert['language']+'|'+cert['path']+'|'
         +cert['market_country']+'|'+cert['currency']).encode())
    return base/(identity+'.json')


def _private_atomic(dest,payload):
    fd,tmp=tempfile.mkstemp(prefix='.mad4b-candidate-',dir=dest.parent)
    try:
        with os.fdopen(fd,'wb') as f:
            os.fchmod(f.fileno(),0o600)
            f.write(payload);f.flush();os.fsync(f.fileno())
        os.replace(tmp,dest)
    finally:
        if os.path.exists(tmp):os.unlink(tmp)


def stage_candidate(cert,job,wp_content):
    """Release the exact source-bound candidate privately, without public HTML."""
    if (cert.get('contract')!=CONTRACT
        or cert.get('status')!='released_pending_qa'
        or cert.get('evidence',{}).get('decision')!='pending_post_release'
        or cert.get('post_release_deadline')!=cert.get('released_at',0)+21600):
        raise ValueError('untrusted-private-release-state')
    path=_private_staged_path(cert,wp_content)
    if path.is_symlink():raise ValueError('private-staging-symlink')
    if path.exists():
        old=data(path)
        if old!=cert:
            if (old.get('post_release_deadline',0)>=int(time.time())
                and old.get('status')=='released_pending_qa'):
                raise ValueError('active-source-candidate-conflict')
            # Expired private receipt cannot block fresh source issuance.
    runtime=Path(wp_content)/'mu-plugins/422-mad4b-public-critical-css-runtime.php'
    consumer=Path(wp_content)/'mu-plugins/422-mad4b-public-critical-css-v2-consumer.php'
    if (runtime.is_symlink() or consumer.is_symlink() or
        sha(runtime.read_bytes())!=cert['runtime_sha'] or
        sha(consumer.read_bytes())!=cert['consumer_sha'] or
        os.environ.get('MAD4B_MULTI_CSS_V2_RELEASE_APPROVED')!='1'):
        raise ValueError('private-stage-site-runtime-authorization-missing')
    _private_atomic(path,(json.dumps(cert,sort_keys=True,indent=2)+'\n').encode())
    if data(path)!=cert:raise ValueError('private-stage-readback-failed')
    return str(path)


def promote_staged(cert,job,wp_content,cat,route,state,runtime,consumer):
    """Post-stage browser QA is required before public cert replacement."""
    stage=_private_staged_path(cert,wp_content)
    if stage.is_symlink() or not stage.is_file() or data(stage)!=cert:
        raise ValueError('private-staged-receipt-mismatch')
    if (cert.get('status')!='released_pending_qa'
        or not isinstance(cert.get('released_at'),int)
        or not isinstance(cert.get('post_release_deadline'),int)
        or not cert['released_at']<=int(time.time())<=cert['post_release_deadline']):
        raise ValueError('private-release-expired-or-invalid')
    for name in ['ordered-native-critical-qa.json','browser-parity.json']:
        path=Path(job)/name
        if (path.is_symlink() or not path.is_file()
            or path.stat().st_mtime<cert['released_at']
            or path.stat().st_mtime>time.time()+300):
            raise ValueError('private-release-fresh-post-stage-QA-required')
    qualified=certificate(cat,route,state,job,wp_content,runtime,consumer)
    stable=('host','path','language','type','object_id','market_country',
            'currency','css_epoch','runtime_sha','consumer_sha',
            'critical_cap_px','native_mobile_breakpoint','source_manifest',
            'source_manifest_sha256','critical_slots','critical_total_bytes',
            'elementor_doc_fingerprint','dependencies')
    if any(qualified.get(k)!=cert.get(k) for k in stable):
        raise ValueError('post-stage-current-source-revision-drift')
    path=publish(qualified,job,wp_content)
    # Publish is atomic per exact route; leave private receipt for audit
    # until ACK and public readback finish, but consumer never reads it.
    if data(path)!=qualified:raise ValueError('staged-promotion-readback-failed')
    return 'certified',str(path)


def discard_staged(cert,wp_content):
    stage=_private_staged_path(cert,wp_content)
    if stage.is_symlink():raise ValueError('private-staged-symlink')
    if not stage.exists():return 'not_present'
    if data(stage)!=cert:raise ValueError('stale-candidate-cannot-discard-newer')
    stage.unlink()
    return 'discarded'


def finalize_post_release(cert,job,wp_content,*,failed=False):
    """After publication: QA promotes the SAME source-bound cert or disables it.

    No pre-publication performance admission. Missing QA is a neutral
    retry while the six-hour pending lease exists. A failed QA or
    incompatible source revision disables pending delivery immediately.
    """
    if cert.get('contract')!=CONTRACT or cert.get('status')!='released_pending_qa':
        raise ValueError('not-an-active-pending-release')
    wp=Path(wp_content)
    if str(wp) not in LIVE_ROOTS or wp.is_symlink():
        raise ValueError('non-enrolled-live-site')
    routekey=sha((cert['host']+'|'+cert['language']+'|'+cert['path']+'|'
                  +cert['market_country']+'|'+cert['currency']).encode())
    path=wp/'mad4b-public-critical-css-v1/certs-v2'/(routekey+'.json')
    if path.is_symlink() or not path.is_file() or data(path)!=cert:
        raise ValueError('published-pending-cert-drift')
    qa_file=Path(job)/'ordered-native-critical-qa.json'
    if not failed and not qa_file.is_file():
        return 'pending_qa_not_yet_available',str(path)
    if not failed:
        parity_file=Path(job)/'browser-parity.json'
        if parity_file.is_symlink() or qa_file.is_symlink():
            raise ValueError('unsafe-post-release-QA-artifact')
        if (not parity_file.is_file()
            or qa_file.stat().st_mtime<cert['released_at']
            or parity_file.stat().st_mtime<cert['released_at']
            or qa_file.stat().st_mtime>time.time()+300
            or parity_file.stat().st_mtime>time.time()+300):
            # QA collected BEFORE publication must not be allowed to
            # promote a certificate that has never been tested live.
            return 'pending_fresh_post_release_qa_required',str(path)
    outcome='reverted'
    decision=None
    if not failed:
        try:
            decision=admission.proof(job)
            if (decision['decision']=='qualified_for_delivery_review'
                and decision['host']==cert['host']
                and decision['path']==cert['path']
                and decision['language']==cert['language']
                and decision['market_country']==cert['market_country']
                and decision['currency']==cert['currency']
                and decision['source_manifest_sha256']==cert['source_manifest_sha256']
                and sha(Path(wp/'mu-plugins/422-mad4b-public-critical-css-runtime.php').read_bytes())==cert['runtime_sha']
                and sha(Path(wp/'mu-plugins/422-mad4b-public-critical-css-v2-consumer.php').read_bytes())==cert['consumer_sha']
                and ordered.compile_manifest(cert['source_manifest'],wp)['manifest_sha256']==cert['source_manifest_sha256']):
                outcome='certified'
        except (ValueError,OSError,KeyError,TypeError,IndexError,UnicodeError):
            outcome='reverted'
    updated=dict(cert)
    updated['status']=outcome if outcome=='certified' else 'reverted_post_release_qa'
    updated['post_release_deadline']=0
    if outcome=='certified':
        verified=decision['viewport_results']
        updated['evidence']={
            'decision':'passed_evidence','tested':5,'viewports':VIEWS,
            'performance_source':'real-original-css-urls',
            'qa_sha256':sha(qa_file.read_bytes()),
            'minimum_mobile_fcp_gain_ms':min(v['fcp_gain_ms'] for v in verified[:4]),
            'worst_lcp_reg_ms':max(v['lcp_reg_ms'] for v in verified),
            'verified_at':int(qa_file.stat().st_mtime)}
        updated['certified_at']=int(time.time())
    else:
        updated['evidence']={'decision':'failed_post_release','tested':0,
                             'viewports':[],'verified_at':int(time.time())}
    # The original signed source-bound bytes must still match immediately
    # before the atomic update; never overwrite another worker's result.
    if data(path)!=cert:raise ValueError('post-release-CAS-conflict')
    _atomic(path,(json.dumps(updated,indent=2,sort_keys=True,ensure_ascii=True)+'\n').encode())
    if data(path)!=updated:raise ValueError('post-release-verify-readback-failed')
    return outcome,str(path)

def revoke_delivered_release(cert,wp_content):
    """Failsafe rollback AFTER QA promotion, if live public readback fails.

    No performance test is involved in issuance; rollback is scoped by the
    exact published SHA and route/locale/market/currency identity.
    """
    if cert.get('contract')!=CONTRACT or cert.get('status') not in (
        'certified','released_pending_qa'):
        raise ValueError('unrevocable-v2-state')
    wp=Path(wp_content)
    if str(wp) not in LIVE_ROOTS or wp.is_symlink():
        raise ValueError('non-enrolled-v2-revoke-site')
    routekey=sha((cert['host']+'|'+cert['language']+'|'+cert['path']+'|'
                  +cert['market_country']+'|'+cert['currency']).encode())
    path=wp/'mad4b-public-critical-css-v1/certs-v2'/(routekey+'.json')
    if path.is_symlink() or not path.is_file() or data(path)!=cert:
        raise ValueError('v2-rollback-source-or-CAS-conflict')
    updated=dict(cert)
    updated['status']='reverted_post_release_qa'
    updated['post_release_deadline']=0
    updated['evidence']={'decision':'failed_public_post_release_readback',
                          'tested':0,'viewports':[],
                          'verified_at':int(time.time())}
    # Do not falsify the historical QA record or any original source hash.
    updated['certified_at']=0
    if data(path)!=cert:raise ValueError('v2-rollback-race')
    _atomic(path,(json.dumps(updated,indent=2,sort_keys=True,ensure_ascii=True)+'\n').encode())
    if data(path)!=updated:raise ValueError('v2-rollback-readback-failed')
    return 'reverted',str(path)

def _atomic(dest,data_bytes):
    fd,temp=tempfile.mkstemp(prefix='.mad4b-multi-v2-',dir=dest.parent)
    try:
        with os.fdopen(fd,'wb') as f:
            os.fchmod(f.fileno(),0o644)
            f.write(data_bytes);f.flush();os.fsync(f.fileno())
        os.replace(temp,dest)
    finally:
        if os.path.exists(temp):os.unlink(temp)

def main():
    if len(sys.argv)!=9:
        raise ValueError('USAGE: catalog route state private-job wp-content 422.php companion.php dryrun|publish')
    cat,route,state,job,wp,runtime,consumer,mode=sys.argv[1:]
    if mode not in ('dryrun','publish','release','stage','promote_staged',
                     'discard_staged','verify','revert','revoke'):
        raise ValueError('invalid-issuance-mode')
    if mode in ('verify','revert','revoke','promote_staged','discard_staged'):
        route_entry=data(route);site_state=data(state)
        host=site_state.get('site_host')
        if host not in ('wayfatours.com','tours.dev.mad4b.com'):
            raise ValueError('untrusted-post-release-site')
        observer=data(Path(job)/'multi-css-source-report.json')
        if (observer.get('host')!=host
            or observer.get('path')!=route_entry.get('path')
            or observer.get('language')!=route_entry.get('language')):
            raise ValueError('post-release-route-binding-drift')
        key=sha((host+'|'+route_entry['language']+'|'+route_entry['path']+'|'
                 +observer['market_country']+'|'+observer['currency']).encode())
        cert_path=Path(wp)/'mad4b-public-critical-css-v1/certs-v2'/(key+'.json')
        if mode in ('promote_staged','discard_staged'):
            location=Path(wp).parent.parent/'operations/css-cron-v1/candidates-v2'/(key+'.json')
            previous=data(location)
            if mode=='promote_staged':
                outcome,target=promote_staged(previous,job,wp,data(cat),
                    route_entry,site_state,runtime,consumer)
            else:
                outcome=discard_staged(previous,wp)
                target='private-staged-candidate-only'
        else:
            previous=data(cert_path)
            if mode=='revoke':
                outcome,target=revoke_delivered_release(previous,wp)
            else:
                outcome,target=finalize_post_release(previous,job,wp,failed=(mode=='revert'))
        print('MULTI_CSS_V2_POST_RELEASE',json.dumps({'outcome':outcome,
            'certificate_path':target,'mode':mode},sort_keys=True))
        return
    release_first=mode in ('release','stage')
    cert=certificate(data(cat),data(route),data(state),Path(job),Path(wp),
                     runtime,consumer,release_first=release_first)
    target=(stage_candidate(cert,job,wp) if mode=='stage'
            else publish(cert,job,wp) if mode in ('publish','release')
            else '(dryrun-only)')
    print('MULTI_CSS_V2_CERTIFICATE',json.dumps({'mode':mode,'route':cert['path'],
       'status':cert['status'],'host':cert['host'],'lang':cert['language'],
       'currency':cert['currency'],'market_country':cert['market_country'],
       'manifest_sha256':cert['source_manifest_sha256'],
       'critical_slots':len(cert['critical_slots']),
       'critical_bytes':cert['critical_total_bytes'],
       'certificate_path':target},sort_keys=True))
if __name__=='__main__':
    try:main()
    except (OSError,ValueError,TypeError,KeyError,IndexError,AssertionError,
            AttributeError,UnicodeError,json.JSONDecodeError) as e:
        print('HOLD_MULTI_CSS_V2_CERTIFICATE',str(e)[:200],file=sys.stderr)
        sys.exit(4)
