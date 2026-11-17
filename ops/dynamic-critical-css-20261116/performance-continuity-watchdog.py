#!/usr/bin/env python3
"""Read-only first-party mobile CSS readiness SLO for Wayfatours EN/ES.

No Chromium, cache purges, WordPress writes, user cookies, URLs in logs,
or central dispatch to remotely hosted company sites.
"""
import datetime,json,os,pathlib,sys,tempfile,time,urllib.request

CONTRACT='mad4b.performance-continuity.mobile-home.v1'
HOST='wayfatours.com'
ROUTES={'en':'/','es':'/es/'}
UA=('Mozilla/5.0 (Linux; Android 14; Pixel 7) AppleWebKit/537.36 '
    '(KHTML, like Gecko) Chrome/157.0.0.0 Mobile Safari/537.36')
ROOT=pathlib.Path('/opt/mad4b-company-provisioning/reports/performance-continuity-v2')
REPORT=ROOT/'wayfa-home.json'

def evaluate(previous,observed,now):
    if set(observed)!=set(ROUTES):raise ValueError('incomplete-language-readback')
    values={}
    for lang,item in observed.items():
        if not isinstance(item,dict):raise ValueError('bad-http-observation')
        code=item.get('http_status');ready=item.get('css_ready');native=item.get('native_complete')
        if (type(code) is not int or code<100 or code>599
            or ready not in (0,1,None) or native not in (0,1,None)):
            raise ValueError('invalid-http-observation')
        values[lang]={'http_status':code,'css_ready':ready,'native_complete':native}
    if all(x['http_status']==200 and x['css_ready']==1 for x in values.values()):
        state='healthy'
    elif any(x['http_status']==200 and x['css_ready']==0 for x in values.values()):
        state='degraded'
    else:state='unknown'
    previous_since=previous.get('degraded_since') if isinstance(previous,dict) else None
    if (not isinstance(previous_since,int) or previous_since>now or previous_since<0
        or previous.get('contract')!=CONTRACT or previous.get('host')!=HOST):
        previous_since=None
    since=(previous_since if state=='degraded' and previous.get('state')=='degraded'
           else now if state=='degraded' else None)
    duration=(now-since) if since is not None else 0
    return {'contract':CONTRACT,'host':HOST,'state':state,'entry_point':'site_first_party_monitor',
            'execution_owner':'local_site_host','observed_at':now,'degraded_since':since,
            'degraded_seconds':duration,'alert':state=='degraded' and duration>=900,
            'recovery_priority':'home_en_es_before_public_routes' if state=='degraded' else 'normal',
            'routes':values}

def load(path):
    if not path.exists():return {}
    if path.is_symlink() or path.stat().st_size>8192:raise ValueError('untrusted-continuity-ledger')
    obj=json.loads(path.read_text())
    if obj.get('contract')!=CONTRACT or obj.get('host')!=HOST:raise ValueError('wrong-site-ledger')
    return obj

def atom(path,data):
    path.parent.mkdir(parents=True,exist_ok=True,mode=0o700)
    if path.parent.is_symlink():raise ValueError('symlink-report-directory')
    fd,name=tempfile.mkstemp(prefix='.continuity-',dir=path.parent)
    try:
        with os.fdopen(fd,'w') as fp:
            os.fchmod(fp.fileno(),0o600)
            json.dump(data,fp,sort_keys=True);fp.write('\n');fp.flush();os.fsync(fp.fileno())
        os.replace(name,path)
    finally:
        if os.path.exists(name):os.unlink(name)

def observe():
    found={}
    for lang,path in ROUTES.items():
        request=urllib.request.Request('https://'+HOST+path,headers={
            'User-Agent':UA,'Accept':'text/html,application/xhtml+xml;q=0.9',
            'Accept-Encoding':'identity','Cache-Control':'no-transform'})
        try:
            with urllib.request.urlopen(request,timeout=9) as r:
                # Never read/store a page body or an authenticated cookie.
                status=r.status;header=r.headers
                ready=header.get('X-MAD4B-Mobile-CSS-Ready');native=header.get('X-MAD4B-Mobile-CSS-Native-Complete')
                found[lang]={'http_status':int(status),'css_ready':int(ready) if ready in ('0','1') else None,
                             'native_complete':int(native) if native in ('0','1') else None}
        except Exception:
            found[lang]={'http_status':503,'css_ready':None,'native_complete':None}
    return found

def main():
    previous=load(REPORT)
    state=evaluate(previous,observe(),int(time.time()))
    atom(REPORT,state)
    label='PERFORMANCE_CONTINUITY_WAYFA_'+('ALERT' if state['alert'] else state['state'].upper())
    print(label,json.dumps({'state':state['state'],'age_seconds':state['degraded_seconds'],
          'ready':{k:v['css_ready'] for k,v in state['routes'].items()},
          'priority':state['recovery_priority']},sort_keys=True))
    return 0
if __name__=='__main__':
    try:sys.exit(main())
    except (ValueError,OSError,TypeError,json.JSONDecodeError) as e:
        print('HOLD_PERFORMANCE_CONTINUITY_WAYFA',type(e).__name__,file=sys.stderr);sys.exit(4)
