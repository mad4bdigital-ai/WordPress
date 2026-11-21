#!/usr/bin/env python3
"""Bounded CSS evidence reconciliation using the existing MAD4B native CSS pipeline.

Consumes enrolled Performance monitor reconciliation_intents. Builds a CSS
candidate only when each source route has an identical complete source across
required viewports; samples never auto-approve a release or public cache.
"""
from __future__ import annotations
import argparse
from datetime import datetime, timezone
import fcntl
import hashlib
import json
import os
from pathlib import Path
import re
import subprocess
import sys
import tempfile
import time
from urllib.parse import urlsplit

from cache_coherence_intelligence_v3 import _runtime_owner, _manifest, _wp_option

CONTRACT="mad4b.css-reconciliation-workflow.v3"
ROOT=Path("/opt/mad4b-company-provisioning")
TOOL_ROOT=ROOT/"ops/mobile-critical-css-v1"
OUTPUT_ROOT=ROOT/"reports/performance-css-reconciliation-v3"
STATE_ROOT=ROOT/"runtime-state/performance-v2"
POLICY_ROOT=ROOT/"policies/performance-v2"
IMAGE="mad4b-firecrawl-playwright:20261107-lifecycle-v3"
VALID_LOCALE=re.compile(r"^[a-z]{2}(?:-[a-z]{2})?$")
VALID_COMPANY=re.compile(r"^[a-z][a-z0-9_-]{1,63}$")
SHA=re.compile(r"^[a-f0-9]{64}$")
VIEWPORTS=(320,390,414,768)
REASONS={"CSS_SOURCE_HASH_NOT_IN_MANIFEST","CSS_ROUTE_LOCALE_VARIANT_ABSENT",
         "CSS_EPOCH_DRIFT","ELEMENTOR_GRAPH_DRIFT","CSS_SOURCE_ASSET_NOT_VERIFIABLE",
         "CSS_ORIGIN_ADMISSION_DENIED","CSS_RESPONSE_VARIANT_UNSTABLE",
         "CSS_BROWSER_EVIDENCE_NOT_ADMITTED"}

def read_json(path:Path, limit=1048576):
    if not path.is_file() or path.is_symlink() or path.stat().st_size>limit:
        raise ValueError("evidence_file_untrusted")
    return json.loads(path.read_text())

def atomic(path:Path, value:dict):
    path.parent.mkdir(parents=True,exist_ok=True)
    fd,tmp=tempfile.mkstemp(prefix=".mad4b-css-",dir=path.parent)
    try:
        with os.fdopen(fd,"w") as fp:
            json.dump(value,fp,sort_keys=True,indent=2);fp.write("\n")
            fp.flush();os.fsync(fp.fileno())
        os.chmod(tmp,0o640);os.replace(tmp,path)
    finally:
        if os.path.exists(tmp):os.unlink(tmp)

def prior_css_strategy_for_qa(manifest:dict,variants:list) -> dict|None:
    """Discover a previous company-scoped tuning hint, NEVER reuse a CSS grant.

    Historical CSS SHAs can only inform a fresh performance QA strategy.
    Every enrolled route/locale must have one coherent previously passed
    strategy; conflicting or incomplete evidence falls back to native trial.
    No cert, asset, origin, route, or market admission is inferred from it.
    """
    entries=manifest.get("entries",{})
    if not isinstance(entries,dict) or not isinstance(variants,list) or not variants:
        return None
    proposals=[]
    for row in variants:
        key=str(row.get("path",""))+"|"+str(row.get("locale",""))+"|mobile"
        hints=set()
        for entry in entries.values():
            if not isinstance(entry,dict):continue
            found=(entry.get("variants") or {}).get(key)
            if not isinstance(found,dict) or found.get("gate_status")!="pass":
                continue
            priority=found.get("css_fetch_priority")
            preload=found.get("css_full_preload")
            if priority not in ("low","auto","high") or not isinstance(preload,bool):
                continue
            evidence=found.get("evidence",{})
            if not isinstance(evidence,dict):continue
            hints.add((priority,preload,
                evidence.get("requires_mobile_hero_priority") is True,
                evidence.get("requires_city_preload_deferred") is True))
        if len(hints)!=1:return None
        proposals.append(next(iter(hints)))
    if not proposals or len(set(proposals))!=1:return None
    priority,preload,hero,defer=proposals[0]
    return {"priority":priority,"preload":preload,
            "hero900":hero,"defer_city":defer}


