#!/usr/bin/env python3
"""Read-only Critical CSS operational integrity audit: both enrolled companies.

Not a browser performance certification, a CSS mutation, or a grant of
readiness. Live WordPress WP-CLI + actual anonymous HTTP; no payment routes.
"""
import calendar,hashlib,importlib.util,json,os,pathlib,re,subprocess,sys,tempfile,time
from pathlib import Path
D=Path(__file__).resolve().parent
SITES={
 'source':{
  'host':'tours.dev.mad4b.com',
  'root':Path('/opt/hostinger-migrations/hosting-3/etg-commerce-staging/site-copy/wp-content'),
  'php':'php-wkgtkzta35wkkgouwt6ruljc'},
 'wayfa':{
  'host':'wayfatours.com',
  'root':Path('/opt/wayfa-rebuild-20261102/site-copy/wp-content'),
  'php':'php-guxhp9y6vgh7oouuw8plxrlr'}
}
REPO=Path('/opt/mad4b-company-provisioning/worktrees/pr814-converged-local-20261115')
CHROMIUM_REPORT_ROOT=Path('/opt/mad4b-company-provisioning/reports/dynamic-critical-css-20261116')
HOME_SHA={
 'source':'75e305345f46af0ec4dbed36f40def63626108de85f938238853b27674c11794',
 'wayfa':'cdb55bd9ab6a86df26483450e33cf0bbc91296940db51102dde082fdb839db5b'
}
REPORT=Path('/opt/mad4b-company-provisioning/reports/critical-css-health-v1')
UA='Mozilla/5.0 (Linux; Android 14; Pixel 7) AppleWebKit/537.36 Chrome/157.0.0.0 Mobile Safari/537.36'
AC='text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,image/apng,*/*;q=0.8'
def load(path,name):
    spec=importlib.util.spec_from_file_location(name,path)
    module=importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module
queue=load(D/'public-route-queue.py','ccss_health_queue')
status=load(D/'public-route-status.py','ccss_health_status')
assess=load(D/'assess.py','ccss_health_original_home_assess')
runner=load(D/'run-public-route.py','ccss_health_runner')
source_family=load(D/'public-source-families.py','ccss_health_source_families')
route_resume=load(D/'public-route-resume.py','ccss_health_route_resume')
def command(args,timeout=25):
    return subprocess.run(args,capture_output=True,text=True,check=True,timeout=timeout).stdout.strip()
def sha(path):
    return hashlib.sha256(path.read_bytes()).hexdigest()
def attest_files(root,site=None):
    # Original public runtime admission already recognizes the *exact*
    # independently approved staging 422 SHA, while requiring original
    # 421 parity on both sites and original 422 parity on Wayfatours.
    # Do not falsely reject this enrolled Source Staging source release.
    if site is not None:
        runner.approved_native_runtime_sha(site,root)
        return ['421-mad4b-public-critical-css-coverage.php',
                '422-mad4b-public-critical-css-runtime.php']
    # Hermetic fixture and legacy original-parity test.
    checks=[]
    for name in ('421-mad4b-public-critical-css-coverage.php',
                 '422-mad4b-public-critical-css-runtime.php'):
        src=REPO/'wp-content/mu-plugins'/name
        live=root/'mu-plugins'/name
        if not src.is_file() or not live.is_file() or src.is_symlink() or live.is_symlink():
            raise ValueError('missing-or-symbolic-native-component:'+name)
        if sha(src)!=sha(live):raise ValueError('native-component-drift:'+name)
        checks.append(name)
    return checks
def file_permissions(root):
    files=root/'mad4b-public-critical-css-v1'
    for entry in (files,files/'assets',files/'certs'):
        if entry.is_symlink() or not entry.is_dir():
            raise ValueError('missing-or-symlink-public-css-directory')
        mode=entry.stat().st_mode&0o777
        if mode & 0o022 or mode & 0o005 != 0o005:
            raise ValueError('unsafe-or-unreadable-public-css-directory:'+str(entry))
    return True
