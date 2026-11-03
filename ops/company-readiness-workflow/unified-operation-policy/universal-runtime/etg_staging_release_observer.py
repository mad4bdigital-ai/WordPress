#!/usr/bin/env python3
"""Non-authorizing central readback of one enrolled ETG Staging release candidate.

Reuses the registered, pinned SSH identity for read-only WP-CLI diagnostics.
Never writes remote files, executes user-provided commands, provisions grants,
or promotes the local source snapshot to a commercial release.
"""
import hashlib
import json
from pathlib import Path
import subprocess

CONTRACT = "mad4b.etg-staging-central-release-observer.v1"
BASE = Path("/opt/mad4b-company-provisioning/source-releases/etg-provenance-20261031")
PLAN = BASE / "etg-exact-release-plan.json"
CANDIDATE = BASE / "mad4b-site-control-plane/MAD4B-BUILD-PROVENANCE.json"
ARCHIVE = BASE / "etg-rc118-canonical-reconciled.zip"
SITE = "https://staging.egypttourgates.com"
UUID = "d745d81f-6fc4-5c6a-99dd-d953c92137bf"
HOST = "u783534439@82.198.227.227"
TARGET_ROOT = "/home/u783534439/domains/egypttourgates.com/public_html/staging"
CREDENTIAL_DIR = "/opt/hostinger-shell-mcp/external-targets/hosting-3"
SSH = [
    "ssh", "-F", "/dev/null", "-T", "-o", "BatchMode=yes",
    "-o", "IdentitiesOnly=yes", "-o", "StrictHostKeyChecking=yes",
    "-o", "UpdateHostKeys=no",
    "-o", "UserKnownHostsFile=" + CREDENTIAL_DIR + "/known_hosts_verified",
    "-o", "ConnectTimeout=8",
    "-i", CREDENTIAL_DIR + "/id_ed25519", "-p", "65002", HOST,
]
DOMAINS = ("transport", "identity", "authority", "admin_shell", "execution_route",
           "full_authority", "agent", "package", "workflow", "central_source",
           "central_authority", "central_execution", "authentication_routes",
           "operation_admission")

def row(state, reason, evidence=None):
    return {"state": state, "reason": reason, "evidence": evidence or {}}

def sha256_file(path):
    h = hashlib.sha256()
    with path.open("rb") as f:
        for chunk in iter(lambda: f.read(131072), b""):
            h.update(chunk)
    return h.hexdigest()

def candidate_check():
    plan = json.loads(PLAN.read_text())
    pkg = json.loads(CANDIDATE.read_text())
    assert plan["contract"] == "mad4b.etg-staging-provenance-reconciliation-plan.v1"
    assert plan["environment"] == "staging" and plan["target_site"] == SITE
    assert plan["site_uuid"] == UUID and plan["production_mutation"] is False
    assert plan["authorization"] == "NOT_GRANTED_BY_THIS_PLAN"
    assert pkg["contract"] == "mad4b.build-provenance.v1"
    assert pkg["source_commit_sha"] == plan["source_commit_sha"]
    assert pkg["package_manifest_digest"] == plan["manifest_digest"]
    assert pkg["build_fingerprint"] == plan["build_fingerprint"]
    assert pkg["manifest_file_count"] == len(pkg["package_files"]) == 890
    assert sha256_file(CANDIDATE) == plan["candidate_manifest_sha256"]
    assert sha256_file(ARCHIVE) == plan["candidate_archive_sha256"]
    return plan

def wpcli_read(operation):
    if operation not in ("status", "package verify"):
        raise ValueError("unsupported_read_operation")
    command = "cd " + TARGET_ROOT + " && wp mad4b " + operation + " --skip-themes --format=json"
    p = subprocess.run(SSH + [command], capture_output=True, text=True,
                       timeout=40, check=False)
    if p.returncode:
        raise RuntimeError("registered_ssh_wpcli_readback_failed")
    data = json.loads(p.stdout)
    if not isinstance(data, dict):
        raise ValueError("wpcli_contract_invalid")
    return data

def mcp_edge_read():
    """Non-authorizing protocol read; 401 is expected until bearer auth."""
    from urllib.error import HTTPError, URLError
    from urllib.request import Request, urlopen
    body=json.dumps({"jsonrpc":"2.0","id":1,"method":"initialize",
                     "params":{"protocolVersion":"2025-11-25",
                               "capabilities":{},
                               "clientInfo":{"name":"mad4b-central-read-only-probe","version":"1.0"}}}).encode()
    request=Request(SITE+"/wp-json/mcp/mad4b-chatgpt",data=body,
                    headers={"Accept":"application/json, text/event-stream",
                             "Content-Type":"application/json",
                             "MCP-Protocol-Version":"2025-11-25"},
                    method="POST")
    try:
        with urlopen(request,timeout=14) as response:
            return response.status, response.read(2048)
    except HTTPError as exc:
        return exc.code, exc.read(2048)
    except (URLError, TimeoutError, OSError):
        return 0, b""

