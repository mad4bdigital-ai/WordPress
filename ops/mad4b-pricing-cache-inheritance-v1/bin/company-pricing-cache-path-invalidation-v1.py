#!/usr/bin/env python3
"""Receive the company-owned WordPress pricing signal, then bump only public
URL cache generations via existing bounded host-owned Unix socket.
No secrets/quote records, SQL writes, Redis flushes or host shell payloads.
"""
import argparse,datetime,hashlib,json,os,pathlib,re,socket,stat,subprocess,sys,tempfile,time
ROOT=pathlib.Path('/opt/mad4b-company-provisioning')
SITES={
 'source':{
  'content':pathlib.Path('/opt/hostinger-migrations/hosting-3/etg-commerce-staging/site-copy/wp-content'),
  'php':'php-wkgtkzta35wkkgouwt6ruljc','host':'tours.dev.mad4b.com',
  'min_published':200},
 'wayfa':{
  'content':pathlib.Path('/opt/wayfa-rebuild-20261102/site-copy/wp-content'),
  'php':'php-guxhp9y6vgh7oouuw8plxrlr','host':'wayfatours.com',
  'min_published':75}
}
def atomic(path,data):
 path.parent.mkdir(parents=True,exist_ok=True)
 fd,tmp=tempfile.mkstemp(prefix='.mad4b-pricing-receipt-',dir=path.parent)
 with os.fdopen(fd,'w') as f:
  json.dump(data,f,ensure_ascii=False,sort_keys=True,indent=2)
  f.write('\n');f.flush();os.fsync(f.fileno())
 os.chmod(tmp,0o600)
 os.replace(tmp,path)
def safe_event(path):
 try:
  st=path.lstat()
  if not stat.S_ISREG(st.st_mode) or st.st_size>2048:return None
  raw=json.loads(path.read_text())
 except (FileNotFoundError,ValueError,OSError):return None
 if not isinstance(raw,dict) or raw.get('contract')!='mad4b.pricing-cache-policy-change.v1':return None
 if not isinstance(raw.get('event_id'),str) or not re.fullmatch(r'[0-9a-f-]{36}',raw['event_id']):return None
 if raw.get('scope')!='published_public_price_surfaces':return None
 if not isinstance(raw.get('reason_classes'),list) or not raw['reason_classes'] or len(raw['reason_classes'])>5:return None
 allowed={'pricing_policy_option','priced_product_meta','priced_product_save','pricing_taxonomy'}
 if any(x not in allowed for x in raw['reason_classes']):return None
 return raw
def routes(site):
 config=SITES[site]
 # Source code identical to the validated WP-CLI report; never accept shell
 # arguments from an HTTP request or the site-local change signal.
 cmd=['docker','exec','-w','/var/www/html',config['php'],'wp','eval-file',
      '/var/www/html/wp-content/mu-plugins/assets/mad4b-pricing-cache-public-paths.php','--allow-root','--skip-themes']
 r=subprocess.run(cmd,capture_output=True,text=True,timeout=150,check=False)
 if r.returncode:
  raise RuntimeError('WP_CLI_PUBLIC_ROUTE_INVENTORY_FAILED:'+r.stderr[-140:])
 lines=[s for s in r.stdout.splitlines() if s.startswith('MAD4B_PRICE_PATHS=')]
 if len(lines)!=1:raise RuntimeError('WP_CLI_PUBLIC_ROUTE_RECEIPT_MISSING')
 inv=json.loads(lines[0].split('=',1)[1])
 if inv.get('contract')!='mad4b.pricing-cache-public-paths.v1':
  raise RuntimeError('WRONG_PUBLIC_ROUTES_CONTRACT')
 if inv.get('source_host')!=config['host']:raise RuntimeError('FOREIGN_ORIGIN_REJECTED')
 count=inv.get('published_tours')
 paths=inv.get('public_paths')
 if not isinstance(count,int) or count<config['min_published'] or not isinstance(paths,list) or not count<=len(paths)<=2000:
  raise RuntimeError('UNSAFE_OR_INCOMPLETE_PUBLIC_ROUTES')
 if len(paths)!=len(set(paths)):raise RuntimeError('DUPLICATE_PUBLIC_ROUTES')
 for p in paths:
  if not isinstance(p,str) or len(p)>500 or not p.startswith('/') or '?' in p or '#' in p or '|' in p or '..' in p or not re.fullmatch(r'/[A-Za-z0-9%._/-]*',p):
   raise RuntimeError('INVALID_PUBLIC_ROUTE')
  if re.search(r'/(?:cart|checkout|account|my-account|favourites|wp-admin|wp-json|booking|login)(?:/|$)',p,re.I):
   raise RuntimeError('PRIVATE_PATH_IN_SOURCE_INVENTORY')
 return inv