def unit_health(site):
    """Read the *actual* WP-Cron owner, not retired systemd CSS timers.

    The native CSS policy and existing signed certificate checks remain
    independent; an unavailable audit cannot deactivate frontend CSS.
    """
    if site not in SITES:raise ValueError('unknown-first-party-css-site')
    cronfile=Path('/etc/cron.d/mad4b-css-wpcron')
    expected_cron=D/'wpcron/mad4b-css-wpcron.cron'
    expected_plugin=D/'wpcron/429-mad4b-css-wpcron-dispatch-v1.php'
    plugin=SITES[site]['root']/'mu-plugins/429-mad4b-css-wpcron-dispatch-v1.php'
    for current,expected in ((cronfile,expected_cron),(plugin,expected_plugin)):
        if (current.is_symlink() or expected.is_symlink()
            or not current.is_file() or not expected.is_file()
            or current.stat().st_uid!=0 or current.stat().st_mode&0o022
            or sha(current)!=sha(expected)):
            raise ValueError('wpcron_scheduler_source_or_permission_drift:'+str(current))
    if command(['systemctl','is-active','cron'],8)!='active':
        raise ValueError('wpcron_host_wakeup_daemon_not_active')
    # Duplicate ownership would run two independent workers. Verify the
    # retired scheduling is genuinely disabled before reporting healthy.
    retired=[
        'mad4b-critical-css-'+site+'.timer',
        'mad4b-public-critical-css-'+site+'.timer',
        'mad4b-public-source-families-refresh.timer',
        'mad4b-post-policy-visual-watch.timer',
        'mad4b-critical-css-integrity-audit.timer',
        'mad4b-critical-css-evidence-retention.timer']
    for unit in retired:
        check=subprocess.run(['systemctl','is-enabled',unit],
            capture_output=True,text=True,timeout=8,check=False)
        if check.stdout.strip() not in ('disabled','masked'):
            raise ValueError('duplicate-systemd-css-timer-ownership:'+unit)
    wp=runner.wp_eval(SITES[site]['php'],r"""
$class='MAD4B_CCSS_WPCron_Dispatch_V1';
if(!class_exists($class,false))exit(3);
$now=time();$tick=wp_next_scheduled($class::TICK);
$state=$class::snapshot();
echo wp_json_encode([
 'contract'=>$class::CONTRACT,'site'=>$class::site(),
 'host'=>(string)wp_parse_url(home_url('/'),PHP_URL_HOST),
 'next_tick'=>$tick===false?0:(int)$tick,
 'last_tick'=>(int)($state['last_tick']??0),
 'pending_count'=>count((array)($state['pending']??[]))
],JSON_UNESCAPED_SLASHES),"\n";
""")
    if (wp.get('contract')!='mad4b.css.wpcron.dispatch.v1'
        or wp.get('site')!=site or wp.get('host')!=SITES[site]['host']
        or not isinstance(wp.get('next_tick'),int) or wp['next_tick']<=0):
        raise ValueError('wpcron_live_scheduler_not_admitted:'+site)
    if wp['next_tick']<int(time.time())-1800:
        raise ValueError('wpcron_event_overdue_beyond_30_minutes:'+site)
    return {'engine':'wpcron_native','host_wake':'cron_5_min',
        'wp_next_tick_epoch':wp['next_tick'],
        'wp_last_tick_epoch':wp['last_tick'],
        'pending_jobs':wp['pending_count'],
        'retired_systemd_timers_disabled':True,
        'wpcron_source_integrity':'verified'}
def http_get(host,path):
    if (not isinstance(path,str) or not re.fullmatch(r'/[A-Za-z0-9/_%.\-]{0,349}',path)
        or '//' in path or '..' in path or re.search(r'%2f|%5c',path,re.I)):
        raise ValueError('untrusted-public-route')
    if any(x in path.lower().split('/') for x in ('checkout','cart','my-account','wp-admin','wp-json','order-pay')):
        raise ValueError('sensitive-route-blocked')
    # Discard full HTML; headers are read from actual anonymous GET,
    # not HEAD (where litespeed_buffer_finalize may not execute).
    raw=command(['curl','-sS','--max-time','18','-D','-','-o','/dev/null',
                 '-A',UA,'-H','Accept: '+AC,'https://'+host+path],23)
    fields={}
    for line in raw.replace('\r','').splitlines():
        if line.startswith('HTTP/'):fields['http']=line.strip()
        elif ':' in line:
            k,v=line.split(':',1)
            fields[k.strip().lower()]=v.strip()
    return {
       'http':fields.get('http',''),
       'ready_home':fields.get('x-mad4b-mobile-css-ready'),
       'ready_public':fields.get('x-mad4b-public-ccss-ready'),
       'market':fields.get('x-mad4b-public-ccss-market'),
       'currency':fields.get('x-mad4b-public-ccss-currency'),
       'cache':fields.get('x-wayfa-origin-cache',fields.get('x-mad4b-preset-cache'))
    }
