#!/usr/bin/env python3
"""Autonomous low-cost unified source registry refresh; never runs Chromium or publishes CSS."""
import fcntl,hashlib,importlib.util,os,pathlib,subprocess,sys,time
from pathlib import Path
D=Path(__file__).resolve().parent

def load(name,file):
    spec=importlib.util.spec_from_file_location(name,D/file)
    mod=importlib.util.module_from_spec(spec);spec.loader.exec_module(mod);return mod


def main():
    # A fresh first-party CPU budget is required before both WordPress CLI
    # source-catalog reads. On pressure, preserve the last certs and retry.
    guard=subprocess.run(['python3',str(D/'public-route-cpu-guard.py')],
                         capture_output=True,text=True,timeout=8)
    if guard.returncode:
        print('DEFER_SOURCE_FAMILY_REFRESH',guard.stdout.strip()[:100]);return
    lock=Path('/run/lock/mad4b-public-source-family-refresh.lock')
    with lock.open('a+') as file:
        try:fcntl.flock(file,fcntl.LOCK_EX|fcntl.LOCK_NB)
        except BlockingIOError:
            print('SKIP_SOURCE_FAMILY_REFRESH_ALREADY_RUNNING');return
        runner=load('ccss_source_refresh_runner','run-public-route.py')
        families=load('ccss_source_refresh_families','public-source-families.py')
        for site in ('source','wayfa'):
            host,php,wp,env=runner.CONFIG[site]
            # Do not ingest arbitrary URLs; WP-CLI and original site-profile
            # runtime provide the only identities. This is a pure report.
            state=runner.obs(php,host,env)
            # Use the exact original runner's per-company source admission.
            # Source Staging 422 v1.0.1 is an approved separate release,
            # while Wayfa Production retains original 421/422 byte parity.
            state['runtime_sha']=runner.approved_native_runtime_sha(site,wp)
            state['qa_policy_sha']=runner.qa_policy_sha()
            catalog=runner.catalog(php,host)
            if any('source_profile' not in item for item in catalog['routes']):
                raise ValueError('source_catalog_not_migrated:'+site)
            root=Path('/opt/mad4b-company-provisioning/reports/public-critical-css-v1')/site
            ledger=families.queue.read_ledger(root/'route-ledger.json',site)
            result=families.build(site,catalog,state,ledger,wp)
            result['refreshed_at_utc']=time.strftime('%Y-%m-%dT%H:%M:%SZ',time.gmtime())
            families.queue.atom(root/'source-families-latest.json',result)
            print('SOURCE_FAMILY_REGISTRY_REFRESH',site,'routes',result['routes_discovered'],
                  'families',result['source_groups_total'],
                  'qualified',result['source_canary_qualified_groups'],
                  'pending',result['source_groups_by_status'].get('source_canary_pending',0),flush=True)

if __name__=='__main__':
    try:main()
    except (OSError,ValueError,KeyError,TypeError,subprocess.TimeoutExpired,subprocess.CalledProcessError) as exc:
        print('HOLD_SOURCE_FAMILY_REGISTRY_REFRESH',str(exc)[:220],file=sys.stderr);sys.exit(4)
