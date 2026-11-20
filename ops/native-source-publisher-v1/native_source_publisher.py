#!/usr/bin/env python3
"""Dynamically enrolled, provider-neutral native WordPress source publisher.

No operation, site, plugin, target file or environment appears in an executable
allowlist. Authority comes ONLY from a SHA-pinned first-party Original306/313
manifest and a root-owned per-site policy, followed by independent Master
Admin and Broker signatures. There is no direct CLI apply or key issuer.
"""
from __future__ import annotations
import argparse
import base64
from datetime import datetime, timedelta, timezone
import hashlib
import importlib.util
import json
import os
from pathlib import Path
import re
import stat

import native_host_source_ownership as host_source

CORE = Path(__file__).resolve().parents[1] / "company-code-release-v1" / "company_code_release.py"
POLICY_PATH = Path("/etc/mad4b/native-source-publisher-policy.v2.json")
REGISTRY_ROOT = Path("/opt/mad4b-company-transport/native-313-independent/dynamic-operation-registry-v1/enrolled")
POLICY_CONTRACT = "mad4b.native-source-publisher-policy.v2"
REQUEST_CONTRACT = "mad4b.native-source-publisher-request.v2"
APPROVAL_CONTRACT = "mad4b.native-source-master-approval.v2"
FAMILY = "mad4b.native-source-release.v2"
BINDING_KEY = "source_binding"
BINDING_CONTRACT = "mad4b.native-source-operation-binding.v2"
HEX64 = re.compile(r"^[a-f0-9]{64}$")
GIT_SHA = re.compile(r"^[a-f0-9]{40}$")
OP_ID = re.compile(r"^[a-z][a-z0-9._-]{2,90}$")
ABILITY = re.compile(r"^[a-z0-9][a-z0-9._-]*/[a-z0-9][a-z0-9._-]*$")
ID = re.compile(r"^[a-f0-9]{8}-[a-f0-9]{4}-[1-8][a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$")
SCOPE = re.compile(r"^[a-z][a-z0-9-]{1,63}:[a-z][a-z0-9-]{1,63}$")
HANDLER_PATH = "native-source-publisher-v1/original_313_host_handler.py"
VERIFIER_PATH = "native-source-publisher-v1/original_313_independent_readback.py"

spec = importlib.util.spec_from_file_location("_mad4b_native_release_core", CORE)
if spec is None or spec.loader is None:
    raise RuntimeError("RELEASE_CORE_NOT_FOUND")
core = importlib.util.module_from_spec(spec)
spec.loader.exec_module(core)


class Denied(core.Denied):
    pass


def sha(blob: bytes) -> str:
    return hashlib.sha256(blob).hexdigest()


def canonical(obj: dict) -> bytes:
    return json.dumps(obj, ensure_ascii=False, sort_keys=True, separators=(",", ":")).encode()


def validate_policy(policy: dict) -> None:
    if (not isinstance(policy, dict) or policy.get("contract") != POLICY_CONTRACT or
        policy.get("enabled") is not True or
        not isinstance(policy.get("targets"), dict) or
        not 1 <= len(policy["targets"]) <= 100):
        raise Denied("SOURCE_POLICY_NOT_ENROLLED")
    for name in ("source_git_root", "runtime_registry", "receipt_dir"):
        val=policy.get(name)
        if not isinstance(val,str) or not val.startswith("/"):
            raise Denied("SOURCE_POLICY_PATH_MISSING:"+name)
    keys=[]
    for name in ("broker_public_key_b64","master_approval_public_key_b64"):
        try:
            val=base64.b64decode(policy[name],validate=True)
            if len(val)!=32:
                raise ValueError("not Ed25519")
            keys.append(val)
        except (ValueError,KeyError,TypeError) as exc:
            raise Denied("SOURCE_INDEPENDENT_PUBLIC_KEY_MISSING:"+name) from exc
    if keys[0]==keys[1]:
        raise Denied("SOURCE_MASTER_AND_BROKER_KEYS_NOT_DISTINCT")
    for scope,target in policy["targets"].items():
        if not isinstance(scope,str) or not SCOPE.fullmatch(scope) or not isinstance(target,dict):
            raise Denied("SOURCE_TARGET_SCOPE_INVALID")
        for item in ("site_uuid","origin","root","container"):
            if not isinstance(target.get(item),str) or not target[item]:
                raise Denied("SOURCE_TARGET_BINDING_MISSING:"+item)
        if not ID.fullmatch(target["site_uuid"]) or not target["origin"].startswith("https://"):
            raise Denied("SOURCE_TARGET_IDENTITY_INVALID")
        if not target["root"].startswith("/") or not re.fullmatch(r"[A-Za-z0-9][A-Za-z0-9_.-]{0,127}",target["container"]):
            # Docker names are opaque, not process command arguments.
            raise Denied("SOURCE_TARGET_CONTAINER_OR_ROOT_INVALID")
        allow=target.get("allowed_targets")
        if not isinstance(allow,list) or not 1<=len(allow)<=200 or len(set(allow))!=len(allow):
            raise Denied("SOURCE_TARGET_ALLOWLIST_INVALID")
        for file in allow:
            try:
                normalized=core.exact_relative(file)
            except core.Denied as exc:
                raise Denied("SOURCE_TARGET_FILE_UNSAFE") from exc
            if not core.permitted_target(normalized):
                raise Denied("SOURCE_TARGET_FILE_UNSAFE")


def checked_policy(path: Path = POLICY_PATH) -> dict:
    if path != POLICY_PATH:
        raise Denied("SOURCE_POLICY_PATH_NOT_ROOT_PINNED")
    core.no_link(path)
    if not path.is_file():
        raise Denied("SOURCE_PUBLISHER_NOT_ENROLLED")
    st=path.stat()
    if st.st_uid!=0 or stat.S_IMODE(st.st_mode)&0o077:
        raise Denied("SOURCE_POLICY_NOT_ROOT_PRIVATE")
    policy=json.loads(core.safe_regular(path,max_size=100_000))
    validate_policy(policy)
    return policy


def company_policy(policy: dict) -> dict:
    validate_policy(policy)
    return {
        "contract":core.POLICY_CONTRACT,
        "targets":policy["targets"],
        "source_git_root":policy["source_git_root"],
        "runtime_registry":policy["runtime_registry"],
        "receipt_dir":policy["receipt_dir"],
        "broker_public_key_b64":policy["broker_public_key_b64"],
    }


