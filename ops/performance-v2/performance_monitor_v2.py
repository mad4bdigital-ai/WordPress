#!/usr/bin/env python3
"""Low-frequency local integrity monitor for enrolled company policies.
No Lighthouse/CrUX requests. Never changes WP cache, releases, or pricing.
"""
import argparse
import json
import os
from pathlib import Path
import re
import subprocess
import sys
import tempfile
from datetime import datetime, timezone
import hashlib
from urllib.parse import urlsplit
from performance_http_readback_v2 import audit
from cache_coherence_intelligence_v3 import diagnose

CONTRACT="mad4b.performance-integrity-monitor.v2"
SAFE=re.compile(r"^[a-z][a-z0-9_-]{1,63}$")

def persist(path,data):
    path.parent.mkdir(parents=True,exist_ok=True)
    fd,temp=tempfile.mkstemp(prefix=".mad4b-performance-",dir=path.parent)
    try:
        with os.fdopen(fd,"w")as stream:
            json.dump(data,stream,sort_keys=True,indent=2)
            stream.write("\n")
            stream.flush()
            os.fsync(stream.fileno())
        os.chmod(temp,0o640)
        os.replace(temp,path)
    finally:
        if os.path.exists(temp):os.unlink(temp)

def verified_critical_css_integrity_readback(policy:dict)->dict:
    """Read-only, company-scoped filesystem proof of deployed CSS certificates.

    Does not read prices, infer geographic market, open the browser, change
    WordPress options, grant releases, or mutate manifests. It must never
    convert a previously held performance monitor result to PASS.
    """
    cfg=policy.get("critical_css_integrity_v1",{})
    if not isinstance(cfg,dict) or cfg.get("enabled") is not True:
        return {"state":"not_enrolled","read_only":True}
    issues=[]
    def fail(code):
        if code not in issues:issues.append(code)
    valid_sha=lambda x:isinstance(x,str) and re.fullmatch(r"[a-f0-9]{64}",x) is not None
    valid_css=lambda x:isinstance(x,str) and re.fullmatch(r"[a-f0-9]{32,64}\.css",x) is not None
    root_raw=policy.get("local_runtime",{}).get("site_root","")
    if not isinstance(root_raw,str) or not root_raw.startswith("/opt/"):
        fail("site_root_not_enrolled")
        root=Path("/no-such-mad4b-tenant")
    else:
        root=Path(root_raw)
        if not root.is_dir() or root.is_symlink():fail("site_root_not_approved")
    wp=root/"wp-content"
    manifest=wp/"mad4b-critical-css-v1/manifest.json"
    consumer=wp/"mu-plugins/415-mad4b-critical-css-orchestrator.php"
    try:
        if consumer.is_symlink() or not consumer.is_file() or not 3000<=consumer.stat().st_size<=100000:
            raise ValueError("unsafe_php")
        raw=consumer.read_bytes()
        consumer_sha=hashlib.sha256(raw).hexdigest()
        if not valid_sha(cfg.get("consumer_sha256")) or consumer_sha!=cfg["consumer_sha256"]:
            fail("critical_css_php_source_drift")
        if b"final class MAD4B_Verified_Critical_CSS_V1" not in raw:
            fail("critical_css_native_consumer_missing")
    except (OSError,ValueError):
        consumer_sha=None
        fail("critical_css_php_source_missing")
    try:
        if manifest.is_symlink() or not manifest.is_file() or not 200<=manifest.stat().st_size<=65536:
            raise ValueError("unsafe_manifest")
        data=json.loads(manifest.read_text())
    except (OSError,ValueError,TypeError):
        data={}
        fail("critical_css_manifest_missing_or_invalid")
    if not isinstance(data,dict):
        fail("critical_css_manifest_not_object")
        data={}
    host=urlsplit(str(policy.get("origin",""))).hostname
    v2=cfg.get("format")=="adaptive-v2"
    v1=cfg.get("format")=="native-v1"
    if (not (v1 or v2)
        or data.get("site")!=host
        or data.get("version")!=(2 if v2 else 1)
        or not isinstance(data.get("entries"),dict)):
        fail("critical_css_manifest_tenant_or_format_mismatch")
    entries=data.get("entries",{})
    if not isinstance(entries,dict):entries={}
    locales=cfg.get("locales",{})
    if not isinstance(locales,dict) or not 1<=len(locales)<=8:
        fail("critical_css_locales_not_enrolled")
        locales={}
    proof={}
    for locale,spec in locales.items():
        if locale not in ("en","es","fr","ar","de","it") or not isinstance(spec,dict):
            fail("critical_css_locale_invalid")
            continue
        css_sha=spec.get("css_sha256")
        crit_sha=spec.get("critical_sha256")
        css_file=spec.get("css_file")
        crit_file=spec.get("critical_file")
        expected_route="/" if locale=="en" else "/"+locale+"/"
        if (not valid_sha(css_sha) or not valid_sha(crit_sha)
            or not valid_css(css_file) or not valid_css(crit_file)
            or spec.get("route")!=expected_route):
            fail("critical_css_cert_identity_invalid_"+locale)
            continue
        for kind,source,expected,minbytes,maxbytes in (
            ("source",wp/"litespeed/css"/css_file,css_sha,500,8000000),
            ("critical",wp/"mad4b-critical-css-v1"/crit_file,crit_sha,16000,1048576)):
            try:
                if source.is_symlink() or not source.is_file() or not minbytes<=source.stat().st_size<=maxbytes:
                    raise ValueError("unsafe_asset")
                actual=hashlib.sha256(source.read_bytes()).hexdigest()
                if actual!=expected:fail("critical_css_"+kind+"_sha_drift_"+locale)
            except (OSError,ValueError):
                fail("critical_css_"+kind+"_asset_missing_"+locale)
        entry=entries.get(css_sha)
        if not isinstance(entry,dict):
            fail("critical_css_cert_not_in_manifest_"+locale)
            continue
        if v2:
            variants=entry.get("variants",{})
            if not isinstance(variants,dict):variants={}
            variant=variants.get(expected_route+"|"+locale+"|mobile")
            valid=(entry.get("bundle_file")==css_file and
                   isinstance(variant,dict) and
                   variant.get("gate_status")=="pass" and
                   variant.get("file")==crit_file and
                   variant.get("critical_sha")==crit_sha and
                   variant.get("route_path")==expected_route and
                   variant.get("language")==locale and
                   variant.get("css_critical_mobile_breakpoint")==cfg.get("breakpoint_px") and
                   variant.get("css_epoch")==cfg.get("css_epoch") and
                   isinstance(variant.get("evidence"),dict) and
                   variant["evidence"].get("decision")=="passed_evidence" and
                   variant["evidence"].get("adaptive_mobile_breakpoint_px")==cfg.get("breakpoint_px") and
                   isinstance(variant["evidence"].get("viewports"),list) and
                   all(x in variant["evidence"]["viewports"] for x in
                       [cfg.get("breakpoint_px"),cfg.get("breakpoint_px")+1]))
        else:
            valid=(entry.get("gate_status")=="pass" and
                   entry.get("file")==crit_file and
                   entry.get("critical_sha")==crit_sha and
                   entry.get("languages")==[locale] and
                   entry.get("devices")==["mobile"])
        if not valid:fail("critical_css_cert_semantics_drift_"+locale)
        proof[locale]={"source_sha256":css_sha,"critical_sha256":crit_sha,
                       "certificate_valid":valid}
    return {"contract":"mad4b.performance.critical-css-static-integrity.v1",
            "state":"passed" if not issues else "hold",
            "reason_codes":issues,"site":host,
            "consumer_sha256":consumer_sha,
            "manifest_format":cfg.get("format"),
            "locales":proof,
            "read_only":True,"runtime_wordpress_options_proven":False,
            "live_browser_activation_proven":False,
            "original313_signed_code_release_issued":False}

