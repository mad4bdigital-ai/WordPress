#!/usr/bin/env python3
"""MAD4B v121: purpose-separated First Anchor Root coordinator.

Scope is pinned to All Royal Egypt STAGING/hosting-2 and exact existing
Original305/306/312/313/318 contracts. No generalized Host operations.

A *new* authenticated WordPress Owner Request is required. This coordinator
does not issue one or replay expired evidence. Issue requires a separately
reviewed root-only commissioning flag that is not installed by source deploy.
On ambiguous Original318 write, preserve prewrite + signed docs, inspect live
readback, NEVER blindly retry the write.
"""
from __future__ import annotations
import argparse
import base64
import fcntl
import hashlib
import importlib.util
import json
import os
from pathlib import Path
import re
import stat
import subprocess
import sys
import time

from cryptography.hazmat.primitives.asymmetric.ed25519 import Ed25519PrivateKey
from cryptography.hazmat.primitives.serialization import Encoding,PublicFormat

ROOT=Path("/opt/mad4b-company-transport/native-313-independent")
REGISTRY=ROOT/"dynamic-operation-registry-v1/enrolled"
PRIVATE=Path("/var/lib/mad4b/original-host-v2")
PLAN_ROOT=Path("/var/lib/mad4b/dynamic-operation-registry/plans")
SCOPE="all-royal-egypt--staging.json"
COMPANY="all-royal-egypt"
ENV="staging"
HOST="hosting-2"
OP="company.host-authority.anchor.issue"
CONTRACT="mad4b.original313.first-anchor-root-coordinator.v121"
DOC_CONTRACT="mad4b.original313.first-issue-durable-documents.v121"
INTENT_CONTRACT="mad4b.original318.first-issue-exact-send-intent.v121"
DOCS=PRIVATE/"first-issue-documents"
INTENTS=PRIVATE/"first-issue-send-intents"
PERMIT=PRIVATE/".first-issue-root-commissioned-v121.json"
HEX32=re.compile(r"[a-f0-9]{32}\Z")
HEX64=re.compile(r"[a-f0-9]{64}\Z")
SOURCES={
 "bridge":(ROOT/"host-authority-v120/broker_signed_admission.py",
            "949f8afce20faf6d2fba1cf713ee45e2ed3c6b967bdf2567c7144ac0d08f0793"),
 "v118":(ROOT/"host-authority-v118/queued_original_capture.py",
            "b76778a5ecc786c74a02b5bd0a9c42f9256a17a7f46b4089ff1bd72368b0ecb2"),
 "v119":(ROOT/"host-authority-v119/first_anchor_runner.py",
            "8ad2faf22c7ee9f72e7aa9ac8cf645d176a18a24ef4b21ab1bed6a3adb7ccff6"),
 "v120":(ROOT/"host-authority-v120/broker_row_witness.py",
            "336ef367637ce1edbef4debbec4317b3b839d3d71e2e3135e0690eb40d96331f"),
 "v105":(ROOT/"host-authority-v105/original313_first_owner_enrollment_issuer.py",
            "9abf86765eed1f7128258c997a474aecc6c872ad0552079189c61e5a6c1f3b71"),
 "v100":(ROOT/"host-authority-v100/original312_first_issue_bundle.py",
            "1a49a50046b19a3076b63fbaf1757d2f8d19982e86bd7c990b36b65af458277d"),
 "v99":(ROOT/"host-authority-v99/original313_postwrite_transaction.py",
            "1b42065db70e224a889c969d28d9b8119e1dceba8003d43e05dc0b0111f0c00d"),
 "prewrite":(ROOT/"host-authority-v119/prewrite_admission.py",
            "197af611ba0587bdb8ad86c9e00dbb6ab2c3533d2b305c2412150b35064dcd90"),
}
KEY=ROOT/"root-only-verifier-ed25519.key"
BROKER=ROOT.parent/"broker-v1/native-vps-client-principal.py"
BROKER_SHA="ebb4971efef114e5b984a9c9ea60d46b5fe7250db9991b5d94cd61708651dc26"

class CoordinatorHeld(ValueError):pass

def need(ok,reason):
    if not ok:raise CoordinatorHeld(reason)

