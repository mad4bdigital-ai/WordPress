#!/usr/bin/env python3
"""Dynamic MAD4B Full Host Authority planning. Never authorizes or executes.

Original Core, the signed host policy and the independent target executor are
separate authorities. This module projects requested capabilities by exact
company/environment/UUID/origin, and never writes a grant or host policy.
"""
from __future__ import annotations
import argparse
import hashlib
import json
import pathlib
import re
import sys
from urllib.parse import urlsplit

CATALOG_CONTRACT = "mad4b.original-company-site-catalog.v1"
EVIDENCE_CONTRACT = "mad4b.company-full-host-authority-evidence.v1"
HOST_CONTRACT = "mad4b.native-vps-operator.v1"
PLAN_CONTRACT = "mad4b.company-full-host-authority-plan.v1"
COMPANY_RE = re.compile(r"^[a-z][a-z0-9-]{2,47}$")
UUID_RE = re.compile(r"^[a-f0-9]{8}-(?:[a-f0-9]{4}-){3}[a-f0-9]{12}$", re.I)
HOST_RE = re.compile(r"^[A-Za-z0-9][A-Za-z0-9._-]{1,79}$")
CAPABILITIES = (
    "shell.full", "wpcli.full", "sql.full", "plugin.install",
    "content.write", "service.manage", "native-executor.full",
    "broker.dispatch", "mcp.execute",
)
REQUIRED_PROOFS = (
    "owner_approval_verified", "manager_delegation_verified", "host_proof_verified",
    "original_core_site_binding_verified", "independent_signer_verified",
)
PRODUCTION_PROOFS = ("production_owner_approval_verified", "rollback_ready")

class InvalidAuthoritySource(ValueError):
    pass


def _exact_site(site: dict) -> tuple[str, str, str, str, str]:
    if not isinstance(site, dict):
        raise InvalidAuthoritySource("SITE_MUST_BE_OBJECT")
    company, env = site.get("company"), site.get("environment")
    uuid, origin, host = site.get("site_uuid"), site.get("canonical_origin"), site.get("host_id")
    if not isinstance(company, str) or not COMPANY_RE.fullmatch(company):
        raise InvalidAuthoritySource("COMPANY_INVALID")
    if env not in ("staging", "production"):
        raise InvalidAuthoritySource("ENVIRONMENT_INVALID")
    if not isinstance(uuid, str) or not UUID_RE.fullmatch(uuid):
        raise InvalidAuthoritySource("SITE_UUID_INVALID")
    if not isinstance(host, str) or not HOST_RE.fullmatch(host):
        raise InvalidAuthoritySource("HOST_INVALID")
    if not isinstance(origin, str):
        raise InvalidAuthoritySource("ORIGIN_INVALID")
    try:
        u = urlsplit(origin)
        if (u.scheme != "https" or not u.hostname or u.username or u.password or u.port
            or u.path or u.query or u.fragment or origin != "https://" + u.netloc
            or not re.fullmatch(r"[a-zA-Z0-9.-]+", u.netloc)):
            raise InvalidAuthoritySource("ORIGIN_NOT_CANONICAL_HTTPS")
    except ValueError as ex:
        raise InvalidAuthoritySource("ORIGIN_NOT_CANONICAL_HTTPS") from ex
    return company, env, uuid, origin, host


def _policy_source(policy: dict, host: str) -> None:
    if (not isinstance(policy, dict) or policy.get("contract") != HOST_CONTRACT
        or policy.get("host_id") != host or policy.get("revoked") is not False
        or policy.get("principal") != "root" or not isinstance(policy.get("scopes"), dict)):
        raise InvalidAuthoritySource("HOST_POLICY_INVALID_OR_REVOKED")


def _attestation_is_exact(site: dict, proof: dict) -> bool:
    # A projected boolean alone is never a credential. Proof entries must be
    # supplied by an authenticated independent verifier; never by this module.
    return (isinstance(proof, dict)
        and proof.get("contract") == EVIDENCE_CONTRACT
        and proof.get("company") == site["company"]
        and proof.get("environment") == site["environment"]
        and proof.get("site_uuid") == site["site_uuid"]
        and proof.get("canonical_origin") == site["canonical_origin"]
        and proof.get("host_id") == site["host_id"]
        and type(proof.get("assignment_revision")) is int
        and proof["assignment_revision"] > 0
        and type(proof.get("wordpress_actor_user_id")) is int
        and proof["wordpress_actor_user_id"] > 0
        and all(proof.get(key) is True for key in REQUIRED_PROOFS)
        and (site["environment"] != "production"
             or all(proof.get(key) is True for key in PRODUCTION_PROOFS)))


def _full_scope_matches(site: dict, scope: dict, policy: dict) -> bool:
    if not isinstance(scope, dict):
        return False
    return (scope.get("site_uuid") == site["site_uuid"]
        and scope.get("canonical_origin") == site["canonical_origin"]
        and scope.get("allow_operator") is True
        and scope.get("allow_mutation") is True
        and scope.get("allow_sql") is True
        and scope.get("allow_production_mutation") is (site["environment"] == "production")
        and policy.get("allow_general_shell") is True
        and policy.get("allow_raw_sql") is True)


