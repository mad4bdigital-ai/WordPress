#!/usr/bin/env python3
"""Original 422 V2 bulk post-release QA: one exact pending route, no pre-QA veto."""
import fcntl,hashlib,importlib.util,json,os,re,subprocess,sys,time
from pathlib import Path
HERE=Path(__file__).resolve().parent
spec=importlib.util.spec_from_file_location('mad4b_original_public',HERE/'run-public-route.py')
runner=importlib.util.module_from_spec(spec);spec.loader.exec_module(runner)
isspec=importlib.util.spec_from_file_location('mad4b_original_v2_issuer',HERE/'public-ordered-critical-certificate-v2.py')
issuer=importlib.util.module_from_spec(isspec);isspec.loader.exec_module(issuer)
ROOT=runner.ROOT/'reports/public-critical-css-v1'
def read(p,limit=2500000):
    if p.is_symlink() or not p.is_file() or not 0<p.stat().st_size<=limit:
        raise ValueError('unsafe-or-missing-original-bulk-record')
    return json.loads(p.read_text())
def atomic(p,data):
    tmp=p.with_name('.'+p.name+'.pending-'+str(os.getpid()))
    with tmp.open('x') as f:
        os.fchmod(f.fileno(),0o600)
        json.dump(data,f,sort_keys=True,indent=2);f.write('\n')
    os.replace(tmp,p)
def lock(path):
    f=path.open('a+')
    fcntl.flock(f,fcntl.LOCK_EX|fcntl.LOCK_NB)
    return f
def pending_rows(site):
    base=ROOT/site
    rows=[]
    for folder in base.iterdir():
        if not folder.is_dir() or folder.is_symlink() or not re.fullmatch(r'20\d{6}T\d{6}Z',folder.name):
            continue
        mark=folder/'bulk-pending.json'
        if (folder/'bulk-complete.json').exists():
            continue
        if not mark.is_file():
            # Recover only a genuine published 422 lease from a prior
            # intent, never from an intent alone. Source, route, market,
            # CSS manifest and installed cert must all agree.
            intent_path=folder/'bulk-intent.json'
            try:
                intent=read(intent_path,8192)
                if (intent.get('contract')!='mad4b.css.v2.bulk.intent.v1'
                    or intent.get('site')!=site
                    or intent.get('host')!=runner.CONFIG[site][0]
                    or intent.get('jobdir')!=str(folder)
                    or not re.fullmatch('[a-f0-9]{64}',str(intent.get('route_key','')))):
                    continue
                previous=read(cert_path(site,intent))
                if (previous.get('status')!='released_pending_qa'
                    or previous.get('host')!=intent['host']
                    or previous.get('path')!=intent['path']
                    or previous.get('language')!=intent['language']
                    or previous.get('market_country')!=intent['market_country']
                    or previous.get('currency')!=intent['currency']
                    or previous.get('source_manifest_sha256')!=intent['manifest_sha256']):
                    continue
                recovered={**intent,'contract':'mad4b.css.v2.bulk.pending.v1',
                           'released_at':previous['released_at'],
                           'state':'pending_qa'}
                atomic(mark,recovered)
                print('BULK_CCSS_RECOVERED_PUBLISHED_INTENT',site,
                      intent['route_key'],flush=True)
            except (ValueError,OSError,KeyError,TypeError,json.JSONDecodeError):
                continue
        try:
            doc=read(mark,8192)
            if (doc.get('contract')!='mad4b.css.v2.bulk.pending.v1'
                or doc.get('site')!=site or doc.get('host')!=runner.CONFIG[site][0]
                or doc.get('jobdir')!=str(folder)
                or not re.fullmatch('[a-f0-9]{64}',str(doc.get('route_key','')))
                or not isinstance(doc.get('released_at'),int)):
                continue
            rows.append((doc['released_at'],folder,doc))
        except (ValueError,OSError,TypeError,json.JSONDecodeError):continue
    return sorted(rows,key=lambda x:(x[0],str(x[1])))
def cert_path(site,doc):
    host,php,wp,environment=runner.CONFIG[site]
    name=hashlib.sha256((host+'|'+doc['language']+'|'+doc['path']+'|'+
       doc['market_country']+'|'+doc['currency']).encode()).hexdigest()+'.json'
    return wp/'mad4b-public-critical-css-v1/certs-v2'/name
