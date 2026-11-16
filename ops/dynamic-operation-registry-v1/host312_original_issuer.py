#!/usr/bin/env python3
"""First-party Original312 Host-resource issuer, not a new job/approval issuer.

Only accepts the already-claimed original306 Job, pinned first-party public
signer, actual root-protected independent Host312 private key, verified
immutable Host plan and separately protected Publisher policy. NO CLI entry.
"""
from __future__ import annotations
import base64
import os
from pathlib import Path
import stat
import time

from cryptography.hazmat.primitives.asymmetric.ed25519 import Ed25519PrivateKey
from cryptography.hazmat.primitives.serialization import Encoding, PublicFormat

import registry
import host_admission as host

HOST_SIGNER=Path('/opt/mad4b-company-transport/native-313-independent/host-source-v1/original312-ed25519.key')


def firstparty_host312_proof(index, plan:dict, job:dict, pending:dict,
                              *, now:int|None=None)->dict:
    """Return a signed Host312 proof only if the original authority exists.

    Calling this cannot mint a Master ticket, grant or Original313 receipt.
    It must be invoked by the existing first-party claimed-job handler under
    its exact immutable Job/plan authority, never by an unauthenticated API.
    """
    registry.require(os.geteuid()==0,'HOST312_FIRST_PARTY_ROOT_REQUIRED')
    instant=int(time.time()) if now is None else now
    candidate=host.claimed_host_job(index,job.get('operation'),plan,job,
                                    pending,now=instant)
    try:
        from pathlib import Path as _Path
        from cryptography.hazmat.primitives.asymmetric.ed25519 import Ed25519PublicKey
        signer=HOST_SIGNER
        if signer.is_symlink() or not signer.is_file():
            raise ValueError('not enrolled')
        meta=signer.stat()
        if (not stat.S_ISREG(meta.st_mode) or meta.st_uid!=0 or
            meta.st_gid!=0 or meta.st_nlink!=1 or
            stat.S_IMODE(meta.st_mode)!=0o600 or meta.st_size!=32):
            raise ValueError('not root private')
        key=Ed25519PrivateKey.from_private_bytes(signer.read_bytes())
    except (ValueError,OSError,TypeError) as exc:
        raise registry.RegistryDenied('HOST312_INDEPENDENT_ISSUER_NOT_ENROLLED') from exc
    public=key.public_key().public_bytes(Encoding.Raw,PublicFormat.Raw)
    registry.require(public==getattr(index,'host_resource_312_public_key',None) and
                     public!=getattr(index,'independent_verifier_public_key',None),
                     'HOST312_ISSUER_KEY_NOT_ORIGINAL_ROOT_PIN')
    # The current Host plan is not a WordPress/user-bound plan. Master/Broker
    # approvals are separate later gates, but their signer public keys must be
    # independently pinned already; no ad-hoc key supplied in a Job is valid.
    import sys
    publisher_dir=Path(__file__).resolve().parents[1]/'native-source-publisher-v1'
    if str(publisher_dir) not in sys.path:sys.path.insert(0,str(publisher_dir))
    import native_source_publisher as publisher
    policy=publisher.checked_policy()
    try:
        master=base64.b64decode(policy['master_approval_public_key_b64'],validate=True)
        broker=base64.b64decode(policy['broker_public_key_b64'],validate=True)
    except (KeyError,ValueError,TypeError) as exc:
        raise registry.RegistryDenied('HOST312_PUBLISHER_SIGNER_PINS_INVALID') from exc
    registry.require(len(master)==32 and len(broker)==32 and
                     len({public,master,broker,index.independent_verifier_public_key})==4,
                     'HOST312_INDEPENDENT_SIGNERS_COLLIDE')
    expiration=min(instant+180,int(job['expires_epoch'])-20)
    registry.require(expiration>instant+100,'HOST312_ORIGINAL_JOB_EXPIRES_TOO_SOON')
    proof={
        'contract':host.PROOF,'issuer':'mad4b-original312-host-authority',
        'operation':candidate['operation_id'],
        'target_scope':'host','command_format':host.FORMAT,'job_type':host.JOBTYPE,
        'host_id':candidate['host_id'],
        'owner_id':candidate['owner_id'],
        'scope':candidate['scope'],
        'owner_catalog_sha256':candidate['owner_catalog_sha256'],
        'plan_sha256':candidate['plan_sha256'],
        'command_sha256':candidate['command_sha256'],
        'job_id':candidate['job_id'],
        'nonce':candidate['original_306_claim_nonce'],
        'issued_epoch':instant,'expires_epoch':expiration,
    }
    payload=(host.PROOF+'\n').encode()+registry.canonical(proof)
    proof['signature']=base64.b64encode(key.sign(payload)).decode()
    checked=host.verify_first_party_host312(index,candidate,proof,now=instant)
    registry.require(checked['original_312_verified'] is True and
                     checked['execution_authorized'] is False and
                     checked['original_313_signed_receipt'] is False,
                     'HOST312_ISSUER_POSTSIGN_READBACK_MISMATCH')
    return proof
