#!/usr/bin/env python3
"""Autonomous low-cost unified source registry refresh; never runs Chromium or publishes CSS."""
import fcntl,hashlib,importlib.util,json,os,pathlib,re,shutil,subprocess,sys,time
from pathlib import Path
D=Path(__file__).resolve().parent

def load(name,file):
    spec=importlib.util.spec_from_file_location(name,D/file)
    mod=importlib.util.module_from_spec(spec);spec.loader.exec_module(mod);return mod



def bind_original_approved_v2_consumer(runner,site,wp,state):
    """Mirror only the exact already-attested original public-route state.

    An unapproved WordPress consumer never contributes a source-family
    recovery proposal. This helper is read-only and does not issue CSS or
    add browser work; the original runner remains the approval authority.
    """
    if not isinstance(state,dict):
        raise ValueError('source-family-consumer-state-invalid')
    runtime=state.get('runtime_sha')
    if (not isinstance(runtime,str) or len(runtime)!=64 or
        any(ch not in '0123456789abcdef' for ch in runtime)):
        raise ValueError('source-family-runtime-not-attested')
    sha=runner.approved_v2_consumer_sha(site,wp,runtime)
    if sha is not None:
        if (not isinstance(sha,str) or len(sha)!=64
            or any(ch not in '0123456789abcdef' for ch in sha)):
            raise ValueError('source-family-consumer-hash-untrusted')
        state['consumer_v2_sha']=sha
    else:
        state.pop('consumer_v2_sha',None)
    return state

def selected_sites(argv):
    if not argv:return ('source','wayfa')
    if len(argv)!=1 or argv[0] not in ('source','wayfa'):
        raise ValueError('css_site_scope_invalid')
    return (argv[0],)

def paired_original_family_source_snapshot(site,catalog,state,report,root,atom):
    """Persist one read-only 421+422 WPML/WCML pair for the existing Bulk planner.

    Only native authenticated WordPress family refresh may generate this
    source-snapshot. Other jobdirs are never chosen as a proxy for current
    catalog; publisher still freshly reads and revalidates original WP.
    """
    expected={'source':'tours.dev.mad4b.com','wayfa':'wayfatours.com'}.get(site)
    if (not expected or not isinstance(catalog,dict)
        or catalog.get('contract')!='mad4b.public-critical-css.routes.v1'
        or catalog.get('host')!=expected or not isinstance(state,dict)
        or state.get('site_host')!=expected or state.get('critical_css')!='on'
        or not isinstance(report,dict) or report.get('host')!=expected
        or type(state.get('css_epoch')) is not int
        or state['css_epoch']!=report.get('css_epoch')
        or state.get('global_generation')!=catalog.get('global_generation')
        or state.get('listing_generation')!=catalog.get('listing_generation')
        or report.get('global_generation')!=catalog.get('global_generation')
        or report.get('listing_generation')!=catalog.get('listing_generation')
        or not re.fullmatch('[a-f0-9]{64}',str(state.get('runtime_sha','')))
        or not re.fullmatch('[a-f0-9]{64}',str(state.get('consumer_v2_sha','')))):
        raise ValueError('original-421-422-family-source-catalog-read-drift')
    root=Path(root)
    if (root.is_symlink() or not root.is_dir()
        or root.stat().st_uid!=0 or root.stat().st_mode&0o077):
        raise ValueError('untrusted-root-private-421-family-source-snapshot')
    # Original 421 schema, not guessed profile source IDs or stale URL list.
    q=load('family_paired_exact_source_routes','public-route-queue.py')
    q.safe_catalog(catalog,site)
    timestamp=time.strftime('%Y%m%dT%H%M%SZ',time.gmtime())
    name='family-source-'+timestamp+'-'+str(os.getpid())
    target=root/name
    if target.exists() or target.is_symlink():
        raise ValueError('duplicate-immutable-source-snapshot')
    target.mkdir(mode=0o700)
    try:
        atom(target/'catalog.json',catalog)
        atom(target/'site.json',state)
        contents=(target/'catalog.json').read_bytes()
        statebytes=(target/'site.json').read_bytes()
        verifycat=json.loads(contents);verifystate=json.loads(statebytes)
        if verifycat!=catalog or verifystate!=state:
            raise ValueError('paired-original-source-write-readback-failed')
    except BaseException:
        shutil.rmtree(target)
        raise
    # The family report is published AFTER these two files, so its pointer
    # is an exact SHA-bound all-or-nothing read-only planning proof.
    report['paired_source_snapshot']=str(target)
    report['paired_catalog_sha256']=hashlib.sha256(contents).hexdigest()
    report['paired_state_sha256']=hashlib.sha256(statebytes).hexdigest()
    recent=sorted((x for x in root.iterdir() if x.is_dir()
        and not x.is_symlink()
        and re.fullmatch(r'family-source-[0-9]{8}T[0-9]{6}Z-[0-9]+',x.name)),
        key=lambda x:x.stat().st_mtime)
    for old in recent[:-3]:
        if old!=target and old.stat().st_uid==0:
            shutil.rmtree(old)
    return target

