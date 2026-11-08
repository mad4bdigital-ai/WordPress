#!/usr/bin/env python3
"""Exact-source cache flush guard for MAD4B company PHP runtime.

Read-only by default. --apply may restore a known *exact* legacy or missing
temporary script, but refuses unknown changes and concurrent WP-CLI runs.
No WordPress DB writes, no Redis operations, no policy/price modifications.
"""
import argparse
import datetime as dt
import hashlib
import json
import os
from pathlib import Path
import re
import subprocess
import sys
import tempfile

ROOT=Path('/opt/mad4b-company-provisioning')
POLICY=ROOT/'policies/company-redis-flush-guard-v1.json'
REPORT=ROOT/'reports/company-redis-flush-guard-current.json'
SAFE_CONTAINER=re.compile(r'^php-[a-z0-9]{8,40}$')
SAFE_SHA=re.compile(r'^[a-f0-9]{64}$')


def sha(blob):
    return hashlib.sha256(blob).hexdigest()


def call(argv, seconds=14):
    return subprocess.run(argv,text=True,capture_output=True,timeout=seconds)


def direct_report(path, data):
    path.parent.mkdir(parents=True,exist_ok=True)
    fd,tmp=tempfile.mkstemp(prefix='.cache-flush-guard-',dir=path.parent)
    try:
        with os.fdopen(fd,'w') as out:
            json.dump(data,out,indent=2,ensure_ascii=False,sort_keys=True)
            out.write('\n');out.flush();os.fsync(out.fileno())
        os.chmod(tmp,0o600)
        os.replace(tmp,path)
    finally:
        if os.path.exists(tmp):os.unlink(tmp)


def assess_site(name, site, approved_source, apply=False):
    container=site['container']
    path=site['runtime_path']
    expected=site['approved_sha256']
    original=site['original_sha256']
    if not SAFE_CONTAINER.fullmatch(container) or path!='/tmp/mad4b-property-type-fix.php':
        raise ValueError('UNENROLLED_RUNTIME_TARGET')
    if not SAFE_SHA.fullmatch(original) or not SAFE_SHA.fullmatch(expected):
        raise ValueError('INVALID_RELEASE_HASH')
    if sha(approved_source)!=expected:raise ValueError('APPROVED_SOURCE_CHANGED')
    result=call(['docker','exec',container,'sh','-c',
        'test -f /tmp/mad4b-property-type-fix.php && sha256sum /tmp/mad4b-property-type-fix.php || echo MISSING'])
    if result.returncode:raise RuntimeError('CONTAINER_UNAVAILABLE')
    fields=result.stdout.strip().split()
    current=fields[0] if fields else 'UNREADABLE'
    if current!='MISSING' and not SAFE_SHA.fullmatch(current):
        return {'runtime_container':container,'state':'UNAVAILABLE','action':'BAD_HASH_READBACK'}
    receipt={'runtime_container':container,'observed_hash':current,'expected_hash':expected,
             'approved_version_present':current==expected,'action':'NONE','state':'PASS' if current==expected else 'DRIFT'}
    if current==expected:
        lint=call(['docker','exec',container,'php','-l',path])
        if lint.returncode:receipt.update(state='DRIFT',action='PHP_LINT_FAIL')
        return receipt
    if not apply:
        receipt['action']='AUDIT_ONLY'
        return receipt
    if current not in (original,'MISSING'):
        receipt.update(state='DRIFT',action='UNKNOWN_CHANGE_REFUSED')
        return receipt
    # Explicit concurrency check. The transition is temporary and never
    # changes WordPress content or Redis.
    running=call(['pgrep','-af','[w]p eval-file /tmp/mad4b-property-type-fix.php'])
    if running.returncode==0 and running.stdout.strip():
        receipt.update(state='DEFERRED',action='CONCURRENT_WPCLI_RUNNING')
        return receipt
    stage='/tmp/mad4b-property-type-fix.guard-candidate.php'
    # Use docker cp stdin from read-only approved host release file.
    copied=call(['docker','cp',str(POLICY.parent.parent/'bin/mad4b-property-type-fix-scoped.php'),container+':'+stage])
    if copied.returncode:
        receipt.update(state='DRIFT',action='STAGE_COPY_FAILED')
        return receipt
    check=call(['docker','exec',container,'php','-l',stage])
    if check.returncode:
        receipt.update(state='DRIFT',action='STAGE_PHP_LINT_FAILED')
        return receipt
    # Recheck immediately before replacing, refuse to clobber concurrent edits.
    recheck=call(['docker','exec',container,'sh','-c',
        'test -f /tmp/mad4b-property-type-fix.php && sha256sum /tmp/mad4b-property-type-fix.php || echo MISSING'])
    if recheck.returncode or recheck.stdout.strip().split()[0]!=current:
        receipt.update(state='DEFERRED',action='CONCURRENT_FILE_EDIT')
        return receipt
    moved=call(['docker','exec','-u','0',container,'mv',stage,path])
    if moved.returncode:
        receipt.update(state='DRIFT',action='APPLY_REJECTED')
        return receipt
    after=call(['docker','exec',container,'sha256sum',path])
    got=after.stdout.strip().split()[0] if after.returncode==0 else ''
    if got!=expected:
        receipt.update(state='DRIFT',action='POST_APPLY_HASH_FAILURE')
        return receipt
    receipt.update(state='PASS',action='RESTORED_APPROVED_RUNTIME',observed_hash=got,
                   approved_version_present=True)
    return receipt


