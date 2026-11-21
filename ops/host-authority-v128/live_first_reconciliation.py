"""Site-bound live-first reconciliation; plans only, no host mutations.

Operational truth: signed on-site current.
Desired truth: independently signed, revisioned per-site approved source.
Candidate: exact Git bytes proposed for review, NEVER approved by Git alone.
Previous: Host Runner backup of the exact current preimage, not a guessed copy.
"""
from __future__ import annotations

import base64
import hashlib
import json
import re
from dataclasses import dataclass
from cryptography.exceptions import InvalidSignature
from cryptography.hazmat.primitives.asymmetric.ed25519 import Ed25519PublicKey
from fleet_policy import SiteBinding
from persistent_enrollment import ConnectionDenied, need, keys_independent
from live_current_source import (
    Current, CANDIDATE_CONTRACT, validate_files, HEX40, HEX32, plan_candidate,
)

DESIRED_CONTRACT = "mad4b.host-v129.approved-desired-source.v1"
DESIRED_DOMAIN = b"MAD4B_APPROVED_SITE_DESIRED_SOURCE_V1\n"
DESIRED_HIGHWATER_CONTRACT="mad4b.host-v129.desired-source-highwater.v1"
HIGHWATER_DOMAIN=b"MAD4B_APPROVED_SITE_DESIRED_HIGHWATER_V1\n"
HIGHWATER_FIELDS=(
    "contract","company","environment","site_uuid","target_host_id",
    "revision","desired_record_sha256","state","observed_epoch","nonce",
)
DESIRED_FIELDS = (
    "contract", "company", "environment", "site_uuid", "target_host_id",
    "host_key_sha256", "policy_sha256", "revision", "source_repo",
    "source_head_sha", "approval_nonce", "approved_epoch", "state",
    "base_files", "files", "deployment_groups",
)
SOURCE_REPO = "mad4bdigital-ai/WordPress"
GROUP = re.compile(r"[a-z][a-z0-9_-]{0,55}\Z")

@dataclass(frozen=True)
class Desired:
    site_uuid: str
    approved_revision: int
    source_head_sha: str
    signed_record_sha256: str
    baseline: tuple
    files: tuple
    groups: tuple

    def baseline_map(self):
        return {p:(state,sha,size) for p,state,sha,size in self.baseline}

    def desired_map(self):
        return {p:(state,sha,size) for p,state,sha,size in self.files}


def canonical_desired(row: dict) -> bytes:
    return DESIRED_DOMAIN + json.dumps(
        {k:row[k] for k in DESIRED_FIELDS},sort_keys=True,
        separators=(",",":"),ensure_ascii=False).encode("utf-8")

def canonical_highwater(row: dict) -> bytes:
    return HIGHWATER_DOMAIN + json.dumps(
        {k:row[k] for k in HIGHWATER_FIELDS},sort_keys=True,
        separators=(",",":"),ensure_ascii=False).encode("utf-8")

def verify_current_desired_highwater(highwater: dict, row: dict,
                                     binding: SiteBinding,
                                     owner_public: bytes, registry_public: bytes,
                                     *, now: int) -> None:
    """Independent approval-registry witness prevents replay of an older
    correctly signed desired revision after a newer approval or revocation.
    """
    keys_independent(owner_public,registry_public)
    need(type(highwater) is dict
         and set(highwater)==set(HIGHWATER_FIELDS)|{"signature"}
         and highwater.get("contract")==DESIRED_HIGHWATER_CONTRACT,
         "DESIRED_REGISTRY_HIGHWATER_SHAPE_INVALID")
    need(all(highwater.get(k)==v for k,v in (
         ("company",binding.company),("environment",binding.environment),
         ("site_uuid",binding.site_uuid),("target_host_id",binding.host_id)))
         and highwater.get("revision")==row["revision"]
         and highwater.get("desired_record_sha256")==
             hashlib.sha256(canonical_desired(row)).hexdigest()
         and highwater.get("state")=="APPROVED"
         and type(highwater.get("observed_epoch")) is int
         and now-30<=highwater["observed_epoch"]<=now+5
         and type(highwater.get("nonce")) is str
         and HEX32.fullmatch(highwater["nonce"]) is not None,
         "DESIRED_REGISTRY_NOT_CURRENT")
    need(type(highwater.get("signature")) is str,
         "DESIRED_REGISTRY_SIGNATURE_INVALID")
    try:
        signature=base64.b64decode(highwater["signature"],validate=True)
        need(len(signature)==64,"DESIRED_REGISTRY_SIGNATURE_INVALID")
        Ed25519PublicKey.from_public_bytes(registry_public).verify(
            signature,canonical_highwater(highwater))
    except (InvalidSignature,TypeError,ValueError,KeyError) as exc:
        raise ConnectionDenied("DESIRED_REGISTRY_SIGNATURE_INVALID") from exc


