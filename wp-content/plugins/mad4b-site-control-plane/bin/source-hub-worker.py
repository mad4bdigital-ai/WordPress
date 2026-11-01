#!/usr/bin/env python3
"""MAD4B native host-worker. Public HTTPS retrieval for exact site-approved sources only.
Reads the site-emitted private manifest; writes private, site-bound transport receipts.
Never writes WordPress posts, calendars, approvals, prices or options.
"""
import argparse,base64,hashlib,ipaddress,json,os,pathlib,requests,socket,tempfile,time
from urllib.parse import urlsplit
parser=argparse.ArgumentParser()
parser.add_argument('--vault',required=True,help='Private site vault directory mounted at /run/mad4b-local-vault/source-hub in WordPress')
parser.add_argument('--source',required=True)
args=parser.parse_args()
vault=pathlib.Path(args.vault).resolve(strict=True)
if not vault.is_dir() or vault.is_symlink():raise SystemExit('Invalid private source vault')
manifest=vault/'manifest.json'
if not manifest.is_file() or manifest.is_symlink() or manifest.stat().st_size>100000:raise SystemExit('No valid site-approved manifest')
m=json.loads(manifest.read_text())
if m.get('contract')!='mad4b.source-hub-worker-manifest.v1' or not m.get('site_uuid'):raise SystemExit('Manifest contract invalid')
s=m.get('sources',{}).get(args.source)
if not isinstance(s,dict) or s.get('id')!=args.source:raise SystemExit('Source not approved in MAD4B')
u=s.get('url','');parts=urlsplit(u);host=parts.hostname or ''
if parts.scheme!='https' or not host or parts.port or parts.username or parts.password or parts.fragment:raise SystemExit('Disallowed URL structure')
if hashlib.sha256(u.encode()).hexdigest()!=s.get('url_sha256'):raise SystemExit('URL binding mismatch')
try:
 addresses=socket.getaddrinfo(host,443,type=socket.SOCK_STREAM)
 if not addresses:raise ValueError('DNS empty')
 for entry in addresses:
  ip=ipaddress.ip_address(entry[4][0])
  if not ip.is_global:raise ValueError('Non-public IP address')
except (OSError,ValueError) as e:raise SystemExit('Source address not publicly routable: '+str(e))
session=requests.Session();session.trust_env=False
with session.get(u,timeout=(6,20),stream=True,allow_redirects=False,headers={'User-Agent':'MAD4B-SourceHub/1.0','Accept':'application/atom+xml,application/rss+xml,application/json,text/calendar,application/xml'}) as r:
 if r.status_code!=200:raise SystemExit('Provider HTTP '+str(r.status_code))
 body=bytearray()
 for chunk in r.iter_content(65536):
  body.extend(chunk)
  if len(body)>1500000:raise SystemExit('Source response over hard limit')
 if not body:raise SystemExit('Empty source response')
source_type=s.get('adapter')
if source_type=='nager_holidays' and not isinstance(json.loads(body),list):raise SystemExit('Provider JSON schema unexpected')
if source_type=='public_ical' and not body.startswith(b'BEGIN:VCALENDAR'):raise SystemExit('Provider iCalendar unexpected')
if source_type=='rss_atom' and b'<rss' not in body[:4000] and b'<feed' not in body[:4000] and b'<rdf:RDF' not in body[:4000]:raise SystemExit('RSS/Atom root unexpected')
receipt={'contract':'mad4b.source-fetch-receipt.v1','source_id':args.source,'site_uuid':m['site_uuid'],'source_revision':s['revision'],
  'url_sha256':s['url_sha256'],'fetched_at':int(time.time()),'body_sha256':hashlib.sha256(body).hexdigest(),
  'body_b64':base64.b64encode(body).decode('ascii')}
inbox=vault/'inbox';inbox.mkdir(mode=0o700,exist_ok=True)
if inbox.is_symlink():raise SystemExit('Spool directory is a symlink')
os.chown(inbox,vault.stat().st_uid,vault.stat().st_gid)
fd,temporary=tempfile.mkstemp(dir=inbox,prefix='.fetch-',suffix='.tmp')
try:
 with os.fdopen(fd,'w',encoding='utf-8') as f:json.dump(receipt,f,separators=(',',':'))
 os.chmod(temporary,0o600)
 os.chown(temporary,vault.stat().st_uid,vault.stat().st_gid)
 os.replace(temporary,inbox/(args.source+'.json'))
finally:
 if os.path.exists(temporary):os.unlink(temporary)
print(json.dumps({'source_id':args.source,'transport':'mad4b_private_local_spool','body_sha256':receipt['body_sha256'],
 'size':len(body),'fetched_at':receipt['fetched_at'],'publication_changes':0}))
