#!/usr/bin/env python3
"""Fleet-scoped Hostinger CDN operation orchestrator. No fabricated CDN purge."""
import argparse,datetime,hashlib,json,os,secrets,shlex,subprocess,tempfile
from pathlib import Path
from urllib.parse import urlsplit
BASE=Path('/opt/mad4b-company-provisioning')
REPORT=BASE/'reports/company-hostinger-cdn-operation-current.json'
AUDIT=BASE/'reports/company-hostinger-cloud-cdn-edge-current.json'
INVENTORY=BASE/'reports/hostinger-cloud-startup-sites-current.json'
KNOWLEDGE=BASE/'knowledge/hostinger-cdn/hostinger-cdn-ssh-invalidation-v1.json'
ROOTS={
 'tourism.almallahgroup-mg.com':('/home/u338416126/domains/almallahgroup-mg.com/public_html/tourism.almallahgroup-mg.com','hosting-1'),
 'www.wovacation.com':('/home/u338416126/domains/wovacation.com/public_html','hosting-1'),
}
PROFILE='/opt/hostinger-shell-mcp/external-targets/hosting-1'
def ssh():
    folder=Path(PROFILE)
    assert all((folder/x).is_file() for x in ('known_hosts_verified','id_ed25519')),'ENROLLED_SSH_MISSING'
    return ['ssh','-F','/dev/null','-T','-o','BatchMode=yes','-o','IdentitiesOnly=yes','-o','StrictHostKeyChecking=yes','-o','UpdateHostKeys=no','-o','UserKnownHostsFile='+str(folder/'known_hosts_verified'),'-o','ConnectTimeout=8','-i',str(folder/'id_ed25519'),'-p','65002','u338416126@147.93.49.130']
def read_remote_home(host):
    path,_=ROOTS[host]
    r=subprocess.run(ssh()+['cd '+shlex.quote(path)+' && wp option get home --skip-themes --skip-plugins'],
       capture_output=True,text=True,timeout=23)
    if r.returncode:raise RuntimeError('PINNED_SSH_WPCLI_HOME_READ_FAILED')
    home=r.stdout.strip()
    if home!='https://'+host:raise RuntimeError('REMOTE_WORDPRESS_HOME_MISMATCH')
    return True
def targeted_litespeed_purge(host):
    path,_=ROOTS[host]
    command='cd '+shlex.quote(path)+' && wp litespeed-purge url '+shlex.quote('https://'+host+'/')+' --skip-themes'
    r=subprocess.run(ssh()+[command],capture_output=True,text=True,timeout=30)
    if r.returncode or 'Success: Purged the URL!' not in r.stdout:
        raise RuntimeError('TARGETED_LITESPEED_PURGE_NOT_ACKNOWLEDGED')
    return {'scope':'https://'+host+'/','provider':'litespeed_url',
        'provider_ack':True,'cdn_provider_ack':False,'global_purge':False}
def safe_report(report):
    REPORT.parent.mkdir(parents=True,exist_ok=True)
    fd,p=tempfile.mkstemp(prefix='.hostinger-operation-',dir=REPORT.parent)
    try:
        with os.fdopen(fd,'w') as f:
            json.dump(report,f,indent=2,sort_keys=True);f.write('\n')
            f.flush();os.fsync(f.fileno())
        os.chmod(p,0o600);os.replace(p,REPORT)
    finally:
        if os.path.exists(p):os.unlink(p)
def reconciled_state(probes):
    if any(v.get('state','ERROR').startswith('CRITICAL') for name,v in probes.items() if name.startswith('cdn_') and name!='cdn_anonymous'):
        return 'WAITING_PROVIDER_CDN_PURGE'
    if any(v.get('state')!='PASS' for name,v in probes.items() if name!='cdn_anonymous'):
        return 'VERIFICATION_REQUIRED'
    return 'VERIFIED_NO_SENSITIVE_EDGE_HIT'
def verify_audit_freshness(audit):
    stamp=datetime.datetime.fromisoformat(audit['utc'])
    age=(datetime.datetime.now(datetime.timezone.utc)-stamp).total_seconds()
    if age<0 or age>3600:raise RuntimeError('CDN_AUDIT_OLDER_THAN_1_HOUR')
