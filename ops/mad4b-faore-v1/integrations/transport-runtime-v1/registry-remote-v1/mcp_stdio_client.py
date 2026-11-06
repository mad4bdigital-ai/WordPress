#!/usr/bin/env python3
"""MCP STDIO over existing enrolled Docker/SSH WP-CLI routes.

WP user context is optional and never inferred from SSH, host operator,
WordPress user ID 1 or the target registration. This is a transport, not
a new job authority: existing WP/MCP permissions and original Broker rules
remain responsible for mutation authorization and independent receipts.
"""
from functools import lru_cache
import argparse
import importlib.util
import json
import os
from pathlib import Path
import re
import selectors
import shlex
import subprocess
import sys
import time

ROOT=Path('/opt/mad4b-company-transport')
sys.path.insert(0,str(ROOT))
sys.path.insert(0,str(ROOT/'registry-remote-v1'))
sys.path.insert(0,str(ROOT/'dynamic-site-binding-v1'))
import registry_remote
import resolver
import wpcli_identity
from central_wpcli_runtime import central_wp

MODES=('auto','host_operator','wordpress_user')
WP_USER=re.compile(r'^[A-Za-z0-9_.@+\-]{1,100}$')
SERVER_ID=re.compile(r'^[a-z][a-z0-9._-]{2,60}$')
CENTRAL='tours-central:staging'

def wp_args(identity, wp_user):
    if identity not in MODES:
        raise ValueError('INVALID_IDENTITY_MODE')
    if identity=='wordpress_user':
        if not isinstance(wp_user,str) or not WP_USER.fullmatch(wp_user):
            raise ValueError('EXPLICIT_WORDPRESS_USER_REQUIRED')
        return ['--user='+wp_user]
    if wp_user is not None:
        raise ValueError('WORDPRESS_USER_REQUIRES_EXPLICIT_MODE')
    return []

def bound_route(route, *, verify=True):
    if route==CENTRAL:
        if verify:
            site=subprocess.run(central_wp()+['option','get','siteurl'],
                                capture_output=True,text=True,timeout=30,check=True)
            if site.stdout.strip()!='https://tours.dev.mad4b.com':
                raise ValueError('CENTRAL_SITE_ORIGIN_MISMATCH')
        return central_wp(),None,None
    if route not in registry_remote.TARGETS:
        raise ValueError('UNENROLLED_ROUTE')
    users=resolver.registry()
    rows=[b for b in users['site_bindings'] if b.get('provider')=='registry_ssh_wpcli'
          and b.get('provider_locator')==route and b.get('registration_status')=='enrolled']
    if len(rows)!=1:
        raise ValueError('UNIQUE_ENROLLED_SITE_BINDING_REQUIRED')
    binding=rows[0]
    target=registry_remote.TARGETS[route]
    if binding['site_uuid']!=target['site_uuid'] or binding['wp_root']!=target['root']:
        raise ValueError('REMOTE_SITE_BINDING_MISMATCH')
    registry_remote.guard(route)
    if verify:
        read=wpcli_identity.single_read(binding,users['host_registry'])
        if (read['site_uuid']!=binding['site_uuid'] or
            read['siteurl']!=binding['canonical_origin']):
            raise ValueError('WORDPRESS_SITE_IDENTITY_MISMATCH')
    prefix,host,_,_=registry_remote.ssh_base(route)
    return ['/usr/local/bin/wp','--path='+target['root'],'--skip-themes'],prefix,host

@lru_cache(maxsize=1)
def exact_enrolled_central_target():
    # The host-owned enrollment is stable only for this short-lived CLI run.
    # Reuse a single authenticated identity readback, never cached globally.
    from wpcli_dynamic_actor import enrolled_target
    return enrolled_target()


