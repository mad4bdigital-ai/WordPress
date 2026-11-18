#!/usr/bin/env python3
import importlib.util
from pathlib import Path
worker=Path(__file__).with_name('css-wpcron-worker.py')
spec=importlib.util.spec_from_file_location('mad4b_css_worker_profile_v5',worker)
w=importlib.util.module_from_spec(spec);spec.loader.exec_module(w)
def check(name,okay):
 assert okay,name
 print('PASS',name)
check('immutable v5 canonical worker release',
      'template-aware-css-wpcron-v6-20261118' in str(w.SOURCE))
check('original Source first_fold uses exact host-egress Chromium',
      w.TASKS['first_fold']('source')[:3]==[
         '/usr/bin/env','MAD4B_SOURCE_CSS_NETWORK_PROFILE=host','/usr/bin/bash'])
check('Wayfa first_fold stays original isolated container network',
      w.TASKS['first_fold']('wayfa')[0]=='/usr/bin/bash')
check('Source cannot take host scope for unrelated coverage tasks',
      all('MAD4B_SOURCE_CSS_NETWORK_PROFILE=host' not in w.TASKS[k]('source')
          for k in w.TASKS if k!='first_fold'))
check('no WordPress owned due queue changed',
      set(w.TASKS)=={'public','first_fold','families','visual','audit','retention'})
print('SOURCE_CSS_V6_DISPATCH_SAFETY 5')
