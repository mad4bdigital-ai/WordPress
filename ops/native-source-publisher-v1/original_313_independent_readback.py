#!/usr/bin/env python3
"""Independent, non-signing observed SHA readback for Original 313 finalizer.

This is only evidence. Central independent Original313 finalizer must verify
the bound signed Job/312 Grant and issue its own signed receipt.
"""
from __future__ import annotations

import hashlib
from pathlib import Path
import native_source_publisher as publisher


def inspect(plan: dict, policy: dict) -> dict:
    publisher.check_plan(plan,policy,after=True)
    publisher.native_runtime_binding(plan,policy)
    path=Path(plan["target_root"])/publisher.TARGET_PATH
    publisher.core.no_link(path)
    raw=publisher.core.safe_regular(path)
    expected=plan["files"][0]["source_sha256"]
    digest=hashlib.sha256(raw).hexdigest()
    if expected!=digest:
        raise publisher.Denied("SOURCE_INDEPENDENT_READBACK_SHA_MISMATCH")
    return {
        "contract":"mad4b.native-source-publisher.independent-readback.v1",
        "state":"EXACT_FILE_HASH_READBACK_ONLY",
        "company":plan["company"],
        "environment":plan["environment"],
        "site_uuid":plan["site_uuid"],"origin":plan["origin"],
        "plan_sha256":plan["plan_sha256"],
        "target":publisher.TARGET_PATH,
        "expected_readback_sha256":expected,
        "readback_sha256":digest,
        "result_status":"verified",
        "independent_313_signed_receipt_issued":False,
        "signature_generated":False,
    }