def validate_manifest(manifest: dict) -> None:
    if (not isinstance(manifest,dict) or
        manifest.get("source_family")!=FAMILY or
        manifest.get("plan_contract")!=core.PLAN_CONTRACT or
        manifest.get("handler",{}).get("path")!=HANDLER_PATH or
        manifest.get("verifier",{}).get("path")!=VERIFIER_PATH):
        raise Denied("SOURCE_MANIFEST_NOT_NATIVE_RELEASE_FAMILY")
    if (not isinstance(manifest.get("operation_id"),str) or
        not OP_ID.fullmatch(manifest["operation_id"]) or
        not isinstance(manifest.get("provider"),str) or
        not OP_ID.fullmatch(manifest["provider"]) or
        not isinstance(manifest.get("approval_ability"),str) or
        not ABILITY.fullmatch(manifest["approval_ability"])):
        raise Denied("SOURCE_MANIFEST_OPERATION_PROVIDER_ABILITY_INVALID")
    dependency_paths={x.get("path") for x in manifest.get("dependencies",[]) if isinstance(x,dict)}
    if not {"native-source-publisher-v1/native_source_publisher.py",
            "native-source-publisher-v1/native_host_source_ownership.py",
            "native-source-publisher-v1/native_host_source_transaction.py",
            "native-source-publisher-v1/native_host_source_readback.py",
            "native-source-publisher-v1/native_host_source_final_receipt.py",
            "native-source-publisher-v1/host_source_activation_doctor.py",
            "company-code-release-v1/company_code_release.py"}<=dependency_paths:
        raise Denied("SOURCE_MANIFEST_DEPENDENCIES_UNPINNED")
    command=manifest.get("command",{})
    if (command.get("bound_operation_id")!=manifest["operation_id"] or
        command.get("mode")!="plan_sha256" or
        command.get("format")!="wpcli.argv.json.v1" or
        command.get("flag")!="--plan_sha256=" or
        not isinstance(command.get("prefix"),list) or
        not 1<=len(command["prefix"])<=6 or
        any(not isinstance(x,str) or not OP_ID.fullmatch(x) for x in command["prefix"])):
        raise Denied("SOURCE_MANIFEST_COMMAND_NOT_PINNED")


def original_registry():
    module=REGISTRY_ROOT/"registry.py"
    if not module.is_file() or module.is_symlink():
        raise Denied("SOURCE_ORIGINAL_REGISTRY_NOT_INSTALLED")
    spec=importlib.util.spec_from_file_location("_mad4b_source_original_registry",module)
    if spec is None or spec.loader is None:
        raise Denied("SOURCE_ORIGINAL_REGISTRY_MODULE_INVALID")
    loaded=importlib.util.module_from_spec(spec)
    spec.loader.exec_module(loaded)
    return loaded.Registry(REGISTRY_ROOT)


def enrolled_original_provider(operation_id: str, registry=None) -> dict:
    if not isinstance(operation_id,str) or not OP_ID.fullmatch(operation_id):
        raise Denied("SOURCE_OPERATION_ID_INVALID")
    try:
        record=(registry or original_registry()).resolve(operation_id)
        validate_manifest(record)
        return record
    except (OSError,KeyError,ValueError,RuntimeError) as exc:
        raise Denied("SOURCE_PROVIDER_NOT_ENROLLED") from exc


def discover_source_operations(registry=None) -> list[dict]:
    """Return only verified first-party enrolled manifests of this family."""
    installed=registry or original_registry()
    result=[]
    for operation_id in sorted(installed.manifests):
        try:
            manifest=enrolled_original_provider(operation_id,installed)
        except Denied:
            continue
        result.append({"operation_id":operation_id,"provider":manifest["provider"],
                       "approval_ability":manifest["approval_ability"],
                       "manifest_version":manifest["version"]})
    return result


HOST_SOURCE_FAMILY = "mad4b.native-host-source-release.v1"


def discover_host_resource_operations(registry=None) -> list[dict]:
    """Discover only first-party SHA-pinned *Host* operations, never WPCLI.

    A registered manifest and signer are prerequisites for recognition; they
    are not a signed Job/Grant, Original312 admission or 313 execution receipt.
    """
    index=registry if registry is not None else original_registry()
    if not isinstance(getattr(index,"host_resource_312_public_key",None),bytes):
        return []
    result=[]
    for op,manifest in sorted(index.manifests.items()):
        if (not isinstance(manifest,dict) or
            manifest.get("source_family")!=HOST_SOURCE_FAMILY):
            continue
        command=manifest.get("command",{})
        if (manifest.get("operation_id")!=op or
            manifest.get("target_scope")!="host" or
            manifest.get("resource_kind")!="host_root_file" or
            manifest.get("job_type")!="signed_vps_host_source" or
            command.get("format")!="host.source.ref.v1" or
            command.get("bound_operation_id")!=op):
            raise Denied("HOST_SOURCE_ENROLLED_RESOURCE_DRIFT")
        deps={x.get("path") for x in manifest.get("dependencies",[])
              if isinstance(x,dict)}
        required={"dynamic-operation-registry-v1/host_admission.py",
                  "native-source-publisher-v1/native_host_source_transaction.py",
                  "native-source-publisher-v1/native_host_source_readback.py",
                  "native-source-publisher-v1/native_host_source_final_receipt.py",
                  "native-source-publisher-v1/native_host_source_ownership.py"}
        if not required <= deps:
            raise Denied("HOST_SOURCE_ENROLLED_DEPS_NOT_PINNED")
        result.append({"operation_id":op,
                       "provider":manifest["provider"],
                       "target_scope":"host",
                       "format":"host.source.ref.v1",
                       "execution_authorized":False})
    return result