def version(site,paths):
 path=SITES[site]['content']/'.mad4b-purge/origin.sock'
 if not path.exists():raise RuntimeError('ORIGIN_VERSION_SOCKET_MISSING')
 total=0;batches=0
 for start in range(0,len(paths),100):
  batch=paths[start:start+100]
  with socket.socket(socket.AF_UNIX,socket.SOCK_STREAM) as conn:
   conn.settimeout(10);conn.connect(str(path))
   data=json.dumps(batch,ensure_ascii=False,separators=(',',':')).encode()+b'\n'
   if len(data)>64000:raise RuntimeError('BATCH_OVER_64K')
   conn.sendall(data)
   conn.shutdown(socket.SHUT_WR)
   buffer=b''
   while b'\n' not in buffer and len(buffer)<4096:
    chunk=conn.recv(4096)
    if not chunk:break
    buffer+=chunk
  reply=json.loads(buffer.split(b'\n')[0])
  if reply.get('status')!='ok' or reply.get('versioned')!=len(batch):
   raise RuntimeError('VERSION_SOCKET_INVALID_ACK')
  total+=reply['versioned'];batches+=1
 return total,batches
def main():
 arg=argparse.ArgumentParser()
 arg.add_argument('site',choices=SITES)
 arg.add_argument('--dry-run',action='store_true')
 a=arg.parse_args()
 cfg=SITES[a.site]
 signal=cfg['content']/'.mad4b-pricing-policy-change.signal'
 receipt_path=ROOT/'reports'/('company-pricing-cache-invalidation-'+a.site+'.json')
 event=safe_event(signal)
 old=json.loads(receipt_path.read_text()) if receipt_path.exists() else {}
 if not a.dry_run and event is None:
  print(json.dumps({'site':a.site,'status':'NO_SIGNED_SITE_CHANGE'}));return 0
 if not a.dry_run and old.get('event_id')==event['event_id'] and old.get('status')=='PASS':
  print(json.dumps({'site':a.site,'status':'ALREADY_APPLIED','event_id':event['event_id']}));return 0
 started=time.monotonic()
 receipt={'contract':'mad4b.pricing-cache-path-invalidation.v1',
   'site':a.site,'origin':cfg['host'],'observed_utc':datetime.datetime.now(datetime.timezone.utc).isoformat(),
   'event_id':event.get('event_id') if event else None,
   'reason_classes':event.get('reason_classes') if event else [],
   'status':'PENDING','paths_attempted':0,'paths_versioned':0,
   'dry_run':bool(a.dry_run)}
 try:
  inv=routes(a.site)
  receipt['paths_attempted']=inv['count']
  receipt['published_tours']=inv['published_tours']
  receipt['languages']=inv['counts_by_locale']
  if a.dry_run:
   receipt['status']='DRY_RUN_PASS'
  else:
   total,batches=version(a.site,inv['public_paths'])
   receipt['paths_versioned']=total
   receipt['batches']=batches
   receipt['status']='PASS'
 except Exception as exc:
  receipt['status']='FAILED_RETRYABLE'
  receipt['error_type']=type(exc).__name__
  receipt['error_code']=str(exc).split(':')[0][:100]
 finally:
  receipt['duration_ms']=round((time.monotonic()-started)*1000,1)
  if not a.dry_run:atomic(receipt_path,receipt)
  print(json.dumps(receipt,ensure_ascii=False))
 return 0 if receipt['status'] in ('PASS','DRY_RUN_PASS') else 2
if __name__=='__main__':sys.exit(main())
