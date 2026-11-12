#!/usr/bin/env python3
"""Site-native source release using existing 306/312/313 and Code Release CAS.

No approval issuer, Job creator, signer, public 'apply' CLI, registry installer,
or direct-site override. The exact-file publishing backend is the existing
Company Code Release transactional engine with a *separate* local-site policy.
This module can execute only from an already enrolled first-party 313 provider.
"""
from __future__ import annotations

import argparse
import base64
from datetime import datetime, timedelta, timezone
import hashlib
import importlib.util
import json
from pathlib import Path
import re
import stat
import subprocess
import sys
from typing import Callable

CORE = Path(__file__).resolve().parents[1] / "company-code-release-v1" / "company_code_release.py"
POLICY_PATH = Path("/etc/mad4b/native-source-publisher-policy.v1.json")
REGISTRY_ROOT = Path("/opt/mad4b-company-transport/native-313-independent/dynamic-operation-registry-v1/enrolled")
APPROVAL_CONTRACT = "mad4b.native-source-master-approval.v1"
POLICY_CONTRACT = "mad4b.native-source-publisher-policy.v1"
SPEC_CONTRACT = "mad4b.native-source-publisher-request.v1"
OPERATION = "source.code.release"
TARGET_SCOPE = "tours-central:staging"
EXPECTED_ORIGIN = "https://tours.dev.mad4b.com"
EXPECTED_UUID = "e5e64bca-ed62-48d2-b2b3-dff693cd702a"
TARGET_PATH = "wp-content/plugins/mad4b-commerce/modules/presentation/assets/unified-date-v0.4.3.js"
HEX64 = re.compile(r"^[a-f0-9]{64}$")
GIT_SHA = re.compile(r"^[a-f0-9]{40}$")
ID = re.compile(r"^[a-f0-9]{8}-[a-f0-9]{4}-[1-8][a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$")

spec = importlib.util.spec_from_file_location("mad4b_native_source_exact_code_core", CORE)
if spec is None or spec.loader is None:
    raise RuntimeError("TRUSTED_SOURCE_RELEASE_CORE_MISSING")
core = importlib.util.module_from_spec(spec)
spec.loader.exec_module(core)


class Denied(core.Denied):
    pass


def sha(blob: bytes) -> str:
    return hashlib.sha256(blob).hexdigest()


def canonical(value: dict) -> bytes:
    return json.dumps(value, separators=(",", ":"), ensure_ascii=False,
                      sort_keys=True).encode("utf-8")


def checked_policy(policy_path: Path = POLICY_PATH) -> dict:
    # The only executable policy source is a root-owned file under /etc/mad4b.
    if policy_path != POLICY_PATH:
        raise Denied("SOURCE_POLICY_PATH_NOT_PINNED")
    core.no_link(policy_path)
    if not policy_path.is_file():
        raise Denied("SOURCE_PUBLISHER_NOT_ENROLLED")
    st = policy_path.stat()
    if st.st_uid != 0 or stat.S_IMODE(st.st_mode) & 0o077:
        raise Denied("SOURCE_POLICY_NOT_ROOT_PRIVATE")
    obj = json.loads(core.safe_regular(policy_path, max_size=15_000))
    validate_policy(obj)
    return obj