def verify_original_host312_proof(plan: dict, original_job: dict,
                                  original_pending: dict, signed_host312: dict) -> dict:
    """Enforce REAL root-pinned original Host306/312 evidence, never callbacks.

    Non-authorizing: this verifies only Host312; the installed first-party
    release handler must *separately* verify Master Admin + Broker grant.
    It cannot issue any authority or discover a signer from the caller.
    """
    import sys
    index=original_registry()
    matches=[(operation,manifest) for operation,manifest in index.manifests.items()
             if manifest.get("source_family")==HOST_SOURCE_FAMILY and
                manifest.get("plan_contract")==plan.get("contract")]
    if len(matches)!=1:
        raise Denied("HOST312_UNIQUE_ORIGINAL_PROVIDER_NOT_ENROLLED")
    operation,manifest=matches[0]
    if not isinstance(getattr(index,"host_resource_312_public_key",None),bytes):
        raise Denied("HOST312_INDEPENDENT_ROOT_SIGNER_NOT_ENROLLED")
    if (manifest.get("target_scope")!="host" or
        manifest.get("resource_kind")!="host_root_file" or
        manifest.get("job_type")!="signed_vps_host_source" or
        manifest.get("command",{}).get("format")!="host.source.ref.v1"):
        raise Denied("HOST312_FIRST_PARTY_RESOURCE_MISMATCH")
    rel="dynamic-operation-registry-v1/host_admission.py"
    dep={d.get("path"):d.get("sha256") for d in manifest.get("dependencies",[])
         if isinstance(d,dict)}
    pinned=dep.get(rel)
    path=index.root/rel
    if (not isinstance(pinned,str) or not HEX64.fullmatch(pinned) or
        path.is_symlink() or not path.is_file() or
        sha(core.safe_regular(path,max_size=2_000_000))!=pinned):
        raise Denied("HOST312_FIRST_PARTY_ADMISSION_MODULE_NOT_PINNED")
    before=list(sys.path)
    try:
        spec=importlib.util.spec_from_file_location(
            "_mad4b_host312_firstparty_"+pinned[:14],path)
        if spec is None or spec.loader is None:
            raise Denied("HOST312_FIRST_PARTY_ADMISSION_MODULE_MISSING")
        module=importlib.util.module_from_spec(spec)
        sys.path.insert(0,str(index.root))
        spec.loader.exec_module(module)
        candidate=module.claimed_host_job(index,operation,plan,
                                          original_job,original_pending)
        result=module.verify_first_party_host312(index,candidate,signed_host312)
    finally:
        sys.path[:]=before
    if (result.get("original_312_verified") is not True or
        result.get("execution_authorized") is not False or
        result.get("original_313_signed_receipt") is not False or
        result.get("plan_sha256")!=plan.get("plan_sha256")):
        raise Denied("HOST312_FIRST_PARTY_READBACK_INVALID")
    return result


HOST_MASTER_CONTRACT="mad4b.native-host-source-master-approval.v1"
HOST_BROKER_CONTRACT="mad4b.native-host-source-broker-grant.v1"


def _verify_host_signed_envelope(row: dict, public_key: str, contract: str,
                                 required: set[str], *, now: int) -> None:
    from cryptography.hazmat.primitives.asymmetric.ed25519 import Ed25519PublicKey
    from cryptography.exceptions import InvalidSignature
    import time
    if (not isinstance(row,dict) or set(row)!=required or
        row.get("contract")!=contract or
        type(row.get("issued_epoch")) is not int or
        type(row.get("expires_epoch")) is not int or
        not now-45<=row["issued_epoch"]<=now or
        not now+10<row["expires_epoch"]<=now+300):
        raise Denied("HOST_RELEASE_SIGNED_ENVELOPE_INVALID_OR_EXPIRED")
    try:
        key=base64.b64decode(public_key,validate=True)
        signature=base64.b64decode(row["signature"],validate=True)
        Ed25519PublicKey.from_public_bytes(key).verify(
            signature,(contract+"\n").encode()+canonical(
                {k:v for k,v in row.items() if k!="signature"}))
    except (InvalidSignature,ValueError,TypeError,KeyError) as exc:
        raise Denied("HOST_RELEASE_INDEPENDENT_SIGNATURE_INVALID") from exc


def _read_original_host_master_ticket(plan: dict, approval: dict,
                                     policy: dict) -> dict:
    """Read current Central ticket; never trust caller-provided approval flags.

    The original WP Host-source review ability is NOT installed yet. Until
    that original controller exists, this check must fail closed.
    """
    scope=policy.get("central_approval_scope")
    if not isinstance(scope,str) or scope not in policy["targets"]:
        raise Denied("HOST_MASTER_ORIGINAL_CENTRAL_APPROVAL_SCOPE_NOT_ENROLLED")
    target=policy["targets"][scope]
    ticket=approval["ticket_id"]
    if not ID.fullmatch(ticket):
        raise Denied("HOST_MASTER_TICKET_ID_INVALID")
    facts=base64.b64encode(canonical({
        "operation_id":approval["operation_id"],"plan_sha256":plan["plan_sha256"],
        "owner_id":plan["owner_id"],"owner_catalog_sha256":plan["owner_catalog_sha256"],
        "host_scope":plan["scope"],"git_commit":plan["git_commit"],
        "ability_name":approval["ability_name"]})).decode()
    code=(
      "$t=class_exists('MAD4B_SCP_Approval_Tickets',false)"
      "?MAD4B_SCP_Approval_Tickets::get('"+ticket+"'):null;"
      "$v=json_decode(base64_decode('"+facts+"',true),true);"
      "$review=class_exists('MAD4B_SCP_Native_Host_Source_Release_Review',false);"
      "$i=$review&&is_array($v)"
      "?MAD4B_SCP_Native_Host_Source_Release_Review::request_input($v):null;"
      "$valid=is_array($i)&&$i===$v;"
      "$fp=$valid&&class_exists('MAD4B_SCP_Authorization_Target_Fingerprint',false)"
      "?MAD4B_SCP_Authorization_Target_Fingerprint::fingerprint"
      "((string)$v['ability_name'],'mad4b-native-host-source',$i):'';"
      "$valid=$valid&&is_array($t)&&is_string($t['target_fingerprint']??null)"
      "&&hash_equals($t['target_fingerprint'],(string)$fp);"
      "$a=$valid&&class_exists('MAD4B_SCP_Agent_Registry',false)"
      "?MAD4B_SCP_Agent_Registry::get_agent_by_id((int)($t['agent_id']??0)):null;"
      "$exact=$valid&&is_array($a)&&($a['status']??'')==='enabled'"
      "&&($t['provider']??'')==='mad4b-native-host-source'"
      "&&($t['server_id']??'')==='mad4b-write'"
      "&&($t['ticket_class']??'')==='mutation'"
      "?MAD4B_SCP_Approval_Tickets::validate_exact('"+ticket+"',$a,"
      "'mad4b-write',(string)$v['ability_name'],"
      "'mad4b-native-host-source',(string)$fp,$i,'mutation'):null;"
      "$valid=$valid&&is_array($exact)&&!is_wp_error($exact);"
      "$u=is_array($t)?get_user_by('id',(int)($t['approved_by']??0)):false;"
      "$master=$u instanceof WP_User&&user_can($u,'manage_options')"
      "&&(in_array('administrator',(array)$u->roles,true)||"
      "in_array('master_admin',(array)$u->roles,true));"
      "echo wp_json_encode(['valid_review'=>$valid,'master_admin'=>$master,"
      "'ticket_id'=>$t['ticket_id']??null,'status'=>$t['status']??null,"
      "'ability_name'=>$t['ability_name']??null,"
      "'payload_sha256'=>$t['payload_sha256']??null,"
      "'approved_by'=>(int)($t['approved_by']??0),"
      "'expires_at'=>$t['expires_at']??null]);")
    raw=core.run(["docker","exec","-u","www-data",target["container"],
                  "wp","--path=/var/www/html","--skip-themes","eval",code],timeout=40)
    lines=[json.loads(line) for line in raw.decode().splitlines()
           if line.startswith("{")]
    if len(lines)!=1 or not isinstance(lines[0],dict):
        raise Denied("HOST_MASTER_CURRENT_TICKET_READBACK_UNAVAILABLE")
    return lines[0]


