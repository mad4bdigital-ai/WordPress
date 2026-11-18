#!/usr/bin/env python3
"""Permanent Form #6 source-backed presentation asset + public HTML reconciler.

No WordPress database writes, unsafe HTML mutation, or full-site purge.
Source-controlled canonical asset is authoritative; its immutable SHA-named
copy is served automatically. Each source-policy change versions approved
public routes via the enrolled company cache generation socket.
"""
import argparse
import datetime as dt
import hashlib
import importlib.util
import json
import os
from pathlib import Path
import re
import stat
import subprocess
import sys
import tempfile

ROOT=Path('/opt/mad4b-company-provisioning')
SITE_ROOTS={
 'source': (
  Path('/opt/hostinger-migrations/hosting-3/etg-commerce-staging/site-copy/wp-content'),
  'php-wkgtkzta35wkkgouwt6ruljc'
 ),
 'wayfa':(
  Path('/opt/wayfa-rebuild-20261102/site-copy/wp-content'),
  'php-guxhp9y6vgh7oouuw8plxrlr'
 )
}
BASENAME='presentation-fluent-quote-v1.js'
POLICY='presentation-form6-occupancy-policy-v1.php'
LOADER='local-presentation-fluent-projection-v1.php'
RATE_FOOTPRINT='410-mad4b-tour-rate-lazy-footprint.php'
STICKY_GUARD='407-mad4b-elementor-sticky-lifecycle-guard.php'
STICKY_ASSET='mad4b-elementor-sticky-lifecycle-c77a2b2fbdc39daa.js'

def verified_regular(path:Path):
 st=path.lstat()
 if not stat.S_ISREG(st.st_mode) or st.st_size<100 or st.st_size>1_000_000:
  raise RuntimeError('UNEXPECTED_SOURCE_FILE_TYPE_OR_SIZE:'+path.name)
 return path.read_bytes()

def process(cmd,content=None,limit=25):
 p=subprocess.run(cmd,input=content,stdout=subprocess.PIPE,
                  stderr=subprocess.PIPE,timeout=limit,check=False)
 if p.returncode:
  raise RuntimeError('SOURCE_SYNTAX_REJECTED:'+p.stderr.decode(errors='replace')[-270:])
 return p.stdout.decode(errors='replace')

def publish_asset(asset,source_bytes,digest):
 target=asset.with_name('presentation-fluent-quote-v1-'+digest[:16]+'.js')
 if target.exists():
  if hashlib.sha256(verified_regular(target)).hexdigest()!=digest:
   raise RuntimeError('EXISTING_CONTENT_ADDRESS_COLLISION')
  return target
 fd,tmp=tempfile.mkstemp(prefix='.mad4b-form6-immutable-',dir=asset.parent)
 try:
  os.fchmod(fd,0o644)
  with os.fdopen(fd,'wb') as out:
   out.write(source_bytes);out.flush();os.fsync(out.fileno())
  os.replace(tmp,target)
 finally:
  if os.path.exists(tmp):os.unlink(tmp)
 if hashlib.sha256(verified_regular(target)).hexdigest()!=digest:
  raise RuntimeError('PUBLISHED_ASSET_MISMATCH')
 return target

def cache_module():
 module_path=ROOT/'bin/company-pricing-cache-path-invalidation-v1.py'
 spec=importlib.util.spec_from_file_location('mad4b_existing_cache_versioning',module_path)
 module=importlib.util.module_from_spec(spec)
 spec.loader.exec_module(module)
 return module

def save_receipt(path,data):
 path.parent.mkdir(parents=True,exist_ok=True)
 fd,tmp=tempfile.mkstemp(prefix='.mad4b-form6-state-',dir=path.parent)
 try:
  os.fchmod(fd,0o600)
  with os.fdopen(fd,'w') as fh:
   json.dump(data,fh,sort_keys=True,indent=2);fh.write('\n')
   fh.flush();os.fsync(fh.fileno())
  os.replace(tmp,path)
 finally:
  if os.path.exists(tmp):os.unlink(tmp)

