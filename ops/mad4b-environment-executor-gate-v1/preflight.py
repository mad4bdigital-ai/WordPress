#!/usr/bin/env python3
"""Non-authorizing MAD4B Company+Environment binding / execution eligibility gate.

Reads an independently collected evidence snapshot; never acquires credentials,
registers grants, runs WP-CLI or marks a Broker receipt complete.
"""
from __future__ import annotations
import argparse
import json
import re
from urllib.parse import urlsplit
from pathlib import Path

CONTRACT = "mad4b.company-environment-executor-preflight.v1"
UUID = re.compile(r"^[a-f0-9]{8}-(?:[a-f0-9]{4}-){3}[a-f0-9]{12}$")
SHA = re.compile(r"^[a-f0-9]{64}$")
CODE = re.compile(r"^[a-z][a-z0-9-]{2,47}$")
FIELDS = {"contract","company","environment","site_profile","central_signed",
          "portfolio","manager","transport","native_executor"}
KINDS = {"same_host_container","remote_managed_wordpress"}

def origin(value):
    if not isinstance(value, str):
        return False
    try:
        u = urlsplit(value)
        return (u.scheme == "https" and bool(u.hostname) and not u.username
                and not u.password and u.port in (None,443)
                and u.path in ("","/") and not u.query and not u.fragment
                and value.rstrip("/") == "https://" + u.hostname)
    except ValueError:
        return False

def assess(s):
    errors = []
    def req(condition, error):
        if condition is not True:
            errors.append(error)
    def done():
        return {"contract":CONTRACT,"eligible":not errors,
                "blockers":errors,"write_performed":False,
                "grant_issued":False,"production_mutation_allowed":False}
    if not isinstance(s,dict) or set(s)!=FIELDS:
        errors.append("EXACT_EVIDENCE_SCHEMA_REQUIRED")
        return done()
    if any(not isinstance(s[x],dict) for x in
           ("site_profile","central_signed","portfolio","manager","transport","native_executor")):
        errors.append("EVIDENCE_OBJECT_REQUIRED")
        return done()
    c,env = s["company"],s["environment"]
    req(s["contract"] == CONTRACT,"CONTRACT_MISMATCH")
    req(isinstance(c,str) and bool(CODE.fullmatch(c)),"COMPANY_CODE_INVALID")
    req(env == "staging","PRODUCTION_AND_UNKNOWN_ENVIRONMENT_DENIED")
    p,proof,portfolio,manager,t,n=(s[x] for x in
       ("site_profile","central_signed","portfolio","manager","transport","native_executor"))
    site,uri=p.get("site_uuid"),p.get("canonical_origin")
    req(origin(uri) and isinstance(site,str) and bool(UUID.fullmatch(site))
        and p.get("environment")=="staging" and p.get("authority_ready") is True
        and isinstance(p.get("profile_digest"),str)
        and bool(SHA.fullmatch(p.get("profile_digest"))),"PROFILE_NOT_AUTHORITATIVE")
    req(proof.get("contract")=="mad4b.environment.signed-v4-proof.v1"
        and proof.get("company")==c and proof.get("environment")=="staging"
        and proof.get("canonical_origin")==uri
        and proof.get("site_uuid")==site
        and proof.get("profile_digest")==p.get("profile_digest")
        and proof.get("centrally_verified") is True
        and proof.get("scoped_trust_verified") is True,
        "STAGING_SIGNED_TRUST_NOT_CURRENT")
    req(portfolio.get("contract")=="mad4b.manager-company-assignment.v1"
        and portfolio.get("company")==c and portfolio.get("environment")=="staging"
        and portfolio.get("company_site_uuid")==site
        and portfolio.get("canonical_origin")==uri
        and portfolio.get("binding_verified") is True
        and portfolio.get("assignment_state")=="active"
        and portfolio.get("operations_manage") is True,
        "ENVIRONMENT_SCOPED_PORTFOLIO_ASSIGNMENT_MISSING")
    actor=manager.get("wordpress_actor_user_id")
    req(manager.get("company")==c and manager.get("environment")=="staging"
        and manager.get("site_uuid")==site and manager.get("member_active") is True
        and manager.get("eligible_actor_count")==1
        and type(actor) is int and actor>0
        and manager.get("operations_manage") is True,
        "WORDPRESS_ACTOR_DELEGATION_NOT_VERIFIED")
    kind=t.get("kind")
    req(isinstance(kind,str) and kind in KINDS,"TRANSPORT_KIND_UNSUPPORTED")
    common=(t.get("company")==c and t.get("environment")=="staging"
       and t.get("site_uuid")==site and t.get("canonical_origin")==uri
       and t.get("wordpress_actor_user_id")==actor
       and t.get("registered") is True and t.get("native_principal_verified") is True
       and t.get("production_mutation_allowed") is False)
    if kind=="same_host_container":
        req(common and isinstance(t.get("container"),str)
            and bool(re.fullmatch(r"[a-z][a-z0-9-]{3,79}",t["container"]))
            and t.get("target_container_independently_bound") is True
            and t.get("wordpress_target_readback") is True,
            "SAME_HOST_NATIVE_TARGET_NOT_ENROLLED")
    elif kind=="remote_managed_wordpress":
        req(common and t.get("contract")=="mad4b.remote-hosting-native-transport.v1"
            and t.get("remote_account_bound") is True
            and t.get("site_root_bound") is True
            and t.get("remote_executor_identity_verified") is True
            and t.get("wordpress_target_readback") is True
            and t.get("signed_dispatch_supported") is True,
            "REMOTE_HOSTING_EXECUTOR_NOT_ENROLLED")
    req(n.get("contract")=="mad4b.signed-vps-wpcli-native.v1"
        and n.get("company")==c and n.get("environment")=="staging"
        and n.get("site_uuid")==site and n.get("transport_kind")==kind
        and n.get("certified") is True
        and n.get("signed_job_verified") is True
        and n.get("independent_readback_supported") is True
        and n.get("receipt_313_verifier_certified") is True,
        "NATIVE_EXECUTOR_OR_313_NOT_CERTIFIED")
    return done()

def main():
    p=argparse.ArgumentParser(description=__doc__)
    p.add_argument("--evidence",type=Path,required=True)
    args=p.parse_args()
    result=assess(json.loads(args.evidence.read_text(encoding="utf-8")))
    print(json.dumps(result,sort_keys=True))
    raise SystemExit(0 if result["eligible"] else 4)

if __name__=="__main__":
    main()