def original_host_authority(route):
    # Reuse the ORIGINAL Host Operator policy; this does not issue a grant.
    path=ROOT/'unified-execution-router-v1/router.py'
    spec=importlib.util.spec_from_file_location('mad4b_original_router_auth',path)
    module=importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    if route==CENTRAL:
        scope=exact_enrolled_central_target()
        identifier=CENTRAL
        fields={'site_uuid':scope['site_uuid'],
                'canonical_origin':'https://tours.dev.mad4b.com',
                'environment':'staging'}
    else:
        registry=resolver.registry()
        rows=[b for b in registry['site_bindings']
              if b.get('provider')=='registry_ssh_wpcli'
              and b.get('provider_locator')==route
              and b.get('registration_status')=='enrolled']
        if len(rows)!=1:
            raise ValueError('EXACT_ENROLLED_AUTHORITY_SCOPE_REQUIRED')
        entry=rows[0]
        identifier=entry['company_id']+':'+entry['environment']
        fields={'site_uuid':entry['site_uuid'],
                'canonical_origin':entry['canonical_origin'],
                'environment':entry['environment']}
    if module.authority(identifier,fields)!='VERIFIED':
        raise ValueError('ORIGINAL_HOST_OPERATOR_AUTHORITY_REQUIRED')
    return True

def exact_central_native_read_command(base):
    # Root host scope is validated by original_host_authority() before this
    # function. Resolve container/site identity only from the enrolled target.
    facts=exact_enrolled_central_target()
    if (base[:4] != ['/usr/bin/docker','exec','-u','www-data']
        or base[5:7] != ['/usr/local/bin/wp','--path=/var/www/html']
        or base[4] != facts.get('container')
        or facts.get('environment')!='staging'
        or facts.get('canonical_origin')!='https://tours.dev.mad4b.com'):
        raise ValueError('EXACT_CENTRAL_NATIVE_RUNTIME_REQUIRED')
    p=subprocess.run(base+['eval','echo DB_NAME;'],capture_output=True,
                     text=True,timeout=30,check=True)
    db=p.stdout.strip()
    if not re.fullmatch(r'[A-Za-z0-9_]{3,64}',db):
        raise ValueError('ENROLLED_CENTRAL_DATABASE_READBACK_INVALID')
    return ['/usr/bin/docker','exec','-i','-u','root',
            '-e','MAD4B_CLI_TARGET_SITE_UUID='+facts['site_uuid'],
            '-e','MAD4B_CLI_TARGET_ORIGIN='+facts['canonical_origin'],
            '-e','MAD4B_CLI_TARGET_DATABASE='+db,
            # This interactive MCP session is read-only. Exact signed Original
            # Broker jobs use their independent authorized execution path.
            '-e','MAD4B_CLI_ALLOWED_WRITE_ENVIRONMENTS=none',
            facts['container'],'/usr/local/bin/wp','--allow-root',
            '--path=/var/www/html','--skip-themes']


def exact_etg_host_read_env(base):
    # Original Host Operator authorization and signed site binding have
    # already passed. Forward only the existing identity facts to WP-CLI;
    # none of these environment values authorizes a write.
    route='hosting-3:staging'
    target=registry_remote.TARGETS[route]
    scope=resolver.registry()
    rows=[b for b in scope['site_bindings']
          if b.get('provider')=='registry_ssh_wpcli'
          and b.get('provider_locator')==route
          and b.get('registration_status')=='enrolled'
          and b.get('site_uuid')==target['site_uuid']
          and b.get('wp_root')==target['root']
          and b.get('canonical_origin')=='https://staging.egypttourgates.com']
    if len(rows)!=1:
        raise ValueError('ETG_EXACT_ENROLLED_STAGING_SCOPE_REQUIRED')
    statement='wp --path='+shlex.quote(target['root'])+(
        ' --skip-plugins --skip-themes eval '+shlex.quote('echo DB_NAME;')+
        ' 2>/dev/null')
    database=registry_remote.remote(route,statement).strip()
    if not re.fullmatch(r'[A-Za-z0-9_]{3,64}',database):
        raise ValueError('ETG_WPCLI_DATABASE_READBACK_MISMATCH')
    return ['env',
        'MAD4B_CLI_TARGET_SITE_UUID='+rows[0]['site_uuid'],
        'MAD4B_CLI_TARGET_ORIGIN='+rows[0]['canonical_origin'],
        'MAD4B_CLI_TARGET_DATABASE='+database,
        'MAD4B_CLI_ALLOWED_WRITE_ENVIRONMENTS=none']+base


