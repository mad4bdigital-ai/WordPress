#!/usr/bin/env python3
"""Bounded permanent company source-template canary Critical CSS admission.

One source canary and one anonymous browser at a time. No request can bypass
WooCommerce/WCML/authorization partitioning. Source/locale/page/CSS revisions
are re-read before publishing; safe native CSS remains for unproven contexts.
"""
import fcntl,hashlib,json,os,pathlib,subprocess,sys,time,re
from pathlib import Path
ROOT=Path('/opt/mad4b-company-provisioning')
# The original signed WordPress runtime identity remains verified separately.
# Worker helpers and Chromium scripts must come from this immutable release.
TOOLS=Path(__file__).resolve().parent
IMAGE='mad4b-firecrawl-playwright:20261107-lifecycle-v3'
CONFIG={
 'source':('tours.dev.mad4b.com','php-wkgtkzta35wkkgouwt6ruljc',
           Path('/opt/hostinger-migrations/hosting-3/etg-commerce-staging/site-copy/wp-content'),'staging'),
 'wayfa':('wayfatours.com','php-guxhp9y6vgh7oouuw8plxrlr',
          Path('/opt/wayfa-rebuild-20261102/site-copy/wp-content'),'production')
}
def run(args,timeout=150,stdout=None,env=None):
    kwargs={'timeout':timeout,'check':True,'env':env}
    if stdout is not None:
        with Path(stdout).open('wb') as f:subprocess.run(args,stdout=f,stderr=subprocess.STDOUT,**kwargs)
        return ''
    return subprocess.run(args,capture_output=True,text=True,**kwargs).stdout.strip()
def wp_eval(php,code,timeout=150):
    # Busy WordPress sites can transiently fail bootstrap while cache jobs
    # contend. A bounded retry is cheaper than a false operator alarm.
    for attempt in range(2):
        try:
            result=run(['docker','exec',php,'wp','--allow-root','eval',code],timeout=timeout)
            parsed=json.loads(result)
            if not isinstance(parsed,dict):raise ValueError('WPCLI_RETURNED_NON_OBJECT')
            return parsed
        except subprocess.CalledProcessError as e:
            detail=(e.stderr or '')[-300:].replace(chr(10),' ')
            print('PUBLIC_CCSS_WORDPRESS_BOOT_HOLD',php,e.returncode,detail,flush=True)
            if attempt:raise
            time.sleep(2)
    raise ValueError('WORDPRESS_BOOT_UNAVAILABLE')
def obs(php,host,env):
    data=wp_eval(php,r'''
$s=get_option("icl_sitepress_settings",[]);
$bp=\Elementor\Plugin::$instance->breakpoints->get_breakpoints()["mobile"]->get_value();
global $woocommerce_wpml;
$wcml_active=function_exists("wcml_is_multi_currency_on") && wcml_is_multi_currency_on();
$wcml_engine=is_object($woocommerce_wpml) && isset($woocommerce_wpml->multi_currency)
    ?$woocommerce_wpml->multi_currency:null;
$codes=($wcml_active && $wcml_engine && method_exists($wcml_engine,"get_currencies"))
    ?array_keys((array)$wcml_engine->get_currencies(true)):[];
$base=(string)get_option("woocommerce_currency","USD");
$css_opt_config=[
 (string)get_option("litespeed.conf.optm-css_comb",""),
 (string)get_option("litespeed.conf.optm-css_min",""),
 (string)get_option("litespeed.conf.optm-css_comb_ext_inl",""),
 (string)get_option("litespeed.conf.optm-css_async",""),
 (string)get_option("litespeed.conf.optm-ucss",""),
 is_file(WPMU_PLUGIN_DIR."/406-mad4b-shared-performance-parity.php")
 ?(string)hash_file("sha256",WPMU_PLUGIN_DIR."/406-mad4b-shared-performance-parity.php"):"missing",
];
$codes=array_values(array_unique(array_filter($wcml_active?$codes:[$base],
    static fn($value)=>is_string($value) && preg_match("/^[A-Z]{3}$/D",$value))));
sort($codes,SORT_STRING);
echo wp_json_encode([
 "site_host"=>(string)wp_parse_url(home_url("/"),PHP_URL_HOST),
 "environment"=>wp_get_environment_type(),
 "critical_css"=>(string)get_option("mad4b_perf_critical_css_v1","off"),
 "all_public_css"=>(string)get_option("mad4b_perf_critical_css_all_public_v1","off"),
 "native_breakpoint"=>(int)$bp,
 "css_epoch"=>(int)get_option("mad4b_css_ai_epoch_v1",0),
 "wcml_multi_currency"=>(bool)$wcml_active,
 "enabled_currencies"=>$codes,
 "base_currency"=>$base,
 "source_optimizer_sha"=>hash("sha256",wp_json_encode($css_opt_config,JSON_UNESCAPED_SLASHES)),
 // A tour-specific CSS source veto is not part of the global LiteSpeed
 // options. Bind its ORIGINAL file bytes to the *tour-only* optimizer
 // negative-proof identity. After a native tour CSS repair, old
 // source_css_bundle_unavailable evidence MUST be re-evaluated, without
 // invalidating unrelated destinations, pages, or home certifications.
 "source_private_css_policy_sha"=>is_file(WPMU_PLUGIN_DIR."/424-mad4b-private-html-css-bundler-v1.php")
   ?(string)hash_file("sha256",WPMU_PLUGIN_DIR."/424-mad4b-private-html-css-bundler-v1.php"):null,
 "source_tour_css_policy_sha"=>is_file(WPMU_PLUGIN_DIR."/378-mad4b-tour-single-css-stability.php")
   ?(string)hash_file("sha256",WPMU_PLUGIN_DIR."/378-mad4b-tour-single-css-stability.php"):null,
 // WordPress core categories keep their per-render JetEngine CSS inline.
 // Bind the first-party optimizer file to category-only negative proof.
 "source_category_css_policy_sha"=>is_file(WPMU_PLUGIN_DIR."/427-mad4b-public-archive-css-source-v1.php")
   ?(string)hash_file("sha256",WPMU_PLUGIN_DIR."/427-mad4b-public-archive-css-source-v1.php"):null,
 // Destination archive dynamic Elementor selectors share the first-party
 // 427 native CSS exclusion with categories, but its negative evidence
 // must be invalidated independently from unrelated CPT/page sources.
 "source_destination_css_policy_sha"=>is_file(WPMU_PLUGIN_DIR."/428-mad4b-public-destination-css-source-v1.php")
   ?(string)hash_file("sha256",WPMU_PLUGIN_DIR."/428-mad4b-public-destination-css-source-v1.php"):null,
 "global_generation"=>(int)get_option("mad4b_ccss_global_generation_v1",0),
 "listing_generation"=>(int)get_option("mad4b_ccss_listing_generation_v1",0),
 "wpml_languages"=>array_values((array)($s["active_languages"]??[])),
 "default_language"=>(string)($s["default_language"]??"")
],JSON_UNESCAPED_SLASHES),"\n";
''')
    if (data['site_host']!=host or data['environment']!=env
        or data['critical_css']!='on' or data['all_public_css']!='on'
        or data['native_breakpoint']!=767 or data['css_epoch']<=0
        or not isinstance(data.get('enabled_currencies'),list)
        or not 1<=len(data['enabled_currencies'])<=20
        or data.get('base_currency') not in data['enabled_currencies']
        or not re.fullmatch('[a-f0-9]{64}',str(data.get('source_optimizer_sha','')))):
        raise ValueError('SITE_ENVIRONMENT_OR_DEFAULT_POLICY_HOLD')
    return data