def main():
    parser=argparse.ArgumentParser()
    parser.add_argument('--action',choices=('reconcile','refresh-origin','selftest'),default='reconcile')
    parser.add_argument('--site',choices=sorted(ROOTS))
    args=parser.parse_args()
    if args.action=='selftest':
        assert reconciled_state({'cdn_currency':{'state':'CRITICAL_CDN_SENSITIVE_CONTEXT_HIT'}})=='WAITING_PROVIDER_CDN_PURGE'
        assert reconciled_state({'cdn_currency':{'state':'PASS'},'origin_auth':{'state':'PASS'}})=='VERIFIED_NO_SENSITIVE_EDGE_HIT'
        print('HOSTINGER_COMPANY_OPERATION_SELFTEST_PASS');return
    if args.action=='refresh-origin' and not args.site:parser.error('--site is required to refresh one origin')
    knowledge=json.loads(KNOWLEDGE.read_text())
    assert knowledge['operation_id']=='company.cache.hostinger.refresh'
    inventory=json.loads(INVENTORY.read_text())
    inventory_hosts={urlsplit(row['home']).hostname:row['remote_root'] for row in inventory['sites']}
    audit=json.loads(AUDIT.read_text())
    verify_audit_freshness(audit)
    result={'contract':'mad4b.company-hostinger-cdn-operation.v1',
      'operation_id':'company.cache.hostinger.refresh',
      'knowledge_id':knowledge['knowledge_id'],'run_id':secrets.token_hex(12),
      'utc':datetime.datetime.now(datetime.timezone.utc).isoformat(),
      'mode':args.action,'provider':'hostinger_cdn','state':'IN_PROGRESS',
      'original_313_signed_receipt':False,'provider_edge_purge_called':False,
      'global_purge':False,'redis_flush':False,'companies':{}}
    selected=[args.site] if args.site else sorted(ROOTS)
    try:
        for host in selected:
            path,_=ROOTS[host]
            if inventory_hosts.get(host)!=path:raise RuntimeError('COMPANY_REGISTRY_SSH_ROOT_MISMATCH')
            if not read_remote_home(host):raise RuntimeError('UNVERIFIED_WORDPRESS_IDENTITY')
            audit_row=audit['sites'].get(host)
            if not isinstance(audit_row,dict):raise RuntimeError('SITE_NOT_PRESENT_IN_LIVE_EDGE_AUDIT')
            entry={'origin':'https://'+host,'ssh_readback':'PASS','edge_state':reconciled_state(audit_row),
                   'edge_audit_utc':audit['utc'],'edge_audit_run_id':audit.get('run_id'),
                   'critical_contexts':[k for k,v in audit_row.items() if v.get('state','').startswith('CRITICAL')],
                   'lite_speed_purge_ack':False,'hostinger_cdn_purge_ack':False}
            if args.action=='refresh-origin':
                entry['origin_purge']=targeted_litespeed_purge(host)
                entry['lite_speed_purge_ack']=True
                # Old edge audit was taken before the purge; still require an
                # independent CDN readback and never infer that CDN cleared.
                entry['edge_state']='INDEPENDENT_CDN_RECHECK_REQUIRED'
            result['companies'][host]=entry
        result['state']='WAITING_PROVIDER_CDN_PURGE' if any(x['edge_state']=='WAITING_PROVIDER_CDN_PURGE' for x in result['companies'].values()) else (
            'INDEPENDENT_CDN_RECHECK_REQUIRED' if args.action=='refresh-origin' else 'VERIFIED_NO_SENSITIVE_EDGE_HIT')
        result['provider_action_required']='Authorized Hostinger CDN invalidation and fresh independent edge verification' if result['state']!='VERIFIED_NO_SENSITIVE_EDGE_HIT' else None
    except Exception as e:
        result['state']='ERROR'
        result['reason']=str(e)[:180]
    safe_report(result)
    print(json.dumps({'operation_id':result['operation_id'],'state':result['state'],
       'companies':{k:{'edge_state':v['edge_state'],'critical_contexts':v['critical_contexts']}
          for k,v in result['companies'].items()},'receipt':str(REPORT)}))
    if result['state']=='ERROR':raise SystemExit(2)
if __name__=='__main__':main()
