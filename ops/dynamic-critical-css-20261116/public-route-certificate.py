#!/usr/bin/env python3
"""Native all-pages certificate publisher: immutable per-route CSS/DOM proof.

No generic 'template-level' or guessed evidence is accepted. A different
WPML route, CSS SHA, Elementor document graph, market or post revision must
be measured and re-certified separately.
"""
import hashlib,json,os,pathlib,re,stat,sys,tempfile,time
from pathlib import Path
CONTRACT='mad4b.public-critical-css.route-certificate.v1'
CATALOG='mad4b.public-critical-css.routes.v1'
QA='mad4b.mobile-critical.qa.v1'
CANDIDATE='mad4b.mobile-critical.candidates.v1'
ALLOWED_HOSTS={'tours.dev.mad4b.com','wayfatours.com'}
ALLOWED_PUBLIC={'page','post','tours-and-activities','properties',
                'destinations','tour-types','property-types','tour-categories',
                'category','archive:tours-and-activities','archive:properties'}
TERM_TYPES={'destinations','tour-types','property-types','tour-categories','category'}
VIEWPORTS={320,390,414,767,768}
def sha(b):return hashlib.sha256(b).hexdigest()
def load(file,limit=3_000_000):
    p=Path(file)
    if p.is_symlink() or not p.is_file() or p.stat().st_size>limit:raise ValueError('untrusted-or-absent-evidence')
    return json.loads(p.read_text())
def verify_queried_object(route,html):
    bodymatch=re.search(r'<body[^>]*class=["\x27]([^"\x27]+)',html,re.I)
    classes=set((bodymatch.group(1) if bodymatch else '').split())
    if route['type'].startswith('archive:'):
        expected='post-type-archive-'+route['type'].split(':',1)[1]
        if route['id']!=0 or expected not in classes:
            raise ValueError('source-WP-archive-body-identity-drift')
    else:
        rx=r'(?:^|\s)'+('term' if route['type'] in TERM_TYPES else r'(?:page-id|postid)')+r'-(\d+)(?:\s|$)'
        match=re.search(rx,' '.join(classes))
        if not match or int(match.group(1))!=route['id']:
            raise ValueError('source-WP-queried-object-drift')
    return True

