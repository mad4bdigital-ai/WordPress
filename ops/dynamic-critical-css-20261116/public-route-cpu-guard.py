#!/usr/bin/env python3
"""Read-only fail-closed admission for optional browser certification (no jobs launched)."""
import json,os,stat,time
from pathlib import Path
STATUS=Path('/run/mad4b-cpu-budget/status.json')


def decision(record, *,now=None,load=None):
    if not isinstance(record,dict) or record.get('contract')!='mad4b.host-cpu-budget.v1':
        return False,'cpu_status_contract_untrusted'
    now=int(time.time()) if now is None else int(now)
    stamp=record.get('sampled_epoch')
    if not isinstance(stamp,int) or isinstance(stamp,bool) or not -5<=now-stamp<=105:
        return False,'cpu_sample_stale'
    if record.get('cores')!=8:
        # Safeguard is enrolled specifically for this eight-vCPU VPS.
        return False,'cpu_host_topology_changed'
    for key in ('host_cpu_pct','cpu_steal_pct','load_1m','cpu_psi_some_avg10'):
        val=record.get(key)
        if isinstance(val,bool) or not isinstance(val,(int,float)) or not 0<=val<=1000:
            return False,'cpu_measurement_missing'
    active=os.getloadavg()[0] if load is None else load
    if isinstance(active,bool) or not isinstance(active,(int,float)) or not 0<=active<=10000:
        return False,'live_load_invalid'
    if record['host_cpu_pct']>=50 or record['load_1m']>=7 or active>=7:
        return False,'cpu_headroom_insufficient'
    if record['cpu_steal_pct']>=20:
        return False,'hypervisor_steal_pressure'
    if record['cpu_psi_some_avg10']>=25:
        return False,'cpu_contention_pressure'
    slots=record.get('wpcli_processes')
    if isinstance(slots,bool) or not isinstance(slots,int) or slots<0 or slots>=2:
        return False,'central_wpcli_slots_reserved'
    return True,'host_quiet_window'


def examine(path=STATUS):
    # The budget file is root-owned system telemetry, not a request parameter.
    # Open without following links and validate the same inode being read.
    if path.parent.is_symlink():
        return False,'cpu_status_parent_symlink'
    try:
        fd=os.open(path,os.O_RDONLY|os.O_NOFOLLOW|os.O_CLOEXEC)
    except OSError:
        return False,'cpu_status_absent_or_symlink'
    try:
        meta=os.fstat(fd)
        if (not stat.S_ISREG(meta.st_mode) or meta.st_uid!=0
            or meta.st_mode & 0o022 or meta.st_size>8192 or meta.st_size<50):
            return False,'cpu_status_untrusted_file'
        raw=os.read(fd,8193)
        if len(raw)!=meta.st_size:
            return False,'cpu_status_mutated_during_read'
        return decision(json.loads(raw))
    except (OSError,ValueError,TypeError,json.JSONDecodeError):
        return False,'cpu_status_read_failure'
    finally:
        os.close(fd)


if __name__=='__main__':
    approved,reason=examine()
    print(('ADMIT' if approved else 'DEFER')+'_PUBLIC_CCSS_HOST_CPU_BUDGET '+reason)
    raise SystemExit(0 if approved else 75)
