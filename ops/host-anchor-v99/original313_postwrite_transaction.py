#!/usr/bin/env python3
"""Original313 root post-write transaction / crash recovery.

This is a purpose-separated ROOT-only finalizer, *not* an endpoint, issuer,
Owner authorization or Original306/312 dispatcher. A Broker-signed ledger
event, a genuine claimed Job, and a fresh signed Original318 readback must
all match BEFORE the independent Original313 signer produces a checkpoint.
The operation is serialized and retryable. A partially committed ledger
without its independent checkpoint is never authority.
"""
from __future__ import annotations
import base64
import fcntl
import hashlib
import importlib.util
import json
import os
from pathlib import Path
import re
import stat
import time
from cryptography.hazmat.primitives.serialization import Encoding, PublicFormat

GATE_PATH = Path("/opt/mad4b-company-transport/native-313-independent/"
                 "host-authority-v83/v81/current_revision_gate.py")
GATE_SHA256 = "8d45990df6a1cdc75c2f3399f4490eea2c775466cc5de104f73c97d6903b2dd6"
ROOT = Path("/var/lib/mad4b/original-host-v2")
ISSUE = "company.host-authority.anchor.issue"
REVOKE = "company.host-authority.anchor.revoke"
RECEIPT = "mad4b.original313.host-anchor-final-receipt.v1"
RECEIPT_FIELDS = ("contract","job_id","operation_id","company","environment",
                  "site_uuid","revision","anchor_nonce","state","event_sha256",
                  "checkpoint_sha256","wp_signed_message_sha256","issued_epoch")
PREWRITE_CONTRACT = "mad4b.original313.host-anchor-write-admission.v1"
PREWRITE_FIELDS = ("contract","job_id","operation_id","company","environment",
    "site_uuid","plan_sha256","command_sha256","grant_id",
    "anchor_revision_before","anchor_revision_after","anchor_nonce",
    "anchor_message_sha256","issued_epoch","expires_epoch")
HEX32 = re.compile(r"[a-f0-9]{32}\Z")
HEX64 = re.compile(r"[a-f0-9]{64}\Z")

class TransactionHeld(ValueError):
    pass

def require(ok,reason):
    if not ok:raise TransactionHeld(reason)

def load_gate():
    require(GATE_PATH.is_file() and not GATE_PATH.is_symlink()
            and hashlib.sha256(GATE_PATH.read_bytes()).hexdigest()==GATE_SHA256,
            "ORIGINAL313_EXACT_GATE_PIN_CHANGED")
    sp=importlib.util.spec_from_file_location("_original313_v81_signed_gate",GATE_PATH)
    require(sp is not None and sp.loader is not None,"ORIGINAL313_GATE_IMPORT_INVALID")
    module=importlib.util.module_from_spec(sp)
    sp.loader.exec_module(module)
    return module

def canonical(row):
    return json.dumps(row,sort_keys=True,separators=(",",":"),
                      ensure_ascii=False).encode()

def file_digest(path):
    return hashlib.sha256(path.read_bytes()).hexdigest()

