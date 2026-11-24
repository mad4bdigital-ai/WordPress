#!/usr/bin/env python3
"""Independent bounded public HTTP verification; never writes native certificates."""
from pathlib import Path
import datetime,fcntl,json,os,re,subprocess,sys,time,hashlib,urllib.parse
ROOT=Path(__file__).resolve().parent
STATE=Path('/opt/mad4b-company-provisioning/reports/native-lazy-http-continuity-20261124')
SITES={'tours.dev.mad4b.com':'php-wkgtkzta35wkkgouwt6ruljc','wayfatours.com':'php-guxhp9y6vgh7oouuw8plxrlr'}
def cmd(args,timeout=40):
    result=subprocess.run(args,check=False,capture_output=True,text=True,timeout=timeout)
    if result.returncode:
        raise RuntimeError('command failed exit='+str(result.returncode)+': '+result.stderr.strip().splitlines()[-1][:160] if result.stderr.strip() else 'command failed exit='+str(result.returncode))
    return result.stdout
def dump(p,x):
    t=p.with_suffix('.tmp');t.write_text(json.dumps(x,ensure_ascii=False,indent=2));os.replace(t,p)
def copy(src,container,dest):
    cmd(['docker','cp',str(src),container+':'+dest],10)
    cmd(['docker','exec','-u0',container,'chmod','644',dest],10)
def observe(c,cfg,path):
    dump(path,cfg)
    copy(path,c,'/tmp/mad4b-http-family-observe-config.json')
    out=cmd(['docker','exec',c,'wp','--allow-root','eval-file','/tmp/mad4b-http-observe-family.php'],35)
    x=json.loads(out)
    if x.get('host')!=cfg['host'] or x.get('locale')!=cfg['locale']:raise ValueError('observation identity mismatch')
    return x
def scope_key(x):
    return '|'.join(str(x[k]) for k in ('locale','page_id','document_id','widget_id','query_id','listing_id'))
def eligible_rows(data,host):
    if data.get('contract')!='mad4b.native-lazy.auto-discovery.v1' or data.get('site_host')!=host:raise ValueError('untrusted discovery')
    rows=[]
    for row in data['candidates']:
        u=urllib.parse.urlsplit(row['url'])
        if u.scheme!='https' or u.netloc!=host or u.query or u.fragment or row.get('query_type')!='terms':continue
        if not re.fullmatch(r'[a-f0-9]{7,9}',row['widget_id']):continue
        if any(type(row[k]) is not int or row[k]<1 for k in ('page_id','document_id','query_id','listing_id')):continue
        if not re.fullmatch(r'[a-z]{2}(?:-[a-z]{2})?',row['locale']):continue
        rows.append({**row,'host':host})
    return sorted(rows,key=scope_key)
def same_source(a,b):
    return all(a.get(k)==b.get(k) for k in ('source_sha256','query_generation','global_data_revision','css_epoch','cli_market','locale','host'))
