#!/usr/bin/env python3
"""MAD4B read-only live semantic company reconciliation.
Reads actual WP runtime, never Git or old snapshots as an authority for current state.
"""
from __future__ import annotations
import argparse
import datetime as dt
import hashlib
import importlib.util
import json
import os
from pathlib import Path
import re
import subprocess
import sys

ROOT = Path('/opt/mad4b-company-provisioning')
CONFIG = ROOT/'policies/live-semantic-discovery-sites-v1.json'
REGISTRY_POLICY = ROOT/'policies/company-fx-distribution-v1.json'
REGISTRY_ADAPTER = ROOT/'bin/company-mode-price-policy-reconcile.py'
REPORT = ROOT/'reports/company-live-semantic-reconciliation-current.json'
CONTRACT = 'mad4b.live-semantic-reconciliation.discovery.v1'
CODE = re.compile(r'^[a-z][a-z0-9-]{2,47}$')
CONTAINER = re.compile(r'^php-[a-z0-9-]{10,80}$')
ORIGIN = re.compile(r'^https://[a-z0-9][a-z0-9.-]{3,180}$')
REF = 'mad4b.company-live-semantic-reconciliation.v1'

def json_atomic(path: Path, data: dict) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    tmp = path.with_suffix(path.suffix+'.tmp')
    payload = json.dumps(data, ensure_ascii=False, sort_keys=True, indent=2)+'\n'
    tmp.write_text(payload, encoding='utf-8')
    tmp.chmod(0o600)
    os.replace(tmp, path)

def enrolled_targets() -> set[tuple[str,str]]:
    policy=json.loads(REGISTRY_POLICY.read_text())
    if policy.get('contract')!='mad4b.company-fx-distribution.v1':
        raise ValueError('ENROLLED_BINDING_POLICY_INVALID')
    spec=importlib.util.spec_from_file_location('mad4b_semantic_bindings_reader',REGISTRY_ADAPTER)
    if not spec or not spec.loader:
        raise RuntimeError('ENROLLED_BINDING_ADAPTER_UNAVAILABLE')
    module=importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    targets=module.gather_bindings(policy)
    return {(t['company_code'],t['environment']) for t in targets}

def load_config(path: Path, enrolled: set[tuple[str,str]]) -> dict:
    cfg=json.loads(path.read_text())
    if cfg.get('contract')!='mad4b.live-semantic-discovery-sites.v1' or cfg.get('allow_write') is not False:
        raise ValueError('SITE_INVENTORY_CONTRACT_INVALID')
    entries=[cfg['source']]+cfg.get('targets',[])
    if len(entries)>42 or not cfg.get('targets'):
        raise ValueError('BAD_SITE_COUNT')
    for e in entries:
        if not ORIGIN.fullmatch(str(e.get('origin',''))) or not CONTAINER.fullmatch(str(e.get('container',''))):
            raise ValueError('SITE_BINDING_FORMAT_INVALID')
    for e in cfg['targets']:
        if not CODE.fullmatch(e.get('company','')) or (e['company'],e.get('environment')) not in enrolled:
            raise ValueError('TARGET_NOT_ENROLLED:'+str(e.get('company')))
    return cfg

def runtime_scan(site: dict, *, timeout: int=55) -> dict:
    # argv never reaches a shell and WP scans are read-only by design.
    cmd=['docker','exec','-u','www-data',site['container'],'wp','mad4b','semantic-discovery','scan','--skip-themes']
    done=subprocess.run(cmd,capture_output=True,text=True,timeout=timeout,check=False)
    if done.returncode!=0:
        raise RuntimeError('WPCLI_SCAN_FAILED:'+site['container']+':'+done.stderr[-180:])
    if len(done.stdout)>1_600_000:
        raise RuntimeError('SNAPSHOT_TOO_LARGE')
    data=json.loads(done.stdout)
    if data.get('contract')!=CONTRACT or data.get('origin')!=site['origin'] or data.get('read_only') is not True or data.get('changes_applied') is not False:
        raise ValueError('LIVE_ORIGIN_OR_DISCOVERY_CONTRACT_MISMATCH')
    return data

def fingerprint_diff(a: dict,b: dict) -> list[dict]:
    src={**a['observations'].get('code_fingerprints',{}),
         **{'plugins/'+k:v for k,v in a['observations'].get('plugin_code_fingerprints',{}).items()}}
    target={**b['observations'].get('code_fingerprints',{}),
         **{'plugins/'+k:v for k,v in b['observations'].get('plugin_code_fingerprints',{}).items()}}
    changed=[]
    for key in sorted(set(src)|set(target)):
        left,right=src.get(key),target.get(key)
        if left==right:continue
        # Tenant-owned capabilities often differ intentionally: never treat absence as a permission to clone.
        owner=('shared_core' if key.startswith('shared-product-core/') or key.startswith('387-')
               or key.startswith('389-') or key.startswith('406-')
               else 'site_specific_or_company_override')
        changed.append({'path':key,'source_sha256':left,'target_sha256':right,
            'classification':owner,'apply_authorized':False})
    return changed