def validate_deployment_groups(raw: object, paths: set[str]) -> tuple:
    need(type(raw) is list and 1<=len(raw)<=200,"ATOMIC_GROUPS_INVALID")
    normalized=[]
    seen=set()
    for group in raw:
        need(type(group) is dict
             and set(group)=={"group_id","paths","depends_on"}
             and type(group["group_id"]) is str
             and GROUP.fullmatch(group["group_id"]) is not None
             and type(group["paths"]) is list
             and group["paths"]
             and all(type(path) is str for path in group["paths"])
             and group["paths"]==sorted(set(group["paths"])),
             "ATOMIC_GROUPS_INVALID")
        names=group["paths"]
        deps=group["depends_on"]
        need(type(deps) is list
             and all(type(x) is str and GROUP.fullmatch(x) is not None
                     for x in deps)
             and deps==sorted(set(deps))
             and group["group_id"] not in deps,
             "ATOMIC_GROUP_DEPENDENCIES_INVALID")
        need(all(path in paths and path not in seen for path in names),
             "ATOMIC_GROUPS_OVERLAP_OR_UNKNOWN_PATH")
        seen.update(names)
        normalized.append((group["group_id"],tuple(names),tuple(deps)))
    need(seen==paths and normalized==sorted(normalized)
         and len({x[0] for x in normalized})==len(normalized),
         "ATOMIC_GROUPS_INCOMPLETE_OR_DUPLICATED")
    dependency_map={name:set(deps) for name,_,deps in normalized}
    need(all(dep in dependency_map for deps in dependency_map.values()
             for dep in deps),
         "ATOMIC_GROUP_DEPENDENCY_UNKNOWN")
    visited=set()
    active=set()
    def visit(name: str) -> None:
        need(name not in active,"ATOMIC_GROUP_DEPENDENCY_CYCLE")
        if name in visited:return
        active.add(name)
        for dep in sorted(dependency_map[name]):
            visit(dep)
        active.remove(name)
        visited.add(name)
    for name in sorted(dependency_map):
        visit(name)
    return tuple(normalized)


def verify_desired(row: dict, binding: SiteBinding,
                   approved_source_public_key: bytes,
                   registry_checkpoint: dict, registry_public_key: bytes,
                   *, now: int) -> Desired:
    """Verify separately signed approved desired state; no owner self-approval.

    Public key comes from trusted fleet registry, not from this signed record
    or an HTTP request. A previous revision's monotonic/high-water continuity
    must ALSO be checked by the independent approved-source registry writer.
    """
    need(type(row) is dict and set(row)==set(DESIRED_FIELDS)|{"signature"}
         and row.get("contract")==DESIRED_CONTRACT
         and row.get("state")=="APPROVED",
         "DESIRED_APPROVAL_SHAPE_INVALID")
    need(type(approved_source_public_key) is bytes
         and len(approved_source_public_key)==32,
         "DESIRED_TRUSTED_SIGNER_REQUIRED")
    need(all(row[k]==v for k,v in (
         ("company",binding.company), ("environment",binding.environment),
         ("site_uuid",binding.site_uuid),("target_host_id",binding.host_id),
         ("host_key_sha256",binding.host_key_sha256),
         ("policy_sha256",binding.policy_sha256))),
         "DESIRED_SITE_BINDING_MISMATCH")
    need(type(row["revision"]) is int and row["revision"]>=1
         and type(row["source_head_sha"]) is str
         and HEX40.fullmatch(row["source_head_sha"]) is not None
         and row["source_repo"]==SOURCE_REPO
         and type(row["approval_nonce"]) is str
         and HEX32.fullmatch(row["approval_nonce"]) is not None
         and type(row["approved_epoch"]) is int
         and 0 < row["approved_epoch"]<=now+5,
         "DESIRED_SOURCE_IDENTITY_INVALID")
    base=validate_files(row["base_files"])
    target=validate_files(row["files"])
    need({x[0] for x in base}=={x[0] for x in target},
         "DESIRED_BASELINE_SET_DRIFT")
    need(all(x[1]=="PRESENT" for x in target),
         "DESIRED_UNSUPPORTED_DELETION")
    groups=validate_deployment_groups(row["deployment_groups"],
                                     {x[0] for x in target})
    need(type(row["signature"]) is str,"DESIRED_SIGNATURE_INVALID")
    try:
        sig=base64.b64decode(row["signature"],validate=True)
        need(len(sig)==64,"DESIRED_SIGNATURE_INVALID")
        Ed25519PublicKey.from_public_bytes(approved_source_public_key).verify(
            sig, canonical_desired(row))
    except (ValueError, TypeError, KeyError, InvalidSignature) as exc:
        raise ConnectionDenied("DESIRED_SIGNATURE_INVALID") from exc
    verify_current_desired_highwater(
        registry_checkpoint,row,binding,approved_source_public_key,
        registry_public_key,now=now)
    return Desired(
        site_uuid=binding.site_uuid, approved_revision=row["revision"],
        source_head_sha=row["source_head_sha"],
        signed_record_sha256=hashlib.sha256(canonical_desired(row)).hexdigest(),
        baseline=base, files=target, groups=groups,
    )


