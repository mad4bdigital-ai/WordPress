"""Fleet-wide current = installed LIVE site code, never Git HEAD by inference.

Read-only signed Host Runner snapshot contract. This module makes the
distinction between the current installed source and an uninstalled candidate
explicit. It issues no deployment authority, SSH commands or WordPress writes.
"""
from __future__ import annotations

import base64
import hashlib
import json
import re
from dataclasses import dataclass
from typing import Callable
from cryptography.hazmat.primitives.asymmetric.ed25519 import Ed25519PublicKey
from cryptography.exceptions import InvalidSignature
from fleet_policy import SiteBinding
from persistent_enrollment import ConnectionDenied, need

CONTRACT = "mad4b.host-v128.live-current-source.v1"
CANDIDATE_CONTRACT = "mad4b.host-v128.site-candidate.v1"
DOMAIN = b"MAD4B_HOST_LIVE_CURRENT_SOURCE_V1\n"
FIELDS = ("contract", "company", "environment", "site_uuid", "target_host_id",
          "host_key_sha256", "policy_sha256", "origin", "observed_epoch",
          "snapshot_nonce", "read_only", "state", "files")
HEX64 = re.compile(r"[a-f0-9]{64}\Z")
HEX32 = re.compile(r"[a-f0-9]{32}\Z")
HEX40 = re.compile(r"[a-f0-9]{40}\Z")
# Code locations only. Not wp-config.php, uploads, media, .env, DB, keys,
# or arbitrary theme/vendor plugin paths. WordPress MU v128 is additive.
HOST_V3_PHP = frozenset((
    "wp-content/mu-plugins/410-mad4b-original318-v3-readback.php",
    "wp-content/mu-plugins/class-mad4b-original318-host-v3-reader.php",
))
PATH = re.compile(r"[a-zA-Z0-9._/-]+\Z")
MAX_FILES = 200

@dataclass(frozen=True)
class Current:
    site_uuid: str
    snapshot_sha256: str
    observed_epoch: int
    snapshot_nonce: str
    files: tuple[tuple[str, str, str, int], ...]
    # Each entry is (relative_path, PRESENT/ABSENT, sha256/ABSENT, size).
    # A Current snapshot can be compared, but never grants a mutation.

    def file_map(self):
        return {p:(state,digest,size) for p,state,digest,size in self.files}

def canonical(row: dict) -> bytes:
    return DOMAIN + json.dumps(
        {k:row[k] for k in FIELDS},sort_keys=True,
        separators=(",",":"),ensure_ascii=False).encode("utf-8")

def code_path(path: str) -> bool:
    if type(path) is not str or not 3 <= len(path) <= 250:
        return False
    if not PATH.fullmatch(path) or path.startswith("/") or "\\" in path:
        return False
    if any(part in ("", ".", "..") for part in path.split("/")):
        return False
    if path in HOST_V3_PHP:
        return True
    # Existing MAD4B plugin code may be used as CURRENT for surgical
    # comparisons; this does not authorize updates to those files.
    return path.startswith("wp-content/plugins/mad4b-") and (
        "/.." not in path and path.endswith((".php",".js",".css",".json"))
    ) and len(path.split("/")) >= 4

def validate_files(files: object) -> tuple[tuple[str,str,str,int],...]:
    need(type(files) is list and 1 <= len(files) <= MAX_FILES,
         "CURRENT_FILE_SET_INVALID")
    ordered=[]
    for file in files:
        need(type(file) is dict and set(file)=={
             "relative_path","state","sha256","size_bytes"},
             "CURRENT_FILE_ENTRY_INVALID")
        path=file["relative_path"]
        state=file["state"]
        sha=file["sha256"]
        size=file["size_bytes"]
        need(code_path(path),"CURRENT_SOURCE_PATH_NOT_ALLOWED")
        need(state in ("PRESENT","ABSENT"),"CURRENT_FILE_STATE_INVALID")
        need(type(size) is int and 0 <= size <= 100_000_000,
             "CURRENT_FILE_SIZE_INVALID")
        if state=="ABSENT":
            need(sha=="ABSENT" and size==0,"CURRENT_ABSENT_PREIMAGE_INVALID")
        else:
            need(type(sha) is str and HEX64.fullmatch(sha) is not None,
                 "CURRENT_FILE_SHA_INVALID")
        ordered.append((path,state,sha,size))
    need(ordered==sorted(ordered) and len({x[0] for x in ordered})==len(ordered),
         "CURRENT_FILES_DUPLICATED_OR_UNSORTED")
    return tuple(ordered)