def semantic_compare(a: dict,b: dict) -> dict:
    af=a['observations']; bf=b['observations']
    source_filters={r['id']:r for r in af['filters']['definitions']}
    target_filters={r['id']:r for r in bf['filters']['definitions']}
    mismatches=[]
    for id in sorted(source_filters.keys() & target_filters.keys()):
        x,y=source_filters[id],target_filters[id]
        # A Query Builder ID can vary after migration; compare semantics before IDs.
        for field in ('query_var','expected_taxonomy','configured_taxonomy','query_taxonomies'):
            if x.get(field)!=y.get(field):
                mismatches.append({'filter_id':id,'field':field,
                    'source':x.get(field),'target':y.get(field),
                    'auto_apply':False})
    ax={r['id']:r for r in af.get('wpml',{}).get('rate_groups',[])}
    bx={r['id']:r for r in bf.get('wpml',{}).get('rate_groups',[])}
    wpml=[]
    for rid in sorted(ax.keys()&bx.keys()):
        x,y=ax[rid],bx[rid]
        # Site-local TRIDs differ legitimately; compare sibling intent instead.
        for field in ('language','source_language','parent'):
            if x.get(field)!=y.get(field):
                wpml.append({'rate_id':rid,'field':field,'source':x.get(field),
                    'target':y.get(field),'auto_apply':False})
    code=fingerprint_diff(a,b)
    ar=af.get('rates',{}).get('counts',{})
    br=bf.get('rates',{}).get('counts',{})
    target_runtime=bf.get('runtime',{})
    target_health={
      'program_filters_correct': all(x.get('query_taxonomies')==['programs'] and
          x.get('configured_taxonomy')=='programs'
          for x in bf['filters']['definitions'] if x['query_var']=='related_program_term_id')
          and len(bf['filters']['program_filter_ids'])>0,
      'published_rates_missing_package':br.get('published_missing_package'),
      'published_rates_invalid_package':br.get('published_invalid_package'),
      'wpml_rate_parent_mismatch':bf.get('wpml',{}).get('rate_lang_parent_mismatch'),
      'wpml_standalone_rate_translations':bf.get('wpml',{}).get('standalone_non_english_rate'),
      'jsf_signed':target_runtime.get('jet_smart_filters_signature'),
      'archive_deep_link_registered':bool(target_runtime.get('archive_rewritable') and
          target_runtime.get('archive_rewrite_rule_present')),
      'rate_facets_loaded':bool(target_runtime.get('rate_eligibility_function') and
          target_runtime.get('rate_facets_function')),
      'dfsb_slots_available':all(v.get('enabled') for v in bf.get('dfsb_slots',{}).values())
          and bool(bf.get('dfsb_slots')),
      'js_inline_combine_off':target_runtime.get('litespeed_combine_inline_external')!='1',
      'visitor_lean_bootstrap_available':bool(target_runtime.get('visitor_bootstrap_file_present')
          and target_runtime.get('visitor_lean_bootstrap')=='on'),
      'multi_program_positive_test_possible':bf.get('tours',{}).get('counts',{}).get('possible_multi_program_cruise',0)>0,
    }
    return {
        'filters_compared':len(source_filters.keys()&target_filters.keys()),
        'filter_semantic_drift':mismatches,
        'wpml_rate_semantic_drift':wpml,
        'code_drift':code,
        'live_target_health':target_health,
        'source_rate_counts':ar,
        'target_rate_counts':br,
        'source_media':af.get('media_identity',{}),
        'target_media':bf.get('media_identity',{}),
        'target_findings':b.get('findings',[]),
        'convergence_claim':False, 'auto_apply':False,
        'browser_acceptance':'PENDING_EXPLICIT_BROWSER_TEST',
    }

def nginx_cache_readback(site: dict) -> dict:
    """Host-only cache policy evidence, never interpreted as a cache HIT proof."""
    path=Path(site.get('nginx_config',''))
    allowed=(str(path).startswith('/opt/hostinger-migrations/') or
             str(path).startswith('/opt/wayfa-rebuild-'))
    if not allowed or not path.is_file() or path.is_symlink():
        return {'state':'UNAVAILABLE','cache_hit_verified':False}
    text=path.read_text(errors='replace')
    raw=re.findall(r'(?m)^\s*fastcgi_cache_valid\s+200\s+(\d+)([smh])\s*;',text)
    multipliers={'s':1,'m':60,'h':3600}
    ttl=sorted({int(number)*multipliers[unit] for number,unit in raw})
    return {'state':'CONFIG_READBACK','origin':site['origin'],
        'nginx_config_sha256':hashlib.sha256(path.read_bytes()).hexdigest(),
        'public_ttl_seconds_observed':ttl,
        'has_30min_policy':1800 in ttl,
        'cache_bypass_rules':len(re.findall(r'(?m)^\s*fastcgi_cache_bypass\s+',text)),
        'no_cache_rules':len(re.findall(r'(?m)^\s*fastcgi_no_cache\s+',text)),
        'device_aware_cache_key':('mad4b_device_class' in text or 'wayfa_html_device_key' in text),
        'cache_hit_verified':False}