class PrivateStore:
    def __init__(self,root=ROOT):
        self.root=Path(root)
        require(self.root.is_absolute() and not self.root.is_symlink()
                and self.root.is_dir(), "ORIGINAL313_PRIVATE_ROOT_ABSENT")
        st=self.root.stat()
        require(st.st_uid==0 and st.st_gid==0
                and stat.S_IMODE(st.st_mode)==0o700,
                "ORIGINAL313_PRIVATE_ROOT_NOT_ENROLLED")
        for folder in ("enrollments","revisions","checkpoints","history","transactions","receipts"):
            p=self.root/folder
            if p.exists():
                require(not p.is_symlink() and p.is_dir()
                        and p.stat().st_uid==0 and p.stat().st_gid==0
                        and stat.S_IMODE(p.stat().st_mode)==0o700,
                        "ORIGINAL313_PRIVATE_STORE_UNTRUSTED")
            else:
                p.mkdir(mode=0o700)
    def path(self,kind,name):
        require(kind in ("enrollments","revisions","checkpoints","history","transactions","receipts")
                and bool(re.fullmatch(r"[a-z0-9][a-z0-9-]{2,95}\.json",name)),
                "ORIGINAL313_SCOPE_PATH_INVALID")
        return self.root/kind/name
    @staticmethod
    def read(path):
        if not path.exists():
            require(not path.is_symlink(),"ORIGINAL313_SYMLINKED_MISSING_DOCUMENT")
            return None
        st=path.lstat()
        require(not path.is_symlink() and stat.S_ISREG(st.st_mode)
                and st.st_uid==0 and st.st_gid==0 and st.st_nlink==1
                and stat.S_IMODE(st.st_mode)==0o600
                and 0<st.st_size<32000,"ORIGINAL313_UNTRUSTED_DOCUMENT")
        out=json.loads(path.read_bytes())
        require(type(out) is dict,"ORIGINAL313_DOCUMENT_NOT_AN_OBJECT")
        return out
    @staticmethod
    def write_new(path,row):
        data=canonical(row)+b"\n"
        fd=os.open(path,os.O_CREAT|os.O_EXCL|os.O_WRONLY|os.O_NOFOLLOW,0o600)
        with os.fdopen(fd,"wb") as f:
            f.write(data);f.flush();os.fsync(f.fileno())
        d=os.open(path.parent,os.O_DIRECTORY|os.O_RDONLY)
        try:os.fsync(d)
        finally:os.close(d)
    @staticmethod
    def replace_verified(path,row,previous):
        before=PrivateStore.read(path)
        require(before==previous,"ORIGINAL313_HIGHWATER_CHANGED_CONCURRENTLY")
        import tempfile
        fd,tmp=tempfile.mkstemp(prefix=".signed-highwater-",dir=path.parent)
        try:
            os.fchmod(fd,0o600)
            with os.fdopen(fd,"wb") as f:
                f.write(canonical(row)+b"\n");f.flush();os.fsync(f.fileno())
            require(PrivateStore.read(path)==previous,
                    "ORIGINAL313_HIGHWATER_CAS_CONFLICT")
            os.replace(tmp,path)
            d=os.open(path.parent,os.O_DIRECTORY|os.O_RDONLY)
            try:os.fsync(d)
            finally:os.close(d)
        finally:
            if os.path.exists(tmp):os.unlink(tmp)

def load_source(path, expected, name):
    require(path.is_file() and not path.is_symlink()
            and hashlib.sha256(path.read_bytes()).hexdigest()==expected,
            "ORIGINAL313_FIRST_PARTY_SOURCE_CHANGED")
    spec=importlib.util.spec_from_file_location(name,path)
    require(spec is not None and spec.loader is not None,
            "ORIGINAL313_FIRST_PARTY_SOURCE_UNAVAILABLE")
    obj=importlib.util.module_from_spec(spec)
    spec.loader.exec_module(obj)
    return obj


ENROLLMENT_VERIFIER = Path(
    "/opt/mad4b-company-transport/native-313-independent/"
    "host-authority-v89/first_anchor_original312.py")
ENROLLMENT_SHA256 = "78d435b833cd91e409d2753a9086064266263eec79e431a3ffacbf7acce299f4"
BOOTSTRAP_VERIFIER = Path(
    "/opt/mad4b-company-transport/native-313-independent/"
    "dynamic-operation-registry-v1/enrolled/host-anchor-v2/bootstrap_signed_job.py")
BOOTSTRAP_SHA256 = "addef3e2693b5e87b2e8755f23bcc5d7fe8eeab0460640c1bc52e541340c61ed"


