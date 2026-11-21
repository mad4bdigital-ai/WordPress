#!/usr/bin/env python3
"""Conservative MAD4B Git worktree lifecycle manager.

Never deletes untracked/modified work, ignored data, source repositories,
Git objects, live sites, or protected worktrees. Recovery is by Git refs.
Only Git's non-forced worktree remove handles eligible clean checkouts.
"""
import argparse
import datetime as dt
import hashlib
import json
import os
import pathlib
import subprocess
import sys
import time

HERE=pathlib.Path(__file__).resolve().parent
POLICY=HERE/'policy.json'
LOGDIR=HERE/'logs'
def run(args, timeout=25, check=False):
    try:
        p=subprocess.run(args,capture_output=True,text=True,timeout=timeout,check=False)
    except (OSError,subprocess.TimeoutExpired) as e:
        if check:raise RuntimeError(str(e)) from e
        return None
    if p.returncode and check:raise RuntimeError('Git or system command failed: '+str(args[:3])+' '+p.stderr[-250:])
    return p
def git(root,*args,timeout=25,check=False):
    return run(['git','-C',str(root),*args],timeout,check)
def parse(raw):
    entries=[];entry=None
    for line in raw.splitlines():
        if line.startswith('worktree '):
            if entry:entries.append(entry)
            entry={'path':line[9:],'locked':False,'prunable':False}
        elif entry and line.startswith('HEAD '):entry['head']=line[5:]
        elif entry and line.startswith('branch '):entry['branch']=line[7:]
        elif entry and line.startswith('locked'):entry['locked']=True
        elif entry and line.startswith('prunable'):entry['prunable']=True
    if entry:entries.append(entry)
    return entries
def process_activity():
    out=[]
    for pid in pathlib.Path('/proc').iterdir():
        if not pid.name.isdigit():continue
        try:
            cwd=os.readlink(pid/'cwd')
            if cwd.startswith('/opt/'):out.append(cwd)
            cmdline=(pid/'cmdline').read_bytes().replace(b'\0',b' ').decode('utf-8','replace')
            if '/opt/' in cmdline:out.append(cmdline)
        except (OSError,PermissionError):continue
    return out
def prefix_ok(path,prefixes):
    return any(str(path).startswith(s) for s in prefixes)
def utc():return dt.datetime.now(dt.timezone.utc).isoformat()
def get_policy():
    p=json.loads(POLICY.read_text())
    assert p['version']=='mad4b.workspace-lifecycle.v1'
    assert p['suspend_dirty_automatically'] is False
    assert p['require_no_ignored_files'] is True
    assert 7 <= int(p['minimum_age_days']) <= 90
    assert 1 <= int(p['max_remove_per_run']) <= 20
    return p
def journal(row):
    LOGDIR.mkdir(parents=True,exist_ok=True,mode=0o700)
    f=LOGDIR/'events.jsonl'
    fd=os.open(f,os.O_WRONLY|os.O_CREAT|os.O_APPEND,0o600)
    try:
        with os.fdopen(fd,'a') as stream:
            stream.write(json.dumps(row,ensure_ascii=False)+'\n')
            stream.flush();os.fsync(stream.fileno())
    except Exception:
        raise
def verify_head(repo,p,e):
    actual=git(p,'rev-parse','HEAD')
    return bool(actual and actual.returncode==0 and actual.stdout.strip()==e.get('head'))
def pin(repo,p,sha,day):
    slug=hashlib.sha256(str(p).encode()).hexdigest()[:20]+'-'+p.name
    ref='refs/parked/lifecycle/'+day+'/'+slug
    prev=git(repo,'rev-parse','--verify',ref)
    if prev and prev.returncode==0:
        if prev.stdout.strip()!=sha:raise RuntimeError('existing immutable recovery ref differs')
    else:
        new=git(repo,'update-ref',ref,sha,'',timeout=30)
        if not new or new.returncode:raise RuntimeError('could not create recovery ref')
    check=git(repo,'rev-parse',ref,check=True)
    if check.stdout.strip()!=sha:raise RuntimeError('recovery ref mismatch')
    return ref
