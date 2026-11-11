#!/usr/bin/env python3
"""Reconcile central Company Environment Registry ↔ pinned Hostinger SSH/API route candidates.

The API route is never promoted by a credential file merely existing.
Requires independent confirmed account+website ID / provider authorization.
"""
import argparse,datetime,json,os,re,subprocess,sys,tempfile
from pathlib import Path
from urllib.parse import urlsplit
ROOT=Path('/opt/mad4b-company-provisioning')
POLICY=ROOT/'policies/company-hostinger-site-binding-map-v1.json'
REPORT=ROOT/'reports/company-hostinger-two-route-bindings-current.json'
CENTRAL_CONTAINER='php-wkgtkzta35wkkgouwt6ruljc'
HOSTS={
 'hosting-1':('u338416126','147.93.49.130','65002'),
 'hosting-2':('u948953904','92.113.28.241','65002'),
 'hosting-3':('u783534439','82.198.227.227','65002')
}
def ssh_cmd(target):
    u,host,port=HOSTS[target]
    base=Path('/opt/hostinger-shell-mcp/external-targets')/target
    assert (base/'known_hosts_verified').is_file() and (base/'id_ed25519').is_file(),'SSH_ENROLLMENT_MISSING'
    return ['ssh','-F','/dev/null','-T','-o','BatchMode=yes','-o','IdentitiesOnly=yes',
      '-o','StrictHostKeyChecking=yes','-o','UpdateHostKeys=no',
      '-o','UserKnownHostsFile='+str(base/'known_hosts_verified'),'-o','ConnectTimeout=7',
      '-i',str(base/'id_ed25519'),'-p',port,u+'@'+host]
def central_registry():
    code=r'''$r=MAD4B_Company_Environment_Registry_V2::discover();
$o=[];foreach((array)$r as $company=>$a)foreach((array)($a["environments"]??[]) as $env=>$site){
 if(!is_array($site))continue;
 $o[]=["company_id"=>$company,"environment"=>$env,"origin"=>(string)($site["canonical_origin"]??""),
       "site_uuid"=>$site["site_uuid"]??null,"registry_connection_state"=>$site["connection_state"]??null];
}
echo wp_json_encode($o,JSON_UNESCAPED_SLASHES);'''
    cmd=['docker','exec','-u','www-data',CENTRAL_CONTAINER,'wp','eval',code,'--path=/var/www/html','--skip-themes']
    r=subprocess.run(cmd,capture_output=True,text=True,timeout=30)
    if r.returncode:raise RuntimeError('CENTRAL_COMPANY_REGISTRY_READ_FAILED')
    return {(v['company_id'],v['environment']):v for v in json.loads(r.stdout)}
def proposed_bindings():
    # Optional binding candidates from Company Manager, never grants.
    code='echo wp_json_encode(get_option("mad4b_company_hostinger_connection_proposals_v1",array()),JSON_UNESCAPED_SLASHES);'
    r=subprocess.run(['docker','exec','-u','www-data',CENTRAL_CONTAINER,'wp','eval',code,
        '--path=/var/www/html','--skip-themes'],capture_output=True,text=True,timeout=28)
    if r.returncode:return {}
    value=json.loads(r.stdout)
    return value if isinstance(value,dict) else {}
def target_origin_matches(row,verified_company):
    if not verified_company or verified_company.get('origin')!=row['origin']:
        return False
    return row['ssh_root'].startswith('/home/'+row['ssh_username']+'/domains/')
def verify_ssh(row):
    import shlex
    command='cd '+shlex.quote(row['ssh_root'])+' && wp option get home --skip-plugins --skip-themes'
    r=subprocess.run(ssh_cmd(row['ssh_target'])+[command],capture_output=True,text=True,timeout=28)
    return r.returncode==0 and r.stdout.strip()==row['origin']
def choose_route(item,operation):
    if operation in ('site.identity.read','wordpress.wpcli.read','wordpress.litespeed.url_purge'):
        mutating=operation=='wordpress.litespeed.url_purge'
        return {'channel':'hostinger_ssh_wpcli',
                'state':(('ROUTE_AVAILABLE_REQUIRES_EXPLICIT_EXECUTION' if mutating else 'READY')
                          if item['ssh_verified'] else 'SSH_IDENTITY_NOT_VERIFIED'),
                'target':item['ssh_target'],'ssh_transport_verified':item['ssh_verified'],
                'mutation_performed':False,'write_grant_inferred':False}
    if operation=='hostinger.cdn.purge':
        return {'channel':'hostinger_api','state':'READY' if item['api_site_verified'] else 'WAITING_HOSTINGER_API_WEBSITE_BINDING',
                'account_alias':item['api_account_alias'],'website_domain_candidate':item['api_website_domain_candidate']}
    if operation=='company.cache.hostinger.refresh':
        return {'channel':'composite','state':'READY' if item['ssh_verified'] and item['api_site_verified'] else
              ('WAITING_HOSTINGER_API_WEBSITE_BINDING' if item['ssh_verified'] else 'SSH_IDENTITY_NOT_VERIFIED'),
              'steps':['wordpress.litespeed.url_purge','hostinger.cdn.purge','cdn.edge.verify']}
    return {'channel':'none','state':'UNSUPPORTED_OPERATION'}