def run(site):
 content,php=SITE_ROOTS[site]
 assets=content/'mu-plugins/shared-product-core/assets'
 source=assets/BASENAME
 policy=content/'mu-plugins/shared-product-core/adapters'/POLICY
 loader=content/'plugins/mad4b-commerce/modules/compat'/LOADER
 rate_footprint=content/'mu-plugins'/RATE_FOOTPRINT
 sticky_guard=content/'mu-plugins'/STICKY_GUARD
 sticky_asset=content/'mu-plugins/assets'/STICKY_ASSET
 code=[verified_regular(f) for f in (
  source,policy,loader,rate_footprint,sticky_guard,sticky_asset)]
 js,pl,ph,rate_php,sticky_php,sticky_js=code
 digest=hashlib.sha256(js).hexdigest()
 composite=hashlib.sha256(b'\0'.join(code)).hexdigest()
 if b"shared.form6.stay-ui.v1" not in pl or b"shared.form6.stay-ui.v1" not in js:
  raise RuntimeError('SERVER_CLIENT_POLICY_CONTRACT_MISSING')
 if b"presentation-fluent-quote-v1-" not in ph or b"hash_file('sha256'" not in ph:
  raise RuntimeError('IMMUTABLE_ASSET_LOADER_CONTRACT_MISSING')
 if b'mad4b-rate-lazy-footprint-v1' not in rate_php or b'jet-engine-query-count' not in rate_php:
  raise RuntimeError('RATE_LAZY_PRESENTATION_CONTRACT_MISSING')
 if b'mad4b_tour_sticky_guard_asset' not in sticky_php or b'MAD4BStickyLifecycleGuard' not in sticky_js:
  raise RuntimeError('STICKY_LIFECYCLE_CONTRACT_MISSING')
 process(['/usr/local/bin/node','--check',str(source)])
 process(['/usr/local/bin/node','--check',str(sticky_asset)])
 for php_source in (pl,ph,rate_php,sticky_php):
  process(['docker','exec','-i',php,'php','-l'],php_source)
 published=publish_asset(source,js,digest)
 state=ROOT/'reports/form6-permanent-assets'/f'{site}.json'
 before={}
 if state.exists():
  before=json.loads(state.read_text())
 if before.get('source_composite_sha256')==composite and before.get('status')=='PASS':
  if before.get('asset_sha256')==digest:
   print(json.dumps({'site':site,'status':'UNCHANGED','asset':published.name}))
   return
 old_revision=before.get('source_composite_sha256')
 mod=cache_module()
 inventory=mod.routes(site)
 total,batches=mod.version(site,inventory['public_paths'])
 if total!=len(inventory['public_paths']):
  raise RuntimeError('PUBLIC_HTML_GENERATION_INCOMPLETE')
 record={
  'contract':'mad4b.form6.content-addressed-assets-and-public-route-invalidation.v1',
  'site':site,'status':'PASS','asset':published.name,'asset_sha256':digest,
  'source_composite_sha256':composite,'previous_source_composite_sha256':old_revision,
  'paths_versioned':total,'batch_count':batches,
  'observed_utc':dt.datetime.now(dt.timezone.utc).isoformat(),
  'scope':'public_source_owned_routes_only',
  'writes_wordpress_or_payment_records':False
 }
 save_receipt(state,record)
 print(json.dumps(record,sort_keys=True))

def main():
 ap=argparse.ArgumentParser()
 ap.add_argument('site',choices=SITE_ROOTS)
 args=ap.parse_args()
 try:run(args.site);return 0
 except Exception as e:
  print(json.dumps({'site':args.site,'status':'FAILED_RETRYABLE',
   'error_type':type(e).__name__,'error_code':str(e).split(':')[0]}),
   file=sys.stderr)
  return 2
if __name__=='__main__':
 sys.exit(main())
