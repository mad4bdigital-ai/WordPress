#!/usr/bin/env python3
"""Independent, OPT-IN Company Manager owner-authorization service.

Never involved in the original VPS Client/SSH/WordPress execution gate.
No unsolicited emails: only centrally approved QUEUED_EMAIL requests are delivered.
No emails, bearer codes, passwords or SSH keys persisted to worker logs.
"""
import argparse,datetime,hashlib,importlib.util,json,os,secrets,shlex,subprocess,sys,time,fcntl,hmac,base64
from pathlib import Path
BASE=Path('/opt/mad4b-company-provisioning')
PLANNER=BASE/'bin/company-central-vps-preferred-route-v1.py'
TWO_ROUTE=BASE/'bin/company-hostinger-two-route-binding-v1.py'
BINDINGS=BASE/'policies/company-hostinger-site-binding-map-v1.json'
ORIGINAL_ROUTER=Path('/opt/mad4b-company-transport/unified-execution-router-v1/router.py')
CENTRAL='php-wkgtkzta35wkkgouwt6ruljc'
CHECKPOINT='/opt/mad4b-company-provisioning/reports/company-optional-authorization-worker-current.json'
def load_module(path,name):
    spec=importlib.util.spec_from_file_location(name,str(path))
    obj=importlib.util.module_from_spec(spec);spec.loader.exec_module(obj);return obj
SSH=load_module(TWO_ROUTE,'company_ssh')
ROUTER=load_module(ORIGINAL_ROUTER,'original_company_router')
POLICY=ROUTER.load()
BIND=json.loads(BINDINGS.read_text())['sites']
# No per-company code: discover original registered remote Staging scopes.
# Explicit Email Queue is still mandatory; discovery never sends mail.
SITES=tuple(sorted(k for k,v in POLICY['targets'].items()
    if k.endswith(':staging') and 'cross_ssh_wpcli' in v.get('providers',{})
    and any(b['company_id']+':'+b['environment']==k for b in BIND)))
def php_quote(s):return json.dumps(s,ensure_ascii=False)
def run(cmd,input=None,timeout=55):
    # Honor MAD4B's native max-two-WP-CLI-session governor. 75 means
    # capacity, never an authorization failure or permission to bypass it.
    for attempt in range(2):
        p=subprocess.run(cmd,input=input,text=True,capture_output=True,timeout=timeout)
        if p.returncode==75 and 'STAGING_WPCLI_BUSY' in p.stderr:
            if attempt==1:raise RuntimeError('ORIGINAL_WPCLI_CAPACITY_BUSY_RETRY_LATER')
            time.sleep(2)
            continue
        break
    if p.returncode:raise RuntimeError('ORIGINAL_HOST_EXEC_FAILED_'+str(p.returncode))
    line=[x for x in p.stdout.splitlines() if x.lstrip().startswith('{')]
    if not line:raise RuntimeError('HOST_READBACK_MISSING')
    try:return json.loads(line[-1])
    except Exception:raise RuntimeError('HOST_READBACK_INVALID_JSON')
def wp_local(php,data=None,timeout=55):
    code=php
    return run(['/usr/bin/docker','exec','-i','-u','www-data',CENTRAL,
       '/usr/local/bin/wp','eval',code,'--path=/var/www/html','--skip-themes'],
       input=json.dumps(data,separators=(',',':')) if data is not None else None,timeout=timeout)
def wp_remote(binding,php,data=None,timeout=55):
    command='cd '+shlex.quote(binding['ssh_root'])+' && wp eval '+shlex.quote(php)+' --skip-themes'
    return run(SSH.ssh_cmd(binding['ssh_target'])+[command],
       input=json.dumps(data,separators=(',',':')) if data is not None else None,timeout=timeout)
def eligible(company,env):
    key=company+':'+env
    if key not in SITES:raise RuntimeError('NOT_ENROLLED_REMOTE_STAGING_SCOPE')
    target=POLICY['targets'][key]
    routing=ROUTER.plan(key,policy=POLICY,operation='wordpress.home.read')
    if routing.get('state')!='READY' or routing.get('selected_lane')!='cross_ssh_wpcli':
        raise RuntimeError('ORIGINAL_CROSS_SSH_NOT_VERIFIED')
    binding=next((b for b in BIND if b['company_id']==company and b['environment']==env),None)
    if not binding:raise RuntimeError('ORIGINAL_SITE_REGISTRY_MISSING')
    if target['site_uuid']!=binding.get('site_uuid',target['site_uuid']):
        raise RuntimeError('ORIGINAL_SITE_UUID_BINDING_CHANGED')
    return target,binding
