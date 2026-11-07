#!/usr/bin/env python3
"""Compile public WordPress URLs to an exact and bounded PHP allowlist.
No PHP execution from user-provided links and no path-based guessed access.
"""
import argparse,json,re,hashlib,os,tempfile
from pathlib import Path
BASE=Path('/opt/mad4b-company-provisioning/reports/runtime-policy-next-20261108')
DEFAULT=Path('/opt/hostinger-migrations/hosting-3/etg-commerce-staging/site-copy/wp-content/mu-plugins/assets/mad4b-public-route-registry-v1.php')
PRIVATE={'account','my-account','checkout','cart','payment','booking','bookings','order','orders','pay',
 'profile','dashboard','login','register','registration','favourites','favorite','favorites','wishlist',
 'wc-api','wp-json','wp-admin','wp-login.php','api','oauth','token','admin','callback','webhook',
 'thank-you','reset-password','lost-password'}
def eligible(path,record):
 if not path.startswith('/') or not path.endswith('/') or len(path)>360:return False
 if '?' in path or '#' in path or '\\' in path or any(c in path for c in ("'",'"','\n','\r','\0')):return False
 if path!='/' and not re.fullmatch(r'/[-%a-zA-Z0-9_./]+',path):return False
 pieces=path.lower().strip('/').split('/')
 if any(p in PRIVATE or p.startswith(('wc-api','wp-json','wp-admin','wp-login','payment-','booking-')) for p in pieces):return False
 if record['type'] not in {'page','post','tours-and-activities','tax:destinations','tax:category','tax:product_cat'}:return False
 return True
def main():
 ap=argparse.ArgumentParser()
 ap.add_argument('--inventory',default=str(BASE/'qa/public-inventory.json'))
 ap.add_argument('--out',default=str(DEFAULT))
 ap.add_argument('--dry-run',action='store_true')
 args=ap.parse_args()
 inv=json.loads(Path(args.inventory).read_text())
 assert inv['contract']=='mad4b.public-route-inventory.v1'
 routes=sorted(set(p.lower() for p,rec in inv['routes'].items() if eligible(p,rec)))
 assert len(routes)>100 and '/' in routes
 php="<?php\n/** MAD4B public route whitelist; generated from published WordPress content. */\ndefined('ABSPATH') || exit;\nreturn [\n"
 for p in routes:php+="    '"+p+"'=>true,\n"
 php+="];\n"
 report={'contract':'mad4b.public-route-registry-compiler.v1','source_count':len(inv['routes']),
         'eligible_count':len(routes),'excluded_count':len(inv['routes'])-len(routes),
         'sha256':hashlib.sha256(php.encode()).hexdigest(),'path':args.out}
 if not args.dry_run:
  target=Path(args.out)
  fd,tmp=tempfile.mkstemp(prefix='.mad4b-public-routes-',dir=target.parent)
  with os.fdopen(fd,'w') as f:
   f.write(php);f.flush();os.fsync(f.fileno())
  os.chmod(tmp,0o644);os.chown(tmp,0,0);os.replace(tmp,target)
 print(json.dumps(report))
if __name__=='__main__':main()
