#!/usr/bin/env python3
"""No WordPress/browser mutations: exact 421/422 legacy and new paired source pins."""
import importlib.util,shutil,tempfile,hashlib,pathlib,sys
from pathlib import Path
D=Path(sys.argv[1]);git=D.parent.parent
spec=importlib.util.spec_from_file_location('css_421_422_source_admission',D/'run-public-route.py')
m=importlib.util.module_from_spec(spec);spec.loader.exec_module(m)
checks=0
def ok(name,value):
 global checks
 assert value,name
 checks+=1;print('PASS',name)
def sha(p):return hashlib.sha256(p.read_bytes()).hexdigest()
live={
 'source':Path('/opt/hostinger-migrations/hosting-3/etg-commerce-staging/site-copy/wp-content'),
 'wayfa':Path('/opt/wayfa-rebuild-20261102/site-copy/wp-content')
}
with tempfile.TemporaryDirectory() as root:
 for site,path in live.items():
  temp=Path(root)/site;mu=temp/'mu-plugins';mu.mkdir(parents=True)
  for name in ('421-mad4b-public-critical-css-coverage.php',
               '422-mad4b-public-critical-css-runtime.php',
               '422-mad4b-public-critical-css-v2-consumer.php'):
   shutil.copy2(path/'mu-plugins'/name,mu/name)
  cfg=m.CONFIG[site]
  m.CONFIG[site]=(cfg[0],cfg[1],temp,cfg[3])
  native=m.approved_native_runtime_sha(site,temp)
  ok(site+' original firstparty native 421/422 source admitted',len(native)==64)
  approved=m.approved_v2_consumer_sha(site,temp,native)
  ok(site+' original 422 companion SHA allowed',approved==sha(mu/'422-mad4b-public-critical-css-v2-consumer.php'))
  shutil.copy2(git/'wp-content/mu-plugins/421-mad4b-public-critical-css-coverage.php',
               mu/'421-mad4b-public-critical-css-coverage.php')
  try:m.approved_native_runtime_sha(site,temp)
  except ValueError:ok(site+' mixed NEW 421 with OLD 422 companion is forbidden',True)
  else:raise AssertionError('unapproved mixed 421/422 state')
  shutil.copy2(git/'wp-content/mu-plugins/422-mad4b-public-critical-css-v2-consumer.php',
               mu/'422-mad4b-public-critical-css-v2-consumer.php')
  newer=m.approved_native_runtime_sha(site,temp)
  ok(site+' new strict firstparty 421+422 source pair admitted',newer==native)
  newcomp=m.approved_v2_consumer_sha(site,temp,newer)
  ok(site+' new 422 proof SHA matches exact original Git release',
    newcomp==sha(mu/'422-mad4b-public-critical-css-v2-consumer.php'))
  shutil.copy2(path/'mu-plugins/421-mad4b-public-critical-css-coverage.php',
               mu/'421-mad4b-public-critical-css-coverage.php')
  try:m.approved_native_runtime_sha(site,temp)
  except ValueError:ok(site+' mixed OLD 421 with NEW 422 companion is forbidden',True)
  else:raise AssertionError('unapproved reverse mixed state')
  shutil.copy2(git/'wp-content/mu-plugins/421-mad4b-public-critical-css-coverage.php',
               mu/'421-mad4b-public-critical-css-coverage.php')
  with (mu/'422-mad4b-public-critical-css-v2-consumer.php').open('ab') as f:
   f.write(b'\n/* unsigned edit */\n')
  try:m.approved_v2_consumer_sha(site,temp,newer)
  except ValueError:ok(site+' unsigned 422 companion mutation fails source-bound authorization',True)
  else:raise AssertionError('unapproved companion admitted')
print('PASS',checks,'original SOURCE and WAYFA firstparty 421/422 exact paired migration cases')