def digest(blob):
    return hashlib.sha256(blob).hexdigest()

def canonical(obj):
    return json.dumps(obj,sort_keys=True,separators=(",",":"),ensure_ascii=False).encode()

def root_file(path,sha=None,size=65536):
    need(os.geteuid()==0 and path.is_file() and not path.is_symlink(),
         "V121_ROOT_FIRST_PARTY_FILE_REQUIRED")
    st=path.lstat()
    need(stat.S_ISREG(st.st_mode) and st.st_uid==0 and st.st_gid==0
         and st.st_nlink==1 and not st.st_mode&0o077
         and 0<st.st_size<size,
         "V121_ORIGINAL_FILE_OWNER_MODE_CHANGED")
    raw=path.read_bytes()
    if sha is not None:need(digest(raw)==sha,
                            "V121_ORIGINAL_PINNED_SOURCE_CHANGED")
    return raw

def module(name):
    need(name in SOURCES,"V121_SOURCE_NOT_ENROLLED")
    path,sha=SOURCES[name]
    root_file(path,sha)
    spec=importlib.util.spec_from_file_location("_v121_root_"+name,path)
    need(spec is not None and spec.loader is not None,
         "V121_ORIGINAL_SOURCE_IMPORT_DENIED")
    value=importlib.util.module_from_spec(spec)
    if name=="prewrite":
        # v119 prewrite's fallback imports first_anchor_runner by name.
        # Force that import to resolve ONLY to the pinned original v119
        # module while loading prewrite. Restore the prior process cache
        # immediately: never trust or permanently replace another module.
        first=module("v119")
        previous=sys.modules.get("first_anchor_runner")
        sys.modules["first_anchor_runner"]=first
        try:
            spec.loader.exec_module(value)
            need(value.runner is first
                 and digest(root_file(SOURCES["v119"][0]))==
                         SOURCES["v119"][1],
                 "V121_PREWRITE_MUST_BIND_PINNED_ORIGINAL_V119")
        finally:
            if previous is None:
                sys.modules.pop("first_anchor_runner",None)
            else:
                sys.modules["first_anchor_runner"]=previous
    else:
        spec.loader.exec_module(value)
    return value

def signed_proof_key(index):
    raw=root_file(KEY,size=1000)
    need(len(raw)==32,"V121_ROOT_313_KEY_LENGTH_INVALID")
    key=Ed25519PrivateKey.from_private_bytes(raw)
    pub=key.public_key().public_bytes(Encoding.Raw,PublicFormat.Raw)
    need(pub==index.independent_verifier_public_key
         and pub!=index.host_anchor_312_public_key,
         "V121_ORIGINAL313_INDEPENDENT_SIGNER_NOT_ENROLLED")
    return key

def root_gate():
    """Commissioning flag is root controlled; test success does not create it."""
    if not PERMIT.exists():
        raise CoordinatorHeld("V121_ROOT_ISSUER_NOT_COMMISSIONED")
    record=json.loads(root_file(PERMIT,size=3000))
    need(type(record) is dict and set(record)=={
         "contract","scope","authorized_runner_sha256",
         "enabled","requires_fresh_owner_request"},
         "V121_ROOT_COMMISSIONING_RECORD_INVALID")
    # Script path is the exact actual release artifact, not derived from CLI.
    here=Path(__file__).resolve(strict=True)
    need(here.parent==ROOT/"host-authority-v121"
         and record["contract"]=="mad4b.original313.first-anchor-commissioning.v121"
         and record["scope"]=="all-royal-egypt/staging/hosting-2"
         and record["authorized_runner_sha256"]==
             digest(root_file(here,size=50000))
         and record["enabled"] is True
         and record["requires_fresh_owner_request"] is True,
         "V121_ROOT_COMMISSIONING_NOT_AUTHORIZED")

