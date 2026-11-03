"""Non-authoritative diagnostics for MAD4B execution routes.

Never used as a runtime permission gate. The original connector/Native Operator
owns authorization, environment scope, backup policy, and execution.
"""
import argparse
import json
from pathlib import Path

CONTRACT = "mad4b.content-hub.transport-diagnostics.v2"


def diagnose(request):
    """Classify a request without granting or denying the original operation."""
    if not isinstance(request, dict):
        return {"contract": CONTRACT, "diagnostic": "INVALID_INPUT",
                "executed": False, "permission_decision": "ORIGINAL_AUTHORITY_ONLY"}

    operation = request.get("operation")
    op = operation.lower() if isinstance(operation, str) else "unspecified"
    error = request.get("observed_error")
    error = error.lower() if isinstance(error, str) else ""
    if "blocked by openai's safety checks" in error or error == "tool_access_denied_pre_execution":
        failure_layer = "TOOL_ACCESS_DENIED_PRE_EXECUTION"
    elif request.get("observed_shell_exit_code") is not None:
        failure_layer = "REMOTE_SHELL_EXIT_OBSERVED"
    elif error:
        failure_layer = "UNCLASSIFIED_TOOL_ERROR"
    else:
        failure_layer = "NO_ERROR_OBSERVED"

    reads = {"inventory", "queue_status", "source_readback", "read", "status", "probe"}
    writes = {"native_apply", "apply", "write", "publish", "deploy", "rollback", "shell"}
    if op in reads:
        risk_class = "read_only_candidate"
    elif op in writes:
        risk_class = "may_mutate"
    else:
        risk_class = "refer_to_original_capability_metadata"

    # Host inventory classification and application environment are independent:
    # Staging commonly lives as a container on a production-tagged VPS.
    notes = []
    host_env = request.get("host_inventory_environment")
    site_env = request.get("site_environment") or request.get("environment")
    if host_env and site_env and host_env != site_env:
        notes.append("HOST_AND_SITE_ENVIRONMENT_MAY_DIFFER_NOT_AN_AUTOMATIC_DENIAL")
    if not request.get("observed_site_identity") and op != "inventory":
        notes.append("SITE_IDENTITY_READBACK_NOT_YET_RECORDED")
    if op in writes:
        notes.append("USE_ORIGINAL_OPERATION_SPECIFIC_AUTHORITY_AND_EFFECT_POLICY")
    if failure_layer == "TOOL_ACCESS_DENIED_PRE_EXECUTION":
        notes.append("DO_NOT_RETRY_THE_REJECTED_ACTION_TO_EVADE_SAFETY_CHECKS")
    return {
        "contract": CONTRACT,
        "diagnostic": "OBSERVATION_ONLY",
        "operation": operation,
        "risk_class": risk_class,
        "failure_layer": failure_layer,
        "notes": notes,
        "permission_decision": "ORIGINAL_AUTHORITY_ONLY",
        "local_permission_gate_added": False,
        "staging_only_target_required": False,
        "backup_required_for_read": False,
        "arbitrary_runtime_mode_ban": False,
        "executed": False,
        "next_step": (
            "Inspect the original platform refusal and use its authorized recovery process."
            if failure_layer == "TOOL_ACCESS_DENIED_PRE_EXECUTION" else
            "Use the original connector or Native Operator and its existing operation-specific policy."
        ),
    }


# Backwards-compatible name: previous PR called this function validate().
def validate(request):
    return diagnose(request)


if __name__ == "__main__":
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--request", required=True, type=Path)
    args = parser.parse_args()
    print(json.dumps(diagnose(json.loads(args.request.read_text(encoding="utf-8"))), sort_keys=True))
