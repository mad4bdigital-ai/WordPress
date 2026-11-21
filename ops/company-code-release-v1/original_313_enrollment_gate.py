#!/usr/bin/env python3
"""Candidate original 306 worker digest projection (not a deployed worker hook).

A code-release implementation file alone NEVER enrolls an operation. The
original broker must enroll a distinct operation-specific 313 handler, an
independent receipt verifier, and the exact company+site dispatch ability.
This projection itself cannot issue a Job, Claim, Grant or Receipt.
"""
from __future__ import annotations
import json
import re
from pathlib import Path
from typing import Any

import original_313_code_release_contract as code313

CONTRACT = "mad4b.company-code-release.original-worker-enrollment.v1"
REQUIRED_EVIDENCE_KEYS = (
    "original_305_company_verified",
    "original_312_verifier_verified",
    "original_313_release_handler_enrolled",
    "original_313_independent_receipt_handler_enrolled",
    "company_release_dispatch_ability_registered",
    "host_code_release_policy_pinned",
)


def project_candidate(
    release_plan: dict,
    enrollment: dict | None,
    independent_proofs: dict | None,
) -> dict:
    """Read-only exact-digest projection; never an authorization decision."""
    proposed = code313.candidate_original_operation(release_plan)
    blocked = []
    if not isinstance(enrollment, dict):
        blocked.append("ORIGINAL_313_OPERATION_ENROLLMENT_MISSING")
    else:
        exact = (
            enrollment.get("contract") == CONTRACT and
            enrollment.get("state") == "ENROLLED_ORIGINAL_FIRST_PARTY" and
            enrollment.get("company") == proposed["company"] and
            enrollment.get("environment") == proposed["environment"] and
            enrollment.get("site_uuid") == proposed["site_uuid"] and
            enrollment.get("origin") == proposed["origin"] and
            enrollment.get("git_commit") == proposed["git_commit"] and
            enrollment.get("plan_sha256") == proposed["plan_sha256"] and
            enrollment.get("command_sha256") == proposed["command_sha256"] and
            enrollment.get("operation") == code313.OPERATION and
            enrollment.get("transport_operation") == code313.TRANSPORT_OPERATION
        )
        if not exact:
            blocked.append("ORIGINAL_313_ENROLLMENT_SCOPE_OR_DIGEST_CHANGED")
    if not isinstance(independent_proofs, dict):
        blocked.append("INDEPENDENT_ORIGINAL_AUTHORITY_EVIDENCE_MISSING")
    else:
        for name in REQUIRED_EVIDENCE_KEYS:
            if independent_proofs.get(name) is not True:
                blocked.append(name.upper() + "_MISSING")
    return {
        "contract": CONTRACT,
        # Even a self-reported all-true metadata object cannot enroll a
        # first-party 313 operation. Only the actual original authenticated
        # registration path may install a worker digest. No caller-provided
        # boolean authorizes a worker.
        "state": "METADATA_READY_ORIGINAL_313_ENROLLMENT_NOT_PERFORMED"
                 if not blocked else "HOLD_EXACT_ORIGINAL_OPERATION_ENROLLMENT",
        "operation": code313.OPERATION,
        "command_sha256": proposed["command_sha256"],
        "candidate_worker_digest": proposed["command_sha256"],
        "eligible_worker_digest": None,
        "command_argv": proposed["command_argv"],
        "git_commit": proposed["git_commit"],
        "plan_sha256": proposed["plan_sha256"],
        "blocked_by": blocked,
        "candidate_is_original_313_execution_handler": False,
        "job_issued": False,
        "claim_made": False,
        "grant_issued": False,
        "original_313_certified": False,
        "production_mutated": False,
    }


def read_current_enrollment(plan: dict) -> dict:
    """Current source-only state: no fictitious persistent registration."""
    return project_candidate(plan, None, None)