def plan(company:str):
    if not VALID_COMPANY.fullmatch(company):raise ValueError("invalid_company")
    policy=read_json(POLICY_ROOT/(company+"-http-policy.json"),8192)
    state=read_json(STATE_ROOT/(company+"-latest.json"),131072)
    if policy.get("company")!=company or policy.get("enabled") is not True:
        raise ValueError("not_enrolled")
    rec=state.get("reconciliation")
    if not isinstance(rec,dict) or rec.get("contract")!="mad4b.performance-cache-convergence.v3":
        raise ValueError("missing_reconciliation_evidence")
    origin=urlsplit(policy.get("origin",""))
    host=origin.hostname
    if origin.scheme!="https" or not host or rec.get("site")!=host or rec.get("site_binding_verified") is not True:
        raise ValueError("site_binding_changed")
    fingerprint=rec.get("candidate_fingerprint")
    if not isinstance(fingerprint,str) or not SHA.fullmatch(fingerprint):
        raise ValueError("missing_immutable_candidate_fingerprint")
    intents=rec.get("reconciliation_intents",[])
    css_intent=next((i for i in intents if i.get("capability")=="critical_css_lifecycle" and
              i.get("auto_admit") is False and i.get("release_state")=="candidate_only"),None)
    if css_intent is None or not set(rec.get("reason_codes",[])) & REASONS:
        return {"contract":CONTRACT,"company":company,"fingerprint":fingerprint,
          "state":"no_css_refresh_needed","can_run":False,"read_only":True}
    routes=policy.get("routes",[])
    if not isinstance(routes,list) or not 1<=len(routes)<=12:
        raise ValueError("invalid_locale_routes")
    requested=set((x.get("path"),x.get("locale")) for x in routes)
    observed=set((x.get("path"),x.get("locale")) for x in rec.get("variants",[]))
    if not observed or not observed.issubset(requested):
        raise ValueError("unexpected_route_not_enrolled")
    locales=sorted({l for p,l in observed if (p=="/" and VALID_LOCALE.fullmatch(l)) or
                    (p=="/"+l+"/" and VALID_LOCALE.fullmatch(l))})
    if len(locales)!=len(observed) or len(locales)>8:
        raise ValueError("css_pipeline_only_supports_canonical_home_locales")
    site, container=_runtime_owner(policy.get("local_runtime"))
    manifest,manifest_sha=_manifest(site,host)
    current_epoch=int(_wp_option(container,"mad4b_css_ai_epoch_v1"))
    if current_epoch!=int(rec.get("current_css_epoch",-1)):
        raise ValueError("css_epoch_changed_after_observation")
    if not hashlib.sha256((site/"wp-content/mad4b-critical-css-v1/manifest.json").read_bytes()).hexdigest()==rec.get("manifest_sha256"):
        raise ValueError("manifest_changed_after_observation")
    # Prior company-certified CSS metadata can seed a *candidate QA strategy*
    # only for an unchanged exact source SHA. An older proof never signs the
    # current CSS epoch or authorizes publication.
    strategy_rows=[]
    for row in rec.get("variants",[]):
        sha=row.get("source_sha256","")
        entry=manifest.get("entries",{}).get(sha,{})
        variant=(entry.get("variants") or {}).get(
            row.get("path","")+"|"+row.get("locale","")+"|mobile",{})
        if isinstance(variant,dict) and variant.get("gate_status")=="pass":
            ev=variant.get("evidence",{})
            strategy_rows.append({
                "priority":variant.get("css_fetch_priority","low"),
                "preload":variant.get("css_full_preload") is True,
                "hero900":ev.get("requires_mobile_hero_priority") is True,
                "defer_city":ev.get("requires_city_preload_deferred") is True})
    shared=(strategy_rows[0] if len(strategy_rows)==len(locales) and
        all(x==strategy_rows[0] for x in strategy_rows) else None)
    # A previously passed same-company strategy is only a candidate test
    # hint if the new exact CSS SHA has not been certified. Every new bundle
    # still requires the full 4-viewport browser gate, no auto-promotion.
    historical=None if shared else prior_css_strategy_for_qa(
        manifest,rec.get("variants",[]))
    strategy_source=("current_css_certified" if shared else
       "historical_strategy_qa_hint_only" if historical else
       "conservative_uncertified")
    return {
        "contract":CONTRACT,"state":"candidate_requested","company":company,
        "qa_strategy":shared or historical or {
           "priority":"low","preload":False,"hero900":False,"defer_city":False},
        "qa_strategy_source":strategy_source,
        "historical_css_certification_reused":False,
        "host":host,"origin":policy["origin"],"fingerprint":fingerprint,
        "site_root":str(site),"manifest_sha256":manifest_sha,"epoch":current_epoch,
        "locales":locales,"viewports":list(VIEWPORTS),
        "observer_source_variants":[{
            "locale":v.get("locale"),"css_file":v.get("bundle"),
            "source_sha":v.get("source_sha256"),
            "dom_sha":next((o.get("elementor_doc_fingerprint") for o in
                   state.get("observations",[]) if o.get("locale")==v.get("locale")
                    and o.get("path")==v.get("path")),None)
            } for v in rec.get("variants",[])],
        "discovery_reasons":sorted(set(rec.get("reason_codes",[])) & REASONS),
        "can_run":True,"css_public_approved":False,
        "market_shared_cache_approved":False,"original313_granted":False,
    }