def validate_policy(policy: dict) -> None:
    if not isinstance(policy, dict) or policy.get("contract") != POLICY_CONTRACT:
        raise Denied("SOURCE_POLICY_CONTRACT_INVALID")
    if policy.get("enabled") is not True:
        raise Denied("SOURCE_PUBLISHER_DISABLED")
    if policy.get("operation") != OPERATION:
        raise Denied("SOURCE_OPERATION_MISMATCH")
    targets = policy.get("targets")
    if not isinstance(targets, dict) or set(targets) != {TARGET_SCOPE}:
        raise Denied("SOURCE_TARGETS_NOT_SINGLE_SITE")
    target = targets[TARGET_SCOPE]
    if (target.get("origin") != EXPECTED_ORIGIN or
            target.get("site_uuid") != EXPECTED_UUID or
            target.get("allowed_targets") != [TARGET_PATH] or
            target.get("release_catalog") is not None):
        raise Denied("SOURCE_SITE_OR_FILE_SCOPE_MISMATCH")
    for key in ("root", "container"):
        if not isinstance(target.get(key), str) or not target[key]:
            raise Denied("SOURCE_TARGET_BINDING_MISSING")
    for name in ("source_git_root", "runtime_registry", "receipt_dir"):
        val = policy.get(name)
        if not isinstance(val, str) or not val.startswith("/"):
            raise Denied("SOURCE_POLICY_ABSOLUTE_PATH_MISSING")
    keys=[]
    for key in ("broker_public_key_b64", "master_approval_public_key_b64"):
        val = policy.get(key)
        try:
            raw = base64.b64decode(val, validate=True)
            if len(raw) != 32:
                raise ValueError("wrong key length")
            keys.append(raw)
        except (ValueError, TypeError):
            raise Denied("SOURCE_TRUST_ANCHOR_MISSING:" + key)
    if keys[0] == keys[1]:
        raise Denied("SOURCE_APPROVER_AND_BROKER_MUST_HAVE_DISTINCT_KEYS")


def company_policy(policy: dict) -> dict:
    """Reuse vetted CAS code. Does not mutate global Company policy."""
    validate_policy(policy)
    return {
        "contract": core.POLICY_CONTRACT,
        "targets": policy["targets"],
        "source_git_root": policy["source_git_root"],
        "runtime_registry": policy["runtime_registry"],
        "receipt_dir": policy["receipt_dir"],
        "broker_public_key_b64": policy["broker_public_key_b64"],
    }


def make_plan(request: dict, policy: dict) -> dict:
    if not isinstance(request, dict) or request.get("contract") != SPEC_CONTRACT:
        raise Denied("SOURCE_RELEASE_REQUEST_INVALID")
    if set(request) != {"contract", "scope", "site_uuid", "origin",
                        "git_commit", "file", "expected_before_sha256"}:
        raise Denied("SOURCE_RELEASE_REQUEST_FIELDS_INVALID")
    if (request.get("scope") != TARGET_SCOPE or request.get("site_uuid") != EXPECTED_UUID
            or request.get("origin") != EXPECTED_ORIGIN
            or request.get("file") != TARGET_PATH
            or not GIT_SHA.fullmatch(str(request.get("git_commit")))
            or not HEX64.fullmatch(str(request.get("expected_before_sha256")))):
        raise Denied("SOURCE_RELEASE_REQUEST_SCOPE_INVALID")
    p = company_policy(policy)
    spec_data = {
        "contract": core.SPEC_CONTRACT, "company": "tours-central",
        "environment": "staging", "site_uuid": EXPECTED_UUID,
        "git_commit": request["git_commit"],
        "files": [{"source": TARGET_PATH, "target": TARGET_PATH,
                   "expected_before_sha256": request["expected_before_sha256"]}],
        "acceptance_checks": ["wp_boot"],
    }
    try:
        plan = core.make_plan(spec_data, p)
    except core.Denied as exc:
        raise Denied(str(exc)) from exc
    if plan["changed_count"] != 1 or len(plan["files"]) != 1:
        raise Denied("SOURCE_RELEASE_MUST_CHANGE_ONE_FILE")
    return plan


def check_plan(plan: dict, policy: dict, *, after: bool = False) -> None:
    c = company_policy(policy)
    if (plan.get("company") != "tours-central" or
        plan.get("environment") != "staging" or
        plan.get("site_uuid") != EXPECTED_UUID or
        plan.get("origin") != EXPECTED_ORIGIN or
        len(plan.get("files", [])) != 1 or
        plan["files"][0].get("target") != TARGET_PATH):
        raise Denied("SOURCE_PUBLISHER_WRONG_PLAN_SCOPE")
    try:
        core.confirm_plan(plan, c, expect_preimage=not after)
    except core.Denied as exc:
        raise Denied(str(exc)) from exc


def approval_message(approval: dict) -> bytes:
    return (APPROVAL_CONTRACT + "\n").encode() + canonical(
        {k: v for k, v in approval.items() if k != "signature"})


