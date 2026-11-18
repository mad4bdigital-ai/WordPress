#!/usr/bin/env python3
"""Root-only readback of independently observed two native source CSS profiles.

It CANNOT certify a CSS file, change a manifest, purge cache or acknowledge
a WordPress ticket. It reveals provenance mismatches and leaves original
Chromium visual/performance QA as the only certification authority.
"""
import hashlib,json,os,re,sys,tempfile,time
from pathlib import Path
ROOT=Path('/opt/mad4b-company-provisioning')
S=ROOT/'reports/dynamic-critical-css-20261116/source'
WP=Path('/opt/hostinger-migrations/hosting-3/etg-commerce-staging/site-copy/wp-content')
OUT=S/'source-css-profile-variance.json'
LANGS=('en','es','de','fr','ar','it')
CSS_RX=re.compile(r'/wp-content/litespeed/css/([a-f0-9]{32}\.css)')
DOC_RX=re.compile(r'(?<![a-z0-9_-])elementor-(\d+)\b',re.I)

def identity_from_html(data):
    if not isinstance(data,str) or not 20000<len(data)<6000000:
        raise ValueError('untrusted_browser_html_size')
    if '<body' not in data.lower():raise ValueError('untrusted_browser_body_missing')
    files=set(CSS_RX.findall(data))
    if len(files)!=1:raise ValueError('untrusted_browser_css_family')
    body=data[data.lower().find('<body'):]
    docs=sorted(set(map(int,DOC_RX.findall(body))))
    return (files.pop(),
      hashlib.sha256(','.join(map(str,docs)).encode()).hexdigest())

def classify(http,manifest,browser,epoch):
    """Profiles need independent identity; one certificate is not shared."""
    results={};pending=[]
    if http.get('contract')!='mad4b.source-home-css-change-observation.v1' or http.get('host')!='tours.dev.mad4b.com':
        raise ValueError('foreign_live_source_profile')
    for lang in LANGS:
        current=http['sources'][lang]
        item=browser.get(lang)
        if not isinstance(item,dict):
            results[lang]={'status':'browser_profile_missing','http_css_sha':current['sha256']}
            pending.append(lang);continue
        cssfile=item['css_file'];css_sha=item['css_sha'];doc_sha=item['doc_sha']
        entry=manifest.get('entries',{}).get(css_sha,{})
        variant=entry.get('variants',{}).get(current['route']+'|'+lang+'|mobile',{})
        browser_qualified=(entry.get('bundle_file')==cssfile
                           and variant.get('gate_status')=='pass'
                           and variant.get('css_epoch')==epoch
                           and variant.get('elementor_doc_fingerprint')==doc_sha
                           and item.get('ready')=='1')
        # An independently original-QA-certified HTTP source may be used
        # only under its OWN exact SHA + Elementor document graph, never by
        # inheriting a valid Chrome certificate for a different bundle.
        http_entry=manifest.get('entries',{}).get(current['sha256'],{})
        http_variant=http_entry.get('variants',{}).get(current['route']+'|'+lang+'|mobile',{})
        http_qualified=(http_entry.get('bundle_file')==current['file']
                       and http_variant.get('gate_status')=='pass'
                       and http_variant.get('css_epoch')==epoch
                       and http_variant.get('elementor_doc_fingerprint')==current['elementor_doc_fingerprint'])
        if not http_qualified:pending.append(lang)
        status=('same_exact_profile' if (http_qualified and browser_qualified
                         and current['sha256']==css_sha and current['elementor_doc_fingerprint']==doc_sha)
                else 'both_profiles_independently_certified' if http_qualified and browser_qualified
                else 'distinct_css_or_elementor_graph_pending_qa')
        results[lang]={
          'status':status,
          'http_css_sha':current['sha256'],
          'chromium_css_sha':css_sha,
          'same_css_sha':current['sha256']==css_sha,
          'same_elementor_graph':current['elementor_doc_fingerprint']==doc_sha,
          'chromium_previous_certificate_matched':browser_qualified,
          'http_previous_independent_certificate_matched':http_qualified,
        }
    return {'contract':'mad4b.source-css-profile-variance.v1','host':http['host'],
            'checked_at':int(time.time()),'site_css_epoch':epoch,
            'source_observer_fingerprint':http['fingerprint'],
            'per_locale':results,'http_profiles_pending_visual_qa':pending,
            'certificate_issued':False,'wp_ticket_acknowledged':False}