def atomic(data):
    fd,tmp=tempfile.mkstemp(prefix='.hostinger-bindings-',dir=REPORT.parent)
    try:
        with os.fdopen(fd,'w') as f:
            json.dump(data,f,indent=2,sort_keys=True);f.write('\n');f.flush();os.fsync(f.fileno())
        os.chmod(tmp,0o600);os.replace(tmp,REPORT)
    finally:
        if os.path.exists(tmp):os.unlink(tmp)
def main():
    arg=argparse.ArgumentParser()
    arg.add_argument('--selftest',action='store_true')
    arg.add_argument('--route',choices=('site.identity.read','wordpress.wpcli.read',
          'wordpress.litespeed.url_purge','hostinger.cdn.purge','company.cache.hostinger.refresh'))
    arg.add_argument('--company')
    arg.add_argument('--environment',choices=('production','staging'))
    options=arg.parse_args()
    if options.selftest:
        case={'ssh_verified':True,'api_site_verified':False,'ssh_target':'hosting-1',
              'api_account_alias':'cloud-startup','api_website_domain_candidate':'almallahgroup-mg.com'}
        assert choose_route(case,'hostinger.cdn.purge')['state']=='WAITING_HOSTINGER_API_WEBSITE_BINDING'
        assert choose_route(case,'wordpress.wpcli.read')['state']=='READY'
        assert choose_route(case,'company.cache.hostinger.refresh')['state']=='WAITING_HOSTINGER_API_WEBSITE_BINDING'
        print('TWO_ROUTE_BINDING_SELFTEST_PASS');return
    if options.route and (options.company is None or options.environment is None):
        arg.error('--route requires --company and --environment')
    policy=json.loads(POLICY.read_text())
    assert policy['contract']=='mad4b.company-hostinger-site-binding-map.v1'
    central=central_registry()
    proposed=proposed_bindings()
    rows={}
    for row in policy['sites']:
        key=(row['company_id'],row['environment'])
        ident=central.get(key)
        attached=target_origin_matches(row,ident)
        verified=bool(attached and verify_ssh(row))
        proposal=proposed.get(row['company_id']+':'+row['environment'],{})
        if not isinstance(proposal,dict):proposal={}
        matched_proposal=(proposal.get('origin')==row['origin']
            and proposal.get('ssh_target')==row['ssh_target']
            and proposal.get('api_account_alias')==row['api_account_alias']
            and proposal.get('api_website_domain_candidate')==row['api_website_domain_candidate'])
        # Provider website has not appeared in a permitted account inventory;
        # matching metadata alone is never proof of API access.
        api_verified=False
        result={'company_id':row['company_id'],'environment':row['environment'],
            'canonical_origin':row['origin'],'company_site_uuid':ident.get('site_uuid') if ident else None,
            'central_company_identity_matched':attached,
            'ssh_target':row['ssh_target'],'ssh_verified':verified,'ssh_readback':'PASS' if verified else 'FAILED',
            'api_account_alias':row['api_account_alias'],
            'api_website_domain_candidate':row['api_website_domain_candidate'],
            'api_site_verified':api_verified,'api_state':'WAITING_PROVIDER_ACCOUNT_WEBSITE_PROOF',
            'ui_proposal_present':matched_proposal,
            'routes':{}}
        for op in ('site.identity.read','wordpress.wpcli.read',
                   'wordpress.litespeed.url_purge','hostinger.cdn.purge','company.cache.hostinger.refresh'):
            result['routes'][op]=choose_route(result,op)
        rows[row['company_id']+':'+row['environment']]=result
    record={'contract':'mad4b.company-hostinger-two-route-bindings.v1',
        'utc':datetime.datetime.now(datetime.timezone.utc).isoformat(),
        'source':'MAD4B Company Environment Registry + registered Hostinger SSH identities',
        'mode':'READ_ONLY_REMOTE_BINDING_RECONCILE_NO_GRANT_ISSUED',
        'site_count':len(rows),'sites':rows,
        'provider_api_credential_values_included':False,'websites_verified_through_api':0}
    atomic(record)
    if options.route:
        key=options.company+':'+options.environment
        if key not in rows:raise SystemExit('COMPANY_SITE_NOT_ENROLLED')
        print(json.dumps({'scope':key,'origin':rows[key]['canonical_origin'],
             'route':rows[key]['routes'][options.route]}))
    else:
        print(json.dumps({'state':'ROUTES_RECONCILED','sites':len(rows),'ssh_verified':sum(x['ssh_verified'] for x in rows.values()),
          'api_verified':sum(x['api_site_verified'] for x in rows.values()),'report':str(REPORT)}))
if __name__=='__main__':main()
