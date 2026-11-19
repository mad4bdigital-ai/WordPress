#!/usr/bin/env python3
"""Read-only trigger for true live EN/ES CSS changes. Never issues a certificate."""
import hashlib,json,os,re,sys,tempfile,time,importlib.util
from html.parser import HTMLParser
from pathlib import Path
from urllib.parse import urljoin,urlsplit
from urllib.request import Request,urlopen
HOST='wayfatours.com'
WP=Path('/opt/wayfa-rebuild-20261102/site-copy/wp-content')
REPORT=Path('/opt/mad4b-company-provisioning/reports/dynamic-critical-css-20261116/wayfa')
CONTRACT='mad4b.wayfa-home-css-change-observation.v1'
UA='Mozilla/5.0 (Linux; Android 14; Pixel 7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/157.0.0.0 Mobile Safari/537.36'
ACCEPT='text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,image/apng,*/*;q=0.8'
class Links(HTMLParser):
 def __init__(self):super().__init__();self.urls=[]
 def handle_starttag(self,tag,attrs):
  d=dict(attrs)
  if tag=='link' and d.get('data-optimized')=='2' and d.get('rel')=='stylesheet':
   self.urls.append(d.get('href',''))
def css_filename(html):
 p=Links();p.feed(html)
 if len(p.urls)!=1:raise ValueError('combined-CSS-link-not-unique')
 u=urlsplit(urljoin('https://'+HOST+'/',p.urls[0]))
 if u.scheme!='https' or u.hostname!=HOST or u.port not in (None,443) or u.fragment:
  raise ValueError('foreign-css-identity')
 name=u.path.removeprefix('/wp-content/litespeed/css/')
 if not re.fullmatch('[a-f0-9]{32}[.]css',name) or u.path!='/wp-content/litespeed/css/'+name:
  raise ValueError('invalid-public-css-path')
 return name
def inspect(lang,wp=WP,fetch=None):
 if lang not in ('en','es'):raise ValueError('unregistered-locale')
 route='/' if lang=='en' else '/es/'
 url='https://'+HOST+route
 if fetch is None:
  def fetch(url):
   with urlopen(Request(url,headers={'User-Agent':UA,'Accept':ACCEPT}),timeout=25) as r:
    if r.status!=200 or r.url!=url:raise ValueError('public-home-HTTP-context')
    return r.read(6000001)
 blob=fetch(url)
 if not isinstance(blob,bytes) or not 20000<=len(blob)<=6000000:
  raise ValueError('invalid-public-home-document')
 name=css_filename(blob.decode('utf-8',errors='replace'))
 source=wp/'litespeed/css'/name
 if source.is_symlink() or not source.is_file():source=wp/'litespeed/.mad4b-asset-retention/css'/name
 if source.is_symlink() or not source.is_file() or not 16000<=source.stat().st_size<=6291456:
  raise ValueError('public-CSS-source-not-retained')
 return {'route':route,'file':name,'sha256':hashlib.sha256(source.read_bytes()).hexdigest()}
def fingerprint(rows):
 if set(rows)!=set(('en','es')):raise ValueError('wrong-Wayfa-locales')
 for lang,row in rows.items():
  if (row.get('route')!=('/' if lang=='en' else '/es/')
      or not re.fullmatch('[a-f0-9]{32}[.]css',row.get('file',''))
      or not re.fullmatch('[a-f0-9]{64}',row.get('sha256',''))):
   raise ValueError('untrusted-CSS-observation')
 return hashlib.sha256(json.dumps(rows,sort_keys=True,separators=(',',':')).encode()).hexdigest()
def atomic(file,obj):
 if file.is_symlink() or file.parent.is_symlink():raise ValueError('observation-ledger-symlink')
 file.parent.mkdir(parents=True,exist_ok=True,mode=0o700)
 fd,temp=tempfile.mkstemp(prefix='.source-identity-',dir=file.parent)
 try:
  with os.fdopen(fd,'w') as f:
   os.fchmod(f.fileno(),0o600)
   json.dump(obj,f,sort_keys=True);f.write('\n');f.flush();os.fsync(f.fileno())
  os.replace(temp,file)
 finally:
  if os.path.exists(temp):os.unlink(temp)
def observation_cooldown(now):
 # Pure verified source readback; never issues a browser certificate.
 policy=Path(__file__).resolve().parent/'home-source-poll-policy-v2.py'
 if policy.is_symlink() or not policy.is_file() or policy.stat().st_size>16384:
  raise ValueError('untrusted-home-source-poll-policy')
 spec=importlib.util.spec_from_file_location('wayfa_source_poll_policy',policy)
 module=importlib.util.module_from_spec(spec);spec.loader.exec_module(module)
 return module.cooldown(now)
def observe(now=None):
 now=int(time.time() if now is None else now)
 state=json.loads((REPORT/'current-site.json').read_text())
 if (state.get('site_host')!=HOST or state.get('environment')!='production'
     or state.get('wpml_languages')!=['en','es']
     or state.get('critical_css')!='on' or state.get('native_breakpoint')!=767):
  raise ValueError('untrusted-Wayfa-site-source')
 ledger=REPORT/'home-current-css-source.json'
 if ledger.is_symlink():raise ValueError('observation-ledger-symlink')
 old=json.loads(ledger.read_text()) if ledger.is_file() else {}
 if old and (old.get('contract')!=CONTRACT or old.get('host')!=HOST):
  raise ValueError('foreign-observation-ledger')
 # A confirmed `CSS-Ready:0` lets source drift detection retry every
 # five minutes; healthy/untrusted context retains the original hour.
 if now-int(old.get('checked_at',0))<observation_cooldown(now):
  return 'cooled',old.get('fingerprint','')
 rows={l:inspect(l) for l in ('en','es')}
 key=fingerprint(rows)
 atomic(ledger,{'contract':CONTRACT,'host':HOST,'checked_at':now,
       'sources':rows,'fingerprint':key,
       'scope':'trigger-fresh-browser-QA-only-not-a-certificate'})
 return 'changed' if key!=old.get('fingerprint') else 'stable',key
if __name__=='__main__':
 try:
  if sys.argv[1:]!=['wayfa']:raise ValueError('wayfa-only')
  result,key=observe()
  print('WAYFA_HOME_CSS_SOURCE_SIGNAL',result,key[:16])
 except (OSError,ValueError,TypeError,KeyError,IndexError,json.JSONDecodeError) as exc:
  print('HOLD_WAYFA_HOME_CSS_OBSERVER',str(exc),file=sys.stderr);sys.exit(4)