def verify_master_approval(plan: dict, policy: dict, approval: dict,
                           *, now: datetime | None = None) -> None:
    """No self-approval: signed Master Admin attestation from independent issuer."""
    from cryptography.hazmat.primitives.asymmetric.ed25519 import Ed25519PublicKey
    if not isinstance(approval, dict) or set(approval) != {
        "contract", "operation", "plan_sha256", "site_uuid", "origin",
        "scope", "ticket_id", "ticket_payload_sha256",
        "approver_role", "ticket_state", "expires_at", "signature",
    }:
        raise Denied("SOURCE_MASTER_APPROVAL_SHAPE_INVALID")
    if (approval["contract"] != APPROVAL_CONTRACT or
        approval["operation"] != OPERATION or
        approval["plan_sha256"] != plan["plan_sha256"] or
        approval["site_uuid"] != EXPECTED_UUID or
        approval["origin"] != EXPECTED_ORIGIN or
        approval["scope"] != TARGET_SCOPE or
        approval["approver_role"] != "master_admin" or
        approval["ticket_state"] != "approved" or
        not ID.fullmatch(str(approval["ticket_id"])) or
        not HEX64.fullmatch(str(approval["ticket_payload_sha256"]))):
        raise Denied("SOURCE_MASTER_APPROVAL_SCOPE_INVALID")
    try:
        expiry = datetime.fromisoformat(approval["expires_at"])
        clock = now or datetime.now(timezone.utc)
        if expiry.tzinfo is None or not clock < expiry <= clock + timedelta(hours=1):
            raise ValueError("expired or overbroad")
        key = Ed25519PublicKey.from_public_bytes(
            base64.b64decode(policy["master_approval_public_key_b64"], validate=True))
        key.verify(base64.b64decode(approval["signature"], validate=True),
                   approval_message(approval))
    except Exception as exc:
        raise Denied("SOURCE_MASTER_APPROVAL_SIGNATURE_OR_EXPIRY_INVALID") from exc


def fresh_master_ticket_readback(plan: dict, policy: dict, approval: dict) -> None:
    # A signed attestation alone must not outlive a Central ticket revocation.
    # Revalidate the actual approved, exact-operation ticket in live WP just
    # before any file write. No user-provided approval status is trusted.
    company_policy(policy)
    container=policy["targets"][TARGET_SCOPE]["container"]
    ticket=approval["ticket_id"]
    if not ID.fullmatch(ticket):
        raise Denied("SOURCE_MASTER_TICKET_ID_INVALID")
    code=(
      "$t=class_exists('MAD4B_SCP_Approval_Tickets')"
      "?MAD4B_SCP_Approval_Tickets::get('"+ticket+"'):null;"
      "if(!is_array($t)){echo '{}';return;}"
      "echo wp_json_encode(array_intersect_key($t,array_flip("
      "['ticket_id','status','ticket_class','server_id','ability_name',"
      "'provider','payload_sha256','approved_by','agent_id','expires_at',"
      "'site_uuid'])));"
    )
    raw=core.run(["docker","exec","-u","www-data",container,
          "wp","--path=/var/www/html","--skip-themes","eval",code],timeout=45)
    rows=[json.loads(line) for line in raw.decode().splitlines()
          if line.startswith("{")]
    if len(rows)!=1:
        raise Denied("SOURCE_CENTRAL_APPROVAL_READBACK_MISSING")
    row=rows[0]
    if (row.get("ticket_id")!=ticket or row.get("status")!="approved" or
        row.get("ticket_class")!="mutation" or
        row.get("server_id")!="mad4b-write" or
        row.get("ability_name")!="mad4b/native-source-release" or
        row.get("provider")!="mad4b-source-release" or
        row.get("payload_sha256")!=approval["ticket_payload_sha256"] or
        row.get("site_uuid")!=EXPECTED_UUID or
        type(row.get("approved_by")) is not int or row["approved_by"]<=0 or
        type(row.get("agent_id")) is not int or row["agent_id"]<=0):
        raise Denied("SOURCE_MASTER_TICKET_CHANGED_OR_REVOKED")
    try:
        end=datetime.strptime(row["expires_at"],"%Y-%m-%d %H:%M:%S").replace(tzinfo=timezone.utc)
        if end<=datetime.now(timezone.utc):
            raise ValueError("approval expired")
    except (ValueError,TypeError,KeyError) as exc:
        raise Denied("SOURCE_MASTER_TICKET_EXPIRED") from exc


