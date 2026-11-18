#!/usr/bin/env python3
"""Observe actual anonymous source-site mobile home CSS across WPML locales.

This is ONLY a SHA-based change trigger. No browser/certificate/price mutation,
and no override of per-locale critical CSS proof. The native fallback continues
to protect changed templates until fresh exact certification is completed.
"""
import hashlib,json,os,re,sys,tempfile,time
from html.parser import HTMLParser
from pathlib import Path
from urllib.parse import urljoin,urlsplit
from urllib.request import Request,urlopen

HOST='tours.dev.mad4b.com'
WP=Path('/opt/hostinger-migrations/hosting-3/etg-commerce-staging/site-copy/wp-content')
REPORT=Path('/opt/mad4b-company-provisioning/reports/dynamic-critical-css-20261116/source')
CONTRACT='mad4b.source-home-css-change-observation.v1'
LANGS=('en','es','de','fr','ar','it')
UA='Mozilla/5.0 (Linux; Android 14; Pixel 7) AppleWebKit/537.36 Chrome/157.0.0.0 Mobile Safari/537.36'
ACCEPT='text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,image/apng,*/*;q=0.8'

class Links(HTMLParser):
    def __init__(self):
        super().__init__();self.urls=[]
    def handle_starttag(self,tag,attrs):
        d=dict(attrs)
        if tag=='link' and d.get('data-optimized')=='2' and d.get('rel')=='stylesheet':
            self.urls.append(d.get('href',''))

def source_filename(html):
    p=Links()
    p.feed(html)
    if len(p.urls)!=1:raise ValueError('source_combined_css_not_unique')
    u=urlsplit(urljoin('https://'+HOST+'/',p.urls[0]))
    if u.scheme!='https' or u.hostname!=HOST or u.port not in (None,443) or u.fragment:
        raise ValueError('source_host_css_mismatch')
    name=u.path.removeprefix('/wp-content/litespeed/css/')
    if not re.fullmatch(r'[a-f0-9]{32}[.]css',name) or u.path!='/wp-content/litespeed/css/'+name:
        raise ValueError('source_combined_css_filename_invalid')
    return name

def inspect(lang,wp=WP,fetch=None):
    if lang not in LANGS:raise ValueError('source_locale_not_enrolled')
    route='/' if lang=='en' else '/'+lang+'/'
    url='https://'+HOST+route
    if fetch is None:
        def fetch(u):
            with urlopen(Request(u,headers={'User-Agent':UA,'Accept':ACCEPT}),timeout=20) as response:
                if response.status!=200 or response.url!=u:
                    raise ValueError('source_home_origin_or_http_not_verified')
                return response.read(6000001)
    blob=fetch(url)
    if not isinstance(blob,bytes) or not 20000<=len(blob)<=6000000:
        raise ValueError('source_public_html_size_or_type_invalid')
    name=source_filename(blob.decode('utf-8',errors='replace'))
    css=wp/'litespeed/css'/name
    if css.is_symlink() or not css.is_file():
        css=wp/'litespeed/.mad4b-asset-retention/css'/name
    if css.is_symlink() or not css.is_file() or not 16000<=css.stat().st_size<=6291456:
        raise ValueError('source_css_file_missing_or_untrusted')
    html=blob.decode('utf-8',errors='replace')
    body=html[html.lower().find('<body'):] if '<body' in html.lower() else ''
    docs=sorted(set(map(int,re.findall(r'(?<![a-z0-9_-])elementor-(\d+)\b',body,re.I))))
    return {'route':route,'file':name,
            'sha256':hashlib.sha256(css.read_bytes()).hexdigest(),
            'elementor_doc_fingerprint':hashlib.sha256(
                ','.join(str(n) for n in docs).encode()).hexdigest()}

def fingerprint(rows):
    if set(rows)!=set(LANGS):raise ValueError('missing_source_wpml_locale')
    for lang,row in rows.items():
        if row.get('route')!=('/' if lang=='en' else '/'+lang+'/')            or not re.fullmatch(r'[a-f0-9]{32}[.]css',row.get('file',''))            or not re.fullmatch(r'[a-f0-9]{64}',row.get('sha256',''))            or not re.fullmatch(r'[a-f0-9]{64}',row.get('elementor_doc_fingerprint','')):
            raise ValueError('invalid_source_css_fingerprint_row')
    return hashlib.sha256(json.dumps(rows,sort_keys=True,separators=(',',':')).encode()).hexdigest()

