#!/usr/bin/env python3
"""MAD4B Central Company Router: transport selection and authorization are separate.

Prefer the ORIGINAL VPS Client MCP -> pinned Cross-SSH transport for exact
enrolled remote company WordPress sites. Company Manager OAuth remains the
DEFAULT interactive identity/grant authorization route; no SSH credential
is an original WordPress write grant. Project read-only decisions centrally.
No remote mutation, arbitrary host command, or OAuth token handling.
"""
from __future__ import annotations
import argparse,datetime,hashlib,importlib.util,json,os,re,stat,subprocess,sys,tempfile,time
from pathlib import Path
BASE=Path('/opt/mad4b-company-provisioning')
ORIGINAL=Path('/opt/mad4b-company-transport/unified-execution-router-v1/router.py')
VPS_MCP_CLIENT=Path('/opt/mad4b-company-transport/registry-remote-v1/mcp_stdio_client.py')
OUTPUT=BASE/'reports/company-central-vps-preferred-route-current.json'
CENTRAL='php-wkgtkzta35wkkgouwt6ruljc'
ORIGIN='https://tours.dev.mad4b.com'
CONTRACT='mad4b.company-central-preferred-router.v1'
OPTION='mad4b_company_central_preferred_router_v1'
VALID_OPS={
    'site.identity.read':'read',
    'wordpress.home.read':'read',
    'wordpress.wpcli.read':'read',
    'site.binding.inspect':'read',
    'site.runtime.inspect':'read',
    'site.content.inspect':'read',
    'fleet-intake.auto':'write',
    'fleet-intake.plan':'write',
    'fleet-intake.save':'write',
    'content.write':'write',
    'plugin.install':'write',
    'configuration.write':'write',
}
SSG=re.compile(r'^[a-z][a-z0-9-]{2,47}:(?:staging|production)$')
UUID=re.compile(r'^[a-f0-9]{8}-(?:[a-f0-9]{4}-){3}[a-f0-9]{12}$')
WP_REGISTRY=r'''if (untrailingslashit(home_url('/'))!=='https://tours.dev.mad4b.com' ||
  wp_get_environment_type()!=='staging' ||
  !class_exists('MAD4B_Company_Environment_Registry_V2',false))
  WP_CLI::error('CENTRAL_ORIGINAL_COMPANY_REGISTRY_REQUIRED');
$all=MAD4B_Company_Environment_Registry_V2::discover();
$rows=[];
foreach($all as $company=>$meta)foreach((array)($meta['environments']??[]) as $env=>$site){
  if(!is_array($site))continue;
  $rows[]=['company_id'=>$company,'environment'=>$env,
    'site_uuid'=>$site['site_uuid']??null,
    'origin'=>untrailingslashit((string)($site['canonical_origin']??''))];
}
echo wp_json_encode(['contract'=>'mad4b.central-company-environments-route-source.v1',
 'rows'=>$rows],JSON_UNESCAPED_SLASHES);'''
def original_module():
    if ORIGINAL.is_symlink():raise RuntimeError('ORIGINAL_ROUTER_SYMLINK_REJECTED')
    st=ORIGINAL.stat()
    if not stat.S_ISREG(st.st_mode) or st.st_uid!=0 or st.st_mode & 0o022:
        raise RuntimeError('ORIGINAL_ROUTER_NOT_TRUSTED')
    sys.path.insert(0,str(ORIGINAL.parent))
    sp=importlib.util.spec_from_file_location('central_original_route',str(ORIGINAL))
    m=importlib.util.module_from_spec(sp);sp.loader.exec_module(m)
    return m
def call_wp(args,timeout=60):
    c=['/usr/bin/docker','exec','-u','www-data',CENTRAL,
       '/usr/local/bin/wp','--path=/var/www/html','--skip-themes',*args]
    p=subprocess.run(c,capture_output=True,text=True,timeout=timeout)
    if p.returncode:raise RuntimeError('CENTRAL_WP_CLI_READBACK_FAILED_'+p.stderr[-105:].strip())
    return p.stdout.strip()
def registry():
    parsed=json.loads(call_wp(['eval',WP_REGISTRY]))
    if parsed.get('contract')!='mad4b.central-company-environments-route-source.v1':
        raise RuntimeError('CENTRAL_REGISTRY_CONTRACT_INVALID')
    return parsed['rows']