def native_browser_rows(report,browser_max_age=5400):
    if not report.is_dir() or report.is_symlink():raise ValueError('untrusted_browser_report')
    if time.time()-report.stat().st_mtime>browser_max_age:
        raise ValueError('browser_readback_stale')
    result={}
    for lang in LANGS:
        html=report/(lang+'.html');headers=report/(lang+'.headers')
        if (html.is_symlink() or headers.is_symlink()
            or not html.is_file() or not headers.is_file()):
            continue
        css,docs=identity_from_html(html.read_text(errors='replace'))
        h=headers.read_text()
        ready='1' if re.search(r'^x-mad4b-mobile-css-ready: 1\s*$',h,re.I|re.M) else ''
        loc=WP/'litespeed/css'/css
        if not loc.is_file() or loc.is_symlink():
            loc=WP/'litespeed/.mad4b-asset-retention/css'/css
        if not loc.is_file() or loc.is_symlink():continue
        result[lang]={'css_file':css,'css_sha':hashlib.sha256(loc.read_bytes()).hexdigest(),
                      'doc_sha':docs,'ready':ready}
    return result

def atomic(path,report):
    if path.is_symlink() or path.parent.is_symlink():raise ValueError('symlink_report_denied')
    fd,tmp=tempfile.mkstemp(prefix='.source-css-cohorts-',dir=path.parent)
    try:
        with os.fdopen(fd,'w') as stream:
            os.fchmod(stream.fileno(),0o600)
            json.dump(report,stream,indent=2,sort_keys=True)
            stream.write('\n')
            stream.flush();os.fsync(stream.fileno())
        os.replace(tmp,path)
    finally:
        if os.path.exists(tmp):os.unlink(tmp)

def main():
    http=json.loads((S/'home-current-css-source.json').read_text())
    manifest=json.loads((WP/'mad4b-critical-css-v1/manifest.json').read_text())
    epoch=json.loads((S/'current-site.json').read_text())['css_epoch']
    if manifest.get('site')!='tours.dev.mad4b.com' or not isinstance(epoch,int):raise ValueError('bad_source_manifest')
    # Existing trusted first-party Chromium receipts. Do not infer from
    # source HTTP alone and do not silently fabricate a missing browser.
    roots=sorted((d for d in S.iterdir() if d.is_dir() and re.fullmatch(r'20\d{6}T\d{6}Z',d.name)),reverse=True)
    browser={}
    for root in roots[:10]:
        if time.time()-root.stat().st_mtime>5400:continue
        rows=native_browser_rows(root/'observed')
        for lang,row in rows.items():browser.setdefault(lang,row)
        if len(browser)==len(LANGS):break
    result=classify(http,manifest,browser,epoch)
    atomic(OUT,result)
    print('SOURCE_CSS_PROFILE_COHORTS','HTTP_UNQUALIFIED='+','.join(result['http_profiles_pending_visual_qa']),
          'CHROMIUM_CERT_MATCHED='+','.join(lang for lang,r in result['per_locale'].items()
             if r.get('chromium_previous_certificate_matched')),'NO_CERTIFICATE_ISSUED')
if __name__=='__main__':
    try:main()
    except (OSError,ValueError,TypeError,KeyError,json.JSONDecodeError) as exc:
        print('SOURCE_CSS_PROFILE_COHORTS_HOLD',type(exc).__name__,str(exc)[:160],file=sys.stderr)
        sys.exit(4)
