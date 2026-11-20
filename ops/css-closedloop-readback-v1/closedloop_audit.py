#!/usr/bin/env python3
"""Read-only public HTML/CSS evidence; never promotes certificates or cache."""
from __future__ import annotations
import argparse
import collections
import hashlib
import json
from pathlib import Path
import re
import sys

CONTRACT = 'mad4b.public-css.closedloop-delivery-audit.v1'


def read_regular(path: Path, limit: int = 5_000_000) -> bytes:
    if path.is_symlink() or not path.is_file() or path.stat().st_size > limit:
        raise ValueError('unsafe-or-missing-readonly-input:' + str(path))
    return path.read_bytes()


def headers_dict(raw: str) -> dict[str, str]:
    # Keep only the final HTTP hop, not an earlier redirect or proxy result.
    result: dict[str, str] = {}
    for line in raw.splitlines():
        if line.lower().startswith('http/'):
            result = {'_http_status': line.split(' ', 2)[1] if ' ' in line else 'unknown'}
        elif ':' in line:
            key, val = line.split(':', 1)
            if re.fullmatch(r'[A-Za-z0-9_-]{1,90}', key):
                result[key.lower()] = val.strip()
    return result


def count_attribute(html: str, tag: str, attribute: str, value: str) -> int:
    # Attribute ordering can vary across Elementor/LiteSpeed.
    start = re.compile(r'<'+tag+r'\b[^>]*>', re.I | re.S)
    attr = re.compile(r'(?<![\w-])'+re.escape(attribute)+r'\s*=\s*([\"\x27])'
                      +re.escape(value)+r'\1', re.I)
    return sum(1 for item in start.finditer(html) if attr.search(item.group(0)))


def html_delivery(raw_headers: str, html: str) -> dict:
    h = headers_dict(raw_headers)
    home = (count_attribute(html, 'style', 'id', 'mad4b-critical-css-v1') == 1
            and count_attribute(html, 'link', 'id', 'mad4b-css-rest-v1') == 1
            and h.get('x-mad4b-mobile-css-ready') == '1')
    route_v1 = (count_attribute(html, 'style', 'id', 'mad4b-public-critical-css-v1') == 1
                and count_attribute(html, 'link', 'id', 'mad4b-public-css-rest-v1') == 1
                and h.get('x-mad4b-public-ccss-ready') == '1')
    critical_v2 = count_attribute(html, 'style', 'data-mad4b-ccss-v2-critical', '1')
    rest_v2 = count_attribute(html, 'link', 'data-mad4b-ccss-v2-rest', '1')
    route_v2 = (critical_v2 >= 1 and rest_v2 >= 2
                and count_attribute(html,'script','id','mad4b-public-ccss-v2-recovery') == 1
                and h.get('x-mad4b-public-ccss-ready') == '1')
    origin_cache = (h.get('x-wayfa-origin-cache') or
                    h.get('x-mad4b-preset-cache') or 'not-advertised').upper()
    css = 'home_mobile_css_markup' if home else ('public_route_v2_markup'
           if route_v2 else 'public_route_v1_markup' if route_v1 else 'not_verified_in_html')
    private = 'private' in h.get('cache-control','').lower() or 'no-store' in h.get('cache-control','').lower()
    return {
        'http_status': h.get('_http_status','unknown'),
        'cache_state': origin_cache,
        'browser_cache_private_or_no_store': private,
        'css_html_readback': css,
        # Even valid markup is not proof of runtime/source/currency freshness.
        'certificate_context_cryptographically_revalidated': False,
        'cache_hit_without_ccss_markup': origin_cache == 'HIT' and css == 'not_verified_in_html',
        'critical_v2_slots_observed': critical_v2,
        'deferred_v2_links_observed': rest_v2,
        'css_delivery_markup_visible': css != 'not_verified_in_html',
        'stylesheet_links': len(re.findall(r'<link\b[^>]*\brel\s*=\s*[\"\x27]stylesheet', html, re.I)),
        'mobile_header_ready': h.get('x-mad4b-mobile-css-ready') == '1',
        'route_header_ready': h.get('x-mad4b-public-ccss-ready') == '1'
    }


