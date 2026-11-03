"""MAD4B lifecycle readiness projection, without mutation or grant inference.

Existing Broker, Native VPS Operator and WordPress are authoritative.
This module categorizes observations and prevents an idle queue or unrelated
OAuth/Agent problem from blocking independent monitoring/planning.
"""
from __future__ import annotations

CONTRACT = "mad4b.lifecycle-readiness-projection.v1"
RELEASE_TARGET = "egypt-tour-gates:staging"
RELEASE_OPERATION = "control_plane.release.apply"
DOMAINS = ("transport", "identity", "package",
           "operation_admission", "execution_route", "authentication_routes")

def _state(observations, domain):
    row = observations.get(domain, {})
    return row.get("state", "unobserved") if isinstance(row, dict) else "unobserved"

def project(observations, broker, *, target=RELEASE_TARGET):
    """Project status; never impersonate a grant, create a Job or apply.

    A valid diagnostic transport is independent of Native queued execution.
    Missing job is an IDLE condition, not a global failure.
    A company release requires an exact owner-granted operation and executable
    provider; it must not borrow authenticated Registry SSH.
    """
    if not isinstance(observations, dict) or not isinstance(broker, dict):
        return {"contract": CONTRACT, "target": target, "state":"INVALID_INPUT",
                "mutation_performed":False,"production_mutation":False}
    transport = _state(observations,"transport")
    identity = _state(observations,"identity")
    package = _state(observations,"package")
    admission = _state(observations,"operation_admission")
    route = _state(observations,"execution_route")
    mcp = _state(observations,"authentication_routes")
    monitoring = (transport == "verified" and identity == "verified")
    verifier = (broker.get("registry_ssh",{}).get("central_grant_verifier_bound") is True
                if isinstance(broker.get("registry_ssh"),dict) else False)
    signed_claims = broker.get("exact_312_verified_claims",[])
    valid_claim_list = isinstance(signed_claims,list)
    job_present = valid_claim_list and bool(signed_claims)
    executor_registered = broker.get("native_executor_enrolled_for_target") is True
    broker_alive = broker.get("broker_connected") is True
    # No job in queue should never be treated as a global crash.
    broker_queue = (
        "UNAVAILABLE" if not broker_alive or not verifier or not valid_claim_list else
        "IDLE_NO_ELIGIBLE_JOB" if not job_present else
        "WAITING_EXECUTOR_ENROLLMENT" if not executor_registered else
        "EXACT_JOB_PREFLIGHT_REQUIRED"
    )
    release_readiness = (
        "READY_FOR_INDEPENDENT_NATIVE_ADMISSION"
        if monitoring and package == "verified" and admission == "verified"
        and route == "verified"
        else "WAITING_EXACT_OPERATION_AUTHORITY"
        if admission != "verified" else
        "WAITING_NATIVE_EXECUTOR" if route != "verified" else
        "WAITING_SOURCE_OR_PACKAGE" if package != "verified" else
        "WAITING_SITE_READBACK"
    )
    # This projection never authorizes an operation, even in READY state.
    return {
        "contract":CONTRACT,"target":target,"operation":RELEASE_OPERATION,
        "central_monitoring":"HEALTHY" if monitoring else "DEGRADED",
        "broker_transport":"CONNECTED" if broker_alive and verifier else "NOT_READY",
        "broker_queue":broker_queue,
        "release_readiness":release_readiness,
        "mcp_status":"BOOTSTRAP_BLOCKED" if mcp == "blocked_dependency" else
                     "AUTHENTICATION_REQUIRED_OR_UNVERIFIED" if mcp == "awaiting_provider" else
                     "OBSERVATION_"+mcp.upper(),
        "diagnostic_available":monitoring,
        "planning_available":monitoring,
        "broker_job_execution_authorized":False,
        "release_operation_authorized":False,
        "operation_executed":False,
        "mutation_performed":False,
        "production_mutation":False,
    }

def from_status(resolver_status, broker_status, *, target=RELEASE_TARGET):
    rows = resolver_status.get("observations",[]) if isinstance(resolver_status,dict) else []
    observations = {r["domain"]:r for r in rows
                    if isinstance(r,dict) and r.get("target")==target
                    and isinstance(r.get("domain"),str)}
    return project(observations,broker_status,target=target)