def _command_for_once(route,identity,wp_user,server=None):
    if identity in ('host_operator','wordpress_user'):
        original_host_authority(route)
    base,prefix,host=bound_route(route)
    chosen=server or ('mad4b-content' if route==CENTRAL else None)
    if route==CENTRAL and chosen=='mad4b-vps-local':
        if identity!='host_operator' or wp_user is not None:
            raise ValueError('EXACT_NATIVE_OPERATOR_WITHOUT_WP_USER_REQUIRED')
        base=exact_central_native_read_command(base)
    elif prefix is None:
        # MCP JSON-RPC needs an attached stdin; ordinary WP-CLI reads do not.
        if base[:2]!=['/usr/bin/docker','exec']:
            raise ValueError('CENTRAL_DOCKER_EXEC_CONTRACT_CHANGED')
        base=base[:2]+['-i']+base[2:]
    if chosen is not None and not SERVER_ID.fullmatch(chosen):
        raise ValueError('INVALID_MCP_SERVER_ID')
    if route=='hosting-3:staging' and identity=='host_operator' and wp_user is None:
        if prefix is None:
            raise ValueError('ETG_NATIVE_READ_REQUIRES_PINNED_CROSS_SSH')
        base=exact_etg_host_read_env(base)
    invocation=base+['mcp-adapter','serve']
    if chosen is not None:invocation.append('--server='+chosen)
    invocation+=wp_args(identity,wp_user)
    if prefix is not None:
        return prefix+[host,shlex.join(invocation)]
    return invocation

def command_for(route,identity,wp_user,server=None):
    if route!=CENTRAL:
        return _command_for_once(route,identity,wp_user,server)
    for attempt in range(3):
        try:
            return _command_for_once(route,identity,wp_user,server)
        except RuntimeError as exc:
            if str(exc) not in ('CENTRAL_WORDPRESS_BUSY','STAGING_WPCLI_BUSY') or attempt==2:
                raise
            time.sleep(2*(attempt+1))


def _send_rpc(process, message):
    process.stdin.write((json.dumps(message,separators=(',',':'))+'\n').encode())
    process.stdin.flush()

def _read_rpc(process, selector, buffers, request_id, deadline):
    while time.monotonic()<deadline:
        for key,_ in selector.select(timeout=min(1,max(.05,deadline-time.monotonic()))):
            stream=key.fileobj
            try:
                data=os.read(stream.fileno(),65536)
            except BlockingIOError:
                continue
            if not data:
                selector.unregister(stream)
                if stream is process.stdout:
                    raise RuntimeError('MCP_STDOUT_CLOSED_BEFORE_REPLY')
                continue
            if stream is process.stderr:
                # Drain stderr so notices cannot block the STDIO channel.
                # Never expose stderr, which may contain sensitive data.
                continue
            buffers['stdout']+=data
            if len(buffers['stdout'])>3_000_000:
                raise ValueError('MCP_RESPONSE_TOO_LARGE')
            while b'\n' in buffers['stdout']:
                line,buffers['stdout']=buffers['stdout'].split(b'\n',1)
                try: message=json.loads(line)
                except (ValueError,TypeError): continue
                if not isinstance(message,dict): continue
                if message.get('id')==request_id: return message
    raise TimeoutError('MCP_RPC_RESPONSE_TIMEOUT')

def _rpc_ok(reply):
    return isinstance(reply,dict) and isinstance(reply.get('result'),dict) and not reply.get('error')

