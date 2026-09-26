#!/usr/bin/env python3
"""Cross-surface safety/eval/fault contract for Feature 007 repository kernel."""

from __future__ import annotations

import json
from pathlib import Path

ROOT = Path(__file__).resolve().parents[4]
PLUGIN = ROOT / "wp-content/plugins/mad4b-site-control-plane"
SPEC = ROOT / "specs/007-content-intelligence-workflow-platform"

host = (ROOT / "tools/mad4b_host_runner.py").read_text(encoding="utf-8")
bridge = (PLUGIN / "includes/class-mad4b-scp-host-bridge.php").read_text(encoding="utf-8")
cli = (PLUGIN / "includes/class-mad4b-scp-cli.php").read_text(encoding="utf-8")
jobs = (PLUGIN / "includes/class-mad4b-scp-content-jobs.php").read_text(encoding="utf-8")
pipeline = (PLUGIN / "includes/class-mad4b-scp-content-intelligence-pipeline.php").read_text(encoding="utf-8")
draft = (PLUGIN / "includes/class-mad4b-scp-governed-draft.php").read_text(encoding="utf-8")
recovery = (ROOT / "tools/mad4b_recovery_plane.py").read_text(encoding="utf-8")
hardening = json.loads((SPEC / "bulk-closure-hardening.json").read_text(encoding="utf-8"))

# New execution planes never expose generic command primitives.
for label, source in {
    "host_runner": host,
    "host_bridge": bridge,
    "wp_cli": cli,
}.items():
    forbidden = [
        "shell.execute",
        "raw_sql.execute",
        "WP_CLI::runcommand",
        "shell_exec(",
        "proc_open(",
        "passthru(",
    ]
    for marker in forbidden:
        if marker in source:
            raise SystemExit(f"{label}: forbidden execution primitive: {marker}")

for required in (
    '"generic_shell_available": False',
    '"network_available_to_runner_contract": False',
    '"production_authorized": False',
):
    if required not in host:
        raise SystemExit(f"host_runner: missing fail-closed marker: {required}")

for required in (
    "'generic_shell_available' => false",
    "'raw_sql_available' => false",
    "'caller_command_strings_allowed' => false",
    "'production_authorized' => false",
):
    if required not in bridge:
        raise SystemExit(f"host_bridge: missing fail-closed marker: {required}")

# ContentJob remains provider-neutral: provider observations belong in artifacts/events.
for vendor in ("openai", "anthropic", "gemini", "semrush", "ahrefs", "dataforseo", "serpapi"):
    if vendor in jobs.lower():
        raise SystemExit(f"ContentJob leaked vendor-specific field/surface: {vendor}")

# QA/draft stages may not create public publication authority.
for source_name, source in {"pipeline": pipeline, "governed_draft": draft}.items():
    for marker in ("wp_publish_post(", "transition_post_status"):
        if marker in source:
            raise SystemExit(f"{source_name}: direct publication primitive present: {marker}")

for required in (
    "'can_publish' => false",
    "'publication_authorized' => false",
):
    if required not in pipeline:
        raise SystemExit(f"pipeline: missing explicit stop-before-publish marker: {required}")
    if required not in draft:
        raise SystemExit(f"governed_draft: missing explicit stop-before-publish marker: {required}")

# Uncertain mutation evidence must be first-class and blind retry denied.
for required in (
    "MUTATED_BUT_EVIDENCE_UNCERTAIN",
    "blind_retry_allowed",
    "reconcile_recovery_evidence",
):
    if required not in recovery:
        raise SystemExit(f"recovery: missing uncertainty contract marker: {required}")
if '"blind_retry_allowed": False' not in host:
    raise SystemExit("host_runner: incident evidence did not deny blind retry")

# Every required fault fixture has explicit repository/live evidence accounting.
fixtures = hardening.get("required_fault_fixtures", [])
evidence = hardening.get("fixture_evidence", {})
if not fixtures or set(fixtures) != set(evidence):
    raise SystemExit("fault fixture evidence accounting does not match required fixture set")
for fixture in fixtures:
    row = evidence[fixture]
    if row.get("repository_status") not in {"PROVEN", "PARTIAL", "PENDING"}:
        raise SystemExit(f"fault fixture repository status invalid: {fixture}")
    if row.get("live_status") not in {"NOT_REQUIRED", "PENDING", "PROVEN"}:
        raise SystemExit(f"fault fixture live status invalid: {fixture}")
    refs = row.get("evidence_refs")
    if not isinstance(refs, list) or not refs:
        raise SystemExit(f"fault fixture evidence refs missing: {fixture}")
    if row.get("live_status") == "PROVEN" and all(
        str(ref).startswith(("specs/", ".github/", "tools/", "wp-content/"))
        for ref in refs
    ):
        raise SystemExit(f"live fixture falsely proven by repository-only evidence: {fixture}")

# Evaluation truthfulness: repository proof may not self-promote live authority.
domain = hardening.get("domain_evidence", {})
for name, row in domain.items():
    if not isinstance(row, dict):
        continue
    if row.get("live_status") == "PROVEN":
        refs = row.get("evidence_refs", [])
        if refs and all(str(ref).startswith(("specs/", ".github/", "tools/", "wp-content/")) for ref in refs):
            raise SystemExit(f"domain {name}: live proof is repository-only")

print("mad4b.feature007.security-ai-eval-faults.v1: PASS")