def verify_recent_chromium_home(site,lang,root,current_state,current_home_dirty,max_age=7*86400):
    # A generic curl probe can receive a different cache/client-hints
    # variant from the independently measured mobile Chromium profile.
    # Only accept existing ORIGINAL browser HTML + headers if the first-
    # party manifest, local CSS bytes, doc fingerprint, CSS epoch, active
    # language and home dependency generation still match current WordPress.
    base=CHROMIUM_REPORT_ROOT/site
    if base.is_symlink() or not base.is_dir():return None
    runnames=sorted((x for x in base.iterdir() if x.is_dir()
             and re.fullmatch(r'20[0-9]{6}T[0-9]{6}Z',x.name)),reverse=True)
    for work in runnames[:10]:
        if work.is_symlink() or (work/'observed').is_symlink():continue
        try:
            start=calendar.timegm(time.strptime(work.name,'%Y%m%dT%H%M%SZ'))
        except ValueError:continue
        age=int(time.time())-int(start)
        if age<0 or age>max_age:continue
        src=work/'site.json'
        html=work/'observed'/(lang+'.html')
        headers=work/'observed'/(lang+'.headers')
        if any(x.is_symlink() or not x.is_file() for x in (src,html,headers)):continue
        if (src.stat().st_size>8192 or html.stat().st_size>6_000_000
            or headers.stat().st_size>16384):continue
        try:
            source=json.loads(src.read_text())
            if (source.get('site_host')!=current_state.get('site_host')
                or source.get('environment')!=current_state.get('environment')
                or source.get('css_epoch')!=current_state.get('css_epoch')
                or source.get('critical_css')!='on'
                or source.get('native_breakpoint')!=current_state.get('native_breakpoint')
                or source.get('critical_css_dirty_generation')!=current_home_dirty
                or source.get('wpml_languages')!=current_state.get('wpml_languages')
                or lang not in source.get('wpml_languages',[])):
                continue
            proof=assess.classify(site,root,source,lang,
                    html.read_text(errors='replace'),headers.read_text().replace(chr(13),''))
            if proof.get('status')=='certified_active':
                return {'result':'certified_active_in_chromium',
                        'captured_at_utc':work.name,'css_sha':proof['css_sha'],
                        'critical_cap_px':proof.get('critical_cap_px')}
        except (OSError,ValueError,KeyError,TypeError,IndexError,AssertionError,
                json.JSONDecodeError):continue
    return None

def coverage_completion(coverage):
    """Operational health is not permission to claim complete CSS coverage."""
    total=int(coverage['discovered'])
    certified=int(coverage['certified_ledger_only'])
    native=int(coverage['native_preferred_full_css'])
    qualified=certified+native
    held=int(coverage['held_full_css'])
    pending=int(coverage['needs_certification'])+int(coverage['pending_native_confirmation'])
    if min(total,certified,native,held,pending)<0 or qualified+held+pending!=total:
        raise ValueError('INVALID_COVERAGE_PARTITION')
    complete=total>0 and qualified==total
    return {
        'status':'complete' if complete else 'rolling',
        'fully_qualified':complete,
        'total_public_routes':total,
        'certified_ledger_only':certified,
        'independently_native_preferred':native,
        'needs_certification_or_hold':held+pending,
        'qualified_percent':round(100*qualified/total,2) if total else 0.0,
        'note':'Route ledger completion only; verified market-specific browser readback is separate',
    }