def inspect(route,identity,wp_user,read_tool=False,seconds=35,server=None):
    command=command_for(route,identity,wp_user,server)
    process=subprocess.Popen(command,stdin=subprocess.PIPE,stdout=subprocess.PIPE,
                             stderr=subprocess.PIPE,bufsize=0)
    selector=selectors.DefaultSelector()
    init, listing, reply=None,None,None
    read_name=('mad4b-hub-universal-vps-status' if route==CENTRAL else 'mad4b-site-info')
    deadline=time.monotonic()+seconds
    buffers={'stdout':b''}
    try:
        for stream in (process.stdout,process.stderr):
            os.set_blocking(stream.fileno(),False)
            selector.register(stream,selectors.EVENT_READ)
        _send_rpc(process,{'jsonrpc':'2.0','id':1,'method':'initialize','params':{
            'protocolVersion':'2025-11-25','capabilities':{},
            'clientInfo':{'name':'mad4b-unified-vps-mcp-client','version':'1.0'}}})
        init=_read_rpc(process,selector,buffers,1,deadline)
        if _rpc_ok(init):
            _send_rpc(process,{'jsonrpc':'2.0','method':'notifications/initialized','params':{}})
            _send_rpc(process,{'jsonrpc':'2.0','id':2,'method':'tools/list','params':{}})
            listing=_read_rpc(process,selector,buffers,2,deadline)
            items=listing.get('result',{}).get('tools',[]) if _rpc_ok(listing) else []
            if read_tool and isinstance(items,list) and any(
                isinstance(tool,dict) and tool.get('name')==read_name for tool in items):
                _send_rpc(process,{'jsonrpc':'2.0','id':3,'method':'tools/call',
                                   'params':{'name':read_name,'arguments':{}}})
                reply=_read_rpc(process,selector,buffers,3,deadline)
    finally:
        selector.close()
        process.terminate()
        try: process.communicate(timeout=3)
        except subprocess.TimeoutExpired:
            process.kill()
            process.communicate()
    initialized=_rpc_ok(init)
    tools_valid=(_rpc_ok(listing) and isinstance(listing['result'].get('tools'),list))
    tools=listing['result']['tools'] if tools_valid else []
    state=('MCP_INITIALIZATION_FAILED' if not initialized else
           'MCP_TOOLS_LIST_FAILED' if not tools_valid else
           'MCP_CONNECTED_WITH_TOOLS' if tools else 'MCP_CONNECTED_NO_VISIBLE_TOOLS')
    result={'contract':'mad4b.vps-mcp-optional-user.v1',
            'target':route,'transport':'local_docker' if route==CENTRAL else 'pinned_ssh',
            'identity_mode':identity,'wp_user_supplied':wp_user is not None,
            'wordpress_user_required_by_transport':False,
            'initialize_pass':initialized,'tools_list_pass':tools_valid,
            'tools_count':len(tools),'tools_exposed':bool(tools),'state':state,
            'operation_executed':'mcp_inspect_readonly',
            'write_authorized_by_transport':False,'write_attempted':False}
    if read_tool:
        tool_response=reply.get('result') if _rpc_ok(reply) else None
        result['read_tool_name']=read_name
        result['read_tool_pass']=isinstance(tool_response,dict) and tool_response.get('isError') is not True
        content=tool_response.get('content',[]) if isinstance(tool_response,dict) else []
        reason=str(content[0].get('text','')) if content and isinstance(content[0],dict) else ''
        result['read_tool_error']=(None if result['read_tool_pass'] else
            'WORDPRESS_PERMISSION_DENIED' if 'permission denied' in reason.lower()
            else 'MCP_TOOL_UNAVAILABLE_OR_FAILED')
    return result