def receipt_milestones(source: dict, target: dict) -> dict:
    """Correlate previous *local receipts* with live reads; receipts are history, not authority."""
    out={}
    report_dir=ROOT/'reports'
    program_dir=report_dir/'program-query-source-fix-20261107'
    rate_dir=report_dir/'rate-program-perf-deep-review-20261107'
    try:
        snapshots=[('source',source),('wayfa',target)]
        results={}
        for label,data in snapshots:
            receipt=json.loads((program_dir/f'{label}-transaction-receipt.json').read_text())
            current={str(x['id']):x for x in data['observations']['filters']['definitions']}
            desired=receipt.get('after',{})
            complete=bool(desired) and all(
                str(current.get(str(k),{}).get('query_id'))==str(v.get('query'))
                and current.get(str(k),{}).get('configured_taxonomy')==v.get('taxonomy')
                and current.get(str(k),{}).get('query_taxonomies')==[v.get('taxonomy')]
                for k,v in desired.items())
            results[label]={'receipt_filters':len(desired),
                'current_runtime_matches_receipt':complete}
        out['program_query_316_to_programs']={'state':
            'VERIFIED_CURRENT' if all(v['current_runtime_matches_receipt'] for v in results.values())
            else 'DRIFT_DETECTED','scopes':results,
            'repair_receipts':[str(program_dir/f'{name}-transaction-receipt.json') for name,_ in snapshots]}
    except (OSError,ValueError,KeyError,TypeError):
        out['program_query_316_to_programs']={'state':'HISTORY_UNAVAILABLE'}
    try:
        manifest=json.loads((rate_dir/'package-fk-repair-manifest.json').read_text())
        receipt=json.loads((rate_dir/'package-fk-apply-receipt.json').read_text())
        rates={int(x['id']):x for x in target['observations']['rates'].get('published_rate_details',[])}
        expected=int(manifest['target_term'])
        ids=[int(x['rate_id']) for x in receipt['changes']]
        rate_readback_available='published_rate_details' in target['observations']['rates']
        complete=(receipt.get('status')=='committed'
                  and manifest.get('expected_rows')==len(ids)
                  and len(ids)==len(set(ids))
                  and rate_readback_available
                  and all(rates.get(i,{}).get('package_term_id')==expected for i in ids))
        out['package_level_rates']={'state':('VERIFIED_CURRENT' if complete else
            'CURRENT_RATE_DETAILS_NOT_COLLECTED' if not rate_readback_available else 'DRIFT_DETECTED'),
            'rate_ids_from_receipt':len(ids),'matched_live_rate_ids':
                sum(rates.get(i,{}).get('package_term_id')==expected for i in ids),
            'term_id':expected,'receipt':str(rate_dir/'package-fk-apply-receipt.json')}
    except (OSError,ValueError,KeyError,TypeError):
        out['package_level_rates']={'state':'HISTORY_UNAVAILABLE'}
    try:
        receipt=json.loads((rate_dir/'wpml-rate-61052-repair-receipt.json').read_text())
        after=receipt['after'];rid=int(after['element_id'])
        row=next((x for x in target['observations']['wpml'].get('rate_groups',[])
                  if int(x['id'])==rid),{})
        complete=(receipt.get('status')=='linked'
                  and row.get('trid')==int(after['trid'])
                  and row.get('language')==after['language_code']
                  and row.get('source_language')==after['source_language_code'])
        out['wpml_rate_sibling_link']={'state':'VERIFIED_CURRENT' if complete else 'DRIFT_DETECTED',
            'rate_id':rid,'receipt':str(rate_dir/'wpml-rate-61052-repair-receipt.json')}
    except (OSError,ValueError,KeyError,TypeError):
        out['wpml_rate_sibling_link']={'state':'HISTORY_UNAVAILABLE'}
    h=semantic_compare(source,target)['live_target_health']
    out['archive_jsf_dfsb_runtime']={
       'state':'RUNTIME_CONFIG_VERIFIED' if
         all(h.get(k) is True for k in ['jsf_signed','archive_deep_link_registered',
               'dfsb_slots_available','js_inline_combine_off','rate_facets_loaded',
               'visitor_lean_bootstrap_available']) else 'RUNTIME_DRIFT_DETECTED',
       'browser_behavior':'NOT_RETESTED_BY_THIS_OBSERVER'}
    out['elementor_php_performance']={
        'state':'HISTORICAL_UNCACHED_TTFB_HOLD',
        'phase_profiles':str(rate_dir/'wayfa-php-phase-profile.jsonl'),
        'measurement_in_this_scan':False}
    return out

