#!/usr/bin/env python3
"""Owner Central: exact five remote WordPress site/host readback via enrolled VPS SSH.

THIS IS ONLY A FACTUAL COMPANY BINDING RECEIPT. It does not enroll an SSH
transport, sign an Owner approval, issue a 312/313 certificate or grant Write.
All remote commands are fixed WP-CLI read-only and site-root-bound.
"""
from __future__ import annotations
import argparse
import base64
import datetime as dt
import hashlib
import importlib.util
import json
import os
from pathlib import Path
import re
import shlex
import stat
import subprocess
import sys
import time

BASE=Path('/opt/mad4b-company-transport')
V144=BASE/'host-authority-v144-ssh-repair-readonly/vps_host_ssh_repair.py'
V144_SHA='d0f9f42d13cc8b36b9eeb53c04330144e1413b07ac4463ace9cdaa8733229534'
POLICY=BASE/'native-operator-v1/host-authority.json'
HOSTS=Path('/opt/hostinger-shell-mcp/external-targets')
DIR=BASE/'host-authority-v146-external-sites-readonly'
OUT=DIR/'latest.json'
WORDPRESS_OPTION='mad4b_remote_external_host_readonly_v146'
SCHEMA='mad4b.host-v146.external-host-exact-site-ssh-readback.v1'
PREFIX='MAD4B_V146_IDENTITY:'
UUID=re.compile(r'^[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}$')
SITES=(
 ('tourism-almallahgroup','production','hosting-1',
  'b8dffda1-db99-4789-aa72-86bf63f557cc',
  'https://tourism.almallahgroup-mg.com',
  '/home/u338416126/domains/almallahgroup-mg.com/public_html/tourism.almallahgroup-mg.com'),
 ('all-royal-egypt','production','hosting-2',
  '0384b6ce-4004-4b4e-b701-7d77cb6f6ff0',
  'https://www.allroyalegypt.com',
  '/home/u948953904/domains/allroyalegypt.com/public_html'),
 ('all-royal-egypt','staging','hosting-2',
  '49c562d1-8f2f-456f-b454-26816c6ba4cb',
  'https://staging.allroyalegypt.com',
  '/home/u948953904/domains/allroyalegypt.com/public_html/staging'),
 ('egypt-tour-gates','production','hosting-3',
  '0c7786ba-a025-4650-9ceb-32a51d74aaec',
  'https://egypttourgates.com',
  '/home/u783534439/domains/egypttourgates.com/public_html'),
 ('egypt-tour-gates','staging','hosting-3',
  'd745d81f-6fc4-5c6a-99dd-d953c92137bf',
  'https://staging.egypttourgates.com',
  '/home/u783534439/domains/egypttourgates.com/public_html/staging'),
)
PHP=r'''$p=get_option("mad4b_scp_site_profile_v2",[]);
$i=get_option("mad4b_company_site_identity_v1",[]);
if(!is_array($p))$p=[];if(!is_array($i))$i=[];
echo "MAD4B_V146_IDENTITY:".wp_json_encode([
"siteurl"=>get_option("siteurl"),"home"=>get_option("home"),
"wp_environment"=>wp_get_environment_type(),
"profile_environment"=>$p["environment"]??null,
"site_uuid"=>$i["site_uuid"]??($p["site_uuid"]??null),
"company_code"=>$i["company_code"]??($p["company_code"]??null)
],JSON_UNESCAPED_SLASHES);'''
WP_CONTAINER='php-wkgtkzta35wkkgouwt6ruljc'

class Denied(ValueError):pass
def require(x,reason):
    if not x:raise Denied(reason)
def trusted(path,sha=None):
    st=path.lstat()
    require(stat.S_ISREG(st.st_mode) and st.st_uid==0
       and not path.is_symlink()
       and (stat.S_IMODE(st.st_mode)&0o077)==0
       and st.st_size<524288,
       'ROOT_SOURCE_NOT_TRUSTED')
    data=path.read_bytes()
    if sha is not None: require(hashlib.sha256(data).hexdigest()==sha,
       'ORIGINAL_SSH_HELPER_HASH_CHANGED')
    return data