def route_for(row,original,policy):
    company,env,uuid,origin=(row.get(k) for k in ('company_id','environment','site_uuid','origin'))
    if not isinstance(company,str) or not SSG.fullmatch(company+':'+str(env)):
        raise RuntimeError('COMPANY_ENV_INVALID')
    if not isinstance(origin,str) or not re.fullmatch(r'https://[A-Za-z0-9.-]+',origin):
        raise RuntimeError('ORIGIN_INVALID')
    key=company+':'+env
    central=(company=='tours-central' and env=='staging' and origin==ORIGIN)
    exact=bool(isinstance(uuid,str) and UUID.fullmatch(uuid))
    fallback='company_manager_oauth'
    prefix={'company_id':company,'environment':env,'site_uuid':uuid,'canonical_origin':origin,
     'default_authorization_route':fallback,
     'authorization_owner':'company_manager',
     'oauth_required_when':'no_current_valid_scoped_write_grant_or_owner_step_up',
     'transport_selection_is_write_authority':False,
     'original_operation_mutated':False,
     'permissions_granted':False,
     'fallback_policy':'ONLY_BEFORE_DISPATCH_SAME_EXACT_TARGET_NO_REPLAY'}
    if not exact:
        return prefix|{'state':'WAITING_ENROLLED_SITE_IDENTITY','preferred_transport':None,
         'transport_ready':False,'transport_candidates':['vps_client_mcp_cross_ssh','company_manager_connected_api'],
         'reason':'ORIGINAL_CENTRAL_REGISTRY_SITE_UUID_MISSING'}
    target=policy.get('targets',{}).get(key)
    if not isinstance(target,dict) or any(target.get(k)!=v for k,v in
          (('site_uuid',uuid),('canonical_origin',origin),('environment',env))):
        return prefix|{'state':'WAITING_ORIGINAL_HOST_TARGET','preferred_transport':None,
         'transport_ready':False,'transport_candidates':['vps_client_mcp_cross_ssh','company_manager_connected_api'],
         'reason':'ORIGINAL_HOST_SCOPE_NOT_ENROLLED'}
    if not original.registered_target(key,target) or original.authority(key,target)!='VERIFIED':
        return prefix|{'state':'ORIGINAL_HOST_SCOPE_NOT_AUTHORIZED','preferred_transport':None,
         'transport_ready':False,'transport_candidates':['vps_client_mcp_cross_ssh','company_manager_connected_api'],
         'reason':'HOST_OPERATOR_SCOPE_UNVERIFIED'}
    if central:
        # The center itself is local to this VPS; do not SSH back into it.
        return prefix|{'state':'TRANSPORT_READY','preferred_transport':'vps_client_local_wpcli',
          'transport_ready':True,'transport_candidates':['vps_client_local_wpcli','company_manager_connected_api'],
          'original_route_binding':'local_wpcli','reason':None}
    # Original router's live WP-CLI preflight checks the exact enrolled user,
    # pinned SSH fingerprint, WordPress home, uuid and environment.
    result=original.plan(key,policy=policy,operation='wordpress.home.read')
    if result.get('state')=='READY' and result.get('selected_lane')=='cross_ssh_wpcli':
        registered=target['providers']['cross_ssh_wpcli']['registered_target']
        if not VPS_MCP_CLIENT.is_file() or VPS_MCP_CLIENT.is_symlink():
            return prefix|{'state':'TRANSPORT_PREFLIGHT_REQUIRED','preferred_transport':None,
                'transport_ready':False,'transport_candidates':['vps_client_mcp_cross_ssh','company_manager_connected_api'],
                'reason':'ORIGINAL_VPS_CLIENT_NOT_AVAILABLE'}
        status=subprocess.run([sys.executable,str(VPS_MCP_CLIENT),'--target',registered,
              '--identity-mode','host_operator','--run','native-read'],
              capture_output=True,text=True,timeout=72)
        try:proof=json.loads(status.stdout) if status.returncode==0 else {}
        except ValueError:proof={}
        if (proof.get('contract')!='mad4b.native-authorized-site-identity-read.v1'
            or proof.get('state')!='EXACT_NATIVE_SITE_READ_VERIFIED'
            or proof.get('target')!=registered
            or proof.get('company_id')!=company
            or any(proof.get(k)!=v for k,v in (
                ('site_uuid',uuid),('environment',env),('canonical_origin',origin)))
            or proof.get('host_operator_authority_verified') is not True
            or proof.get('write_authorized') is not False
            or proof.get('target_mutated') is not False):
            return prefix|{'state':'TRANSPORT_PREFLIGHT_REQUIRED','preferred_transport':None,
               'transport_ready':False,'transport_candidates':['vps_client_mcp_cross_ssh','company_manager_connected_api'],
               'reason':'ORIGINAL_VPS_CLIENT_IDENTITY_READBACK_FAILED'}
        return prefix|{'state':'TRANSPORT_READY','preferred_transport':'vps_client_mcp_cross_ssh',
          'transport_ready':True,'transport_candidates':['vps_client_mcp_cross_ssh','company_manager_connected_api'],
          'original_route_binding':'cross_ssh_wpcli',
          'vps_client_identity_proof':'EXACT_NATIVE_SITE_READ_VERIFIED',
          'reason':None}
    # Never fall through after an attempted site mutation. This plan is read-only.
    return prefix|{'state':'TRANSPORT_PREFLIGHT_REQUIRED','preferred_transport':None,
      'transport_ready':False,'transport_candidates':['vps_client_mcp_cross_ssh','company_manager_connected_api'],
      'reason':'ORIGINAL_ROUTER_'+str(result.get('reason') or result.get('state'))[:70]}
