#!/usr/bin/env python3
"""MAD4B Unified Execution Router v1.

Three first-class adapters; a read-only WordPress siteurl slice is executable.
The route is chosen BEFORE execution. A failed/uncertain attempt never falls back.
Host operator authority is checked independently from SSH authentication.
"""
from __future__ import annotations
import argparse, datetime as dt, hashlib, importlib.util, json, os, re, stat
import subprocess, sys, tempfile
from pathlib import Path

ROOT=Path('/opt/mad4b-company-transport')
HERE=ROOT/'unified-execution-router-v1'
POLICY=HERE/'policy.json'
OPERATOR=ROOT/'native-operator-v1/host-authority.json'
INVENTORY=ROOT/'environment-inventory-v1.json'
JOURNAL=HERE/'journal'
STATUS=HERE/'status.json'
CONTRACT='mad4b.unified-execution-router.v1'
LANES=('local_wpcli','cross_ssh_wpcli','remote_native')
OP='wordpress.siteurl.read'
READ_OPTION_KEYS={OP:'siteurl',
                  'wordpress.home.read':'home',
                  'wordpress.blogname.read':'blogname'}
ID=re.compile(r'^[a-zA-Z0-9][a-zA-Z0-9._-]{5,127}$')

def secure_json(path):
    st=path.lstat()
    if not stat.S_ISREG(st.st_mode) or st.st_uid!=0 or (st.st_mode&0o022):
        raise ValueError('UNTRUSTED_ROOT_OWNED_POLICY')
    return json.loads(path.read_text())

def load():
    policy=secure_json(POLICY)
    if policy.get('contract')!='mad4b.unified-execution-router.policy.v1':
        raise ValueError('ROUTER_POLICY_INVALID')
    if policy.get('operation')!=OP or policy.get('fallback',{}).get('only_before_dispatch') is not True:
        raise ValueError('ROUTER_FALLBACK_CONTRACT_INVALID')
    allow=policy.get('allowed_read_operations',[OP])
    if not isinstance(allow,list) or not allow or len(set(allow))!=len(allow) or any(v not in READ_OPTION_KEYS for v in allow):
        raise ValueError('UNSUPPORTED_WPCLI_OPERATION_IN_POLICY')
    return discover_enrolled_crossssh(policy)

def discover_enrolled_crossssh(policy):
    """Project already approved Original Host Scopes as router targets.

    This adds no grant, trust root or Production mutation; an exact WordPress
    Site UUID, registered target/root, company environment and host policy
    must all exist before a new remote target can enter the route catalog.
    """
    original=secure_json(OPERATOR)
    registered=secure_json(INVENTORY).get('targets',{})
    native=registry_module().TARGETS
    if original.get('contract')!='mad4b.native-vps-operator.v1' or original.get('revoked') is not False:
        raise ValueError('ORIGINAL_HOST_AUTHORITY_UNAVAILABLE')
    for target,host in original.get('scopes',{}).items():
        if target in policy['targets'] or not isinstance(host,dict) or ':' not in target:
            continue
        company,environment=target.rsplit(':',1)
        binding=host.get('registry_binding')
        site=registered.get(target)
        target_config=native.get(binding)
        if (environment not in ('staging','production')
            or host.get('allow_operator') is not True
            or host.get('allow_remote_shell') is not True
            or not isinstance(binding,str) or binding.split(':')[-1]!=environment
            or not isinstance(site,dict) or not isinstance(target_config,dict)):
            continue
        uuid=host.get('site_uuid')
        origin=host.get('canonical_origin')
        expected=host.get('expected_root')
        if (not isinstance(uuid,str) or len(uuid)!=36
            or not isinstance(origin,str) or not origin.startswith('https://')
            or not isinstance(expected,str) or not expected.startswith('/home/')
            or any(site.get(x)!=value for x,value in (
               ('site_uuid',uuid),('canonical_origin',origin),('environment',environment)))
            or target_config.get('site_uuid')!=uuid
            or target_config.get('root')!=expected):
            continue
        policy['targets'][target]={
          'site_uuid':uuid,'canonical_origin':origin,'environment':environment,
          'providers':{'cross_ssh_wpcli':{
             'registered_target':binding,'expected_root':expected}},
          'preferences':['cross_ssh_wpcli','remote_native']}
    return policy

def operation_allowed(policy, operation):
    allowed=policy.get('allowed_read_operations',[OP])
    return isinstance(operation,str) and operation in READ_OPTION_KEYS and operation in allowed

