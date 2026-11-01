#!/usr/bin/env python3
"""Read-only runtime bridge: live Original Core decisions + protected Host policy.

A default full-host capability PRESET is not permission. No apply, signing,
remote execution, grants, WordPress user impersonation or deployment.
"""
from __future__ import annotations
import argparse
import datetime
import json
import os
from pathlib import Path
import subprocess
import sys
from full_host_authority import CAPABILITIES, COMPANY_RE, UUID_RE, HOST_CONTRACT, InvalidAuthoritySource

ROOT = Path("/opt/mad4b-company-provisioning")
CORE = ROOT / "bin/company-unified-operation-policy.py"
REPORT = ROOT / "reports/company-unified-operation-policy-current.json"
HOST = Path("/opt/mad4b-company-transport/native-operator-v1/host-authority.json")
PROJECT_CONTRACT = "mad4b.unified-operation-policy-projection.v1"
RESULT_CONTRACT = "mad4b.full-host-authority-live-reconciliation.v1"
REQUIRED_OPERATIONS = {"plugin.install", "content.write"}


def _exact_uuid(value):
    return isinstance(value, str) and bool(UUID_RE.fullmatch(value))


def reconcile(core: dict, policies: dict) -> dict:
    """Compute requested capabilities for all discovered sites; never confer authority."""
    if (not isinstance(core, dict) or core.get("contract") != PROJECT_CONTRACT
        or core.get("status") != "ORIGINAL_CORE_AND_LEGACY_TRANSPORT_SEMANTICS_SEPARATED"
        or core.get("original_authority") != "live_mad4b_company_execution_resolver"
        or core.get("native_ssh_grant_authority") != "original_core_305_transport_grant"
        or any(core.get(x) is not False for x in ("grants_created", "operations_executed", "production_mutated"))):
        raise InvalidAuthoritySource("LIVE_ORIGINAL_CORE_PROJECTION_REQUIRED")
    sites = core.get("targets")
    if not isinstance(sites, list) or type(core.get("target_count")) is not int or len(sites) != core["target_count"]:
        raise InvalidAuthoritySource("LIVE_TARGET_COUNT_INVALID")
    if not isinstance(policies, dict) or not policies:
        raise InvalidAuthoritySource("LIVE_SIGNED_HOST_POLICY_REQUIRED")
    for host, policy in policies.items():
        if (not isinstance(host, str) or not isinstance(policy, dict)
            or policy.get("contract") != HOST_CONTRACT or policy.get("host_id") != host
            or policy.get("revoked") is not False or policy.get("principal") != "root"
            or not isinstance(policy.get("scopes"), dict)):
            raise InvalidAuthoritySource("HOST_POLICY_CONTRACT_INVALID")
    seen = set()
    result = []
    for site in sites:
        if not isinstance(site, dict):
            raise InvalidAuthoritySource("ORIGINAL_SITE_NOT_OBJECT")
        key, uuid, origin = site.get("target"), site.get("site_uuid"), site.get("canonical_origin")
        if (not isinstance(key, str) or key.count(":") != 1):
            raise InvalidAuthoritySource("SITE_SCOPE_INVALID")
        company, env = key.split(":")
        if not COMPANY_RE.fullmatch(company) or env not in ("staging", "production"):
            raise InvalidAuthoritySource("SITE_SCOPE_INVALID")
        if key in seen:
            raise InvalidAuthoritySource("DUPLICATE_SITE_SCOPE")
        seen.add(key)
        if not isinstance(origin, str) or not origin.startswith("https://") or "/" in origin[8:]:
            raise InvalidAuthoritySource("CANONICAL_ORIGIN_INVALID")
        if (site.get("legacy_operations_are_not_native_write_grants") is not True
            or site.get("grant_or_job_issued_by_projection") is not False
            or site.get("original_312_job_verified_by_projection") is not False
            or site.get("original_313_receipt_verified_by_projection") is not False
            or site.get("production_mutated") is not False):
            raise InvalidAuthoritySource("SITE_AUTHORITY_PROJECTION_CONTRACT_INVALID")
        operations = site.get("operations")
        if not isinstance(operations, dict) or set(operations) != REQUIRED_OPERATIONS:
            raise InvalidAuthoritySource("ORIGINAL_OPERATIONS_MISSING")
        if not all(isinstance(v, dict) for v in operations.values()):
            raise InvalidAuthoritySource("ORIGINAL_OPERATION_INVALID")
        hosts = []
        for host, policy in policies.items():
            scope = policy["scopes"].get(key)
            if scope is None:
                continue
            if not isinstance(scope, dict):
                raise InvalidAuthoritySource("HOST_SCOPE_INVALID")
            if not _exact_uuid(uuid) or scope.get("site_uuid") != uuid or scope.get("canonical_origin") != origin:
                raise InvalidAuthoritySource("HOST_SCOPE_IDENTITY_CONFLICT")
            hosts.append((host, policy, scope))
        if len(hosts) > 1:
            raise InvalidAuthoritySource("MULTIPLE_HOSTS_CLAIM_SAME_SITE")
        exact_site = _exact_uuid(uuid)
        host = hosts[0][0] if hosts else None
        policy = hosts[0][1] if hosts else None
        scope = hosts[0][2] if hosts else None
        host_full = bool(hosts
            and policy.get("allow_general_shell") is True
            and policy.get("allow_raw_sql") is True
            and scope.get("allow_operator") is True
            and scope.get("allow_mutation") is True
            and scope.get("allow_sql") is True
            and scope.get("allow_production_mutation") is (env == "production"))
        original_305 = site.get("original_305_native_ssh_active_pair_present") is True
        admitted = all(operations[op].get("eligible_for_native_wordpress_operation") is True
                       for op in REQUIRED_OPERATIONS)
        blockers = []
        if not exact_site or site.get("identity_registered_by_original_305") is not True:
            blockers.append("EXACT_ORIGINAL_CORE_SITE_IDENTITY_PENDING")
        if not hosts:
            blockers.append("EXACT_HOST_SCOPE_AND_BINDING_PENDING")
        elif not host_full:
            blockers.append("FULL_SHELL_SQL_AND_HOST_SCOPE_PENDING")
        if not original_305:
            blockers.append("ORIGINAL_305_ACTIVE_TRANSPORT_GRANT_PENDING")
        if not admitted:
            blockers.append("ORIGINAL_NATIVE_OPERATION_ADMISSION_PENDING")
        # 312/313 and host credential/manager issuer proofs must still be
        # independently checked when executing, not inferred from this report.
        blockers.append("INDEPENDENT_EXECUTION_AND_313_RECEIPT_REQUIRED")
        if env == "production":
            blockers.append("SEPARATE_PRODUCTION_OPERATION_APPROVAL_REQUIRED")
        result.append({
            "target": key, "site_uuid": uuid, "canonical_origin": origin,
            "verified_host_scope_id": host, "requested_capabilities": list(CAPABILITIES),
            "default_capability_preset": "full_host_authority",
            "site_identity_registered": site.get("identity_registered_by_original_305") is True,
            "full_host_scope_observed": host_full,
            "original_305_active_pair_observed": original_305,
            "native_operations_admitted_by_original_core": admitted,
            "state": "PENDING_SITE_SPECIFIC_AUTHORITY",
            "blockers": blockers,
            "grants_created": False, "operations_executed": False,
            "production_mutated": False,
        })
    if type(core.get("company_count")) is not int or core["company_count"] != len({v["target"].split(":")[0] for v in result}):
        raise InvalidAuthoritySource("LIVE_COMPANY_COUNT_INVALID")
    return {
        "contract": RESULT_CONTRACT,
        "source": "exact_original_core_readback_plus_root_protected_host_policy",
        "company_count": core["company_count"], "target_count": len(result),
        "targets": sorted(result, key=lambda item: item["target"]),
        "wildcard_grants_created": False, "grants_created": False,
        "operations_executed": False, "production_mutated": False,
    }


