#!/usr/bin/env python3
"""MAD4B channel governance: diagnostic-only readbacks and fail-closed decisions.

Important: OpenAI tool-layer refusals cannot be observed by the VPS. The caller
must report them as tool invocation refusals, not as server execution failures.
No retries of denied actions on alternate channels.
"""
from __future__ import annotations
from datetime import datetime, timezone
from pathlib import Path
import json
import stat

CONTRACT = "mad4b.channel-governance-readback.v1"
SNAPSHOTS = {
    "auto_authority": (
        "/opt/mad4b-company-transport/central-auto-authority-v1/readiness.json",
        "mad4b.central-auto-authority.v1"),
    "unified_router": (
        "/opt/mad4b-company-transport/unified-execution-router-v1/status.json",
        "mad4b.unified-execution-router.v1"),
    "cross_ssh_site_identity": (
        "/opt/mad4b-company-transport/dynamic-site-binding-v1/wpcli-identity-status.json",
        "mad4b.wpcli-site-identity-discovery.v1"),
}
FAILURES = {
    "TOOL_INVOCATION_BLOCKED": ("tool_policy", "STOP_AND_REPORT_EXTERNAL_TOOL_REFUSAL"),
    "ORIGINAL_AUTHORITY_DENIED": ("site_authority", "STOP_NO_CHANNEL_FALLBACK"),
    "HOST_IDENTITY_MISMATCH": ("site_authority", "STOP_NO_CHANNEL_FALLBACK"),
    "TRANSPORT_UNAVAILABLE_PRE_DISPATCH": ("transport", "REPLAN_SAME_EXACT_SCOPE_IF_SEPARATELY_AUTHORIZED"),
    "READ_ONLY_PROBE_FAILED": ("diagnostics", "REPORT_DIAGNOSTIC_UNAVAILABLE_NO_EXECUTOR_CLAIM"),
    "EXECUTION_EFFECT_UNCERTAIN": ("execution", "STOP_AND_RECONCILE_ORIGINAL_RECEIPT_NO_REPLAY"),
}

def decision(code, *, effect_started=False):
    if code not in FAILURES:
        return {"code": code, "category": "unknown",
                "action": "STOP_UNCLASSIFIED", "fallback_allowed": False}
    category, action = FAILURES[code]
    fallback = code == "TRANSPORT_UNAVAILABLE_PRE_DISPATCH" and not effect_started
    if effect_started and fallback is False and code == "TRANSPORT_UNAVAILABLE_PRE_DISPATCH":
        action = "STOP_AND_RECONCILE_ORIGINAL_RECEIPT_NO_REPLAY"
    return {"code": code, "category": category, "action": action,
            "fallback_allowed": fallback, "original_scope_required": True}

def read_snapshot(path, contract, now=None, max_age_seconds=900):
    now = now or datetime.now(timezone.utc)
    p = Path(path)
    try:
        metadata=p.lstat()
        if (not stat.S_ISREG(metadata.st_mode) or metadata.st_uid != 0 or
            metadata.st_mode & 0o022 or metadata.st_size > 200000):
            return {"state": "UNTRUSTED_STATUS_FILE"}
        payload=json.loads(p.read_text())
        if not isinstance(payload, dict) or payload.get("contract") != contract:
            return {"state": "INVALID_STATUS_CONTRACT"}
        checked=payload.get("checked_at")
        stamp=datetime.fromisoformat(checked.replace("Z","+00:00"))
        if stamp.tzinfo is None:
            return {"state": "INVALID_STATUS_TIMEZONE"}
        age=(now-stamp).total_seconds()
        state = "CURRENT_SNAPSHOT" if -60 <= age <= max_age_seconds else "STALE_SNAPSHOT"
        return {"state": state, "checked_at": checked,
                "age_seconds": round(age),
                "contract": contract,
                "grants_created": payload.get("grants_created", 0),
                "production_mutation_reported": payload.get("production_mutation", False)}
    except (OSError, ValueError, TypeError, AttributeError, OverflowError):
        return {"state": "STATUS_UNAVAILABLE"}

def report():
    return {
        "contract": CONTRACT,
        "source": "PREEXISTING_ORIGINAL_READ_ONLY_STATUS_FILES",
        "status_reads_only": True,
        "original_issuer_called": False,
        "wordpress_write_executed": False,
        "tool_invocation_status": "NOT_OBSERVABLE_FROM_VPS",
        "dispatcher_service_liveness": "NOT_CONFIRMED_BY_SNAPSHOT",
        "snapshots": {name:read_snapshot(path,contract)
            for name,(path,contract) in SNAPSHOTS.items()},
        "governance": {
          "tool_policy_refusal_is_not_server_refusal": True,
          "production_not_blanket_prohibited": True,
          "security_denial_cross_channel_fallback": False,
          "transport_fallback_before_dispatch_only": True,
          "effect_uncertain_replay": False,
        },
    }

if __name__ == "__main__":
    print(json.dumps(report(),sort_keys=True))
