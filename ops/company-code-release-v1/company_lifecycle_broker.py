#!/usr/bin/env python3
"""Read-only Company Code Release lifecycle reconciliation.

Queries only the original registered WordPress 305 & 312 statuses, and reads
the original operation handler catalog. Never grants, signs, dispatches, or
executes a release. A global legacy staging SSH install is not equivalent
to an operation-specific Company Production executor.
"""
from __future__ import annotations

import argparse
import ast
import json
from pathlib import Path
import subprocess
import importlib.util
import re
import sys

CONTRACT = "mad4b.company-code-release.lifecycle-bridge.v1"
CENTRAL_PHP = "php-wkgtkzta35wkkgouwt6ruljc"
CENTRAL_312_COMMAND = [
    "python3",
    "/opt/mad4b-company-transport/broker-v1/vps-broker-exact-authority.py",
    "status",
]
ORIGINAL_TARGET_ADAPTER = Path(
    "/opt/mad4b-company-transport/native-operator-v1/native_operator_target.py"
)
SITE_DISPATCH_EVAL = (
    'echo wp_json_encode(["dispatch_registered"=>'
    'function_exists("wp_has_ability")&&'
    'wp_has_ability("mad4b/company-code-release-dispatch"),'
    '"readiness_registered"=>function_exists("wp_has_ability")&&'
    'wp_has_ability("mad4b/company-code-release-readiness")]);'
)


def command305(company: str, environment: str) -> list[str]:
    if not re.fullmatch(r"[a-z][a-z0-9-]{2,47}", company) or not (
        isinstance(environment, str) and
        re.fullmatch(r"[a-z][a-z0-9-]{1,63}", environment)
    ):
        raise ValueError("INVALID_COMPANY_ENVIRONMENT")
    return [
        "docker", "exec", "-u", "www-data", CENTRAL_PHP, "wp", "mad4b",
        "company-native-wpcli", "status", "--company=" + company,
        "--environment=" + environment, "--path=/var/www/html",
        "--skip-themes",
    ]


def command_dispatch(container: str) -> list[str]:
    if not re.fullmatch(r"[A-Za-z0-9][A-Za-z0-9_.-]{0,127}", container):
        raise ValueError("INVALID_VERIFIED_PHP_CONTAINER")
    return [
        "docker", "exec", "-u", "www-data", container, "wp",
        "--path=/var/www/html", "--skip-themes", "eval", SITE_DISPATCH_EVAL,
    ]


def resolve_original_target(company: str, environment: str) -> dict:
    """Original root-enrolled identity resolver, no invented site aliases."""
    import stat
    original = ORIGINAL_TARGET_ADAPTER
    metadata = original.lstat()
    if (not stat.S_ISREG(metadata.st_mode) or metadata.st_uid != 0 or
            metadata.st_mode & 0o022):
        raise RuntimeError("ORIGINAL_NATIVE_TARGET_ADAPTER_UNTRUSTED")
    parent = str(original.parent)
    old = list(sys.path)
    try:
        sys.path.insert(0, parent)
        spec = importlib.util.spec_from_file_location(
            "_mad4b_lifecycle_original_target", original
        )
        if spec is None or spec.loader is None:
            raise RuntimeError("ORIGINAL_ADAPTER_NOT_LOADABLE")
        adapter = importlib.util.module_from_spec(spec)
        spec.loader.exec_module(adapter)
        result = adapter.target(company, environment)
    finally:
        sys.path[:] = old
    if not (result.get("contract") ==
            "mad4b.faore.native-operator-dynamic-target.v1" and
            result.get("read_only") is True and
            result.get("authorization_created") is False and
            result.get("native_execution_performed") is False):
        raise RuntimeError("ORIGINAL_DYNAMIC_TARGET_NOT_VERIFIED")
    return result