def audit_and_apply(p,execute=False,minimum_age=None,max_remove=None):
    age=float(minimum_age if minimum_age is not None else p['minimum_age_days'])
    cap=int(max_remove if max_remove is not None else p['max_remove_per_run'])
    allowed=p['allowed_checkout_prefixes']
    protected={str(pathlib.Path(q).resolve()) for q in p['protected_paths']}
    active=process_activity()
    result={'timestamp':utc(),'mode':'execute' if execute else 'audit','scanned':0,'eligible':0,'suspended':0,'suspended_mib_estimate':0,'dirty':0,'skipped':{},'entries':[]}
    load=os.getloadavg()[0]
    result['load_1m']=round(load,2)
    if execute and load>float(p['max_1minute_load']):
        result['blocked']='LOAD_LIMIT_EXCEEDED'
        return result
    for r in p['repositories']:
        repo=pathlib.Path(r)
        if not repo.is_dir() or repo.is_symlink():
            result['skipped']['repository_not_available']=result['skipped'].get('repository_not_available',0)+1
            continue
        wr=git(repo,'worktree','list','--porcelain',timeout=35)
        if not wr or wr.returncode:
            result['skipped']['worktree_list_failed']=result['skipped'].get('worktree_list_failed',0)+1
            continue
        for entry in parse(wr.stdout):
            result['scanned']+=1
            path=pathlib.Path(entry['path'])
            reason=''
            if not path.is_dir() or path.is_symlink():reason='unavailable'
            elif str(path.resolve()) in protected or path.resolve()==repo.resolve():reason='protected'
            elif not prefix_ok(str(path),allowed):reason='outside_allowlist'
            elif entry['locked'] or entry['prunable']:reason='locked_or_prunable'
            elif not verify_head(repo,path,entry):reason='changed_head'
            else:
                try:mtime=path.stat().st_mtime
                except OSError:mtime=time.time()
                days=(time.time()-mtime)/86400
                status=git(path,'status','--porcelain=v1','-z','--untracked-files=all',timeout=40)
                if not status or status.returncode:reason='status_error'
                elif status.stdout:reason='dirty'
                elif days<age:reason='recent'
                elif any(str(path) in proc or proc.startswith(str(path)+'/') for proc in active):reason='active_process'
                else:
                    ignored=git(path,'clean','-ndX',timeout=25)
                    if not ignored or ignored.returncode:reason='ignored_check_error'
                    elif ignored.stdout.strip():reason='ignored_files_exist'
                    else:reason='eligible'
            if reason=='eligible':
                result['eligible']+=1
                if execute and result['suspended']<cap:
                    # repeat checks after optional other repo work
                    if not verify_head(repo,path,entry):
                        reason='raced_head_changed'
                    else:
                        final=git(path,'status','--porcelain=v1','-z','--untracked-files=all',timeout=40)
                        if not final or final.returncode or final.stdout:reason='raced_dirty'
                        else:
                            try:
                                recovery=pin(repo,path,entry['head'],dt.datetime.now(dt.timezone.utc).strftime('%Y%m%d'))
                                size=run(['du','-sk',str(path)])
                                kb=int(size.stdout.split()[0]) if size and size.returncode==0 else 0
                                removed=git(repo,'worktree','remove',str(path),timeout=90)
                                if not removed or removed.returncode:reason='git_remove_refused'
                                else:
                                    reason='suspended'
                                    result['suspended']+=1
                                    result['suspended_mib_estimate']+=kb//1024
                                    journal({'at':utc(),'repository':str(repo),'original_path':str(path),
                                             'head':entry['head'],'original_branch':entry.get('branch'),
                                             'recovery_ref':recovery,'status':'suspended','size_kib':kb})
                            except Exception as ex:
                                reason='recovery_ref_or_remove_failed'
                                journal({'at':utc(),'repository':str(repo),'original_path':str(path),
                                         'status':'held','why':str(ex)[:180]})
            if reason=='dirty':result['dirty']+=1
            result['skipped'][reason]=result['skipped'].get(reason,0)+1
            if reason not in ('recent','protected') or len(result['entries'])<30:
                result['entries'].append({'repo':str(repo),'path':str(path),'status':reason,'branch':entry.get('branch'),'head':entry.get('head')})
    return result
def main():
    parser=argparse.ArgumentParser()
    group=parser.add_mutually_exclusive_group(required=True)
    group.add_argument('--audit',action='store_true')
    group.add_argument('--execute',action='store_true')
    parser.add_argument('--min-age-days',type=int)
    parser.add_argument('--max-remove',type=int)
    args=parser.parse_args()
    policy=get_policy()
    # Production manager cannot weaken the immutable minimum 14-day retention.
    if args.execute and args.min_age_days is not None and args.min_age_days<policy['minimum_age_days']:
        raise SystemExit('RETENTION_OVERRIDE_DENIED')
    if args.execute and args.max_remove is not None and args.max_remove>policy['max_remove_per_run']:
        raise SystemExit('BATCH_LIMIT_OVERRIDE_DENIED')
    report=audit_and_apply(policy,args.execute,args.min_age_days,args.max_remove)
    output=LOGDIR/'latest-audit.json'
    output.write_text(json.dumps(report,indent=2,ensure_ascii=False)+'\n')
    os.chmod(output,0o600)
    print(json.dumps({k:v for k,v in report.items() if k!='entries'},ensure_ascii=False))
    if report.get('blocked'):raise SystemExit(2)
if __name__=='__main__':main()