def atomic(path,content):
    if path.is_symlink() or path.parent.is_symlink():raise ValueError('source_observer_symlink')
    path.parent.mkdir(parents=True,exist_ok=True,mode=0o700)
    fd,tmp=tempfile.mkstemp(prefix='.home-source-',dir=path.parent)
    try:
        with os.fdopen(fd,'w') as f:
            os.fchmod(f.fileno(),0o600)
            json.dump(content,f,sort_keys=True);f.write('\n');f.flush();os.fsync(f.fileno())
        os.replace(tmp,path)
    finally:
        if os.path.exists(tmp):os.unlink(tmp)

def observe(now=None,inspector=None):
    now=int(time.time() if now is None else now)
    state=json.loads((REPORT/'current-site.json').read_text())
    if state.get('site_host')!=HOST or state.get('environment')!='staging'        or set(state.get('wpml_languages',[]))!=set(LANGS)        or state.get('critical_css')!='on' or state.get('native_breakpoint')!=767:
        raise ValueError('source_site_context_untrusted')
    target=REPORT/'home-current-css-source.json'
    if target.is_symlink():raise ValueError('source_observer_ledger_symlink')
    old=json.loads(target.read_text()) if target.is_file() else {}
    if old and (old.get('contract')!=CONTRACT or old.get('host')!=HOST):
        raise ValueError('source_observer_foreign_ledger')
    # 15-minute resource-bounded sampling when previously degraded; one hour
    # when stable. The native WP-Cron job is the sole due-time authority.
    ttl=900 if (old and old.get('state')=='drift') else 3600
    if now-int(old.get('checked_at',0))<ttl:
        return 'cooled',old.get('fingerprint','')
    call=inspect if inspector is None else inspector
    rows={lang:call(lang) for lang in LANGS}
    key=fingerprint(rows)
    manifest=WP/'mad4b-critical-css-v1/manifest.json'
    if manifest.is_symlink() or not manifest.is_file() or manifest.stat().st_size>65536:
        raise ValueError('source_home_manifest_untrusted')
    raw=json.loads(manifest.read_text())
    if raw.get('contract')!='mad4b.mobile-critical-css.v2' or raw.get('site')!=HOST:
        raise ValueError('source_manifest_contract_invalid')
    missing=[]
    for lang,row in rows.items():
        entry=raw.get('entries',{}).get(row['sha256'],{})
        variant=entry.get('variants',{}).get(row['route']+'|'+lang+'|mobile',{}) if isinstance(entry,dict) else {}
        if (not isinstance(entry,dict)
            or entry.get('bundle_file')!=row['file']
            or variant.get('gate_status')!='pass'
            or variant.get('css_epoch')!=state['css_epoch']
            or variant.get('elementor_doc_fingerprint')!=row['elementor_doc_fingerprint']):
            missing.append(lang)
    state_name='drift' if missing else 'source_matched'
    atomic(target,{'contract':CONTRACT,'host':HOST,'checked_at':now,
       'sources':rows,'fingerprint':key,'state':state_name,
       'languages_without_exact_source_certificate':missing,
       'scope':'trigger_exact_locale_browser_QA_not_a_certificate'})
    return 'changed' if key!=old.get('fingerprint') else ('drift' if missing else 'stable'),key

if __name__=='__main__':
    try:
        if sys.argv[1:]!=['source']:raise ValueError('source_only')
        decision,key=observe()
        print('SOURCE_HOME_CSS_SOURCE_SIGNAL',decision,key[:16],flush=True)
    except (OSError,ValueError,KeyError,IndexError,TypeError,json.JSONDecodeError) as exc:
        print('HOLD_SOURCE_HOME_CSS_OBSERVER',str(exc)[:170],file=sys.stderr)
        sys.exit(4)