def root_json(path: Path) -> dict:
    st = path.lstat()
    if not path.is_file() or path.is_symlink() or st.st_uid != 0 or st.st_mode & 0o077 or st.st_size > 1_000_000:
        raise InvalidAuthoritySource("ROOT_PROTECTED_AUTHORITY_SOURCE_REQUIRED")
    return json.loads(path.read_text())


def live_once() -> dict:
    if os.geteuid() != 0:
        raise InvalidAuthoritySource("REGISTERED_LOCAL_ROOT_READBACK_REQUIRED")
    st = CORE.lstat()
    if not CORE.is_file() or CORE.is_symlink() or st.st_uid != 0 or st.st_mode & 0o022:
        raise InvalidAuthoritySource("ORIGINAL_CORE_READBACK_SCRIPT_UNTRUSTED")
    completed = subprocess.run([sys.executable, str(CORE), "once"], capture_output=True, text=True, timeout=195, check=False)
    if completed.returncode != 0:
        raise InvalidAuthoritySource("ORIGINAL_CORE_LIVE_READBACK_FAILED")
    core = root_json(REPORT)
    observed = core.get("observed_utc")
    try:
        t = datetime.datetime.fromisoformat(observed)
        age = (datetime.datetime.now(datetime.timezone.utc) - t).total_seconds()
        if t.tzinfo is None or not 0 <= age <= 300:
            raise ValueError("stale")
    except (ValueError, TypeError):
        raise InvalidAuthoritySource("ORIGINAL_CORE_READBACK_STALE")
    policy = root_json(HOST)
    return reconcile(core, {policy["host_id"]: policy})


if __name__ == "__main__":
    a = argparse.ArgumentParser()
    a.add_argument("operation", choices=("once",))
    args = a.parse_args()
    try:
        print(json.dumps(live_once(), sort_keys=True))
    except (OSError, TypeError, ValueError, subprocess.TimeoutExpired) as exc:
        print(json.dumps({"state":"DENIED","reason":str(exc),"grants_created":False}), file=sys.stderr)
        raise SystemExit(2)
