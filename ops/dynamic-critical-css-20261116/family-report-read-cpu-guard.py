#!/usr/bin/env python3
"""Bounded extra WP-CLI reader lane for original source-family reports ONLY.

Never admits browser CSS, public route issuance, publication, cache promotion,
or any WP write. Original strict browser CPU guard remains fully unchanged.
"""
import importlib.util,json,os,stat,sys,time
from pathlib import Path
D=Path(__file__).resolve().parent
STATUS=Path('/run/mad4b-cpu-budget/status.json')
def original():
    spec=importlib.util.spec_from_file_location('original_css_cpu_budget',D/'public-route-cpu-guard.py')
    o=importlib.util.module_from_spec(spec);spec.loader.exec_module(o);return o
def decision(record,*,now=None,load=None):
    now=int(time.time()) if now is None else int(now)
    current=os.getloadavg()[0] if load is None else load
    # The original browser budget is the single trusted resource ABI.
    # This exception is valid ONLY when it rejects a *second* existing
    # WP-CLI slot with otherwise quiet real CPU and clean attestation.
    ok,reason=original().decision(record,now=now,load=current)
    if ok:return True,'original_host_quiet_window'
    if reason!='central_wpcli_slots_reserved':
        return False,reason
    io=record.get('cpu_iowait_pct')
    idle=record.get('cpu_idle_pct')
    slots=record.get('wpcli_processes')
    if (type(slots) is not int or slots!=2
        or isinstance(io,bool) or not isinstance(io,(int,float))
        or isinstance(idle,bool) or not isinstance(idle,(int,float))
        or not 0<=io<=100 or not 0<=idle<=100):
        return False,'family_reader_budget_untrusted'
    if (record['host_cpu_pct']>=38 or idle<55
        or record['load_1m']>=5 or current>=5
        or record['cpu_psi_some_avg10']>=8
        or record['cpu_steal_pct']>=4 or io>=3):
        return False,'family_reader_requires_genuinely_quiet_host'
    return True,'bounded_second_wpcli_reader_only'
def examine(path=STATUS):
    if path.parent.is_symlink():return False,'family_cpu_parent_symlink'
    try:fd=os.open(path,os.O_RDONLY|os.O_NOFOLLOW|os.O_CLOEXEC)
    except OSError:return False,'family_cpu_budget_absent'
    try:
        st=os.fstat(fd)
        if (not stat.S_ISREG(st.st_mode) or st.st_uid!=0
            or st.st_mode&0o022 or not 50<=st.st_size<=8192):
            return False,'family_cpu_budget_unsafe'
        raw=os.read(fd,8193)
        if len(raw)!=st.st_size:return False,'family_cpu_budget_changed'
        return decision(json.loads(raw))
    except (ValueError,TypeError,OSError,json.JSONDecodeError):
        return False,'family_cpu_budget_invalid'
    finally:os.close(fd)
if __name__=='__main__':
    ok,reason=examine()
    print(('ADMIT' if ok else 'DEFER')+'_CSS_FAMILY_READ_ONLY '+reason,flush=True)
    sys.exit(0 if ok else 75)