ORIGINAL_WORKER = Path(
    "/opt/mad4b-company-transport/native-313-independent/plugin-install-v1/"
    "original-wpcli-executor.py"
)
RELEASE_OPERATION = "company.code.release"
DYNAMIC_ROOT = Path(
    "/opt/mad4b-company-transport/native-313-independent/"
    "dynamic-operation-registry-v1/enrolled"
)
DYNAMIC_PLANS = Path("/var/lib/mad4b/dynamic-operation-registry/plans")
ACTIVE_ORIGINAL_306_WORKER = Path(
    "/opt/mad4b-company-transport/native-313-independent/claimed-job-worker.py"
)


def dynamic_status(company: str, environment: str,
                   plan_sha256: str | None = None,
                   include_central_313: bool = True) -> dict:
    """Observe installed 306/313 independently. Never creates signed authority."""
    result = {
        "dynamic_registry_enrolled": False,
        "dynamic_306_digest_registered": False,
        "dynamic_313_independent_verifier_registered": False,
        "dynamic_313_finalizer_connected": False,
        "dynamic_scope_plan_matched": False,
    }
    if not (DYNAMIC_ROOT / "index.json").is_file():
        return result
    old = list(sys.path)
    try:
        sys.path.insert(0, str(DYNAMIC_ROOT))
        import registry as registry_module
        import worker_adapter as worker_module
        index = registry_module.Registry(DYNAMIC_ROOT)
        verified = worker_module.indexed_plans(index, DYNAMIC_PLANS)
        scoped = {sha: entry for sha, entry in verified.items()
                  if entry["operation"] == RELEASE_OPERATION and
                  entry["plan"]["company"] == company and
                  entry["plan"]["environment"] == environment and
                  (plan_sha256 is None or
                   entry["plan"]["plan_sha256"] == plan_sha256)}
        result["dynamic_registry_enrolled"] = True
        result["dynamic_scope_plan_matched"] = bool(scoped)
        if scoped:
            spec = importlib.util.spec_from_file_location(
                "_mad4b_live_original_306_worker",
                ACTIVE_ORIGINAL_306_WORKER)
            if spec is None or spec.loader is None:
                raise RuntimeError("ORIGINAL_DYNAMIC_WORKER_NOT_LOADABLE")
            active = importlib.util.module_from_spec(spec)
            spec.loader.exec_module(active)
            actual = active.operation_digests()
            result["dynamic_306_digest_registered"] = all(
                actual.get(sha) == "dynamic:" + entry["operation"]
                for sha, entry in scoped.items()
            )
    finally:
        sys.path[:] = old
    if not result["dynamic_306_digest_registered"] or not include_central_313:
        # A completed Original306 readback is NOT a Central313 certificate.
        return result
    sys.path.insert(0, "/opt/mad4b-company-transport")
    try:
        from central_wpcli_runtime import central_wp
        from wpcli_dynamic_actor import resolve_actor
        central = call_json(
            central_wp()+["--user="+str(resolve_actor()),
                          "mad4b", "dynamic-313-proof", "status"]
        )
    finally:
        if sys.path[0] == "/opt/mad4b-company-transport":
            sys.path.pop(0)
    if (central.get("contract") == "mad4b.dynamic-313-wordpress.v1" and
            central.get("first_party_proof_enrolled") is True and
            central.get("operation_id") == RELEASE_OPERATION):
        result["dynamic_313_independent_verifier_registered"] = (
            central.get("independent_verifier_ready") is True
        )
        result["dynamic_313_finalizer_connected"] = (
            central.get("original_313_finalizer_connected") is True
        )
    return result