def fragment_integrity_readback(policy:dict)->dict:
    """Static read-only Fragments release coherence check within existing timer.

    This cannot prove runtime WordPress options or real geographic routing.
    It never runs WP-CLI, purges caches, or approves a release.
    """
    cfg=policy.get("fragment_integrity_v1",{})
    if not isinstance(cfg,dict) or cfg.get("enabled") is not True:
        return {"state":"not_enrolled","read_only":True}
    issues=[]
    def fail(code):
        if code not in issues:issues.append(code)
    expected=cfg.get("sha256",{})
    if not isinstance(expected,dict):expected={}
    def valid_sha(x):
        return isinstance(x,str) and re.fullmatch(r"[a-f0-9]{64}",x) is not None
    for key in ("governor","css","manifest","fragment_class"):
        if not valid_sha(expected.get(key)):fail("untrusted_fragment_pin_"+key)
    site=policy.get("local_runtime",{}).get("site_root","")
    root=Path(site) if isinstance(site,str) else Path("/not-enrolled")
    if not root.is_dir() or root.is_symlink():
        fail("site_root_not_approved")
    mu=root/"wp-content/mu-plugins"
    data={}
    for key,file in (("governor","mad4b-render-query-governor.php"),
                     ("css","414-mad4b-stable-listing-css-identity.php"),
                     ("manifest","mad4b-selective-render-policy.json")):
        p=mu/file
        try:
            st=p.lstat()
            if p.is_symlink() or not p.is_file() or st.st_size<50 or st.st_size>180000:
                raise ValueError("unsafe_file")
            b=p.read_bytes()
        except (OSError,ValueError):
            fail("fragment_file_missing_or_unsafe_"+key)
            data[key]=b""
        else:
            data[key]=b
            if valid_sha(expected.get(key)) and hashlib.sha256(b).hexdigest()!=expected[key]:
                fail("fragment_source_hash_drift_"+key)
    body=data["governor"].decode("utf-8","replace")
    start="final class MAD4B_Render_Query_Public_Fragments {"
    ending="\n}\nadd_action('wp', ['MAD4B_Render_Query_Public_Fragments'"
    if body.count(start)!=1:
        fail("fragment_class_not_unique")
        core=""
    else:
        start_at=body.index(start)
        close_at=body.find(ending,start_at)
        if close_at<0:
            fail("fragment_hook_or_class_not_canonical")
            core=""
        else:
            core=body[start_at:close_at+2]
    core_sha=hashlib.sha256(core.encode()).hexdigest() if core else None
    if core and valid_sha(expected.get("fragment_class")) and core_sha!=expected["fragment_class"]:
        fail("fragment_class_contract_drift")
    css=data["css"].decode("utf-8","replace")
    if ("final class MAD4B_Stable_Listing_CSS_Identity" not in css
        or "normalize_public_term_fragment" not in css
        or "capture_tour_route" not in css):
        fail("fragment_css_contract_missing")
    if (not core or "verifiedTourRoute" not in core
        or "fragment_market_identity" not in core
        or "private static function safe_html" not in core
        or "self::VERSION" not in core):
        fail("fragment_private_market_contract_missing")
    meta=None
    try:
        meta=json.loads(data["manifest"])
    except (TypeError,ValueError):
        fail("fragment_manifest_invalid_json")
    if not isinstance(meta,dict):
        meta={}
    host=urlsplit(str(policy.get("origin",""))).hostname
    if (meta.get("contract")!="mad4b.render-query.public-fragments.v1"
        or meta.get("site_host")!=host
        or meta.get("environment")!=cfg.get("environment")
        or meta.get("status")!="active"):
        fail("fragment_manifest_origin_or_environment")
    ttl=meta.get("ttl_seconds")
    if not isinstance(ttl,int) or isinstance(ttl,bool) or not 15<=ttl<=600:
        fail("fragment_ttl_out_of_range")
    templates=meta.get("templates")
    if not isinstance(templates,dict) or not 1<=len(templates)<=16:
        fail("fragment_template_policy_missing")
        templates={}
    for tid,spec in templates.items():
        if (not isinstance(tid,str) or re.fullmatch(r"[1-9][0-9]{0,9}",tid) is None
            or not isinstance(spec,dict)
            or not valid_sha(spec.get("elementor_sha256"))
            or spec.get("taxonomies")!=["destinations"]):
            fail("fragment_template_identity_invalid")
            break
        if spec.get("tour_public_fragment") is True and (
            spec.get("tour_css_identity")!="jet_term_fallback"
            or spec.get("tour_untranslated_fallback") is not True):
            fail("fragment_tour_fallback_not_certified")
    if (not any(spec.get("tour_public_fragment") is True for spec in templates.values()
           if isinstance(spec,dict))):
        fail("no_certified_tour_fragment_template")
    if cfg.get("require_tour_only") and any(
        not spec.get("tour_only") is True for spec in templates.values()
        if isinstance(spec,dict) and spec.get("tour_public_fragment")):
        fail("tenant_tour_only_isolation_missing")
    if cfg.get("require_tour_only") and not isinstance(cfg.get("require_tour_only"),bool):
        fail("tenant_policy_type_invalid")
    return {
        "contract":"mad4b.performance.fragment-static-integrity.v1",
        "state":"passed" if not issues else "hold",
        "reason_codes":issues,
        "fragment_class_sha256":core_sha,
        "governor_sha256":hashlib.sha256(data["governor"]).hexdigest() if data["governor"] else None,
        "css_sha256":hashlib.sha256(data["css"]).hexdigest() if data["css"] else None,
        "template_count":len(templates),
        "read_only":True,
        "runtime_wordpress_options_proven":False,
        "actual_geographic_origin_proven":False,
        "original313_release_approved":False
    }

