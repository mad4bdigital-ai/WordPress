#!/usr/bin/env python3
"""Hermetic CPU-admitted native CSS window policy, no live WordPress/browser."""
from pathlib import Path
import json,os,re,subprocess,sys,tempfile
ROOT=Path(__file__).resolve().parent/'releases';tests=0
for site in ('source','wayfa'):
 original=(ROOT/(site+'-quiet-window-wake-20261123.sh')).read_text()
 assert 'public-route-cpu-guard.py' in original
 assert '/run/lock/' not in original # Locks owned by the original /etc/cron.d, not the launcher.
 with tempfile.TemporaryDirectory() as tmp:
  base=Path(tmp);(base/'renderer').mkdir()
  guard=base/'renderer/public-route-cpu-guard.py'
  worker=base/'worker.py';marker=base/'worker-ran'
  worker.write_text("from pathlib import Path\nPath("+repr(str(marker))+").write_text('ORIGINAL_NATIVE_WORKER_ONLY')\n")
  launcher=base/'quiet-window-wake.sh'
  patched=re.sub(r"BASE='[^']+'", "BASE='"+str(base)+"'",original)
  assert patched!=original
  launcher.write_text(patched);launcher.chmod(0o700)
  for mode in ('defer','admit'):
   guard.write_text('import sys\n'
       +("print('DEFER_PUBLIC_CCSS_HOST_CPU_BUDGET central_wpcli_slots_reserved');sys.exit(75)\n"
          if mode=='defer' else
          "print('ADMIT_PUBLIC_CCSS_HOST_CPU_BUDGET host_quiet_window')\n"))
   for args in (['--inspect'],[]):
    result=subprocess.run(['/bin/sh',str(launcher),*args],capture_output=True,text=True,timeout=9)
    assert result.returncode==0,(site,mode,args,result)
    assert (('CSS_QUIET_WINDOW_DEFER' in result.stdout) if mode=='defer'
            else ('CSS_QUIET_WINDOW_INSPECT_ADMITTED' in result.stdout if args else 'CSS_QUIET_WINDOW_NATIVE_WORKER_BEGIN' in result.stdout)),(site,mode,args,result.stdout)
    assert marker.exists()==(mode=='admit' and not args),(site,mode,args)
    tests+=1
    if marker.exists():marker.unlink()
   print('PASS',site,'strict resource guard',mode,'observed worker execution only after admit')
  result=subprocess.run(['/bin/sh',str(launcher),'bad-token'],capture_output=True,text=True,timeout=8)
  assert result.returncode==64 and not marker.exists()
  tests+=1
  guard.unlink()
  result=subprocess.run(['/bin/sh',str(launcher)],capture_output=True,text=True,timeout=8)
  assert result.returncode==65 and not marker.exists()
  tests+=1
  print('PASS',site,'fail-closed on incorrect arguments and missing governor')
print('PASS',tests,'/',tests,'Hermetic site-owned quiet-window governor checks')