def exact_store_dir(path):
    need(os.geteuid()==0 and path.parent==PRIVATE
         and path.parent.is_dir() and not path.parent.is_symlink()
         and path.parent.stat().st_uid==0
         and stat.S_IMODE(path.parent.stat().st_mode)==0o700
         and not path.is_symlink(),
         "V121_PRIVATE_ROOT_STORAGE_UNTRUSTED")
    if not path.exists():
        path.mkdir(mode=0o700)
    need(path.is_dir() and not path.is_symlink()
         and path.stat().st_uid==0 and path.stat().st_gid==0
         and stat.S_IMODE(path.stat().st_mode)==0o700,
         "V121_PRIVATE_ARTIFACT_DIR_NOT_PROTECTED")

def immutable_write(path,record):
    exact_store_dir(path.parent)
    need(isinstance(record,dict) and type(record) is dict,
         "V121_EXACT_DURABLE_RECORD_REQUIRED")
    b=canonical(record)+b"\n"
    need(100<len(b)<24000,"V121_DURABLE_DOC_SIZE_INVALID")
    try:
        fd=os.open(path,os.O_WRONLY|os.O_CREAT|os.O_EXCL|os.O_NOFOLLOW,0o600)
    except FileExistsError:
        need(immutable_read(path)==record,
             "V121_DURABLE_ARTIFACT_REPLAY_CHANGED")
        return False
    try:
        with os.fdopen(fd,"wb") as f:
            f.write(b);f.flush();os.fsync(f.fileno())
        d=os.open(path.parent,os.O_RDONLY|os.O_DIRECTORY)
        try:os.fsync(d)
        finally:os.close(d)
    except BaseException:
        path.unlink(missing_ok=True)
        raise
    return True

def immutable_read(path):
    exact_store_dir(path.parent)
    need(path.is_file() and not path.is_symlink(),
         "V121_IMMUTABLE_ARTIFACT_MISSING")
    st=path.lstat()
    need(st.st_uid==0 and st.st_gid==0 and st.st_nlink==1
         and stat.S_IMODE(st.st_mode)==0o600 and 100<st.st_size<24000,
         "V121_IMMUTABLE_ARTIFACT_UNTRUSTED")
    data=json.loads(path.read_bytes())
    need(type(data) is dict,"V121_IMMUTABLE_ARTIFACT_NOT_OBJECT")
    return data

def current_originals(job_id):
    need(os.geteuid()==0 and isinstance(job_id,str)
         and HEX32.fullmatch(job_id),
         "V121_ROOT_EXACT_OWNER_JOB_REQUIRED")
    bridge=module("bridge")
    broker=bridge.pinned(bridge.BROKER,bridge.BROKER_SHA,
        "_v121_enrolled_original_wordpress_reader")
    return bridge.read_original_claimed(job_id,broker)

def inspect(job_id):
    """No signer or WP mutation; requires queued and separate signed Broker row."""
    need(os.geteuid()==0 and isinstance(job_id,str)
         and HEX32.fullmatch(job_id),"V121_JOB_ID_INVALID")
    if str(REGISTRY) not in sys.path:sys.path.insert(0,str(REGISTRY))
    from registry import Registry,checked_json
    index=Registry(REGISTRY)
    need(index.resolve(OP).get("execution_enabled") is False,
         "V121_GENERIC_REGISTRY_EXECUTION_MUST_REMAIN_OFF")
    v118=module("v118")
    v120=module("v120")
    bridge=module("bridge")
    historic=v118.read_preimage(job_id)
    witness=v120.read_witness(job_id)
    plan=checked_json(PLAN_ROOT/(historic["plan_sha256"]+".json"),PLAN_ROOT)
    current=current_originals(job_id)
    now=int(time.time())
    row=v120.verified_broker_row(witness,historic,
        index.host_anchor_312_public_key,now=now)
    v119=module("v119")
    allowed=v119.validate_readiness(
        index,historic,current["job"],current["claim"],row,plan,current,
        now=now)
    need(allowed["execution_authorized"] is False
         and allowed["anchor_issued"] is False
         and allowed["issuer_commissioned"] is False,
         "V121_NONAUTHORIZING_FIRST_ANCHOR_ADMISSION_CHANGED")
    return {
        "index":index,"historic":historic,"witness":witness,
        "plan":plan,"current":current,"broker_row":row,
        "gate":allowed,
    }