def public_service_route_scope(cat,service_channels):
    """Respect first-party Wayfa standalone service activation, never invent 200s.

    A published JetEngine Properties record is not a public URL when
    service-activation makes that channel tour-only or disabled. The
    original 004 service visibility policy intentionally returns HTTP
    404 to shoppers for standalone Properties. Such records must not
    consume an expensive Chromium critical-css source-family slot.
    """
    if not isinstance(cat,dict):
        raise ValueError('UNTRUSTED_SERVICE_CATALOG')
    if cat.get('host')!='wayfatours.com':
        return cat
    if (not isinstance(service_channels,dict)
        or set(service_channels)!={'tours','properties'}
        or any(not isinstance(v,bool) for v in service_channels.values())):
        raise ValueError('PUBLIC_SERVICE_POLICY_UNATTESTED')
    excluded=set()
    if not service_channels['properties']:
        excluded.update(('properties','archive:properties','property-types'))
    if not service_channels['tours']:
        excluded.update(('tours-and-activities','archive:tours-and-activities',
                         'tour-types','tour-categories'))
    rows=cat.get('routes')
    if not isinstance(rows,list):raise ValueError('INVALID_PUBLIC_SERVICE_CATALOG')
    # Filter only the original WordPress CLI catalog view, not the site's
    # public activation settings, posts, WPML translation graph or cache.
    return {**cat,'routes':[row for row in rows
            if isinstance(row,dict) and row.get('type') not in excluded]}

def catalog(php,host):
    admitted=wp_eval(php,r'''
if(!class_exists("MAD4B_Public_Critical_CSS_Coverage_V1",false)) exit(3);
$routes=MAD4B_Public_Critical_CSS_Coverage_V1::catalog();
$service=null;
if(($routes["host"]??"")==="wayfatours.com"){
    // This is the first-party service visibility policy used by the
    // site's public pre_get_posts and singular-template HTTP 404 gate.
    // Do not infer visibility from published post status or permalink.
    if(!function_exists("service_visibility_active"))exit(4);
    $service=["tours"=>(bool)service_visibility_active("tours"),
              "properties"=>(bool)service_visibility_active("properties")];
}
echo wp_json_encode(["catalog"=>$routes,"public_service_channels"=>$service],
    JSON_UNESCAPED_SLASHES),"\n";
''',timeout=210)
    if not isinstance(admitted,dict):raise ValueError('CATALOG_ADMISSION_INVALID')
    out=admitted.get('catalog')
    if not isinstance(out,dict) or out.get('contract')!='mad4b.public-critical-css.routes.v1' or out.get('host')!=host:
        raise ValueError('CATALOG_HOST_UNTRUSTED')
    return public_service_route_scope(out,admitted.get('public_service_channels'))
def jsonsave(p,obj):
    p.write_text(json.dumps(obj,indent=2,sort_keys=True)+'\n')
def optimizer_bundle_missing(capture_index,route):
    """Classify only four original Chromium HTTP-200 no-bundle captures."""
    if capture_index.is_symlink() or not capture_index.is_file() or capture_index.stat().st_size>200000:
        return False
    try:
        proof=json.loads(capture_index.read_text())
        if proof.get('contract')!='mad4b.mobile-critical.capture.v1':return False
        rows=proof.get('scenarios')
        return (isinstance(rows,list) and len(rows)==4
            and {r.get('width') for r in rows}=={320,390,414,768}
            and all(r.get('uri')==route.get('path')
                    and r.get('lang')==route.get('language')
                    and r.get('error')=='Error: status=200 bundles=0'
                    for r in rows))
    except (OSError,ValueError,TypeError,KeyError,json.JSONDecodeError):
        return False

def locked(file):
    f=open(file,'a+');fcntl.flock(f,fcntl.LOCK_EX|fcntl.LOCK_NB);return f

def approved_browser_network(site,env,script):
    # Original Chromium's default Docker bridge resolves WCML DE to USD,
    # while the exact same unauthenticated public request from host/outside
    # resolves DE to EUR. Only the staging public navigation/QA workload may
    # use host egress; keep capabilities removed and original browser locks.
    # All other tenant/workload combinations retain their original network.
    if site=='tours.dev.mad4b.com' and env=='staging' and script in (
        'capture.js','verify.js','probe-public.js',
        'capture-ordered-critical.js','verify-native-ordered-critical.js',
        'verify-ordered-css.js'):
        # Original Source LiteSpeed CSS directory is www-data:www-data
        # (0770). With all Linux capabilities dropped, root-in-container
        # cannot traverse it without group 33. Grant ONLY the supplemental
        # group needed by the read-only /source-css capture bind, not the
        # directory write access or DAC override capabilities.
        if script=='capture.js':
            return ['--network=host','--cap-drop=ALL','--group-add=33']
        return ['--network=host','--cap-drop=ALL']
    if (site,env) not in [
        ('tours.dev.mad4b.com','staging'),('wayfatours.com','production')]:
        raise ValueError('UNAPPROVED_BROWSER_SITE_ENVIRONMENT')
    return []

def browser(job,wp,site,env,script,output,extra=None,limit=1320):
    opts={
      'NODE_PATH':'/usr/src/app/node_modules',
      'MAD4B_SOURCE_SITE':'https://'+site,
      'MAD4B_DEFAULT_LANG':job['language'],
      'MAD4B_PUBLIC_CSS_ROUTE':job['path'],
      'MAD4B_PUBLIC_CSS_LANG':job['language'],
      'MAD4B_PUBLIC_CSS_OBJECT_ID':str(job['id']),
      'MAD4B_PUBLIC_CSS_TYPE':job['type'],
    }
    opts.update(extra or {})
    # Larger Chrome memory only for the Wayfatours ordered multi-CSS
    # post-release checks; keep Source and original workers unchanged.
    ordered={'capture-ordered-critical.js','verify-ordered-css.js',
             'verify-native-ordered-critical.js'}
    resource=(['--memory=1900m','--cpus=1','--shm-size=256m']
              if site=='wayfatours.com' and script in ordered
              else ['--memory=1024m','--cpus=1.3'])
    args=['docker','run','--rm','--user','0:0',*resource,
          '--pids-limit=250','--security-opt=no-new-privileges',
          '-v',str(TOOLS/'tools')+':/tools:ro','-v',str(output.parent)+':/out']
    args+=approved_browser_network(site,env,script)
    if script=='capture.js':args+=['-v',str(wp/'litespeed/css')+':/source-css:ro']
    for k,v in opts.items():args+=['-e',k+'='+v]
    args+=[IMAGE,'node','/tools/'+script]
    return run(args,timeout=limit,stdout=output)
