#!/usr/bin/env python3
"""MAD4B company-scoped mobile FastCGI cache primer; public routes only."""
import argparse,hashlib,json,os,re,sys,time,tempfile
from pathlib import Path
from urllib.request import Request,urlopen
from urllib.parse import urlparse

ROOT=Path('/opt/mad4b-company-provisioning')
HOST_ROOT=Path('/opt')
UA='Mozilla/5.0 (Linux; Android 14; Pixel 7) AppleWebKit/537.36 Chrome/157.0.0.0 Mobile Safari/537.36'
def persist(path,value):
 path.parent.mkdir(parents=True,exist_ok=True)
 fd,tmp=tempfile.mkstemp(prefix='.prime-',dir=path.parent)
 try:
  with os.fdopen(fd,'w')as f:
   json.dump(value,f);f.flush();os.fsync(f.fileno())
  os.chmod(tmp,0o600);os.replace(tmp,path)
 finally:
  if os.path.exists(tmp):os.unlink(tmp)
# Trusted final WordPress evidence, as authorized by the original Nginx
# mobile-home CSS gate. This is distinct from a successful Critical CSS cert.
READY_HEADER='X-Mad4b-Mobile-Css-Ready'
NATIVE_HEADER='X-Mad4b-Mobile-Css-Native-Complete'
NATIVE_LINK=re.compile(r'<link\b[^>]*>',re.I)
ATTR=re.compile(r'\b([a-zA-Z][a-zA-Z0-9-]*)\s*=\s*(["\x27])([^"\x27]*)\2')
BUNDLE=re.compile(r'/wp-content/litespeed/css/([a-f0-9]{32}\.css)\Z')

def admit_css(body,headers,css_manifest,origin):
 """Return exact admitted CSS mode or refuse; never synthesize critical CSS.

 Proof is the original WordPress final-buffer header, not the public cache
 HIT state alone. Confirm physical full LiteSpeed asset when native mode is
 asserted; the normal optimizer uses its exact content MD5 as filename.
 """
 ready=headers.get(READY_HEADER,'')
 native=headers.get(NATIVE_HEADER,'')
 first=body.count('id="mad4b-critical-css-v1"')
 rest=body.count('id="mad4b-css-rest-v1"')
 loader=body.count('data-mad4b-css-async-lib="inline-v1"')
 if ready=='1' and native in ('0','') and (first,rest,loader)==(1,1,1):
  if len(body)<25000 or '</head>' not in body:
   raise RuntimeError('css_critical_incomplete')
  # The site-owned certifier supports two original, exact HTML contracts:
  # all-width CCSS with media=print fallback, and a phone-only <=767px
  # certificate that must keep native full CSS render-blocking at >=768px.
  # The old primer recognized only the first, falsely HOLDing valid pages.
  style=re.search(r'<style id="mad4b-critical-css-v1"( media="\(max-width:767px\)")?>',body)
  if style is None:
   raise RuntimeError('css_critical_style_contract')
  start=style.end()
  end=body.find('</style>',start)
  if end<0 or not 16000<=end-start<=1048576:
   raise RuntimeError('css_critical_incomplete')
  tags=[tag for tag in NATIVE_LINK.findall(body)
        if re.search(r'\bid="mad4b-css-rest-v1"',tag)]
  if len(tags)!=1:
   raise RuntimeError('css_critical_async_link_count')
  attrs={k.lower():v for k,quote,v in ATTR.findall(tags[0])}
  u=urlparse(attrs.get('href',''))
  if (attrs.get('rel','').lower()!='stylesheet' or
      u.scheme!='https' or u.netloc!=urlparse(origin).netloc or
      BUNDLE.fullmatch(u.path) is None or 'onload' not in attrs):
   raise RuntimeError('css_critical_async_source')
  expected='(min-width:768px)' if style.group(1) else 'print'
  if attrs.get('media')!=expected:
   raise RuntimeError('css_critical_breakpoint_mismatch')
  return 'critical_verified'
 if ready!='0' or native!='1' or (first,rest,loader)!=(0,0,0):
  raise RuntimeError('css_unverified_origin_proof')
 if len(body)<25000 or len(body)>6000000 or '</head>' not in body:
  raise RuntimeError('css_native_document_incomplete')
 matches=[]
 for tag in NATIVE_LINK.findall(body):
  attrs={k.lower():v for k,quote,v in ATTR.findall(tag)}
  if attrs.get('rel','').lower()!='stylesheet' or not attrs.get('href'):
   continue
  url=urlparse(attrs['href'])
  if url.scheme!='https' or url.netloc!=urlparse(origin).netloc:
   continue
  m=BUNDLE.fullmatch(url.path)
  if not m:continue
  if 'onload' in attrs or attrs.get('media','all').lower()!='all':
   raise RuntimeError('css_native_not_render_blocking')
  matches.append(m.group(1))
 if len(matches)!=1:raise RuntimeError('css_native_bundle_count')
 css_root=css_manifest.parent.parent
 if css_root.is_symlink() or not css_root.is_dir():raise RuntimeError('css_root')
 file=css_root/'litespeed/css'/matches[0]
 if not file.is_file() or file.is_symlink():
  file=css_root/'litespeed/.mad4b-asset-retention/css'/matches[0]
 if (not file.is_file() or file.is_symlink() or
     not 100000<=file.stat().st_size<=6291456):
  raise RuntimeError('css_native_asset_missing')
 contents=file.read_bytes()
 if hashlib.md5(contents).hexdigest()!=matches[0][:-4]:
  raise RuntimeError('css_native_asset_hash')
 return 'native_full_verified'