def stable_digest(value: dict) -> str:
    # The timestamp is not drift; identical live semantic readbacks get the same hash.
    stable={k:v for k,v in value.items() if k not in ('captured_utc','observed_utc')}
    return hashlib.sha256(json.dumps(stable,sort_keys=True,
        ensure_ascii=False,separators=(',',':')).encode()).hexdigest()

def main() -> None:
    parser=argparse.ArgumentParser()
    parser.add_argument('--config',type=Path,default=CONFIG)
    parser.add_argument('--report',type=Path,default=REPORT)
    parser.add_argument('--source-snapshot',type=Path,default=None,help='offline test only; requires both snapshots')
    parser.add_argument('--target-snapshot',type=Path,default=None)
    parser.add_argument('--no-publish',action='store_true')
    args=parser.parse_args()
    if bool(args.source_snapshot) != bool(args.target_snapshot):
        raise ValueError('BOTH_OFFLINE_SNAPSHOTS_REQUIRED')
    if args.source_snapshot and not args.no_publish and args.report==REPORT:
        raise ValueError('OFFLINE_REPLAY_CANNOT_OVERWRITE_LIVE_CURRENT')
    cfg=load_config(args.config,enrolled_targets())
    if args.source_snapshot:
        source=json.loads(args.source_snapshot.read_text())
        target=json.loads(args.target_snapshot.read_text())
        if source.get('origin')!=cfg['source']['origin'] or target.get('origin')!=cfg['targets'][0]['origin']:
            raise ValueError('SNAPSHOT_ORIGIN_INVALID')
    else:
        source=runtime_scan(cfg['source'])
        target=runtime_scan(cfg['targets'][0])
    comparison=semantic_compare(source,target)
    comparison['prior_repairs_live_readback']=receipt_milestones(source,target)
    comparison['host_cache_policy']={
        'source':nginx_cache_readback(cfg['source']),
        'target':nginx_cache_readback(cfg['targets'][0])}
    report={'contract':REF,'observed_utc':dt.datetime.now(dt.timezone.utc).isoformat(),
        'source_origin':source['origin'],'target_origin':target['origin'],
        'company':cfg['targets'][0]['company'],'environment':cfg['targets'][0]['environment'],
        'source_snapshot_sha256':stable_digest(source),
        'target_snapshot_sha256':stable_digest(target),
        'comparison':comparison,
        'evidence_mode':'OFFLINE_REPLAY' if args.source_snapshot else 'LIVE_RUNTIME',
        'read_only':True,'writes_to_tenant':False,
        'generated_jobs':False,'grants_issued':False,
        'disposition':'DISCOVERED_NOT_APPLIED'}
    report['semantic_revision_sha256']=stable_digest(comparison)
    prior=None
    if args.report.is_file():
        try:
            prior=json.loads(args.report.read_text())
        except (OSError,ValueError): pass
    report['change_state']=('UNCHANGED' if prior and
        prior.get('semantic_revision_sha256')==report['semantic_revision_sha256']
        and prior.get('source_origin')==report['source_origin']
        and prior.get('target_origin')==report['target_origin'] else 'UPDATED')
    if not args.no_publish:json_atomic(args.report,report)
    mode=('LIVE_SEMANTIC_DISCOVERY_REPLAY' if args.source_snapshot
          else 'LIVE_SEMANTIC_DISCOVERY_PUBLISHED' if not args.no_publish
          else 'LIVE_SEMANTIC_DISCOVERY_TESTED')
    print(json.dumps({'state':mode,
        'report':str(args.report),'company':report['company'],'source_origin':report['source_origin'],
        'target_origin':report['target_origin'],'target_p1':target['counts']['p1'],
        'target_p2':target['counts']['p2'],'semantic_filter_drift_count':len(comparison['filter_semantic_drift']),
        'wpml_semantic_drift_count':len(comparison['wpml_rate_semantic_drift']),
        'code_drift_count':len(comparison['code_drift']),
        'change_state':report['change_state'],
        'writes_to_tenant':False,'ready_for_auto_apply':False},ensure_ascii=False))

if __name__=='__main__':
    try:main()
    except Exception as exc:
        print(json.dumps({'state':'LIVE_SEMANTIC_DISCOVERY_DEFERRED',
            'reason':str(exc)[:260],'writes_to_tenant':False},ensure_ascii=False),file=sys.stderr)
        raise SystemExit(3)
