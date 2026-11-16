#!/usr/bin/env python3
"""Original publisher's internal Host CAS transaction backend.

NO CLI. NO authority issuer. The original signed Host-resource 306/312/313
adapter must independently authorize a claimed job before invoking the
private backend; this module cannot approve, attest or publish by itself.
"""
from __future__ import annotations

import base64
from contextlib import ExitStack
from typing import Callable
from datetime import datetime, timezone
import errno
import fcntl
import json
import os
from pathlib import Path
import re
import stat
import subprocess

import native_host_source_ownership as owner
import native_source_publisher as publisher

HEX = re.compile(r'^[a-f0-9]{64}$')
NONCE = re.compile(r'^[a-zA-Z0-9_-]{20,100}$')


class HostTransactionDenied(owner.HostSourceDenied):
    pass


def deny(reason: str):
    raise HostTransactionDenied(reason)


def private_receipts(root: Path) -> None:
    # Receipt/backup/Journals are owned by the independently enrolled
    # first-party handler, and are never created based on caller input.
    if not isinstance(root,Path) or not root.is_absolute() or root.is_symlink():
        deny('HOST_RECEIPT_DIR_NOT_PINNED')
    if not root.is_dir():deny('HOST_RECEIPT_DIR_MISSING')
    info=root.stat()
    if info.st_uid!=0 or info.st_gid!=0 or stat.S_IMODE(info.st_mode)&0o077:
        deny('HOST_RECEIPTS_NOT_ROOT_PRIVATE')


def journal_path(root: Path,plan:dict)->Path:
    if not isinstance(plan.get('plan_sha256'),str) or not HEX.fullmatch(plan['plan_sha256']):
        deny('HOST_PLAN_DIGEST_INVALID')
    return root/(plan['plan_sha256']+'.intent.json')


def checked_journal(root:Path,plan:dict):
    target=journal_path(root,plan)
    if target.is_symlink():deny('HOST_INTENT_SYMLINK')
    if target.exists():
        # An interrupted prior attempt may have applied some files. A new
        # execution MUST reconcile the record, never blindly retry.
        deny('HOST_RECOVERY_REQUIRED_EXISTING_INTENT')
    return target


def stable_dirsync(path:Path):
    fd=os.open(path,os.O_RDONLY|os.O_DIRECTORY|os.O_NOFOLLOW)
    try:os.fsync(fd)
    finally:os.close(fd)


def atomic(root:Path,relative:Path, data:bytes,mode:int=0o600):
    path=root/relative
    if path.is_symlink():deny('HOST_ATOMIC_SYMLINK_DENIED')
    # Reuse canonical Company Code Release atomic CAS writer; verify all
    # file identities and fsync parent directory after the rename.
    publisher.core.atomic_write(path,data,mode)
    stable_dirsync(path.parent)


def new_intent(path:Path,data:bytes):
    if path.exists() or path.is_symlink():deny('HOST_INTENT_ALREADY_EXISTS')
    fd=os.open(path,os.O_CREAT|os.O_EXCL|os.O_WRONLY|os.O_NOFOLLOW,0o600)
    try:
        with os.fdopen(fd,'wb') as f:
            f.write(data);f.flush();os.fsync(f.fileno())
        stable_dirsync(path.parent)
    except Exception:
        # An intent with failed durability must not be silently removed.
        raise


def original_locks(paths:list[str],stack:ExitStack):
    if not isinstance(paths,list) or not paths:
        deny('HOST_ORIGINAL_LOCK_LIST_INVALID')
    for name in sorted(paths):
        owner.exact_lock(name)
        file=Path(name)
        if file.is_symlink():deny('HOST_ORIGINAL_LOCK_IS_SYMLINK')
        fd=os.open(name,os.O_CREAT|os.O_RDWR|os.O_CLOEXEC|os.O_NOFOLLOW,0o600)
        stack.callback(os.close,fd)
        meta=os.fstat(fd)
        if meta.st_uid!=0 or meta.st_gid!=0 or meta.st_mode&0o022:
            deny('HOST_ORIGINAL_LOCK_UNTRUSTED')
        try:
            fcntl.flock(fd,fcntl.LOCK_EX|fcntl.LOCK_NB)
        except BlockingIOError:
            deny('HOST_ORIGINAL_LOCK_BUSY__RETRY_LATER')
        stack.callback(fcntl.flock,fd,fcntl.LOCK_UN)