def verify_original_host_master_and_broker(plan: dict, host312: dict,
                                          approval: dict, grant: dict,
                                          *, now: int | None=None)->dict:
    """Both first-party signatures AND live Master ticket mandatory.

    This validates existing, independently issued envelopes only; it cannot
    mint approvals, grants, jobs or Host312/313 receipts.
    """
    import time
    current=int(time.time()) if now is None else now
    policy=checked_policy()
    index=original_registry()
    original312=getattr(index,"host_resource_312_public_key",None)
    try:
        master_key=base64.b64decode(
            policy["master_approval_public_key_b64"],validate=True)
        broker_key=base64.b64decode(
            policy["broker_public_key_b64"],validate=True)
    except (KeyError,TypeError,ValueError) as exc:
        raise Denied("HOST_RELEASE_SIGNER_POLICY_KEYS_INVALID") from exc
    if (not isinstance(original312,bytes) or len(original312)!=32 or
        len(master_key)!=32 or len(broker_key)!=32 or
        len({original312,master_key,broker_key})!=3):
        raise Denied("HOST_RELEASE_ORIGINAL312_MASTER_BROKER_KEYS_NOT_DISTINCT")
    if (host312.get("original_312_verified") is not True or
        host312.get("execution_authorized") is not False or
        host312.get("plan_sha256")!=plan.get("plan_sha256") or
        not isinstance(host312.get("job_id"),str) or
        not isinstance(host312.get("host312_signed_nonce"),str)):
        raise Denied("HOST_RELEASE_SIGNED312_MISSING_OR_PLAN_CHANGED")
    job=host312["job_id"]
    common={"operation_id":host312["operation_id"],
            "host_id":host312["host_id"],
            "owner_id":plan["owner_id"],
            "owner_catalog_sha256":plan["owner_catalog_sha256"],
            "plan_sha256":plan["plan_sha256"],
            "job_id":job}
    master_keys=set(common)|{"contract","issuer","ticket_id","ticket_payload_sha256",
                "ability_name","approved_by","issued_epoch","expires_epoch","signature"}
    grant_keys=set(common)|{"contract","issuer","nonce","action",
              "issued_epoch","expires_epoch","signature"}
    if (not isinstance(approval,dict) or not isinstance(grant,dict) or
        any(approval.get(k)!=v or grant.get(k)!=v for k,v in common.items()) or
        approval.get("issuer")!="mad4b-master-admin" or
        approval.get("ability_name")!="mad4b/host-source-release" or
        grant.get("issuer")!="mad4b-company-broker" or
        grant.get("action")!="apply" or
        grant.get("nonce")!=host312["host312_signed_nonce"] or
        type(approval.get("approved_by")) is not int or
        approval["approved_by"]<=0 or
        not ID.fullmatch(str(approval.get("ticket_id",""))) or
        not HEX64.fullmatch(str(approval.get("ticket_payload_sha256","")))):
        raise Denied("HOST_RELEASE_APPROVAL_OR_GRANT_NOT_BOUND")
    _verify_host_signed_envelope(approval,policy["master_approval_public_key_b64"],
                                 HOST_MASTER_CONTRACT,master_keys,now=current)
    _verify_host_signed_envelope(grant,policy["broker_public_key_b64"],
                                 HOST_BROKER_CONTRACT,grant_keys,now=current)
    row=_read_original_host_master_ticket(plan,approval,policy)
    if (row.get("valid_review") is not True or row.get("master_admin") is not True
        or row.get("ticket_id")!=approval["ticket_id"] or
        row.get("status")!="approved" or
        row.get("ability_name")!=approval["ability_name"] or
        row.get("payload_sha256")!=approval["ticket_payload_sha256"] or
        row.get("approved_by")!=approval["approved_by"]):
        raise Denied("HOST_RELEASE_MASTER_TICKET_REVOKED_OR_CHANGED")
    try:
        expiry=datetime.strptime(row["expires_at"],"%Y-%m-%d %H:%M:%S").replace(
            tzinfo=timezone.utc)
        if not current<expiry.timestamp()<=current+3600:
            raise ValueError("expired or too broad")
    except (ValueError,KeyError,TypeError) as exc:
        raise Denied("HOST_RELEASE_MASTER_TICKET_EXPIRED") from exc
    return {"state":"HOST_MASTER_AND_BROKER_SIGNED_NEEDS_INDEPENDENT313",
            "job_id":job,"plan_sha256":plan["plan_sha256"],
            "original_312_verified":True,"master_approval_verified":True,
            "broker_release_grant_verified":True,
            "original_313_signed_receipt":False,
            "execution_authorized":False}


