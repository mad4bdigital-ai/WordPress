#!/usr/bin/env python3
"""Dynamic, read-only Signed V4 enrollment planner for ALL registered companies/sites.

Reads the ORIGINAL Central WordPress Company Environment Registry using its
registered admin-only diagnostic CLI. Does not grant trust, create site keys,
issue 305, modify target sites or grant Production authority.
"""
from __future__ import annotations
import json
import os
from pathlib import Path
import re
import stat
import subprocess
import sys
import tempfile
from urllib.parse import urlsplit

sys.path.insert(0, "/opt/mad4b-company-transport")
from wpcli_dynamic_actor import MARKER, with_actor

REPORT = Path("/opt/mad4b-company-provisioning/reports/company-signed-v4-enrollment-plan-current.json")
BASE = ["/usr/bin/docker", "exec", "-u", "www-data", "etg-commerce-staging-php",
        "/usr/local/bin/wp", "--path=/var/www/html", "--url=https://tours.dev.mad4b.com",
        "--skip-themes"]
COMPANY = re.compile(r"[a-z][a-z0-9-]{2,47}\Z")
UUID = re.compile(r"[a-f0-9]{8}-(?:[a-f0-9]{4}-){3}[a-f0-9]{12}\Z", re.I)
CONTRACT = "mad4b.company-environment-cli.v1"
OUTPUT = "mad4b.multi-company-signed-v4-readiness.v1"

def wp_once(args, runner=subprocess.run):
    # No site-supplied argv, no shell, no passwords.
    p = runner(with_actor(BASE + args + [MARKER], runner=runner),
               capture_output=True, text=True, timeout=50, check=False)
    if p.returncode: raise RuntimeError("ORIGINAL_CENTRAL_READBACK_REJECTED")
    rows = [v for v in p.stdout.splitlines() if v.startswith("{")]
    if len(rows)!=1: raise RuntimeError("ORIGINAL_CENTRAL_JSON_NOT_EXACT")
    return json.loads(rows[0])

def wp(args, runner=subprocess.run):
    # All commands here are original READ-ONLY diagnostics. A bounded retry
    # handles intermittent Central WP-CLI runtime failures, never bad identity.
    try:
        return wp_once(args,runner)
    except RuntimeError as error:
        if str(error) not in ("WPCLI_DYNAMIC_ACTOR_READBACK_DENIED",
                              "ORIGINAL_CENTRAL_READBACK_REJECTED"):
            raise
        return wp_once(args,runner)

def canonical(origin):
    if not isinstance(origin,str): return False
    u=urlsplit(origin)
    return (u.scheme=="https" and bool(u.hostname) and
            not u.username and not u.password and u.port is None and
            u.path in ("","/") and not u.query and not u.fragment and
            origin==f"https://{u.hostname}")

def classify(key,record,proof=None):
    if not isinstance(key,str) or key.count(":")!=1 or not isinstance(record,dict):
        raise ValueError("TARGET_SHAPE_INVALID")
    company,env=key.split(":")
    if not COMPANY.fullmatch(company) or env not in ("staging","production"):
        raise ValueError("TARGET_ID_INVALID")
    origin=record.get("canonical_origin")
    if not canonical(origin): raise ValueError("CANONICAL_ORIGIN_INVALID")
    uuid=record.get("site_uuid")
    if uuid is not None and (not isinstance(uuid,str) or not UUID.fullmatch(uuid)):
        raise ValueError("SITE_UUID_INVALID")
    if company=="tours-central" and env=="staging" and origin=="https://tours.dev.mad4b.com":
        action="CENTRAL_CONTROLLER_NOT_A_MANAGED_SITE"
    elif uuid is None:
        action="ENROLL_ORIGINAL_EXACT_SITE_PROFILE"
    elif record.get("identity_candidate") is not True:
        action="RECONCILE_ORIGINAL_ENVIRONMENT_BINDING"
    elif env=="production":
        action="PRODUCTION_ORIGINAL_SIGNED_ISSUER_REQUIRED"
    elif proof is None:
        action="RUN_ORIGINAL_SIGNED_V4_PREFLIGHT"
    elif proof.get("signed_cryptographic_proof_current") is True:
        action="SIGNED_V4_VERIFIED_OWNER_ASSIGNMENT_SEPARATE"
    elif proof.get("endpoint_http")==404:
        action="PROVISION_SIGNED_V4_SITE_PROVIDER"
    elif proof.get("trust_configured") is not True:
        action="REGISTER_INDEPENDENTLY_VERIFIED_SITE_PUBLIC_KEY"
    else:
        action="VERIFY_ORIGINAL_SIGNED_V4_CHALLENGE"
    return {"target":key,"site_uuid":uuid,"canonical_origin":origin,
            "environment":env,"next_action":action,
            "identity_verified":action=="SIGNED_V4_VERIFIED_OWNER_ASSIGNMENT_SEPARATE",
            "owner_assignment_approved_by_plan":False,
            "grant_305_issued_by_plan":False,"production_mutated":False}

