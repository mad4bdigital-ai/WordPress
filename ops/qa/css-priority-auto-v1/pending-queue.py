#!/usr/bin/env python3
"""Read WordPress dirty queue; do not invent or broaden paths."""
import subprocess,json,re,sys
cmd=['docker','exec','-u','www-data','-w','/var/www/html','etg-commerce-staging-php','wp','eval','echo wp_json_encode(MAD4B_SCP_Adaptive_Asset_Intelligence::queue());','--quiet']
r=subprocess.run(cmd,capture_output=True,text=True,timeout=30)
if r.returncode:raise SystemExit('QUEUE_UNAVAILABLE: '+r.stderr[:100])
rows=json.loads(r.stdout)
if not isinstance(rows,list):raise SystemExit('queue not a list')
for x in rows:
 p=x.get('path') if isinstance(x,dict) else None
 if isinstance(p,str) and re.fullmatch(r'/[A-Za-z0-9/_~.-]{0,239}',p) and '..' not in p and '//' not in p:
  print(p);sys.exit(0)
print('')
