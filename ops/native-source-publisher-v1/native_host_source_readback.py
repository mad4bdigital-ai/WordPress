#!/usr/bin/env python3
"""Original Publisher Host readback; evidence only, NEVER 313 signed receipt."""
from __future__ import annotations

from pathlib import Path
import base64
import json
import os
import stat

import native_host_source_ownership as owner
import native_host_source_transaction as txn


class HostReadbackDenied(owner.HostSourceDenied):
    pass


def fail(why:str):raise HostReadbackDenied(why)


def read_private_json(path:Path,root:Path)->dict:
    if path.parent!=root or path.is_symlink() or not path.is_file():
        fail('HOST_READBACK_ARTIFACT_NOT_PRIVATE')
    m=path.stat()
    if m.st_uid!=0 or m.st_gid!=0 or stat.S_IMODE(m.st_mode)&0o077 or m.st_size>12_000_000:
        fail('HOST_READBACK_ARTIFACT_UNTRUSTED')
    value=json.loads(path.read_bytes())
    if not isinstance(value,dict):fail('HOST_READBACK_RECORD_NOT_OBJECT')
    return value


def independent_readback(plan:dict,catalog:dict,root:Path,nonce:str)->dict:
    txn.private_receipts(root)
    if not isinstance(nonce,str) or not txn.NONCE.fullmatch(nonce):
        fail('HOST_READBACK_NONCE_INVALID')
    if not isinstance(plan,dict) or not isinstance(plan.get('plan_sha256'),str):
        fail('HOST_READBACK_PLAN_INVALID')
    if not txn.HEX.fullmatch(plan['plan_sha256']):
        fail('HOST_READBACK_PLAN_DIGEST_INVALID')
    if plan['plan_sha256']!=owner.digest(owner.canon({k:v for k,v in plan.items()
                                                   if k!='plan_sha256'})):
        fail('HOST_READBACK_PLAN_TAMPERED')
    owner.catalog_data_validate(catalog)
    oid=plan.get('owner_id')
    entry=catalog['owners'].get(oid)
    if not isinstance(entry,dict):fail('HOST_READBACK_OWNER_UNENROLLED')
    if (plan.get('scope')!=entry['scope'] or
        plan.get('target_root')!=entry['target_root'] or
        plan.get('git_root')!=catalog['git_root'] or
        plan.get('owner_catalog_sha256')!=owner.digest(owner.canon(catalog)) or
        plan.get('original_lock_paths')!=sorted(entry['locks']) or
        plan.get('authority')!='READ_ONLY_PREFLIGHT' or
        any(plan.get(k) is not False for k in ('publication_authorized',
            'broker_grant_verified','original_312_verified','original_313_receipt_signed',
            'master_approval_verified','publisher_registered'))):
        fail('HOST_READBACK_ORIGINAL_OWNER_DRIFT')
    result=read_private_json(root/(nonce+'.host-result.json'),root)
    intent=read_private_json(txn.journal_path(root,plan),root)
    if (set(result)!={'contract','state','plan_sha256','nonce','changed_files',
                     'failure','blind_retry_allowed','original_313_signed_receipt'} or
        result['contract']!='mad4b.native-host-source-transaction-result.v1' or
        result['state']!='APPLIED_PENDING_INDEPENDENT_313' or
        result['plan_sha256']!=plan['plan_sha256'] or
        result['nonce']!=nonce or
        result['failure'] is not None or
        result['blind_retry_allowed'] is not False or
        result['original_313_signed_receipt'] is not False):
        fail('HOST_READBACK_RESULT_NOT_SUCCESSFUL_OR_NOT_BOUND')
    if (intent.get('contract')!='mad4b.native-host-source-intent.v1' or
        intent.get('plan_sha256')!=plan['plan_sha256'] or
        intent.get('owner_catalog_sha256')!=plan['owner_catalog_sha256'] or
        intent.get('git_commit')!=plan['git_commit'] or
        intent.get('nonce')!=nonce or
        intent.get('original_313_signed_receipt') is not False or
        intent.get('state')!='PREPARED_RECOVERY_REQUIRED_IF_INTERRUPTED'):
        fail('HOST_READBACK_PREPARED_INTENT_NOT_BOUND')
    claims={(v['source'],v['target']) for v in entry['file_claims']}
    rows=plan.get('files')
    if not isinstance(rows,list) or not 1<=len(rows)<=owner.MAX_FILES:
        fail('HOST_READBACK_FILES_INVALID')
    if (not isinstance(result['changed_files'],list) or
        result['changed_files']!=[x.get('target') for x in rows] or
        not isinstance(intent.get('files'),dict) or
        set(intent['files'])!={x.get('target') for x in rows}):
        fail('HOST_READBACK_FILES_AND_INTENT_DRIFT')
    observed=[];seen=set()
    for row in rows:
        if not isinstance(row,dict) or set(row)!={'source','target',
                'expected_before_sha256','source_sha256','changed'}:
            fail('HOST_READBACK_ROW_SHAPE_INVALID')
        src=owner.safe_relative(row['source']);dest=owner.safe_relative(row['target'])
        if (src,dest) not in claims or dest in seen:
            fail('HOST_READBACK_FILE_UNCLAIMED_OR_DUPLICATE')
        seen.add(dest)
        if not all(isinstance(row.get(k),str) and owner.SHA.fullmatch(row[k])
                   for k in ('expected_before_sha256','source_sha256')):
            fail('HOST_READBACK_DIGEST_INVALID')
        blob=owner.git_blob(Path(plan['git_root']),plan['git_commit'],src)
        if owner.digest(blob)!=row['source_sha256']:
            fail('HOST_READBACK_GIT_OBJECT_DRIFT')
        p=Path(plan['target_root'])/dest
        owner.root_owned(p,file=True)
        data=p.read_bytes()
        if owner.digest(data)!=row['source_sha256']:
            fail('HOST_READBACK_LIVE_CONTENT_MISMATCH')
        prepared=intent['files'][dest]
        if (not isinstance(prepared,dict) or
            prepared.get('sha256')!=row['expected_before_sha256'] or
            prepared.get('uid')!=0 or prepared.get('gid')!=0 or
            not isinstance(prepared.get('mode'),int)):
            fail('HOST_READBACK_BACKUP_NOT_ORIGINAL')
        try:original=base64.b64decode(prepared['data_b64'],validate=True)
        except Exception as exc:
            raise HostReadbackDenied('HOST_READBACK_BACKUP_BYTES_INVALID') from exc
        if owner.digest(original)!=row['expected_before_sha256']:
            fail('HOST_READBACK_BACKUP_DIGEST_MISMATCH')
        if stat.S_IMODE(p.stat().st_mode)!=prepared['mode']:
            fail('HOST_READBACK_FILE_MODE_DRIFT')
        observed.append({'target':dest,'sha256':row['source_sha256']})
    return {
        'contract':'mad4b.native-host-source-independent-readback.v1',
        'state':'HOST_READBACK_VERIFIED_PENDING_ORIGINAL_313',
        'plan_sha256':plan['plan_sha256'],
        'owner_catalog_sha256':plan['owner_catalog_sha256'],
        'nonce':nonce,
        'output_sha256':owner.digest(owner.canon({'files':observed})),
        'file_count':len(observed),
        'original_313_signed_receipt':False,
        'publication_authorized':False,
    }