def save_signed_documents(ctx,doc,approval):
    job=ctx["current"]["job"]
    jid=job["job_id"]
    record={
      "contract":DOC_CONTRACT,
      "job_id":jid,
      "scope":COMPANY+"/"+ENV,
      "plan_sha256":job["plan_sha256"],
      "command_sha256":job["command_sha256"],
      "claimed_original_sha256":digest(canonical(job)),
      "original312_documents":doc,
      "original313_signed_approval":approval,
    }
    path=DOCS/(jid+".json")
    immutable_write(path,record)
    need(immutable_read(path)==record,
         "V121_SIGNED_DOCUMENTS_DURABLE_READBACK_CHANGED")
    return record

def get_docs_for_recovery(jid):
    need(isinstance(jid,str) and HEX32.fullmatch(jid),"V121_JOB_ID_INVALID")
    p=DOCS/(jid+".json")
    if not p.is_file():return None
    r=immutable_read(p)
    need(r.get("contract")==DOC_CONTRACT
         and r.get("job_id")==jid
         and r.get("scope")==COMPANY+"/"+ENV
         and type(r.get("original312_documents")) is dict
         and type(r.get("original313_signed_approval")) is dict,
         "V121_DURABLE_SIGNED_ISSUE_DOCS_DRIFT")
    return r

def send_intent(jid,proof):
    need(type(proof) is dict
         and proof.get("contract")==
          "mad4b.original318.anchor-reconcile-signed.v1"
         and proof.get("job_id")==jid,
         "V121_ORIGINAL318_EXACT_SIGNED_PROOF_REQUIRED")
    record={
       "contract":INTENT_CONTRACT,
       "job_id":jid,
       "proof_sha256":digest(canonical(proof)),
       "company":COMPANY,"environment":ENV,
       "write_attempted":True,
    }
    # O_EXCL first. Returning False means NEVER repeat the command.
    first=immutable_write(INTENTS/(jid+".json"),record)
    need(immutable_read(INTENTS/(jid+".json"))==record,
         "V121_ORIGINAL318_WRITE_INTENT_CHANGED")
    return first

def read_wp_anchor():
    v100=module("v100")
    source=module("v105")
    signer_source=v100.pinned(
        v100.pinned(v100.FIRST,v100.FIRST_SHA,
          "_v121_first_broker_verify").ORIGINAL_SIGNER_SOURCE,
        v100.pinned(v100.FIRST,v100.FIRST_SHA,
          "_v121_first_broker_verify2").ORIGINAL_SIGNER_SHA256,
        "_v121_enrolled_wpcli_read")
    return v100.fresh_original_wp_snapshot(signer_source)

def invoke_wordpress_original318(jid,proof):
    """Only one possible WordPress mutation per job, intent persisted first."""
    need(send_intent(jid,proof),"V121_ORIGINAL318_POSSIBLE_PRIOR_WRITE_NO_REPLAY")
    broker=module("v105").pinned(BROKER,BROKER_SHA,
       "_v121_original_broker_fixed_wpcli")
    payload=base64.b64encode(json.dumps(
        proof,ensure_ascii=False,separators=(",",":")).encode()).decode()
    need(len(payload)<20000,"V121_EXACT_SIGNED_PROOF_EXCEEDS_WP_CLI_LIMIT")
    try:
        out=subprocess.run(broker.original_central_wp()+[
             "mad4b","wpcli-host-grant","anchor-reconcile-signed",
             "--proof="+payload
        ],text=True,capture_output=True,timeout=45)
    except subprocess.TimeoutExpired as exc:
        raise CoordinatorHeld("V121_ORIGINAL318_WRITE_RESULT_AMBIGUOUS_READBACK_ONLY") from exc
    lines=[line for line in out.stdout.splitlines() if line.startswith("{")]
    need(len(lines)==1,"V121_ORIGINAL318_WRITE_RESULT_AMBIGUOUS_READBACK_ONLY")
    try:reply=json.loads(lines[0])
    except ValueError as exc:
        raise CoordinatorHeld("V121_ORIGINAL318_WRITE_RESULT_AMBIGUOUS_READBACK_ONLY") from exc
    need(out.returncode==0 and
         reply.get("state")==
          "WORDPRESS_SIGNED_ANCHOR_POSTWRITE_VERIFIED__ROOT_313_RECEIPT_PENDING"
         and reply.get("job_id")==jid
         and reply.get("anchor_revision")==1,
         "V121_ORIGINAL318_WRITE_RESULT_AMBIGUOUS_READBACK_ONLY")
    return reply