def operation_binding(plan_sha256: str, manifest: dict) -> dict:
    values={
        "contract":BINDING_CONTRACT,
        "operation_id":manifest["operation_id"],
        "approval_ability":manifest["approval_ability"],
        "provider":manifest["provider"],
        "source_family":FAMILY,
        "manifest_sha256":sha(canonical(manifest)),
    }
    values["binding_sha256"]=sha(canonical(dict(values,plan_sha256=plan_sha256)))
    return values


def make_plan(request: dict, policy: dict, registry=None) -> dict:
    validate_policy(policy)
    if not isinstance(request,dict) or request.get("contract")!=REQUEST_CONTRACT:
        raise Denied("SOURCE_REQUEST_CONTRACT_INVALID")
    operation_id=request.get("operation_id")
    manifest=enrolled_original_provider(operation_id,registry)
    scope=request.get("scope")
    if not isinstance(scope,str) or not SCOPE.fullmatch(scope) or scope not in policy["targets"]:
        raise Denied("SOURCE_OPERATION_TARGET_NOT_ENROLLED")
    company,environment=scope.split(":")
    target=policy["targets"][scope]
    if request.get("site_uuid")!=target["site_uuid"] or request.get("origin")!=target["origin"]:
        raise Denied("SOURCE_OPERATION_SITE_IDENTITY_MISMATCH")
    if not GIT_SHA.fullmatch(str(request.get("git_commit"))):
        raise Denied("SOURCE_GIT_COMMIT_INVALID")
    records=request.get("files")
    if not isinstance(records,list) or not 1<=len(records)<=24:
        raise Denied("SOURCE_RELEASE_FILE_COUNT_INVALID")
    files=[]
    for item in records:
        if not isinstance(item,dict) or set(item)!={"target","source","expected_before_sha256"}:
            raise Denied("SOURCE_RELEASE_FILE_RECORD_INVALID")
        source=core.exact_relative(item["source"])
        dest=core.exact_relative(item["target"])
        before=item["expected_before_sha256"]
        if not isinstance(before,str) or not HEX64.fullmatch(before):
            raise Denied("SOURCE_PREIMAGE_MUST_BE_EXACT")
        if dest not in target["allowed_targets"]:
            raise Denied("SOURCE_RELEASE_FILE_NOT_ENROLLED")
        # The immutable Git path must be the same deployable file. A provider
        # cannot use one approved target to substitute a different source.
        if source != dest:
            raise Denied("SOURCE_TARGET_EQUIVALENCE_REQUIRED")
        files.append({"source":source,"target":dest,"expected_before_sha256":before})
    core_spec={
        "contract":core.SPEC_CONTRACT,"company":company,"environment":environment,
        "site_uuid":target["site_uuid"],"git_commit":request["git_commit"],
        "files":files,"acceptance_checks":["wp_boot"],
    }
    try:
        plan=core.make_plan(core_spec,company_policy(policy))
    except core.Denied as exc:
        raise Denied(str(exc)) from exc
    if not plan["changed_count"]:
        raise Denied("SOURCE_NO_CHANGE_TO_PUBLISH")
    # The core release plan SHA must remain an EXACT canonical digest of the
    # original CAS document. Appending fields and recomputing the SHA changes
    # the semantics of core.confirm_plan and must never be done.
    plan[BINDING_KEY] = operation_binding(plan["plan_sha256"],manifest)
    return plan



def verified_enriched_plan(original: dict, registry=None, *,
                           verify_signed_operation=None) -> dict:
    """Rebuild a transient source binding ONLY after first-party signed proof.

    The authoritative Original305 plan store is byte-for-byte core CAS. The
    signature is in the separately enrolled root-owned association store.
    Neither a WordPress plan field nor an unsigned requested operation may
    choose the provider. The result is never persisted to the CAS plan store.
    """
    import sys
    index=registry if registry is not None else original_registry()
    if (not isinstance(original,dict) or BINDING_KEY in original or
        "operation_id" in original or
        not isinstance(original.get("plan_sha256"),str) or
        not HEX64.fullmatch(original["plan_sha256"]) or
        sha(canonical({k:v for k,v in original.items()
             if k not in ("plan_sha256","changed_count")})) !=
             original["plan_sha256"]):
        raise Denied("SOURCE_ORIGINAL_CAS_PLAN_CHANGED")
    if verify_signed_operation is None:
        # The enrolled source manifest is already verified by the Original
        # Registry; the helper itself must be byte-pinned before import.
        eligible=[manifest for manifest in index.manifests.values()
                  if manifest.get("source_family")==FAMILY and
                  manifest.get("plan_contract")==original.get("contract")]
        if not eligible:
            raise Denied("SOURCE_SIGNED_OPERATION_NOT_ENROLLED")
        preclaims={(item.get("preclaim",{}).get("path"),
                    item.get("preclaim",{}).get("sha256"))
                   for item in eligible}
        if len(preclaims)!=1:
            raise Denied("SOURCE_ORIGINAL306_PRECLAIM_SOURCE_CONFLICT")
        rel,pinned=next(iter(preclaims))
        if (rel!="native-source-publisher-v1/original_306_preclaim.py" or
            not isinstance(pinned,str) or not HEX64.fullmatch(pinned)):
            raise Denied("SOURCE_ORIGINAL306_PRECLAIM_NOT_ENROLLED")
        import importlib.util
        root=getattr(index,"root",None)
        if (not isinstance(root,Path) or not root.is_absolute() or
            root.is_symlink() or not root.is_dir()):
            raise Denied("SOURCE_FIRST_PARTY_REGISTRY_ROOT_NOT_TRUSTED")
        path=root/rel
        core.no_link(path)
        if sha(core.safe_regular(path,max_size=2_000_000)) != pinned:
            raise Denied("SOURCE_ORIGINAL306_PRECLAIM_SOURCE_DRIFT")
        old_path=list(sys.path)
        try:
            sys.path.insert(0,str(index.root))
            spec=importlib.util.spec_from_file_location(
                "_mad4b_verified_source_plan_association_"+pinned[:16],path)
            if spec is None or spec.loader is None:
                raise Denied("SOURCE_ORIGINAL306_PRECLAIM_NOT_LOADABLE")
            module=importlib.util.module_from_spec(spec)
            spec.loader.exec_module(module)
            if (Path(module.__file__)!=path or
                sha(core.safe_regular(path,max_size=2_000_000))!=pinned):
                raise Denied("SOURCE_ORIGINAL306_PRECLAIM_CHANGED_DURING_LOAD")
            verified=module.signed_operation(index,original)
        finally:
            sys.path[:]=old_path
    else:
        verified=verify_signed_operation(index,original)
    if not isinstance(verified,tuple) or len(verified)!=2:
        raise Denied("SOURCE_SIGNED_OPERATION_RESULT_INVALID")
    operation,manifest=verified
    if not isinstance(operation,str) or not OP_ID.fullmatch(operation) or not isinstance(manifest,dict):
        raise Denied("SOURCE_SIGNED_OPERATION_NOT_REGISTERED")
    try:
        registered=index.resolve(operation)
    except (KeyError,ValueError,TypeError,AttributeError) as exc:
        raise Denied("SOURCE_SIGNED_OPERATION_NOT_REGISTERED") from exc
    if (registered!=manifest or manifest.get("operation_id")!=operation or
        manifest.get("plan_contract")!=original["contract"] or
        manifest.get("source_family")!=FAMILY):
        raise Denied("SOURCE_SIGNED_OPERATION_NOT_REGISTERED")
    validate_manifest(manifest)
    augmented={**original,BINDING_KEY:operation_binding(
        original["plan_sha256"],manifest)}
    return augmented


