#!/usr/bin/env python3
"""Read-only MAD4B Company Staging binding gate; never grants or executes."""
import argparse
import json
import pathlib
import re
from urllib.parse import urlsplit

SCHEMA = "mad4b.company-staging-binding-evidence.v1"
UUID = re.compile(r"^[a-f0-9]{8}-(?:[a-f0-9]{4}-){3}[a-f0-9]{12}$")
SHA256 = re.compile(r"^[a-f0-9]{64}$")
COMPANY = re.compile(r"^[a-z][a-z0-9-]{2,47}$")
KEYS = {
    "contract", "company", "central", "site_profile", "environment_registry",
    "portfolio", "manager", "host", "target_runtime", "native_executor"
}

def canonical_origin(value):
    if not isinstance(value, str):
        return False
    try:
        u = urlsplit(value)
        return (u.scheme == "https" and bool(u.hostname) and not u.username
                and not u.password and u.port in (None, 443)
                and u.path in ("", "/") and not u.query and not u.fragment
                and value.rstrip("/") == f"https://{u.hostname}")
    except ValueError:
        return False

def evaluate(s):
    fail = []
    def deny(code, condition):
        if not condition:
            fail.append(code)

    if not isinstance(s, dict) or set(s) != KEYS:
        return {"contract": SCHEMA, "eligible": False,
                "blockers": ["EVIDENCE_SHAPE_INVALID"], "mutation_performed": False}
    company = s["company"]
    deny("COMPANY_CODE_INVALID", isinstance(company, str) and bool(COMPANY.fullmatch(company)))
    for key in KEYS - {"company", "contract"}:
        if not isinstance(s[key], dict):
            return {"contract": SCHEMA, "eligible": False,
                    "blockers": [f"{key.upper()}_EVIDENCE_MISSING"],
                    "mutation_performed": False}
    deny("EVIDENCE_CONTRACT_INVALID", s["contract"] == SCHEMA)
    central = s["central"]
    profile = s["site_profile"]
    env = s["environment_registry"]
    portfolio = s["portfolio"]
    manager = s["manager"]
    host = s["host"]
    target = s["target_runtime"]
    native = s["native_executor"]
    origin = profile.get("origin")
    uuid = profile.get("site_uuid")
    deny("STAGING_PROFILE_NOT_VERIFIED", profile.get("authority_ready") is True
         and profile.get("origin_match") is True
         and profile.get("environment") == "staging"
         and isinstance(uuid, str) and bool(UUID.fullmatch(uuid))
         and canonical_origin(origin))
    deny("ENVIRONMENT_SITE_MISMATCH", env.get("environment") == "staging"
         and env.get("origin") == origin and env.get("site_uuid") == uuid)
    deny("CENTRAL_DIRECTORY_STAGING_ORIGIN_MISMATCH",
         central.get("company") == company
         and central.get("environment") == "staging"
         and central.get("origin") == origin
         and central.get("signed_profile_trust") is True)
    deny("PORTFOLIO_VERIFIED_BINDING_MISSING",
         portfolio.get("company") == company
         and portfolio.get("host_binding_verified") is True
         and portfolio.get("company_site_uuid") == uuid
         and isinstance(portfolio.get("native_profile_uuid"), str)
         and bool(UUID.fullmatch(portfolio["native_profile_uuid"]))
         and isinstance(portfolio.get("native_profile_digest"), str)
         and bool(SHA256.fullmatch(portfolio["native_profile_digest"])))
    deny("MANAGER_DELEGATION_NOT_ACTIVE",
         manager.get("assignment_state") == "active"
         and manager.get("assignment_company") == company
         and manager.get("assigned_site_uuid") == uuid
         and manager.get("eligible_actor_count") == 1
         and type(manager.get("eligible_actor_id")) is int
         and manager["eligible_actor_id"] > 0
         and manager.get("operations_manage") is True)
    deny("SAME_HOST_STAGING_TARGET_NOT_ENROLLED",
         host.get("scope") == f"{company}:staging"
         and host.get("allow_operator") is True
         and host.get("allow_mutation") is True
         and host.get("allow_production_mutation") is not True
         and host.get("origin") == origin
         and host.get("site_uuid") == uuid
         and isinstance(host.get("target_container"), str)
         and bool(re.fullmatch(r"[a-z][a-z0-9-]{3,79}", host["target_container"]))
         and host.get("host_id") == target.get("host_id")
         and host.get("target_container") == target.get("container"))
    deny("TARGET_WORDPRESS_IDENTITY_NOT_VERIFIED",
         target.get("origin") == origin
         and target.get("environment") == "staging"
         and type(target.get("wordpress_actor_user_id")) is int
         and target.get("wordpress_actor_user_id") == manager.get("eligible_actor_id")
         and host.get("wordpress_actor_user_id") == target.get("wordpress_actor_user_id")
         and target.get("independent_readback") is True)
    deny("NATIVE_EXECUTOR_NOT_CERTIFIED",
         native.get("contract") == "mad4b.signed-vps-wpcli-native.v1"
         and native.get("certified") is True
         and native.get("site_uuid") == uuid
         and native.get("company") == company
         and native.get("independent_receipt_verifier") is True)
    return {"contract": SCHEMA, "eligible": not bool(fail),
            "blockers": fail, "mutation_performed": False,
            "production_mutation_allowed": False}

if __name__ == "__main__":
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--snapshot", type=pathlib.Path, required=True)
    args = parser.parse_args()
    print(json.dumps(evaluate(json.loads(args.snapshot.read_text(encoding="utf-8"))),
                     sort_keys=True))