def main():
    ap=argparse.ArgumentParser()
    ap.add_argument('--apply',action='store_true')
    ap.add_argument('--strict',action='store_true')
    ap.add_argument('--policy',default=str(POLICY))
    ap.add_argument('--report',default=str(REPORT))
    args=ap.parse_args()
    p=json.loads(Path(args.policy).read_text())
    if p.get('contract')!='mad4b.redis-flush-guard.desired-state.v1':
        raise ValueError('POLICY_CONTRACT_INVALID')
    approved=Path(p['approved_property_script'])
    source=approved.read_bytes()
    docs={}
    for path,digest in p['host_pins'].items():
        f=Path(path)
        live=sha(f.read_bytes()) if f.is_file() and not f.is_symlink() else 'MISSING'
        docs[path]={'sha256':live,'match':live==digest}
    for path,digest in p.get('restored_vendor_sources',{}).items():
        f=Path(path)
        live=sha(f.read_bytes()) if f.is_file() and not f.is_symlink() else 'MISSING'
        docs[path]={'sha256':live,'match':live==digest}
    status={}
    for name,site in p['sites'].items():
        try:status[name]=assess_site(name,site,source,args.apply)
        except Exception as ex:status[name]={'state':'UNAVAILABLE','failure_type':type(ex).__name__}
    report={'contract':'mad4b.redis-flush-guard.reconcile.v1',
      'observed_utc':dt.datetime.now(dt.timezone.utc).isoformat(),
      'mode':'apply_if_known_safe' if args.apply else 'audit_only',
      'host_files':docs,'sites':status,
      'summary':{'PASS':sum(s.get('state')=='PASS' for s in status.values()),
                 'DRIFT':sum(s.get('state')=='DRIFT' for s in status.values())+
                         sum(not x['match'] for x in docs.values()),
                 'DEFERRED':sum(s.get('state')=='DEFERRED' for s in status.values()),
                 'UNAVAILABLE':sum(s.get('state')=='UNAVAILABLE' for s in status.values())},
      'does_not_change_redis':True,'does_not_change_wordpress_content':True}
    direct_report(Path(args.report),report)
    print(json.dumps({'summary':report['summary'],
       'mode':report['mode'],
       'sites':{n:{'state':x['state'],'action':x.get('action')} for n,x in status.items()}},sort_keys=True))
    return 2 if args.strict and (report['summary']['DRIFT'] or report['summary']['UNAVAILABLE']) else 0


if __name__=='__main__':sys.exit(main())