def reconcile(current: Current, desired: Desired, candidate: dict,
              binding: SiteBinding) -> dict:
    """Three-way diff: last approved base -> current vs approved desired.

    Safe isolated atomic groups can be proposed; drifted groups are retained
    for review, never overwritten silently. No execution grant is created.
    """
    need(current.site_uuid==desired.site_uuid==binding.site_uuid,
         "RECONCILIATION_CROSS_SITE_DRIFT")
    need(type(candidate) is dict and candidate.get("contract")==CANDIDATE_CONTRACT,
         "RECONCILIATION_CANDIDATE_REQUIRED")
    # The baseline plan validates exact site/host/source and source file set.
    raw_plan=plan_candidate(current,candidate,binding)
    candidate_files=validate_files(candidate["files"])
    current_map=current.file_map()
    base_map=desired.baseline_map()
    desired_map=desired.desired_map()
    need(set(current_map)==set(base_map)==set(desired_map),
         "RECONCILIATION_FILE_SET_MISMATCH")
    candidate_approved=(
        candidate["source_head_sha"]==desired.source_head_sha
        and candidate_files==desired.files
    )
    records=[]
    for group_name,paths,deps in desired.groups:
        changes=[]
        conflicts=[]
        live_changes=[]
        for path in paths:
            live=current_map[path]
            base=base_map[path]
            target=desired_map[path]
            if live==target:
                continue
            if live!=base:
                conflicts.append(path)
                live_changes.append(path)
            else:
                changes.append(path)
        if not candidate_approved:
            verdict="CANDIDATE_NOT_APPROVED"
        elif conflicts:
            verdict="PRESERVE_LIVE_DRIFT_REVIEW"
        elif changes:
            verdict="EXACT_GROUP_CHANGE_PENDING_AUTHORIZATION"
        else:
            verdict="IN_SYNC"
        records.append({
            "group_id":group_name,
            "verdict":verdict,
            "paths":list(paths),
            "change_paths":changes if verdict=="EXACT_GROUP_CHANGE_PENDING_AUTHORIZATION" else [],
            "preserved_live_paths":live_changes,
            "conflicts":conflicts,
            "requires_atomic_install":True,
            "depends_on":list(deps),
        })
    # A dependent update is not ready until its prerequisites are already
    # installed and verified, even if their proposed changes are approved.
    by_group={row["group_id"]:row for row in records}
    for row in records:
        if row["verdict"]=="EXACT_GROUP_CHANGE_PENDING_AUTHORIZATION":
            unmet=[dep for dep in row["depends_on"]
                   if by_group[dep]["verdict"]!="IN_SYNC"]
            if unmet:
                row["verdict"]="WAITING_FOR_DEPENDENCY"
                row["change_paths"]=[]
                row["unmet_dependencies"]=unmet
    eligible=[r["group_id"] for r in records
              if r["verdict"]=="EXACT_GROUP_CHANGE_PENDING_AUTHORIZATION"]
    blocked=[r["group_id"] for r in records
             if r["verdict"] in ("PRESERVE_LIVE_DRIFT_REVIEW",
                                "CANDIDATE_NOT_APPROVED")]
    waiting=[r["group_id"] for r in records
             if r["verdict"]=="WAITING_FOR_DEPENDENCY"]
    return {
        "contract":"mad4b.host-v129.live-first-reconciliation-plan.v1",
        "current_source":"verified_live_installed_code",
        "current_snapshot_sha256":current.snapshot_sha256,
        "current_nonce":current.snapshot_nonce,
        "site_uuid":binding.site_uuid,
        "desired_source":"approved_signed_per_site_registry",
        "desired_record_sha256":desired.signed_record_sha256,
        "desired_revision":desired.approved_revision,
        "desired_head_sha":desired.source_head_sha,
        "candidate_head_sha":candidate["source_head_sha"],
        "candidate_exactly_matches_desired":candidate_approved,
        "groups":records,
        "eligible_atomic_groups":eligible,
        "blocked_atomic_groups":blocked,
        "dependency_waiting_groups":waiting,
        "all_desired_realized":current.files==desired.files,
        "previous":{
            "source":"current_live_preimage",
            "snapshot_sha256":current.snapshot_sha256,
            "state":"AWAITING_INDEPENDENT_BACKUP",
            "verified":False,
        },
        "requires_independent_source_bytes_verification":True,
        "requires_fresh_target_precommit_readback":True,
        "requires_exact_operation_approval":True,
        "requires_verified_preimage_backup":True,
        "requires_verified_postcommit_readback":True,
        "host_write_authorized":False,
        "automatic_deployment_performed":False,
        "desired_promotion_performed":False,
    }