def wpml_jetengine_prefetch_integrity_readback(policy:dict)->dict:
    """Static fail-closed source pin checked by existing company performance monitor.

    Does not run WP-CLI or infer runtime WordPress options. The original
    WPML API remains the only translation authority.
    """
    cfg=policy.get("wpml_jetengine_prefetch_integrity_v1",{})
    if not isinstance(cfg,dict) or cfg.get("enabled") is not True:
        return {"state":"not_enrolled","read_only":True}
    issues=[]
    def reject(code):
        if code not in issues:issues.append(code)
    def sha_valid(s):
        return isinstance(s,str) and re.fullmatch(r"[a-f0-9]{64}",s) is not None
    root=policy.get("local_runtime",{}).get("site_root","")
    site=Path(root) if isinstance(root,str) and root else Path("/not-enrolled")
    if not site.is_dir() or site.is_symlink():
        reject("prefetch_site_root_invalid")
    expected=cfg.get("sha256",{})
    if not isinstance(expected,dict):expected={}
    owner=site/"wp-content/mu-plugins/mad4b-elementor-wpml-schema-guard.php"
    vendor=site/"wp-content/plugins/sitepress-multilingual-cms/classes/core-abstract-classes/class-wpml-element-translation.php"
    found={}
    for name,path in (("owner",owner),("vendor",vendor)):
        if not sha_valid(expected.get(name)):
            reject("untrusted_"+name+"_sha256")
        try:
            st=path.lstat()
            if path.is_symlink() or not path.is_file() or not 50<=st.st_size<=110000:
                raise ValueError("file_not_regular")
            b=path.read_bytes()
        except (OSError,ValueError):
            reject(name+"_not_regular_or_missing")
            found[name]=b""
        else:
            found[name]=b
            if sha_valid(expected.get(name)) and hashlib.sha256(b).hexdigest()!=expected[name]:
                reject(name+"_version_hash_drift")
    owner_text=found["owner"].decode("utf-8","replace")
    expected_vendor=expected.get("vendor","")
    if (not owner_text
        or "final class MAD4B_Elementor_WPML_Schema_Guard_V1" not in owner_text
        or "mad4b_wpml_jetengine_taxonomy_prefetch_mode" not in owner_text
        or "jet-engine/meta-boxes/taxonomy-options/terms-args" not in owner_text
        or "$wpml_term_translations->prefetch_ids($ids)" not in owner_text
        or "count($ids)>800" not in owner_text
        or "is_admin() || wp_doing_ajax()" not in owner_text
        or "private static array $tax_prefetched" not in owner_text
        or not sha_valid(expected_vendor)
        or ("hash_equals('"+expected_vendor+"'") not in owner_text):
        reject("prefetch_native_contract_missing")
    # The existing Code Release policy already pins an OFFLINE, local Git root.
    # Reuse that authority: immutable Git blob + pinned candidate ref + live
    # source SHA; no fetch, ls-remote, GitHub, remote credentials, or network.
    local=cfg.get("local_git_source",{})
    repository=str(local.get("repo","")) if isinstance(local,dict) else ""
    commit=str(local.get("commit","")) if isinstance(local,dict) else ""
    candidate_ref=str(local.get("ref","")) if isinstance(local,dict) else ""
    blob_path="wp-content/mu-plugins/mad4b-elementor-wpml-schema-guard.php"
    git_blob_sha=None
    if (not re.fullmatch(r"[0-9a-f]{40}",commit)
        or candidate_ref!="refs/mad4b/release-candidates/wpml-jetengine-native-prefetch-v1"
        or not repository.startswith("/opt/")
        or Path(repository).is_symlink()):
        reject("local_git_pin_invalid")
    else:
        try:
            official=json.loads(Path(
                "/etc/mad4b/company-code-release-policy.json").read_text())
            if official.get("source_git_root")!=repository:
                reject("local_git_not_canonical_release_source")
            elif not Path(repository).is_dir():
                reject("local_git_repository_missing")
            else:
                ref_res=subprocess.run(
                    ["git","-C",repository,"rev-parse","--verify",candidate_ref],
                    capture_output=True,timeout=7,check=False)
                if ref_res.returncode!=0 or ref_res.stdout.decode().strip()!=commit:
                    reject("local_git_candidate_ref_drift")
                blob_res=subprocess.run(
                    ["git","-C",repository,"cat-file","blob",
                     commit+":"+blob_path],
                    capture_output=True,timeout=7,check=False)
                if blob_res.returncode or not 50<=len(blob_res.stdout)<=110000:
                    reject("local_git_blob_missing_or_unsafe")
                else:
                    git_blob_sha=hashlib.sha256(blob_res.stdout).hexdigest()
                    if (git_blob_sha!=expected.get("owner")
                        or blob_res.stdout!=found["owner"]):
                        reject("local_git_blob_not_equal_live_source")
        except (OSError,ValueError,TypeError,subprocess.TimeoutExpired) as exc:
            reject("local_git_readback_error_"+type(exc).__name__)
    return {
        "contract":"mad4b.performance.wpml-jetengine-native-prefetch-integrity.v1",
        "state":"passed" if not issues else "hold",
        "reason_codes":issues,
        "owner_sha256":hashlib.sha256(found["owner"]).hexdigest() if found["owner"] else None,
        "local_git_commit":commit,
        "local_git_ref":candidate_ref,
        "local_git_blob_sha256":git_blob_sha,
        "git_remote_contacted":False,
        "vendor_sha256":hashlib.sha256(found["vendor"]).hexdigest() if found["vendor"] else None,
        "read_only":True,
        "runtime_wp_options_verified":False,
        "mutation_performed":False,
        "original313_release_admitted":False
    }

