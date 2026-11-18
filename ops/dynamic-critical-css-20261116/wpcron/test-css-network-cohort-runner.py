#!/usr/bin/env python3
"""Original browser/publisher admission unchanged; network mode is source-only."""
from pathlib import Path
import subprocess,sys
p=Path(__file__).resolve().parents[1]/'run-site.sh'
s=p.read_text()
def check(name,v):
 assert v,name
 print('PASS',name)
check('three native browser paths get same network scope',
      s.count('docker run --rm "${BROWSER_NETWORK_ARGS[@]}" --user 0:0')==3)
check('source-only host mode',
      '"$SITE" == source' in s and 'HOLD_CSS_HOST_NETWORK_SOURCE_ONLY' in s)
check('network never adds OS capabilities',
      'BROWSER_NETWORK_ARGS=(--network host --cap-drop ALL)' in s
      and '--security-opt=no-new-privileges' in s)
check('defaults original bridge profile',
      'CSS_NETWORK_PROFILE="${MAD4B_SOURCE_CSS_NETWORK_PROFILE:-bridge}"' in s)
check('publisher still has original QA gates',
      'publish_step 1' in s and 'publish_step 0' in s
      and 'source-home-delivery-admission.py' in s
      and 'verify-source-revision.py' in s)
check('script parses',subprocess.run(['bash','-n',str(p)]).returncode==0)
r=subprocess.run(['bash',str(p),'wayfa'],env={'MAD4B_SOURCE_CSS_NETWORK_PROFILE':'host','PATH':'/usr/bin:/bin'},
                 stdout=subprocess.PIPE,stderr=subprocess.PIPE,timeout=12)
check('Wayfa cannot use host network profile',
      r.returncode==2 and b'HOLD_CSS_HOST_NETWORK_SOURCE_ONLY' in r.stderr)
print('SOURCE_CSS_NETWORK_COHORT_RUNNER_TESTS 7')
