#!/usr/bin/env python3
"""Strict source-family read adapter for independently qualified v2 routes.

Never publishes CSS. It interprets a pre-existing v2 certificate only after
the certificate's first-party route/market/runtime/source/asset proof passes.
A template-family proof is informational; it NEVER authorizes copying one
route's CSS, price/market or hero images to another.
"""
import hashlib, importlib.util, json, re
from pathlib import Path

CONTRACT='mad4b.public-critical-css.route-certificate.v2'
SHA=re.compile(r'^[0-9a-f]{64}$')
FILE=re.compile(r'^[0-9a-f]{64}\.json$')
BASE=Path(__file__).resolve().parent

def digest(data):
    return hashlib.sha256(data).hexdigest()

def load_into(root,prior):
    root=Path(root)
    folder=root/'mad4b-public-critical-css-v1'/'certs-v2'
    if folder.is_symlink():raise ValueError('unsafe-v2-family-certificates-directory')
    if not folder.exists():return prior
    if not folder.is_dir():raise ValueError('v2-certs-not-a-directory')
    if folder.stat().st_mode & 0o022:raise ValueError('insecure-v2-cert-directory')
    out=dict(prior)
    for path in sorted(folder.iterdir()):
        if path.is_symlink() or not path.is_file() or not FILE.fullmatch(path.name):
            continue
        if path.stat().st_size>180000:continue
        try:
            cert=json.loads(path.read_text())
            if (not isinstance(cert,dict)
                or cert.get('contract')!=CONTRACT
                or cert.get('status')!='certified'):
                continue
            context=(cert['language'],cert['path'],cert['market_country'],cert['currency'])
            if (not isinstance(cert.get('host'),str)
                or len(cert['host'])>253
                or not all(isinstance(x,str) and len(x)<500 for x in context)):
                continue
            pathkey=digest((cert['host']+'|'+
                            '|'.join(context)).encode())
            if path.name!=pathkey+'.json':continue
            # An exact certified v2 route overrides legacy v1; a provisional
            # or reverted v2 never hides a legitimately certified old v1.
            out[context]=cert
        except (ValueError,KeyError,TypeError,UnicodeError):
            continue
    return out

def validate(root,host,route,state,catalog,ledger,cert,source_key):
    root=Path(root)
    if (not isinstance(cert,dict)
        or cert.get('contract')!=CONTRACT or cert.get('status')!='certified'
        or not isinstance(ledger,dict) or ledger.get('status')!='certified'
        or ledger.get('source_key')!=source_key
        or cert.get('host')!=host or cert.get('type')!=route['type']
        or route['type']!='tours-and-activities'
        or cert.get('language')!=route['language']
        or cert.get('path')!=route['path']
        or cert.get('object_id')!=route['id']
        or cert.get('css_epoch')!=state.get('css_epoch')
        or cert.get('runtime_sha')!=state.get('runtime_sha')
        or cert.get('native_mobile_breakpoint')!=767
        or cert.get('critical_cap_px')!=767
        or cert.get('market_country')!='base'
        or cert.get('currency')!=state.get('base_currency')
        or state.get('wcml_multi_currency') is not False
        or state.get('enabled_currencies')!=[state.get('base_currency')]
        or not isinstance(cert.get('released_at'),int)
        or not isinstance(cert.get('certified_at'),int)
        or cert['certified_at']<cert['released_at']
        or cert['certified_at']<=0
        or cert.get('post_release_deadline')!=0):
        return False
    d=cert.get('dependencies')
    if (not isinstance(d,dict)
        or d.get('route_generation')!=route.get('revision')
        or d.get('global_generation')!=catalog.get('global_generation')
        or (d.get('contains_dynamic_listing')
            and d.get('listing_generation')!=catalog.get('listing_generation'))):
        return False
    evidence=cert.get('evidence')
    if (not isinstance(evidence,dict)
        or evidence.get('decision')!='passed_evidence'
        or evidence.get('tested')!=5
        or evidence.get('viewports')!=[320,390,414,767,768]
        or evidence.get('performance_source')!='real-original-css-urls'
        or not isinstance(evidence.get('qa_sha256'),str)
        or not SHA.fullmatch(evidence['qa_sha256'])
        or not SHA.fullmatch(str(cert.get('elementor_doc_fingerprint','')))):
        return False
    runtime=root/'mu-plugins/422-mad4b-public-critical-css-runtime.php'
    companion=root/'mu-plugins/422-mad4b-public-critical-css-v2-consumer.php'
    if (runtime.is_symlink() or companion.is_symlink()
        or not runtime.is_file() or not companion.is_file()
        or digest(runtime.read_bytes())!=cert['runtime_sha']
        or digest(companion.read_bytes())!=cert.get('consumer_sha')):
        return False
    key=digest((host+'|'+route['language']+'|'+route['path']+'|'
                +cert['market_country']+'|'+cert['currency']).encode())
    published=root/'mad4b-public-critical-css-v1/certs-v2'/(key+'.json')
    if (published.is_symlink() or not published.is_file()
        or published.stat().st_size>180000):return False
    try:
        if json.loads(published.read_text())!=cert:return False
    except (OSError,ValueError,TypeError,UnicodeError):return False
    manifest=cert.get('source_manifest')
    if (not isinstance(manifest,dict)
        or not SHA.fullmatch(str(cert.get('source_manifest_sha256','')))
        or manifest.get('manifest_sha256')!=cert['source_manifest_sha256']
        or manifest.get('host')!=host):return False
    spec=importlib.util.spec_from_file_location('v2_family_ordered_source_compiler',
                                                BASE/'public-css-dependency-manifest.py')
    compiler=importlib.util.module_from_spec(spec);spec.loader.exec_module(compiler)
    try:
        fresh=compiler.compile_manifest(manifest,root)
    except (OSError,ValueError,KeyError,TypeError,UnicodeError):
        return False
    if fresh.get('manifest_sha256')!=cert['source_manifest_sha256']:return False
    slots=cert.get('critical_slots');chunks=fresh.get('chunks')
    if (not isinstance(slots,list) or not isinstance(chunks,list)
        or len(slots)!=len(chunks) or not slots):return False
    total=0
    for slot,chunk in zip(slots,chunks):
        if (slot.get('slot')!=chunk.get('slot')
            or slot.get('source_sha256')!=chunk.get('sha256')
            or slot.get('source_bytes')!=chunk.get('bytes')
            or not isinstance(slot.get('critical_bytes'),int)
            or slot['critical_bytes']<0 or slot['critical_bytes']>1048576):
            return False
        total+=slot['critical_bytes']
        if slot['critical_bytes']==0:continue
        digestname=slot.get('critical_sha256')
        if (not isinstance(digestname,str) or not SHA.fullmatch(digestname)
            or slot.get('critical_file')!=digestname+'.css'):
            return False
        file=root/'mad4b-public-critical-css-v1/assets'/(digestname+'.css')
        if (file.is_symlink() or not file.is_file()
            or file.stat().st_size!=slot['critical_bytes']
            or digest(file.read_bytes())!=digestname):
            return False
    return 16000<=total<=1048576 and total==cert.get('critical_total_bytes')
