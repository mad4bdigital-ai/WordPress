#!/usr/bin/env python3
"""Read-only MAD4B pricing cache release/invalidator observer.

Checks code drift and event-driven path invalidation; never executes pricing,
touches customer records or modifies any remote WordPress site.
"""
import argparse,datetime,hashlib,json,subprocess,sys,time
from pathlib import Path
ROOT=Path('/opt/mad4b-company-provisioning')
DEFAULT=ROOT/'policies/company-pricing-cache-inheritance-v1.json'
REPORT=ROOT/'reports/company-pricing-cache-inheritance-current.json'
def sha(file):return hashlib.sha256(file.read_bytes()).hexdigest()
def inspect(policy):
 report={'contract':'mad4b.pricing-cache-inheritance-observer.v1',
  'observed_utc':datetime.datetime.now(datetime.timezone.utc).isoformat(),
  'release_version':policy.get('version'),'sites':{},
  'summary':{'PASS':0,'DRIFT':0,'DEGRADED':0}}
 for name,spec in policy['sites'].items():
  issues=[];warnings=[];module_checks={}
  root=Path(spec['wp_content'])
  for rel,digest in policy['pinned_files'].items():
   file=root/rel
   actual=sha(file) if file.is_file() and not file.is_symlink() else ''
   valid=(actual==digest)
   module_checks[rel]={'match':valid,'sha256':actual}
   if not valid:issues.append('FILE_HASH_DRIFT:'+rel)
  for suffix in ['path','timer']:
   unit='mad4b-pricing-cache-invalidation-'+name+'.'+suffix
   run=subprocess.run(['systemctl','is-active',unit],capture_output=True,text=True,timeout=10)
   if run.stdout.strip()!='active':issues.append('RECEIPT_WATCHER_INACTIVE:'+unit)
  signal=root/'.mad4b-pricing-policy-change.signal'
  receipt=ROOT/'reports'/('company-pricing-cache-invalidation-'+name+'.json')
  if signal.is_file() and signal.stat().st_size>0:
   try:
    event=json.loads(signal.read_text())
    x=json.loads(receipt.read_text()) if receipt.is_file() else {}
    if event.get('contract')!='mad4b.pricing-cache-policy-change.v1':
     issues.append('UNRECOGNIZED_PRICE_POLICY_SIGNAL')
    elif x.get('event_id')!=event.get('event_id') or x.get('status')!='PASS':
     seconds=max(0,time.time()-signal.stat().st_mtime)
     if seconds>360:issues.append('PRICING_POLICY_CHANGE_NOT_INVALIDATED')
     else:warnings.append('PRICING_POLICY_INVALIDATION_PENDING')
    elif x.get('paths_versioned',0)<spec['min_paths']:
     issues.append('PUBLIC_PRICE_ROUTE_INVALIDATION_INCOMPLETE')
   except (json.JSONDecodeError,OSError,TypeError):
    issues.append('PRICE_CHANGE_RECEIPT_INVALID')
  state='DRIFT' if issues else ('DEGRADED' if warnings else 'PASS')
  report['sites'][name]={'state':state,'issues':issues,'warnings':warnings,
   'verified_files':module_checks}
  report['summary'][state]+=1
 report['verdict']='PASS' if report['summary']['PASS']==len(policy['sites']) else 'ACTION_REQUIRED'
 return report
def main():
 arg=argparse.ArgumentParser()
 arg.add_argument('--policy',default=str(DEFAULT))
 arg.add_argument('--report',default=str(REPORT))
 arg.add_argument('--strict',action='store_true')
 a=arg.parse_args()
 policy=json.loads(Path(a.policy).read_text())
 if policy.get('contract')!='mad4b.pricing-cache-source-manifest.v1':
  raise ValueError('POLICY_CONTRACT_UNTRUSTED')
 result=inspect(policy)
 path=Path(a.report)
 tmp=path.with_name('.'+path.name+'.tmp')
 tmp.write_text(json.dumps(result,indent=2,ensure_ascii=False)+'\n')
 tmp.replace(path)
 print(json.dumps({'verdict':result['verdict'],'summary':result['summary'],
  'observed_utc':result['observed_utc']},ensure_ascii=False))
 return 2 if a.strict and result['verdict']!='PASS' else 0
if __name__=='__main__':sys.exit(main())
