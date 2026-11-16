#!/usr/bin/env python3
"""Root-only existing first-party Host source handler entrypoint.

It never accepts CLI/HTTP-provided approval or authority booleans. The
original306 Worker must supply real claimed Job, signed312, Master and Broker
proof read from its first-party registered stores. Transaction verifies all
proofs and current Central Master ticket again before applying any Host file.
"""
from __future__ import annotations
import os
from pathlib import Path

import native_host_source_ownership as owner
import native_host_source_transaction as txn
import native_source_publisher as publisher

class HostSourceHandlerDenied(owner.HostSourceDenied):pass

def firstparty_execute_claimed(plan:dict, *, original_job:dict,
          original_pending:dict, signed_host312:dict,
          signed_master_approval:dict, signed_broker_grant:dict,
          nonce:str)->dict:
    if os.geteuid()!=0:raise HostSourceHandlerDenied('ORIGINAL_HOST_HANDLER_ROOT_REQUIRED')
    policy=publisher.checked_policy()
    catalog=owner.checked_host_catalog()
    owner.verify_host_plan(plan,catalog)
    original=publisher.original_registry()
    manifest=original.resolve(original_job.get('operation'))
    if (manifest.get('source_family')!='mad4b.native-host-source-release.v1'
        or manifest.get('resource_kind')!='host_root_file'
        or manifest.get('target_scope')!='host'
        or manifest.get('job_type')!='signed_vps_host_source'
        or manifest.get('plan_contract')!=plan.get('contract')):
        raise HostSourceHandlerDenied('ORIGINAL_HOST_HANDLER_PROVIDER_UNENROLLED')
    return txn._apply_verified_job_files(
        plan,catalog,Path(policy['receipt_dir']),nonce,
        original_306_job=original_job,
        original_306_pending=original_pending,
        original_312_signed_proof=signed_host312,
        signed_master_approval=signed_master_approval,
        signed_broker_grant=signed_broker_grant)

if __name__=='__main__':
    raise SystemExit('ORIGINAL_HOST_HANDLER_CLI_DISABLED_REQUIRE_FIRSTPARTY_CLAIM')
