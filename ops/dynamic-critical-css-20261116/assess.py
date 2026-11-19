#!/usr/bin/env python3
"""Read-only, exact-company mobile Critical CSS capture/admission selector.

Never approves a stylesheet by option state alone. A current certificate must
match the observed anonymous mobile HTML, exact local CSS bytes, locale/route,
the native WP CSS epoch, and (for v2) Elementor body document fingerprint.
"""
import hashlib,json,re,sys
from pathlib import Path

SHA=lambda s:hashlib.sha256(s).hexdigest()
VALID_HOSTS={
 'source':('tours.dev.mad4b.com','staging',2),
 'wayfa':('wayfatours.com','production',1)
}
def classify(site:str,wp:Path,st:dict,locale:str,html:str,headers:str):
    host,environment,expected=VALID_HOSTS[site]
    if (st.get('site_host')!=host or st.get('environment')!=environment
        or st.get('critical_css')!='on' or st.get('native_breakpoint')!=767):
        raise ValueError('Company profile, setting or breakpoint changed')
    if not re.fullmatch(r'[a-z]{2}',locale):raise ValueError('Invalid WPML locale')
    route='/' if locale==st['default_language'] else f'/{locale}/'
    assert locale in st['wpml_languages']
    status=re.findall(r'^HTTP/[0-9.]+ ([0-9]{3})',headers,re.M|re.I)
    if not status or int(status[-1])!=200:return {'locale':locale,'route':route,'status':'hold_http'}
    css_files=re.findall(r'/wp-content/litespeed/css/([a-f0-9]{32}\.css)',html)
    files=list(dict.fromkeys(css_files))
    if len(files)!=1:return {'locale':locale,'route':route,'status':'hold_ambiguous_css','stylesheets':len(files)}
    filename=files[0]
    path=wp/'litespeed/css'/filename
    if path.is_symlink() or not path.is_file() or path.stat().st_size>6291456:
        return {'locale':locale,'route':route,'status':'hold_invalid_css'}
    sha=SHA(path.read_bytes())
    manifest_path=wp/'mad4b-critical-css-v1/manifest.json'
    manifest=json.loads(manifest_path.read_text())
    if (manifest.get('site')!=host or manifest.get('version')!=expected
        or not isinstance(manifest.get('entries'),dict)):
        return {'locale':locale,'route':route,'status':'hold_wrong_manifest'}
    entry=manifest['entries'].get(sha,{})
    proof={}
    if expected==1:
        if (entry.get('gate_status')=='pass'
            and locale in entry.get('languages',[])
            and 'mobile' in entry.get('devices',[])
            and entry.get('source_css_bytes')==path.stat().st_size):
            proof=entry
    else:
        variant=entry.get('variants',{}).get(f'{route}|{locale}|mobile',{})
        match=re.search(r'<body\b',html,re.I)
        document=html[match.start():] if match else ''
        docs=sorted(set(map(int,re.findall(r'(?<![a-z0-9_-])elementor-(\d+)\b',document,re.I))))
        docsha=SHA(','.join(map(str,docs)).encode())
        evidence=variant.get('evidence',{})
        cap=variant.get('css_critical_mobile_breakpoint')
        valid_cap=(cap==767 and evidence.get('adaptive_mobile_breakpoint_px')==767)
        if cap==599:
            # Narrower CSS is a measured source-only 599/600 boundary
            # while Elementor's actual mobile 767/768 breakpoint remains
            # unchanged. A new WPML locale or stale certificate is never
            # accepted on the original 767-only proof.
            widths=evidence.get('viewports',[])
            valid_cap=(st['native_breakpoint']==767
                and evidence.get('native_mobile_breakpoint_px')==767
                and evidence.get('adaptive_mobile_breakpoint_px')==599
                and isinstance(widths,list) and len(widths)==7
                and set(widths)=={320,390,414,599,600,767,768}
                and '<style id="mad4b-critical-css-v1" media="(max-width:599px)">' in html
                and 'id="mad4b-css-rest-v1"' in html
                and 'media="(min-width:600px)"' in html)
            critical_file=variant.get('file')
            critical_sha=variant.get('critical_sha')
            if (valid_cap and isinstance(critical_file,str)
                and re.fullmatch(r'[a-f0-9]{64}[.]css',critical_file)
                and isinstance(critical_sha,str)
                and re.fullmatch(r'[a-f0-9]{64}',critical_sha)):
                asset=wp/'mad4b-critical-css-v1'/critical_file
                valid_cap=(not asset.is_symlink() and asset.is_file()
                    and 16000<=asset.stat().st_size<=1048576
                    and asset.stat().st_size==variant.get('critical_bytes')
                    and SHA(asset.read_bytes())==critical_sha)
            else:
                valid_cap=False
        if (variant.get('gate_status')=='pass'
            and evidence.get('decision')=='passed_evidence'
            and variant.get('css_epoch')==int(st['css_epoch'])
            and variant.get('elementor_doc_fingerprint')==docsha
            and valid_cap
            and entry.get('bundle_file')==filename):
            proof=variant
    ready=re.search(r'^x-mad4b-mobile-css-ready:\s*1\s*$',headers,re.I|re.M)
    inline=re.search(r'<style\b[^>]*id=["\']mad4b-critical-css-v1["\']',html,re.I)
    active=bool(proof and ready and inline)
    return {
        'locale':locale,'route':route,'css_file':filename,
        'css_sha':sha,'css_epoch':int(st['css_epoch']),
        'status':'certified_active' if active else 'needs_native_browser_qa',
        'header_ready':bool(ready),'manifest_match':bool(proof),
        'critical_cap_px':proof.get('css_critical_mobile_breakpoint') if proof else None
    }

def main():
    site,root,state,observed=sys.argv[1:5]
    if site not in VALID_HOSTS:raise ValueError('Invalid company')
    wp=Path(root);st=json.loads(Path(state).read_text())
    docs=Path(observed)
    results=[]
    for locale in st['wpml_languages']:
        if not re.fullmatch(r'[a-z]{2}',locale):raise ValueError('Unsupported WPML language code '+str(locale))
        body=docs/f'{locale}.html';headers=docs/f'{locale}.headers'
        if not body.is_file() or not headers.is_file():
            results.append({'locale':locale,'status':'hold_no_html'});continue
        results.append(classify(site,wp,st,locale,body.read_text(errors='replace'),
             headers.read_text(errors='replace').replace('\r','')))
    assert len(results)==len(st['wpml_languages'])
    print(json.dumps({'contract':'mad4b.critical-css.dynamic-selection.v1',
       'site':site,'css_epoch':st['css_epoch'],'routes':results},
       ensure_ascii=False,sort_keys=True))
if __name__=='__main__':
    try:main()
    except Exception as e:
        print('ERROR source/manifest assessment:',str(e),file=sys.stderr);sys.exit(2)