def profile_stable(rows:list, locales:list):
    if not isinstance(rows,list) or len(rows)!=len(locales)*len(VIEWPORTS):
        return False,"CAPTURE_COVERAGE_INCOMPLETE",{}
    grouping={}
    for row in rows:
        lang=row.get("lang");width=row.get("width")
        if row.get("error") or lang not in locales or width not in VIEWPORTS:
            return False,"CAPTURE_FAILED",{}
        identity=(lang,width)
        if identity in grouping:return False,"CAPTURE_DUPLICATE_VIEWPORT",{}
        grouping[identity]=row
    output={}
    for lang in locales:
        values=[grouping.get((lang,width)) for width in VIEWPORTS]
        if any(v is None for v in values):
            return False,"CAPTURE_COVERAGE_INCOMPLETE",{}
        fields=("css_file","source_sha","dom_sha","page_id")
        versions={key:{v.get(key) for v in values} for key in fields}
        if any(len(v)!=1 or None in v for v in versions.values()):
            output[lang]={key:sorted(map(str,x)) for key,x in versions.items()}
            return False,"SOURCE_VARIANT_UNSTABLE_ACROSS_VIEWPORTS",output
        output[lang]={key:next(iter(x)) for key,x in versions.items()}
    return True,"STABLE",output

def run_native(plan:dict, step:str, out:Path, timeout:int):
    wp=Path(plan["site_root"])/"wp-content"
    if step not in ("capture","assemble","verify"):raise ValueError("invalid_pipeline_stage")
    locales=",".join(plan["locales"])
    args=["docker","run","--rm","--user","0:0","--memory=1000m",
          "--cpus=1.5","--pids-limit=250","--security-opt=no-new-privileges",
          "-v",str(TOOL_ROOT)+":/tools:ro","-v",str(out)+":/out",
          "-v",str(wp/"litespeed/css")+":/source-css:ro",
          "-e","NODE_PATH=/usr/src/app/node_modules",
          "-e","MAD4B_OUTPUT=/out","-e","MAD4B_CSS_ROOT=/source-css",
          "-e","MAD4B_SOURCE_SITE="+plan["origin"],
          "-e","MAD4B_LANGS="+locales,"-e","MAD4B_WIDTHS="+",".join(map(str,VIEWPORTS)),
          "-e","MAD4B_QA_LANGS="+locales,"-e","MAD4B_QA_WIDTHS="+",".join(map(str,VIEWPORTS)),
          IMAGE,"node","/tools/"+{"capture":"capture.js","assemble":"assemble.js","verify":"verify.js"}[step]]
    if step=="verify":
        strategy=plan["qa_strategy"]
        args[-3:-3]=["-e","MAD4B_CSS_PRIORITY="+strategy["priority"],
          "-e","MAD4B_CSS_PRELOAD="+("1" if strategy["preload"] else "0"),
          "-e","MAD4B_HERO_900_CANDIDATE="+("1" if strategy["hero900"] else "0"),
          "-e","MAD4B_FIRST_CITY_DEFER_CANDIDATE="+("1" if strategy["defer_city"] else "0")]
    p=subprocess.run(args,capture_output=True,text=True,timeout=timeout,check=False)
    # Keep a bounded log; scripts report sanitized route/bundle and measured
    # values only. No cookies or customer data enter these commands.
    (out/(step+".log")).write_text((p.stdout+"\n"+p.stderr)[-20000:])
    if p.returncode:raise RuntimeError(step.upper()+"_EXECUTION_FAILED")
    return p.stdout