def approved_native_runtime_sha(site,wp):
    """Exact original Git 421/422 parity shared by runner and registry.

    Source Staging 422 has one independently attested immutable source
    release SHA. Wayfatours 421/422 and Source 421 remain byte-equal to
    the original central checkout. No runtime can authorize itself merely
    by matching its own current or arbitrary installed bytes.
    """
    if site not in CONFIG or wp!=CONFIG[site][2] or wp.is_symlink():
        raise ValueError('SOURCE_NATIVE_COMPONENT_COMPANY_ROOT_MISMATCH')
    approved_stage_422='254c382bb96faadda2510d75501107ac73ae80673093dd3c0a47a5f63f7f2ff6'
    # Explicitly approved source-sha pair. A locally edited or partially
    # installed v2 file cannot impersonate this pair.
    released_v2_pairs={
        'source':('e6a4bba04a1839be5ef400f76cf3b58762ebb7724508c5d168b397af21811993',
                  '1ffefcb36102f39feb0cf6263b9b79b75a776721cd92c7a682ff845f5322edfb'),
        'wayfa':('597a31f0ea962d2700ead68f9856596727a4e07055650705f52ba57bbaf11119',
                 '249083d5aa0124ea1280a88f5062945cdff2885eca63b36ba5c66682b0faf3d1')}
    released_v2_422,released_v2_consumer=released_v2_pairs[site]
    original=ROOT/'worktrees/pr814-converged-local-20261115/wp-content/mu-plugins'
    runtime=None
    for name in ['421-mad4b-public-critical-css-coverage.php',
                 '422-mad4b-public-critical-css-runtime.php']:
        live=wp/'mu-plugins'/name
        if live.is_symlink() or not live.is_file():
            raise ValueError('SOURCE_LIVE_NATIVE_COMPONENT_DRIFT:'+name)
        blob=live.read_bytes()
        actual_sha=hashlib.sha256(blob).hexdigest()
        if name=='422-mad4b-public-critical-css-runtime.php' and actual_sha==released_v2_422:
            companion=wp/'mu-plugins/422-mad4b-public-critical-css-v2-consumer.php'
            if (companion.is_symlink() or not companion.is_file() or
                hashlib.sha256(companion.read_bytes()).hexdigest()!=released_v2_consumer):
                raise ValueError('MULTI_CSS_V2_RUNTIME_COMPANION_PAIR_DRIFT')
        elif site=='source' and name=='422-mad4b-public-critical-css-runtime.php':
            if actual_sha!=approved_stage_422:
                raise ValueError('SOURCE_LIVE_NATIVE_COMPONENT_DRIFT:'+name)
        else:
            source=original/name
            if source.is_symlink() or not source.is_file() or source.read_bytes()!=blob:
                raise ValueError('SOURCE_LIVE_NATIVE_COMPONENT_DRIFT:'+name)
        if name=='422-mad4b-public-critical-css-runtime.php':
            runtime=hashlib.sha256(blob).hexdigest()
    if not runtime:raise ValueError('SOURCE_NATIVE_RUNTIME_HASH_UNAVAILABLE')
    return runtime

def approved_v2_consumer_sha(site,wp,runtime_sha):
    if site not in CONFIG or wp!=CONFIG[site][2] or wp.is_symlink():
        raise ValueError('MULTI_CSS_V2_COMPANY_ROOT_UNTRUSTED')
    approved_pairs={
        'source':('e6a4bba04a1839be5ef400f76cf3b58762ebb7724508c5d168b397af21811993',
                  '1ffefcb36102f39feb0cf6263b9b79b75a776721cd92c7a682ff845f5322edfb'),
        'wayfa':('597a31f0ea962d2700ead68f9856596727a4e07055650705f52ba57bbaf11119',
                 '249083d5aa0124ea1280a88f5062945cdff2885eca63b36ba5c66682b0faf3d1')}
    released_runtime,approved=approved_pairs[site]
    if runtime_sha!=released_runtime:return None
    companion=wp/'mu-plugins/422-mad4b-public-critical-css-v2-consumer.php'
    if (companion.is_symlink() or not companion.is_file()
        or hashlib.sha256(companion.read_bytes()).hexdigest()!=approved):
        raise ValueError('MULTI_CSS_V2_COMPANION_RELEASE_SHA_UNAPPROVED')
    return approved

def qa_policy_sha():
    # Exact deployed original Chromium QA code, never a user-supplied key.
    # Invalidating negative attempts never publishes a public certificate.
    digest=hashlib.sha256(b'mad4b-public-css-qa-implementation-v1\x00')
    for name in ('verify.js','verify-native-ordered-critical.js',
                 'lcp-observation-window.js'):
        p=TOOLS/'tools'/name
        if p.is_symlink() or not p.is_file():
            raise ValueError('QA_VERIFIER_TOOL_MISSING_OR_SYMLINK:'+name)
        digest.update(name.encode()+b'\x00'+p.read_bytes()+b'\x00')
    return digest.hexdigest()

