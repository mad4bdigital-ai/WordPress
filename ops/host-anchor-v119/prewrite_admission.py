#!/usr/bin/env python3
"""Pure Original313 first-Anchor prewrite approval composer — source only.

The full Root issuer is NOT commissioned and this module has no CLI,
WordPress write or private-key loader. Live future use MUST come only after
original v105 persisted Enrollment and original v100 signed documents, with
fresh claimed originals and Root's independently pinned signer key.
"""
from __future__ import annotations
import base64
import hashlib
import json
from cryptography.hazmat.primitives.asymmetric.ed25519 import Ed25519PublicKey
from cryptography.hazmat.primitives.serialization import Encoding,PublicFormat

try:
    from . import first_anchor_runner as runner
except ImportError:
    import first_anchor_runner as runner

class PrewriteHeld(ValueError):pass

def need(ok,reason):
    if not ok:raise PrewriteHeld(reason)

def prepare_approval(claimed, signed_docs, signer, independent_pub, broker_pub, *,
                     now):
    """Pure construction. The signer argument is only for hermetic tests or
    a separately vetted fixed Root-only first-party caller; this module
    never loads the real key, preauthorizes WordPress, or persists evidence.
    """
    need(type(claimed) is dict and type(signed_docs) is dict
         and isinstance(now,int) and isinstance(independent_pub,bytes)
         and len(independent_pub)==32 and isinstance(broker_pub,bytes)
         and len(broker_pub)==32 and independent_pub!=broker_pub
         and callable(getattr(signer,"sign",None)),
         "V119_ORIGINAL313_PREWRITE_SIGNER_AND_ORIGINALS_REQUIRED")
    v100=runner.pin("v100")
    v99=runner.pin("v99")
    pub=signer.public_key().public_bytes(Encoding.Raw,PublicFormat.Raw)
    need(pub==independent_pub,"V119_INDEPENDENT_313_SIGNER_IDENTITY_CHANGED")
    need(claimed.get("operation_id")==v99.ISSUE
        and claimed.get("company")=="all-royal-egypt"
        and claimed.get("environment")=="staging"
        and claimed.get("target_host_id")=="hosting-2"
        and claimed.get("state")=="BROKER_CLAIMED_PENDING_INDEPENDENT_HOST_AUTHORITY"
        and claimed.get("job_type")=="signed_remote_hostinger_wpcli_v2"
        and claimed.get("principal_type")=="signed_host_principal_v2"
        and claimed.get("executed") is False
        and type(claimed.get("expires_epoch")) is int
        and claimed["expires_epoch"]>=now+40,
        "V119_ORIGINAL306_JOB_NOT_FRESH_ENOUGH_FOR_PREWRITE")
    need(signed_docs.get("contract")==
        "mad4b.original312.first-anchor-issue-documents.v1"
        and signed_docs.get("job_id")==claimed.get("job_id")
        and signed_docs.get("operation_id")==v99.ISSUE
        and signed_docs.get("original312_signatures_verified") is True
        and signed_docs.get("original318_write_performed") is False
        and signed_docs.get("original313_prewrite_approved") is False
        and signed_docs.get("original313_final_receipt_signed") is False
        and signed_docs.get("anchor_issued") is False
        and signed_docs.get("execution_authorized") is False,
        "V119_ORIGINAL312_NONEXECUTING_SIGNED_DOCUMENTS_REQUIRED")
    anchor=signed_docs.get("anchor")
    event=signed_docs.get("revision_event")
    boot=signed_docs.get("bootstrap")
    need(type(anchor) is dict and type(event) is dict and type(boot) is dict
        and anchor.get("company")==claimed["company"]
        and anchor.get("environment")==claimed["environment"]
        and anchor.get("site_uuid")==claimed["site_uuid"]
        and anchor.get("ssh_target")==claimed["target_host_id"]
        and anchor.get("ssh_principal")==claimed.get("target_ssh_principal")
        and anchor.get("revision")==1
        and anchor.get("state")=="ACTIVE_INDEPENDENT_HOST_AUTHORITY"
        and anchor.get("revoked") is False
        and event.get("event_id")==claimed.get("job_id")
        and event.get("revision")==1
        and event.get("state")=="ACTIVE"
        and event.get("anchor_nonce")==anchor.get("nonce")
        and boot.get("job_id")==claimed.get("job_id")
        and boot.get("plan_sha256")==claimed.get("plan_sha256")
        and boot.get("command_sha256")==claimed.get("command_sha256"),
        "V119_ORIGINAL312_SIGNED_DOCS_SCOPE_OR_REVISION_CHANGED")
    body=v100.anchor_body(anchor)
    digest=hashlib.sha256(body).hexdigest()
    need(digest==signed_docs.get("anchor_message_sha256"),
        "V119_ORIGINAL312_WORDPRESS_MESSAGE_CHANGED")
    try:
        Ed25519PublicKey.from_public_bytes(broker_pub).verify(
            base64.b64decode(anchor["signature"],validate=True),body)
        bootstrap_verifier=v100.pinned(v100.BOOTSTRAP,v100.BOOTSTRAP_SHA,
            "_v119_signed_bootstrap_verifier")
        Ed25519PublicKey.from_public_bytes(broker_pub).verify(
            base64.b64decode(boot["signature"],validate=True),
            bootstrap_verifier.signed_message(boot))
    except (ValueError,KeyError,TypeError) as exc:
        raise PrewriteHeld("V119_ORIGINAL312_ANCHOR_SIGNATURE_INVALID") from exc
    except Exception as exc:
        raise PrewriteHeld("V119_ORIGINAL312_ANCHOR_SIGNATURE_INVALID") from exc
    gate=v99.load_gate()
    gate.sigcheck(event,gate.FIELDS,gate.LEDGER,
        "MAD4B_ORIGINAL_HOST_LEDGER_V1",broker_pub,now)
    need(type(anchor.get("issued")) is int
        and now-60<=anchor["issued"]<=now+10
        and anchor.get("expires",0)>=now+35,
        "V119_ORIGINAL312_ANCHOR_SIGNED_LIFETIME_INVALID")
    issued=now
    expires=min(now+100,claimed["expires_epoch"]-10)
    need(expires>=now+25,"V119_PREWRITE_SIGNED_WINDOW_TOO_SHORT")
    fields={
        "contract":v99.PREWRITE_CONTRACT,
        "job_id":claimed["job_id"],
        "operation_id":v99.ISSUE,
        "company":claimed["company"],
        "environment":claimed["environment"],
        "site_uuid":claimed["site_uuid"],
        "plan_sha256":claimed["plan_sha256"],
        "command_sha256":claimed["command_sha256"],
        "grant_id":claimed["grant_id"],
        "anchor_revision_before":0,
        "anchor_revision_after":1,
        "anchor_nonce":anchor["nonce"],
        "anchor_message_sha256":digest,
        "issued_epoch":issued,
        "expires_epoch":expires,
    }
    need(tuple(fields)==v99.PREWRITE_FIELDS,
        "V119_ORIGINAL313_SIGNED_PREWRITE_SCHEMA_DRIFT")
    message=(b"MAD4B_ORIGINAL313_HOST_ANCHOR_WRITE_V1\n"+
        v99.canonical(fields))
    fields["signature"]=base64.b64encode(signer.sign(message)).decode()
    try:
        Ed25519PublicKey.from_public_bytes(independent_pub).verify(
            base64.b64decode(fields["signature"],validate=True),message)
    except Exception as exc:
        raise PrewriteHeld("V119_ORIGINAL313_PREWRITE_SIGNATURE_INVALID") from exc
    return fields

