#!/usr/bin/env python3
"""Generate a *candidate* Original 306 Worker patch, not live deployment.

Requires an explicit input/output and pinned expected source SHA. It adds
manifest-driven preclaim only, preserving original code.release and license
behavior. Never grants enrollment or changes production itself.
"""
from __future__ import annotations

import argparse
import hashlib
from pathlib import Path

ANCHOR="""        if operation=='company.code.release':
            return company_code_release_grant_ready_legacy(job,command_sha)
        if operation!='plugin.license.activate':return False
"""
INSERT="""        if operation=='company.code.release':
            return company_code_release_grant_ready_legacy(job,command_sha)
        # Generic first-party Preclaim extension, only for a Registry-pinned
        # capability. A provider without an admitted hook follows existing
        # historic behavior. If Original 313 isn't enrolled, the hook must
        # refuse the queued Job before the Original 306 broker Claim.
        manifest=index.resolve(operation)
        if isinstance(manifest.get('preclaim'),dict):
            from worker_adapter import provider_preclaim
            return provider_preclaim(index,DYNAMIC_PLAN_STORE,job,command_sha)
        if operation!='plugin.license.activate':return False
"""


def transform(original:str)->str:
    if 'from worker_adapter import provider_preclaim' in original:
        raise ValueError("ORIGINAL306_ALREADY_PATCHED_REVIEW_REQUIRED")
    if original.count(ANCHOR)!=1:
        raise ValueError("EXACT_ORIGINAL306_PRECLAIM_ANCHOR_NOT_FOUND")
    return original.replace(ANCHOR,INSERT)


def main()->None:
    p=argparse.ArgumentParser()
    p.add_argument('--input',type=Path,required=True)
    p.add_argument('--output',type=Path,required=True)
    p.add_argument('--expected-sha256',required=True)
    a=p.parse_args()
    src=a.input.read_bytes()
    if hashlib.sha256(src).hexdigest()!=a.expected_sha256:
        raise SystemExit('ORIGINAL306_WORKER_SOURCE_SHA_CHANGED_HOLD')
    if a.output.exists() or a.output.is_symlink():
        raise SystemExit('ORIGINAL306_CANDIDATE_OUTPUT_ALREADY_EXISTS_HOLD')
    data=transform(src.decode('utf-8')).encode()
    a.output.write_bytes(data)
    print('ORIGINAL306_SOURCE_PATCHED_AS_CANDIDATE_ONLY')


if __name__=='__main__':
    main()