def inspect(scope, read=wpcli_read, edge=mcp_edge_read):
    out = {key:row("not_applicable","etg_staging_release_observer_only") for key in DOMAINS}
    out["authority"] = row("blocked_authority","release_apply_authority_not_established")
    out["operation_admission"] = row("blocked_authority","exact_release_apply_not_authorized")
    out["execution_route"] = row("awaiting_provider","authorized_release_executor_not_bound")
    out["workflow"] = row("blocked_dependency","release_apply_readback_pending")
    out["agent"] = row("awaiting_provider","broker_exact_target_enrollment_not_verified")
    out["authentication_routes"] = row("awaiting_provider","mcp_authenticated_session_not_verified")
    out["package"] = row("blocked_dependency","candidate_not_yet_applied")
    out["transport"] = row("awaiting_provider","pinned_ssh_readback_pending")
    out["identity"] = row("blocked_dependency","site_identity_readback_pending")
    out["admin_shell"] = row("awaiting_provider","diagnostic_ssh_readback_pending")
    if (scope.get("site_uuid")!=UUID or scope.get("environment")!="staging"
        or scope.get("canonical_origin")!=SITE
        or scope.get("release_observer")!=CONTRACT or scope.get("operations")!=[]):
        out["identity"] = row("review_required","registered_site_identity_scope_conflict")
        return out
    try:
        plan = candidate_check()
    except (AssertionError, ValueError, KeyError, OSError, TypeError, json.JSONDecodeError):
        out["package"] = row("review_required","candidate_archive_or_plan_invalid")
        return out
    try:
        status = read("status")
        package = read("package verify")
    except (RuntimeError, ValueError, OSError, subprocess.TimeoutExpired):
        out["transport"] = row("retry","registered_ssh_readback_unavailable")
        return out
    out["transport"] = row("verified","registered_pinned_ssh_wpcli_read_only",{"remote_write":False})
    out["admin_shell"] = row("verified","diagnostic_only_no_deployment_authority",{"read_only":True})
    profile = status.get("site_profile", {})
    if not isinstance(profile,dict) or (
        profile.get("site_uuid")!=UUID or profile.get("environment")!="staging"
        or profile.get("canonical_origin")!=SITE or
        profile.get("deployment_binding_match") is not True or
        profile.get("origin_match") is not True):
        out["identity"]=row("review_required","target_site_profile_or_binding_conflict")
        return out
    out["identity"] = row("verified","exact_etg_staging_profile_verified",
                          {"site_uuid":UUID,"read_only":True})
    # Display unresolved prerequisites from the exact release contract.
    # An unsigned plan or local Git snapshot NEVER becomes a native grant.
    from control_plane_release_preflight import preflight
    review = preflight(
        plan,
        {"site_uuid":profile.get("site_uuid"),
         "canonical_origin":profile.get("canonical_origin"),
         "environment":profile.get("environment"),
         "origin_match":profile.get("origin_match"),
         "deployment_binding_match":profile.get("deployment_binding_match")},
        {"source_commit_sha":plan["source_commit_sha"],
         "archive_sha256":plan.get("candidate_archive_sha256"),
         "manifest_digest":plan.get("manifest_digest"),
         "independent_repository_verified":False,
         "git_source_registered":False,
         "local_snapshot_only":True},
        {}, {})
    out["operation_admission"] = row(
        "blocked_authority", "exact_release_apply_not_authorized",
        {"preflight_contract":review["contract"],
         "preflight_blockers":review["blockers"],
         "native_operation_authorized":False,
         "execution_performed":False})
    http_code, http_body=edge()
    if http_code == 503:
        # Cache/provenance guard runs before MCP's OAuth bearer check.
        out["authentication_routes"]=row("blocked_dependency","mcp_runtime_bootstrap_http_503",
                                          {"http_status":503,"credential_exposed":False})
    elif http_code == 401:
        out["authentication_routes"]=row("awaiting_provider",
            "mcp_bearer_challenge_ready_authenticated_session_not_yet_verified",
            {"http_status":401,"credential_exposed":False})
    elif http_code == 0:
        out["authentication_routes"]=row("retry","mcp_edge_network_unavailable")
    else:
        out["authentication_routes"]=row("review_required","mcp_edge_unexpected_http_status",
                                          {"http_status":http_code})
    runtime=package.get("runtime",{})
    if not isinstance(runtime,dict):
        out["package"] = row("review_required","wpcli_package_response_invalid")
        return out
    evidence={"candidate_source":plan["source_commit_sha"],
              "observed_source":runtime.get("source_commit_sha"),
              "candidate_digest":plan["manifest_digest"],
              "observed_digest":runtime.get("package_manifest_digest"),
              "runtime_manifest_match":runtime.get("runtime_manifest_match"),
              "stale":runtime.get("stale"), "release_apply_authorized":False}
    if (package.get("verified") is True
        and runtime.get("runtime_manifest_match") is True
        and runtime.get("stale") is False
        and runtime.get("source_commit_sha")==plan["source_commit_sha"]
        and runtime.get("package_manifest_digest")==plan["manifest_digest"]):
        out["package"] = row("verified","exact_runtime_matches_local_candidate_not_commercial_approval",evidence)
        out["workflow"] = row("blocked_authority","authenticated_mcp_and_release_receipt_unverified")
    elif runtime.get("manifest_valid") is True and runtime.get("runtime_manifest_match") is False:
        out["package"]=row("blocked_dependency","installed_files_differ_from_declared_release",evidence)
    else:
        out["package"]=row("review_required","unexpected_runtime_or_candidate_identity",evidence)
    return out

def main():
    import sys
    if len(sys.argv)!=2 or sys.argv[1]!="status":
        print("usage: etg_staging_release_observer.py status",file=sys.stderr)
        raise SystemExit(2)
    scopes=json.loads(Path("/opt/mad4b-company-transport/universal-runtime-v1/policy.json").read_text())["scopes"]
    scope=scopes.get("egypt-tour-gates:staging",{})
    results=inspect(scope)
    print(json.dumps({"contract":CONTRACT,"target":"egypt-tour-gates:staging",
                      "domains":results,"external_write_performed":False,
                      "release_apply_authorized":False,"production_mutation":False},sort_keys=True))

if __name__=="__main__":
    main()