def load_v144():
    trusted(V144,V144_SHA)
    spec=importlib.util.spec_from_file_location('_v144_root_ssh',V144)
    require(spec is not None and spec.loader is not None,
            'ORIGINAL_V144_HELPER_MISSING')
    module=importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module

def validate(evidence,central,host,site):
    company,env,target,uuid,origin,root=site
    require(central.get('site_uuid')==uuid and
       central.get('canonical_origin')==origin,
       'CENTRAL_COMPANY_REGISTRY_IDENTITY_MISMATCH')
    require(host.get('site_uuid')==uuid and
       host.get('canonical_origin')==origin and
       host.get('registry_binding')==target+':'+env and
       host.get('expected_root')==root,
       'ROOT_ORIGINAL_COMPANY_HOST_SCOPE_DRIFT')
    require(evidence.get('siteurl')==origin and
       evidence.get('home')==origin and
       evidence.get('wp_environment')==env and
       evidence.get('profile_environment')==env and
       evidence.get('site_uuid')==uuid and
       evidence.get('company_code') in (None,'',company),
       'REMOTE_COMPANY_SITE_IDENTITY_NOT_VERIFIED')
    return {
      'company':company,'environment':env,'host_id':target,
      'site_uuid':uuid,'canonical_origin':origin,
      'identity_verified':True,'pinned_ssh_verified':True,
      'scope_evidence':'central_registry_root_company_host_and_remote_wpcli',
      'host_company_binding_readonly_verified':True,
      'original305_signed_grant_verified':False,
      'original312_signed_exact_job_verified':False,
      'original313_predecessor_verified':False,
      'owner_v3_stepup_signed_verified':False,
      'host_write_authorized':False,
      'production_mutated':False,
      'next_action':'ORIGINAL_FIRSTPARTY_SIGNED_COMPANY_SCOPE_ENROLLMENT',
    }

def remote(host,site):
    company,env,target,uuid,origin,root=site
    host_c=host['host'] if 'host' in host else host['hostname']
    user=host['user'] if 'user' in host else host['username']
    keyroot=HOSTS/target
    command=['/usr/local/bin/wp','--path='+root,'--skip-plugins','--skip-themes',
             'eval',PHP]
    fixed_cmd=shlex.join(command)
    args=['/usr/bin/ssh','-F','/dev/null','-T',
       '-o','BatchMode=yes','-o','IdentitiesOnly=yes',
       '-o','StrictHostKeyChecking=yes','-o','UpdateHostKeys=no',
       '-o','UserKnownHostsFile='+str(keyroot/'known_hosts_verified'),
       '-o','GlobalKnownHostsFile=/dev/null',
       '-o','ClearAllForwardings=yes','-o','PermitLocalCommand=no',
       '-o','ProxyCommand=none','-o','ConnectTimeout=8','-o','LogLevel=ERROR',
       '-i',str(keyroot/'id_ed25519'),'-p',str(host['port']),
       user+'@'+host_c,fixed_cmd]
    p=subprocess.run(args,capture_output=True,text=True,
                     stdin=subprocess.DEVNULL,timeout=33,check=False)
    require(p.returncode==0,'PINNED_SITE_REMOTE_WPCLI_READ_FAILED')
    evidence=[x[len(PREFIX):] for x in p.stdout.splitlines()
              if x.startswith(PREFIX)]
    require(len(evidence)==1 and len(evidence[0])<4096,
            'REMOTE_SITE_PROOF_MISSING_OR_AMBIGUOUS')
    result=json.loads(evidence[0])
    require(type(result) is dict and set(result)=={
        'home','siteurl','wp_environment','profile_environment',
        'site_uuid','company_code'},
        'REMOTE_SITE_EVIDENCE_SHAPE_INVALID')
    return result