def original313_finalizer_job(job,event):
    """Canonical read-only view over the real Original306 job's SSH principal.

    Original306 stores `target_ssh_principal`, while Original313 v99
    historical event verification compares `ssh_principal`. This adapter
    DOES NOT fabricate identity, alter WordPress, or change original source:
    it binds the existing signed Owner/328/plan principal to the event and
    exposes an equivalent alias to the finalizer ONLY, after comparison.
    """
    need(type(job) is dict and type(event) is dict
         and job.get("operation_id")==OP
         and job.get("company")==COMPANY
         and job.get("environment")==ENV
         and job.get("target_host_id")==HOST
         and job.get("job_id")==event.get("event_id")
         and job.get("site_uuid")==event.get("site_uuid")
         and job.get("state")==
             "BROKER_CLAIMED_PENDING_INDEPENDENT_HOST_AUTHORITY"
         and job.get("target_ssh_principal")==event.get("ssh_principal")
         and isinstance(event.get("ssh_principal"),str)
         and re.fullmatch(r"u[0-9]{9}",event["ssh_principal"])
         and ("ssh_principal" not in job or
              job["ssh_principal"]==job["target_ssh_principal"]),
         "V121_ORIGINAL306_313_SSH_PRINCIPAL_PROVENANCE_CHANGED")
    return {**job,"ssh_principal":job["target_ssh_principal"]}

def independent_postwrite(jid,record):
    v99=module("v99")
    job=current_originals(jid)["job"]
    need(digest(canonical(job))==record["claimed_original_sha256"],
         "V121_ORIGINAL306_CLAIM_CHANGED_POSTWRITE")
    doc=record["original312_documents"]
    observed=read_wp_anchor()["anchor_readback"]
    need(type(observed) is dict
         and observed.get("state")=="ACTIVE"
         and observed.get("revision")==1
         and observed.get("readback_verified") is True
         and observed.get("anchor_nonce")==doc["anchor"]["nonce"]
         and observed.get("signed_anchor_message_sha256")==
              doc["anchor_message_sha256"],
         "V121_FIRST_ANCHOR_POSTWRITE_NOT_INDEPENDENTLY_OBSERVED")
    if str(REGISTRY) not in sys.path:sys.path.insert(0,str(REGISTRY))
    from registry import Registry
    index=Registry(REGISTRY)
    key=signed_proof_key(index)
    source_bound_job=original313_finalizer_job(job,doc["revision_event"])
    result=v99.finalize(source_bound_job,doc["revision_event"],observed,
         index.host_anchor_312_public_key,key)
    need(result.get("revision")==1
         and result.get("original313_certified") is True
         and result.get("anchor_issued") is True,
         "V121_ORIGINAL313_FINAL_RECEIPT_NOT_VERIFIED")
    store=v99.PrivateStore()
    scope=SCOPE
    revision=store.read(store.path("revisions",scope))
    cp=store.read(store.path("checkpoints",scope))
    receipt=store.read(store.path("receipts",jid+".json"))
    need(type(revision) is dict and type(cp) is dict
         and type(receipt) is dict
         and receipt.get("job_id")==jid
         and receipt.get("revision")==1
         and revision.get("revision")==cp.get("revision")==1
         and receipt.get("anchor_nonce")==observed["anchor_nonce"],
         "V121_POSTWRITE_ROOT_LEDGER_INCOMPLETE")
    gate=v99.load_gate()
    gate.sigcheck(revision,gate.FIELDS,gate.LEDGER,
         "MAD4B_ORIGINAL_HOST_LEDGER_V1",
         index.host_anchor_312_public_key,int(time.time()),historical=True)
    gate.sigcheck(cp,gate.C_FIELDS,gate.CHECKPOINT,
         "MAD4B_ORIGINAL_HOST_CHECKPOINT_V1",
         index.independent_verifier_public_key,int(time.time()),historical=True)
    return {
        "state":"FIRST_ANCHOR_REVISION_1_SIGNED_RECEIPT_AND_HIGHWATER_VERIFIED",
        "job_id":jid,"revision":1,
        "original313_receipt_verified":True,
        "original318_readback_verified":True,
        "generic_runtime_execution_enabled":False
    }