def native_site_read(route, identity, wp_user):
    """Explicit Original Host-Operator site identity READ, not an MCP Ability.

    Private WordPress Abilities retain their own WordPress/NHI permissions.
    Do not relabel their 403 response as a successful native fallback.
    """
    if identity not in ('auto', 'host_operator') or wp_user is not None:
        raise ValueError('NATIVE_READ_REQUIRES_NONIMPERSONATING_OPERATOR_MODE')
    original_host_authority(route)
    registry=resolver.registry()
    if route==CENTRAL:
        matches=[b for b in registry['site_bindings']
                 if b.get('company_id')=='tours-central'
                 and b.get('site_id')=='control-plane'
                 and b.get('environment')=='staging'
                 and b.get('provider_locator')=='php-wkgtkzta35wkkgouwt6ruljc'
                 and b.get('provider')=='docker_wpcli'
                 and b.get('registration_status')=='enrolled']
        valid_state='WPCLI_IDENTITY_AND_ENROLLED_HOST_PROFILE_VERIFIED'
        sources=None
    else:
        if route not in registry_remote.TARGETS:
            raise ValueError('NATIVE_READ_TARGET_NOT_ENROLLED')
        matches=[b for b in registry['site_bindings']
                 if b.get('provider')=='registry_ssh_wpcli'
                 and b.get('provider_locator')==route
                 and b.get('registration_status')=='enrolled'
                 and b.get('wp_root')==registry_remote.TARGETS[route]['root']
                 and b.get('site_uuid')==registry_remote.TARGETS[route]['site_uuid']]
        valid_state='WPCLI_IDENTITY_AND_SIGNED_CENTRAL_PROOF_VERIFIED'
        sources=resolver.central_sites()
    if len(matches)!=1:
        raise ValueError('EXACT_UNIQUE_ENROLLED_READ_SCOPE_REQUIRED')
    target=matches[0]
    result=wpcli_identity.inspect(target,registry['host_registry'],source=sources)
    if (result.get('state')!=valid_state
        or result.get('readback_verified') is not True
        or result.get('siteurl_observed')!=target.get('canonical_origin')
        or result.get('site_uuid_observed')!=target.get('site_uuid')
        or result.get('execution_authorized') is not False
        or result.get('write_authorized') is not False):
        raise ValueError('NATIVE_SITE_READ_IDENTITY_PROOF_NOT_VERIFIED')
    return {'contract':'mad4b.native-authorized-site-identity-read.v1',
            'state':'EXACT_NATIVE_SITE_READ_VERIFIED',
            'target':route,'company_id':target['company_id'],
            'site_uuid':target['site_uuid'],
            'environment':target['environment'],
            'canonical_origin':target['canonical_origin'],
            'source_readback_state':result['state'],
            'host_operator_authority_verified':True,
            'wordpress_user_required':False,
            'mcp_private_ability_unlocked':False,
            'grant_issued':False,'job_submitted':False,
            'write_authorized':False,'target_mutated':False}


