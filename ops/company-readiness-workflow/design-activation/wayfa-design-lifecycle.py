#!/usr/bin/env python3
"""Single governed onboarding stage: schema parity -> design -> compatibility resolver."""
import argparse
import json
import pathlib
import subprocess
import sys
from datetime import datetime, timezone

ROOT=pathlib.Path("/opt/mad4b-company-provisioning")
BIN=ROOT/"bin"
REPORTS=ROOT/"reports"

def run(script, args):
    proc=subprocess.run(["python3",str(BIN/script),*args],
                        capture_output=True,text=True,timeout=270)
    if proc.returncode:
        raise RuntimeError(script+": "+proc.stderr[-700:])
    records=[]
    for line in proc.stdout.splitlines():
        if line.startswith("{"):
            try:records.append(json.loads(line))
            except json.JSONDecodeError:pass
    return records

def main():
    parser=argparse.ArgumentParser()
    parser.add_argument("--mode",choices=["plan","auto","resolve-diff"],default="plan")
    opts=parser.parse_args()
    schema_out=run("wayfa-schema-compatibility.py",["--probe"])
    schema=json.loads((REPORTS/"wayfatours-schema-compatibility.json").read_text())
    if schema["state"]!="SCHEMA_PARITY_CONFIRMED":
        diffs=run("wayfa-design-activate.py",["--mode","resolve-diff"])
        print(json.dumps({"state":"CONFIG_DIFF_ENGINE_REQUIRED","schema_diffs":schema["diffs"],
                          "resolver":diffs[-1] if diffs else {}, "tenant_mutated":False}))
        return
    if opts.mode=="resolve-diff":
        print(json.dumps({"state":"SECONDARY_RESOLVER_COMPLETE","details":
            run("wayfa-design-activate.py",["--mode","resolve-diff"])[-1]}))
        return
    apply=opts.mode=="auto"
    selection=["--apply"] if apply else ["--plan"]
    wpml=run("wayfa-wpml-resolve.py",selection)
    jet=run("wayfa-jet-config-autoload.py",selection)
    elementor=run("wayfa-design-activate.py",["--mode","schema-auto",*selection])
    stages={"schema":"SCHEMA_PARITY_CONFIRMED",
            "wpml":wpml[-1] if wpml else {},
            "jetengine":jet[-1] if jet else {},
            "elementor":next((r for r in reversed(elementor)
                              if r.get("state") in ("DESIGN_ACTIVATION_APPLIED",
                                                   "DESIGN_ACTIVATION_PLAN")),{}),
            "difference_resolver":next((r for r in reversed(elementor)
                                        if r.get("state")=="DIFFERENCES_DETECTED"),{})}
    if apply:
        status=run("company-readiness-workflow",["--refresh"])
        stages["central_readiness"]=status[-1] if status else {}
    out={"contract":"mad4b.company-onboarding-design-stage.v1",
         "site":"wayfatours.mad4b.com",
         "mode":opts.mode,"state":"SCHEMA_AUTO_COMPLETED" if apply else "SCHEMA_AUTO_PLAN",
         "stage_results":stages,
         "template_publish_is_separate_from_commercial_content":True,
         "generated_utc":datetime.now(timezone.utc).isoformat()}
    print(json.dumps(out,ensure_ascii=False))

if __name__=="__main__":main()