def plan(fetch=wp):
    listing=fetch(["mad4b-company-env","status"])
    entries=listing.get("registrations")
    if (listing.get("contract")!=CONTRACT or listing.get("central_origin")!="https://tours.dev.mad4b.com"
        or listing.get("mutation_performed") is not False
        or listing.get("production_mutation") is not False
        or not isinstance(entries,dict) or listing.get("targets")!=len(entries)
        or type(listing.get("companies")) is not int):
        raise RuntimeError("ORIGINAL_REGISTRY_NOT_TRUSTED")
    results=[]
    for key,record in sorted(entries.items()):
        baseline=classify(key,record)
        v4=None
        if baseline["next_action"]=="RUN_ORIGINAL_SIGNED_V4_PREFLIGHT":
            company=key.split(":")[0]
            response=fetch(["mad4b-company-env","signed_v4_check","--company="+company])
            if (response.get("contract")!="mad4b.company.signed-v4-preflight.v1"
                or response.get("company_id")!=company or response.get("environment")!="staging"
                or response.get("canonical_origin")!=baseline["canonical_origin"]
                or response.get("site_uuid")!=baseline["site_uuid"]
                or response.get("remote_mutation_performed") is not False
                or response.get("production_mutation") is not False):
                raise RuntimeError("ORIGINAL_SIGNED_V4_EVIDENCE_MISMATCH")
            v4=response
        results.append(classify(key,record,v4))
    if len({r["target"].split(":")[0] for r in results}) != listing["companies"]:
        raise RuntimeError("ORIGINAL_COMPANY_COUNT_MISMATCH")
    return {"contract":OUTPUT,"company_count":listing["companies"],
            "target_count":len(results),"targets":results,
            "site_keys_created":False,"trusted_public_keys_registered":False,
            "manager_approvals_created":False,"grants_created":False,
            "production_mutated":False}

def main():
    if os.geteuid()!=0: raise RuntimeError("LOCAL_ROOT_ORIGINAL_OBSERVER_REQUIRED")
    result=plan()
    REPORT.parent.mkdir(parents=True,exist_ok=True)
    if REPORT.exists() or REPORT.is_symlink():
        st=REPORT.lstat()
        if REPORT.is_symlink() or st.st_uid!=0 or stat.S_IMODE(st.st_mode)!=0o600:
            raise RuntimeError("ORIGINAL_ROOT_REPORT_UNTRUSTED")
    fd,name=tempfile.mkstemp(prefix=".signed-v4-plan-",dir=REPORT.parent)
    try:
        with os.fdopen(fd,"w") as f:
            os.fchmod(f.fileno(),0o600)
            json.dump(result,f,sort_keys=True,separators=(",",":"))
            f.write("\n");f.flush();os.fsync(f.fileno())
        os.replace(name,REPORT)
    finally:
        if os.path.exists(name):os.unlink(name)
    print(json.dumps(result,sort_keys=True))

if __name__=="__main__":
    try:main()
    except (OSError, ValueError, RuntimeError, subprocess.SubprocessError) as e:
        print("SIGNED_V4_ALL_SITE_PLAN_DENIED:"+str(e)[:150],file=sys.stderr)
        sys.exit(2)