def central_registry():
    code=r'''$r=get_option("mad4b_company_environment_registry_v2",[]);
$o=[];foreach(($r["companies"]??[]) as $c=>$v)foreach(($v["environments"]??[]) as $e=>$s)
if(is_array($s))$o[$c.":".$e]=["site_uuid"=>$s["site_uuid"]??null,
"canonical_origin"=>$s["canonical_origin"]??null];
echo "MAD4B_V146_CENTRAL:".wp_json_encode($o,JSON_UNESCAPED_SLASHES);'''
    args=['/usr/bin/docker','exec',WP_CONTAINER,'wp',
          'eval',code,'--path=/var/www/html','--skip-plugins','--skip-themes']
    p=subprocess.run(args,text=True,capture_output=True,
                     timeout=28,check=False)
    require(p.returncode==0,'CENTRAL_COMPANY_REGISTRY_WPCLI_UNAVAILABLE')
    rows=[v.split('MAD4B_V146_CENTRAL:',1)[1] for v in p.stdout.splitlines()
          if v.startswith('MAD4B_V146_CENTRAL:')]
    require(len(rows)==1 and len(rows[0])<16384,
            'CENTRAL_COMPANY_REGISTRY_READBACK_MISSING')
    obj=json.loads(rows[0])
    require(type(obj) is dict,'CENTRAL_COMPANY_REGISTRY_SHAPE_INVALID')
    return obj

def inspect(central=None, policy=None, v144=None, reader=remote):
    require(os.geteuid()==0,'ROOT_ONLY_REMOTE_READ_REQUIRED')
    v144=v144 or load_v144()
    st=v144.inspect_all(network=True)
    require(st.get('contract')=='mad4b.host-v144.vps-registered-host-ssh-repair-status.v1',
            'SSH_PINNED_MONITOR_CONTRACT_DRIFT')
    known={r['target_id'] for r in st['targets']
           if r.get('ssh_authenticated') is True
           and r.get('identity_pinned') is True
           and r.get('fixed_diagnostic_shell_verified') is True}
    central=central if central is not None else central_registry()
    policy=policy if policy is not None else json.loads(trusted(POLICY))
    require(policy.get('contract')=='mad4b.native-vps-operator.v1',
            'ROOT_NATIVE_OPERATOR_CONTRACT_MISMATCH')
    source=policy.get('scopes',{})
    result=[]
    for s in SITES:
        company,env,target,uuid,origin,root=s
        base={'company':company,'environment':env,'host_id':target,
              'site_uuid':uuid,'canonical_origin':origin,
              'identity_verified':False,'pinned_ssh_verified':target in known,
              'host_company_binding_readonly_verified':False,
              'host_write_authorized':False,'production_mutated':False}
        try:
            require(target in known,'PINNED_SSH_NOT_AVAILABLE')
            remote_host=(v144.original_registry().HOSTS[target] if target!='hosting-2'
                         else v144.HOSTING2)
            if target=='hosting-2':v144.host2_binding()
            centralrow=central.get(company+':'+env,{})
            scope=source.get(company+':'+env,{})
            evidence=reader(remote_host,s)
            result.append(validate(evidence,centralrow,scope,s))
        except (Denied,OSError,KeyError,ValueError,
                json.JSONDecodeError,subprocess.TimeoutExpired) as e:
            base['state']='MISSING_VERIFIED_SITE_OR_HOST_EVIDENCE'
            base['reason_code']=str(e)[:110] if isinstance(e,Denied) else type(e).__name__
            result.append(base)
    complete=sum(r['host_company_binding_readonly_verified'] is True for r in result)
    return {
      'contract':SCHEMA,'observed_epoch':int(time.time()),
      'origin':'https://tours.dev.mad4b.com',
      'purpose':'NON_AUTHORIZING_EXACT_COMPANY_SSH_SITE_IDENTITY_READBACK',
      'registered_external_site_count':len(SITES),
      'verified_site_count':complete,
      'hosts':['hosting-1','hosting-2','hosting-3'],
      'sites':result,
      'no_nonregistered_sites_inferred':True,
      'ssh_identity_does_not_authorize_write':True,
      'owner_stepup_v3_satisfied_by_ssh':False,
      'original305_hold_preserved':True,
      'write_authorized':False,'production_mutated':False,
    }