def current_chromium_route_evidence(site,root,catalog,route,state,market,currency,
                                    max_age=7*86400):
    """Recognize trusted Chrome proof when generic curl uses another LSC CSS.

    This is read-only; it never declares the generic HTTP variant Ready:1.
    The original 422/route-resume verifier checks the *currently installed*
    route certificate, CSS bytes, source generations, DOM and WCML market.
    """
    if site not in SITES or str(root)!=str(SITES[site]['root']):return None
    if not isinstance(market,str) or not isinstance(currency,str):return None
    reports=REPORT.parent
    base=reports/'public-critical-css-v1'/site
    alternate=reports/'critical-css-health-v1'
    sources=[]
    if base.is_dir() and not base.is_symlink():
        sources.extend(sorted((x for x in base.glob('20*') if x.is_dir() and not x.is_symlink()),reverse=True)[:20])
    if site=='wayfa' and alternate.is_dir() and not alternate.is_symlink():
        sources.extend(sorted((x for x in alternate.glob('contact-hero-wayfa-*')
                   if x.is_dir() and not x.is_symlink()),reverse=True)[:5])
    for folder in sources:
        r=folder/'route.json'
        if r.is_symlink() or not r.is_file() or r.stat().st_size>8192:continue
        try:
            selected=json.loads(r.read_text())
            if (selected.get('route_key')!=route['route_key']
                or selected.get('path')!=route['path']
                or selected.get('id')!=route['id']):continue
        except (OSError,ValueError,TypeError,json.JSONDecodeError):continue
        for name in ('readback','existing-certificate'):
            proof=folder/name
            if not proof.is_dir() or proof.is_symlink():continue
            head=proof/(route['language']+'.headers')
            html=proof/(route['language']+'.html')
            if any(f.is_symlink() or not f.is_file() for f in (head,html)):continue
            if (head.stat().st_size>16384 or html.stat().st_size>6000000
                or html.stat().st_size<1000):continue
            age=time.time()-min(head.stat().st_mtime,html.stat().st_mtime)
            if not -60<=age<=max_age:continue
            try:
                matched=route_resume.ok_cert(site,root,catalog,route,state,
                    head.read_text(),html.read_text(errors='replace'))
                if (matched and matched.get('market')==market
                    and matched.get('currency')==currency):
                    return {'decision':'current_original_chromium_certificate',
                            'site':site,'language':route['language'],
                            'market':market,'currency':currency,
                            'evidence_folder':folder.name,
                            'css_sha':matched['css_sha']}
            except (OSError,ValueError,KeyError,TypeError,json.JSONDecodeError):continue
    return None

