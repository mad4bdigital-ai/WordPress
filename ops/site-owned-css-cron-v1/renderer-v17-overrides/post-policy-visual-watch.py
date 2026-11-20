#!/usr/bin/env python3
"""Small unattended browser monitor AFTER native template policy activation.

Failed checks only write private health receipts. They never return to the
certificate publisher, alter a CSS file, change WordPress, or gate any policy.
"""
import fcntl,importlib.util,json,os,pathlib,subprocess,sys,time
from pathlib import Path
D=Path(__file__).resolve().parent
ROOT=Path('/opt/mad4b-company-provisioning/reports/public-critical-css-v1')
IMAGE='mad4b-firecrawl-playwright:20261107-lifecycle-v3'

def load(file):
    spec=importlib.util.spec_from_file_location('original_post_policy_visual',D/file)
    obj=importlib.util.module_from_spec(spec);spec.loader.exec_module(obj)
    return obj

def selected_sites(argv):
    # The first-party host executor must pass its own exact site identity.
    # Legacy no-arg callers keep their historical two-site read-only behavior.
    if not argv: return ('source','wayfa')
    if len(argv)!=1 or argv[0] not in ('source','wayfa'):
        raise ValueError('css_site_scope_invalid')
    return (argv[0],)

def main():
    guard=subprocess.run(['python3',str(D/'public-route-cpu-guard.py')],
       capture_output=True,text=True,timeout=8)
    if guard.returncode:
        print('DEFER_POST_POLICY_VISUAL_MONITOR_CPU',guard.stdout.strip()[:120]);return
    lock=Path('/run/lock/mad4b-native-lazy-browser-global.lock')
    with lock.open('a+') as l:
        try:fcntl.flock(l,fcntl.LOCK_EX|fcntl.LOCK_NB)
        except BlockingIOError:
            print('SKIP_POST_POLICY_VISUAL_MONITOR_ORIGINAL_BROWSER_BUSY');return
        fam=load('public-source-families.py')
        for site in selected_sites(sys.argv[1:]):
            host={'source':'tours.dev.mad4b.com','wayfa':'wayfatours.com'}[site]
            directory=ROOT/site
            reportfile=directory/'source-families-latest.json'
            if (reportfile.is_symlink() or not reportfile.is_file()
                or reportfile.stat().st_size>2000000):continue
            report=json.loads(reportfile.read_text())
            if report.get('site')!=site or report.get('host')!=host:continue
            # The report and the original WP-CLI catalog must BOTH admit
            # the URL. A stale or corrupted report is never a fetch source.
            snapshots=sorted(directory.glob('20*/catalog.json'))
            if not snapshots:continue
            catalog=fam.queue.read(snapshots[-1])
            routes=fam.queue.safe_catalog(catalog,site)
            permitted={r['path'] for r in routes}
            by_key={r['route_key']:r['path'] for r in routes}
            # Observe both already-certified source canaries and the
            # scheduled unproven template variants. A QA/certificate
            # failure must not make a policy-governed route invisible.
            probed={path for group in report.get('source_families',[])
                    for path in group.get('sample_paths',[])
                    if isinstance(path,str) and path in permitted}
            probed.update(by_key[group['next_canary']] for group in
                report.get('source_families',[])
                if group.get('next_canary') in by_key)
            # If no canary was ever certified, still monitor public
            # source-template pages using only the WordPress catalog.
            if not probed:
                sampled=set()
                for r in routes:
                    name=(r.get('source_profile'),r['language'],r['type'])
                    if name not in sampled and not fam.queue.independently_certified_home(r):
                        probed.add(r['path']);sampled.add(name)
            paths=sorted(probed)
            if not paths:
                print('SKIP_POST_POLICY_NO_CERTIFIED_PATH',site);continue
            cursorfile=directory/'visual-observer-cursor.json'
            cursor=0
            if cursorfile.is_file() and not cursorfile.is_symlink():
                try:
                    old=json.loads(cursorfile.read_text())
                    if old.get('site')==site and old.get('contract')=='mad4b.public-css.observer-cursor.v1':
                        cursor=max(0,int(old.get('cursor',0)))
                except (OSError,ValueError,TypeError):pass
            route=paths[cursor%len(paths)]
            # Catalog and first-party certificate checks above already
            # establish the exact public WordPress route.
            if not route.startswith('/') or '//' in route or '..' in route:
                raise ValueError('unsafe-original-public-probe-route')
            stamp=time.strftime('%Y%m%dT%H%M%SZ',time.gmtime())
            work=directory/'post-policy-visual'/stamp
            work.mkdir(parents=True,exist_ok=True,mode=0o700)
            args=['docker','run','--rm','--user=0:0','--memory=768m',
                  '--cpus=0.8','--pids-limit=220','--security-opt=no-new-privileges',
                  '--cap-drop=ALL','--read-only','--tmpfs','/tmp:rw,nosuid,nodev,size=150m',
                  '-v',str(D/'tools')+':/tools:ro','-v',str(work)+':/out',
                  '-e','NODE_PATH=/usr/src/app/node_modules','-e','HOME=/tmp',
                  '-e','MAD4B_OBSERVE_HOST='+host,'-e','MAD4B_OBSERVE_ROUTE='+route,
                  '-e','MAD4B_OBSERVE_OUT=/out']
            if site=='source':args+=['--network=host']
            args += [IMAGE,'node','/tools/observe-public-visual.js']
            try:
                task=subprocess.run(args,capture_output=True,text=True,timeout=200)
                receipt=work/'visual-observation.json'
                if task.returncode==0 and receipt.is_file():
                    result=json.loads(receipt.read_text())
                    fam.queue.atom(directory/'visual-interaction-health-latest.json',result)
                    print('POST_POLICY_VISUAL_OBSERVED',site,route,
                          'alerts',result.get('qualityAlerts'),flush=True)
                else:
                    print('POST_POLICY_VISUAL_MONITOR_FAILED_NOT_A_POLICY_GATE',
                          site,task.returncode,task.stderr[-220:],flush=True)
            except (OSError,subprocess.TimeoutExpired,ValueError) as exc:
                print('POST_POLICY_VISUAL_MONITOR_DEFERRED_NOT_A_POLICY_GATE',
                      site,str(exc)[:160],flush=True)
            fam.queue.atom(cursorfile,{'contract':'mad4b.public-css.observer-cursor.v1',
                'site':site,'cursor':cursor+1})
    print('POST_POLICY_VISUAL_MONITOR_NO_CERTIFICATE_OR_POLICY_MUTATION')

if __name__=='__main__':
    try:main()
    except (OSError,ValueError,TypeError,KeyError,subprocess.TimeoutExpired) as exc:
        # Only this independent monitor fails. The always-on policy doesn't
        # read the result or depend on this unit's successful completion.
        print('POST_POLICY_VISUAL_OBSERVER_ERROR_ONLY',str(exc)[:220],file=sys.stderr)
        sys.exit(3)