def verify_current_receipt(row: dict, binding: SiteBinding,
                           source_pub: bytes, expected_origin: str, *,
                           now: int) -> Current:
    """Signed first-party readback; caller must pin the public Host Runner key.

    Never trust a WordPress option, Git checkout, or client-provided signing key.
    """
    need(type(row) is dict and set(row)==set(FIELDS)|{"signature"}
         and row.get("contract")==CONTRACT and row.get("read_only") is True
         and row.get("state")=="VERIFIED_LIVE_INSTALLED_CODE",
         "LIVE_CURRENT_SIGNED_SHAPE_INVALID")
    need(type(source_pub) is bytes and len(source_pub)==32,
         "LIVE_CURRENT_PINNED_SIGNER_REQUIRED")
    need(type(expected_origin) is str and expected_origin.startswith("https://")
         and row["origin"]==expected_origin,
         "LIVE_CURRENT_ORIGIN_DRIFT")
    need(row["company"]==binding.company
         and row["environment"]==binding.environment
         and row["site_uuid"]==binding.site_uuid
         and row["target_host_id"]==binding.host_id
         and row["host_key_sha256"]==binding.host_key_sha256
         and row["policy_sha256"]==binding.policy_sha256,
         "LIVE_CURRENT_SITE_BINDING_MISMATCH")
    need(type(row["observed_epoch"]) is int
         and type(now) is int
         and now-120 <= row["observed_epoch"] <= now+5
         and type(row["snapshot_nonce"]) is str
         and HEX32.fullmatch(row["snapshot_nonce"]) is not None,
         "LIVE_CURRENT_STALE_OR_REPLAYABLE")
    files=validate_files(row["files"])
    need(type(row["signature"]) is str,"LIVE_CURRENT_SIGNATURE_INVALID")
    try:
        signature=base64.b64decode(row["signature"],validate=True)
        need(len(signature)==64,"LIVE_CURRENT_SIGNATURE_INVALID")
        Ed25519PublicKey.from_public_bytes(source_pub).verify(
            signature,canonical(row))
    except (InvalidSignature, ValueError, KeyError, TypeError) as exc:
        raise ConnectionDenied("LIVE_CURRENT_SIGNATURE_INVALID") from exc
    return Current(
        site_uuid=binding.site_uuid,
        snapshot_sha256=hashlib.sha256(canonical(row)).hexdigest(),
        observed_epoch=row["observed_epoch"],
        snapshot_nonce=row["snapshot_nonce"],
        files=files,
    )

def read_current(binding: SiteBinding, reader: Callable[[SiteBinding],dict],
                 pinned_source_pub: bytes, expected_origin: str, *,
                 now: int) -> Current:
    need(callable(reader),"LIVE_HOST_RUNNER_READER_REQUIRED")
    # The reader is first-party and must retrieve bytes/hash from the exact
    # installed site, not a cached Git export or an old staging backup.
    row=reader(binding)
    return verify_current_receipt(
        row,binding,pinned_source_pub,expected_origin,now=now)

def plan_candidate(current: Current, candidate: dict, binding: SiteBinding) -> dict:
    """Pure preimage-pinned plan. Review != authorization, install or promotion."""
    need(type(candidate) is dict
         and set(candidate)=={"contract","company","environment","site_uuid",
                             "target_host_id","source_head_sha","files"}
         and candidate["contract"]==CANDIDATE_CONTRACT
         and candidate["company"]==binding.company
         and candidate["environment"]==binding.environment
         and candidate["site_uuid"]==current.site_uuid==binding.site_uuid
         and candidate["target_host_id"]==binding.host_id
         and type(candidate["source_head_sha"]) is str
         and HEX40.fullmatch(candidate["source_head_sha"]) is not None,
         "CANDIDATE_SCOPE_OR_SOURCE_INVALID")
    target=validate_files(candidate["files"])
    original=current.file_map()
    need(set(original)=={row[0] for row in target},
         "CANDIDATE_MUST_HAVE_LIVE_PREIMAGE_FOR_ALL_FILES")
    changes=[]
    for path,state,sha,size in target:
        need(state=="PRESENT","CANDIDATE_CAN_ONLY_INSTALL_CODE")
        before_state,before_sha,before_bytes=original[path]
        if before_sha!=sha:
            changes.append({
                "relative_path":path,
                "current_state":before_state,
                "current_sha256":before_sha,
                "current_size_bytes":before_bytes,
                "candidate_sha256":sha,
                "candidate_size_bytes":size,
            })
    return {
        "contract":"mad4b.host-v128.live-current-candidate-plan.v1",
        "site_uuid":binding.site_uuid,
        "current":{
            "source":"verified_live_installed_code",
            "snapshot_sha256":current.snapshot_sha256,
            "observed_epoch":current.observed_epoch,
            "snapshot_nonce":current.snapshot_nonce,
        },
        "candidate":{
            "source":"exact_git_candidate_not_installed",
            "source_head_sha":candidate["source_head_sha"],
        },
        "changes":changes,
        "change_count":len(changes),
        "previous":{
            "source":"current_live_preimage",
            "snapshot_sha256":current.snapshot_sha256,
            "files":[{
                "relative_path":path,
                "state":state,
                "sha256":sha,
                "size_bytes":size,
            } for path,state,sha,size in current.files],
            "backup_verified":False,
            "state":"AWAITING_HOST_RUNNER_BACKUP",
        },
        "requires_precommit_fresh_current_readback":True,
        "requires_exact_owner_or_operator_approval":True,
        "requires_site_specific_rollback_backup":True,
        "host_write_authorized":False,
        "deployment_performed":False,
    }

def confirm_new_current(old: Current, candidate: dict, binding: SiteBinding,
                        reader: Callable[[SiteBinding],dict],
                        source_pub: bytes, origin: str, *, now: int) -> Current:
    """Only post-write LIVE signed readback changes the current variable.

    It does not claim the deployment itself was authorized. The independent
    executor receipt is a separate required operational completion artifact.
    """
    plan_candidate(old,candidate,binding)
    updated=read_current(binding,reader,source_pub,origin,now=now)
    need(updated.snapshot_nonce!=old.snapshot_nonce
         and updated.observed_epoch>=old.observed_epoch,
         "POSTWRITE_CURRENT_NOT_FRESH")
    expected=validate_files(candidate["files"])
    need(updated.files==expected,
         "POSTWRITE_LIVE_SOURCE_NOT_EQUAL_CANDIDATE")
    return updated