def current_requests():
    code=r'''if(!class_exists('MAD4B_Optional_Company_Authorization_Central_V1',false))WP_CLI::error('CENTRAL_MODULE_NOT_LOADED');
    echo wp_json_encode(array('records'=>MAD4B_Optional_Company_Authorization_Central_V1::all()));'''
    return wp_local(code)['records']
def discover(company,env,remote):
    code=r'''if(!class_exists('MAD4B_Optional_Company_Authorization_Site_V1',false)
    ||!class_exists('MAD4B_SCP_Site_Profile',false))WP_CLI::error('COMPANY_SITE_MODULE_NOT_LOADED');
    $p=MAD4B_SCP_Site_Profile::profile();
    $u=get_users(array('role'=>'master_admin','fields'=>'all','number'=>30));
    $eligible=array();
    foreach($u as $item){if(!is_email($item->user_email)||!user_can($item,'read'))continue;
      $eligible[]=array('user_id'=>(int)$item->ID,
         'email_sha256'=>hash('sha256',strtolower(trim($item->user_email))));
    }
    echo wp_json_encode(array('origin'=>untrailingslashit(home_url('/')),
      'site_uuid'=>$p['site_uuid']??'','environment'=>wp_get_environment_type(),
      'candidates'=>$eligible));'''
    result=wp_remote(remote,code)
    target=POLICY['targets'][company+':'+env]
    if(result['origin']!=target['canonical_origin'] or result['site_uuid']!=target['site_uuid']
       or result['environment']!=env):
       raise RuntimeError('SITE_IDENTITY_READBACK_NOT_MATCHED')
    code=r'''$v=json_decode(file_get_contents('php://stdin'),true);
    if(!is_array($v))WP_CLI::error('INPUT_INVALID');
    $out=MAD4B_Optional_Company_Authorization_Central_V1::host_candidates(
      $v['company'],$v['environment'],$v['site_uuid'],$v['candidates']);
    if(is_wp_error($out))WP_CLI::error($out->get_error_code());
    echo wp_json_encode($out);'''
    return wp_local(code,dict(company=company,environment=env,
                              site_uuid=result['site_uuid'],candidates=result['candidates']))
def prepare_remote(record,remote,token):
    if record['state']!='QUEUED_EMAIL' or record['expires_epoch']<time()+301:
        raise RuntimeError('REQUEST_NOT_ACTIVE')
    id=record['id']
    payload={k:record[k] for k in ('id','site_uuid','origin','company','environment','purpose','owner_user_id','expires_epoch')}
    payload['token_hash']=hashlib.sha256(token.encode()).hexdigest()
    code=r'''$p=json_decode(file_get_contents('php://stdin'),true);
    if(!is_array($p))WP_CLI::error('INPUT_INVALID');
    $result=MAD4B_Optional_Company_Authorization_Site_V1::prepare($p);
    echo wp_json_encode($result);'''
    r=wp_remote(remote,code,payload)
    if r.get('ok') is not True or r.get('owner_role_verified') is not True:
        raise RuntimeError('EXACT_REMOTE_MASTER_ADMIN_NOT_VERIFIED_'+str(r.get('reason','UNKNOWN')))
    if r.get('site_uuid')!=record['site_uuid'] or r.get('owner_user_id')!=record['owner_user_id']:
        raise RuntimeError('REMOTE_OWNER_BINDING_CHANGED')
    return r
def deliver(record,identity,token,send):
    from urllib.parse import urlencode
    url=record['origin']+'/wp-admin/admin.php?'+urlencode({
        'page':'mad4b-company-authorization','request':record['id'],'code':token})
    values={'id':record['id'],'site_uuid':record['site_uuid'],
      'owner_user_id':record['owner_user_id'],'owner_email':identity['owner_email'],
      'url':url,'action':'send' if send else 'dry-run'}
    code=r'''$v=json_decode(file_get_contents('php://stdin'),true);
    if(!is_array($v))WP_CLI::error('INPUT_INVALID');
    $r=MAD4B_Optional_Company_Authorization_Central_V1::host_delivery(
      $v['id'],$v['site_uuid'],$v['owner_user_id'],$v['owner_email'],$v['url'],$v['action']);
    if(is_wp_error($r))WP_CLI::error($r->get_error_code());
    echo wp_json_encode($r);'''
    return wp_local(code,values,timeout=65)