def authority(target,scope):
    """Root's original delegated host policy, not caller-provided identity."""
    try:
        source=secure_json(OPERATOR)
        assigned=source.get('scopes',{}).get(target)
        if source.get('contract')!='mad4b.native-vps-operator.v1' or source.get('revoked') is not False:
            return 'AUTHORITY_DENIED'
        if source.get('host_id')!='srv2030908' or source.get('allow_general_shell') is not True:
            return 'AUTHORITY_DENIED'
        if not isinstance(assigned,dict) or assigned.get('allow_operator') is not True:
            return 'AUTHORITY_DENIED'
        if assigned.get('site_uuid')!=scope.get('site_uuid'):
            return 'SCOPE_MISMATCH'
        if assigned.get('canonical_origin')!=scope.get('canonical_origin'):
            return 'SCOPE_MISMATCH'
        if assigned.get('site_environment',scope.get('environment'))!=scope.get('environment'):
            return 'SCOPE_MISMATCH'
        return 'VERIFIED'
    except (OSError,ValueError,KeyError,TypeError):
        return 'AUTHORITY_DENIED'

def registered_target(target,scope):
    """Never route a request to a different company/site/environment."""
    if target=='tours-central:staging':
        return (scope.get('environment')=='staging'
                and scope.get('canonical_origin')=='https://tours.dev.mad4b.com')
    try:
        row=secure_json(INVENTORY)['targets'][target]
        return all(row.get(k)==scope.get(k) for k in
                   ('canonical_origin','environment','site_uuid'))
    except (OSError,KeyError,ValueError,TypeError):
        return False

def command(argv,timeout=20):
    return subprocess.run(argv,capture_output=True,text=True,check=False,timeout=timeout)

def local_preflight(provider):
    container=provider.get('container')
    if container not in ('etg-commerce-staging-php',):
        return 'SCOPE_MISMATCH'
    if provider.get('wp_path')!='/var/www/html':
        return 'SCOPE_MISMATCH'
    try:
        p=command(['/usr/bin/docker','inspect','-f','{{.State.Running}}',container])
        return 'READY' if p.returncode==0 and p.stdout.strip()=='true' else 'PROVIDER_UNAVAILABLE'
    except (OSError,subprocess.TimeoutExpired):
        return 'PROVIDER_UNAVAILABLE'

def registry_module():
    path=ROOT/'registry-remote-v1/registry_remote.py'
    spec=importlib.util.spec_from_file_location('mad4b_router_registry_remote',path)
    module=importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module

def cross_preflight(target,scope,provider):
    transport=provider.get('registered_target')
    # Original per-site Host Scope and pinned registry are authoritative.
    # Do not make any one customer/hosting account a universal prerequisite.
    if (not isinstance(transport,str) or not isinstance(target,str)
        or ':' not in target or transport.split(':')[-1]!=target.rsplit(':',1)[-1]):
        return 'SCOPE_MISMATCH'
    expected=provider.get('expected_root')
    try:
        original=secure_json(OPERATOR)['scopes'][target]
        if original.get('allow_remote_shell') is not True:
            return 'AUTHORITY_DENIED'
        if original.get('expected_root')!=expected or original.get('registry_binding')!=transport:
            return 'SCOPE_MISMATCH'
        m=registry_module()
        enrolled=m.TARGETS.get(transport)
        if (not isinstance(enrolled,dict)
            or enrolled.get('root')!=expected
            or enrolled.get('site_uuid')!=scope.get('site_uuid')):
            return 'SCOPE_MISMATCH'
        if m.guard(transport)!=expected:
            return 'SCOPE_MISMATCH'
        # Verify the actual target WordPress instance on the pinned SSH host.
        # This replaces a redundant static binding map for READ operations.
        # A successful generic SSH login or `command -v wp` is insufficient.
        import shlex
        found=m.remote(transport,'command -v wp')
        if found!='/usr/local/bin/wp':
            return 'PROVIDER_UNAVAILABLE'
        home=m.remote(transport,shlex.join(['/usr/local/bin/wp',
            '--path='+expected,'--skip-plugins','--skip-themes',
            'option','get','home']))
        if home.strip()!=scope['canonical_origin']:
            return 'SCOPE_MISMATCH'
        php=('echo wp_json_encode(["site_uuid" => '
             '(class_exists("MAD4B_SCP_Site_Profile") ? '
             'MAD4B_SCP_Site_Profile::site_uuid() : null), '
             '"environment"=>wp_get_environment_type()]);')
        response=m.remote(transport,shlex.join(['/usr/local/bin/wp',
            '--path='+expected,'--skip-themes','eval',php]))
        identity=json.loads(response)
        if (identity.get('site_uuid')!=scope['site_uuid']
            or identity.get('environment')!=scope['environment']):
            return 'SCOPE_MISMATCH'
        return 'READY'
    except ValueError as exc:
        return 'SCOPE_MISMATCH' if 'mismatch' in str(exc) else 'PROVIDER_UNAVAILABLE'
    except (OSError,RuntimeError,subprocess.TimeoutExpired,KeyError,TypeError):
        return 'PROVIDER_UNAVAILABLE'

