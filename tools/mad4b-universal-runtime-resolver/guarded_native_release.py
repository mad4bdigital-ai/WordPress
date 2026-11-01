"""Staging-only release coordinator: exact sources + one site lock + original operator.

This module never executes shell commands, mints a grant, or acts as a release
authority. The caller supplies the *existing* registered native executor,
which must independently admit/apply the exact immutable operation and
produce an independently verified execution receipt. No fallback after apply.
"""
from __future__ import annotations
import fcntl
import hashlib
import json
import os
from pathlib import Path
import re
import stat

import control_plane_release_preflight as native
import source_drift_guard as drift

CONTRACT="mad4b.central-guarded-staging-release.v1"
UUID=re.compile(r"^[a-f0-9-]{36}$")

class GuardedReleaseError(RuntimeError):pass

def secure_lock_directory(directory):
    st=directory.lstat()
    if (not stat.S_ISDIR(st.st_mode) or st.st_uid!=0
            or stat.S_IMODE(st.st_mode)!=0o700):
        raise GuardedReleaseError("UNTRUSTED_RELEASE_LOCK_DIRECTORY")

def acquire(lock_directory, site_uuid):
    if not isinstance(site_uuid,str) or not UUID.fullmatch(site_uuid):
        raise GuardedReleaseError("INVALID_SITE_UUID")
    secure_lock_directory(lock_directory)
    # Stable identity-derived lock key; never accept an arbitrary lock filename.
    name="mad4b-staging-release-"+hashlib.sha256(site_uuid.encode()).hexdigest()[:32]+".lock"
    fd=os.open(lock_directory/name,os.O_CREAT|os.O_RDWR|os.O_NOFOLLOW|os.O_CLOEXEC,0o600)
    try:
        st=os.fstat(fd)
        if (not stat.S_ISREG(st.st_mode) or st.st_uid!=0
                or stat.S_IMODE(st.st_mode)!=0o600):
            raise GuardedReleaseError("UNTRUSTED_RELEASE_LOCK_FILE")
        try:
            fcntl.flock(fd,fcntl.LOCK_EX|fcntl.LOCK_NB)
        except BlockingIOError as error:
            raise GuardedReleaseError("SITE_RELEASE_ALREADY_ACTIVE") from error
        return fd
    except BaseException:
        os.close(fd)
        raise

def immutable_candidate(artifact,expected_sha):
    if not isinstance(expected_sha,str) or not re.fullmatch("[0-9a-f]{64}",expected_sha):
        raise GuardedReleaseError("RELEASE_ARCHIVE_PIN_INVALID")
    if drift.sha(artifact.read_bytes())!=expected_sha:
        raise GuardedReleaseError("RELEASE_ARCHIVE_HASH_CHANGED")

def checked_payload(plan, root, original_source, archive, new_source, approved_delta):
    if (not isinstance(plan,dict) or plan.get("environment")!="staging"
            or plan.get("production_mutation") is not False
            or plan.get("authorization")!="NOT_GRANTED_BY_THIS_PLAN"):
        raise GuardedReleaseError("NOT_EXACT_STAGING_NONAUTHORIZING_PLAN")
    if plan.get("source_commit_sha")!=new_source or plan.get("candidate_archive_sha256") is None:
        raise GuardedReleaseError("CANDIDATE_RELEASE_PLAN_MISMATCH")
    immutable_candidate(archive,plan["candidate_archive_sha256"])
    installed=drift.read_root(root)
    drift.verify(installed,original_source)
    proposed=drift.read_archive(archive)
    new=drift.verify(proposed,new_source)
    if new["manifest"]!=plan.get("manifest_digest"):
        raise GuardedReleaseError("CANDIDATE_MANIFEST_PLAN_MISMATCH")
    changes=drift.approved_delta(installed,proposed,original_source,new_source,approved_delta)
    return {"approved_changes":changes,"candidate_manifest":new["manifest"]}

def guarded_native_release(*, site, plan, source, grant, executor,
                           root, original_source, archive, new_source,
                           review, lock_directory, native_operator):
    """Return VERIFIED only for one original authorized, executed native release.

    Original operator API: admit(plan), apply(plan), verify(plan, receipt).
    The original operator owns rollback and immutable grant validation;
    it must NOT auto-replay an attempted operation.
    """
    if not isinstance(site,dict) or site.get("environment")!="staging":
        raise GuardedReleaseError("STAGING_SITE_REQUIRED")
    site_uuid=site.get("site_uuid")
    if plan.get("site_uuid")!=site_uuid or plan.get("target_site")!=site.get("canonical_origin"):
        raise GuardedReleaseError("SITE_SCOPE_CHANGED")
    if not all(callable(getattr(native_operator,op,None)) for op in ("admit","apply","verify")):
        raise GuardedReleaseError("ORIGINAL_NATIVE_OPERATOR_REQUIRED")
    fd=acquire(Path(lock_directory),site_uuid)
    attempted=False
    try:
        pre=native.preflight(plan,site,source,grant,executor)
        if pre.get("can_submit_to_native_operator") is not True:
            raise GuardedReleaseError("NATIVE_RELEASE_PREFLIGHT_BLOCKED")
        delta=checked_payload(plan,Path(root),original_source,Path(archive),new_source,review)
        # Never use the review-only preflight as operation authorization.
        authority=native_operator.admit(plan)
        if (not isinstance(authority,dict) or authority.get("state")!="AUTHORIZED"
                or authority.get("site_uuid")!=site_uuid
                or authority.get("archive_sha256")!=plan["candidate_archive_sha256"]
                or authority.get("original_operation_verified") is not True):
            raise GuardedReleaseError("ORIGINAL_OPERATION_GRANT_DENIED")
        # This exact call is the single execution attempt. Do not retry/fallback.
        attempted=True
        receipt=native_operator.apply(plan)
        if (not native.verify_external_readback(plan,receipt)
                or native_operator.verify(plan,receipt) is not True):
            raise GuardedReleaseError("EXECUTION_ATTEMPTED_UNVERIFIED_REVIEW_REQUIRED")
        # Source file bytes and package manifest are verified independently
        # under the same lock, after native original readback.
        final=drift.verify(drift.read_root(Path(root)),new_source)
        if final["manifest"]!=plan["manifest_digest"]:
            raise GuardedReleaseError("POST_INSTALL_SOURCE_MISMATCH_REVIEW_REQUIRED")
        return {"contract":CONTRACT,"state":"VERIFIED","site_uuid":site_uuid,
                "source":new_source,"approved_changes":delta["approved_changes"],
                "native_execution_receipt_verified":True,
                "post_install_manifest_verified":True,"production_mutation":False}
    finally:
        # The native operator, never this wrapper, owns any rollback.
        # After execution uncertainty, the request must remain parked
        # for independent readback, not switch to a secondary transport.
        fcntl.flock(fd,fcntl.LOCK_UN)
        os.close(fd)