def run_site(site,run_http=True):
    cfg=SITES[site];root=cfg['root'];host=cfg['host']
    errors=[];warnings=[]
    result={'site':site,'host':host,'at_utc':time.strftime('%Y-%m-%dT%H:%M:%SZ',time.gmtime()),
            'status':'unknown','issues':errors,'warnings':warnings}
    result['file_parity']=attest_files(root,site)
    if site=='source':
        # This original first-party HTTPS source CSS observation is advisory:
        # it cannot certify a page or deactivate an already admitted policy.
        # Its absence/staleness must never be reported as fully stable.
        import importlib.util
        proof_path=D/'source-home-delivery-admission.py'
        proof_module=load(proof_path,'ccss_source_home_delivery_admission')
        proof=Path('/opt/mad4b-company-provisioning/reports/dynamic-critical-css-20261116/source/home-current-css-source.json')
        stable,reason=proof_module.evaluate(proof)
        result['home_css_source_continuity']={
            'exact_six_locale_source_proven':stable,'reason':reason}
        if not stable:
            warnings.append('source_home_css_source_continuity_unverified:'+reason)
    home_runtime=root/'mu-plugins/415-mad4b-critical-css-orchestrator.php'
    if (home_runtime.is_symlink() or not home_runtime.is_file()
        or sha(home_runtime)!=HOME_SHA[site]):
        raise ValueError('original-home-consumer-unexpected-version')
    result['home_consumer_sha']=HOME_SHA[site]
    result['public_asset_directory_readable']=file_permissions(root)
    result['timers']=unit_health(site)
    manifest=root/'mad4b-critical-css-v1/manifest.json'
    if manifest.is_symlink() or not manifest.is_file() or manifest.stat().st_size>65536:
        raise ValueError('home-manifest-missing-or-unsafe')
    home=json.loads(manifest.read_text())
    if home.get('site')!=host or not isinstance(home.get('entries'),dict):
        raise ValueError('home-manifest-site-mismatch')
    result['home_manifest_entries']=len(home['entries'])
    state=runner.obs(cfg['php'],host,'staging' if site=='source' else 'production')
    state['runtime_sha']=sha(root/'mu-plugins/422-mad4b-public-critical-css-runtime.php')
    catalog=runner.catalog(cfg['php'],host)
    ledger=queue.read_ledger(Path('/opt/mad4b-company-provisioning/reports/public-critical-css-v1')/site/'route-ledger.json',site)
    coverage=status.build(site,catalog,state,ledger)
    result['coverage']={k:coverage[k] for k in
        ('discovered','certified_ledger_only','held_full_css','native_preferred_full_css',
         'pending_native_confirmation','needs_certification')}
    result['languages']={lang:sum(counts.values()) for lang,counts in coverage['by_language'].items()}
    result['coverage_completion']=coverage_completion(coverage)
    result['coverage_by_language']=coverage['by_language']
    # Source/template canary certification is the primary QA unit. Keep
    # per-route CSS-ready evidence separate; unsampled objects remain native.
    sources=source_family.build(site,catalog,state,ledger,root)
    result['source_certification']={k:sources[k] for k in (
       'source_groups_total','source_groups_by_status',
       'source_canary_qualified_groups','routes_discovered',
       'routes_with_exact_current_certificate','routes_on_native_or_unproven_css',
       'source_lineage_note')}
    result['source_progress_slo']={'status':'not_yet_reported'}
    monitor=REPORT/'source-progress-latest.json'
    if monitor.is_file() and not monitor.is_symlink() and monitor.stat().st_size<130000:
        with monitor.open() as stream:
            progress=json.load(stream)
        if progress.get('contract')=='mad4b.public-css.source-progress-slo.v1':
            part=progress.get('sites',{}).get(site,{})
            result['source_progress_slo']={
                'status':progress.get('status'),
                'last_success_epoch':progress.get('last_success_epoch'),
                'seconds_since_proven_source_progress':progress.get('seconds_since_proven_source_progress'),
                'registry_age_seconds':part.get('registry_age_seconds'),
                'pending_groups':part.get('pending_groups'),
                'current_proven_market_profiles':part.get('current_proven_market_profiles')}
            if progress.get('status')=='stalled':
                warnings.append('source_qualification_progress_slo_stalled')
    result['source_by_profile']=[{'profile':x['source_profile'],'language':x['language'],
       'type':x['route_type'],'members':x['member_count'],'status':x['status'],
       'proven_markets':x['proven_market_profiles'],
       'currency_scope':x.get('required_currencies',[]),
       'pending_currencies':x.get('currencies_pending',[]),
       'source_graph_status':x.get('source_graph_status')}
       for x in sources['source_families']]
    result['css_epoch']=state['css_epoch']
    report_root=Path('/opt/mad4b-company-provisioning/reports')
    browser_report_root=report_root/'dynamic-critical-css-20261116'/site
    general_report_root=report_root/'public-critical-css-v1'/site
    def directory_bytes(folder):
        if folder.is_symlink() or not folder.is_dir():return 0
        total=0
        for parent,subdirs,filenames in os.walk(folder,followlinks=False):
            subdirs[:]=[d for d in subdirs if not (Path(parent)/d).is_symlink()]
            for name in filenames:
                file=Path(parent)/name
                if not file.is_symlink() and file.is_file():
                    total+=file.stat().st_size
        return total
    result['full_browser_reports_bytes']=directory_bytes(browser_report_root)
    result['route_browser_reports_bytes']=directory_bytes(general_report_root)
    if result['full_browser_reports_bytes']+result['route_browser_reports_bytes']>15*1024**3:
        warnings.append('browser_evidence_report_storage_exceeds_15_gib')
    result['wpml_languages']=state['wpml_languages']
    result['ledger_records']=len(ledger['routes'])
    # HOLD is never a certificate. Record why a route could not be tested,
    # separately from legitimate native CSS fallback and signed success.
    reasons={}
    for v in ledger['routes'].values():
        if not isinstance(v,dict) or v.get('status')!='hold':continue
        reason=v.get('hold_reason') or 'unclassified_hold'
        if not isinstance(reason,str) or not re.fullmatch(r'[a-z_]{3,60}',reason):
            reason='unclassified_hold'
        reasons[reason]=reasons.get(reason,0)+1
    result['hold_reasons']=dict(sorted(reasons.items()))
    attempted=max((int(v.get('attempted_at',0)) for v in ledger['routes'].values()
                   if isinstance(v,dict)),default=0)
    result['last_route_attempt_utc']=time.strftime('%Y-%m-%dT%H:%M:%SZ',time.gmtime(attempted)) if attempted else None
    if (coverage['needs_certification']>0 and attempted
        and int(time.time())-attempted>48*3600):
        warnings.append('route_worker_no_progress_over_48h')
    result['public_certificate_files']=len(list((root/'mad4b-public-critical-css-v1/certs').glob('*.json')))
    result['native_fallback_after_source_dependency_change']=(
        coverage['certified_ledger_only']==0 and result['public_certificate_files']>0)
    # Correct invalidation is safe, not a broken engine. Historical assets
    # stay immutable but never qualify without current source dependencies.
    if run_http:
        result['safe_profile_fallbacks']=[]
        result['home_chromium_evidence']={}
        home_dirty=None
        home_paths=[('/' if l==state['default_language'] else '/'+l+'/',l) for l in state['wpml_languages']]
        result['home_public_readback']={}
        for path,lang in home_paths:
            try:
                probe=http_get(host,path)
                result['home_public_readback'][lang]=probe
                if not probe['http'].startswith('HTTP/2 200') and not probe['http'].startswith('HTTP/1.1 200'):
                    warnings.append('home_http_not_200:'+lang)
                if probe['ready_home']!='1':
                    if home_dirty is None:
                        home_dirty=runner.wp_eval(cfg['php'],
                            'echo wp_json_encode(["dirty"=>(int)get_option("mad4b_critical_css_home_dirty_v1",0)],JSON_UNESCAPED_SLASHES),"\\n";')['dirty']
                    proof=verify_recent_chromium_home(site,lang,root,state,home_dirty)
                    if proof:
                        result['home_chromium_evidence'][lang]=proof
                        result['safe_profile_fallbacks'].append(lang)
                    else:
                        warnings.append('home_native_css_fallback_not_verified_in_browser:'+lang)
            except (OSError,ValueError,subprocess.CalledProcessError,subprocess.TimeoutExpired) as exc:
                warnings.append('home_http_probe_failed:'+lang+':'+str(exc)[:80])
        # Check a small bounded number of ledger-certified public URLs.
        result['public_route_readback']=[]
        result['safe_route_profile_fallbacks']=[]
        for route in catalog['routes']:
            rec=ledger['routes'].get(route['route_key'],{})
            if rec.get('status')!='certified':continue
            if len(result['public_route_readback'])>=2:break
            try:
                probe=http_get(host,route['path'])
                variant={'path':route['path'],'language':route['language'],**probe}
                # Routes may be certified for a different trusted market/currency.
                # Report Ready:0 on the anonymous auditor's market as unproven
                # rather than as a falsely active or corrupt certificate.
                country=probe.get('market');currency=probe.get('currency')
                if country and currency:
                    fingerprint=hashlib.sha256(
                        (host+'|'+route['language']+'|'+route['path']+'|'
                         +country+'|'+currency).encode()).hexdigest()
                    current=root/'mad4b-public-critical-css-v1/certs'/(fingerprint+'.json')
                    variant['certificate_for_observed_market']=current.is_file() and not current.is_symlink()
                else:variant['certificate_for_observed_market']=False
                variant['active_in_observed_market']=(probe['ready_public']=='1')
                result['public_route_readback'].append(variant)
                if not probe['http'].endswith('200'):warnings.append('certified_route_http_not_200:'+route['path'])
                if variant['certificate_for_observed_market'] and not variant['active_in_observed_market']:
                    evidence=current_chromium_route_evidence(site,root,catalog,
                        route,state,country,currency)
                    if evidence:
                        variant['current_chromium_proof']=evidence
                        variant['status']='native_http_variant_with_current_chromium_proof'
                        result['safe_route_profile_fallbacks'].append(route['path'])
                    else:
                        warnings.append('published_certificate_not_active_in_observed_market:'+route['path'])
            except (OSError,ValueError,subprocess.CalledProcessError,subprocess.TimeoutExpired) as exc:
                warnings.append('public_route_probe_failed:'+route['path']+':'+str(exc)[:80])
    result['active_route_samples']=sum(int(p['active_in_observed_market'])
                                       for p in result.get('public_route_readback',[]))
    result['status']=('degraded' if warnings else
                      'healthy_with_safe_fallback' if (
                          result.get('safe_profile_fallbacks')
                          or result.get('safe_route_profile_fallbacks')
                          or result.get('native_fallback_after_source_dependency_change'))
                      else 'healthy')
    return result
