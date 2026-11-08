#!/usr/bin/env python3
"""Strict two-company Nginx FastCGI cache-root ownership reconciler.

Repairs only a known legacy image UID 101 -> runtime UID 33 on the cache
root itself. Never changes child paths, permissions, cache keys, Redis or HTML.
"""
import argparse,datetime as dt,json,os,stat,subprocess,tempfile
from pathlib import Path
REPORT=Path('/opt/mad4b-company-provisioning/reports/wayfa-anonymous-public-cache-control-20261108/cache-dir-owner-current.json')
SITES={
 'tours-dev':{'container':'nginx-wkgtkzta35wkkgouwt6ruljc','dir':'/var/cache/nginx/etg_woo_perf_v1'},
 'wayfatours':{'container':'nginx-guxhp9y6vgh7oouuw8plxrlr','dir':'/var/cache/nginx/wayfa'}
}
EXPECTED_USER='33:33'
EXPECTED_STAT='33:33:700'
LEGACY_STAT='101:33:700'
def choose(observed):
    if observed==EXPECTED_STAT:return 'PASS'
    if observed==LEGACY_STAT:return 'REPAIR_ALLOWED'
    return 'DRIFT_REQUIRES_REVIEW'
def run(args,timeout=12):
    p=subprocess.run(args,stdout=subprocess.PIPE,stderr=subprocess.PIPE,text=True,timeout=timeout)
    if p.returncode:raise RuntimeError('command_failed:'+str(p.returncode)+':'+p.stderr[-180:])
    return p.stdout.strip()
def assess(site, config, repair):
    c=config['container'];directory=config['dir']
    # No external input enters the shell; exact enrolled path and container IDs only.
    runtime=run(['docker','inspect','--format','{{.Config.User}}',c])
    if runtime!=EXPECTED_USER:raise RuntimeError('unexpected_nginx_runtime_user')
    shell='p="'+directory+'"; test ! -L "$p" && test -d "$p" && stat -c "%u:%g:%a" "$p"'
    before=run(['docker','exec','--user','0',c,'sh','-c',shell])
    action=choose(before)
    adjusted=False
    if action=='REPAIR_ALLOWED' and repair:
        command=('p="'+directory+'"; test ! -L "$p" && test -d "$p" && '
                 'test "$(stat -c "%u:%g:%a" "$p")" = "101:33:700" && '
                 'chown 33:33 "$p" && '
                 'test "$(stat -c "%u:%g:%a" "$p")" = "33:33:700"')
        run(['docker','exec','--user','0',c,'sh','-c',command])
        adjusted=True
    after=run(['docker','exec','--user','0',c,'sh','-c',shell])
    if after==EXPECTED_STAT:
        run(['docker','exec','--user','33:33',c,'sh','-c','p="'+directory+'"; test -x "$p" && test -r "$p" && test -w "$p"'])
        # Existing cache files and subdirectories must also remain owned by
        # Nginx's runtime UID. Do not perform an unsafe recursive chown.
        child_scan=('p="'+directory+'"; '
            'find "$p" -mindepth 1 -maxdepth 3 -type d ! -user 33 -print -quit; '
            'find "$p" -mindepth 1 -type f ! -user 33 -print -quit')
        mismatched=run(['docker','exec','--user','0',c,'sh','-c',child_scan],timeout=20)
        verdict='DRIFT_CHILD_OWNER' if mismatched else 'PASS'
    elif action=='REPAIR_ALLOWED' and not repair:verdict='REPAIR_ELIGIBLE'
    else:verdict='DRIFT'
    return {'site':site,'status':verdict,'before':before,'after':after,'repaired_root_only':adjusted,'cache_files_mutated':False,'nginx_reloaded':False,'redis_flushed':False}
def atomic_report(data):
    REPORT.parent.mkdir(parents=True,exist_ok=True)
    fd,name=tempfile.mkstemp(prefix='.cache-owner-',dir=REPORT.parent)
    try:
        with os.fdopen(fd,'w') as f:
            json.dump(data,f,indent=2,sort_keys=True);f.write('\n');f.flush();os.fsync(f.fileno())
        os.chmod(name,0o600)
        os.replace(name,REPORT)
    finally:
        if os.path.exists(name):os.unlink(name)
def main():
    p=argparse.ArgumentParser()
    p.add_argument('--selftest',action='store_true')
    p.add_argument('--repair',action='store_true')
    args=p.parse_args()
    if args.selftest:
        assert choose('33:33:700')=='PASS'
        assert choose('101:33:700')=='REPAIR_ALLOWED'
        for value in ['101:33:777','33:33:600','0:0:700','101:0:700','notfound']:
            assert choose(value)=='DRIFT_REQUIRES_REVIEW'
        print('FASTCGI_CACHE_ROOT_OWNER_GUARD_SELFTEST_PASS')
        return
    results={}
    for site,conf in SITES.items():
        try:results[site]=assess(site,conf,args.repair)
        except (OSError,RuntimeError,subprocess.TimeoutExpired) as e:
            results[site]={'site':site,'status':'DEGRADED','reason':str(e)[:250],'cache_files_mutated':False,'redis_flushed':False}
    verdict='PASS' if all(x['status']=='PASS' for x in results.values()) else 'DEGRADED'
    report={'contract':'mad4b.nginx.fastcgi.cache-root-ownership.v1',
            'utc':dt.datetime.now(dt.timezone.utc).isoformat(),'verdict':verdict,
            'repair_mode':args.repair,'scope':'exact_enrolled_cache_root_only_no_recursion',
            'sites':results}
    atomic_report(report)
    print(json.dumps({'verdict':verdict,'sites':results},sort_keys=True))
    if verdict!='PASS':raise SystemExit(2)
if __name__=='__main__':main()

