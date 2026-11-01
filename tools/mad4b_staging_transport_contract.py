"""Non-executing, fail-closed typed preflight for Content Hub Staging."""
import argparse
import json
import re
from pathlib import Path

CONTRACT = "mad4b.content-hub.staging-typed-transport.v1"
ROUTES = {"inventory": "registered_target_inventory",
          "queue_status": "original_staging_read",
          "source_readback": "original_staging_read",
          "native_plan": "original_native_operator.plan",
          "native_apply": "original_native_operator.apply"}
FIELDS = {"contract", "operation", "environment", "site", "database", "mode",
          "target_id", "staging_binding_verified", "transport_authorized",
          "authority_receipt", "plan_sha256", "backup_sha256",
          "readback_sha256", "rollback_ref", "correlation_id"}
REF = re.compile(r"[a-z][a-z0-9_.-]{2,95}\Z")
SHA = re.compile(r"[a-f0-9]{64}\Z")


def validate(value):
    if not isinstance(value, dict):
        return {"admitted": False, "reasons": ["INVALID_REQUEST"], "executed": False}
    errors = []
    if set(value) - FIELDS:
        errors.append("RAW_COMMAND_OR_UNKNOWN_FIELD")
    if value.get("contract") != CONTRACT:
        errors.append("INVALID_CONTRACT")
    if value.get("environment") != "staging":
        errors.append("PRODUCTION_NOT_AUTHORIZED")
    if value.get("site") != "https://tours.dev.mad4b.com" or value.get("database") != "etg_commerce_staging":
        errors.append("SCOPE_MISMATCH")
    if value.get("mode") != "certified_only":
        errors.append("MODE_NOT_CERTIFIED_ONLY")
    op = value.get("operation")
    if not isinstance(op, str) or op not in ROUTES:
        errors.append("UNSUPPORTED_OPERATION")
        op = None
    if op not in (None, "inventory"):
        if not isinstance(value.get("target_id"), str) or not REF.fullmatch(value["target_id"]):
            errors.append("STAGING_TARGET_NOT_ENROLLED")
        if value.get("staging_binding_verified") is not True:
            errors.append("STAGING_BINDING_NOT_VERIFIED")
        if value.get("transport_authorized") is not True:
            errors.append("TRANSPORT_NOT_AUTHORIZED")
    if op == "native_apply":
        for key in ("authority_receipt", "rollback_ref", "correlation_id"):
            if not isinstance(value.get(key), str) or not REF.fullmatch(value[key]):
                errors.append(key.upper() + "_REQUIRED")
        for key in ("plan_sha256", "backup_sha256", "readback_sha256"):
            if not isinstance(value.get(key), str) or not SHA.fullmatch(value[key]):
                errors.append(key.upper() + "_REQUIRED")
    return {"contract": CONTRACT, "admitted": not errors,
            "reasons": sorted(set(errors)), "route": ROUTES.get(op),
            "executed": False, "execution_authority_granted": False,
            "next_step": ("Use the original tool and its independent authorization."
                          if not errors else
                          "Fix original enrollment/authority. Do not rephrase or retry blocked root-shell commands.")}


if __name__ == "__main__":
    parser = argparse.ArgumentParser()
    parser.add_argument("--request", required=True, type=Path)
    args = parser.parse_args()
    result = validate(json.loads(args.request.read_text(encoding="utf-8")))
    print(json.dumps(result, sort_keys=True))
    raise SystemExit(0 if result["admitted"] else 2)