def issue(jid):
    """One-shot controlled issuance. Never reuse an existing send-intent."""
    root_gate()
    need(not (INTENTS/(jid+".json")).exists(),
         "V121_ORIGINAL318_PRIOR_WRITE_INTENT_RECOVERY_ONLY")
    ctx=inspect(jid)
    claimed=ctx["current"]["job"]
    historic=ctx["historic"]["originals"]
    claim=ctx["current"]["claim"]
    site=ctx["current"]["site"]
    registry=ctx["index"]
    v105=module("v105")
    v99=module("v99")
    store=v99.PrivateStore()
    enrollment=store.read(store.path("enrollments",SCOPE))
    if enrollment is None:
        # First-party signer authenticates a NEW exact Owner / 306 Claim
        # and persists its signed Enrollment inside Original313 Root store.
        v105.first_party_originals(registry,historic["queued"],
            historic["pending"][0],claimed,claim,ctx["broker_row"],
            ctx["plan"],ctx["current"]["request"],ctx["current"]["grant"],
            ctx["current"]["parent"],ctx["current"]["transport"],site)
        enrollment=store.read(store.path("enrollments",SCOPE))
    need(type(enrollment) is dict
         and enrollment.get("original306_job_id")==jid
         and enrollment.get("plan_sha256")==claimed["plan_sha256"],
         "V121_INDEPENDENT313_OWNER_ENROLLMENT_NOT_PERSISTED")
    # After an interrupted first-issue, never mint a second enrollment.
    # Verify the existing Original313 signed Enrollment using the same
    # pinned original v89 verifier, signer key, and current claimed job.
    v89=v99.load_source(v99.ENROLLMENT_VERIFIER,
        v99.ENROLLMENT_SHA256,"_v121_original313_existing_enrollment")
    verified_enrollment=v89.verify_enrollment(enrollment,
        registry.independent_verifier_public_key,claimed,
        now=int(time.time()))
    need(verified_enrollment.get("independent_enrollment_signature_verified")
         is True,"V121_EXISTING_ORIGINAL313_ENROLLMENT_NOT_VERIFIED")
    saved=get_docs_for_recovery(jid)
    if saved is not None:
        # Crash after signed docs but before signed Original313 prewrite:
        # read exact durable original; never re-sign divergent documents.
        need(saved["plan_sha256"]==claimed["plan_sha256"]
             and saved["command_sha256"]==claimed["command_sha256"]
             and saved["claimed_original_sha256"]==digest(canonical(claimed)),
             "V121_DURABLE_ORIGINAL312_SOURCE_DRIFT")
        docs=saved["original312_documents"]
        approval=saved["original313_signed_approval"]
    else:
        v100=module("v100")
        docs=v100.from_original_claim(
            registry,ctx["plan"],claimed,claim,ctx["broker_row"])
        pre=module("prewrite")
        signer=signed_proof_key(registry)
        pub=registry.independent_verifier_public_key
        approval=pre.prepare_approval(claimed,docs,signer,pub,
            registry.host_anchor_312_public_key,now=int(time.time()))
        # Durable signed docs exist BEFORE reserving any write intent.
        saved=save_signed_documents(ctx,docs,approval)
    pub=registry.independent_verifier_public_key
    pre=module("prewrite")
    transaction=store.read(store.path("transactions",jid+".json"))
    if transaction is None:
        reserved=v99.reserve_signed_prewrite(claimed,enrollment,
            docs["bootstrap"],approval,
            registry.host_anchor_312_public_key,pub)
        need(reserved.get("state")=="ORIGINAL313_SIGNED_PREWRITE_RESERVED"
             and reserved.get("job_id")==jid,
             "V121_ORIGINAL313_PREWRITE_NOT_RESERVED")
    else:
        # If Original313 reserved prewrite and the process crashed before
        # response, its *existing* immutable protected transaction is
        # authenticated against the exact signed docs below. Never reserve
        # a second intent or alter its clock/signature.
        reserved={
            "state":"ORIGINAL313_SIGNED_PREWRITE_RESERVED",
            "job_id":jid,
            "anchor_issued":False,
            "original313_certified":False,
        }
    proof=pre.build_original318_proof(claimed,docs,approval,reserved,
       independent_pub=pub,broker_pub=registry.host_anchor_312_public_key,
       now=int(time.time()))
    # Separate current Original318 revision-0 read immediately before write.
    state=read_wp_anchor()["anchor_readback"]
    need(state.get("revision")==0
         and state.get("state")=="MISSING_OR_INVALID"
         and state.get("write_authorized") is False
         and state.get("original313_certified") is False,
         "V121_FIRST_ANCHOR_CHANGED_BEFORE_WRITE")
    invoke_wordpress_original318(jid,proof)
    return independent_postwrite(jid,saved)

