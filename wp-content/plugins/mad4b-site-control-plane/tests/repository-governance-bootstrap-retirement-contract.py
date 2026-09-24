#!/usr/bin/env python3
from pathlib import Path

governance = Path(".github/workflows/mad4b-repository-governance.yml").read_text(encoding="utf-8")
verdict = Path(".github/workflows/mad4b-release-verdict.yml").read_text(encoding="utf-8")

forbidden_governance = [
    "bootstrap_merge_exception",
    "bootstrap_scope",
    "initial_master_ruleset_only",
    "Repository governance external enforcement is pending; bounded initial bootstrap accepted.",
]
for needle in forbidden_governance:
    if needle in governance:
        raise SystemExit(f"repository governance bootstrap exception not retired: {needle}")

forbidden_verdict = [
    "governance_bootstrap_paths",
    "governance_bootstrap_candidate",
    "governance_bootstrap_pending",
    "bounded initial governance bootstrap",
]
for needle in forbidden_verdict:
    if needle in verdict:
        raise SystemExit(f"release verdict bootstrap exception not retired: {needle}")

required = [
    "repository governance must verify ready for every merge-gate PASS",
    "and governance_ready",
]
for needle in required:
    if needle not in verdict:
        raise SystemExit(f"retired governance enforcement contract missing: {needle}")

print("REPOSITORY_GOVERNANCE_BOOTSTRAP_RETIREMENT_CONTRACT: PASS")
print("bootstrap_exception=retired")
print("merge_gate_requires=governance_ready")