def run(host,limit,target_locale=None,target_query=None):
    container=SITES[host];d=STATE/host;d.mkdir(parents=True,exist_ok=True)
    statefile=d/'state.json';state=json.loads(statefile.read_text()) if statefile.exists() else {'page_cursor':0,'last_key':''}
    stamp=datetime.datetime.now(datetime.timezone.utc).strftime('%Y%m%dT%H%M%SZ')
    dest=d/stamp;dest.mkdir()
    copy(ROOT/'discover-public-listings.php',container,'/tmp/mad4b-http-discover-public.php')
    copy(ROOT/'observe-family.php',container,'/tmp/mad4b-http-observe-family.php')
    raw=cmd(['docker','exec','-e','MAD4B_COVERAGE_CURSOR='+str(state['page_cursor']),'-e','MAD4B_COVERAGE_PAGE_BATCH=30',container,'wp','--allow-root','eval-file','/tmp/mad4b-http-discover-public.php'],55)
    data=json.loads(raw);rows=eligible_rows(data,host);dump(dest/'discovery.json',data)
    targeted=target_locale is not None
    pending=([r for r in rows if r['locale']==target_locale and (target_query is None or r['query_id']==target_query)] if targeted else [r for r in rows if scope_key(r)>state.get('last_key','')])
    if not pending:
        if targeted:raise ValueError('requested cohort unavailable')
        state={'page_cursor':data['page_scan']['next_cursor'],'last_key':''};dump(statefile,state)
        print('NEXT_DISCOVERY_BATCH',host,state['page_cursor']);return
    results=[]
    for idx,cfg in enumerate(pending[:limit]):
        if float(Path('/proc/loadavg').read_text().split()[0])>=5.6:
            print('DEFER_RESOURCE_CEILING',host);break
        path=dest/('cohort-%02d.json'%idx)
        result={'host':host,'locale':cfg['locale'],'query_id':cfg['query_id'],'listing_id':cfg['listing_id'],'document_id':cfg['document_id'],'scope_key':scope_key(cfg),'status':'hold','native_certificate_written':False,'scope_generation_available':cfg.get('query_generation') is not None}
        try:
            pre=observe(container,cfg,path)
            process=subprocess.run([sys.executable,str(ROOT/'public-ssr-parity.py'),str(path)],capture_output=True,text=True,timeout=55)
            http=json.loads(process.stdout)
            post=observe(container,cfg,path)
            stable=same_source(pre,post)
            result.update(http=http,source_before=pre,source_after=post,source_stable=stable,
                 status='pass_functional' if process.returncode==0 and http.get('ordered_semantics_match') is True and stable else 'hold')
        except Exception as e:
            result['reason']=str(e)[:180]
        result['observed_at']=int(time.time())
        if result['status']=='pass_functional':
            try:
                receiptfile=dest/('registration-input-%02d.json'%idx)
                dump(receiptfile,result)
                copy(ROOT/'register-http-observation.php',container,'/tmp/mad4b-register-http-observation.php')
                copy(receiptfile,container,'/tmp/mad4b-http-family-observation-result.json')
                result['observation_registration']=json.loads(cmd(['docker','exec',container,'wp','--allow-root','eval-file','/tmp/mad4b-register-http-observation.php'],35))
            except Exception as e:
                result['observation_registration']={'registered':False,'reason':str(e)[:180]}
        dump(dest/('result-%02d.json'%idx),result)
        results.append({k:result.get(k) for k in ('host','locale','query_id','listing_id','document_id','status','source_stable','scope_generation_available','native_certificate_written')})
        print('HTTP_CONTINUITY',json.dumps(results[-1]),flush=True)
        if not targeted:
            state['last_key']=scope_key(cfg)
            dump(statefile,state)
    dump(dest/'summary.json',{'contract':'mad4b.native-listing.scheduled-http-continuity.v1','site':host,'discovered':len(rows),'results':results,'market_attested':False,'native_certificate_written':False})
    if not targeted and len(results)==len(pending):
        dump(statefile,{'page_cursor':data['page_scan']['next_cursor'],'last_key':''})
    print('SUMMARY',host,len(results),sum(r['status']=='pass_functional' for r in results))
def main():
    os.umask(0o077);STATE.mkdir(parents=True,exist_ok=True)
    with open('/run/lock/mad4b-native-lazy-http-continuity.lock','w') as lock:
        try:fcntl.flock(lock,fcntl.LOCK_EX|fcntl.LOCK_NB)
        except BlockingIOError:print('SKIP existing HTTP continuity');return
        if float(Path('/proc/loadavg').read_text().split()[0])>=5.6:print('DEFER_RESOURCE_CEILING');return
        host=sys.argv[1] if len(sys.argv)>1 else None
        if not host:
            f=STATE/'next-site';host=f.read_text().strip() if f.exists() else next(iter(SITES))
            if host not in SITES:host=next(iter(SITES))
            f.write_text(next(h for h in SITES if h!=host))
        if host not in SITES:raise ValueError('unknown site')
        run(host,max(1,min(2,int(sys.argv[2]) if len(sys.argv)>2 else 2)),sys.argv[3] if len(sys.argv)>3 else None,int(sys.argv[4]) if len(sys.argv)>4 else None)
if __name__=='__main__':main()