def reserve_signed_prewrite(job, enrollment, bootstrap, approval,
                            broker_pub, independent_pub, *, store=None, now=None):
    """Proof-verified, immutable prewrite intent, never a write permission.

    The fixed first-party caller must supply the genuine claimed Original306
    Job, independent-signer-backed Enrollment, Broker bootstrap signature,
    and independently signed approval. This function signs nothing.
    """
    from cryptography.hazmat.primitives.asymmetric.ed25519 import Ed25519PublicKey
    from cryptography.exceptions import InvalidSignature
    instant=int(time.time()) if now is None else now
    require(type(job) is dict and type(enrollment) is dict
            and type(bootstrap) is dict and type(approval) is dict,
            "ORIGINAL313_INDEPENDENT_PREWRITE_EVIDENCE_REQUIRED")
    require(type(broker_pub) is bytes and len(broker_pub)==32
            and type(independent_pub) is bytes and len(independent_pub)==32
            and broker_pub != independent_pub,
            "ORIGINAL313_DISTINCT_SIGNER_PINS_REQUIRED")
    require(job.get("operation_id")==ISSUE
            and job.get("company")=="all-royal-egypt"
            and job.get("environment")=="staging"
            and job.get("target_host_id")=="hosting-2"
            and type(job.get("job_id")) is str
            and HEX32.fullmatch(job["job_id"]) is not None
            and job.get("state")=="BROKER_CLAIMED_PENDING_INDEPENDENT_HOST_AUTHORITY"
            and job.get("job_type")=="signed_remote_hostinger_wpcli_v2"
            and job.get("principal_type")=="signed_host_principal_v2"
            and job.get("executed") is False
            and type(job.get("expires_epoch")) is int
            and job["expires_epoch"]>=instant+20,
            "ORIGINAL313_REAL_FIRST_CLAIMED_JOB_REQUIRED")
    scope="all-royal-egypt--staging.json"
    store=PrivateStore() if store is None else store
    signed_enrollment=store.read(store.path("enrollments",scope))
    require(signed_enrollment is not None and signed_enrollment==enrollment,
            "ORIGINAL313_ENROLLED_FIRST_PARTY_OWNER_PROOF_REQUIRED")
    verification=load_source(ENROLLMENT_VERIFIER,ENROLLMENT_SHA256,
                             "_original313_v89_enrollment")
    verified=verification.verify_enrollment(signed_enrollment,independent_pub,
                                            job,now=instant)
    claimed=load_source(BOOTSTRAP_VERIFIER,BOOTSTRAP_SHA256,
                        "_original313_original312_bootstrap")
    observed={**verified,"contract":"mad4b.original-host-enrollment-authority.v1"}
    proof=claimed.verify_bootstrap_job(bootstrap,broker_pub,job,observed,now=instant)
    require(proof.get("bootstrap_signature_verified") is True
            and proof.get("execution_authorized") is False,
            "ORIGINAL313_BROKER_BOOTSTRAP_PROOF_REQUIRED")
    require(set(approval)==set(PREWRITE_FIELDS)|{"signature"}
            and approval.get("contract")==PREWRITE_CONTRACT
            and approval.get("job_id")==job["job_id"]
            and approval.get("operation_id")==ISSUE
            and approval.get("company")==job["company"]
            and approval.get("environment")==job["environment"]
            and approval.get("site_uuid")==job["site_uuid"]
            and approval.get("plan_sha256")==job["plan_sha256"]
            and approval.get("command_sha256")==job["command_sha256"]
            and approval.get("grant_id")==job["grant_id"]
            and approval.get("anchor_revision_before")==0
            and approval.get("anchor_revision_after")==1
            and type(approval.get("anchor_nonce")) is str
            and HEX32.fullmatch(approval["anchor_nonce"]) is not None
            and type(approval.get("anchor_message_sha256")) is str
            and HEX64.fullmatch(approval["anchor_message_sha256"]) is not None
            and type(approval.get("issued_epoch")) is int
            and instant-60<=approval["issued_epoch"]<=instant+10
            and type(approval.get("expires_epoch")) is int
            and instant<approval["expires_epoch"]<=approval["issued_epoch"]+120,
            "ORIGINAL313_PREWRITE_SCOPE_OR_LIFETIME_INVALID")
    message=(b"MAD4B_ORIGINAL313_HOST_ANCHOR_WRITE_V1\n"
       +canonical({name:approval[name] for name in PREWRITE_FIELDS}))
    try:
        signed=base64.b64decode(approval["signature"],validate=True)
        require(len(signed)==64,"ORIGINAL313_PREWRITE_SIGNATURE_SIZE_INVALID")
        Ed25519PublicKey.from_public_bytes(independent_pub).verify(signed,message)
    except (ValueError,TypeError,InvalidSignature) as exc:
        raise TransactionHeld("ORIGINAL313_PREWRITE_SIGNATURE_INVALID") from exc
    immutable={
       "contract":PREWRITE_CONTRACT,
       "job_id":job["job_id"],"operation_id":ISSUE,
       "company":job["company"],"environment":job["environment"],
       "site_uuid":job["site_uuid"],
       "anchor_revision_after":1,
       "anchor_nonce":approval["anchor_nonce"],
       "anchor_message_sha256":approval["anchor_message_sha256"],
       "plan_sha256":job["plan_sha256"],
       "command_sha256":job["command_sha256"],
       "grant_id":job["grant_id"],
       "enrollment_sha256":hashlib.sha256(canonical(enrollment)).hexdigest(),
       "bootstrap_sha256":hashlib.sha256(canonical(bootstrap)).hexdigest(),
       "approval_sha256":hashlib.sha256(canonical(approval)).hexdigest(),
       "signed_approval":approval,
       "reserved_epoch":instant,
    }
    path=store.path("transactions",job["job_id"]+".json")
    fd=os.open(store.root/".original313-txn.lock",
               os.O_CREAT|os.O_RDWR|os.O_NOFOLLOW,0o600)
    try:
        fcntl.flock(fd,fcntl.LOCK_EX)
        require(store.read(store.path("revisions",scope)) is None
                and store.read(store.path("checkpoints",scope)) is None,
                "ORIGINAL313_FIRST_REVISION_ALREADY_EXISTS")
        # Serialize first-reservation admission across different Job IDs.
        # Two concurrent prewrite approvals cannot both become the first.
        others=list((store.root/"transactions").glob("*.json"))
        require(len(others)<=256,
                "ORIGINAL313_PENDING_FIRST_RESERVATIONS_UNBOUNDED")
        for pending in others:
            if pending != path:
                row=store.read(pending)
                require(row.get("company")!=job["company"]
                        or row.get("environment")!=job["environment"],
                        "ORIGINAL313_DIFFERENT_FIRST_JOB_ALREADY_RESERVED")
        recorded=store.read(path)
        require(recorded is None or recorded==immutable,
                "ORIGINAL313_PREWRITE_RESERVATION_REPLAY_CHANGED")
        if recorded is None:store.write_new(path,immutable)
    finally:
        fcntl.flock(fd,fcntl.LOCK_UN)
        os.close(fd)
    return {"state":"ORIGINAL313_SIGNED_PREWRITE_RESERVED",
            "job_id":job["job_id"],"anchor_issued":False,
            "original313_certified":False}