def main():
    # A fresh first-party CPU budget is required before both WordPress CLI
    # source-catalog reads. On pressure, preserve the last certs and retry.
    guard=subprocess.run(['python3',str(D/'public-route-cpu-guard.py')],
                         capture_output=True,text=True,timeout=8)
    if guard.returncode:
        # The host's original site-owned worker permits only a bounded
        # read-only source-family exception to the WPCLI slot count.
        # The browser and certification CPU gates remain unchanged.
        light=None
        if len(sys.argv)==2 and sys.argv[1] in ('source','wayfa'):
            light=subprocess.run(
                ['python3',str(D/'family-report-read-cpu-guard.py')],
                capture_output=True,text=True,timeout=8)
        if light is not None and light.returncode==0:
            print('ADMIT_SOURCE_FAMILY_READ_ONLY',
                  light.stdout.strip()[:120],flush=True)
        else:
            print('DEFER_SOURCE_FAMILY_REFRESH',guard.stdout.strip()[:100])
            # A reserved WP-CLI slot is not CPU pressure: keep cheap first-party
        # Variant observations moving from recently pinned source snapshots.
        # No cached identity may grant a new CSS certificate or HTML cache.
            if guard.stdout.strip().endswith('central_wpcli_slots_reserved'):
                try:
                    cached=load('cached_wp_template_variants','cached-variant-refresh.py')
                    cached.refresh()
                except (OSError,ValueError,TypeError,KeyError) as exc:
                    print('DEFER_LIGHT_VARIANTS_NOT_POLICY_GATE',str(exc)[:120])
            return
    lock=Path('/run/lock/mad4b-public-source-family-refresh.lock')
    with lock.open('a+') as file:
        try:fcntl.flock(file,fcntl.LOCK_EX|fcntl.LOCK_NB)
        except BlockingIOError:
            print('SKIP_SOURCE_FAMILY_REFRESH_ALREADY_RUNNING');return
        runner=load('ccss_source_refresh_runner','run-public-route.py')
        families=load('ccss_source_refresh_families','public-source-families.py')
        variants=load('ccss_source_refresh_variants','public-template-variant-index.py')
        for site in selected_sites(sys.argv[1:]):
            host,php,wp,env=runner.CONFIG[site]
            # Do not ingest arbitrary URLs; WP-CLI and original site-profile
            # runtime provide the only identities. This is a pure report.
            state=runner.obs(php,host,env)
            # Use the exact original runner's per-company source admission.
            # Source Staging 422 v1.0.1 is an approved separate release,
            # while Wayfa Production retains original 421/422 byte parity.
            state['runtime_sha']=runner.approved_native_runtime_sha(site,wp)
            state=bind_original_approved_v2_consumer(runner,site,wp,state)
            try:state['qa_policy_sha']=runner.qa_policy_sha()
            except (OSError,ValueError) as exc:
                state['qa_policy_sha']='unavailable'
                print('OBSERVE_QA_SOURCE_MISSING_POLICY_REMAINS_ACTIVE',
                      site,str(exc)[:150],flush=True)
            catalog=runner.catalog(php,host)
            if any('source_profile' not in item for item in catalog['routes']):
                raise ValueError('source_catalog_not_migrated:'+site)
            root=Path('/opt/mad4b-company-provisioning/reports/public-critical-css-v1')/site
            ledger=families.queue.read_ledger(root/'route-ledger.json',site)
            # Always-on native policy is independent of whether optional
            # browser visual/interactivity checks pass. Observe a bounded
            # number of original public HTML sources with plain HTTPS only.
            # A 404/timeout never vetoes a policy or a published certificate.
            variantpath=root/'template-variant-index.json'
            try:
                index=variants.refresh_file(
                    site,catalog,state,ledger,wp,families,variantpath,budget=8)
            except (BlockingIOError,ValueError,OSError,KeyError,TypeError) as exc:
                print('OBSERVE_TEMPLATE_HTML_NONBLOCKING',site,str(exc)[:120],flush=True)
                try:index=variants.read(variantpath,site)
                except (ValueError,OSError,KeyError,TypeError):index=variants.empty(site)
            result=families.build(site,catalog,state,ledger,wp,variant_index=index)
            result['refreshed_at_utc']=time.strftime('%Y-%m-%dT%H:%M:%SZ',time.gmtime())
            pair=paired_original_family_source_snapshot(
                site,catalog,state,result,root,families.queue.atom)
            families.queue.atom(root/'source-families-latest.json',result)
            print('SOURCE_FAMILY_PAIRED_421_422_SNAPSHOT',site,
                  pair.name,'css_epoch',state['css_epoch'],flush=True)
            print('SOURCE_FAMILY_REGISTRY_REFRESH',site,'routes',result['routes_discovered'],
                  'families',result['source_groups_total'],
                  'qualified',result['source_canary_qualified_groups'],
                  'pending',result['source_groups_by_status'].get('source_canary_pending',0),flush=True)
            # Prepare only *source-bound* HTML/CSS assets for the next 24
            # pages in the native publisher cursor's Elementor/WPML order.
            # This never runs Chromium, does not issue any CSS certificate
            # or influence whether source-family registry itself is valid.
            try:
                preflight=load('mad4b_source_family_preparer','bulk-family-prepare.py')
                prep=preflight.prepare(site,catalog,state,wp,budget=24)
                print('BULK_FAMILY_SOURCE_PREPARED',site,
                    'attempted',prep['attempted'],'fresh',prep['prepared'],
                    'reused',prep['reused'],'deferred',prep['deferred'],
                    'source_profiles',prep['source_profiles'],flush=True)
            except (OSError,ValueError,KeyError,TypeError,UnicodeError) as exc:
                print('BULK_FAMILY_PREPARATION_DEFERRED_NOT_PUBLISH_GATE',
                      site,type(exc).__name__,str(exc)[:140],flush=True)

if __name__=='__main__':
    try:main()
    except (OSError,ValueError,KeyError,TypeError,subprocess.TimeoutExpired,subprocess.CalledProcessError) as exc:
        print('HOLD_SOURCE_FAMILY_REGISTRY_REFRESH',str(exc)[:220],file=sys.stderr);sys.exit(4)