def proof(catalog,route,candidates,qa,source_html,source_headers,site_state,root):
    host=catalog.get('host')
    if (catalog.get('contract')!=CATALOG or host not in ALLOWED_HOSTS
        or candidates.get('contract')!=CANDIDATE or candidates.get('host')!=host
        or candidates.get('errors') or qa.get('contract')!=QA
        or site_state.get('site_host')!=host or site_state.get('critical_css')!='on'):
        raise ValueError('site-or-evidence-contract-mismatch')
    if route not in catalog.get('routes',[]):raise ValueError('route-missing-from-authenticated-WPML-catalog')
    # Only the exact WP-CLI scoped post revision can issue a CSS proof.
    # Preserve native dependency invalidation for changed content,
    # translated terms, and market-sensitive route sources.
    revision=route.get('revision')
    if not isinstance(revision,int) or isinstance(revision,bool) or revision<0:
        raise ValueError('invalid-source-post-revision')
    runtime_sha=site_state.get('runtime_sha')
    if not isinstance(runtime_sha,str) or not re.fullmatch(r'[a-f0-9]{64}',runtime_sha):
        raise ValueError('missing-or-untrusted-original-runtime-SHA')
    if ('route_generation' in site_state
        and site_state['route_generation']!=revision):
        raise ValueError('conflicting-site-and-route-revision')
    if route['type'] not in ALLOWED_PUBLIC:
        raise ValueError('route-type-not-approved-by-public-css-market-protocol')
    if route['path']=='/' or route['path']==('/'+route['language']+'/'):
        raise ValueError('homepages-already-owned-by-native-home-critical-CSS')
    if (len(candidates.get('candidates',[]))!=1
        or len(qa.get('gates',{}))!=1
        or qa.get('tested')!=5 or len(qa.get('results',[]))!=5):
        raise ValueError('expected-one-route-five-viewport-proofs')
    row=candidates['candidates'][0];lang=route['language']
    if (row.get('resolved_currency')!=site_state.get('currency')
        or row.get('resolved_market')!=site_state.get('market_country')):
        raise ValueError('paired-Chromium-capture-country-and-currency-mismatch')
    if (row['lang']!=lang or row['route']!=route['path']
        or row['page_id']!=route['id']
        or row['device']!='mobile'
        or row['widths']!=[320,390,414,768]
        or row['source_bytes']<16000 or row['critical_bytes']<16000
        or row['critical_bytes']>1048576):
        raise ValueError('candidate-route-binding-failed')
    gate=qa['gates'].get(lang,{})
    if (gate.get('decision')!='passed_evidence'
        or gate.get('adaptiveMaxWidth')!=767 or gate.get('cssPriority')!='high'
        or gate.get('fullCssPreload') is not True
        or set(gate.get('widths',[]))!=VIEWPORTS
        or len(gate.get('widths',[]))!=5
        or any(x['lang']!=lang for x in qa['results'])
        or {r['width'] for r in qa['results']}!=VIEWPORTS
        or any(x['pass'] is not True for x in qa['results'])):
        raise ValueError('paired-Chromium-performance-or-breakpoint-proof-failed')
    headers=source_headers.lower().replace('\r','')
    if not re.search(r'^http/[0-9.]+\s+200\b',headers,re.M):
        raise ValueError('source-HTTP-response-not-200')
    currency_headers=re.findall(r'^x-mad4b-public-ccss-currency:\s*([a-z]{3})\s*$',
        headers,re.M)
    market_headers=re.findall(r'^x-mad4b-public-ccss-market:\s*([a-z]{2}|static|base)\s*$',
        headers,re.M)
    expected_market=site_state.get('market_country')
    if (len(currency_headers)!=1 or len(market_headers)!=1
        or currency_headers[0].upper()!=site_state.get('currency')
        or (market_headers[0].upper() if market_headers[0] not in ['static','base'] else market_headers[0])!=expected_market):
        raise ValueError('server-WCML-country-and-currency-not-bound')
    if (not source_html.count('<body') or 'id="mad4b-public-critical-css-v1"' in source_html
        or 'id="mad4b-critical-css-v1"' in source_html):
        raise ValueError('source-must-be-native-unoptimized-HTML')
    css=list(dict.fromkeys(re.findall(r'/wp-content/litespeed/css/([a-f0-9]{32}\.css)',source_html)))
    if css!=[row['css_file']] or not re.fullmatch(r'[a-f0-9]{32}\.css',row['css_file']):
        raise ValueError('source-CSS-URL-identity-drift')
    verify_queried_object(route,source_html)
    body=source_html[source_html.lower().find('<body'):]
    docs=sorted({int(i) for i in re.findall(r'(?<![a-z0-9_-])elementor-(\d+)\b',body,re.I)})
    docsha=sha(','.join(map(str,docs)).encode())
    if docsha!=row['dom_sha']:raise ValueError('Elementor-document-graph-drift')
    origin=root/'litespeed/css'/row['css_file']
    if not origin.is_file() or origin.is_symlink() or origin.stat().st_size!=row['source_bytes']:
        raise ValueError('origin-CSS-source-not-stable')
    if sha(origin.read_bytes())!=row['source_sha']:
        raise ValueError('origin-CSS-SHA-changed')
    critical_name=row['critical_file']
    if not re.fullmatch(r'[a-f0-9]{64}\.css',critical_name):
        raise ValueError('unsafe-content-addressed-critical-filename')
    if (not isinstance(site_state.get('currency'),str) or
        not re.fullmatch(r'[A-Z]{3}|NONE',site_state['currency'])
        or site_state.get('native_breakpoint')!=767
        or not isinstance(site_state.get('css_epoch'),int)
        or site_state['css_epoch']<=0):
        raise ValueError('unbound-site-currency-or-Elementor-breakpoint')
    if (route['type']=='page' and route['path'].startswith('/wp-')
        or route['language'] not in site_state.get('wpml_languages',[])):
        raise ValueError('untrusted-page-or-removed-language')
    market_sensitive=(route['type'] not in {'page','post'} or
        any(x in body for x in [
        'jet-listing-grid','woocommerce-Price-amount',
        'mad4b-pricing','mad4b-rate','tour-rate','data-price=']))
    if market_sensitive and not (
        expected_market=='base' or
        isinstance(expected_market,str) and re.fullmatch(r'[A-Z]{2}',expected_market)):
        raise ValueError('dynamic-market-pricing-page-requires-a-trusted-country')
    if not market_sensitive and expected_market!='static':
        raise ValueError('static-page-market-proof-category-mismatch')
    return {'contract':CONTRACT,'status':'certified','host':host,'path':route['path'],
        'language':lang,'type':route['type'],'object_id':route['id'],
        'market_country':site_state['market_country'],'currency':site_state['currency'],
        'css_epoch':site_state['css_epoch'],'runtime_sha':runtime_sha,
        'native_mobile_breakpoint':767,
        'critical_cap_px':767,'css_file':row['css_file'],
        'css_sha':row['source_sha'],'critical_file':critical_name,
        'critical_sha':row['critical_sha'],'critical_bytes':row['critical_bytes'],
        'elementor_doc_fingerprint':row['dom_sha'],
        'dependencies':{'global_generation':int(site_state.get('global_generation',0)),
            'route_generation':revision,
            'listing_generation':int(site_state.get('listing_generation',0)),
            'contains_dynamic_listing':market_sensitive},
        'evidence':{'decision':'passed_evidence','viewports':[320,390,414,767,768],
            'tested':5,'minimum_fcp_gain_ms':min(x['fcpGain'] for x in qa['results']),
            'worst_lcp_reg_ms':max(x['lcpReg'] for x in qa['results']),
            'worst_cls':max(x['variant']['cls'] for x in qa['results']),
            'tested_at':gate.get('testedAt')},
        'certified_at':int(time.time())}