def finalize(job,broker_signed_event,wp,broker_pub,independent_private,
             *,store=None,now=None):
    """Only call with fresh Original306 & Original318 first-party readbacks.

    This function does NOT fetch or invent a Job, create an Anchor, sign an
    original312 operation or authorize a grant. A Root caller must load
    originals from installed sources, never untrusted REST/CLI request data.
    """
    now=int(time.time()) if now is None else now
    gate=load_gate()
    require(type(job) is dict and type(wp) is dict
            and type(broker_signed_event) is dict,
            "ORIGINAL313_REAL_ORIGINALS_REQUIRED")
    require(type(broker_pub) is bytes and len(broker_pub)==32,
            "ORIGINAL313_BROKER_PUBLIC_KEY_REQUIRED")
    independent_pub=independent_private.public_key().public_bytes(Encoding.Raw,PublicFormat.Raw)
    require(type(independent_pub) is bytes and len(independent_pub)==32
            and broker_pub!=independent_pub,
            "ORIGINAL313_DISTINCT_INDEPENDENT_KEY_REQUIRED")
    event=broker_signed_event
    gate.sigcheck(event,gate.FIELDS,gate.LEDGER,
                  "MAD4B_ORIGINAL_HOST_LEDGER_V1",broker_pub,now,historical=True)
    op=job.get("operation_id")
    require(op in (ISSUE,REVOKE) and job.get("job_id")==event.get("event_id")
            and type(job.get("job_id")) is str
            and HEX32.fullmatch(job["job_id"]) is not None
            and job.get("state")=="BROKER_CLAIMED_PENDING_INDEPENDENT_HOST_AUTHORITY"
            and job.get("job_type")=="signed_remote_hostinger_wpcli_v2"
            and job.get("principal_type")=="signed_host_principal_v2"
            and job.get("executed") is False
            and all(job.get(k)==event.get(k) for k in
                    ("company","environment","site_uuid","target_host_id","ssh_principal")),
            "ORIGINAL313_CLAIMED_ORIGINAL_JOB_CHANGED")
    scope=event["company"]+"--"+event["environment"]+".json"
    require(scope=="all-royal-egypt--staging.json"
            and event["target_host_id"]=="hosting-2",
            "ORIGINAL313_FIRST_STAGING_ONLY")
    rev=event["revision"]
    require(event["state"]==("ACTIVE" if op==ISSUE else "REVOKED")
            and event["anchor_nonce"]==wp.get("anchor_nonce")
            and wp.get("source")=="original318_fresh_wp_readback"
            and wp.get("contract")=="mad4b.original318.host-anchor-current-readback.v2"
            and wp.get("readback_verified") is True
            and type(wp.get("observed_epoch")) is int
            and now-30<=wp["observed_epoch"]<=now+5
            and all(wp.get(k)==event.get(k) for k in
                    ("company","environment","site_uuid","revision","state"))
            and type(wp.get("signed_anchor_message_sha256")) is str
            and HEX64.fullmatch(wp["signed_anchor_message_sha256"]) is not None,
            "ORIGINAL313_POSTWRITE_SIGNED_WP_READBACK_CHANGED")
    if op==ISSUE:
        require(rev==1 and event["grant_revision"]==0
                and event["previous_event_sha256"]=="0"*64
                and wp.get("current_anchor_signature_verified") is True
                and wp.get("signed_anchor_active_in_option") is True
                and wp.get("current_anchor_eligible") is False
                and wp.get("original313_certified") is False
                and wp.get("write_authorized") is False,
                "ORIGINAL313_FIRST_ISSUE_STATE_NOT_INDEPENDENT")
    else:
        require(rev>=2 and wp.get("prior_grants_ineligible") is True
                and wp.get("current_anchor_eligible") is False
                and wp.get("original313_certified") is False,
                "ORIGINAL313_REVOKE_STATE_NOT_INDEPENDENT")
    store=PrivateStore() if store is None else store
    lock=store.root/".original313-txn.lock"
    fd=os.open(lock,os.O_CREAT|os.O_RDWR|os.O_NOFOLLOW,0o600)
    try:
        fcntl.flock(fd,fcntl.LOCK_EX)
        revision_file=store.path("revisions",scope)
        checkpoint_file=store.path("checkpoints",scope)
        transaction_file=store.path("transactions",job["job_id"]+".json")
        receipt_file=store.path("receipts",job["job_id"]+".json")
        previous=store.read(revision_file)
        old_checkpoint=store.read(checkpoint_file)
        previous_event=None
        ledger_digest=hashlib.sha256(
            gate.body(event,gate.FIELDS,"MAD4B_ORIGINAL_HOST_LEDGER_V1")).hexdigest()
        # A complete same-event retry must not create a second event.
        replay=previous is not None and previous==event
        if not replay:
            if op==ISSUE:
                require(previous is None and old_checkpoint is None,
                        "ORIGINAL313_FIRST_REVISION_ALREADY_PERSISTED")
            else:
                require(previous is not None and old_checkpoint is not None,
                        "ORIGINAL313_REVOKE_REQUIRES_PERSISTED_PRIOR_CHECKPOINT")
                gate.sigcheck(previous,gate.FIELDS,gate.LEDGER,
                    "MAD4B_ORIGINAL_HOST_LEDGER_V1",broker_pub,now,historical=True)
                gate.sigcheck(old_checkpoint,gate.C_FIELDS,gate.CHECKPOINT,
                    "MAD4B_ORIGINAL_HOST_CHECKPOINT_V1",independent_pub,now,
                    historical=True)
                digest=hashlib.sha256(gate.body(previous,gate.FIELDS,
                         "MAD4B_ORIGINAL_HOST_LEDGER_V1")).hexdigest()
                require(previous["state"]=="ACTIVE"
                        and old_checkpoint["event_sha256"]==digest
                        and previous["revision"]+1==rev
                        and event["previous_event_sha256"]==digest
                        and event["anchor_nonce"]!=previous["anchor_nonce"],
                        "ORIGINAL313_REVOKE_CHAIN_REPLAY_OR_ROLLBACK")
                previous_event=previous
        if replay and op==REVOKE and type(old_checkpoint) is dict:
            # Crash after Broker event's durable replacement but before
            # Original313 checkpoint rotation: the independently signed old
            # checkpoint and archived prior Broker event are the recovery
            # proof. Do not treat the unsigned gap as current authority.
            old_digest=old_checkpoint.get("event_sha256")
            if old_digest!=ledger_digest:
                require(old_digest==event["previous_event_sha256"],
                        "ORIGINAL313_REPLAY_OLDER_CHECKPOINT_NOT_IN_CHAIN")
                gate.sigcheck(old_checkpoint,gate.C_FIELDS,gate.CHECKPOINT,
                    "MAD4B_ORIGINAL_HOST_CHECKPOINT_V1",independent_pub,now,
                    historical=True)
                history=list((store.root/"history").glob("*.json"))
                require(len(history)<=256,
                        "ORIGINAL313_HISTORY_TOO_LARGE_FOR_RECOVERY")
                matching=[]
                for path in history:
                    prior=store.read(path)
                    gate.sigcheck(prior,gate.FIELDS,gate.LEDGER,
                        "MAD4B_ORIGINAL_HOST_LEDGER_V1",broker_pub,now,
                        historical=True)
                    digest=hashlib.sha256(gate.body(prior,gate.FIELDS,
                           "MAD4B_ORIGINAL_HOST_LEDGER_V1")).hexdigest()
                    if digest==old_digest:
                        matching.append(prior)
                require(len(matching)==1
                        and matching[0]["revision"]+1==rev
                        and matching[0]["state"]=="ACTIVE"
                        and old_checkpoint["revision"]==matching[0]["revision"],
                        "ORIGINAL313_PREVIOUS_SIGNED_EVENT_RECOVERY_FAILED")
                previous_event=matching[0]
        receipt_old=store.read(receipt_file)
        if receipt_old is not None:
            # Never trust a file merely because it has the right filename.
            # Validate both independent signatures, the current high-water
            # linkage and the exact signed postwrite observation on EVERY
            # retry before claiming a previously completed transaction.
            require(replay and type(old_checkpoint) is dict
                    and set(receipt_old)==set(RECEIPT_FIELDS)|{"signature"}
                    and receipt_old.get("event_sha256")==ledger_digest
                    and receipt_old.get("job_id")==job["job_id"]
                    and receipt_old.get("operation_id")==op
                    and receipt_old.get("company")==event["company"]
                    and receipt_old.get("environment")==event["environment"]
                    and receipt_old.get("site_uuid")==event["site_uuid"]
                    and receipt_old.get("revision")==rev
                    and receipt_old.get("anchor_nonce")==event["anchor_nonce"]
                    and receipt_old.get("state")==event["state"]
                    and receipt_old.get("wp_signed_message_sha256")==
                        wp["signed_anchor_message_sha256"]
                    and receipt_old.get("checkpoint_sha256")==
                        hashlib.sha256(gate.body(
                            old_checkpoint,gate.C_FIELDS,
                            "MAD4B_ORIGINAL_HOST_CHECKPOINT_V1")).hexdigest(),
                    "ORIGINAL313_PREVIOUS_RECEIPT_BINDING_FAILED")
            gate.sigcheck(old_checkpoint,gate.C_FIELDS,gate.CHECKPOINT,
                          "MAD4B_ORIGINAL_HOST_CHECKPOINT_V1",independent_pub,
                          now,historical=True)
            require(old_checkpoint["event_sha256"]==ledger_digest,
                    "ORIGINAL313_PREVIOUS_RECEIPT_HIGHWATER_CHANGED")
            from cryptography.hazmat.primitives.asymmetric.ed25519 import Ed25519PublicKey
            from cryptography.exceptions import InvalidSignature
            try:
                sig=base64.b64decode(receipt_old["signature"],validate=True)
                require(len(sig)==64,"ORIGINAL313_PREVIOUS_RECEIPT_SIGNATURE_SIZE_INVALID")
                Ed25519PublicKey.from_public_bytes(independent_pub).verify(
                    sig,b"MAD4B_ORIGINAL313_HOST_FINAL_RECEIPT_V1\n"+
                        canonical({k:receipt_old[k] for k in RECEIPT_FIELDS}))
            except (ValueError,TypeError,InvalidSignature) as exc:
                raise TransactionHeld("ORIGINAL313_PREVIOUS_RECEIPT_SIGNATURE_INVALID") from exc
            return {"state":"ORIGINAL313_PRIOR_SIGNED_RECEIPT_REUSED",
                    "job_id":job["job_id"],"revision":rev,
                    "original313_certified":True,
                    "anchor_issued":op==ISSUE,"anchor_revoked":op==REVOKE}
        # A post-write observation cannot create its own prior authorization.
        # The independent pre-write phase must have durably persisted this
        # exact Job/Anchor commitment BEFORE WordPress was mutated.
        recorded=store.read(transaction_file)
        require(type(recorded) is dict
                and recorded.get("contract")==PREWRITE_CONTRACT
                and recorded.get("job_id")==job["job_id"]
                and recorded.get("operation_id")==op
                and recorded.get("company")==event["company"]
                and recorded.get("environment")==event["environment"]
                and recorded.get("site_uuid")==event["site_uuid"]
                and recorded.get("anchor_revision_after")==rev
                and recorded.get("anchor_nonce")==event["anchor_nonce"]
                and recorded.get("anchor_message_sha256")==
                    wp["signed_anchor_message_sha256"]
                and type(recorded.get("reserved_epoch")) is int
                and recorded["reserved_epoch"]<=wp["observed_epoch"]
                and recorded["reserved_epoch"]<=now,
                "ORIGINAL313_SIGNED_PREWRITE_INTENT_REQUIRED")
        approval=recorded.get("signed_approval")
        require(type(approval) is dict
                and set(approval)==set(PREWRITE_FIELDS)|{"signature"}
                and approval["contract"]==PREWRITE_CONTRACT
                and approval["job_id"]==job["job_id"]
                and approval["operation_id"]==op
                and approval["company"]==event["company"]
                and approval["environment"]==event["environment"]
                and approval["site_uuid"]==event["site_uuid"]
                and approval["anchor_revision_after"]==rev
                and approval["anchor_nonce"]==event["anchor_nonce"]
                and approval["anchor_message_sha256"]==
                    wp["signed_anchor_message_sha256"]
                and hashlib.sha256(canonical(approval)).hexdigest()==
                    recorded.get("approval_sha256"),
                "ORIGINAL313_PREWRITE_SIGNED_APPROVAL_CHANGED")
        from cryptography.hazmat.primitives.asymmetric.ed25519 import Ed25519PublicKey
        from cryptography.exceptions import InvalidSignature
        try:
            a_sig=base64.b64decode(approval["signature"],validate=True)
            require(len(a_sig)==64,
                    "ORIGINAL313_PREWRITE_SIGNED_APPROVAL_SIZE_INVALID")
            Ed25519PublicKey.from_public_bytes(independent_pub).verify(
                a_sig,b"MAD4B_ORIGINAL313_HOST_ANCHOR_WRITE_V1\n"+
                canonical({f:approval[f] for f in PREWRITE_FIELDS}))
        except (InvalidSignature,ValueError,TypeError) as exc:
            raise TransactionHeld("ORIGINAL313_PREWRITE_SIGNATURE_DRIFT") from exc
        if previous_event is not None:
            archive=store.path("history",
                 previous_event["event_id"]+".json")
            archive_old=store.read(archive)
            require(archive_old is None or archive_old==previous_event,
                    "ORIGINAL313_HISTORY_EVENT_CHANGED")
            if archive_old is None:store.write_new(archive,previous_event)
        if not replay:
            if previous is None:store.write_new(revision_file,event)
            else:store.replace_verified(revision_file,event,previous)
        event_checkpoint={
            "contract":gate.CHECKPOINT,
            "company":event["company"],"environment":event["environment"],
            "site_uuid":event["site_uuid"],"revision":rev,
            "grant_revision":event["grant_revision"],
            "anchor_nonce":event["anchor_nonce"],"state":event["state"],
            "event_sha256":ledger_digest,
            "issued_epoch":now,"expires_epoch":now+45,
        }
        event_checkpoint["signature"]=base64.b64encode(independent_private.sign(
            gate.body(event_checkpoint,gate.C_FIELDS,
                     "MAD4B_ORIGINAL_HOST_CHECKPOINT_V1"))).decode()
        gate.sigcheck(event_checkpoint,gate.C_FIELDS,gate.CHECKPOINT,
           "MAD4B_ORIGINAL_HOST_CHECKPOINT_V1",independent_pub,now)
        if op==ISSUE:
            projected={**job,"anchor_revision":rev,
                       "grant_revision":event["grant_revision"],
                       "ssh_principal":event["ssh_principal"]}
            gate.verify_fresh_current(projected,event,event_checkpoint,wp,
                                      broker_pub,independent_pub,now=now)
        else:
            projected={**job,"anchor_revision":rev-1,
                       "grant_revision":event["grant_revision"],
                       "ssh_principal":event["ssh_principal"]}
            gate.verify_revoked_postcondition(projected,event,
                event_checkpoint,wp,broker_pub,independent_pub,now=now)
        if old_checkpoint is None:
            store.write_new(checkpoint_file,event_checkpoint)
        else:
            require(replay or previous_event is not None,
                    "ORIGINAL313_UNEXPECTED_CHECKPOINT_REPLACEMENT")
            if replay:
                require(old_checkpoint.get("event_sha256")==ledger_digest
                        or (op==REVOKE and previous_event is not None
                            and old_checkpoint.get("event_sha256")==
                                event["previous_event_sha256"]),
                        "ORIGINAL313_RECOVERY_CHECKPOINT_MISMATCH")
            store.replace_verified(checkpoint_file,event_checkpoint,old_checkpoint)
        checkpoint_digest=hashlib.sha256(gate.body(event_checkpoint,
            gate.C_FIELDS,"MAD4B_ORIGINAL_HOST_CHECKPOINT_V1")).hexdigest()
        receipt={ "contract":RECEIPT,"job_id":job["job_id"],
            "operation_id":op,"company":event["company"],
            "environment":event["environment"],"site_uuid":event["site_uuid"],
            "revision":rev,"anchor_nonce":event["anchor_nonce"],
            "state":event["state"],"event_sha256":ledger_digest,
            "checkpoint_sha256":checkpoint_digest,
            "wp_signed_message_sha256":wp["signed_anchor_message_sha256"],
            "issued_epoch":now}
        receipt["signature"]=base64.b64encode(independent_private.sign(
            b"MAD4B_ORIGINAL313_HOST_FINAL_RECEIPT_V1\n"+
            canonical({k:receipt[k] for k in RECEIPT_FIELDS}))).decode()
        store.write_new(receipt_file,receipt)
        return { "state":"ORIGINAL313_SIGNED_POSTWRITE_RECEIPT_PERSISTED",
           "job_id":job["job_id"],"revision":rev,
           "event_sha256":ledger_digest,
           "original313_certified":True,
           "anchor_issued":op==ISSUE,"anchor_revoked":op==REVOKE}
    finally:
        fcntl.flock(fd,fcntl.LOCK_UN)
        os.close(fd)

def main():
    # Deliberately no user-supplied Job, paths or signing operation.
    # Live transaction must be invoked by the first-party Root Worker
    # with independently verified original Job and fresh WordPress readback.
    print(json.dumps({"state":"HOLD","reason":"FIRST_PARTY_ORIGINAL306_JOB_REQUIRED",
                      "signed_receipt_created":False},sort_keys=True))
    return 2

if __name__=="__main__":
    raise SystemExit(main())