def compile_projection():
    source=registry()
    r=original_module()
    policy=r.load()
    items={}
    for row in source:
        company=row['company_id'];env=row['environment'];key=company+':'+env
        if key in items:raise RuntimeError('DUPLICATE_COMPANY_ENV')
        items[key]=route_for(row,r,policy)
    now=int(time.time())
    report={'contract':CONTRACT,'version':'1.0.0','generated_epoch':now,
     'expires_epoch':now+1800,
     'generated_utc':datetime.datetime.now(datetime.timezone.utc).isoformat(),
     'origin':ORIGIN,'authorization_default':'company_manager_oauth',
     'preferred_remote_transport':'vps_client_mcp_cross_ssh',
     'preferred_center_transport':'vps_client_local_wpcli',
     'verified_target_count':sum(1 for x in items.values() if x['transport_ready']),
     'target_count':len(items),'sites':items,
     'read_only_projection':True,'write_grants_issued':False,'secrets_exported':False}
    report['projection_sha256']=hashlib.sha256(json.dumps(report,sort_keys=True,separators=(',',':')).encode()).hexdigest()
    return report
def atomic(report):
    OUTPUT.parent.mkdir(parents=True,exist_ok=True)
    fd,tmp=tempfile.mkstemp(prefix='.company-route-',dir=OUTPUT.parent)
    try:
        with os.fdopen(fd,'w') as f:json.dump(report,f,sort_keys=True,indent=2);f.write('\n');f.flush();os.fsync(f.fileno())
        os.chmod(tmp,0o600);os.replace(tmp,OUTPUT)
    finally:
        if os.path.exists(tmp):os.unlink(tmp)