def call_json(command: list[str], *, status_timeout_seconds: int = 15) -> dict:
    """A bounded status-only read: never hold a readback waiting on WP-CLI.

    This timeout applies to reporting only, not Apply/Grant/Claim commands.
    """
    if type(status_timeout_seconds) is not int or not 1 <= status_timeout_seconds <= 30:
        raise ValueError("ORIGINAL_STATUS_TIMEOUT_INVALID")
    response = subprocess.run(command, capture_output=True, text=True,
                              check=False, timeout=status_timeout_seconds)
    if response.returncode:
        raise RuntimeError("ORIGINAL_READ_STATUS_FAILED:" + command[0])
    try:
        data = json.loads(response.stdout.strip().splitlines()[-1])
    except (ValueError, IndexError) as exc:
        raise RuntimeError("ORIGINAL_READ_STATUS_INVALID") from exc
    if not isinstance(data, dict):
        raise RuntimeError("ORIGINAL_READ_STATUS_NOT_OBJECT")
    return data


def original_handler_operations(path: Path = ORIGINAL_WORKER) -> set[str]:
    """Read named operations from original source's argparse choices; no execution."""
    if (not path.is_file() or path.is_symlink() or path.stat().st_uid != 0 or
            path.stat().st_mode & 0o022):
        raise RuntimeError("ORIGINAL_313_HANDLER_SOURCE_UNTRUSTED")
    tree = ast.parse(path.read_text())
    for node in ast.walk(tree):
        if not isinstance(node, ast.Call) or not isinstance(node.func, ast.Attribute):
            continue
        if node.func.attr != "add_argument" or not node.args:
            continue
        if not isinstance(node.args[0], ast.Constant) or node.args[0].value != "--operation":
            continue
        for keyword in node.keywords:
            if keyword.arg == "choices":
                value = ast.literal_eval(keyword.value)
                if (isinstance(value, list) and all(isinstance(item, str)
                                                    for item in value)):
                    return set(value)
    raise RuntimeError("ORIGINAL_313_HANDLER_OPERATION_CATALOG_NOT_PROVEN")


def reconcile(
    enrollment: dict, verifier: dict, dispatch: dict,
    original_operations: set[str], *,
    site_uuid: str,
    origin: str,
    company: str,
    environment: str,
    dynamic: dict | None = None,
) -> dict:
    """Pure scope-aware classification; does not infer authority from SSH."""
    valid305 = (
        enrollment.get("contract") == "mad4b.company-native-wpcli-enrollment.v1"
        and enrollment.get("state") == "ORIGINAL_305_PERSISTENT_PAIR_ENROLLED"
        and enrollment.get("company") == company
        and enrollment.get("environment") == environment
        and enrollment.get("site_uuid") == site_uuid
        and enrollment.get("host_authority_verified") is True
        and enrollment.get("full_wpcli_active") is True
        and enrollment.get("persistent_grant_present") is True
        and enrollment.get("execution_performed") is False
    )
    valid312 = (
        verifier.get("contract") == "mad4b.broker-exact-ssh-authority.v1"
        and verifier.get("verifier_registered") is True
        and verifier.get("remote_execution_authority_automatically_granted")
        is False
        and verifier.get("shell_executed") is False
    )
    supported = RELEASE_OPERATION in original_operations
    dynamic = dynamic if isinstance(dynamic, dict) else {}
    dynamic_306 = (
        dynamic.get("dynamic_registry_enrolled") is True and
        dynamic.get("dynamic_scope_plan_matched") is True and
        dynamic.get("dynamic_306_digest_registered") is True
    )
    dynamic_313_proof = (
        dynamic.get("dynamic_313_independent_verifier_registered") is True
    )
    dynamic_313_finish = (
        dynamic.get("dynamic_313_finalizer_connected") is True
    )
    dispatch_ready = dispatch.get("dispatch_registered") is True
    blockers = []
    if not valid305:
        blockers.append("ORIGINAL_305_COMPANY_SCOPE_NOT_VERIFIED")
    if not valid312:
        blockers.append("ORIGINAL_312_VERIFIER_NOT_VERIFIED")
    if not supported and not dynamic_306:
        blockers.append("ORIGINAL_313_COMPANY_CODE_RELEASE_HANDLER_NOT_REGISTERED")
    if dynamic_306 and not dynamic_313_proof:
        blockers.append("DYNAMIC_313_INDEPENDENT_PROOF_VERIFIER_NOT_REGISTERED")
    if dynamic_306 and not dynamic_313_finish:
        blockers.append("DYNAMIC_313_ORIGINAL_FINALIZER_NOT_CONNECTED")
    if not dispatch_ready and not dynamic_306:
        blockers.append("COMPANY_CODE_RELEASE_DISPATCH_ABILITY_NOT_REGISTERED")
    # WP ability presence, 305 standing access and 312 registry status do not
    # prove an individual claimed job or one-time grant.
    blockers.extend((
        "EXACT_ORIGINAL_306_CLAIM_AND_312_JOB_GRANT_NOT_VERIFIED",
        "ORIGINAL_313_INDEPENDENT_RELEASE_RECEIPT_NOT_VERIFIED",
    ))
    return {
        "contract": CONTRACT, "company": company, "environment": environment,
        "site_uuid": site_uuid, "canonical_origin": origin,
        "original_305_standing_wpcli_verified": bool(valid305),
        "original_312_verifier_registered": bool(valid312),
        "original_313_code_release_handler_registered": bool(supported),
        "company_code_release_dispatch_ability_registered": bool(dispatch_ready),
        "dynamic_registry_enrolled":
            bool(dynamic.get("dynamic_registry_enrolled") is True),
        "dynamic_306_digest_registered": bool(dynamic_306),
        "dynamic_313_independent_verifier_registered": bool(dynamic_313_proof),
        "dynamic_313_finalizer_connected": bool(dynamic_313_finish),
        "legacy_staging_ssh_installation_is_authoritative": False,
        "original_signed_job_grant_verified": False,
        "original_313_release_receipt_verified": False,
        "code_release_execution_allowed": False,
        "production_mutated": False,
        "state": ("HOLD_DYNAMIC_ORIGINAL_313_FINALIZER" if
                  dynamic_306 and not dynamic_313_finish else
                  "READY_FOR_ORIGINAL_HANDLER_ENROLLMENT")
        if valid305 and valid312 else "HOLD_ORIGINAL_305_OR_312",
        "blockers": blockers,
    }


