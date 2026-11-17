#!/usr/bin/env python3
"""O(1) first-paint SHA/epoch gate. No Chromium until a dependency changes.

decide must run after authenticated WP-CLI observation and before browser QA.
complete is only for successful verified coverage, not a premature ACK.
"""
import hashlib,json,os,sys,tempfile,time
from pathlib import Path
EXPECTED={"source":"tours.dev.mad4b.com","wayfa":"wayfatours.com"}
CONTRACT="mad4b.critical-css.event-gate.v1"
QA_ROOT=Path(__file__).resolve().parent
QA_COMMON=('run-site.sh','event-gate.py','coverage-queue.py',
           'tools/verify.js','tools/probe-public.js',
           'tools/publish.py','verify-source-revision.py')
QA_SOURCES=QA_COMMON+('safe-cap-fallback.py','wayfa-home-preflight.py',
                      'wayfa-home-source-observer.py')
LIVE_WAYFA_CSS=Path('/opt/mad4b-company-provisioning/reports/dynamic-critical-css-20261116/wayfa/home-current-css-source.json')
def observed_home_source_key(site):
    # Source-file fingerprints from cookie-free mobile HTTP are a
    # CHANGE SIGNAL only. Browser QA and published consumer checks must
    # still qualify the real Chromium/client-hints variant independently.
    if site!='wayfa':return None
    if not LIVE_WAYFA_CSS.exists():return 'not-yet-observed'
    if LIVE_WAYFA_CSS.is_symlink() or LIVE_WAYFA_CSS.stat().st_size>8192:
        raise ValueError('untrusted-company-home-observation')
    evidence=json.loads(LIVE_WAYFA_CSS.read_text())
    if (evidence.get('contract')!='mad4b.wayfa-home-css-change-observation.v1'
        or evidence.get('host')!='wayfatours.com'
        or not __import__('re').fullmatch('[a-f0-9]{64}',evidence.get('fingerprint',''))):
        raise ValueError('invalid-company-home-observation')
    return evidence['fingerprint']
def qa_semantics(site):
    # Trigger requalification if admission logic itself changed. Otherwise
    # a HOLD for an old QA implementation might be replayed for an hour,
    # or a stale ready certificate reused for up to seven days. Only the
    # company's own strategy dependency is fingerprinted.
    paths=QA_COMMON+('safe-cap-fallback.py',) if site=='source' else QA_COMMON+('wayfa-home-preflight.py','wayfa-home-source-observer.py')
    result={}
    for name in paths:
        path=QA_ROOT/name
        if path.is_symlink() or not path.is_file() or path.stat().st_size>262144:
            raise ValueError('missing-or-untrusted-QA-source:'+name)
        result[name]=hashlib.sha256(path.read_bytes()).hexdigest()
    return result
def digest(state,manifest,consumer,site):
    if site not in EXPECTED or state.get("site_host")!=EXPECTED[site]:
        raise ValueError("company source mismatch")
    if state.get("critical_css")!="on" or state.get("environment")!=("staging" if site=="source" else "production"):
        raise ValueError("site CSS policy mismatch")
    if not isinstance(state.get("css_epoch"),int) or state["css_epoch"]<=0:raise ValueError("invalid CSS epoch")
    langs=state.get("wpml_languages")
    if not isinstance(langs,list) or not langs or len(langs)>20 or len(set(langs))!=len(langs):
        raise ValueError("invalid language scope")
    if state.get("default_language") not in langs:raise ValueError("invalid default WPML language")
    if not manifest.is_file() or manifest.is_symlink() or manifest.stat().st_size>65536:
        raise ValueError("untrusted manifest")
    if not consumer.is_file() or consumer.is_symlink():raise ValueError("untrusted native consumer")
    # A manifest file can remain unchanged while LiteSpeed replaces
    # the underlying CSS bytes. Pin the actual on-disk asset SHA too.
    manifest_data=json.loads(manifest.read_text())
    content_root=manifest.parent.parent
    bundle_fingerprint={}
    for source_sha,meta in manifest_data.get('entries',{}).items():
        if not isinstance(meta,dict):continue
        name=meta.get('bundle_file')
        if not isinstance(name,str) or not __import__('re').fullmatch(r'[a-f0-9]{32}[.]css',name):
            continue
        css=content_root/'litespeed/css'/name
        if not css.is_file():
            css=content_root/'litespeed/.mad4b-asset-retention/css'/name
        if css.is_symlink() or not css.is_file() or css.stat().st_size>6291456:
            bundle_fingerprint[source_sha]='missing-or-untrusted'
        else:
            bundle_fingerprint[source_sha]=hashlib.sha256(css.read_bytes()).hexdigest()
    data={
       "contract":CONTRACT,"site":site,"host":state["site_host"],
       "epoch":state["css_epoch"],"wpml":sorted(langs),
       "default":state["default_language"],
       "home_impact":state.get("last_home_impact_at",0),
       "dependency_generation":int(state.get("critical_css_dirty_generation",0)),
       "native_mobile_breakpoint":state.get("native_breakpoint"),
       "manifest_sha":hashlib.sha256(manifest.read_bytes()).hexdigest(),
       "source_css_files":bundle_fingerprint,
       "runtime_versions":state.get("runtime_versions",{}),
       "consumer_sha":hashlib.sha256(consumer.read_bytes()).hexdigest(),
       "qa_implementation":qa_semantics(site),
       "actual_public_home_css_change_signal":observed_home_source_key(site)
    }
    return hashlib.sha256(json.dumps(data,sort_keys=True,separators=(',',':')).encode()).hexdigest()