def atomic_status(value):
    require(os.geteuid()==0 and DIR.is_dir()
         and DIR.stat().st_uid==0
         and stat.S_IMODE(DIR.stat().st_mode)==0o700
         and not DIR.is_symlink(),
         'READONLY_REPORT_PARENT_UNTRUSTED')
    if OUT.exists():trusted(OUT)
    temp=DIR/('.v146-'+str(os.getpid()))
    fd=os.open(temp,os.O_WRONLY|os.O_CREAT|os.O_EXCL|os.O_NOFOLLOW,0o600)
    try:
        with os.fdopen(fd,'wb') as f:
            data=(json.dumps(value,sort_keys=True,indent=2)+'\n').encode()
            f.write(data);f.flush();os.fsync(f.fileno())
        os.replace(temp,OUT)
    finally:
        if temp.exists():temp.unlink()

def wp_projection(value):
    """Only nonauthorizing safe evidence, originally verified from Root SSH."""
    require(value.get('contract')==SCHEMA
       and len(value.get('sites',[]))==len(SITES)
       and not value.get('write_authorized'), 'WP_PROJECTION_NOT_SAFE')
    json_string=json.dumps(value,separators=(',',':'),sort_keys=True)
    b64=base64.b64encode(json_string.encode()).decode()
    php='''$d=json_decode(base64_decode(%s),true);
if(!is_array($d)||($d["contract"]??"")!=="%s"
 || ($d["write_authorized"]??true)!==false
 || ($d["owner_stepup_v3_satisfied_by_ssh"]??true)!==false
 || count($d["sites"]??[])!==5
 || !defined("DB_NAME") || DB_NAME!=="etg_commerce_staging"
 || wp_get_environment_type()!=="staging"
 || get_option("siteurl")!=="https://tours.dev.mad4b.com")
 {echo "DENIED";exit(3);}
update_option("%s",$d,false);
$r=get_option("%s",[]);
if(!is_array($r)||($r["contract"]??"")!=="%s"
  ||($r["sites"]??null)!==$d["sites"]
  ||($r["observed_epoch"]??null)!==$d["observed_epoch"])
  {echo "DENIED_READBACK";exit(4);}
echo "MAD4B_V146_SYNC:".hash("sha256",wp_json_encode($r,JSON_UNESCAPED_SLASHES));'''%(
       "'"+b64+"'",SCHEMA,WORDPRESS_OPTION,WORDPRESS_OPTION,SCHEMA)
    args=['/usr/bin/docker','exec',WP_CONTAINER,'wp',
          'eval',php,'--path=/var/www/html','--skip-plugins','--skip-themes']
    p=subprocess.run(args,capture_output=True,text=True,timeout=28,check=False)
    require(p.returncode==0 and 'MAD4B_V146_SYNC:' in p.stdout,
            'WORDPRESS_READONLY_BINDING_PROJECTION_NOT_INSTALLED')
    return 'SYNCED_READONLY_OWNER_DASHBOARD'

def main():
    p=argparse.ArgumentParser()
    p.add_argument('--mode',required=True,choices=('check','publish'))
    a=p.parse_args()
    value=inspect()
    if a.mode=='publish':
        atomic_status(value)
        try:value['dashboard_sync']=wp_projection(value)
        except (Denied,subprocess.TimeoutExpired,OSError):
            value['dashboard_sync']='FAILED_NO_AUTHORITY_INFERRED'
    print(json.dumps(value,sort_keys=True))
    return 0

if __name__=='__main__':
    try:sys.exit(main())
    except (Denied,ValueError,KeyError,OSError,subprocess.TimeoutExpired) as e:
        print('HOST_V146_FAIL_CLOSED:'+str(e),file=sys.stderr)
        sys.exit(3)