def native_preflight(target,scope,provider):
    # A registered broker or SSH session alone does not authorize a new job.
    # Native runtime currently exposes only status/selftest and no execute.
    try:
        p=command(['/usr/local/bin/node','/opt/mad4b-company-provisioning/governed-execution/native-ssh-v1/runtime/native-cli.mjs','status'])
        if p.returncode==0:
            data=json.loads(p.stdout)
            if data.get('contract')=='mad4b.native-managed-ssh-status.v1':
                return 'PROVIDER_NOT_ENROLLED'
    except (OSError,ValueError,subprocess.TimeoutExpired):
        pass
    return 'PROVIDER_NOT_ENROLLED'

def probe(lane,target,scope):
    provider=scope.get('providers',{}).get(lane)
    if not isinstance(provider,dict):
        return 'PROVIDER_NOT_ENROLLED'
    if lane=='local_wpcli':return local_preflight(provider)
    if lane=='cross_ssh_wpcli':return cross_preflight(target,scope,provider)
    if lane=='remote_native':return native_preflight(target,scope,provider)
    return 'CAPABILITY_NOT_SUPPORTED'

def choose(preferences,states):
    """Fallback only while NOT_STARTED and for identical target/operation."""
    acceptable={'PROVIDER_UNAVAILABLE','PROVIDER_NOT_ENROLLED','CAPABILITY_NOT_SUPPORTED'}
    observations=[]
    for lane in preferences:
        state=states(lane) if callable(states) else states.get(lane,'PROVIDER_NOT_ENROLLED')
        observations.append({'lane':lane,'status':state})
        if state=='READY':
            return {'state':'READY','selected_lane':lane,'routes':observations,
                    'effect_state':'NOT_STARTED'}
        if state not in acceptable:
            return {'state':'DENIED','reason':state,'selected_lane':None,
                    'routes':observations,'effect_state':'NOT_STARTED'}
    return {'state':'NO_ELIGIBLE_ROUTE','selected_lane':None,
            'routes':observations,'effect_state':'NOT_STARTED'}

def plan(target,policy=None,probe_fn=probe,operation=OP):
    policy=policy or load()
    if not operation_allowed(policy,operation):
        return {'contract':CONTRACT,'state':'DENIED','reason':'OPERATION_NOT_ALLOWLISTED'}
    scope=policy['targets'].get(target)
    if not isinstance(scope,dict):
        return {'contract':CONTRACT,'state':'DENIED','reason':'TARGET_NOT_ENROLLED'}
    trusted=registered_target(target,scope)
    if not trusted:
        return {'contract':CONTRACT,'state':'DENIED','reason':'SCOPE_MISMATCH'}
    gate=authority(target,scope)
    if gate!='VERIFIED':
        return {'contract':CONTRACT,'state':'DENIED','reason':gate}
    preferences=scope.get('preferences',[])
    if not isinstance(preferences,list) or not preferences or len(set(preferences))!=len(preferences) or any(l not in LANES for l in preferences):
        return {'contract':CONTRACT,'state':'DENIED','reason':'INVALID_ROUTE_ORDER'}
    # Lazy provider preflights: no probing of unselected subsequent routes.
    decision=choose(preferences,lambda lane:probe_fn(lane,target,scope))
    if decision.get('state')=='READY' and policy.get('dynamic_binding',{}).get('required_for_read_router'):
        # Cross-SSH preflight has already independently verified exact
        # WordPress origin, Site UUID and environment against Original Core.
        # The legacy secondary binding catalog must not re-block read-only
        # operations once this stronger live identity proof succeeds.
        if decision['selected_lane']!='cross_ssh_wpcli':
            import dynamic_binding_guard
            binding=dynamic_binding_guard.check(target,scope,decision['selected_lane'])
            if binding!='VERIFIED':
                decision={'state':'WAITING_AUTHORIZED_BINDING','reason':binding,
                          'execution_authorized':False}
        else:
            decision['binding_evidence']='ORIGINAL_SCOPE_PINNED_CROSSSSH_LIVE_WORDPRESS'
    return {'contract':CONTRACT,'target':target,'site_uuid':scope['site_uuid'],
            'canonical_origin':scope['canonical_origin'],'environment':scope['environment'],
            'operation':operation,'production_mutation':False,'authority_source':'native_host_operator_scope',
            'fallback_policy':'PRE_DISPATCH_ONLY_SAME_SCOPE_NO_REPLAY',**decision}

