#!/usr/bin/env python3
"""Bounded original WordPress snapshot observer when WP-CLI slots are reserved.

Never runs WP-CLI, Chromium, PHP, a certificate publisher or writes WordPress.
Cached identity observations are advisory and automatically invalidated by a
new catalog/source key. Live performance and user CSS are unchanged.
"""
from __future__ import annotations
import importlib.util
import os
import re
import stat
import sys
import time
from pathlib import Path

D=Path(__file__).resolve().parent
REPORTS=Path('/opt/mad4b-company-provisioning/reports/public-critical-css-v1')
MAX_AGE_S=5400
CONTRACT='mad4b.public-css.snapshot-variant-progress.v1'
HOSTS={'source':'tours.dev.mad4b.com','wayfa':'wayfatours.com'}
STAMP=re.compile(r'20[0-9]{6}T[0-9]{6}Z\Z')

def load(name, filename):
    spec=importlib.util.spec_from_file_location(name,D/filename)
    obj=importlib.util.module_from_spec(spec)
    spec.loader.exec_module(obj)
    return obj

def trusted_file(path,cap):
    if path.is_symlink() or not path.is_file():
        return False
    info=path.stat()
    return (stat.S_ISREG(info.st_mode) and info.st_uid==0
            and info.st_mode & 0o022 == 0
            and 0 < info.st_size <= cap)

def snapshot(root,site,queue,now=None):
    """Read only last pinned, private WP-CLI catalog. Never invent freshness."""
    if site not in HOSTS or root.is_symlink() or not root.is_dir():
        raise ValueError('untrusted-site-snapshot-root')
    now=time.time() if now is None else now
    folders=sorted((p for p in root.iterdir()
                    if p.is_dir() and not p.is_symlink()
                    and STAMP.fullmatch(p.name)),key=lambda p:p.name,reverse=True)
    for folder in folders:
        catalog=folder/'catalog.json'
        state=folder/'site.json'
        if not trusted_file(catalog,3000000) or not trusted_file(state,50000):
            continue
        oldest=min(catalog.stat().st_mtime,state.stat().st_mtime)
        if not 0 <= now-oldest <= MAX_AGE_S:
            continue
        cat=queue.read(catalog)
        st=queue.read(state)
        if (cat.get('host')!=HOSTS[site] or st.get('site_host')!=HOSTS[site]
            or st.get('critical_css')!='on'
            or not isinstance(st.get('css_epoch'),int)
            or not isinstance(cat.get('routes'),list)):
            raise ValueError('cached-wordpress-source-identity-invalid')
        queue.safe_catalog(cat,site)
        return folder,cat,st,int(now-oldest)
    raise ValueError('no-recent-root-owned-wordpress-catalog')

def selected_sites(arguments):
    # Company-owned Cron must not mutate another tenant's variant index.
    if not arguments:return ('source','wayfa')
    if len(arguments)!=1 or arguments[0] not in HOSTS:
        raise ValueError('untrusted-company-variant-observation-target')
    return (arguments[0],)


def refresh(site_args=()):
    sites=selected_sites(site_args)
    budget=load('cached_variant_cpu','public-route-cpu-guard.py')
    okay,reason=budget.examine()
    # The first-party 8-vCPU budget independently validates load/steal/PSI
    # before distinguishing two reserved WP-CLI slots from actual pressure.
    if not okay and reason!='central_wpcli_slots_reserved':
        print('DEFER_LIGHT_VARIANT_FALLBACK',reason,flush=True)
        return
    families=load('cached_variant_families','public-source-families.py')
    variants=load('cached_variant_index','public-template-variant-index.py')
    for site in sites:
        root=REPORTS/site
        try:
            folder,cat,st,age=snapshot(root,site,families.queue)
            wp=Path(variants.ROOTS[site])
            ledger=families.queue.read_ledger(root/'route-ledger.json',site)
            index=variants.refresh_file(site,cat,st,ledger,wp,families,
                                        root/'template-variant-index.json',budget=1)
            result={'contract':CONTRACT,'site':site,'host':HOSTS[site],
                    'source_snapshot':folder.name,'snapshot_age_seconds':age,
                    'new_observations':index.get('last_updated',0),
                    'attempted_observations':index.get('last_scanned',0),
                    'observations_total':len(index.get('observations',{})),
                    'policy_state':'active','evidence':'advisory_snapshot_only',
                    'real_time_wordpress_readback':False,
                    'css_published':False,'certificates_granted':False,
                    'chromium_invoked':False,'recorded_epoch':int(time.time())}
            families.queue.atom(root/'light-variant-progress-latest.json',result)
            print('LIGHT_VARIANTS_FROM_WP_SNAPSHOT',site,
                  'attempted',result['attempted_observations'],
                  'updated',result['new_observations'],
                  'total',result['observations_total'],flush=True)
        except (OSError,ValueError,TypeError,KeyError,BlockingIOError) as exc:
            print('DEFER_LIGHT_VARIANTS_NOT_POLICY_GATE',site,
                  str(exc)[:160],flush=True)

if __name__=='__main__':
    try:refresh(tuple(sys.argv[1:]))
    except (ValueError,TypeError,KeyError) as exc:
        print('HOLD_LIGHT_VARIANTS_SITE_SCOPE',str(exc)[:130],file=sys.stderr)
        raise SystemExit(4)