def native_runtime_binding(plan: dict, policy: dict) -> None:
    """Only native container+WordPress site binding; not Remote Host Authority."""
    c = company_policy(policy)
    binding = c["targets"][TARGET_SCOPE]
    root = Path(binding["root"])
    core.no_link(root)
    if not root.is_dir() or str(root.resolve()) != plan["target_root"]:
        raise Denied("SOURCE_LIVE_ROOT_CHANGED")
    paths = json.loads(core.run(["docker", "inspect", binding["container"],
                    "--format", "{{json .Mounts}}"],timeout=25))
    mounts = [x for x in paths if x.get("Type")=="bind" and
              x.get("Destination")=="/var/www/html" and x.get("RW") is True]
    if len(mounts) != 1 or Path(mounts[0]["Source"]).resolve() != root.resolve():
        raise Denied("SOURCE_CONTAINER_BIND_MISMATCH")
    # Source identity is verified by WordPress itself, not by a caller-provided
    # site URL or a forged HTTPS Host header.
    code = (
        'echo wp_json_encode(["origin"=>rtrim(home_url(),"/"),'
        '"is_multisite"=>is_multisite()]);'
    )
    raw = core.run(["docker", "exec", "-u", "www-data", binding["container"],
                    "wp", "--path=/var/www/html", "--skip-themes",
                    "eval", code],timeout=45)
    observations = [json.loads(line) for line in raw.decode().splitlines()
                    if line.startswith("{")]
    if len(observations)!=1 or observations[0].get("origin")!=EXPECTED_ORIGIN or \
            observations[0].get("is_multisite") is not False:
        raise Denied("SOURCE_WP_ORIGIN_DRIFT")
    # Original root-owned FAORE identity and target-registry readback remain
    # the site UUID authority. Local source does not need host v2 remote grant.
    host=Path("/opt/mad4b-company-transport/native-operator-v1/host-authority.json")
    core.no_link(host)
    if host.stat().st_uid != 0 or host.stat().st_mode & 0o022:
        raise Denied("SOURCE_ORIGINAL_NATIVE_REGISTRY_UNTRUSTED")
    entry=json.loads(host.read_text())["scopes"].get(TARGET_SCOPE,{})
    if (entry.get("site_uuid")!=EXPECTED_UUID or
        entry.get("canonical_origin")!=EXPECTED_ORIGIN or
        entry.get("site_environment")!="staging"):
        raise Denied("SOURCE_ORIGINAL_NATIVE_TARGET_DRIFT")


def enrolled_original_provider(operation: str = OPERATION) -> dict:
    """Requires first-party, SHA-pinned live 306 registry. No local fake registry."""
    if operation != OPERATION:
        raise Denied("SOURCE_OPERATION_UNSUPPORTED")
    if not REGISTRY_ROOT.is_dir():
        raise Denied("SOURCE_PROVIDER_NOT_ENROLLED")
    module=REGISTRY_ROOT/"registry.py"
    spec=importlib.util.spec_from_file_location("_mad4b_native_source_live_registry",module)
    if spec is None or spec.loader is None:
        raise Denied("SOURCE_PROVIDER_REGISTRY_UNAVAILABLE")
    loaded=importlib.util.module_from_spec(spec)
    spec.loader.exec_module(loaded)
    try:
        return loaded.Registry(REGISTRY_ROOT).resolve(OPERATION)
    except (OSError, ValueError, RuntimeError) as exc:
        raise Denied("SOURCE_PROVIDER_NOT_ENROLLED") from exc