def build_original318_proof(claimed,signed_docs,approval,prewrite_receipt, *,
                            independent_pub,broker_pub,now,store=None):
    """Construct exact Original318 envelope ONLY after signed Original313
    PREWRITE is independently read back from its protected durable store.

    This function does not invoke WordPress and NEVER decides to retry an
    uncertain write. The future single-use Root caller must also verify a
    fresh original318 Revision 0 immediately before invoking the CLI.
    """
    need(all(type(r) is dict for r in
        (claimed,signed_docs,approval,prewrite_receipt)),
        "V119_ORIGINAL318_PROOF_REQUIRES_ORIGINALS")
    need(isinstance(now,int) and isinstance(independent_pub,bytes)
        and isinstance(broker_pub,bytes) and len(independent_pub)==32
        and len(broker_pub)==32 and broker_pub!=independent_pub,
        "V119_INDEPENDENT_FIRST_PARTY_KEY_PINS_REQUIRED")
    v99=runner.pin("v99")
    v100=runner.pin("v100")
    need(prewrite_receipt.get("state")==
        "ORIGINAL313_SIGNED_PREWRITE_RESERVED"
        and prewrite_receipt.get("job_id")==claimed.get("job_id")
        and prewrite_receipt.get("anchor_issued") is False
        and prewrite_receipt.get("original313_certified") is False,
        "V119_ORIGINAL313_SIGNED_PREWRITE_NOT_DURABLE")
    need(signed_docs.get("job_id")==claimed.get("job_id")
        and approval.get("job_id")==claimed.get("job_id")
        and approval.get("anchor_nonce")==signed_docs["anchor"]["nonce"]
        and approval.get("anchor_message_sha256")==
            signed_docs["anchor_message_sha256"]
        and approval.get("plan_sha256")==claimed.get("plan_sha256")
        and approval.get("command_sha256")==claimed.get("command_sha256")
        and approval.get("grant_id")==claimed.get("grant_id")
        and approval.get("expires_epoch",0)>=now+15
        and isinstance(claimed.get("expires_epoch"),int)
        and claimed["expires_epoch"]>=now+20
        and signed_docs["anchor"].get("expires",0)>=now+30,
        "V119_ORIGINAL312_313_PROOF_BINDING_CHANGED")
    proof_store=v99.PrivateStore() if store is None else store
    need(type(proof_store) is v99.PrivateStore,
        "V119_ORIGINAL313_PRIVATESTORE_REQUIRED")
    v99.require_enrolled_live_signers(proof_store,broker_pub,independent_pub)
    record=proof_store.read(proof_store.path(
        "transactions",claimed["job_id"]+".json"))
    need(type(record) is dict
        and record.get("contract")==v99.PREWRITE_CONTRACT
        and record.get("job_id")==claimed["job_id"]
        and record.get("operation_id")==runner.ISSUE
        and record.get("company")==claimed["company"]
        and record.get("environment")==claimed["environment"]
        and record.get("site_uuid")==claimed["site_uuid"]
        and record.get("anchor_revision_after")==1
        and record.get("anchor_nonce")==approval["anchor_nonce"]
        and record.get("anchor_message_sha256")==
            approval["anchor_message_sha256"]
        and record.get("plan_sha256")==claimed["plan_sha256"]
        and record.get("command_sha256")==claimed["command_sha256"]
        and record.get("grant_id")==claimed["grant_id"]
        and record.get("signed_approval")==approval
        and record.get("approval_sha256")==
            hashlib.sha256(v99.canonical(approval)).hexdigest()
        and isinstance(record.get("reserved_epoch"),int)
        and record["reserved_epoch"]<=now,
        "V119_ORIGINAL313_SIGNED_PREWRITE_DURABLE_STORE_MISMATCH")
    message=(b"MAD4B_ORIGINAL313_HOST_ANCHOR_WRITE_V1\n"+
        v99.canonical({k:approval[k] for k in v99.PREWRITE_FIELDS}))
    try:
        Ed25519PublicKey.from_public_bytes(independent_pub).verify(
            base64.b64decode(approval["signature"],validate=True),message)
        body=v100.anchor_body(signed_docs["anchor"])
        Ed25519PublicKey.from_public_bytes(broker_pub).verify(
            base64.b64decode(signed_docs["anchor"]["signature"],validate=True),body)
        event=signed_docs["revision_event"]
        gate=v99.load_gate()
        gate.sigcheck(event,gate.FIELDS,gate.LEDGER,
            "MAD4B_ORIGINAL_HOST_LEDGER_V1",broker_pub,now)
        need(event["event_id"]==claimed["job_id"]
            and event["anchor_nonce"]==approval["anchor_nonce"]
            and event["revision"]==1 and event["state"]=="ACTIVE",
            "V119_ORIGINAL312_LEDGER_CHANGED")
    except Exception as exc:
        raise PrewriteHeld("V119_ORIGINAL312_313_DUAL_SIGNED_PROOF_INVALID") from exc
    need(hashlib.sha256(body).hexdigest()==
            approval["anchor_message_sha256"],
        "V119_ORIGINAL312_ANCHOR_MESSAGE_CHANGED")
    return {
        "contract":"mad4b.original318.anchor-reconcile-signed.v1",
        "job_id":claimed["job_id"],
        "operation_id":runner.ISSUE,
        "anchor":signed_docs["anchor"],
        "approval":approval,
    }