def css_certification_state(modes):
 """Never mistake a verified native fallback for permanent Critical CSS.

 A complete native stylesheet can be a temporary privacy-safe FastCGI
 variant, but cannot complete a CCSS+inline-async rollout. This state is
 nonauthorizing; the original per-site Chromium worker alone issues proof.
 """
 if not isinstance(modes,dict) or not modes:
  raise ValueError('empty_css_modes')
 allowed={'critical_verified','native_full_verified'}
 if any(mode not in allowed for mode in modes.values()):
  raise ValueError('untrusted_css_mode')
 return 'ready' if all(mode=='critical_verified' for mode in modes.values()) else 'awaiting_ccss'

def run(company):
 if not re.fullmatch(r'[a-z][a-z0-9_-]{1,63}',company):raise ValueError('bad_company')
 pol=ROOT/'policies/cache-mobile-prime-v1'/f'{company}.json'
 if pol.is_symlink() or not pol.is_file() or pol.stat().st_size>12000:raise ValueError('policy')
 p=json.loads(pol.read_text())
 if p.get('company')!=company or p.get('contract')!='mad4b.mobile-cache-prime.v1' or p.get('enabled') is not True:raise ValueError('not_enrolled')
 if not re.fullmatch(r'https://[a-z0-9.-]+',p.get('origin','')):raise ValueError('origin')
 routes=p.get('routes')
 if not isinstance(routes,list) or not 1<=len(routes)<=6:raise ValueError('routes')
 origin_header=p.get('public_page_header')
 if not isinstance(origin_header,str) or not re.fullmatch(r'X-[A-Za-z0-9-]{4,60}',origin_header):
  raise ValueError('public_origin_header')
 if p.get('public_page_value')!='1':raise ValueError('untrusted_public_proof_value')
 header=p.get('cache_header')
 if not isinstance(header,str) or not re.fullmatch(r'X-[A-Za-z0-9-]{4,60}',header):
  raise ValueError('cache_header')
 if len({(row.get('path'),row.get('class')) for row in routes})!=len(routes):
  raise ValueError('duplicate_variants')
 gen=Path(p['generation_root']);conf=Path(p['nginx_config'])
 if not gen.is_dir() or not conf.is_file() or gen.is_symlink() or conf.is_symlink():raise ValueError('owner')
 if not gen.resolve().is_relative_to(HOST_ROOT) or not conf.resolve().is_relative_to(HOST_ROOT):
  raise ValueError('scope')
 css=Path(p['css_manifest'])
 if not css.is_file() or css.is_symlink() or not css.resolve().is_relative_to(HOST_ROOT):
  raise ValueError('css_manifest_not_bound')
 parts=[]
 for row in routes:
  path=row['path'];kind=row['class']
  if not re.fullmatch(r'/(?:[a-z]{2}/)?',path) or kind not in ('anonymous','locale_seen','wpml','tracking'):raise ValueError('route')
  if kind=='locale_seen' and path!='/':raise ValueError('root_redirect_scope')
  marker=gen/path.strip('/')/'.generation'
  st=marker.stat()
  parts.append((path,st.st_ino,st.st_mtime_ns,st.st_size))
 parts.append(hashlib.sha256(conf.read_bytes()).hexdigest())
 parts.append(hashlib.sha256(Path(p['css_manifest']).read_bytes()).hexdigest())
 digest=hashlib.sha256(json.dumps(parts).encode()).hexdigest()
 state=ROOT/'runtime-state/cache-mobile-prime-v1'/f'{company}.json'
 prev=json.loads(state.read_text()) if state.exists() else {}
 if (prev.get('digest')==digest and prev.get('state')=='ready'
     and prev.get('css_modes') and prev.get('primer_contract')=='v2'
     and css_certification_state(prev['css_modes'])=='ready'):
  return {'state':'UNCHANGED_SKIP','company':company,
    'css_modes':prev['css_modes'],'css_certified':all(
     v=='critical_verified' for v in prev['css_modes'].values())}
 if time.time()-prev.get('attempt',0)<15:
  return {'state':'BOUNDED_DEFER','company':company}
 persist(state,{'state':'attempt','digest':digest,'attempt':time.time()})
 start=time.monotonic()
 modes={}
 for row in routes:
  path=row['path'];kind=row['class']
  locale=path.strip('/')or'en'
  cookie={'anonymous':'','locale_seen':'mad4b_locale_seen=1',
          'wpml':'wp-wpml_current_language='+locale,
          'tracking':'_ga=GA1.1.110000000.110000000'}[kind]
  for attempt in range(2):
   h={'User-Agent':UA,'Accept':'text/html,application/xhtml+xml','Accept-Encoding':'identity'}
   if cookie:h['Cookie']=cookie
   req=Request(p['origin']+path,headers=h)
   with urlopen(req,timeout=22)as r:
    if r.status!=200 or r.geturl()!=p['origin']+path:raise RuntimeError('http')
    status=r.headers.get(header,'')
    # A cookie-safe *browser* variant can be private while the origin's
    # WordPress + Nginx contract explicitly certifies it as shared-public.
    # Never treat a private/no-store origin as shareable; never trust a
    # user-supplied header as an origin certification.
    cc=r.headers.get('Cache-Control','').lower()
    if (r.headers.get(origin_header)!=p['public_page_value']
        or r.headers.get('Set-Cookie')
        or 'no-store' in cc or 'no-cache' in cc
        or 'max-age=0' in cc or ('private' in cc and not cookie)):
     raise RuntimeError('origin_not_cache_eligible')
    body=r.read(5*1024*1024).decode('utf-8','replace')
    # Final CSS proof comes from the original WP/NGINX contract, even when
    # the edge serves an earlier fully verified cache HIT.
    css_mode=admit_css(body,r.headers,css,p['origin'])
   modes[path+'|'+kind]=css_mode
   if status in ('HIT','STALE','REVALIDATED'):break
   if status!='MISS':raise RuntimeError('not_public')
   if attempt==1:raise RuntimeError('repeated_miss')
  if time.monotonic()-start>75:raise RuntimeError('over_budget')
 if parts != _snapshot(gen,conf,routes,p["css_manifest"]):raise RuntimeError('changed_during_prime')
 phase=css_certification_state(modes)
 persist(state,{'state':phase,'digest':digest,'attempt':time.time(),
    'routes':len(routes),'primer_contract':'v2','css_modes':modes,
    'css_certified':phase=='ready'})
 return {'state':'READY' if phase=='ready' else 'AWAITING_CCSS',
    'company':company,'routes':len(routes),'css_modes':modes,
    'css_certified':phase=='ready','duration_s':round(time.monotonic()-start,2)}
def _snapshot(gen,conf,routes,policy_css_manifest):
 parts=[]
 for row in routes:
  path=row['path'];st=(gen/path.strip('/')/'.generation').stat()
  parts.append((path,st.st_ino,st.st_mtime_ns,st.st_size))
 parts.append(hashlib.sha256(conf.read_bytes()).hexdigest())
 parts.append(hashlib.sha256(Path(policy_css_manifest).read_bytes()).hexdigest())
 return parts
def main():
 ap=argparse.ArgumentParser();ap.add_argument('--company',required=True);args=ap.parse_args()
 try:out=run(args.company)
 except Exception as e:out={'state':'HOLD','company':args.company,'reason':type(e).__name__+':'+str(e)[:90]}
 print('MAD4B_MOBILE_PRIME '+json.dumps(out,sort_keys=True),flush=True)
 return 0 if out['state'] in ('READY','UNCHANGED_SKIP','BOUNDED_DEFER','AWAITING_CCSS')else 75
if __name__=='__main__':sys.exit(main())