def read_option(lane,scope,operation):
    # No caller-controlled WP-CLI arguments, plugin execution, or arbitrary shell.
    name=READ_OPTION_KEYS.get(operation)
    if name is None:
        raise RuntimeError('OPERATION_NOT_ALLOWLISTED')
    provider=scope['providers'][lane]
    if lane=='local_wpcli':
        args=['/usr/bin/docker','exec','-u','www-data',provider['container'],
              '/usr/local/bin/wp','--path=/var/www/html','--skip-plugins','--skip-themes',
              'option','get',name]
        result=command(args,35)
        if result.returncode!=0: raise RuntimeError('LOCAL_WPCLI_FAILED')
        return result.stdout.strip()
    if lane=='cross_ssh_wpcli':
        import shlex
        m=registry_module()
        root=provider['expected_root']
        if m.TARGETS[provider['registered_target']]['root']!=root:
            raise RuntimeError('REMOTE_ROOT_DRIFT')
        fixed='/usr/local/bin/wp --path='+shlex.quote(root)+ ' --skip-plugins --skip-themes option get '+name
        return m.remote(provider['registered_target'],fixed).strip()
    raise RuntimeError('NATIVE_EXECUTOR_NOT_ENROLLED')

def read_siteurl(lane,scope):
    return read_option(lane,scope,OP)

def atomic_record(path,data):
    fd,tmp=tempfile.mkstemp(prefix='.router-',dir=JOURNAL)
    try:
        with os.fdopen(fd,'w') as f:
            json.dump(data,f,sort_keys=True,indent=2)
            f.write('\n');f.flush();os.fsync(f.fileno())
        os.chmod(tmp,0o600)
        os.replace(tmp,path)
    finally:
        if os.path.exists(tmp):os.unlink(tmp)

def run(target,opid,policy=None,preflight=probe,reader=None,operation=OP):
    if not ID.fullmatch(opid):
        return {'contract':CONTRACT,'state':'DENIED','reason':'OPERATION_ID_INVALID'}
    cfg=policy or load()
    decision=plan(target,cfg,preflight,operation)
    if decision.get('state')!='READY':
        return decision
    lane=decision['selected_lane']
    scope=cfg['targets'][target]
    JOURNAL.mkdir(mode=0o700,parents=True,exist_ok=True)
    receipt=JOURNAL/(opid+'.json')
    try:
        fd=os.open(receipt,os.O_CREAT|os.O_EXCL|os.O_NOFOLLOW|os.O_WRONLY,0o600)
    except FileExistsError:
        return {'contract':CONTRACT,'state':'DENIED','reason':'OPERATION_ALREADY_ATTEMPTED_NO_REPLAY'}
    evidence={'contract':CONTRACT,'operation_id':opid,'target':target,
             'site_uuid':scope['site_uuid'],'environment':scope['environment'],
             'canonical_origin':scope['canonical_origin'],'operation':operation,
             'selected_lane':lane,'state':'ATTEMPTED',
             'at':dt.datetime.now(dt.timezone.utc).isoformat(),
             'production_mutation':False,'fallback_after_dispatch':False}
    with os.fdopen(fd,'w') as f:
        json.dump(evidence,f,sort_keys=True);f.write('\n');f.flush();os.fsync(f.fileno())
    # After the attempt is journaled, NEVER try another transport on failure.
    if reader is None:
        reader=lambda lane,scope:read_option(lane,scope,operation)
    try:
        result=reader(lane,scope)
        back=reader(lane,scope)
        if not isinstance(result,str) or not result or len(result)>512 or back!=result or (
                operation==OP and result!=scope['canonical_origin']):
            raise RuntimeError('INDEPENDENT_READBACK_MISMATCH')
        evidence.update({'state':'VERIFIED','result_sha256':hashlib.sha256(result.encode()).hexdigest(),
                         'readback_verified':True})
    except (OSError,RuntimeError,ValueError,KeyError,subprocess.TimeoutExpired):
        evidence.update({'state':'RECONCILIATION_REQUIRED','readback_verified':False})
    atomic_record(receipt,evidence)
    return {'contract':CONTRACT,'target':target,'operation_id':opid,
            'selected_lane':lane,'state':evidence['state'],'execution_attempted':True,
            'readback_verified':evidence['readback_verified'],
            'replay_allowed':False,'receipt_path':str(receipt),
            'production_mutation':False}

