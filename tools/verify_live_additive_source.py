#!/usr/bin/env python3
"""Fail-closed source-only verifier of additive live snapshots. No authorization."""
from __future__ import annotations
import argparse
import hashlib
import json
from pathlib import Path
import subprocess

ROOT=Path(__file__).resolve().parents[1]
MANIFEST=ROOT/"docs/release-coverage/portfolio-live-additive-20261111.json"
LIVE=Path("/opt/hostinger-migrations/hosting-3/etg-commerce-staging/site-copy")

class Unverified(ValueError):pass

def git(*args):
    completed=subprocess.run(["git","-C",str(ROOT),*args],capture_output=True,
                             text=True,timeout=20,check=False)
    return completed if completed.returncode==0 else None

def verify(data,*,live=True):
    if not isinstance(data,dict) or data.get('contract')!='mad4b.live-additive-source-provenance.v1':
        raise Unverified("UNTRUSTED_MANIFEST")
    head=data.get("base_head")
    if not isinstance(head,str) or len(head)!=40 or git("cat-file","-t",head) is None:
        raise Unverified("BASE_REVISION_UNTRUSTED")
    files=data.get("files")
    if not isinstance(files,list) or len(files)!=19:
        raise Unverified("INCOMPLETE_ADDENDUM")
    visited=set()
    for item in files:
        if not isinstance(item,dict) or set(item)!={"path","source","sha256","bytes","source_owner_uid","is_runtime_activated"}:
            raise Unverified("WRONG_MANIFEST_ENTRY")
        rel=item["path"]
        if not isinstance(rel,str) or rel.startswith(("-","/")) or chr(92) in rel or any(x in ("",".","..") for x in rel.split("/")):
            raise Unverified("UNSAFE_SOURCE_PATH")
        if rel in visited:raise Unverified("DUPLICATE_PATH")
        visited.add(rel)
        allowed_portfolio=(rel.startswith("wp-content/plugins/mad4b-company-portfolio-manager/includes/") and rel.endswith(".php")
           or rel.startswith("wp-content/plugins/mad4b-company-portfolio-manager/assets/") and rel.endswith((".js",".css"))
           or rel.startswith("wp-content/plugins/mad4b-company-portfolio-manager/tests/") and rel.endswith(".php"))
        allowed_control=(rel in ("wp-content/plugins/mad4b-site-control-plane/tests/google-incremental-consent-panel-regression.php",
                                 "wp-content/plugins/mad4b-site-control-plane/tests/google-gtm-publish-version-scope.php"))
        if not allowed_portfolio and not allowed_control:
            raise Unverified("PATH_OUTSIDE_ADDENDUM")
        if item['source']!='live_Staging_exact_snapshot' or item['source_owner_uid'] not in (0,33) or item['is_runtime_activated'] is not False:
            raise Unverified("SOURCE_CLAIM_INVALID")
        if not isinstance(item['sha256'],str) or len(item['sha256'])!=64 or not isinstance(item['bytes'],int) or item['bytes']<=0:
            raise Unverified("SOURCE_DIGEST_INVALID")
        if git("cat-file","-e",head+":"+rel) is not None:
            raise Unverified("ADDENDUM_REPLACES_EXISTING_BASE_SOURCE")
        for base in (ROOT, LIVE) if live else (ROOT,):
            path=base/rel
            if path.is_symlink() or not path.is_file() or path.stat().st_size!=item["bytes"]:
                raise Unverified("MISSING_OR_CHANGED_SOURCE")
            if hashlib.sha256(path.read_bytes()).hexdigest()!=item["sha256"]:
                raise Unverified("SOURCE_SHA_MISMATCH")
    if sum("mad4b-company-portfolio-manager" in r for r in visited)!=17 or len(visited)!=19:
        raise Unverified("GROUP_COUNTS_MISMATCH")
    return {"state":"PASS_SOURCE_SNAPSHOT_ONLY","verified_files":len(visited),
            "verified_against_current_live":live,"deployment_approved":False,
            "wordpress_mutated":False,"release_certified":False}

if __name__=="__main__":
    cli=argparse.ArgumentParser()
    cli.add_argument("--without-live",action="store_true")
    args=cli.parse_args()
    try:
        out=verify(json.loads(MANIFEST.read_text()),live=not args.without_live)
        print(json.dumps(out,sort_keys=True))
    except (Unverified,OSError,ValueError,subprocess.TimeoutExpired) as exc:
        print(json.dumps({"state":"BLOCKED","reason":str(exc),"release_certified":False}))
        raise SystemExit(2)