def write(path,payload):
    if path.parent.is_symlink() or path.is_symlink():
        raise ValueError('untrusted-audit-report-symlink')
    path.parent.mkdir(parents=True,exist_ok=True,mode=0o700)
    if path.parent.is_symlink() or path.is_symlink():
        raise ValueError('untrusted-audit-report-symlink')
    fd,name=tempfile.mkstemp(prefix='.health-',dir=path.parent)
    try:
        with os.fdopen(fd,'w') as f:
            os.fchmod(f.fileno(),0o600)
            json.dump(payload,f,ensure_ascii=False,sort_keys=True,indent=2);f.write('\n')
            f.flush();os.fsync(f.fileno())
        os.replace(name,path)
    finally:
        if os.path.exists(name):os.unlink(name)
def classify_site_failure(exc):
    # Original WP-CLI admission returns code 75 when other authorized
    # WordPress sessions are running; this is contention, not corrupt CSS,
    # a certificate proof error or a missing user permission.
    if (isinstance(exc,subprocess.CalledProcessError)
        and exc.returncode==75
        and 'STAGING_WPCLI_BUSY' in str((exc.stderr or '')+' '+(exc.stdout or ''))):
        return {'status':'deferred','issues':[],
                'warnings':['word_press_cli_concurrent_sessions_retry_required']}
    return {'status':'error','issues':[type(exc).__name__+':'+str(exc)[:180]]}