def propose_live_source_review(current: Current, desired: Desired,
                               binding: SiteBinding) -> dict:
    """Suggest deliberate live-to-source review, never push to Git.

    Any mismatch against the last approved baseline remains an open, explicit
    review; it must be tested, accepted and independently signed as next desired.
    """
    need(current.site_uuid==desired.site_uuid==binding.site_uuid,
         "LIVE_TO_SOURCE_SITE_DRIFT")
    live=current.file_map()
    expected=desired.desired_map()
    need(set(live)==set(expected),"LIVE_TO_SOURCE_FILE_SET_DRIFT")
    base=desired.baseline_map()
    hotfix=[{
        "relative_path":p,
        "live_state":live[p][0],
        "live_sha256":live[p][1],
        "approved_sha256":expected[p][1],
        "approved_base_sha256":base[p][1],
    } for p in sorted(live)
      if live[p]!=base[p] and live[p]!=expected[p]]
    outstanding=[p for p in sorted(live)
                 if live[p]==base[p] and live[p]!=expected[p]]
    return {
        "contract":"mad4b.host-v129.live-to-approved-source-review.v1",
        "site_uuid":binding.site_uuid,
        "current_snapshot_sha256":current.snapshot_sha256,
        "desired_record_sha256":desired.signed_record_sha256,
        "live_divergence":hotfix,
        "desired_pending_paths":outstanding,
        "review_required":bool(hotfix),
        "auto_commit_to_git":False,
        "auto_update_desired":False,
        "auto_deploy":False,
    }

BACKUP_CONTRACT="mad4b.host-v129.host-runner-verified-preimage.v1"
BACKUP_DOMAIN=b"MAD4B_HOST_RUNNER_VERIFIED_PREIMAGE_V1\n"
BACKUP_FIELDS=(
    "contract","company","environment","site_uuid","target_host_id",
    "source_current_snapshot_sha256","source_current_nonce",
    "group_id","files","backup_bundle_sha256","backup_receipt_id",
    "backup_kind","status","verified_epoch",
)
HEX64=re.compile(r"[a-f0-9]{64}\Z")

def canonical_backup(row: dict) -> bytes:
    return BACKUP_DOMAIN+json.dumps(
        {k:row[k] for k in BACKUP_FIELDS},sort_keys=True,
        separators=(",",":"),ensure_ascii=False).encode("utf-8")