def live_reconcile(company: str, environment: str,
                   plan_sha256: str | None = None) -> dict:
    """Read-only independent probes; a failed probe never grants authority.

    The first-party target resolver must succeed so status cannot bind to
    an invented company or container. Other readbacks may independently
    fail or time out: preserve the successful evidence and return a HOLD
    with explicit failed probe names instead of losing the whole report.
    """
    current = resolve_original_target(company, environment)
    failed = []

    def read(name: str, query, empty):
        try:
            return query()
        except (OSError, subprocess.SubprocessError, RuntimeError, ValueError,
                KeyError, TypeError, SyntaxError) as exc:
            # Never print untrusted stderr or exception payloads (may include
            # WP CLI output, paths, tokens or provider-private data).
            failed.append({"probe": name, "error_type": type(exc).__name__})
            return empty

    enrollment = read("original305_scope", lambda:
                      call_json(command305(company, environment)), {})
    verifier = read("original312_status", lambda:
                    call_json(CENTRAL_312_COMMAND), {})
    dispatch = read("wordpress_dispatch", lambda:
                    call_json(command_dispatch(current["target_container"])), {})
    legacy_ops = read("original313_legacy_handler",
                      original_handler_operations, set())
    dynamic = read("dynamic306_313_registry", lambda:
                   dynamic_status(company, environment, plan_sha256), {})
    report = reconcile(
        enrollment, verifier, dispatch, legacy_ops,
        site_uuid=current["site_uuid"], origin=current["canonical_origin"],
        company=company, environment=environment, dynamic=dynamic,
    )
    report["independent_probe_results"] = {
        name: ("FAILED" if any(x["probe"] == name for x in failed)
               else "VERIFIED") for name in (
                   "original305_scope", "original312_status",
                   "wordpress_dispatch", "original313_legacy_handler",
                   "dynamic306_313_registry")
    }
    report["failed_readback_probes"] = failed
    if failed:
        report["blockers"] = sorted(set(
            [*report["blockers"], "ORIGINAL_READBACK_PROBES_INCOMPLETE"]))
        report["state"] = "HOLD_INCOMPLETE_ORIGINAL_READBACK"
    return report