def plan(catalog: dict, host_policies: dict, evidence: dict) -> dict:
    """Plan for all discovered sites; zero writes, zero inferred grants.

    catalog is the *verified Original Core* company inventory, not untrusted
    WordPress input. host_policies are exact host_id -> signed host snapshots.
    evidence is exact scope -> verified independent authorization projections.
    """
    if not isinstance(catalog, dict) or catalog.get("contract") != CATALOG_CONTRACT:
        raise InvalidAuthoritySource("ORIGINAL_CATALOG_REQUIRED")
    sites = catalog.get("targets")
    if (not isinstance(sites, list) or not isinstance(catalog.get("target_count"), int)
        or isinstance(catalog.get("target_count"), bool) or len(sites) != catalog["target_count"]):
        raise InvalidAuthoritySource("ORIGINAL_CATALOG_TARGET_COUNT_INVALID")
    if not isinstance(host_policies, dict) or not isinstance(evidence, dict):
        raise InvalidAuthoritySource("AUTHORITY_SOURCES_MUST_BE_OBJECTS")
    seen, result = set(), []
    for site in sites:
        company, env, uuid, origin, host = _exact_site(site)
        key = company + ":" + env
        if key in seen:
            raise InvalidAuthoritySource("DUPLICATE_COMPANY_ENVIRONMENT")
        seen.add(key)
        policy = host_policies.get(host)
        _policy_source(policy, host)
        scope = policy["scopes"].get(key)
        if scope is not None and (not isinstance(scope, dict)
            or scope.get("site_uuid") != uuid or scope.get("canonical_origin") != origin):
            raise InvalidAuthoritySource("EXISTING_HOST_SCOPE_IDENTITY_CONFLICT")
        proof = evidence.get(key)
        # No evidence can be used for a different site, host or environment.
        verified = _attestation_is_exact(site, proof)
        existing_full = _full_scope_matches(site, scope, policy)
        enrolled = (isinstance(proof, dict) and verified
            and proof.get("original_305_matched_active_pair") is True
            and proof.get("native_executor_independent_readback") is True)
        # Intent/availability never transforms into authority. The original
        # verifier and Original 305 remain authoritative at execution time.
        blockers = []
        if not verified:
            blockers.append("EXACT_SIGNED_OWNER_MANAGER_AND_HOST_PROOFS_REQUIRED")
        if not existing_full:
            blockers.append("EXACT_FULL_HOST_SCOPE_NOT_ACTIVE")
        if not enrolled:
            blockers.append("ORIGINAL_305_AND_NATIVE_EXECUTOR_READBACK_REQUIRED")
        if env == "production" and not (isinstance(proof, dict)
            and all(proof.get(x) is True for x in PRODUCTION_PROOFS)):
            blockers.append("INDEPENDENT_PRODUCTION_AUTHORITY_REQUIRED")
        result.append({
            "target": key, "company": company, "environment": env,
            "site_uuid": uuid, "canonical_origin": origin, "host_id": host,
            "requested_capabilities": list(CAPABILITIES),
            "default_capability_preset": "full_host_authority",
            "proposed_exact_host_scope": {
                "site_uuid": uuid, "canonical_origin": origin,
                "allow_operator": True, "allow_mutation": True,
                "allow_sql": True,
                "allow_production_mutation": env == "production",
            },
            "requires_host_global_shell_and_sql": True,
            "exact_owner_manager_host_proofs_verified": verified,
            "existing_full_scope_verified": existing_full,
            "original_305_and_executor_readback_verified": enrolled,
            "state": "ADMISSION_EVIDENCE_COMPLETE_READ_ONLY" if not blockers else "PENDING_SITE_SPECIFIC_AUTHORITY",
            "blockers": blockers,
            "authorization_issued_by_this_plan": False,
            "host_policy_mutated": False,
            "production_mutated": False,
        })
    unknown_evidence = set(evidence) - seen
    if unknown_evidence:
        raise InvalidAuthoritySource("EVIDENCE_REFERENCES_UNKNOWN_TARGET")
    return {
        "contract": PLAN_CONTRACT,
        "intent": "full_host_authority_for_every_verified_company_environment",
        "target_count": len(result),
        "company_count": len({x["company"] for x in result}),
        "targets": sorted(result, key=lambda x:x["target"]),
        "global_wildcard_authority_created": False,
        "grants_created": False,
        "operations_executed": False,
        "production_mutated": False,
    }


def load(path: str) -> dict:
    p = pathlib.Path(path)
    if not p.is_file() or p.is_symlink() or p.stat().st_size > 1024 * 1024:
        raise InvalidAuthoritySource("INPUT_FILE_INVALID")
    return json.loads(p.read_text())


def main() -> None:
    a = argparse.ArgumentParser(description="Read-only dynamic full-host authority plan")
    a.add_argument("--catalog", required=True)
    a.add_argument("--host-policies", required=True)
    a.add_argument("--verified-evidence", required=True)
    args = a.parse_args()
    try:
        print(json.dumps(plan(load(args.catalog), load(args.host_policies), load(args.verified_evidence)), indent=2))
    except (InvalidAuthoritySource, OSError, ValueError, TypeError) as exc:
        print(json.dumps({"state":"DENIED","reason":str(exc),"grants_created":False}), file=sys.stderr)
        raise SystemExit(2)

if __name__ == "__main__":
    main()
