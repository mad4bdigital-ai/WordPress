#!/usr/bin/env python3
"""MAD4B noncritical public cache warm-up admission on VPS.

Applies to registered public warm timers only via systemd ExecCondition.
When host CPU or WordPress CLI demand is elevated, skip this *cycle*.
Original Broker / Signed 305-313 jobs are outside this guard.
"""
import json, os, sys
from pathlib import Path

def count_wpcli() -> int:
    total=0
    for item in Path('/proc').iterdir():
        if not item.name.isdecimal():
            continue
        try:
            argv=(item/'cmdline').read_bytes().split(b'\0')
        except (OSError, PermissionError):
            continue
        if len(argv)<2:
            continue
        command=Path(os.fsdecode(argv[0])).name
        if not (command.startswith('php') or command in {'wp','wp-cli'}):
            continue
        if any(b'/usr/local/bin/wp' in arg or arg==b'wp' for arg in argv[1:]):
            total+=1
    return total

def decide(load:float, cpus:int, wpcli:int) -> str:
    if wpcli >= 2:
        return 'WPCLI_SLOTS_BUSY'
    if load >= max(4.0, cpus * .65):
        return 'CPU_LOAD_HIGH'
    return 'ACCEPT'

def main() -> int:
    if len(sys.argv)>1 and sys.argv[1]=='--selftest':
        cases=[(0.8,8,0,'ACCEPT'),
            (3.2,8,2,'WPCLI_SLOTS_BUSY'),
            (7.0,8,0,'CPU_LOAD_HIGH'),
            (2.0,2,0,'ACCEPT'),
            (4.4,2,0,'CPU_LOAD_HIGH')]
        assert all(decide(a,b,c)==d for a,b,c,d in cases)
        print(json.dumps({'contract':'mad4b.visitor-load-admission.v1',
            'state':'SELFTEST_PASS','cases':len(cases)}))
        return 0
    load=os.getloadavg()[0]
    cpus=os.cpu_count() or 1
    wpcli=count_wpcli()
    decision=decide(load,cpus,wpcli)
    print(json.dumps({
       'contract':'mad4b.visitor-load-admission.v1',
       'decision':decision,'load1':round(load,2),
       'cpu_count':cpus,'active_wpcli':wpcli,
       'scope':'OPTIONAL_PUBLIC_CACHE_WARM_ONLY',
       'broker_authority_unchanged':True,
       'retry':'NEXT_TIMER' if decision!='ACCEPT' else 'NOW'
    },sort_keys=True),flush=True)
    # systemd ExecCondition: status 1 = skipped, not a failed unit.
    return 0 if decision=='ACCEPT' else 1

if __name__=='__main__':
    sys.exit(main())