def execute(company:str,force=False):
    task=plan(company)
    if not task.get("can_run"):return task
    # Respect a saturated live VPS. Defer, do not save a failed attempt or
    # spawn a duplicate browser, so the existing timer can retry later.
    available_kb=0
    for line in Path("/proc/meminfo").read_text().splitlines():
        if line.startswith("MemAvailable:"):
            available_kb=int(line.split()[1]);break
    if available_kb<4*1024*1024 or os.getloadavg()[0]>(os.cpu_count() or 1)*1.0:
        return {"contract":CONTRACT,"state":"deferred_host_capacity",
           "company":company,"fingerprint":task["fingerprint"],
           "read_only":True,"css_manifest_modified":False,
           "available_memory_mb":available_kb//1024}
    out=OUTPUT_ROOT/company/task["fingerprint"]
    out.mkdir(parents=True,exist_ok=True)
    lockfile=OUTPUT_ROOT/company/".reconcile.lock"
    with lockfile.open("a+") as lock:
        fcntl.flock(lock,fcntl.LOCK_EX|fcntl.LOCK_NB)
        receipt=out/"workflow-receipt.json"
        if receipt.exists() and not force:
            prev=read_json(receipt,32768)
            if prev.get("state") in ("source_unstable","qa_hold","candidate_evidence_ready"):
                return prev
        before={"contract":CONTRACT,"company":company,
          "fingerprint":task["fingerprint"],"state":"running",
          "started_utc":datetime.now(timezone.utc).isoformat(),
          "wordpress_modified":False,"css_manifest_modified":False,
          "nginx_modified":False,"browser_evidence_is_release_grant":False}
        atomic(receipt,before)
        result=before.copy()
        try:
            # Source must still match the drift report. Never regenerate an
            # invalidated candidate based on an earlier epoch.
            current=plan(company)
            if current.get("fingerprint")!=task["fingerprint"]:
                raise ValueError("SOURCE_CONVERGENCE_FINGERPRINT_MOVED")
            run_native(task,"capture",out,540)
            capture=read_json(out/"capture-index.json",81920)
            stable,reason,versions=profile_stable(capture.get("scenarios",[]),task["locales"])
            result["source_fingerprints"]=versions
            if stable:
                for prior in task["observer_source_variants"]:
                    updated=versions.get(prior["locale"])
                    if not updated or any(updated.get(key)!=prior.get(key)
                            for key in ("css_file","source_sha","dom_sha")):
                        stable=False
                        reason="OBSERVATION_TO_CAPTURE_DRIFT"
                        break
            if not stable:
                result.update(state="source_unstable",reason=reason)
            else:
                run_native(task,"assemble",out,40)
                candidates=read_json(out/"candidates.json",32768)
                if any(c.get("gate_status")!="candidate" or
                    sorted(c.get("widths",[]))!=list(VIEWPORTS)
                    for c in candidates.get("candidates",[])):
                    result.update(state="source_unstable",reason="ASSEMBLED_SOURCE_INCOMPLETE")
                else:
                    run_native(task,"verify",out,840)
                    qa=read_json(out/"verification.json",131072)
                    all_passed=all(qa.get("gates",{}).get(l,{}).get("decision")=="passed_evidence"
                                  for l in task["locales"])
                    result.update(state="candidate_evidence_ready" if all_passed else "qa_hold",
                                  verification_sha256=hashlib.sha256((out/"verification.json").read_bytes()).hexdigest(),
                                  qa_gates={l:qa.get("gates",{}).get(l,{}) for l in task["locales"]},
                                  performance_gate_separate=True,release_admission=False)
            if result["state"]=="candidate_evidence_ready" and plan(company)["fingerprint"]!=task["fingerprint"]:
                result.update(state="source_unstable",reason="SOURCE_CHANGED_DURING_QA")
        except (RuntimeError,ValueError,OSError,subprocess.TimeoutExpired) as exc:
            result.update(state="execution_hold",reason=type(exc).__name__+":"+str(exc)[:90])
        result["finished_utc"]=datetime.now(timezone.utc).isoformat()
        atomic(receipt,result)
        return result

def main():
    parser=argparse.ArgumentParser()
    parser.add_argument("--company",required=True)
    parser.add_argument("--execute",action="store_true")
    parser.add_argument("--force",action="store_true")
    args=parser.parse_args()
    try:
        response=execute(args.company,args.force) if args.execute else plan(args.company)
    except (ValueError,OSError,RuntimeError,subprocess.TimeoutExpired,BlockingIOError) as exc:
        response={"contract":CONTRACT,"state":"preflight_hold",
            "reason":type(exc).__name__+":"+str(exc)[:100],
            "wordpress_modified":False,"css_manifest_modified":False}
    print(json.dumps(response,sort_keys=True))
    return 0 if response["state"] in ("candidate_requested","candidate_evidence_ready",
               "qa_hold","source_unstable","no_css_refresh_needed",
               "deferred_host_capacity") else 2

if __name__=="__main__":sys.exit(main())