def resilient_inspect(route, identity, wp_user, server=None):
    """Explicit failover: MCP tool denial never becomes an ability grant.

    A pinned Host Operator falls back to existing read-only native WP-CLI and,
    if the signed identity is unavailable, the plugin-independent core Doctor.
    This is NOT a generic tool executor or a source of WordPress permissions.
    """
    if identity != 'host_operator' or wp_user is not None:
        raise ValueError('ENROLLED_HOST_OPERATOR_WITHOUT_WORDPRESS_USER_REQUIRED')
    if route not in (CENTRAL, *registry_remote.TARGETS.keys()):
        raise ValueError('RECOVERY_TARGET_NOT_ENROLLED')
    original_host_authority(route)
    try:
        mcp = inspect(route,identity,None,read_tool=True,server=server,seconds=28)
    except (OSError,ValueError,RuntimeError,TimeoutError,subprocess.TimeoutExpired) as exc:
        mcp = {'state':'MCP_UNAVAILABLE',
               'initialize_pass':False,'tools_list_pass':False,
               'read_tool_pass':False,'read_tool_error':type(exc).__name__}
    if mcp.get('read_tool_pass') is True:
        return {
            'contract':'mad4b.cross-ssh-resilient-read.v1',
            'state':'ORIGINAL_MCP_ABILITY_READ_VERIFIED',
            'target':route,'mcp_state':mcp.get('state'),
            'mcp_read_tool_pass':True,'native_fallback_used':False,
            'wordpress_user_required':False,
            'write_authorized':False,'grant_issued':False,'mutation_performed':False}
    native = None
    try:
        native=native_site_read(route,'host_operator',None)
    except (OSError,ValueError,RuntimeError,KeyError,subprocess.TimeoutExpired):
        pass
    doctor = None
    if native is None and route in registry_remote.TARGETS:
        try:doctor=registry_remote.doctor(route)
        except (OSError,ValueError,RuntimeError,KeyError,subprocess.TimeoutExpired):
            pass
    native_ok=(isinstance(native,dict) and
               native.get('state')=='EXACT_NATIVE_SITE_READ_VERIFIED')
    doctor_ok=(isinstance(doctor,dict) and
               doctor.get('identity_verified') is True and
               doctor.get('wpcli_without_plugins') is True)
    return {
        'contract':'mad4b.cross-ssh-resilient-read.v1',
        'state':('OUT_OF_BAND_SIGNED_NATIVE_READ_VERIFIED' if native_ok else
                 'OUT_OF_BAND_PLUGIN_INDEPENDENT_DIAGNOSIS_VERIFIED' if doctor_ok else
                 'OUT_OF_BAND_RECOVERY_NOT_VERIFIED'),
        'target':route,'mcp_state':mcp.get('state'),
        'mcp_read_tool_pass':False,
        'mcp_read_tool_error':mcp.get('read_tool_error'),
        'native_fallback_used':True,
        'native_identity_read_verified':native_ok,
        'plugin_independent_diagnosis_verified':doctor_ok,
        'diagnostic_state':doctor.get('state') if doctor_ok else None,
        'mcp_private_ability_unlocked':False,
        'wordpress_user_required':False,'write_authorized':False,
        'grant_issued':False,'mutation_performed':False}


def main():
    parser=argparse.ArgumentParser()
    parser.add_argument('--target',required=True,
                        choices=[CENTRAL,*registry_remote.TARGETS.keys()])
    parser.add_argument('--identity-mode',choices=MODES,default='auto')
    parser.add_argument('--wp-user',default=None)
    parser.add_argument('--server',default=None)
    parser.add_argument('--run',choices=('inspect','stdio','native-read','recover'),default='inspect')
    parser.add_argument('--read-tool',action='store_true')
    a=parser.parse_args()
    wp_args(a.identity_mode,a.wp_user)
    if a.run=='recover':
        data=resilient_inspect(a.target,a.identity_mode,a.wp_user,a.server)
        print(json.dumps(data,sort_keys=True))
        return 0 if data['state'] != 'OUT_OF_BAND_RECOVERY_NOT_VERIFIED' else 2
    if a.run=='native-read':
        if a.server is not None or a.read_tool:
            raise ValueError('NATIVE_READ_DOES_NOT_ACCEPT_MCP_SERVER_OR_TOOL')
        result=native_site_read(a.target,a.identity_mode,a.wp_user)
        print(json.dumps(result,sort_keys=True))
        return 0
    if a.run=='stdio':
        argv=command_for(a.target,a.identity_mode,a.wp_user,a.server)
        os.execvp(argv[0],argv)
    result=inspect(a.target,a.identity_mode,a.wp_user,a.read_tool,server=a.server)
    print(json.dumps(result,sort_keys=True))
    return 0 if result['initialize_pass'] and result['tools_list_pass'] and (
        not a.read_tool or result['read_tool_pass']) else 2

if __name__=='__main__':
    try:sys.exit(main())
    except (OSError,ValueError,RuntimeError,subprocess.TimeoutExpired,subprocess.CalledProcessError) as exc:
        print(json.dumps({'state':'FAIL_CLOSED','error':type(exc).__name__,
                          'detail':str(exc)[:180],'write_attempted':False}))
        sys.exit(3)