def admission(plan: dict, policy: dict, approval: dict, broker_grant: dict) -> None:
    # This must not be used as a direct 'apply' endpoint. The executing
    # handler first checks the *original* claimed Job and provider enrollment.
    enrolled_original_provider()
    check_plan(plan,policy)
    verify_master_approval(plan,policy,approval)
    core.verify_grant(plan,company_policy(policy),broker_grant,"apply")
    native_runtime_binding(plan,policy)
    fresh_master_ticket_readback(plan,policy,approval)


def reserve_master_ticket_once(plan: dict, policy: dict,
                               approval: dict, receipt_root: Path) -> None:
    # A replay of a newly signed Broker Grant must not reuse the same Master
    # ticket, even if the previous host result was uncertain or rolled back.
    import os
    core.assert_pinned_receipt_directory(company_policy(policy),receipt_root)
    ticket=approval["ticket_id"]
    if not ID.fullmatch(ticket):
        raise Denied("SOURCE_MASTER_TICKET_ID_INVALID")
    marker=receipt_root/("master-"+ticket+".reserved")
    core.no_link(marker)
    try:
        fd=os.open(marker, os.O_CREAT|os.O_EXCL|os.O_WRONLY|
                   getattr(os,"O_NOFOLLOW",0),0o600)
    except FileExistsError as exc:
        raise Denied("SOURCE_MASTER_APPROVAL_ALREADY_CLAIMED") from exc
    with os.fdopen(fd,"wb") as stream:
        stream.write((plan["plan_sha256"]+"\n").encode())
        stream.flush()
        os.fsync(stream.fileno())


def source_acceptance(plan: dict) -> None:
    core.php_lint(plan)
    script=plan["files"][0]
    if script["target"].endswith(".js"):
        core.run(["node","--check",str(Path(plan["target_root"])/script["target"])],
                 timeout=20)


def executor(plan: dict, policy: dict, approval: dict,
             broker_grant: dict, receipt_root: Path) -> dict:
    """Private first-party handler entry, not CLI. Native CAS+rollback backend.

    The original 306/313 worker must issue the independently signed receipt
    after verifying this result. The following receipt is NEVER that proof.
    """
    admission(plan,policy,approval,broker_grant)
    reserve_master_ticket_once(plan,policy,approval,receipt_root)
    outcome = core.apply_release(
        plan,company_policy(policy),broker_grant,receipt_root,
        runtime_check=native_runtime_binding,validate_code=source_acceptance)
    outcome["original_313_receipt"] = False
    outcome["master_approval_verified_at_admission"] = True
    outcome["native_local_source_provider"] = True
    return outcome


def main() -> int:
    parser=argparse.ArgumentParser(description="Source-only site-native publisher")
    parser.add_argument("action",choices=("status","plan","verify"))
    parser.add_argument("--request",type=Path)
    parser.add_argument("--plan",type=Path)
    args=parser.parse_args()
    if args.action=="status":
        try:
            checked_policy()
            enrolled_original_provider()
            state="ENROLLED_PENDING_MASTER_APPROVAL_AND_SIGNED_ORIGINAL_JOB"
        except Exception as exc:
            state="HOLD__"+type(exc).__name__+":"+str(exc)[:100]
        print(json.dumps({"contract":POLICY_CONTRACT,"status":state,
                          "publishing_performed":False}))
        return 0
    policy=checked_policy()
    if args.action=="plan":
        if args.request is None:
            raise Denied("SOURCE_RELEASE_REQUEST_REQUIRED")
        data=json.loads(args.request.read_text())
        print(json.dumps(make_plan(data,policy),sort_keys=True,indent=2))
    else:
        if args.plan is None:
            raise Denied("SOURCE_PLAN_REQUIRED")
        plan=json.loads(args.plan.read_text())
        check_plan(plan,policy)
        print(json.dumps({"state":"EXACT_LOCAL_SOURCE_PLAN_RECHECK_PASS",
                          "plan_sha256":plan["plan_sha256"],
                          "publication_authorized":False}))
    return 0


if __name__=="__main__":
    try:
        raise SystemExit(main())
    except Exception as error:
        print(json.dumps({"state":"HOLD","reason":str(error)[:220],
                          "publishing_performed":False}))
        raise SystemExit(2)