def publish(report):
    # An original host-owned read-only topology projection is not an OAuth
    # grant and must not be considered original WordPress write authorization.
    payload=json.dumps(report,separators=(',',':'))
    import base64
    encoded=base64.b64encode(payload.encode()).decode()
    php=r'''$raw=base64_decode('''+json.dumps(encoded)+r''',true);
$v=json_decode($raw,true,20);
if(!is_array($v)||($v['contract']??'')!=='mad4b.company-central-preferred-router.v1'
 || ($v['origin']??'')!==untrailingslashit(home_url('/'))
 || ($v['read_only_projection']??null)!==true
 || ($v['write_grants_issued']??null)!==false
 || !class_exists('MAD4B_Company_Environment_Registry_V2',false))
 WP_CLI::error('CENTRAL_HOST_ROUTE_PROJECTION_INVALID');
$r=MAD4B_Company_Environment_Registry_V2::discover();
foreach($v['sites'] as $key=>$item){
 $entry=$r[$item['company_id']]['environments'][$item['environment']]??null;
 if(!is_array($entry)||$key!==$item['company_id'].':'.$item['environment']
    ||($entry['site_uuid']??null)!==($item['site_uuid']??null)
    ||untrailingslashit((string)($entry['canonical_origin']??''))!==$item['canonical_origin'])
   WP_CLI::error('COMPANY_SITE_PROJECTION_CHANGED');
}
update_option('mad4b_company_central_preferred_router_v1',$v,false);
$back=get_option('mad4b_company_central_preferred_router_v1',[]);
echo wp_json_encode(['published'=>is_array($back)&&
($back['projection_sha256']??'')===$v['projection_sha256'],
'targets'=>count($back['sites']??[]),'expires_epoch'=>$back['expires_epoch']??null]);'''
    result=json.loads(call_wp(['eval',php],timeout=75))
    if not result.get('published') or result.get('targets')!=report['target_count']:
        raise RuntimeError('CENTRAL_ROUTE_PROJECTION_READBACK_FAILED')
    return result
def choose(record,operation):
    if operation not in VALID_OPS: return {'state':'UNSUPPORTED_OPERATION','execution_authorized':False}
    kind=VALID_OPS[operation]
    if not record['transport_ready']:
        return {'state':record['state'],'preferred_transport':None,
         'authorization_route':record['default_authorization_route'],
         'execution_authorized':False,'reason':record['reason']}
    if kind=='read':
        return {'state':'ROUTE_READY_REQUIRES_ORIGINAL_READ_ADMISSION',
         'preferred_transport':record['preferred_transport'],
         'authorization_route':'company_manager_existing_actor_or_host_operator',
         'execution_authorized':False}
    return {'state':'TRANSPORT_READY_WRITE_AUTHORITY_REQUIRED',
       'preferred_transport':record['preferred_transport'],
       'authorization_route':'company_manager_oauth',
       'next_step':'EXACT_OPERATION_GRANT_OR_REAUTH_THROUGH_COMPANY_MANAGER',
       'execution_authorized':False,'original_write_receipt_required':True}
def selftest():
    a={'transport_ready':True,'preferred_transport':'vps_client_mcp_cross_ssh',
       'default_authorization_route':'company_manager_oauth','state':'TRANSPORT_READY','reason':None}
    assert choose(a,'fleet-intake.save')['state']=='TRANSPORT_READY_WRITE_AUTHORITY_REQUIRED'
    assert choose(a,'fleet-intake.save')['execution_authorized'] is False
    assert choose(a,'site.binding.inspect')['state']=='ROUTE_READY_REQUIRES_ORIGINAL_READ_ADMISSION'
    a.update({'transport_ready':False,'preferred_transport':None,'state':'WAITING_ENROLLED_SITE_IDENTITY','reason':'MISSING'})
    assert choose(a,'fleet-intake.save')['state']=='WAITING_ENROLLED_SITE_IDENTITY'
    assert choose(a,'anything.unknown')['state']=='UNSUPPORTED_OPERATION'
    print(json.dumps({'selftest':'PASS','checks':5,'writes_executed':0}))
def main():
    parser=argparse.ArgumentParser()
    parser.add_argument('action',choices=('selftest','once','plan'))
    parser.add_argument('--company')
    parser.add_argument('--environment',choices=('staging','production'))
    parser.add_argument('--operation',default='fleet-intake.save')
    args=parser.parse_args()
    if args.action=='selftest':selftest();return
    report=compile_projection()
    if args.action=='plan':
        key=str(args.company)+':'+str(args.environment)
        if key not in report['sites']:raise SystemExit('SITE_NOT_IN_CENTRAL_REGISTRY')
        print(json.dumps({'site':key,'transport':report['sites'][key],'plan':choose(report['sites'][key],args.operation)}))
        return
    atomic(report)
    status=publish(report)
    print(json.dumps({'state':'CENTRAL_ROUTE_POLICY_SYNCED','site_count':report['target_count'],
      'preferred_routes_ready':report['verified_target_count'],
      'projection_sha256':report['projection_sha256'],
      'central_option_published':status['published'],'report':str(OUTPUT)}))
if __name__=='__main__':main()