def emit_local_alerts(rows):
    # Local host journal only: no SMTP/ecommerce notification side effects.
    # Do not claim an external delivery receipt from a local warning.
    for site,row in rows.items():
        if row.get('status') not in ('degraded','error'):continue
        message='site='+site+' status='+row['status']+' warnings='+','.join(row.get('warnings',[])[:8])
        if row.get('issues'):message+=' errors='+','.join(row.get('issues',[])[:2])
        try:
            subprocess.run(['logger','-p','daemon.err' if row['status']=='error' else 'daemon.warning',
                            '-t','mad4b-ccss-integrity',message[:900]],
                           timeout=5,check=False)
        except (OSError,subprocess.TimeoutExpired):pass

def main():
    if len(sys.argv)!=2 or sys.argv[1] not in ('source','wayfa','all'):
        raise ValueError('usage: health-audit.py source|wayfa|all')
    sites=list(SITES) if sys.argv[1]=='all' else [sys.argv[1]]
    rows={}
    for site in sites:
        try:rows[site]=run_site(site)
        except Exception as exc:rows[site]=classify_site_failure(exc)
    state={'contract':'mad4b.critical-css.integrity-audit.v1',
           'at_utc':time.strftime('%Y-%m-%dT%H:%M:%SZ',time.gmtime()),
           'sites':rows}
    # Per-company audit jobs must not overwrite the historic two-company
    # aggregate latest.json or claim the other company's health.
    suffix=('' if sys.argv[1]=='all' else '-'+sys.argv[1])
    write(REPORT/('latest'+suffix+'.json'),state)
    write(REPORT/(time.strftime('%Y%m%dT%H%M%SZ',time.gmtime())+suffix+'.json'),state)
    print('PUBLIC_CCSS_INTEGRITY',json.dumps(state,ensure_ascii=False,separators=(',',':')))
    # A safe CSS fallback is still operator-visible at warning priority.
    emit_local_alerts(rows)
    if any(x['status']=='error' for x in rows.values()):sys.exit(4)
    if any(x['status']=='deferred' for x in rows.values()):sys.exit(5)
if __name__=='__main__':
    try:main()
    except (ValueError,OSError,TypeError,IndexError) as exc:
        print('HOLD_CCSS_INTEGRITY_AUDITOR',str(exc),file=sys.stderr);sys.exit(4)
