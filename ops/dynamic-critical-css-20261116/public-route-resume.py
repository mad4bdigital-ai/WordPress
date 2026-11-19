#!/usr/bin/env python3
"""Reuse an already deployed public Critical CSS certificate after live readback.

Does not create certificates or waive original five-viewport performance QA.
It only reuses a previously issued, content-addressed pass whose exact
visible Critical CSS and native-rest markup still match a fresh browser view,
WP content revision, market/currency and source CSS.
"""
import hashlib,importlib.util,json,re,sys
from pathlib import Path
DIR=Path(__file__).resolve().parent
spec=importlib.util.spec_from_file_location("queue",DIR/"public-route-queue.py")
queue=importlib.util.module_from_spec(spec);spec.loader.exec_module(queue)
HOSTS={'source':('tours.dev.mad4b.com','/opt/hostinger-migrations/hosting-3/etg-commerce-staging/site-copy/wp-content'),
       'wayfa':('wayfatours.com','/opt/wayfa-rebuild-20261102/site-copy/wp-content')}
def sha(raw):return hashlib.sha256(raw).hexdigest()
def ok_cert(site,root,catalog,route,state,headers,html):
    if site not in HOSTS or HOSTS[site]!=(catalog.get('host'),str(root)):
        raise ValueError('company-context-unauthorized')
    trusted=queue.safe_catalog(catalog,site)
    if route not in trusted or route.get('path') in ('/','/'+route.get('language','')+'/'):
        raise ValueError('untrusted-public-WPML-route')
    if not isinstance(html,str) or len(html)>6000000 or len(html)<1000:
        return None
    h=headers.lower().replace('\r','')
    if (not re.search(r'^http/[0-9.]+\s+200\b',h,re.M)
        or not re.search(r'^x-mad4b-public-ccss-ready:\s*1\s*$',h,re.M)
        or html.count('id="mad4b-public-critical-css-v1"')!=1
        or html.count('id="mad4b-public-css-rest-v1"')!=1
        or 'media="(max-width:767px)"' not in html
        or 'media="(min-width:768px)"' not in html):
        return None
    currency=re.findall(r'^x-mad4b-public-ccss-currency:\s*([a-z]{3}|none)\s*$',h,re.M)
    market=re.findall(r'^x-mad4b-public-ccss-market:\s*(static|base|[a-z]{2})\s*$',h,re.M)
    if len(currency)!=1 or len(market)!=1:return None
    cur=currency[0].upper();country=market[0].upper() if market[0] not in ('static','base') else market[0]
    host=HOSTS[site][0]
    if state.get('site_host')!=host or state.get('critical_css')!='on' or state.get('all_public_css')!='on':
        raise ValueError('site-policy-not-authorized')
    digest=sha((host+'|'+route['language']+'|'+route['path']+'|'+country+'|'+cur).encode())
    certpath=root/'mad4b-public-critical-css-v1/certs'/(digest+'.json')
    if certpath.is_symlink() or not certpath.is_file() or certpath.stat().st_size>8192:return None
    cert=json.loads(certpath.read_text())
    for key,val in (('contract','mad4b.public-critical-css.route-certificate.v1'),
                    ('status','certified'),('host',host),('path',route['path']),
                    ('language',route['language']),('type',route['type']),
                    ('object_id',route['id']),('market_country',country),
                    ('currency',cur),('css_epoch',state['css_epoch']),
                    ('critical_cap_px',767),('native_mobile_breakpoint',767)):
        if cert.get(key)!=val:return None
    # Public Ready:1 is not sufficient when PHP was upgraded between
    # capture and readback. The exact first-party 422 consumer must match
    # both the authorized WP-CLI state and the issued certificate SHA.
    native=root/'mu-plugins/422-mad4b-public-critical-css-runtime.php'
    expected=state.get('runtime_sha')
    if (native.is_symlink() or not native.is_file()
        or not isinstance(expected,str)
        or not re.fullmatch(r'[a-f0-9]{64}',expected)
        or cert.get('runtime_sha')!=expected
        or sha(native.read_bytes())!=expected):
        return None
    deps=cert.get('dependencies',{})
    if (deps.get('route_generation')!=route['revision']
        or deps.get('global_generation')!=catalog.get('global_generation')
        or deps.get('global_generation')!=state.get('global_generation')
        or bool(deps.get('contains_dynamic_listing')) and
             (deps.get('listing_generation')!=catalog.get('listing_generation')
              or deps.get('listing_generation')!=state.get('listing_generation'))):
        return None
    proof=cert.get('evidence',{})
    if (proof.get('decision')!='passed_evidence'
        or proof.get('tested')!=5
        or proof.get('viewports')!=[320,390,414,767,768]):
        return None
    filename=cert.get('css_file');critname=cert.get('critical_file')
    if not isinstance(filename,str) or not re.fullmatch(r'[a-f0-9]{32}\.css',filename):return None
    if not isinstance(critname,str) or not re.fullmatch(r'[a-f0-9]{64}\.css',critname):return None
    if not re.fullmatch(r'[a-f0-9]{64}',str(cert.get('css_sha',''))):return None
    if not re.fullmatch(r'[a-f0-9]{64}',str(cert.get('critical_sha',''))):return None
    sources=[root/'litespeed/css'/filename,
             root/'litespeed/.mad4b-asset-retention/css'/filename]
    source=next((x for x in sources if x.is_file() and not x.is_symlink()),None)
    critical=root/'mad4b-public-critical-css-v1/assets'/critname
    if (source is None or critical.is_symlink() or not critical.is_file()
        or critical.stat().st_size!=cert.get('critical_bytes')
        or not 16000<=critical.stat().st_size<=1048576
        or sha(source.read_bytes())!=cert['css_sha']
        or sha(critical.read_bytes())!=cert['critical_sha']):
        return None
    embedded=re.findall(r'<style id="mad4b-public-critical-css-v1"[^>]*>(.*?)</style>',html,re.S|re.I)
    if len(embedded)!=1 or sha(embedded[0].encode())!=cert['critical_sha']:
        return None
    if not re.search(re.escape('/wp-content/litespeed/css/'+filename),html):
        return None
    rest=re.search(r'<link\b[^>]*id="mad4b-public-css-rest-v1"[^>]*>',html,re.I)
    if not rest or 'media="(min-width:768px)"' not in rest.group(0):return None
    body=html.lower().find('<body')
    if body<0:return None
    docids=sorted({int(n) for n in re.findall(r'(?<![a-z0-9_-])elementor-(\d+)\b',html[body:],re.I)})
    if sha(','.join(map(str,docids)).encode())!=cert.get('elementor_doc_fingerprint'):
        return None
    return {'contract':'mad4b.public-critical-css.active-readback.v1',
            'site':site,'host':host,'route':route['path'],'language':route['language'],
            'market':country,'currency':cur,'css_sha':cert['css_sha'],
            'critical_sha':cert['critical_sha'],
            'depends_on_listings':bool(deps.get('contains_dynamic_listing')),
            'scope':'one_browser_resolved_market_currency'}
def main():
    if len(sys.argv)!=9:raise ValueError('usage: site root catalog route state headers html report')
    site,root,catalog,route,state,headers,html,report=sys.argv[1:]
    cat=queue.read(catalog);rt=queue.read(route);st=queue.read(state)
    candidate=ok_cert(site,Path(root),cat,rt,st,Path(headers).read_text(),
                      Path(html).read_text(errors='replace'))
    if candidate is None:
        print('PUBLIC_CCSS_ACTIVE_CERTIFICATE_NOT_REUSABLE',site,rt.get('path',''))
        sys.exit(10)
    Path(report).write_text(json.dumps(candidate,indent=2,sort_keys=True)+'\n')
    print('PUBLIC_CCSS_ACTIVE_CERTIFICATE_REUSED',site,rt['path'],
          candidate['market'],candidate['currency'])
if __name__=='__main__':
    try:main()
    except (KeyError,TypeError,OSError,ValueError,IndexError,json.JSONDecodeError) as e:
        print('HOLD_PUBLIC_CCSS_CERT_RESUME',str(e),file=sys.stderr);sys.exit(4)
