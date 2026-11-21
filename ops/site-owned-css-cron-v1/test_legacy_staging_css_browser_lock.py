#!/usr/bin/env python3
"""Probe must share original browser lock without changing CSS source or QA policy."""
from pathlib import Path
import re,subprocess,sys
p=Path(__file__).resolve().with_name('legacy-staging-css-probe-budgeted.sh')
s=p.read_text()
def check(text,value):
 assert value,text
 print('PASS',text)
r=subprocess.run(['/bin/sh','-n',str(p)],capture_output=True,text=True)
check('POSIX shell syntax',r.returncode==0)
check('source probe remains optional staging-only',
      '/opt/commerce-components/qa/mad4b-live-css-render-blocking.py' in s
      and 'mad4bbackground.slice' in s
      and '--property=CPUQuota=80%' in s)
check('unchanged CPU/io/memory PSI guards stay before Chrome launch',
      all(v in s for v in ("load>7","ps['cpu']>17","ps['io']>6","ps['memory']>0.6"))
      and s.index("if load>7")<s.index('/usr/bin/flock -n -E 75'))
check('legacy source QA must use same original CSS global browser lock',
      '/usr/bin/flock -n -E 75 /run/lock/mad4b-native-lazy-browser-global.lock' in s)
check('no waiting on original release, do not fight busy browser',
      "'MAD4B_STAGING_QA_DEFERRED original_css_browser_global_lock_busy'" in s
      and 'exit 0' in s)
check('legacy own Cron lock remains separate via caller',s.count('mad4b-native-lazy-browser-global.lock')==1)
check('same old 95 second timeout keeps subordinate browser bounded',
      '/usr/bin/timeout 95 /usr/bin/python3' in s)
check('wrapper never touches WP, CSS/422 or market cache',
      all(x not in s for x in ('wp --','wp-cli','wp option','docker exec','cache purge','nginx -s reload','pkill','killall','systemctl stop')))
print('PASS 8/8 staging-only global Chromium serialization tests')