def activation_readback(record,remote,remote_state):
    """Observe native ChatGPT PKCE token and original exact grants; never mint."""
    activation=remote_state.get('activation')
    if not isinstance(activation,dict):
        raise RuntimeError('ORIGINAL_SITE_ACTIVATION_PROOF_UNAVAILABLE')
    values={'id':record['id'],'site_uuid':record['site_uuid'],
         'owner_user_id':record['owner_user_id'],
         'digest':remote_state['receipt_digest'],'activation':activation}
    code=r"""$v=json_decode(file_get_contents('php://stdin'),true);
    if(!is_array($v))WP_CLI::error('ACTIVATION_INPUT_INVALID');
    $r=MAD4B_Optional_Company_Authorization_Central_V1::host_activation_readback(
        $v['id'],$v['site_uuid'],$v['owner_user_id'],$v['digest'],$v['activation']);
    if(is_wp_error($r))WP_CLI::error($r->get_error_code());
    echo wp_json_encode($r);"""
    persisted=wp_local(code,values)
    if persisted.get('activation_state')!=activation.get('state'):
        raise RuntimeError('ORIGINAL_PKCE_GRANT_PROOF_MISMATCH')
    return {'activation_state':persisted['activation_state'],
            'oauth_token_issued':bool(persisted.get('oauth_token_issued')),
            'exact_grants_ready':bool(persisted.get('exact_grants_ready')),
            'next_original_ability':persisted.get('next_original_ability',''),
            'original_grants_created_by_host_worker':False}

def mirror(record,remote):
    code=r"""$v=json_decode(file_get_contents('php://stdin'),true);
    $r=MAD4B_Optional_Company_Authorization_Site_V1::readback($v['id']);
    echo wp_json_encode($r);"""
    remote_state=wp_remote(remote,code,{'id':record['id']})
    if not remote_state.get('ok') or remote_state.get('state') not in ('APPROVED','DECLINED'):
        return {'state':'NO_OWNER_DECISION'}
    for key in ('id','site_uuid','company','environment','origin','purpose','owner_user_id'):
        if record[key]!=remote_state[key]:raise RuntimeError('REMOTE_RECEIPT_SITE_SCOPE_MISMATCH')
    if remote_state['decision_user_id']!=record['owner_user_id']:
        raise RuntimeError('REMOTE_RECEIPT_USER_MISMATCH')
    digest=remote_state.get('receipt_digest','')
    if not isinstance(digest,str) or len(digest)!=64:
        raise RuntimeError('REMOTE_RECEIPT_DIGEST_MISSING')
    if record['state'] in ('EMAIL_ACCEPTED','EMAIL_FAILED'):
        values={'id':record['id'],'site_uuid':record['site_uuid'],
             'owner_user_id':record['owner_user_id'],
             'decision':remote_state['state'],'digest':digest}
        code=r"""$v=json_decode(file_get_contents('php://stdin'),true);
        $r=MAD4B_Optional_Company_Authorization_Central_V1::host_decision(
           $v['id'],$v['site_uuid'],$v['owner_user_id'],$v['decision'],$v['digest']);
        if(is_wp_error($r))WP_CLI::error($r->get_error_code());
        echo wp_json_encode($r);"""
        readback=wp_local(code,values)
        if readback.get('state')!='CONSENT_'+remote_state['state']:
            raise RuntimeError('CENTRAL_RECEIPT_READBACK_FAILED')
    elif record['state']!='CONSENT_APPROVED' or remote_state['state']!='APPROVED':
        raise RuntimeError('CONSENT_STATE_ORIGINAL_MISMATCH')
    if remote_state['state']=='APPROVED':
        proof=activation_readback(record,remote,remote_state)
        return {'state':'CONSENT_APPROVED',**proof}
    return {'state':'CONSENT_DECLINED','original_write_grants_created':False}

def owner_link_token(record):
    # Recoverable idempotently after worker interruption, without storing
    # plaintext email links or bearer codes in WordPress/worker reports.
    keypath=Path('/etc/mad4b/optional-company-authorization-hmac.key')
    if not keypath.exists():raise RuntimeError('HOST_OWNER_LINK_KEY_NOT_CONFIGURED')
    st=keypath.stat()
    if st.st_uid!=0 or st.st_mode & 0o077 or st.st_size!=32:
        raise RuntimeError('HOST_OWNER_LINK_KEY_INSECURE')
    key=keypath.read_bytes()
    context='|'.join(str(record[k]) for k in ('id','company','environment','site_uuid','owner_user_id'))
    return base64.urlsafe_b64encode(hmac.new(key,context.encode(),hashlib.sha256).digest()).decode().rstrip('=')