def verify_previous_backup(receipt: dict, current: Current,
                           binding: SiteBinding, selected_group: dict,
                           pinned_host_runner_public: bytes, *, now: int) -> dict:
    """Only a signed actual private Host Runner preimage is previous.

    Merely listing old hashes or setting backup_verified=true in a request
    is not evidence. The registered Host Runner is responsible for reading
    the actual backup bytes and attesting their sealed content.
    """
    need(type(receipt) is dict
         and set(receipt)==set(BACKUP_FIELDS)|{"signature"}
         and receipt.get("contract")==BACKUP_CONTRACT
         and receipt.get("status")=="SEALED_AND_HASH_VERIFIED"
         and receipt.get("backup_kind")=="host_private_rollback_store",
         "PREVIOUS_BACKUP_RECEIPT_SHAPE_INVALID")
    need(type(pinned_host_runner_public) is bytes
         and len(pinned_host_runner_public)==32,
         "PREVIOUS_BACKUP_TRUSTED_SIGNER_REQUIRED")
    need(all(receipt[k]==v for k,v in (
         ("company",binding.company),("environment",binding.environment),
         ("site_uuid",binding.site_uuid),("target_host_id",binding.host_id),
         ("source_current_snapshot_sha256",current.snapshot_sha256),
         ("source_current_nonce",current.snapshot_nonce),
         ("group_id",selected_group["group_id"]))),
         "PREVIOUS_BACKUP_PREIMAGE_OR_SCOPE_MISMATCH")
    expected=set(selected_group["paths"])
    originals=current.file_map()
    backup_files=validate_files(receipt["files"])
    need({p for p,*_ in backup_files}==expected
         and all(originals[p]==(state,sha,size)
                 for p,state,sha,size in backup_files),
         "PREVIOUS_BACKUP_CONTENT_DRIFT")
    need(type(receipt["backup_bundle_sha256"]) is str
         and HEX64.fullmatch(receipt["backup_bundle_sha256"]) is not None
         and type(receipt["backup_receipt_id"]) is str
         and HEX32.fullmatch(receipt["backup_receipt_id"]) is not None
         and type(receipt["verified_epoch"]) is int
         and current.observed_epoch<=receipt["verified_epoch"]<=now+5,
         "PREVIOUS_BACKUP_NOT_FRESH_OR_PINNED")
    need(type(receipt.get("signature")) is str,
         "PREVIOUS_BACKUP_SIGNATURE_INVALID")
    try:
        sig=base64.b64decode(receipt["signature"],validate=True)
        need(len(sig)==64,"PREVIOUS_BACKUP_SIGNATURE_INVALID")
        Ed25519PublicKey.from_public_bytes(pinned_host_runner_public).verify(
            sig,canonical_backup(receipt))
    except (InvalidSignature,ValueError,TypeError,KeyError) as exc:
        raise ConnectionDenied("PREVIOUS_BACKUP_SIGNATURE_INVALID") from exc
    return {
        "source":"independently_signed_actual_host_runner_backup",
        "snapshot_sha256":current.snapshot_sha256,
        "group_id":selected_group["group_id"],
        "backup_receipt_id":receipt["backup_receipt_id"],
        "backup_bundle_sha256":receipt["backup_bundle_sha256"],
        "verified":True,
    }

def prepare_atomic_group_operation(current: Current, desired: Desired,
                                   candidate: dict, binding: SiteBinding,
                                   group_id: str, backup_receipt: dict,
                                   site_reader, pinned_source_pub: bytes,
                                   expected_origin: str, *, now: int) -> dict:
    """CAS-bound preflight; does not generate an executable host grant.

    Must re-read current from the real site AFTER the backup. Executor must
    perform another atomic current preimage check at the actual commit
    boundary. No PR/CI may stand in for signed site evidence.
    """
    initial=reconcile(current,desired,candidate,binding)
    eligible={x["group_id"]:x for x in initial["groups"]
              if x["verdict"]=="EXACT_GROUP_CHANGE_PENDING_AUTHORIZATION"}
    need(group_id in eligible,"ATOMIC_GROUP_NOT_ELIGIBLE")
    group=eligible[group_id]
    previous=verify_previous_backup(
        backup_receipt,current,binding,group,pinned_source_pub,now=now)
    from live_current_source import read_current
    fresh=read_current(binding,site_reader,pinned_source_pub,
                       expected_origin,now=now)
    need(fresh.snapshot_nonce!=current.snapshot_nonce
         and fresh.observed_epoch>=current.observed_epoch
         and fresh.files==current.files,
         "PRECOMMIT_LIVE_CURRENT_CHANGED_OR_NOT_FRESH")
    refreshed=reconcile(fresh,desired,candidate,binding)
    need(group_id in refreshed["eligible_atomic_groups"],
         "PRECOMMIT_ATOMIC_GROUP_NO_LONGER_ELIGIBLE")
    return {
        "contract":"mad4b.host-v129.atomic-group-execution-proposal.v1",
        "site_uuid":binding.site_uuid,
        "company":binding.company,
        "environment":binding.environment,
        "group_id":group_id,
        "paths":group["paths"],
        "current_sha256":fresh.snapshot_sha256,
        "initial_current_sha256":current.snapshot_sha256,
        "desired_sha256":desired.signed_record_sha256,
        "source_commit_sha":desired.source_head_sha,
        "candidate_exact":True,
        "previous":previous,
        "executor_operation_required":"mad4b.host-v129.atomic-group-install",
        "executor_must_recheck_live_preimage_at_commit":True,
        "exact_owner_or_operator_signed_grant_required":True,
        "host_write_authorized":False,
        "operation_executed":False,
        "desired_promoted":False,
    }