def cert_summary(certs: Path, wp_content: Path, epoch: int, runtime_sha: str) -> dict:
    count = collections.Counter()
    by_epoch = collections.Counter()
    for file in sorted(certs.glob('*.json')) if certs.is_dir() else []:
        if file.is_symlink():
            count['unsafe_file'] += 1
            continue
        try:
            c = json.loads(read_regular(file, 200_000))
            if not isinstance(c, dict):
                raise ValueError('not-object')
        except (ValueError, json.JSONDecodeError):
            count['malformed'] += 1
            continue
        count['stored'] += 1
        ep = c.get('css_epoch')
        by_epoch[str(ep)] += 1
        if ep != epoch:
            count['epoch_mismatch'] += 1
        if c.get('runtime_sha') != runtime_sha:
            count['runtime_mismatch'] += 1
        css_file = c.get('css_file')
        css_ok_name = isinstance(css_file,str) and re.fullmatch(r'[a-f0-9]{32}\.css',css_file)
        css_path = wp_content/'litespeed/css'/css_file if css_ok_name else None
        css_exists = (css_path is not None and not css_path.is_symlink()
                      and css_path.is_file())
        if not css_exists: count['combined_css_missing'] += 1
        critical_name = c.get('critical_file')
        critical_ok_name = (isinstance(critical_name,str)
            and re.fullmatch(r'[a-f0-9]{64}\.css',critical_name))
        critical_path = (wp_content/'mad4b-public-critical-css-v1/assets'/critical_name
                         if critical_ok_name else None)
        critical_exists = (critical_path is not None
            and not critical_path.is_symlink() and critical_path.is_file())
        if not critical_exists: count['critical_asset_missing'] += 1
        css_integrity = False
        critical_integrity = False
        if css_exists:
            try:
                css_integrity=(isinstance(c.get('css_sha'),str)
                    and hashlib.sha256(read_regular(css_path)).hexdigest()==c['css_sha'])
            except ValueError: pass
            if not css_integrity: count['combined_css_sha_mismatch'] += 1
        if critical_exists:
            try:
                sha=hashlib.sha256(read_regular(critical_path)).hexdigest()
                critical_integrity=(isinstance(c.get('critical_sha'),str)
                    and sha==c['critical_sha']
                    and critical_name==sha+'.css')
            except ValueError: pass
            if not critical_integrity: count['critical_asset_sha_mismatch'] += 1
        # Even with exact on-disk bytes this is NOT a route certificate:
        # context, Elementor graph, market/currency and HTML must still pass.
        if ep == epoch and c.get('runtime_sha') == runtime_sha \
                and css_integrity and critical_integrity:
            count['minimum_epoch_runtime_assets_eligible'] += 1
    return {
        **{k:int(count[k]) for k in ['stored','epoch_mismatch','runtime_mismatch',
            'combined_css_missing','critical_asset_missing',
            'combined_css_sha_mismatch','critical_asset_sha_mismatch',
            'minimum_epoch_runtime_assets_eligible','malformed','unsafe_file']},
        'saved_certificate_epochs':dict(sorted(by_epoch.items())),
        'route_qualified_after_full_context_validation':'not_asserted',
    }