def read_only_receipt(path:Path):
    if not path.is_file() or path.is_symlink() or path.stat().st_size>131072:
        raise ValueError("invalid_monitor_receipt")
    data=json.loads(path.read_text())
    if not isinstance(data,dict) or data.get("contract")!="mad4b.performance-mobile-http-readback.v2":
        raise ValueError("invalid_monitor_contract")
    return data

def run(policy_dir:Path,state_dir:Path,max_load=1.6):
    if not policy_dir.is_dir() or policy_dir.is_symlink():
        raise ValueError("policy_directory_missing")
    cpus=os.cpu_count() or 1
    load=os.getloadavg()[0]
    stamp=datetime.now(timezone.utc).isoformat()
    if load > cpus * max_load:
        return {"contract":CONTRACT,"state":"deferred_host_load","load1":round(load,2),
                "cpu_count":cpus,"observed_utc":stamp,"read_only":True}
    policies=sorted(policy_dir.glob("*-http-policy.json"))
    if not 1<=len(policies)<=12:
        raise ValueError("policy_count_out_of_range")
    items=[]
    hosts=set()
    # One company candidate per daily pass, even if many sites drift.
    # No new daemon/timer; full browser rebuilds are capacity-deferred.
    enrolled_candidate=None
    for file in policies:
        name=file.name.removesuffix("-http-policy.json")
        if not SAFE.fullmatch(name) or file.is_symlink() or file.stat().st_size>8192:
            raise ValueError("invalid_company_policy")
        policy=json.loads(file.read_text())
        if policy.get("company")!=name:
            raise ValueError("policy_company_mismatch")
        if policy.get("origin") in hosts:
            raise ValueError("duplicate_origin")
        hosts.add(policy["origin"])
        try:
            status=audit(policy)
        except (Exception) as exc:
            status={"contract":"mad4b.performance-mobile-http-readback.v2","company":name,
                    "state":"hold","read_only":True,
                    "reason":type(exc).__name__+":"+str(exc)[:140]}
        # Use the already-running monitor as owner. Local CSS/WP/runtime
        # introspection is opt-in per enrolled company and never public-facing.
        if policy.get("local_runtime") and status.get("observations"):
            try:
                status["reconciliation"]=diagnose(policy,status["observations"])
            except (ValueError,OSError,RuntimeError,TimeoutError,subprocess.TimeoutExpired) as exc:
                status["state"]="hold"
                status["reconciliation"]={
                    "contract":"mad4b.performance-cache-convergence.v3",
                    "state":"unverified_runtime",
                    "reason":str(exc)[:110],
                    "mutation_performed":False,
                    "shared_price_cache_admitted":False}
        # Company-opted-in market/edge evidence uses the SAME periodic monitor.
        # It compiles candidate configuration only; no plugin, config or
        # release mutation, and no prices/markets inferred from visitor headers.
        if policy.get("market_cache_workflow",{}).get("enabled") is True:
            from market_cache_candidate_workflow_v1 import prepare as market_prepare
            try:
                status["market_cache_candidate_workflow"]=market_prepare(
                    policy, Path("/opt/mad4b-company-provisioning/reports/performance-market-cache-v1"))
            except (ValueError,OSError,RuntimeError,TimeoutError,subprocess.TimeoutExpired) as exc:
                status["state"]="hold"
                status["market_cache_candidate_workflow"]={
                    "contract":"mad4b.market-cache-candidate-workflow.v1",
                    "state":"candidate_hold",
                    "reason":type(exc).__name__+":"+str(exc)[:100],
                    "shared_cache_admitted":False,"production_mutation":False}
        # Additive, read-only Fragment integrity check in the SAME enrolled
        # performance monitor. Never turn a pre-existing HOLD into PASS.
        if policy.get("fragment_integrity_v1",{}).get("enabled") is True:
            try:
                status["fragment_integrity"]=fragment_integrity_readback(policy)
            except (OSError,ValueError,RuntimeError,TypeError) as exc:
                status["fragment_integrity"]={
                    "contract":"mad4b.performance.fragment-static-integrity.v1",
                    "state":"hold","reason_codes":["readback_exception_"+type(exc).__name__],
                    "read_only":True}
            if status["fragment_integrity"]["state"]!="passed":
                status["state"]="hold"
        # Exact content-addressed Critical CSS coherence: source and tenant
        # opt in under the existing daily monitor; no new background worker.
        if policy.get("critical_css_integrity_v1",{}).get("enabled") is True:
            try:
                status["critical_css_integrity"]=(
                    verified_critical_css_integrity_readback(policy))
            except (OSError,ValueError,RuntimeError,TypeError) as exc:
                status["critical_css_integrity"]={
                    "contract":"mad4b.performance.critical-css-static-integrity.v1",
                    "state":"hold",
                    "reason_codes":["readback_exception_"+type(exc).__name__],
                    "read_only":True}
            if status["critical_css_integrity"]["state"]!="passed":
                status["state"]="hold"
        # Same scheduled company monitor owns WPML/JetEngine native-prefetch
        # fidelity. New PASS must never suppress an older HOLD.
        if policy.get("wpml_jetengine_prefetch_integrity_v1",{}).get("enabled") is True:
            try:
                status["wpml_jetengine_prefetch_integrity"]=(
                    wpml_jetengine_prefetch_integrity_readback(policy))
            except (OSError,ValueError,RuntimeError,TypeError) as exc:
                status["wpml_jetengine_prefetch_integrity"]={
                    "contract":"mad4b.performance.wpml-jetengine-native-prefetch-integrity.v1",
                    "state":"hold","reason_codes":["readback_exception_"+type(exc).__name__],
                    "read_only":True}
            if status["wpml_jetengine_prefetch_integrity"]["state"]!="passed":
                status["state"]="hold"
        status["observed_utc"]=datetime.now(timezone.utc).isoformat()
        persist(state_dir/(name+"-latest.json"),status)
        if (enrolled_candidate is None and
            policy.get("candidate_workflow",{}).get("enabled") is True and
            status.get("reconciliation",{}).get("state")=="reconciliation_required"):
            enrolled_candidate=(name,status.get("reconciliation",{}).get("candidate_fingerprint"))
        items.append({"company":name,"state":status["state"],
                      "observations":len(status.get("observations",[])),
                      "convergence_state":status.get("reconciliation",{}).get("state","not_enrolled"),
                      "candidate_intents":len(status.get("reconciliation",{}).get("reconciliation_intents",[]))})
    candidate_result=None
    if enrolled_candidate:
        from css_reconciliation_workflow_v3 import execute as try_reconcile
        chosen,fingerprint=enrolled_candidate
        try:
            candidate_result=try_reconcile(chosen)
        except Exception as exc:
            # Candidate is non-authorizing. Never convert an exception into
            # a public cache grant or suppress the original observer HOLD.
            candidate_result={"state":"candidate_runner_hold",
                "reason":type(exc).__name__+":"+str(exc)[:100]}
        if isinstance(candidate_result,dict):
            existing=state_dir/(chosen+"-latest.json")
            current=read_only_receipt(existing)
            if (current.get("reconciliation",{}).get("candidate_fingerprint")==fingerprint):
                current["css_candidate_workflow"]={
                    "state":candidate_result.get("state"),
                    "fingerprint":fingerprint,
                    "release_admitted":False,
                    "observed_utc":datetime.now(timezone.utc).isoformat()}
                persist(existing,current)
    out={"contract":CONTRACT,"state":"passed" if all(x["state"]=="passed" for x in items)else "hold",
         "read_only":True,"observed_utc":stamp,"companies":items,
         "ps_score_measured":False,"mutation_performed":False}
    persist(state_dir/"latest-summary.json",out)
    return out

def main():
    parser=argparse.ArgumentParser()
    parser.add_argument("--policies",type=Path,required=True)
    parser.add_argument("--state",type=Path,required=True)
    a=parser.parse_args()
    try:result=run(a.policies,a.state)
    except Exception as exc:
        result={"contract":CONTRACT,"state":"hold","read_only":True,
                "reason":type(exc).__name__+":"+str(exc)[:160]}
    print(json.dumps(result,sort_keys=True,indent=2))
    return 0 if result["state"] in ("passed","deferred_host_load") else 2

if __name__=="__main__":sys.exit(main())
