#!/usr/bin/env python3
"""Source Staging: precisely version current certified WPML home HTML routes.

The source home FastCGI key includes a per-path generation marker; publishing
a correct Critical CSS certificate alone does not invalidate old Ready:0 HTML.
No WooCommerce, archives, query strings or private market variants are purged.
"""
import hashlib,json,pathlib,re,socket,stat,sys,time
from pathlib import Path

SITE='tours.dev.mad4b.com'
ROOT=Path('/opt/hostinger-migrations/hosting-3/etg-commerce-staging/site-copy/wp-content')
def sha(path):
    return hashlib.sha256(path.read_bytes()).hexdigest()
def valid_languages(state):
    if (state.get('site_host')!=SITE or state.get('environment')!='staging'
        or state.get('critical_css')!='on'
        or not isinstance(state.get('css_epoch'),int)
        or not isinstance(state.get('wpml_languages'),list)):
        raise ValueError('source-site-registry-invalid')
    langs=state['wpml_languages'];default=state.get('default_language')
    if not 1<=len(langs)<=20 or len(set(langs))!=len(langs) or default not in langs or any(not re.fullmatch(r'[a-z]{2}',x) for x in langs):
        raise ValueError('WPML-home-namespace-invalid')
    return {l:('/' if l==default else '/'+l+'/') for l in langs}
def certified(manifest,root,entry,lang,route,epoch,css_sha,critical_sha=None,native_breakpoint=None):
    meta=manifest.get('entries',{}).get(css_sha,{})
    row=meta.get('variants',{}).get(route+'|'+lang+'|mobile',{})
    filename=meta.get('bundle_file')
    name=row.get('file')
    cap=row.get('css_critical_mobile_breakpoint')
    evidence=row.get('evidence',{})
    # This is only a home HTML generation rollover. It must never turn a
    # partially measured narrower CSS cap into a public first-paint release.
    valid_cap=(cap==767 and evidence.get('adaptive_mobile_breakpoint_px')==767)
    if cap==599:
        valid_cap=(native_breakpoint==767
            and evidence.get('native_mobile_breakpoint_px')==767
            and evidence.get('adaptive_mobile_breakpoint_px')==599
            and set(evidence.get('viewports',[]))=={320,390,414,599,600,767,768})
    if (not valid_cap or not re.fullmatch(r'[a-f0-9]{32}\.css',str(filename))
        or not re.fullmatch(r'[a-f0-9]{64}\.css',str(name))
        or row.get('gate_status')!='pass'
        or row.get('route_path')!=route or row.get('language')!=lang
        or row.get('device')!='mobile'
        or row.get('evidence',{}).get('decision')!='passed_evidence'
        or row.get('css_epoch')!=epoch
        or (critical_sha is not None and row.get('critical_sha')!=critical_sha)):
        return False
    original=root/'litespeed/css'/filename
    css=root/'mad4b-critical-css-v1'/name
    if (original.is_symlink() or css.is_symlink()
        or not original.is_file() or not css.is_file()
        or css.stat().st_size!=row.get('critical_bytes')
        or not stat.S_IMODE(css.stat().st_mode)&0o004):
        return False
    return sha(original)==css_sha and sha(css)==row['critical_sha']
def plan(mode,state,manifest,inventory,root):
    routes=valid_languages(state)
    if (manifest.get('version')!=2 or manifest.get('site')!=SITE
        or manifest.get('contract')!='mad4b.mobile-critical-css.v2'
        or not isinstance(manifest.get('entries'),dict)):
        raise ValueError('source-Critical-CSS-v2-manifest-invalid')
    chosen=[]
    if mode=='promote':
        if (inventory.get('contract')!='mad4b.mobile-critical-promotion.v1'
            or inventory.get('host')!=SITE
            or inventory.get('dry_run') is not False
            or inventory.get('holds')
            or inventory.get('epoch')!=state['css_epoch']):
            raise ValueError('not-an-actual-same-epoch-certified-release')
        rows=inventory.get('promoted',[])
        for r in rows:
            lang=r.get('language');route=r.get('route')
            if lang not in routes or route!=routes[lang]:
                raise ValueError('foreign-homepage-promotion')
            if not certified(manifest,root,None,lang,route,state['css_epoch'],r.get('css_sha'),r.get('critical_sha'),state.get('native_breakpoint')):
                raise ValueError('promoted-critical-asset-source-or-proof-drift')
            chosen.append(route)
    elif mode=='recover':
        if inventory.get('contract')!='mad4b.critical-css.dynamic-selection.v1' or inventory.get('site')!='source' or inventory.get('css_epoch')!=state['css_epoch']:
            raise ValueError('not-a-current-source-CSS-selection')
        for r in inventory.get('routes',[]):
            lang=r.get('locale');route=r.get('route')
            if lang not in routes:raise ValueError('foreign-or-removed-WPML-locale')
            if route is not None and route!=routes[lang]:raise ValueError('noncanonical-WPML-route')
            if (r.get('manifest_match') is True
                and r.get('status')=='needs_native_browser_qa'
                and r.get('header_ready') is False
                and certified(manifest,root,None,lang,routes[lang],state['css_epoch'],r.get('css_sha'),native_breakpoint=state.get('native_breakpoint'))):
                chosen.append(routes[lang])
    else:raise ValueError('invalid cache refresh mode')
    if not chosen or len(chosen)>20 or len(set(chosen))!=len(chosen):return []
    return chosen
def purge(root,paths):
    if root!=ROOT or not paths:return {'status':'no_op','paths':0,'versioned':0}
    endpoint=root/'.mad4b-purge/origin.sock'
    if endpoint.is_symlink() or not endpoint.exists() or not stat.S_ISSOCK(endpoint.stat().st_mode):
        raise ValueError('native-company-path-generation-socket-unavailable')
    message=json.dumps(paths,separators=(',',':')).encode()+b'\n'
    with socket.socket(socket.AF_UNIX,socket.SOCK_STREAM) as conn:
        conn.settimeout(8);conn.connect(str(endpoint));conn.sendall(message)
        reply=json.loads(conn.makefile('rb').readline(4096))
    if (reply.get('status')!='ok' or reply.get('paths')!=len(paths)
        or reply.get('versioned')!=len(paths)):
        raise ValueError('source-selective-cache-coordinator-rejected')
    return reply
def main():
    if len(sys.argv)!=7:raise ValueError('expected mode, state, manifest, release/selection, content root, report')
    mode,st,mf,inv,root,out=sys.argv[1:]
    root=Path(root)
    if root!=ROOT:raise ValueError('source-company-root-only')
    state,manifest,inventory=(json.loads(Path(x).read_text()) for x in [st,mf,inv])
    paths=plan(mode,state,manifest,inventory,root)
    response=purge(root,paths)
    data={'contract':'mad4b.source-certified-home-cache-release.v1',
          'mode':mode,'site':SITE,'epoch':state['css_epoch'],
          'paths':paths,'receipt':response,'timestamp':int(time.time())}
    Path(out).write_text(json.dumps(data,sort_keys=True,indent=2)+'\n')
    print('SOURCE_CERTIFIED_HOME_CACHE_REFRESH',json.dumps(data,sort_keys=True))
if __name__=='__main__':
    try:main()
    except (ValueError,TypeError,KeyError,IndexError,AssertionError,OSError) as e:
        print('HOLD_SOURCE_CERTIFIED_HOME_REFRESH',str(e),file=sys.stderr);sys.exit(4)