def build(site: str, wp_content: Path, registry: Path,
          sample_manifest: Path | None = None, cron: Path | None = None,
          cron_profile: Path | None = None) -> dict:
    if site not in ('source','wayfa'):
        raise ValueError('site-not-allowed')
    families = json.loads(read_regular(registry, 2_000_000))
    if families.get('contract') != 'mad4b.public-css.source-family-registry.v1' or families.get('site') != site:
        raise ValueError('family-registry-site-or-contract-invalid')
    epoch = families['css_epoch']
    if not isinstance(epoch,int) or epoch < 1:
        raise ValueError('css-epoch-not-observed')
    runtime_file = wp_content/'mu-plugins/422-mad4b-public-critical-css-runtime.php'
    runtime_sha = hashlib.sha256(read_regular(runtime_file, 100_000)).hexdigest()
    certs = cert_summary(wp_content/'mad4b-public-critical-css-v1/certs',
                         wp_content,epoch,runtime_sha)
    certs_v2 = wp_content/'mad4b-public-critical-css-v1/certs-v2'
    v2_count = (sum(1 for file in certs_v2.glob('*.json')
                    if file.is_file() and not file.is_symlink())
                if certs_v2.is_dir() else 0)
    group_counts = collections.Counter(f.get('status') for f in families.get('source_families',[]))
    result = {
      'contract': CONTRACT, 'site': site, 'host': families['host'],
      'mode': 'READ_ONLY_NOT_A_CERTIFICATE_OR_RELEASE',
      'current_css_epoch': epoch, 'runtime_sha256': runtime_sha,
      'routes_discovered':families['routes_discovered'],
      'template_families':families['source_groups_total'],
      'fully_qualified_source_families': families['source_canary_qualified_groups'],
      'exact_current_route_certificates_in_family_registry':families['routes_with_exact_current_certificate'],
      'canary_attempts_in_family_registry':sum(int(f.get('attempted_canary_count',0)) for f in families['source_families']),
      'family_statuses':dict(sorted(group_counts.items())),
      'saved_public_certificates':certs,
      'v2_published_certificates_observed':v2_count,
      'html_samples':[],
      'browser_html_ready_from_cache_state_alone': False,
      'template_family_reuse_authorized_by_this_report': False,
      'fastcgi_promotion_authorized_by_this_report': False
    }
    if sample_manifest is not None:
        samples=json.loads(read_regular(sample_manifest, 100_000))
        if not isinstance(samples,list) or len(samples)>20:
            raise ValueError('invalid-html-probe-manifest')
        for item in samples:
            if not isinstance(item,dict) or set(item)!={'name','headers','html'}:
                raise ValueError('invalid-probe-entry')
            if not re.fullmatch(r'[a-z0-9_-]{1,55}',item['name']):
                raise ValueError('invalid-sample-name')
            headers=read_regular(Path(item['headers']),50_000).decode(errors='replace')
            html=read_regular(Path(item['html'])).decode(errors='replace')
            result['html_samples'].append({'name':item['name'],**html_delivery(headers,html)})
    enrolled_jobs = None
    if cron_profile is not None:
        profile=json.loads(read_regular(cron_profile, 100_000))
        if (profile.get('contract')!='mad4b.css.site-owned-cron.profile.v1'
                or profile.get('site_id')!=site
                or profile.get('site_host')!=families['host']):
            raise ValueError('wrong-site-or-contract-for-cron-profile')
        jobs=profile.get('tasks')
        if (not isinstance(jobs,list) or not jobs
                or len(jobs)!=len(set(map(str,jobs)))):
            raise ValueError('invalid-cron-profile-task-list')
        enrolled_jobs=set(jobs)
        result['site_owned_css_tasks_enrolled_in_profile']=sorted(enrolled_jobs)
    if cron is not None:
        receipts={}
        for job in ('public','first_fold','families','visual','audit'):
            file=cron/(site+'-'+job+'-latest.json')
            if file.is_file():
                data=json.loads(read_regular(file, 30_000))
                receipts[job]={'at':data.get('at'),'deferred':data.get('deferred'),
                    'worker_complete':data.get('worker_complete'),
                    'completion_reason':data.get('completion_reason')}
            else:
                reason=('not_enrolled_in_site_owned_profile' if enrolled_jobs is not None and job not in enrolled_jobs
                        else 'missing_receipt_not_proof_of_runtime_schedule')
                receipts[job]={'receipt':reason}
        result['cron_receipts']=receipts
    return result


def main() -> int:
    parser=argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--site',choices=('source','wayfa'),required=True)
    parser.add_argument('--wp-content',type=Path,required=True)
    parser.add_argument('--family-registry',type=Path,required=True)
    parser.add_argument('--samples-manifest',type=Path)
    parser.add_argument('--cron-receipts',type=Path)
    parser.add_argument('--cron-profile',type=Path)
    args=parser.parse_args()
    report=build(args.site,args.wp_content,args.family_registry,
                 args.samples_manifest,args.cron_receipts,args.cron_profile)
    print(json.dumps(report,sort_keys=True,indent=2,ensure_ascii=False))
    return 0


if __name__ == '__main__':
    try: raise SystemExit(main())
    except (ValueError,OSError,KeyError,TypeError) as exc:
        print('HOLD_CSS_CLOSEDLOOP_AUDIT',str(exc)[:200],file=sys.stderr)
        raise SystemExit(3)