def qa_one(site,folder,doc):
    host,php,wp,environment=runner.CONFIG[site]
    job=read(folder/'selected.json')
    route=read(folder/'route.json')
    state=read(folder/'site.json')
    cat=read(folder/'catalog.json')
    measured=read(folder/'measured-site-v2.json')
    path=cert_path(site,doc)
    cert=read(path)
    if (job.get('route')!=route or route.get('route_key')!=doc['route_key']
        or route.get('path')!=doc['path'] or route.get('language')!=doc['language']
        or cat.get('host')!=host or state.get('site_host')!=host
        or measured.get('currency')!=doc['currency']
        or measured.get('market_country')!=doc['market_country']
        or cert.get('host')!=host or cert.get('path')!=route['path']
        or cert.get('source_manifest_sha256')!=doc['manifest_sha256']
        or cert.get('language')!=route['language']
        or cert.get('market_country')!=doc['market_country']
        or cert.get('currency')!=doc['currency']):
        raise ValueError('bulk-qa-exact-source-market-route-mismatch')
    if cert.get('status')=='certified':
        atomic(folder/'bulk-complete.json',{'contract':'mad4b.css.v2.bulk.qa-result.v1',
          'site':site,'outcome':'superseded_certified',
          'at':int(time.time()),'route_key':doc['route_key']})
        return 'superseded_certified'
    if cert.get('status')!='released_pending_qa':
        atomic(folder/'bulk-complete.json',{'contract':'mad4b.css.v2.bulk.qa-result.v1',
          'site':site,'outcome':'superseded_or_expired',
          'at':int(time.time()),'route_key':doc['route_key']})
        return 'superseded_or_expired'
    args=['python3',str(HERE/'public-ordered-critical-certificate-v2.py'),
          str(folder/'catalog.json'),str(folder/'route.json'),
          str(folder/'measured-site-v2.json'),str(folder),str(wp),
          str(wp/'mu-plugins/422-mad4b-public-critical-css-runtime.php'),
          str(wp/'mu-plugins/422-mad4b-public-critical-css-v2-consumer.php')]
    outcome='reverted'
    phase='revert'
    reason='expired_or_source_changed'
    try:
        if cert.get('post_release_deadline',0)<=int(time.time()):
            raise ValueError('original-six-hour-lease-expired')
        def live_state():
            s=runner.obs(php,host,environment)
            s['runtime_sha']=runner.approved_native_runtime_sha(site,wp)
            h=runner.approved_v2_consumer_sha(site,wp,s['runtime_sha'])
            if h:s['consumer_v2_sha']=h
            s['qa_policy_sha']=runner.qa_policy_sha()
            return s
        if live_state()!=state:
            raise ValueError('original-WordPress-source-changed-before-postrelease-QA')
        current=next((x for x in runner.catalog(php,host)['routes']
                      if x.get('route_key')==route['route_key']),None)
        if current!=route:raise ValueError('original-route-changed-before-postrelease-QA')
        runner.browser(route,wp,host,environment,'verify-ordered-css.js',
          folder/'bulk-ordered-qa.log',{'MAD4B_WIDTHS':'320,390,414,767,768'},limit=260)
        runner.browser(route,wp,host,environment,'verify-native-ordered-critical.js',
          folder/'bulk-native-qa.log',{},limit=880)
        a=runner.run(['python3',str(HERE/'public-ordered-critical-admission.py'),str(folder)],timeout=25)
        receipts=[line.removeprefix('ORDERED_CRITICAL_ADMISSION ')
                  for line in a.splitlines() if line.startswith('ORDERED_CRITICAL_ADMISSION ')]
        if len(receipts)!=1:raise ValueError('original-browser-verdict-missing')
        evidence=json.loads(receipts[0])
        if (evidence.get('decision')!='qualified_for_delivery_review'
            or evidence.get('source_manifest_sha256')!=doc['manifest_sha256']
            or evidence.get('market_country')!=doc['market_country']
            or evidence.get('currency')!=doc['currency']):
            raise ValueError('original-postrelease-5width-QA-not-qualified')
        if live_state()!=state:raise ValueError('WordPress-source-changed-during-QA')
        current=next((x for x in runner.catalog(php,host)['routes']
                     if x.get('route_key')==route['route_key']),None)
        if current!=route:raise ValueError('route-revision-changed-during-QA')
        runner.run(args+['verify'],timeout=42)
        phase='revoke'
        final=read(path)
        if (final.get('status')!='certified'
            or final.get('source_manifest_sha256')!=doc['manifest_sha256']
            or final.get('evidence',{}).get('tested')!=5):
            raise ValueError('atomic-certified-V2-readback-failed')
        runner.run(['python3',str(HERE/'public-route-cache-release.py'),
           'before_capture',site,str(wp),str(folder/'catalog.json'),
           str(folder/'route.json'),'-',str(folder/'bulk-qualified-cache.json')],timeout=28)
        if site=='wayfa':
            runner.run(['python3',str(HERE/'public-v2-existing-readback.py'),
                site,str(wp),str(folder/'catalog.json'),
                str(folder/'route.json'),str(folder/'site.json'),
                str(folder/'bulk-origin-readback.json')],timeout=38)
            readback=read(folder/'bulk-origin-readback.json')
            depends=bool(readback['depends_on_listing'])
        else:
            depends=bool(final['dependencies']['contains_dynamic_listing'])
        # The original queue source-key and exact source revision must still
        # match before crediting the independently completed certificate.
        runner.run(['python3',str(HERE/'public-route-queue.py'),'record',site,
            str(folder/'catalog.json'),str(folder/'site.json'),
            str(ROOT/site/'route-ledger.json'),str(folder/'selected.json'),
            'certified','listing' if depends else 'no-listing'],timeout=32)
        outcome='certified'
        reason='original-five-viewport-and-public-source-readback-pass'
    except (ValueError,OSError,KeyError,TypeError,subprocess.CalledProcessError,
            subprocess.TimeoutExpired,json.JSONDecodeError) as exc:
        reason=(type(exc).__name__+':'+str(exc))[:180]
        # No failed or unverified CSS may remain publicly provisional.
        try:
            runner.run(args+[phase],timeout=42)
            runner.run(['python3',str(HERE/'public-route-cache-release.py'),
                'before_capture',site,str(wp),str(folder/'catalog.json'),
                str(folder/'route.json'),'-',str(folder/'bulk-reverted-cache.json')],timeout=30)
        except (ValueError,OSError,subprocess.CalledProcessError,
                subprocess.TimeoutExpired) as rollback:
            # Leave pending record for the next original WP-Cron retry.
            print('BULK_CCSS_QA_ROLLBACK_RETRY',site,route['path'],
                  str(rollback)[:150],flush=True)
            return 'rollback_retry'
        try:
            runner.run(['python3',str(HERE/'public-route-queue.py'),'record',site,
                str(folder/'catalog.json'),str(folder/'site.json'),
                str(ROOT/site/'route-ledger.json'),str(folder/'selected.json'),
                'hold'],timeout=30)
        except (ValueError,OSError,subprocess.CalledProcessError):
            pass  # Different source revision; never write old queue result.
    atomic(folder/'bulk-complete.json',{'contract':'mad4b.css.v2.bulk.qa-result.v1',
       'site':site,'route_key':doc['route_key'],'at':int(time.time()),
       'outcome':outcome,'reason':reason,
       'postrelease_tested':outcome=='certified'})
    return outcome