def core_plan(plan: dict) -> dict:
    if not isinstance(plan,dict) or BINDING_KEY not in plan:
        raise Denied("SOURCE_OPERATION_BINDING_REQUIRED")
    bound=plan[BINDING_KEY]
    if not isinstance(bound,dict):
        raise Denied("SOURCE_OPERATION_BINDING_INVALID")
    original=dict(plan)
    original.pop(BINDING_KEY)
    return original


def check_plan(plan: dict, policy: dict, *, after: bool = False, registry=None) -> dict:
    original=core_plan(plan)
    binding=plan[BINDING_KEY]
    if not isinstance(binding,dict) or set(binding)!={
        "contract","operation_id","provider","approval_ability",
        "source_family","manifest_sha256","binding_sha256"}:
        raise Denied("SOURCE_OPERATION_BINDING_SHAPE_INVALID")
    manifest=enrolled_original_provider(binding["operation_id"],registry)
    expected=operation_binding(original["plan_sha256"],manifest)
    if binding!=expected:
        raise Denied("SOURCE_OPERATION_BINDING_OR_MANIFEST_DRIFT")
    scope=str(original.get("company",""))+":"+str(original.get("environment",""))
    if scope not in policy["targets"]:
        raise Denied("SOURCE_RELEASE_TARGET_UNENROLLED")
    rows=original.get("files")
    if not isinstance(rows,list) or not 1<=len(rows)<=24:
        raise Denied("SOURCE_RELEASE_FILE_COUNT_INVALID")
    for row in rows:
        if not isinstance(row,dict) or row.get("source")!=row.get("target"):
            raise Denied("SOURCE_TARGET_EQUIVALENCE_REQUIRED")
        if (not isinstance(row.get("source_sha256"),str) or
            not HEX64.fullmatch(row["source_sha256"]) or
            not isinstance(row.get("expected_before_sha256"),str) or
            not HEX64.fullmatch(row["expected_before_sha256"])):
            raise Denied("SOURCE_CHANGE_DIGESTS_INVALID")
    derived=sum(row["source_sha256"]!=row["expected_before_sha256"] for row in rows)
    # Core CAS excludes changed_count from its canonical plan SHA. Verify it
    # independently on every readback; a forged count is not authority.
    if type(original.get("changed_count")) is not int or original["changed_count"]!=derived:
        raise Denied("SOURCE_DERIVED_CHANGED_COUNT_MISMATCH")
    if not derived:
        raise Denied("SOURCE_NO_CHANGE_TO_PUBLISH")
    try:
        core.confirm_plan(original,company_policy(policy),expect_preimage=not after)
    except core.Denied as exc:
        raise Denied(str(exc)) from exc
    return manifest


def approval_operation_input(plan: dict) -> dict:
    """The signed operation is bound to exact CAS plan + enrolled manifest."""
    binding=plan[BINDING_KEY]
    files=plan.get("files")
    if not isinstance(files,list) or not 1<=len(files)<=24:
        raise Denied("SOURCE_APPROVAL_FILE_COUNT_INVALID")
    return {
        "operation_id":binding["operation_id"],
        "ability_name":binding["approval_ability"],
        "provider":binding["provider"],
        "source_manifest_sha256":binding["manifest_sha256"],
        "operation_binding_sha256":binding["binding_sha256"],
        "company":plan["company"],"environment":plan["environment"],
        "site_uuid":plan["site_uuid"],"origin":plan["origin"],
        "git_commit":plan["git_commit"],"plan_sha256":plan["plan_sha256"],
        "files":[{"target":row["target"],
                  "expected_before_sha256":row["expected_before_sha256"],
                  "source_sha256":row["source_sha256"]} for row in files],
    }


def approval_message(approval: dict) -> bytes:
    return (APPROVAL_CONTRACT+"\n").encode()+canonical(
        {k:v for k,v in approval.items() if k!="signature"})