def read(path,site):
    if not path.exists():return {}
    if path.is_symlink() or path.stat().st_size>8192:raise ValueError("bad state ledger")
    x=json.loads(path.read_text())
    if x.get("contract")!=CONTRACT or x.get("site")!=site:raise ValueError("cross-company state ledger")
    return x
def decide(g,ledger,now,quiet_sec=7*24*3600):
    if ledger.get("key")!=g:return ("inspect","new_dependency_identity")
    # Every invocation already validates fresh source/manifest SHA and WPML.
    # Chromium is a rare weekly drift check, not an expensive 24h timer.
    if ledger.get("status")=="ready":
        if now-int(ledger.get("checked_at",0))<quiet_sec:
            return ("skip","unchanged_healthy_certificate")
        return ("inspect","bounded_weekly_browser_nonregression")
    # Resource deferrals are NOT failed CSS/browser qualification.
    # A locked Chromium lane, busy CPU, or an in-progress generation can
    # retry on the next 5-minute worker tick. Preserve full failure cooldown
    # only after an actual QA failure (hold). Never relax source identity.
    status=ledger.get("status")
    age=max(0,now-int(ledger.get("checked_at",0)))
    if status=="pending":
        if age<120:return ("skip","resource_deferral_short_cooldown")
        return ("inspect","retry_pending_resource_generation")
    if age<3600:return ("skip","unchanged_failed_generation_cooldown")
    return ("inspect","retry_failed_generation_after_cooldown")
def write(path,obj):
    path.parent.mkdir(mode=0o700,parents=True,exist_ok=True)
    fd,tmp=tempfile.mkstemp(prefix=".mad4b-event-",dir=path.parent)
    try:
        with os.fdopen(fd,'w') as h:
            os.fchmod(h.fileno(),0o600)
            json.dump(obj,h,sort_keys=True,indent=2);h.write("\n");h.flush();os.fsync(h.fileno())
        os.replace(tmp,path)
    finally:
        if os.path.exists(tmp):os.unlink(tmp)
def main():
    mode,site,site_json,manifest,consumer,ledger_file,*args=sys.argv[1:]
    state=json.loads(Path(site_json).read_text())
    ledger=Path(ledger_file)
    key=digest(state,Path(manifest),Path(consumer),site)
    old=read(ledger,site)
    now=int(time.time())
    if mode=="decide":
        action,why=decide(key,old,now)
        print("CRITICAL_CSS_EVENT_GATE",json.dumps({"action":action,"reason":why,"site":site,"fingerprint":key[:16]}))
        if action=="skip":sys.exit(10)
        return
    if mode=="complete":
        if not args or args[0] not in ["ready","hold","pending"]:
            raise ValueError("complete requires an explicit verified status")
        # Reason is an allowlisted stable code, never user-derived text.
        reasons={"none","browser_busy","host_cpu_busy","source_changed_during_qa",
                 "browser_qa_failed","source_unavailable","certified",
                 "post_publish_public_readback_pending"}
        reason=args[1] if len(args)>1 else "none"
        if reason not in reasons:raise ValueError("invalid event gate completion reason")
        write(ledger,{"contract":CONTRACT,"site":site,"key":key,"status":args[0],
                      "reason":reason,"checked_at":now})
        print("CRITICAL_CSS_EVENT_LEDGER_COMMITTED",site,args[0],key[:16])
        return
    raise ValueError("unknown mode")
if __name__=="__main__":
    try:main()
    except (ValueError,KeyError,TypeError,OSError,IndexError,json.JSONDecodeError) as e:
        print("HOLD_CRITICAL_CSS_EVENT_GATE",str(e),file=sys.stderr);sys.exit(4)