def prepare_public_critical_directories(root):
    # The worker runs with systemd UMask=0077. mkdir(mode=0755)
    # therefore creates root-only 0700 directories unless chmod is
    # explicitly applied. WordPress PHP runs as www-data and otherwise
    # sees certificates as missing despite successful publication/QA.
    # Only the exact public CSS assets/certs directories are adjusted;
    # no private workspaces or WordPress-wide permissions are changed.
    base=root/'mad4b-public-critical-css-v1'
    for directory in (base,base/'assets',base/'certs'):
        if directory.is_symlink():raise ValueError('public-CSS-directory-symlink-denied')
        directory.mkdir(mode=0o755,exist_ok=True)
        if directory.is_symlink() or not directory.is_dir():
            raise ValueError('public-CSS-directory-invalid')
        fd=os.open(directory,os.O_RDONLY|os.O_DIRECTORY|os.O_NOFOLLOW)
        try:
            existing=stat.S_IMODE(os.fstat(fd).st_mode)
            if existing & 0o022:raise ValueError('public-CSS-directory-world-writable')
            os.fchmod(fd,0o755)
            if stat.S_IMODE(os.fstat(fd).st_mode)!=0o755:
                raise ValueError('public-CSS-directory-not-readable-by-PHP')
        finally:
            os.close(fd)
    return base

def atomic_write(path,data):
    path.parent.mkdir(parents=True,mode=0o755,exist_ok=True)
    fd,name=tempfile.mkstemp(prefix='.mad4b-certified-',dir=path.parent)
    try:
        with os.fdopen(fd,'wb') as o:
            os.fchmod(o.fileno(),0o644);o.write(data);o.flush();os.fsync(o.fileno())
        os.replace(name,path)
    finally:
        if os.path.exists(name):os.unlink(name)
def main():
    if len(sys.argv)!=9:raise ValueError('expected catalog route evidence QA browser state wp-content mode')
    catalogfile,routefile,candidatefile,qafile,browser,statefile,contentroot,mode=sys.argv[1:]
    root=Path(contentroot)
    if str(root) not in [
      '/opt/hostinger-migrations/hosting-3/etg-commerce-staging/site-copy/wp-content',
      '/opt/wayfa-rebuild-20261102/site-copy/wp-content']:
        raise ValueError('company-WordPress-root-not-authorized')
    if mode not in ['dryrun','publish']:raise ValueError('unsupported-publishing-mode')
    catalog=load(catalogfile)
    route=load(routefile)
    candidates=load(candidatefile)
    qa=load(qafile)
    state=load(statefile)
    htmlfile=Path(browser)/(route['language']+'.html')
    headerfile=Path(browser)/(route['language']+'.headers')
    cert=proof(catalog,route,candidates,qa,htmlfile.read_text(errors='replace'),
        headerfile.read_text(),state,root)
    origin=Path(candidatefile).parent/cert['critical_file']
    if not origin.is_file() or origin.is_symlink() or origin.stat().st_size!=cert['critical_bytes']:
        raise ValueError('critical-origin-size-drift')
    payload=origin.read_bytes()
    if sha(payload)!=cert['critical_sha']:raise ValueError('critical-origin-CSS-SHA-drift')
    base=root/'mad4b-public-critical-css-v1'
    route_key=sha((cert['host']+'|'+cert['language']+'|'+cert['path']
        +'|'+cert['market_country']+'|'+cert['currency']).encode())
    target=base/'certs'/(route_key+'.json')
    asset=base/'assets'/cert['critical_file']
    if mode=='publish':
        if asset.is_symlink() or target.is_symlink():raise ValueError('untrusted-content-addressed-path')
        prepare_public_critical_directories(root)
        atomic_write(asset,payload)
        atomic_write(target,(json.dumps(cert,indent=2,sort_keys=True)+'\n').encode())
        if sha(asset.read_bytes())!=cert['critical_sha'] or load(target)['css_sha']!=cert['css_sha']:
            raise ValueError('post-publish-readback-failed')
    print('PUBLIC_ROUTE_CRITICAL_CSS',json.dumps({'mode':mode,'route':cert['path'],
          'language':cert['language'],'css_sha':cert['css_sha'][:16],
          'critical_sha':cert['critical_sha'][:16],
          'fcp_gain_ms':cert['evidence']['minimum_fcp_gain_ms'],
          'worst_lcp_reg_ms':cert['evidence']['worst_lcp_reg_ms'],
          'has_dynamic_listing':cert['dependencies']['contains_dynamic_listing'],
          'certificate_path':str(target)},sort_keys=True))
if __name__=='__main__':
    try:main()
    except (ValueError,KeyError,TypeError,OSError,AssertionError,IndexError,json.JSONDecodeError) as e:
        print('HOLD_PUBLIC_ROUTE_CSS_CERTIFICATE',str(e),file=sys.stderr);sys.exit(4)