def verify_master_approval(plan: dict, policy: dict, approval: dict, *,
                           now: datetime | None = None) -> None:
    from cryptography.hazmat.primitives.asymmetric.ed25519 import Ed25519PublicKey
    required={"contract","operation_id","plan_sha256","approval_ability",
         "approval_provider","source_manifest_sha256","operation_binding_sha256","scope","site_uuid","origin","ticket_id",
        "ticket_payload_sha256","approver_role","ticket_state",
        "expires_at","signature"}
    if not isinstance(approval,dict) or set(approval)!=required:
        raise Denied("SOURCE_MASTER_APPROVAL_SHAPE_INVALID")
    values={"contract":APPROVAL_CONTRACT,
      "operation_id":plan[BINDING_KEY]["operation_id"],"plan_sha256":plan["plan_sha256"],
      "approval_ability":plan[BINDING_KEY]["approval_ability"],
      "approval_provider":plan[BINDING_KEY]["provider"],
      "source_manifest_sha256":plan[BINDING_KEY]["manifest_sha256"],
      "operation_binding_sha256":plan[BINDING_KEY]["binding_sha256"],
      "scope":plan["company"]+":"+plan["environment"],
      "site_uuid":plan["site_uuid"],"origin":plan["origin"],
      "approver_role":"master_admin","ticket_state":"approved"}
    if (any(approval.get(k)!=v for k,v in values.items()) or
        not ID.fullmatch(str(approval["ticket_id"])) or
        not HEX64.fullmatch(str(approval["ticket_payload_sha256"]))):
        raise Denied("SOURCE_MASTER_APPROVAL_BINDING_INVALID")
    try:
        expiry=datetime.fromisoformat(approval["expires_at"])
        clock=now or datetime.now(timezone.utc)
        if expiry.tzinfo is None or not clock<expiry<=clock+timedelta(hours=1):
            raise ValueError("expiry")
        Ed25519PublicKey.from_public_bytes(
            base64.b64decode(policy["master_approval_public_key_b64"],validate=True)
        ).verify(base64.b64decode(approval["signature"],validate=True),
                 approval_message(approval))
    except Exception as exc:
        raise Denied("SOURCE_MASTER_APPROVAL_SIGNATURE_OR_EXPIRY_INVALID") from exc


def validate_master_ticket_row(plan: dict, approval: dict, row: dict,
                               *, now: datetime | None = None) -> None:
    expected={"ticket_id":approval["ticket_id"],"status":"approved",
        "ticket_class":"mutation","server_id":"mad4b-write",
        "ability_name":plan[BINDING_KEY]["approval_ability"],
        "provider":plan[BINDING_KEY]["provider"],
        "payload_sha256":approval["ticket_payload_sha256"],
        "site_uuid":plan["site_uuid"],
        "master_admin_approver_verified":True,
        "approval_input_unchanged":True}
    if not isinstance(row,dict) or any(row.get(k)!=v for k,v in expected.items()):
        raise Denied("SOURCE_MASTER_TICKET_CHANGED_OR_REVOKED")
    if (not isinstance(row.get("target_fingerprint"),str) or
        not HEX64.fullmatch(row["target_fingerprint"]) or
        row.get("exact_plan_target_fingerprint")!=row["target_fingerprint"]):
        raise Denied("SOURCE_MASTER_TICKET_TARGET_DRIFT")
    for field in ("approved_by","agent_id"):
        if type(row.get(field)) is not int or row[field]<=0:
            raise Denied("SOURCE_MASTER_TICKET_ACTOR_INVALID")
    try:
        expiry=datetime.strptime(row["expires_at"],"%Y-%m-%d %H:%M:%S").replace(tzinfo=timezone.utc)
        if expiry<=(now or datetime.now(timezone.utc)):
            raise ValueError("expired")
    except (KeyError,ValueError,TypeError) as exc:
        raise Denied("SOURCE_MASTER_TICKET_EXPIRED") from exc


def fresh_master_ticket_readback(plan: dict, policy: dict, approval: dict) -> None:
    """Read the REAL approved WordPress ticket and recompute its dynamic target."""
    company_policy(policy)
    ticket=approval["ticket_id"]
    if not ID.fullmatch(ticket):
        raise Denied("SOURCE_MASTER_TICKET_ID_INVALID")
    facts=base64.b64encode(canonical(approval_operation_input(plan))).decode()
    code=(
      "$t=class_exists('MAD4B_SCP_Approval_Tickets')"
      "?MAD4B_SCP_Approval_Tickets::get('"+ticket+"'):null;"
      "if(!is_array($t)){echo '{}';return;}"
      "$v=json_decode(base64_decode('"+facts+"',true),true);"
      "$i=class_exists('MAD4B_SCP_Native_Source_Release_Review')"
      "?MAD4B_SCP_Native_Source_Release_Review::request_input($v):null;"
      "$clean=is_array($i)&&!is_wp_error($i);"
      "$a=$clean&&class_exists('MAD4B_SCP_Staging_Write_Authority')"
      "?MAD4B_SCP_Staging_Write_Authority::authorization_input"
      "($i,(string)$i['ability_name']):$i;"
      "$t['approval_input_unchanged']=$clean&&is_array($a)&&$a===$i;"
      "$t['exact_plan_target_fingerprint']=$t['approval_input_unchanged']&&"
      "class_exists('MAD4B_SCP_Authorization_Target_Fingerprint')"
      "?MAD4B_SCP_Authorization_Target_Fingerprint::fingerprint"
      "((string)$i['ability_name'],(string)$i['provider'],$i):'';"
      "$u=get_user_by('id',(int)($t['approved_by']??0));"
      "$t['master_admin_approver_verified']=$u instanceof WP_User&&"
      "user_can($u,'manage_options')&&"
      "(in_array('administrator',(array)$u->roles,true)||"
      "in_array('master_admin',(array)$u->roles,true));"
      "$t['approved_by']=(int)($t['approved_by']??0);"
      "$t['agent_id']=(int)($t['agent_id']??0);"
      "echo wp_json_encode(array_intersect_key($t,array_flip("
      "['ticket_id','status','ticket_class','server_id','ability_name',"
      "'provider','payload_sha256','target_fingerprint','approved_by',"
      "'agent_id','expires_at','site_uuid','approval_input_unchanged',"
      "'exact_plan_target_fingerprint','master_admin_approver_verified'])));"
    )
    container=policy["targets"][plan["company"]+":"+plan["environment"]]["container"]
    raw=core.run(["docker","exec","-u","www-data",container,
             "wp","--path=/var/www/html","--skip-themes","eval",code],timeout=45)
    lines=[json.loads(x) for x in raw.decode().splitlines()
           if x.startswith("{")]
    if len(lines)!=1:
        raise Denied("SOURCE_MASTER_TICKET_READBACK_MISSING")
    validate_master_ticket_row(plan,approval,lines[0])


