from pathlib import Path
import sys
P=Path(sys.argv[1]);specs={
 'mad4b-wayfa-home-cache-warm.timer.d-90-quiet-window.conf':{'OnUnitInactiveSec=5min','RandomizedDelaySec=90s','AccuracySec=30s'},
 'mad4b-wayfa-mobile-prime.timer.d-90-quiet-window.conf':{'OnUnitInactiveSec=15min','RandomizedDelaySec=180s','AccuracySec=30s'},
 'mad4b-wayfa-home-cache-warm.service.d-90-quiet-window.conf':{'ExecCondition=/usr/bin/python3 /usr/local/libexec/mad4b-visitor-load-admission.py','Nice=15','IOSchedulingClass=idle','CPUWeight=10','IOWeight=10','CPUQuota=60%'},
 'mad4b-wayfa-mobile-prime.service.d-90-quiet-window.conf':{'ExecCondition=/usr/bin/python3 /usr/local/libexec/mad4b-visitor-load-admission.py','Nice=15','IOSchedulingClass=idle','CPUWeight=10','IOWeight=10','CPUQuota=60%'}
}
for name,required in specs.items():
 s=(P/'systemd'/name).read_text();lines=[x.strip() for x in s.splitlines()]
 assert all(x in lines for x in required),name
 assert 'ExecStart=' not in s and 'SuccessExitStatus=' not in s and 'Environment=' not in s,name
 if '.timer.' in name:
  assert lines.count('OnUnitInactiveSec=')==1
 else:
  assert '[Service]' in s and 'ExecCondition=' in s and '/usr/local/libexec/mad4b-visitor-load-admission.py' in s
 print('PASS',name)
assert len(specs)==4
print('PASS 4/4 scoped low-load systemd drop-in contracts')