def validate_code_bytes(row:dict,data:bytes):
    name=row['target']
    if name.endswith('.py'):
        compile(data,name,'exec')
    elif name.endswith('.sh'):
        cmd=subprocess.run(['bash','-n'],input=data,stdout=subprocess.PIPE,
                           stderr=subprocess.PIPE,timeout=15)
        if cmd.returncode:deny('HOST_SOURCE_BASH_SYNTAX_INVALID')
    elif name.endswith(('.service','.timer')):
        # Native unit validation is delegated to the original first-party
        # executor and must be explicitly included in signed acceptance.
        deny('HOST_SYSTEMD_VALIDATOR_NOT_YET_ENROLLED')
    else:deny('HOST_SOURCE_FORMAT_NOT_SUPPORTED')


def _apply_verified_job_files(plan:dict,catalog:dict,receipt_root:Path,nonce:str,*,
                              original_306_job:dict,
                              original_306_pending:dict,
                              original_312_signed_proof:dict,
                              signed_master_approval:dict,
                              signed_broker_grant:dict,
                              post_apply:Callable[[dict],None]|None=None):
    """PRIVATE transaction primitive, never a first-party job admission.

    The separately enrolled first-party handler must supply the ORIGINAL306
    claimed Job/pending, Host312 signed proof, and independently issued Master
    approval and Broker grant. The transaction validates both real signatures
    and fresh original WordPress Master ticket itself. There are NO caller
    supplied admission or proof callbacks. The public publisher cannot invoke
    this private transaction through its CLI.
    """
    if not isinstance(nonce,str) or not NONCE.fullmatch(nonce):
        deny('HOST_JOB_NONCE_INVALID')
    private_receipts(receipt_root)
    if not all(isinstance(x,dict) for x in (
        original_306_job,original_306_pending,original_312_signed_proof,
        signed_master_approval,signed_broker_grant)):
        deny('HOST_ORIGINAL_SIGNED_ADMISSION_REQUIRED')
    # A source-only preview catalog can never become executable simply by
    # toggling "enabled" in memory. Require exact readback of the root-owned,
    # first-party enrolled Host-owner catalog on EVERY execution. Only the
    # original signed 313 handler may eventually supply admitted_original_job.
    enrolled=owner.checked_host_catalog()
    if catalog != enrolled:
        deny('HOST_RUNTIME_OWNER_CATALOG_NOT_FIRSTPARTY_ENROLLED')
    # Never let an unsigned request select a path outside the Host catalog.
    owner.verify_host_plan(plan,catalog)
    # The independent registered Host312 verifier is mandatory. A generic
    # callback returning True is *not* authority to write any VPS source.
    signed=publisher.verify_original_host312_proof(
        plan,original_306_job,original_306_pending,original_312_signed_proof)
    if signed.get('host312_signed_nonce')!=nonce:
        deny('HOST_ORIGINAL312_NONCE_NOT_BOUND_TO_JOB')
    # Master Admin and Broker signatures are verified against separately
    # pinned first-party public keys. The Master ticket is freshly re-read
    # from Central WordPress OUTSIDE the original Host locks.
    validated=publisher.verify_original_host_master_and_broker(
        plan,signed,signed_master_approval,signed_broker_grant)
    if (validated.get('master_approval_verified') is not True or
        validated.get('broker_release_grant_verified') is not True or
        validated.get('execution_authorized') is not False):
        deny('HOST_ORIGINAL_SIGNED_MASTER_AND_BROKER_NOT_VERIFIED')
    approval_digest=owner.digest(owner.canon(signed_master_approval))
    grant_digest=owner.digest(owner.canon(signed_broker_grant))
    attempts=receipt_root/(nonce+'.host-result.json')
    if attempts.exists() or attempts.is_symlink():
        deny('HOST_NONCE_REPLAY_DENIED')
    result=None
    with ExitStack() as stack:
        original_locks(plan['original_lock_paths'],stack)
        # Verify immutable Git and all live preimages *under* the locks.
        owner.verify_host_plan(plan,catalog)
        checked_journal(receipt_root,plan)
        # Recheck the cryptographic Host312 proof and current expiry under
        # the original Host locks. This imports only a root-pinned validator
        # and does not invoke Original305 or HostProof CLI recursively.
        second=publisher.verify_original_host312_proof(
            plan,original_306_job,original_306_pending,original_312_signed_proof)
        if (second.get('host312_signed_nonce')!=nonce or
            second.get('job_id')!=signed.get('job_id') or
            second.get('plan_sha256')!=plan['plan_sha256']):
            deny('HOST_ORIGINAL312_CHANGED_UNDER_LOCK')
        if (owner.digest(owner.canon(signed_master_approval))!=approval_digest or
            owner.digest(owner.canon(signed_broker_grant))!=grant_digest):
            deny('HOST_SIGNED_MASTER_BROKER_CHANGED_UNDER_LOCK')
        # Recheck both actual first-party cryptographic signatures and expiry,
        # without a second WPCLI call that might re-enter original Host locks.
        keys=publisher.checked_policy()
        for payload,keyname in ((signed_master_approval,'master_approval_public_key_b64'),
                                (signed_broker_grant,'broker_public_key_b64')):
            publisher._verify_host_signed_envelope(
                payload,keys[keyname],payload['contract'],set(payload))
        owner.verify_host_plan(plan,catalog)
        target_root=Path(plan['target_root'])
        backup={}
        new={}
        for row in plan['files']:
            path=target_root/row['target']
            prior=path.read_bytes()
            if owner.digest(prior)!=row['expected_before_sha256']:
                deny('HOST_PREWRITE_CAS_CHANGED')
            data=owner.git_blob(Path(plan['git_root']),plan['git_commit'],row['source'])
            if owner.digest(data)!=row['source_sha256']:
                deny('HOST_GIT_BLOB_CHANGED')
            validate_code_bytes(row,data)
            meta=path.stat()
            backup[row['target']]={
                'sha256':owner.digest(prior),
                'data_b64':base64.b64encode(prior).decode(),
                'mode':stat.S_IMODE(meta.st_mode),
                'uid':meta.st_uid,'gid':meta.st_gid,
            }
            new[row['target']]=data
        # A durable, one-time original intent and backup exist *before* any
        # live edit. If the process dies mid-replace the next run HALTS and
        # requires repair/reconciliation; it does not risk double execution.
        intent={
            'contract':'mad4b.native-host-source-intent.v1',
            'state':'PREPARED_RECOVERY_REQUIRED_IF_INTERRUPTED',
            'plan_sha256':plan['plan_sha256'],
            'owner_catalog_sha256':plan['owner_catalog_sha256'],
            'git_commit':plan['git_commit'],
            'nonce':nonce,
            'files':backup,
            'original_313_signed_receipt':False,
        }
        new_intent(journal_path(receipt_root,plan),owner.canon(intent))
        changed=[]
        failure=None
        rollback_ok=True
        try:
            for row in plan['files']:
                target=target_root/row['target']
                if owner.digest(target.read_bytes())!=row['expected_before_sha256']:
                    deny('HOST_PREWRITE_RACE_DURING_BATCH')
                meta=backup[row['target']]
                if meta['uid']!=0 or meta['gid']!=0:
                    deny('HOST_EXPECTED_ROOT_FILE_OWNER_CHANGED')
                atomic(target.parent,Path(target.name),new[row['target']],meta['mode'])
                changed.append(row['target'])
                if owner.digest(target.read_bytes())!=row['source_sha256']:
                    deny('HOST_POSTWRITE_DIGEST_MISMATCH')
            if post_apply is not None:post_apply(plan)
        except Exception as exc:
            failure=type(exc).__name__+':'+str(exc)[:200]
            for name in reversed(changed):
                target=target_root/name
                prior=backup[name]
                try:
                    if owner.digest(target.read_bytes()) != next(
                        row['source_sha256'] for row in plan['files'] if row['target']==name):
                        raise RuntimeError('CONCURRENT_WRITE_DETECTED_DO_NOT_CLOBBER')
                    atomic(target.parent,Path(target.name),
                           base64.b64decode(prior['data_b64'],validate=True),prior['mode'])
                    if owner.digest(target.read_bytes())!=prior['sha256']:
                        raise RuntimeError('RESTORE_HASH_MISMATCH')
                except Exception:
                    rollback_ok=False
                    break
        state=('APPLIED_PENDING_INDEPENDENT_313' if failure is None else
               ('ROLLED_BACK_RECONCILIATION_REQUIRED' if rollback_ok else
                'RECOVERY_REQUIRED_UNSAFE_TO_RETRY'))
        result={'contract':'mad4b.native-host-source-transaction-result.v1',
                'state':state,'plan_sha256':plan['plan_sha256'],
                'nonce':nonce,'changed_files':changed,
                'failure':failure,'blind_retry_allowed':False,
                'original_313_signed_receipt':False}
        atomic(receipt_root,Path(nonce+'.host-result.json'),owner.canon(result),0o600)
    return result


def first_party_execute(*_args,**_kwargs):
    # Safe public entrypoint until independently signed Host-source Original
    # 306/312/313 provider has been installed by its first-party owner.
    # Future runtime hooks must verify the signed job and call the PRIVATE
    # transaction primitive, never change this to caller-provided true/false.
    deny('HOST_RESOURCE_ORIGINAL_312_313_PROVIDER_NOT_ENROLLED')
