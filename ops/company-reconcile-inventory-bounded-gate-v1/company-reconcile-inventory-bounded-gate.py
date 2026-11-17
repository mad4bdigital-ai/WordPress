#!/usr/bin/env python3
"""Bounded, fail-closed scheduling for *read-only* company inventory.

Evaluate BOTH original optional-work and CPU admission gates in one observation.
Never change thresholds, signed authority, native jobs, or any WordPress state.
"""
from __future__ import annotations
import argparse,json,os,pathlib,subprocess,sys,time

UNIT='mad4b-company-reconcile-inventory-refresh.service'
OPTIONAL=pathlib.Path('/usr/local/libexec/mad4b-optional-work-admission.py')
CPU=pathlib.Path('/usr/local/libexec/mad4b-cpu-admission.py')
MAX_WAIT=45

def trusted(path:pathlib.Path)->bool:
    try:
        s=path.lstat()
        return path.is_file() and not path.is_symlink() and s.st_uid==0 and (s.st_mode & 0o022)==0
    except (OSError,ValueError):
        return False

def run_gate(args:list[str],timeout:int):
    try:
        r=subprocess.run(args,capture_output=True,text=True,timeout=timeout,check=False)
        return r.returncode,r.stdout[-1000:]
    except (OSError,subprocess.TimeoutExpired):
        return 1,''

def one_attempt(runner=run_gate):
    """No return TRUE without both original policies passing this same cycle."""
    if not trusted(OPTIONAL) or not trusted(CPU):
        return False,'admission_program_untrusted'
    optional,_=runner([sys.executable,str(OPTIONAL)],7)
    if optional!=0:
        return False,'original_optional_admission_denied'
    # Sampling *never* authorizes anything; original CPU gate will still
    # recheck current site WPCLI slots, 313 activity and load after sample.
    sampled,_=runner([sys.executable,str(CPU),'sample'],8)
    if sampled!=0:
        return False,'original_cpu_sample_unavailable'
    budget,_=runner([sys.executable,str(CPU),'admit',UNIT],8)
    if budget!=0:
        return False,'original_cpu_budget_denied'
    return True,'both_original_gates_admitted'

def wait_for_window(max_wait:int=45,clock=time.monotonic,sleep=time.sleep,attempt=one_attempt):
    if not isinstance(max_wait,int) or max_wait<0 or max_wait>MAX_WAIT:
        raise ValueError('BOUNDED_INVENTORY_WAIT_INVALID')
    deadline=clock()+max_wait
    attempts=0
    while True:
        attempts+=1
        ok,reason=attempt()
        if ok:
            return {'admitted':True,'reason':reason,'attempts':attempts,
                    'original_gates_bypassed':False,'write_jobs_created':0}
        remaining=deadline-clock()
        if remaining<=5:
            return {'admitted':False,'reason':reason,'attempts':attempts,
                    'retry':'NEXT_EXISTING_TIMER','original_gates_bypassed':False,
                    'write_jobs_created':0}
        sleep(min(6,remaining-1))

def main(argv=None):
    a=argparse.ArgumentParser();a.add_argument('--max-wait',type=int,default=45)
    args=a.parse_args(argv)
    if args.max_wait<0 or args.max_wait>MAX_WAIT:
        return 2
    # Invocation itself is only intended for the single enrolled read-only
    # inventory service. Systemd unit identity is configured in the drop-in;
    # the source script has no arbitrary shell, command, host or target input.
    result=wait_for_window(args.max_wait)
    print(json.dumps({'contract':'mad4b.company-inventory.safe-window-admission.v1',
                      'unit':UNIT,**result},sort_keys=True),flush=True)
    return 0 if result['admitted'] else 1
if __name__=='__main__':sys.exit(main())