STATUS_PROBES = (
    "original305_scope", "original312_status", "wordpress_dispatch",
    "original313_legacy_handler", "dynamic306_registry",
)


def live_probe(company: str, environment: str, name: str) -> dict:
    """One bounded readback at a time; a probe is NEVER an executor grant."""
    if name not in STATUS_PROBES:
        raise ValueError("UNKNOWN_ORIGINAL_STATUS_PROBE")
    current = resolve_original_target(company, environment)
    try:
        if name == "original305_scope":
            row = call_json(command305(company, environment))
            observed = (
                row.get("contract") ==
                "mad4b.company-native-wpcli-enrollment.v1" and
                row.get("state") == "ORIGINAL_305_PERSISTENT_PAIR_ENROLLED" and
                row.get("company") == company and
                row.get("environment") == environment and
                row.get("site_uuid") == current["site_uuid"] and
                row.get("host_authority_verified") is True and
                row.get("full_wpcli_active") is True and
                row.get("persistent_grant_present") is True and
                row.get("execution_performed") is False)
        elif name == "original312_status":
            row = call_json(CENTRAL_312_COMMAND)
            observed = (row.get("contract") ==
                        "mad4b.broker-exact-ssh-authority.v1" and
                        row.get("verifier_registered") is True and
                        row.get("remote_execution_authority_automatically_granted")
                        is False and row.get("shell_executed") is False)
        elif name == "wordpress_dispatch":
            row = call_json(command_dispatch(current["target_container"]))
            observed = row.get("dispatch_registered") is True
        elif name == "original313_legacy_handler":
            observed = RELEASE_OPERATION in original_handler_operations()
        else:
            row = dynamic_status(company, environment,
                                 include_central_313=False)
            observed = (
                row.get("dynamic_registry_enrolled") is True and
                row.get("dynamic_scope_plan_matched") is True and
                row.get("dynamic_306_digest_registered") is True)
            # Central313 is intentionally *not* queried by this quick
            # local Original306 probe and is never inferred from it.
        return {
            "contract": "mad4b.company-code-release.status-probe.v1",
            "company": company, "environment": environment,
            "site_uuid": current["site_uuid"],
            "probe": name,
            "state": "READBACK_OBSERVED_NOT_RELEASE_AUTHORITY",
            "observed_true": bool(observed),
            "probe_error_type": None,
            "release_execution_allowed": False,
            "production_mutated": False,
        }
    except (OSError, subprocess.SubprocessError, RuntimeError, ValueError,
            KeyError, TypeError, SyntaxError) as exc:
        return {
            "contract": "mad4b.company-code-release.status-probe.v1",
            "company": company, "environment": environment,
            "site_uuid": current["site_uuid"],
            "probe": name,
            "state": "HOLD_ORIGINAL_STATUS_PROBE_UNAVAILABLE",
            "observed_true": False,
            "probe_error_type": type(exc).__name__,
            "release_execution_allowed": False,
            "production_mutated": False,
        }


def main() -> None:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("action", choices=("status",))
    parser.add_argument("--company", required=True)
    parser.add_argument("--environment", required=True,
                        help="Exact enrolled environment identifier")
    parser.add_argument("--probe", choices=STATUS_PROBES,
                        help="Read only one Original gate; fail closed")
    args = parser.parse_args()
    assert args.action == "status"
    report = (live_probe(args.company, args.environment, args.probe)
              if args.probe else
              live_reconcile(args.company, args.environment))
    print(json.dumps(report, ensure_ascii=False, sort_keys=True))


if __name__ == "__main__":
    main()