def resume(jid):
    """Never repeats Original318 CLI. Only independent postwrite finalization."""
    root_gate()
    record=get_docs_for_recovery(jid)
    need(record is not None,
         "V121_NO_DURABLE_ORIGINAL312_313_DOCUMENTS")
    need((INTENTS/(jid+".json")).exists(),
         "V121_NO_ORIGINAL318_ATTEMPT_TO_RECOVER")
    immutable_read(INTENTS/(jid+".json"))
    return independent_postwrite(jid,record)

def execute_locked(jid,action):
    """Serialize EVERY live caller, including the root worker hook.

    Never race a standalone invocation against the periodic Original306
    worker or recovery process; fail closed if another issuer owns the
    lock instead of waiting until signed freshness expires.
    """
    need(action in ("issue","resume")
         and os.geteuid()==0
         and isinstance(jid,str)
         and HEX32.fullmatch(jid),
         "V121_EXACT_ISSUER_ACTION_AND_OWNER_JOB_REQUIRED")
    lock=PRIVATE/".first-issue-runner.lock"
    fd=os.open(lock,os.O_CREAT|os.O_RDWR|os.O_NOFOLLOW,0o600)
    try:
        try:
            fcntl.flock(fd,fcntl.LOCK_EX|fcntl.LOCK_NB)
        except BlockingIOError as exc:
            raise CoordinatorHeld("V121_ANOTHER_EXACT_FIRST_ANCHOR_ISSUER_ACTIVE") from exc
        return issue(jid) if action=="issue" else resume(jid)
    finally:
        fcntl.flock(fd,fcntl.LOCK_UN)
        os.close(fd)

def main(argv=None):
    parser=argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--job-id",required=True)
    group=parser.add_mutually_exclusive_group(required=True)
    group.add_argument("--inspect",action="store_true")
    group.add_argument("--issue",action="store_true")
    group.add_argument("--resume",action="store_true")
    opts=parser.parse_args(argv)
    try:
        need(os.geteuid()==0
             and isinstance(opts.job_id,str)
             and HEX32.fullmatch(opts.job_id),
             "V121_ROOT_JOB_ID_INVALID")
        if opts.inspect:
            out=inspect(opts.job_id)
            result={"contract":CONTRACT,"job_id":opts.job_id,
               "state":"HOLD_OWNER_ISSUER_NOT_COMMISSIONED",
               "signed_broker_row_verified":True,
               "original306_claim_matched":True,
               "signer_invoked":False,"anchor_issued":False}
            status=2
        else:
            result=execute_locked(opts.job_id,
                "issue" if opts.issue else "resume")
            status=0
    except (CoordinatorHeld,ValueError,KeyError,TypeError,OSError) as exc:
        why=str(exc)
        if not re.fullmatch(r"[A-Z][A-Z0-9_]{5,130}",why):
            why="V121_FIRST_ANCHOR_ORIGINAL_ISSUER_HELD"
        result={"contract":CONTRACT,"state":"HOLD",
            "reason":why,"signer_invoked":False,
            "anchor_issued":False}
        status=2
    print(json.dumps(result,sort_keys=True))
    return status

if __name__=="__main__":
    raise SystemExit(main())