def native_registration_status():
    # This is status only. Root session via the connector is not a central job grant.
    path=Path('/opt/mad4b-company-provisioning/governed-execution/native-ssh-v1/registration.json')
    try:
        source=secure_json(path)
        if source.get('contract')!='mad4b.native-managed-ssh-installation.v1':
            return {'state':'REGISTRATION_UNTRUSTED','dispatch_enabled':False}
        supported=source.get('interfaces',[])
        registered=(source.get('registration_state')=='ENROLLED'
            and source.get('central_dispatcher_binding_verified') is True
            and source.get('ssh_credential_enrolled') is True
            and source.get('host_registry_enrolled') is True
            and source.get('trusted_grant_verifier_bound') is True
            and isinstance(supported,list) and 'execute' in supported)
        return {'state':'ENROLLED' if registered else 'INSTALLED_UNENROLLED',
                'dispatch_enabled':registered,
                'registration_state':source.get('registration_state'),
                'has_execute_interface':isinstance(supported,list) and 'execute' in supported}
    except (OSError,ValueError,TypeError):
        return {'state':'REGISTRATION_UNAVAILABLE','dispatch_enabled':False}

def snapshot():
    cfg=load()
    rows={target:plan(target,cfg) for target in cfg['targets']}
    output={'contract':CONTRACT,'checked_at':dt.datetime.now(dt.timezone.utc).isoformat(),
            'routes':rows,'native_lanes':list(LANES),
            'allowed_read_operations':cfg.get('allowed_read_operations',[OP]),
            'remote_native_registration':native_registration_status(),
            'production_mutation':False,'queued_jobs_executed':0}
    fd,tmp=tempfile.mkstemp(prefix='.status-',dir=HERE)
    try:
        with os.fdopen(fd,'w') as f:
            json.dump(output,f,sort_keys=True,indent=2);f.write('\n');f.flush();os.fsync(f.fileno())
        os.chmod(tmp,0o600);os.replace(tmp,STATUS)
    finally:
        if os.path.exists(tmp):os.unlink(tmp)
    return output

def cli():
    parser=argparse.ArgumentParser()
    parser.add_argument('action',choices=('status','plan','run','reconcile','general-prepare','general-plan','general-run'))
    parser.add_argument('--target',default='')
    parser.add_argument('--operation-id',default='')
    parser.add_argument('--operation',default=OP)
    parser.add_argument('--request-file',default='')
    opts=parser.parse_args()
    if os.geteuid()!=0:raise SystemExit('ROOT_OWNED_ROUTER_REQUIRED')
    try:
        if opts.action in ('general-prepare','general-plan','general-run'):
            import general_wpcli
            out=(general_wpcli.prepare(opts.request_file) if opts.action=='general-prepare' else general_wpcli.operation(opts.action.split('-',1)[1],opts.request_file))
        elif opts.action=='reconcile':out=snapshot()
        elif opts.action=='status':
            out={'contract':CONTRACT,'lanes':list(LANES),'fallback':'PRE_DISPATCH_ONLY',
                 'production_mutation':False,'policy_version':1,
                 'general_wpcli':{'source':'central_signed_grant_305_316',
                   'supported_lanes':['local_wpcli','cross_ssh_wpcli'],
                   'original_grant_required':True,
                   'allow_any_originally_authorized_wpcli_subcommand':True,
                   'execution_without_grant':False},
                 'allowed_read_operations':load().get('allowed_read_operations',[OP]),
                 'remote_native_registration':native_registration_status()}
        elif opts.action=='plan':out=plan(opts.target,operation=opts.operation)
        else:out=run(opts.target,opts.operation_id,operation=opts.operation)
        print(json.dumps(out,sort_keys=True))
        if out.get('state') in ('DENIED','RECONCILIATION_REQUIRED'):raise SystemExit(2)
    except (OSError,ValueError,TypeError,KeyError) as exc:
        print(json.dumps({'contract':CONTRACT,'state':'DENIED','reason':str(exc)[:120]}))
        raise SystemExit(2)
if __name__=='__main__':cli()