def run_core(site):
    if site not in CONFIG:raise ValueError('UNKNOWN_COMPANY')
    host,php,wp,env=CONFIG[site]
    base=ROOT/'reports/public-critical-css-v1'/site
    base.mkdir(parents=True,exist_ok=True,mode=0o700)
    a=locked('/run/lock/mad4b-public-critical-css-'+site+'.lock')
    # Home owns first recovery opportunity while native full CSS is in use.
    # Advisory evidence is local and per-company; no queue item is consumed.
    if site=='wayfa':
        import importlib.util
        spec=importlib.util.spec_from_file_location('home_css_priority',TOOLS/'home-recovery-priority.py')
        priority=importlib.util.module_from_spec(spec);spec.loader.exec_module(priority)
        policy,reason=priority.from_file()
        if policy=='defer':
            print('DEFER_PUBLIC_CCSS_HOME_RECOVERY',site,reason)
            return
    try:b=locked('/run/lock/mad4b-native-lazy-browser-global.lock')
    except BlockingIOError:
        print('SKIP_PUBLIC_CCSS_BROWSER_BUSY',site);return
    # Shared exact first-party runtime admission; refresh registry uses
    # the same source-release identity and cannot diverge from this runner.
    verified_runtime_sha=approved_native_runtime_sha(site,wp)
    # Borrow the host's existing 45-second CPU sensor instead of starting a
    # Chromium run at 51-100% guest CPU (or with two original WPCLI slots).
    # A deferred run never selects a route, mutates the ledger or changes a
    # certificate. The next calendar opportunity retries automatically.
    try:
        admission=subprocess.run(['python3',str(TOOLS/'public-route-cpu-guard.py')],
            capture_output=True,text=True,timeout=6)
        if admission.returncode!=0:
            print('DEFER_PUBLIC_CCSS_RESOURCE_WINDOW',site,
                  admission.stdout.strip()[:140] or 'sensor_error')
            return
    except (OSError,subprocess.TimeoutExpired):
        print('DEFER_PUBLIC_CCSS_RESOURCE_SENSOR_UNAVAILABLE',site)
        return
    cores=os.cpu_count() or 1
    pressure=os.getloadavg()[0]
    if pressure>max(4,cores*1.35):
        print('HOLD_PUBLIC_CCSS_HIGH_LOAD',round(pressure,2));return
    state=obs(php,host,env)
    state['runtime_sha']=verified_runtime_sha
    consumer_sha=approved_v2_consumer_sha(site,wp,verified_runtime_sha)
    if consumer_sha:state['consumer_v2_sha']=consumer_sha
    # The optional browser verifier is NOT an activation authority for
    # the native template policy. If unavailable, keep policy discovery
    # active and defer only further expensive browser measurement.
    qa_available=True
    try:state['qa_policy_sha']=qa_policy_sha()
    except (OSError,ValueError) as exc:
        print('OBSERVE_CHROMIUM_QA_UNAVAILABLE_POLICY_REMAINS_ACTIVE',
              site,str(exc)[:150],flush=True)
        state['qa_policy_sha']='unavailable'
        qa_available=False
    listing=catalog(php,host)
    stamp=time.strftime('%Y%m%dT%H%M%SZ',time.gmtime())
    jobdir=base/stamp
    jobdir.mkdir(mode=0o700)
    statefile=jobdir/'site.json';catfile=jobdir/'catalog.json'
    ledger=base/'route-ledger.json';jobfile=jobdir/'selected.json'
    jsonsave(statefile,state);jsonsave(catfile,listing)
    if any('source_profile' not in route for route in listing['routes']):
        raise ValueError('UNQUALIFIED_LEGACY_SOURCE_CATALOG_NO_FAMILY_IDENTITY')
    # The sole automatic route QA queue now selects *source canaries*.
    # Source groups are built from WP-owned shared templates, real original
    # browser certification receipts and the immutable CSS/Elementor assets;
    # a route with different dynamic data is never silently given its sibling
    # page's certificate. No extra Chromium run after a qualified cohort.
    import importlib.util
    spec=importlib.util.spec_from_file_location('source_families',TOOLS/'public-source-families.py')
    family=importlib.util.module_from_spec(spec);spec.loader.exec_module(family)
    current_ledger=family.queue.read_ledger(ledger,site)
    # One bounded, no-cookie, content-addressed source CSS audit in each
    # already admitted browser window. This repairs a certified route when
    # LiteSpeed rewrites a real CSS bundle without changing post/global
    # generations. It never bypasses independent Chromium QA or WCML scope.
    drift_spec=importlib.util.spec_from_file_location(
        'native_live_css_drift',TOOLS/'public-route-live-css-drift.py')
    drift_mod=importlib.util.module_from_spec(drift_spec)
    drift_spec.loader.exec_module(drift_mod)
    try:
        drift=drift_mod.scan(site,listing,state,current_ledger,wp,family,
            base/'live-css-drift-scan-cursor.json',count=4)
    except (OSError,ValueError,KeyError,TypeError) as exc:
        print('HOLD_PUBLIC_CCSS_DRIFT_AUDIT',site,str(exc)[:140],flush=True)
        drift=None
    selected_ledger_path=ledger
    report_ledger=current_ledger
    if drift:
        report_ledger=drift_mod.select_verified_repair(current_ledger,drift)
        selected_ledger_path=jobdir/'verified-asset-drift-selection-ledger.json'
        family.queue.atom(selected_ledger_path,report_ledger)
        print('PUBLIC_CCSS_LIVE_ASSET_DRIFT',site,drift['path'],
              drift['expected_css_file'],drift['css_file'],flush=True)
    # Template policies apply at native source discovery. Chromium QA is
    # separate evidence of display/interaction quality; it must not decide
    # whether the template policy or variant index is activated.
    v_spec=importlib.util.spec_from_file_location(
        'original_template_variant_index',TOOLS/'public-template-variant-index.py')
    variants=importlib.util.module_from_spec(v_spec)
    v_spec.loader.exec_module(variants)
    vpath=base/'template-variant-index.json'
    try:
        index=variants.refresh_file(
            site,listing,state,report_ledger,wp,family,vpath,budget=4)
    except (BlockingIOError,ValueError,OSError,KeyError,TypeError) as exc:
        print('OBSERVE_TEMPLATE_HTML_NONBLOCKING',site,str(exc)[:120],flush=True)
        try:index=variants.read(vpath,site)
        except (ValueError,OSError,KeyError,TypeError):index=variants.empty(site)
    report=family.build(site,listing,state,report_ledger,wp,variant_index=index)
    report['refreshed_at_utc']=time.strftime('%Y-%m-%dT%H:%M:%SZ',time.gmtime())
    family.queue.atom(base/'source-families-latest.json',report)
    preferred=family.plan_next_css_issuance(
        report,int(report_ledger.get('scheduler_sequence',0)),
        drift['route_key'] if drift else None)
    if not qa_available:
        print('POLICY_ACTIVE_BROWSER_EVIDENCE_DEFERRED',site,
              report['source_groups_total'],flush=True)
        return
    print('PUBLIC_CCSS_SOURCE_FAMILIES',site,'total',report['source_groups_total'],
        'qualified',report['source_canary_qualified_groups'],
        'next',preferred[:12] if preferred else 'none',flush=True)
    if not preferred:
        print('READY_PUBLIC_CCSS_SOURCE_FAMILIES',site,
              'NO_UNPROVEN_TEMPLATE_CANARY_ELIGIBLE_NATIVE_PAGE_FALLBACKS_REMAIN')
        return
    run(['python3',str(TOOLS/'public-route-queue.py'),'select',site,
        str(catfile),str(statefile),str(selected_ledger_path),str(jobfile),preferred])
    job=json.loads(jobfile.read_text())
    route=job['route']
    if route is None:
        print('READY_PUBLIC_CCSS_QUEUE_EMPTY',site,len(listing['routes']));return
    print('PUBLIC_CCSS_SELECTED',site,route['language'],route['type'],route['path'])
    def record(result,listing_hint=None):
        args=['python3',str(TOOLS/'public-route-queue.py'),'record',site,
              str(catfile),str(statefile),str(ledger),str(jobfile),result]
        if listing_hint:args.append(listing_hint)
        print(run(args,timeout=25))
    supported={'page','post','tours-and-activities','properties','destinations',
         'tour-types','property-types','tour-categories','category',
         'archive:tours-and-activities','archive:properties'}
    if route['type'] not in supported:
        record('unsupported')
        print('HOLD_PUBLIC_ROUTE_TYPE_NOT_ADMITTED',route['type'])
        return
    lang=route['language'];jobpath=route['path'];rid=route['id']
    jsonsave(jobdir/'route.json',route)
    # Before any new Chromium work, ask the real WP 422 Consumer whether
    # an existing V2 cert is still valid after a bounded unrelated update.
    # Bypass the shared FastCGI representation. A cached HIT is NEVER proof.
    if state.get('consumer_v2_sha'):
        try:
            print(run(['python3',str(TOOLS/'public-v2-existing-readback.py'),
                site,str(wp),str(catfile),str(jobdir/'route.json'),
                str(statefile),str(jobdir/'v2-existing-readback.json')],timeout=32))
            prior_proof=json.loads((jobdir/'v2-existing-readback.json').read_text())
            record('certified','listing' if prior_proof['depends_on_listing'] else 'no-listing')
            print('CERTIFIED_PUBLIC_CCSS',site,lang,jobpath,
                  'V2_VERIFIED_REUSED_NO_BROWSER',flush=True)
            return
        except (subprocess.CalledProcessError,subprocess.TimeoutExpired,
                ValueError,OSError) as exc:
            print('V2_PUBLIC_CERT_NEEDS_FRESH_SOURCE',site,lang,jobpath,
                  str(exc)[:180],flush=True)
    # The public path may still be an HTML generation from before the
    # latest 421/422 runtime or market-header update. Only this single
    # authenticated WPML page's cache generation is renewed, never cart,
    # checkout, user variants or a global CDN/object cache.
    try:
        print(run(['python3',str(TOOLS/'public-route-cache-release.py'),
          'before_capture',site,str(wp),str(catfile),
          str(jobdir/'route.json'),'-',
          str(jobdir/'cache-before-capture.json')],timeout=24))
    except (subprocess.CalledProcessError,subprocess.TimeoutExpired,OSError,ValueError) as exc:
        print('HOLD_PUBLIC_CCSS_PRECAPTURE_CACHE',site,jobpath,str(exc)[:200])
        record('hold')
        return
    # A previously issued certificate may already be active while the
    # incremental queue was marked HOLD by a transient Chromium readback
    # error, or its PHP consumer SHA changed without changing this page's
    # actual Critical CSS, Elementor graph or WCML market.
    # Before running a second expensive FCP/LCP series, use a single public
    # Chromium readback to confirm the *exact installed* certificate and
    # visible inline CSS. This never invents new proof: the original five
    # measured viewports remain bound to the existing content-addressed
    # certificate. A mismatch immediately falls through to full QA.
    prior=None
    if ledger.is_file():
        try:
            prior=json.loads(ledger.read_text()).get('routes',{}).get(route['route_key'])
        except (OSError,ValueError,TypeError):
            prior=None
    if isinstance(prior,dict) and prior.get('status') in ('certified','hold'):
        resdir=jobdir/'existing-certificate'
        resdir.mkdir(mode=0o700)
        try:
            browser(route,wp,host,env,'probe-public.js',
                    jobdir/'existing-certificate.log',
                    {'MAD4B_PROBE_OUTPUT':'/out/existing-certificate',
                     'MAD4B_PROBE_LANGS':lang},limit=190)
            proofout=jobdir/'existing-certificate-proof.json'
            print(run(['python3',str(TOOLS/'public-route-resume.py'),
                       site,str(wp),str(catfile),str(jobdir/'route.json'),
                       str(statefile),str(resdir/(lang+'.headers')),
                       str(resdir/(lang+'.html')),str(proofout)],timeout=25))
            # Resume has the same original source/QA revision gate as
            # fresh certification. Include *all* admitted synthetic
            # fields from initial capture before comparing revisions.
            fresh_state=obs(php,host,env)
            fresh_state['runtime_sha']=approved_native_runtime_sha(site,wp)
            companion_sha=approved_v2_consumer_sha(site,wp,fresh_state['runtime_sha'])
            if companion_sha:fresh_state['consumer_v2_sha']=companion_sha
            fresh_state['qa_policy_sha']=qa_policy_sha()
            fresh_catalog=catalog(php,host)
            current=next((r for r in fresh_catalog['routes']
                          if r['route_key']==route['route_key']),None)
            if (fresh_state!=state or current!=route
                or fresh_catalog.get('global_generation')!=listing.get('global_generation')
                or fresh_catalog.get('listing_generation')!=listing.get('listing_generation')):
                raise ValueError('SOURCE_REVISION_CHANGED_DURING_REUSE_READBACK')
            resumed=json.loads(proofout.read_text())
            record('certified','listing' if resumed['depends_on_listings'] else 'no-listing')
            print('CERTIFIED_PUBLIC_CCSS_REUSED',site,lang,jobpath,
                  resumed['market'],resumed['currency'],'NO_REPEATED_PERFORMANCE_QA')
            return
        except (subprocess.CalledProcessError,subprocess.TimeoutExpired,
                OSError,ValueError,KeyError,TypeError,json.JSONDecodeError) as exc:
            print('PUBLIC_CCSS_OLD_CERTIFICATE_NEEDS_FRESH_QA',
                  site,lang,jobpath,str(exc)[:180])
    # Source-only ordered CSS admission is bounded to the current native
    # WP-CLI route, host and company filesystem. It is a nonblocking private
    # compiler receipt; it NEVER makes a public route READY, invalidates an
    # installed certificate, runs a second Chromium job, or purges FastCGI.
    # The existing route/currency/browser gates remain authoritative until
    # the multi-asset delivery contract is independently released.
    try:
        source_only=run(['python3',str(TOOLS/'public-css-manifest-observer.py'),
             str(catfile),str(jobdir/'route.json'),str(wp),str(jobdir)],
             timeout=28,env={**os.environ,'MAD4B_EXPECTED_SITE_HOST':host})
        print(source_only)
        receipts=[line.removeprefix('MULTICSS_SOURCE_ONLY ')
                  for line in source_only.splitlines()
                  if line.startswith('MULTICSS_SOURCE_ONLY ')]
        if len(receipts)!=1:
            raise ValueError('multi-css-source-report-missing')
        observed=json.loads(receipts[0])
        if (observed.get('contract')!='mad4b.public-css.manifest-observer.v1'
            or observed.get('host')!=host
            or observed.get('path')!=route['path']
            or observed.get('route_key')!=route['route_key']
            or observed.get('decision')!='awaiting_multi_css_route_qa'):
            raise ValueError('multi-css-source-receipt-identity-drift')
        if observed.get('css_files',0)>1:
            # The legacy Capture/QA only measures a single LiteSpeed
            # stylesheet. It cannot prove other synchronously loaded CSS.
            # Never let that partial proof publish READY for this route.
            print('MULTICSS_ROUTE_QUALITY_AWAITING',site,lang,jobpath,
                  'files',observed['css_files'],
                  'slots',observed['cascade_slots'],flush=True)
            # Existing CPU/Chrome guard and one original route job own this
            # bounded, sequential QA. Explicit opt-in admits evidence
            # generation, NEVER public promotion or FastCGI invalidation.
            release_first=os.environ.get('MAD4B_MULTI_CSS_V2_RELEASE_FIRST')=='1'
            if release_first or os.environ.get('MAD4B_MULTI_CSS_SOURCE_QA','0')=='1':
                issued_before_qa=False
                release_promoted=False
                release_args=None
                try:
                    browser(route,wp,host,env,'capture-ordered-critical.js',
                      jobdir/'ordered-critical-capture.log',{},limit=390)
                    # A first-party cached HTML body may contain an outdated
                    # Elementor popup/document graph while the live 422
                    # WordPress source has a new graph. Never issue a pending
                    # certificate based on stale cached source: prove the
                    # exact current, no-store original before release-first.
                    if site=='wayfa':
                        try:
                            print(run(['python3',str(TOOLS/'public-source-stability-before-release.py'),
                                str(catfile),str(jobdir/'route.json'),str(wp),
                                str(jobdir)],timeout=34))
                        except subprocess.CalledProcessError as origin_exc:
                            # Keep the *exact* fail-closed reason inside the
                            # root-private native worker log. Never publish
                            # the invalid candidate or issue a WordPress ACK.
                            reason=(origin_exc.stderr or '').strip()[-180:]
                            print('PUBLIC_SOURCE_PRE_RELEASE_HOLD',site,
                                  route['path'],reason,flush=True)
                            raise
                    if release_first:
                        # Release a source-bound candidate BEFORE browser QA
                        # into ROOT-PRIVATE agent storage, outside WordPress'
                        # public document root. The v2 consumer never reads
                        # unqualified candidates, and previous certified HTML
                        # remains available until verified replacement.
                        if (os.environ.get('MAD4B_MULTI_CSS_V2_PUBLISH')!='1'
                            or os.environ.get('MAD4B_MULTI_CSS_V2_RELEASE_APPROVED')!='1'):
                            raise ValueError('release-first-publisher-opt-in-missing')
                        fresh=obs(php,host,env)
                        fresh['runtime_sha']=approved_native_runtime_sha(site,wp)
                        companion=approved_v2_consumer_sha(site,wp,fresh['runtime_sha'])
                        if companion:fresh['consumer_v2_sha']=companion
                        fresh['qa_policy_sha']=qa_policy_sha()
                        current=next((x for x in catalog(php,host)['routes']
                            if x['route_key']==route['route_key']),None)
                        if fresh!=state or current!=route or not fresh.get('consumer_v2_sha'):
                            raise ValueError('MULTICSS_SOURCE_CHANGED_BEFORE_RELEASE')
                        market=observed['market_country'];currency=observed['currency']
                        measured={**state,'market_country':market,'currency':currency}
                        jsonsave(jobdir/'measured-site-v2.json',measured)
                        release_args=['python3',str(TOOLS/'public-ordered-critical-certificate-v2.py'),
                            str(catfile),str(jobdir/'route.json'),str(jobdir/'measured-site-v2.json'),
                            str(jobdir),str(wp),
                            str(wp/'mu-plugins/422-mad4b-public-critical-css-runtime.php'),
                            str(wp/'mu-plugins/422-mad4b-public-critical-css-v2-consumer.php')]
                        print(run(release_args+['stage'],timeout=35))
                        issued_before_qa=True
                        print('MULTICSS_V2_PRIVATE_STAGED_PENDING_POST_QA',site,route['path'],flush=True)
                        # Do NOT purge or reveal unqualified candidate HTML.
                        # Only a completed QA promotion changes public FastCGI.
                    # Post-release QA begins only after publish above.
                    browser(route,wp,host,env,'verify-ordered-css.js',
                      jobdir/'ordered-full-cascade-qa.log',
                      {'MAD4B_WIDTHS':'320,390,414,767,768'},limit=260)
                    browser(route,wp,host,env,'verify-native-ordered-critical.js',
                      jobdir/'ordered-native-performance.log',{},limit=880)
                    result=run(['python3',str(TOOLS/'public-ordered-critical-admission.py'),
                                str(jobdir)],timeout=20)
                    print(result)
                    receipts=[line.removeprefix('ORDERED_CRITICAL_ADMISSION ')
                        for line in result.splitlines()
                        if line.startswith('ORDERED_CRITICAL_ADMISSION ')]
                    if len(receipts)!=1:raise ValueError('MULTICSS_ADMISSION_RECEIPT_MISSING')
                    admitted=json.loads(receipts[0])
                    if issued_before_qa:
                        if (admitted.get('decision')!='qualified_for_delivery_review'
                            or admitted.get('source_manifest_sha256')!=observed['manifest_sha256']):
                            print(run(release_args+['discard_staged'],timeout=30))
                            issued_before_qa=False
                            raise ValueError('MULTICSS_POST_RELEASE_QA_FAILED_AND_REVERTED')
                        # Re-check current WPML/market/runtime generations AFTER
                        # browser QA. This is post-release invalidation, never
                        # a blocker to initial source-bound publication.
                        fresh=obs(php,host,env)
                        fresh['runtime_sha']=approved_native_runtime_sha(site,wp)
                        latest_consumer=approved_v2_consumer_sha(site,wp,fresh['runtime_sha'])
                        if latest_consumer:fresh['consumer_v2_sha']=latest_consumer
                        fresh['qa_policy_sha']=qa_policy_sha()
                        current=next((x for x in catalog(php,host)['routes']
                            if x['route_key']==route['route_key']),None)
                        if fresh!=state or current!=route:
                            raise ValueError('POST_RELEASE_WP_SOURCE_REVISION_CHANGED')
                        print(run(release_args+['promote_staged'],timeout=40))
                        release_promoted=True
                        # Certification is distinct from cache delivery. Only
                        # the exactly attested WordPress route is renewed.
                        certkey=hashlib.sha256((host+'|'+lang+'|'+route['path']+'|'
                            +admitted['market_country']+'|'+admitted['currency']).encode()).hexdigest()
                        certpath=wp/'mad4b-public-critical-css-v1/certs-v2'/(certkey+'.json')
                        final=json.loads(certpath.read_text())
                        if (final.get('status')!='certified'
                            or final.get('source_manifest_sha256')!=observed['manifest_sha256']
                            or final.get('consumer_sha')!=state['consumer_v2_sha']
                            or final.get('object_id')!=rid):
                            raise ValueError('POST_RELEASE_PUBLIC_CERT_SOURCE_DRIFT')
                        print(run(['python3',str(TOOLS/'public-route-cache-release.py'),
                            'before_capture',site,str(wp),str(catfile),
                            str(jobdir/'route.json'),'-',
                            str(jobdir/'v2-qualified-cache-refresh.json')],timeout=28))
                        # Verify delivered uncacheable origin HTML after QA
                        # promotion, before granting the WordPress queue ACK.
                        if site=='wayfa':
                            print(run(['python3',str(TOOLS/'public-v2-existing-readback.py'),
                                site,str(wp),str(catfile),str(jobdir/'route.json'),
                                str(statefile),str(jobdir/'v2-postqa-origin-readback.json')],timeout=32))
                            verified_http=json.loads((jobdir/'v2-postqa-origin-readback.json').read_text())
                            depends=verified_http['depends_on_listing']
                        else:
                            depends=bool(final['dependencies']['contains_dynamic_listing'])
                        record('certified','listing' if depends else 'no-listing')
                        # The private issue receipt is now redundant; only the
                        # published, post-QA certificate participates in HTML.
                        try:
                            print(run(release_args+['discard_staged'],timeout=30))
                        except (subprocess.CalledProcessError,subprocess.TimeoutExpired,
                                ValueError,OSError) as cleanup_exc:
                            print('MULTICSS_PRIVATE_STAGE_CLEANUP_DEFERRED',
                                  site,str(cleanup_exc)[:160],flush=True)
                        print('CERTIFIED_PUBLIC_CCSS',site,lang,route['path'],
                              'V2_POST_RELEASE_VERIFIED',flush=True)
                        return
                    elif (admitted.get('decision')=='qualified_for_delivery_review'
                        and state.get('consumer_v2_sha')):
                        # QA may run several minutes: reverify native WPML,
                        # market-agnostic source revision and explicit release
                        # fingerprint before emitting a new v2 certificate.
                        fresh=obs(php,host,env)
                        fresh['runtime_sha']=approved_native_runtime_sha(site,wp)
                        fresh_consumer=approved_v2_consumer_sha(site,wp,fresh['runtime_sha'])
                        if fresh_consumer:fresh['consumer_v2_sha']=fresh_consumer
                        fresh['qa_policy_sha']=qa_policy_sha()
                        current=next((x for x in catalog(php,host)['routes']
                            if x['route_key']==route['route_key']),None)
                        if fresh!=state or current!=route:
                            raise ValueError('MULTICSS_WP_SOURCE_OR_RUNTIME_CHANGED_DURING_QA')
                        market=admitted['market_country'];currency=admitted['currency']
                        if (market!=observed['market_country']
                            or currency!=observed['currency']):
                            raise ValueError('MULTICSS_MARKET_CONTEXT_CHANGED_IN_QA')
                        measured={**state,'market_country':market,'currency':currency}
                        jsonsave(jobdir/'measured-site-v2.json',measured)
                        args=['python3',str(TOOLS/'public-ordered-critical-certificate-v2.py'),
                            str(catfile),str(jobdir/'route.json'),
                            str(jobdir/'measured-site-v2.json'),str(jobdir),
                            str(wp),str(wp/'mu-plugins/422-mad4b-public-critical-css-runtime.php'),
                            str(wp/'mu-plugins/422-mad4b-public-critical-css-v2-consumer.php')]
                        print(run(args+['dryrun'],timeout=24))
                        # Publication is independently owner-/runtime-gated
                        # and is OFF by default. Even a published certificate
                        # does not automatically mark FastCGI READY.
                        if (os.environ.get('MAD4B_MULTI_CSS_V2_PUBLISH')=='1'
                            and os.environ.get('MAD4B_MULTI_CSS_V2_RELEASE_APPROVED')=='1'):
                            print(run(args+['publish'],timeout=35))
                except (subprocess.CalledProcessError,subprocess.TimeoutExpired,
                        ValueError,OSError) as exc:
                    if issued_before_qa and release_args:
                        # A failed post-release browser run must never leave an
                        # unqualified certificate in shared public delivery.
                        try:
                            print(run(release_args+['revoke' if release_promoted else 'discard_staged'],
                                      timeout=30))
                            if release_promoted:
                                print(run(['python3',str(TOOLS/'public-route-cache-release.py'),
                                    'before_capture',site,str(wp),str(catfile),
                                    str(jobdir/'route.json'),'-',
                                    str(jobdir/'v2-reverted-cache-refresh.json')],timeout=28))
                        except (subprocess.CalledProcessError,subprocess.TimeoutExpired,
                                ValueError,OSError) as rollback_exc:
                            print('MULTICSS_POST_RELEASE_REVERT_DEFERRED',site,
                                  str(rollback_exc)[:160],flush=True)
                    print('HOLD_MULTI_CSS_QUALITY_PROOF',site,lang,jobpath,
                          str(exc)[:220],flush=True)
            record('hold')  # Original queue has no multi-CSS-specific hold enum
            return
    except (subprocess.CalledProcessError,subprocess.TimeoutExpired,OSError,ValueError) as exc:
        print('HOLD_MULTI_CSS_SOURCE_PROOF',site,lang,
              route['route_key'][:12],str(exc)[:130],flush=True)
        # Unknown CSS composition is not evidence for the legacy single-
        # bundle publisher. Preserve native CSS; retry after normal cooldown.
        record('hold','capture_error')
        return
    envcapture={'MAD4B_OUTPUT':'/out','MAD4B_CSS_ROOT':'/source-css',
                'MAD4B_LANGS':lang,'MAD4B_WIDTHS':'320,390,414,768'}
    qaenv={'MAD4B_OUTPUT':'/out','MAD4B_QA_LANGS':lang,
           'MAD4B_QA_WIDTHS':'320,390,414,767,768',
           'MAD4B_CSS_PRIORITY':'high','MAD4B_CSS_PRELOAD':'1',
           'MAD4B_CSS_ADAPTIVE_MAX':'767',
           'MAD4B_HERO_900_CANDIDATE':'0',
           'MAD4B_FIRST_CITY_DEFER_CANDIDATE':'0'}
    try:
        browser(route,wp,host,env,'capture.js',jobdir/'capture.log',envcapture,limit=500)
        browser(route,wp,host,env,'assemble.js',jobdir/'assemble.log',{'MAD4B_OUTPUT':'/out'},limit=90)
        # Four individually captured viewport sources must agree in CSS
        # filename/bytes, Elementor graph and trusted WCML market. Do not
        # waste an expensive FCP/LCP run on a volatile JetEngine listing
        # graph, or report missing verification.json as a browser failure.
        preflight=run(['python3',str(TOOLS/'public-route-capture-preflight.py'),
          str(jobdir/'capture-index.json'),str(jobdir/'candidates.json'),
          str(jobdir/'route.json')],timeout=20)
        print(preflight)
        gate=json.loads(preflight.split(' ',1)[1])
        if gate.get('decision')!='admit_qa':
            reason=gate.get('reason','capture_error')
            print('HOLD_PUBLIC_CCSS_SOURCE_UNSTABLE',site,lang,jobpath,reason)
            record('hold',reason)
            return
        browser(route,wp,host,env,'verify.js',jobdir/'verification.log',qaenv,limit=1100)
        proof=jobdir/'verification.json'
        if not proof.is_file():
            raise ValueError('BROWSER_QA_EVIDENCE_MISSING_AFTER_ZERO_EXIT')
        q=json.loads(proof.read_text())
        v=q.get('results',[]);g=q.get('gates',{}).get(lang,{})
        if (q.get('tested')==0 and len(v)==0 and
            g.get('decision')=='hold' and
            g.get('reason') in ('source_html_drift','country_or_currency_context_drift')):
            print('HOLD_PUBLIC_CCSS_QA_SOURCE_DRIFT',site,lang,jobpath,g['reason'])
            record('hold',g['reason'])
            return
        if (len(v)!=5 or q.get('tested')!=5 or {z['width'] for z in v}!={320,390,414,767,768}
            or any(z.get('pass') is not True for z in v)
            or g.get('decision')!='passed_evidence'):
            # A complete, stable, interaction-safe measurement may prove
            # that native CSS is preferable. Retain this negative evidence
            # under the exact route+runtime+epoch+dependency fingerprint.
            # Never promote a failed CSS candidate or repeatedly spend CPU
            # proving the same non-benefit on an unchanged page.
            import importlib.util
            modspec=importlib.util.spec_from_file_location('negative_css',TOOLS/'public-route-native-preferred.py')
            mod=importlib.util.module_from_spec(modspec);modspec.loader.exec_module(mod)
            if mod.is_native_preferred(q,lang):
                # The independent native-CSS nonbenefit decision must
                # compare the same source/QA release identity as original
                # capture, otherwise legitimate negative evidence is
                # incorrectly converted into a generic QA hold.
                fresh=obs(php,host,env)
                fresh['runtime_sha']=approved_native_runtime_sha(site,wp)
                fresh['qa_policy_sha']=qa_policy_sha()
                current=next((x for x in catalog(php,host)['routes']
                              if x['route_key']==route['route_key']),None)
                if fresh==state and current==route:
                    record('native_candidate','listing')
                    print('NATIVE_CSS_NONBENEFIT_MEASURED',site,lang,jobpath,
                          'FULL_NATIVE_CSS_KEPT_PENDING_INDEPENDENT_CONFIRMATION')
                    return
            raise ValueError('CHROMIUM_FCP_LCP_CLS_OR_INTERACTION_FAILED')
        # Real server-resolved market/currency in same Chromium profile.
        probe=jobdir/'recheck';probe.mkdir(mode=0o700)
        browser(route,wp,host,env,'probe-public.js',jobdir/'recheck.log',
                {'MAD4B_PROBE_OUTPUT':'/out/recheck','MAD4B_PROBE_LANGS':lang},limit=190)
        run(['python3',str(TOOLS/'observed-currency-state.py'),
             str(statefile),str(probe/(lang+'.headers')),str(jobdir/'measured-site.json')])
        # Re-read global + route-specific revision before admission.
        fresh_state=obs(php,host,env)
        # The original QA policy is part of the initial snapshot since
        # versioned negative evidence was introduced. Recompute the same
        # admission-bound, fail-closed fields at final readback; otherwise
        # an unchanged page is always misclassified as revision drift.
        # A *real* QA source change while Chromium runs still fails here.
        fresh_state['runtime_sha']=approved_native_runtime_sha(site,wp)
        companion_sha=approved_v2_consumer_sha(site,wp,fresh_state['runtime_sha'])
        if companion_sha:fresh_state['consumer_v2_sha']=companion_sha
        fresh_state['qa_policy_sha']=qa_policy_sha()
        fresh_catalog=catalog(php,host)
        current=next((x for x in fresh_catalog['routes'] if x['route_key']==route['route_key']),None)
        if (fresh_state!=state or current!=route):
            raise ValueError('SOURCE_REVISIONS_CHANGED_DURING_QA')
        # Require every newly issued certificate to originate from its own
        # exact public CSS SHA and native WCML currency, not the CLI base.
        baseargs=['python3',str(TOOLS/'public-route-certificate.py'),
                  str(catfile),str(jobdir/'route.json'),str(jobdir/'candidates.json'),
                  str(jobdir/'verification.json'),str(probe),
                  str(jobdir/'measured-site.json'),str(wp)]
        print(run(baseargs+['dryrun']))
        print(run(baseargs+['publish']))
        # The source certificate is now on disk, but FastCGI may serve a
        # pre-certificate HTML snapshot. Confirm the installed certificate,
        # CSS source SHA, critical asset SHA, route revision, market and
        # currency before advancing this one page's generation.
        print(run(['python3',str(TOOLS/'public-route-cache-release.py'),
          'after_promotion',site,str(wp),str(catfile),
          str(jobdir/'route.json'),str(jobdir/'measured-site.json'),
          str(jobdir/'cache-after-promotion.json')],timeout=24))
        # Critical CSS may still be hidden by an older public HTML FastCGI
        # snapshot. Recheck rather than claiming live certification.
        readback=jobdir/'readback';readback.mkdir(mode=0o700)
        browser(route,wp,host,env,'probe-public.js',jobdir/'readback.log',
                {'MAD4B_PROBE_OUTPUT':'/out/readback','MAD4B_PROBE_LANGS':lang},limit=190)
        headers=(readback/(lang+'.headers')).read_text().lower()
        market=json.loads((jobdir/'measured-site.json').read_text())
        measured_country=market['market_country']
        measured_currency=market['currency']
        if ('x-mad4b-public-ccss-market: '+measured_country.lower() not in headers
            or 'x-mad4b-public-ccss-currency: '+measured_currency.lower() not in headers):
            raise ValueError('CERTIFICATE_READBACK_MARKET_OR_CURRENCY_CHANGED')
        html=(readback/(lang+'.html')).read_text(errors='replace')
        if ('x-mad4b-public-ccss-ready: 1' not in headers
            or html.count('id="mad4b-public-critical-css-v1"')!=1
            or 'media="(min-width:768px)"' not in html):
            raise ValueError('PUBLIC_READBACK_NOT_CERTIFIED_OR_STALE_HTML')
        original=(probe/(lang+'.html')).read_text(errors='replace')
        islisting='jet-listing-grid' in original[original.find('<body'):]
        record('certified','listing' if islisting else 'no-listing')
        print('CERTIFIED_PUBLIC_CCSS',site,lang,jobpath,'MARKET_CURRENCY_BOUND')
    except (subprocess.CalledProcessError,subprocess.TimeoutExpired,
            OSError,ValueError,KeyError,json.JSONDecodeError) as exc:
        no_bundle=optimizer_bundle_missing(jobdir/'capture-index.json',route)
        print('HOLD_PUBLIC_CCSS',site,lang,jobpath,
              'SOURCE_ORIGINAL_CSS_BUNDLE_UNAVAILABLE' if no_bundle else str(exc)[:270])
        try:record('hold','source_css_bundle_unavailable' if no_bundle else None)
        except Exception as ledger_error:print('HOLD_QUEUE_LEDGER',str(ledger_error)[:180])
        # Do not declare service success as proof of a particular page.
def main():
    if len(sys.argv)!=2:raise ValueError('USAGE: run-public-route.py source|wayfa')
    run_core(sys.argv[1])
if __name__=='__main__':
    try:main()
    except BlockingIOError:print('SKIP_PUBLIC_CCSS_ALREADY_RUNNING')
    except (OSError,ValueError,KeyError,subprocess.CalledProcessError) as e:
        print('HOLD_PUBLIC_CCSS_WORKER',str(e)[:300],file=sys.stderr)
        sys.exit(4)