def native_runtime_binding(plan: dict, policy: dict) -> None:
    """Reuse original FAORE target identity + mount. No remote host grant."""
    core.assert_runtime_binding(core_plan(plan),company_policy(policy))
    code='echo wp_json_encode(["origin"=>rtrim(home_url(),"/"),"is_multisite"=>is_multisite()]);'
    raw=core.run(["docker","exec","-u","www-data",plan["container"],
                  "wp","--path=/var/www/html","--skip-themes","eval",code],timeout=45)
    observed=[json.loads(x) for x in raw.decode().splitlines() if x.startswith("{")]
    if (len(observed)!=1 or observed[0].get("origin")!=plan["origin"]
        or observed[0].get("is_multisite") is not False):
        raise Denied("SOURCE_WORDPRESS_SITE_IDENTITY_DRIFT")


def reserve_master_ticket_once(plan: dict, policy: dict,
                               approval: dict, receipt_root: Path) -> None:
    core.assert_pinned_receipt_directory(company_policy(policy),receipt_root)
    ticket=approval["ticket_id"]
    if not ID.fullmatch(ticket):
        raise Denied("SOURCE_MASTER_TICKET_ID_INVALID")
    marker=receipt_root/("master-"+ticket+".reserved")
    core.no_link(marker)
    try:
        fd=os.open(marker,os.O_CREAT|os.O_EXCL|os.O_WRONLY|
                   getattr(os,"O_NOFOLLOW",0),0o600)
    except FileExistsError as exc:
        raise Denied("SOURCE_MASTER_APPROVAL_ALREADY_CLAIMED") from exc
    with os.fdopen(fd,"wb") as stream:
        stream.write((plan["plan_sha256"]+"\n").encode())
        stream.flush()
        os.fsync(stream.fileno())


def source_acceptance(plan: dict) -> None:
    core.php_lint(plan)
    for item in plan["files"]:
        if item["target"].endswith(".js"):
            core.run(["node","--check",str(Path(plan["target_root"])/item["target"])],timeout=20)


def admission(plan: dict, policy: dict, approval: dict, broker_grant: dict) -> None:
    check_plan(plan,policy)
    verify_master_approval(plan,policy,approval)
    core.verify_grant(plan,company_policy(policy),broker_grant,"apply")
    native_runtime_binding(plan,policy)
    fresh_master_ticket_readback(plan,policy,approval)


def executor(plan: dict, policy: dict, approval: dict,
             broker_grant: dict, receipt_root: Path) -> dict:
    """ONLY private first-party handler may invoke this after original 312."""
    admission(plan,policy,approval,broker_grant)
    reserve_master_ticket_once(plan,policy,approval,receipt_root)
    result=core.apply_release(core_plan(plan),company_policy(policy),broker_grant,receipt_root,
                              runtime_check=native_runtime_binding,
                              validate_code=source_acceptance)
    result["original_313_receipt"]=False
    result["source_family"]=FAMILY
    return result


def main():
    parser=argparse.ArgumentParser(description="Dynamic enrolled local source publisher")
    parser.add_argument("action",choices=("status","plan","verify",
                                         "host-plan","host-verify","host-doctor"))
    parser.add_argument("--request",type=Path)
    parser.add_argument("--plan",type=Path)
    args=parser.parse_args()
    if args.action=="host-doctor":
        if args.plan or args.request:
            raise Denied("HOST_DOCTOR_NO_EXTERNAL_ARTIFACT_INPUT")
        from host_source_activation_doctor import actual_status
        print(json.dumps(actual_status(),sort_keys=True,indent=2))
        return 0
    if args.action=="status":
        try:
            checked_policy()
            state="ENROLLED_FOR_READ_ONLY_DISCOVERY"
            operations=discover_source_operations()
        except Exception as exc:
            state="HOLD__"+type(exc).__name__+":"+str(exc)[:100]
            operations=[]
        try:
            host_operations=discover_host_resource_operations()
        except Exception:
            host_operations=[]
        print(json.dumps({"contract":FAMILY,"state":state,
                          "registered_operations":operations,
                          "host_resource_ownership":host_source.status(),
                          "host_resource_registered_operations":host_operations,
                          "host_signed_original_312_provider":(
                              "ENROLLED_SOURCE_MANIFEST_NEEDS_SIGNED_JOB"
                              if host_operations else "NOT_ENROLLED"),
                          "host_original_313_finalizer":"NOT_ENROLLED",
                          "publishing_performed":False}))
        return 0
    if args.action in ("host-plan","host-verify"):
        # This is a preflight extension of the same publisher. Neither route
        # can execute a native task, create an approval or accept a host grant.
        # An exact private first-party root catalog is REQUIRED, unlike
        # synthetic test fixtures. The existing WP mode is unchanged.
        catalog=host_source.checked_host_catalog()
        if args.action=="host-plan":
            if not args.request or args.plan:
                raise Denied("HOST_SOURCE_REQUEST_REQUIRED")
            request=json.loads(args.request.read_text())
            plan=host_source.create_host_plan(request,catalog)
            print(json.dumps(plan,sort_keys=True,indent=2))
        else:
            if not args.plan or args.request:
                raise Denied("HOST_SOURCE_PLAN_REQUIRED")
            plan=json.loads(args.plan.read_text())
            host_source.verify_host_plan(plan,catalog)
            print(json.dumps({"state":"EXACT_HOST_GIT_SOURCE_PREFLIGHT_VERIFIED",
                "plan_sha256":plan["plan_sha256"],
                "original_312_verified":False,
                "original_313_receipt_signed":False,
                "publication_authorized":False},sort_keys=True))
        return 0
    policy=checked_policy()
    if args.action=="plan":
        if not args.request:
            raise Denied("SOURCE_REQUEST_REQUIRED")
        request=json.loads(args.request.read_text())
        print(json.dumps(make_plan(request,policy),sort_keys=True,indent=2))
    else:
        if not args.plan:
            raise Denied("SOURCE_PLAN_REQUIRED")
        plan=json.loads(args.plan.read_text())
        check_plan(plan,policy)
        print(json.dumps({"state":"EXACT_SOURCE_PLAN_CAS_VERIFIED",
                          "plan_sha256":plan["plan_sha256"],
                          "publication_authorized":False}))
    return 0


if __name__=="__main__":
    try:
        raise SystemExit(main())
    except Exception as exc:
        print(json.dumps({"state":"HOLD","reason":str(exc)[:200],
                          "publishing_performed":False}))
        raise SystemExit(2)