def worker(dry_run=False,site_filter=None):
    out={'contract':'mad4b.company.optional-authorization.worker.v1',
         'at_utc':datetime.datetime.now(datetime.timezone.utc).isoformat(),
         'optional':True,'required_for_routing':False,'auto_send':False,
         'candidates':{},'processed':[],'mail_attempts':0,'emails_accepted':0,
         'owner_decisions_synced':0,'new_write_grants':0,'original_313_receipts':0}
    # Intentional opt-in: a periodic worker NEVER scans all companies'
    # users or attempts email. Owner candidate discovery is an explicit
    # separate 'recipients' action; this run processes only real queued work.
    rows=current_requests()
    for id,record in rows.items():
        if not isinstance(record,dict) or record.get('state') not in ('QUEUED_EMAIL','EMAIL_ACCEPTED','EMAIL_FAILED','CONSENT_APPROVED'):
            continue
        site_key=record.get('company','')+':'+record.get('environment','')
        if site_filter and site_key!=site_filter:continue
        entry={'id':id,'site':site_key}
        try:
            _,remote=eligible(record['company'],record['environment'])
            if record['state']=='QUEUED_EMAIL':
                if dry_run:
                    entry['state']='DRY_RUN_NO_MAIL';out['processed'].append(entry);continue
                token=owner_link_token(record)
                identity=prepare_remote(record,remote,token)
                out['mail_attempts']+=1
                status=deliver(record,identity,token,True)
                entry['state']=status['state']
                if status['state']=='EMAIL_ACCEPTED':out['emails_accepted']+=1
            else:
                entry.update(mirror(record,remote))
                if entry['state'].startswith('CONSENT_'):out['owner_decisions_synced']+=1
        except Exception as e:
            entry['state']='ERROR'
            entry['reason']=str(e)[:115] # intentionally no email, URL, token in exceptions
        out['processed'].append(entry)
    return out
def main():
    # Non-blocking singleton prevents duplicate remote session floods.
    lock=os.open('/run/lock/mad4b-optional-company-authorization.lock',os.O_CREAT|os.O_RDWR,0o600)
    try:fcntl.flock(lock,fcntl.LOCK_EX|fcntl.LOCK_NB)
    except BlockingIOError:raise SystemExit('OPTIONAL_OWNER_WORKER_ALREADY_RUNNING')
    ap=argparse.ArgumentParser()
    ap.add_argument('action',choices=('once','dry-run','recipients','selftest'))
    ap.add_argument('--site',choices=SITES,default=None)
    a=ap.parse_args()
    if a.action=='selftest':
        assert len(SITES)>=1 and all(x.endswith(':staging') for x in SITES)
        print(json.dumps({'selftest':'PASS','default_automatic_send':False,'requires_explicit_queue':True,'no_routing_gates':True}));return
    if a.action=='recipients':
        for key in SITES:
            if a.site and key!=a.site:continue
            company,env=key.split(':')
            _,site=eligible(company,env)
            r=discover(company,env,site)
            print(json.dumps({'site':key,'owner_candidates_verified':r['eligible_owner_count'],
              'no_emails_printed':True}))
        return
    try:
        report=worker(dry_run=a.action=='dry-run',site_filter=a.site)
    except RuntimeError as exc:
        if str(exc)!='ORIGINAL_WPCLI_CAPACITY_BUSY_RETRY_LATER':
            raise
        report={'contract':'mad4b.company.optional-authorization.worker.v1',
                'at_utc':datetime.datetime.now(datetime.timezone.utc).isoformat(),
                'state':'DEFERRED_CAPACITY','reason':'ORIGINAL_WPCLI_CAPACITY_BUSY',
                'email_attempts':0,'write_grants_issued':0,'optional':True,
                'original_router_blocked':False}
    if a.action=='once':
        p=Path(CHECKPOINT)
        t=p.with_suffix('.tmp')
        t.write_text(json.dumps(report,indent=2)+'\n')
        os.chmod(t,0o600);t.replace(p)
    print(json.dumps(report))
if __name__=='__main__':main()
