#!/usr/bin/env python3
"""Non-mutating MAD4B WordPress Cache Control desired-state observer.

No WP-CLI calls, database connections, Redis writes, FastCGI socket
messages, customer data or automatic repair. Checks only file identity,
systemd socket readiness and explicitly documented policy coverage.
"""
import argparse
import datetime as dt
import hashlib
import json
import os
from pathlib import Path
import stat
import subprocess
import sys
import tempfile

ROOT=Path('/opt/mad4b-company-provisioning')
POLICY=ROOT/'policies/company-cache-control-readiness-v1.json'
REPORT=ROOT/'reports/company-cache-control-readiness-current.json'


def digest(path:Path)->str:
    return hashlib.sha256(path.read_bytes()).hexdigest()


def safe_file_check(path:Path,expected:str)->str:
    if not path.is_file() or path.is_symlink():
        return 'MISSING_OR_UNSAFE'
    return 'PASS' if digest(path)==expected else 'HASH_DRIFT'


def socket_state(path:Path,service:str)->dict:
    try:
        bits=path.lstat()
    except OSError:
        return {'state':'MISSING'}
    if not stat.S_ISSOCK(bits.st_mode):
        return {'state':'NOT_A_SOCKET'}
    result=subprocess.run(['systemctl','is-active',service],
       capture_output=True,text=True,timeout=10)
    state=result.stdout.strip()
    return {'state':'PASS' if state=='active' and result.returncode==0
        else 'SERVICE_NOT_ACTIVE','systemd':state}


def site_observation(name:str,spec:dict,source_hash:str)->dict:
    file=Path(spec['plugin'])
    reg=Path(spec['registry'])
    actual=safe_file_check(file,source_hash)
    found=reg.is_file() and not reg.is_symlink()
    sock=socket_state(Path(spec['socket']),spec['socket_unit'])
    coverage='REGISTRY_V2_FILE_PRESENT' if found else 'LOCAL_FALLBACK_ONLY'
    integrity=actual=='PASS' and sock['state']=='PASS'
    state=('DRIFT' if not integrity else
        ('PASS' if found else 'DEGRADED_INHERITANCE'))
    return {'state':state,'plugin':actual,'socket':sock,
        'inheritance_coverage':coverage,
        'runtime_registry_resolution_verified_this_poll':False,
        'safe_read_only':True,
        'no_customer_cache_cleared':True}


def write_json_atomic(path:Path,data:dict)->None:
    path.parent.mkdir(parents=True,exist_ok=True)
    fd,temp=tempfile.mkstemp(prefix='.mad4b-cache-control-',dir=path.parent)
    try:
        with os.fdopen(fd,'w') as stream:
            json.dump(data,stream,indent=2,sort_keys=True)
            stream.write('\n')
            stream.flush()
            os.fsync(stream.fileno())
        os.chmod(temp,0o600)
        os.replace(temp,path)
    finally:
        if os.path.exists(temp):
            os.unlink(temp)


def main():
    ap=argparse.ArgumentParser()
    ap.add_argument('--policy',type=Path,default=POLICY)
    ap.add_argument('--report',type=Path,default=REPORT)
    ap.add_argument('--strict',action='store_true')
    args=ap.parse_args()
    policy=json.loads(args.policy.read_text())
    if policy.get('contract')!='mad4b.cache-control.readiness-policy.v1':
        raise ValueError('policy_contract_invalid')
    expected=policy['sha256']
    if not isinstance(expected,str) or len(expected)!=64:
        raise ValueError('policy_sha_invalid')
    source=Path(policy['approved_source'])
    source_check=safe_file_check(source,expected)
    status={}
    for name,spec in policy['sites'].items():
        try:
            status[name]=site_observation(name,spec,expected)
        except Exception as exc:
            status[name]={'state':'UNAVAILABLE','reason':type(exc).__name__}
    summary={key:sum(v['state']==key for v in status.values())
        for key in ('PASS','DEGRADED_INHERITANCE','DRIFT','UNAVAILABLE')}
    if source_check!='PASS':
        summary['DRIFT']+=1
    report={
        'contract':'mad4b.cache-control.readiness-report.v1',
        'at_utc':dt.datetime.now(dt.timezone.utc).isoformat(),
        'approved_source_integrity':source_check,
        'sites':status,'summary':summary,
        'verdict':'ALERT' if summary['DRIFT'] or summary['UNAVAILABLE']
            else ('PARTIAL_INHERITANCE' if summary['DEGRADED_INHERITANCE']
                  else 'PASS'),
        'automatic_repair':False,'wpcli_invocations':0,
        'fastcgi_purge_commands':0,'redis_flush_commands':0,
        'actual_admin_apply_test_completed':False,
        'pricing_authority':'existing_native_quote_engine'}
    write_json_atomic(args.report,report)
    print(json.dumps({'verdict':report['verdict'],'summary':summary}))
    return 2 if args.strict and report['verdict']=='ALERT' else 0


if __name__=='__main__':
    sys.exit(main())