def main():
    if len(sys.argv)!=2 or sys.argv[1] not in runner.CONFIG:
        raise ValueError('usage: bulk-postrelease-qa.py source|wayfa')
    site=sys.argv[1]
    locks=[]
    try:
        locks=[lock(Path('/run/lock/mad4b-public-critical-css-'+site+'.lock')),
               lock(Path('/run/lock/mad4b-native-lazy-browser-global.lock'))]
    except BlockingIOError:
        print('BULK_CCSS_QA_HOST_BUSY',site);return
    admission=subprocess.run(['python3',str(HERE/'public-route-cpu-guard.py')],
         capture_output=True,text=True,timeout=7)
    if admission.returncode:
        print('BULK_CCSS_QA_DEFER_RESOURCE',site,admission.stdout[:120]);return
    rows=pending_rows(site)
    if not rows:
        print('BULK_CCSS_QA_IDLE',site);return
    _,folder,doc=rows[0]
    outcome=qa_one(site,folder,doc)
    if outcome=='rollback_retry':
        print('BULK_CCSS_QA_ROLLBACK_PENDING',site,doc['route_key'])
        return
    print('BULK_CCSS_QA_COMPLETED',site,outcome,doc['route_key'],
          doc['language'],doc['path'],flush=True)
if __name__=='__main__':
    try:main()
    except (OSError,ValueError,KeyError,TypeError,
            subprocess.TimeoutExpired,subprocess.CalledProcessError) as e:
        print('BULK_CCSS_QA_HOLD',type(e).__name__,str(e)[:200],file=sys.stderr)
        sys.exit(4)
