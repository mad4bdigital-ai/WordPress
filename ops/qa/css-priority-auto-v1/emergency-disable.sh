#!/usr/bin/env bash
# Emergency rollback for autonomous CSS on tours.dev staging. Use plan or apply.
set -euo pipefail
Q=/opt/hostinger-migrations/hosting-3/etg-commerce-staging/qa/css-priority-auto-v1
M=/opt/hostinger-migrations/hosting-3/etg-commerce-staging/site-copy/wp-content/mu-plugins/mad4b-css-autonomous-policy.json
MODE="${1:-plan}"
[ "$MODE" = plan ] || [ "$MODE" = apply ] || { echo 'Usage: emergency-disable.sh plan|apply';exit 2; }
python3 "$Q/policy-engine.py" status
if [ "$MODE" = plan ];then echo 'PLAN: disable scheduled local cycle; expire all certified/candidate CSS route policies, original CSS remains intact';exit 0;fi
exec 8>/run/lock/mad4b-autonomous-css.lock
flock -w 20 8 || { echo 'FAIL: active profiling cycle; do not race it';exit 7; }
cp -p "$M" "$Q/emergency-backup-$(date -u +%Y%m%dT%H%M%SZ).json"
crontab -l | grep -v 'MAD4B_CSS_AUTONOMOUS_STAGING_LOCAL_V1' | crontab -
python3 - "$M" <<'PY'
import sys,json,tempfile,os,time
p=sys.argv[1];m=json.load(open(p));assert m['site_host']=='tours.dev.mad4b.com' and m['site_uuid']=='e5e64bca-ed62-48d2-b2b3-dff693cd702a'
for x in m['routes'].values():x.update({'status':'hold','expires':0,'issues':['emergency_disabled']})
fd,name=tempfile.mkstemp(dir=os.path.dirname(p),prefix='.emergency-css-');os.write(fd,(json.dumps(m,indent=2)+'\n').encode());os.fchmod(fd,0o640);os.fchown(fd,0,33);os.close(fd);os.replace(name,p)
PY
python3 "$Q/policy-engine.py" status
echo 'LOCAL_CSS_ROLLBACK_COMPLETE; native site styles automatically resume'
