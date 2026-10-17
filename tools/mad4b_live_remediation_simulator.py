#!/usr/bin/env python3
"""Fault-injected digital-twin of *observed* Staging MCP incident classes.

No network calls, WP mutation, owner grant, external CLI credentials, or
automatic claim of live repair. The evidence fixture is redacted and bounded.
"""
from __future__ import annotations

import argparse
import hashlib
import importlib.util
import json
import re
import subprocess
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
DEFAULT_EVIDENCE = ROOT / "wp-content/plugins/mad4b-site-control-plane/tests/fixtures/pr403-live-runtime-incidents.json"
ENGINE = ROOT / "tools/mad4b_predictive_dependency_gate.py"
SOURCE_SHA = re.compile(r"^[a-f0-9]{40}$")
FORBIDDEN_EVIDENCE = re.compile(r"(?i)(access[_-]?token|refresh[_-]?token|password|authorization:|cookie:|private[_-]?key)")
IMPACT_CLASS = {
    "opaque_mcp_read": "mcp_transport",
    "upstream_latency_gap": "mcp_observability",
    "uncertain_mutation": "db_reservation",
    "expired_owner_intent": "owner_stepup",
    "stale_candidate_binding": "write_convergence",
    "adapter_downgrade": "adapter_runtime",
    "provider_shadow": "provider_closure",
    "skills_deferred": "skills",
    "generation_drift": "mcp_auth",
    "mutex_contention": "db_reservation",
    "missing_rollback": "backup_restore",
    "php_85_regression": "core",
    "partial_site_outage": "mcp_transport",
}
# The scenario input is the fault to inject; resolution actions are safe
# *plans*, never actual invocation of the external tool.
REQUIRED = {
    "opaque_mcp_read": "new_pinned_receipt_for_verified_read_only_operation",
    "upstream_latency_gap": "correlate_gateway_and_origin_request_ids",
    "uncertain_mutation": "inspect_durable_journal_never_replay",
    "expired_owner_intent": "obtain_fresh_exact_owner_step_up",
    "stale_candidate_binding": "compare_current_head_then_governed_write_only_review",
    "adapter_downgrade": "retain_certified_active_adapter",
    "provider_shadow": "keep_shadow_until_canary_and_rollback_verified",
    "skills_deferred": "read_full_skills_certificate_not_historical_summary",
    "generation_drift": "discard_stale_receipt_and_reprepare_read",
    "mutex_contention": "deny_write_until_lock_and_journal_reconciled",
    "missing_rollback": "withhold_release_until_restorability_proven",
    "php_85_regression": "fail_candidate_before_staging_upload",
    "partial_site_outage": "withhold_two_site_release_acceptance",
}
# Combinations emulate the second-order bugs that appear AFTER one local
# patch: resolving a single input does not discharge other dependencies.
COMPOUND_FAULTS = {
    "owner_head_transport_race": ("expired_owner_intent", "stale_candidate_binding", "opaque_mcp_read"),
    "provider_adapter_rollback": ("adapter_downgrade", "provider_shadow", "missing_rollback"),
    "database_uncertain_generation": ("uncertain_mutation", "generation_drift", "mutex_contention"),
    "skills_deferred_mcp_outage": ("skills_deferred", "opaque_mcp_read", "partial_site_outage"),
    "php_adapter_provider_parity": ("php_85_regression", "adapter_downgrade", "provider_shadow"),
}
# Existing PHP implementations, not mock-only policy checks. Each fixture
# runs in an isolated process and does not contact the two live sites.
EXECUTABLE_REGRESSIONS = (
    "staging-write-owner-transport-runtime.php",
    "session-safe-transport-resilience-runtime.php",
    "provider-transport-eligibility-runtime.php",
    "managed-skills-heartbeat-runtime.php",
    "staging-operation-journal-runtime.php",
    "staging-operation-journal-wpdb-concurrency.php",
)
SCOPE = "staging_read_only_fault_simulation"


def load_engine():
    spec = importlib.util.spec_from_file_location("mad4b_predictive_dependency_gate", ENGINE)
    if spec is None or spec.loader is None:
        raise RuntimeError("missing predictive dependency engine")
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module


def validated_evidence(path: Path) -> dict:
    blob = path.read_bytes()
    if len(blob) > 90_000:
        raise ValueError("evidence limit exceeded")
    doc = json.loads(blob)
    if doc.get("contract") != "mad4b.live-staging-incident-snapshot.v1":
        raise ValueError("unrecognized live evidence contract")
    if FORBIDDEN_EVIDENCE.search(blob.decode("utf-8", "replace")):
        raise ValueError("evidence may contain secrets; reject")
    sites = doc.get("sites")
    if not isinstance(sites, list) or len(sites) != 2:
        raise ValueError("exactly two Staging site observations expected")
    if len({s["site_key"] for s in sites}) != len(sites):
        raise ValueError("duplicate site identity")
    for site in sites:
        if site.get("environment") != "staging":
            raise ValueError("non-Staging evidence cannot be simulated")
        if not SOURCE_SHA.fullmatch(site.get("last_confirmed_head", "")):
            raise ValueError("invalid last confirmed deployed HEAD")
        if site.get("fresh_read_state") not in ("success", "host_internal_error"):
            raise ValueError("invalid current read state")
        if site.get("owner_receipt") not in ("expired", "unknown", "current"):
            raise ValueError("owner receipt status must be explicit")
        if site.get("candidate_binding_matches") not in (True, False, None):
            raise ValueError("binding unknown must be null")
        if site.get("runtime_adapter") != "0.7.0":
            raise ValueError("runtime adapter cannot be inferred")
    return doc


def simulate(site: dict, scenario: str, engine) -> dict:
    assert scenario in IMPACT_CLASS
    initial = IMPACT_CLASS[scenario]
    impacted, edges = engine.closure([initial])
    live_required = sorted({check for d in impacted for check in engine.DOMAINS[d]["live"]})
    action = REQUIRED[scenario]
    # Each injected fault is a deliberate denial test, never a fictitious
    # read/approve/apply action. Skills deferred is informational, not a
    # proof of outage, even when the historical summary says false.
    classification = {
        "opaque_mcp_read": "transient_or_unknown_transport",
        "upstream_latency_gap": "unattributed_external_or_queue_latency",
        "uncertain_mutation": "outcome_unknown",
        "expired_owner_intent": "exact_owner_approval_not_current",
        "stale_candidate_binding": "runtime_authority_candidate_not_reconciled",
        "adapter_downgrade": "unsafe_adapter_version_change",
        "provider_shadow": "uncertified_provider_mutation",
        "skills_deferred": "deep_read_required_not_a_failure",
        "generation_drift": "prepared_receipt_stale",
        "mutex_contention": "reservation_denied",
        "missing_rollback": "recovery_evidence_missing",
        "php_85_regression": "target_runtime_not_certified",
        "partial_site_outage": "two_site_evidence_incomplete",
    }[scenario]
    return {
        "site_key": site["site_key"],
        "fault": scenario,
        "fault_classification": classification,
        "origin_observation": site["fresh_read_state"],
        "direct_domain": initial,
        "transitive_domains": impacted,
        "dependency_edges": edges,
        "required_live_acceptance": live_required,
        "corrective_action_plan": action,
        "safe_retry_only_if_verified_read": scenario in ("opaque_mcp_read", "generation_drift"),
        "simulated_write_count": 0,
        "live_site_write_count": 0,
        "release_ready": False,
        "owner_granted": False,
        "breakglass_granted": False,
        "production_mutation_allowed": False,
        "actual_repair_verified_live": False,
        "status": "DENY_OR_DEFER",
    }


def simulate_compound(site: dict, case: str, faults: tuple, engine) -> dict:
    # Dynamic union of already-computed transitive dependency closures, not
    # an enumerated whitelist of special-cased WordPress abilities.
    parts = [simulate(site, fault, engine) for fault in faults]
    dependencies = sorted(set(x for part in parts for x in part["transitive_domains"]))
    checks = sorted(set(x for part in parts for x in part["required_live_acceptance"]))
    # Mutate a disposable *model* of active fault predicates only; never
    # call MCP, a site, or the owner. Predict what remains broken after each
    # hypothetical targeted fix, then recompute its transitive risk surface.
    active = list(faults)
    repair_trace = []
    for fault in faults:
        before = list(active)
        if fault not in active:
            raise AssertionError("cannot repair absent fault")
        active.remove(fault)
        residual_domains = sorted(set(d for f in active
            for d in engine.closure([IMPACT_CLASS[f]])[0]))
        repair_trace.append({
            "modeled_fix_for": fault,
            "safe_remediation_plan": REQUIRED[fault],
            "active_before": before,
            "active_after": list(active),
            "predicted_remaining_domains": residual_domains,
            "all_root_faults_modeled_cleared": len(active) == 0,
            "actual_site_change_performed": False,
            "live_receipt_verified": False,
            "release_ready": False,
        })
    return {
        "site_key": site["site_key"], "case": case,
        "injected_faults": list(faults),
        "transitive_domains": dependencies,
        "required_live_acceptance": checks,
        "simulated_first_fix_resolves_entire_case": not bool(repair_trace[0]["active_after"]),
        "remaining_faults_after_first_fix": repair_trace[0]["active_after"],
        "repair_sequence": repair_trace,
        "modeled_repairs_exhaust_all_injected_faults": not active,
        "modeled_repairs_do_not_prove_real_cause_eliminated": True,
        "manual_live_receipts_required": True,
        "simulated_write_count": 0,
        "owner_granted": False,
        "breakglass_granted": False,
        "actual_repair_verified_live": False,
        "release_ready": False,
    }


def observed_baseline_gate(site: dict) -> dict:
    # Distinguish real current evidence from previously confirmed identity.
    # A connector failure cannot be converted into a site-side PHP failure,
    # an owner receipt, or successful candidate binding.
    blockers = []
    if site["fresh_read_state"] != "success":
        blockers.append("new_read_failed_no_fresh_runtime_snapshot")
    if site["candidate_binding_matches"] is not True:
        blockers.append("candidate_binding_unmatched_or_unverified")
    if site["owner_receipt"] != "current":
        blockers.append("owner_receipt_expired_or_unverified")
    return {
        "site_key": site["site_key"],
        "fresh_read_state": site["fresh_read_state"],
        "last_confirmed_source": site["last_confirmed_head"],
        "runtime_adapter_last_confirmed": site["runtime_adapter"],
        "blocking_evidence": blockers,
        "governed_write_ready": False,
        "site_repair_verified": False,
        "conclusion": "not_authorized_pending_live_evidence",
    }


def build(doc: dict) -> dict:
    engine = load_engine()
    rows = []
    baselines = [observed_baseline_gate(site) for site in doc["sites"]]
    for site in doc["sites"]:
        for key in IMPACT_CLASS:
            rows.append(simulate(site, key, engine))
    compounds = [simulate_compound(site, case, faults, engine)
                 for site in doc["sites"] for case, faults in COMPOUND_FAULTS.items()]
    affected = sorted(set(d for r in rows + compounds for d in r["transitive_domains"]))
    live_acceptance = sorted(set(check for r in rows + compounds for check in r["required_live_acceptance"]))
    mandatory = sorted({engine.DOMAINS[d]["test"] for d in affected})
    missing = [t for t in mandatory if not (ROOT / engine.TEST / t).is_file()]
    problems = []
    for row in rows:
        if row["simulated_write_count"] or row["owner_granted"] or row["breakglass_granted"] or row["release_ready"]:
            problems.append("unsafe simulation authorization: " + row["fault"])
        if row["fault"] in ("opaque_mcp_read", "uncertain_mutation") and row["corrective_action_plan"] == "replay_mutation":
            problems.append("unsafe retry")
    if not set(IMPACT_CLASS).issubset({x["fault"] for x in rows}):
        problems.append("missing injected fault")
    for combined in compounds:
        if combined["simulated_first_fix_resolves_entire_case"] or combined["release_ready"]:
            problems.append("compound fault prematurely accepted")
        if not combined["modeled_repairs_exhaust_all_injected_faults"]:
            problems.append("modeled repair sequence incomplete")
        if any(step["release_ready"] or step["actual_site_change_performed"]
               or step["live_receipt_verified"] for step in combined["repair_sequence"]):
            problems.append("hypothetical remediation forged live evidence")
        if len(combined["remaining_faults_after_first_fix"]) != len(combined["injected_faults"]) - 1:
            problems.append("combined fix failed to retain downstream failures")
    problems.extend("missing mandatory regression: " + t for t in missing)
    return {
        "contract": "mad4b.live-remediation-predictive-simulation.v1",
        "source_evidence_sha256": hashlib.sha256(json.dumps(doc, sort_keys=True).encode()).hexdigest(),
        "scope": SCOPE,
        "site_count": len(doc["sites"]),
        "fault_types": len(IMPACT_CLASS),
        "fault_injections": len(rows) + len(compounds),
        "compound_fault_types": len(COMPOUND_FAULTS),
        "affected_domains": affected,
        "mandatory_regressions": mandatory,
        "missing_regressions": missing,
        "live_acceptance_debt": live_acceptance,
        "observed_baseline_decisions": baselines,
        "observations": [
            {"site_key": s["site_key"], "fresh_read_state": s["fresh_read_state"],
             "identity_last_confirmed": s["last_confirmed_head"],
             "candidate_binding_matches": s["candidate_binding_matches"],
             "owner_receipt": s["owner_receipt"],
             "live_evidence_complete": False}
            for s in doc["sites"]
        ],
        "scenarios": rows,
        "compound_scenarios": compounds,
        "policy_violations": problems,
        "simulation_pass": not problems,
        "runtime_repair_proven": False,
        "release_ready": False,
        "staging_write_authorized": False,
        "production_mutation_allowed": False,
    }


def run_php_fault_probes() -> list[dict]:
    root = ROOT / "wp-content/plugins/mad4b-site-control-plane/tests"
    results = []
    for name in EXECUTABLE_REGRESSIONS:
        path = root / name
        if not path.is_file():
            results.append({"test": name, "passed": False, "reason": "probe_missing"})
            continue
        try:
            call = subprocess.run(["php", str(path)], cwd=ROOT, text=True,
                                  stdout=subprocess.PIPE, stderr=subprocess.STDOUT,
                                  timeout=90, check=False)
            results.append({"test": name, "passed": call.returncode == 0,
                            "reason": "PASS" if call.returncode == 0 else call.stdout[-800:]})
        except (OSError, subprocess.TimeoutExpired) as exc:
            results.append({"test": name, "passed": False, "reason": type(exc).__name__})
    return results


def self_test():
    engine = load_engine()
    fake = {
        "contract": "mad4b.live-staging-incident-snapshot.v1",
        "sites": [
            {"site_key": "fixture-a", "environment": "staging",
             "last_confirmed_head": "a" * 40, "fresh_read_state": "success",
             "owner_receipt": "expired", "candidate_binding_matches": False, "runtime_adapter": "0.7.0"},
            {"site_key": "fixture-b", "environment": "staging",
             "last_confirmed_head": "b" * 40, "fresh_read_state": "host_internal_error",
             "owner_receipt": "unknown", "candidate_binding_matches": None, "runtime_adapter": "0.7.0"},
        ],
    }
    result = build(fake)
    assert result["fault_injections"] == (len(IMPACT_CLASS) + len(COMPOUND_FAULTS)) * 2
    assert result["compound_fault_types"] == len(COMPOUND_FAULTS)
    assert all(len(row["injected_faults"]) >= 3 and not row["release_ready"]
               for row in result["compound_scenarios"])
    assert all("production_guard" in row["transitive_domains"]
               for row in result["compound_scenarios"])
    assert all(r["repair_sequence"][-1]["all_root_faults_modeled_cleared"]
               and not r["repair_sequence"][-1]["release_ready"]
               and r["repair_sequence"][0]["predicted_remaining_domains"]
               for r in result["compound_scenarios"])
    assert result["simulation_pass"] and not result["runtime_repair_proven"]
    assert result["site_count"] == 2 and result["release_ready"] is False
    assert all(b["blocking_evidence"] and not b["governed_write_ready"]
               for b in result["observed_baseline_decisions"])
    assert "new_read_failed_no_fresh_runtime_snapshot" in result["observed_baseline_decisions"][1]["blocking_evidence"]
    assert all(r["simulated_write_count"] == 0 and not r["owner_granted"] for r in result["scenarios"])
    assert all("production_guard" in r["transitive_domains"] for r in result["scenarios"]
               if r["fault"] in ("expired_owner_intent", "stale_candidate_binding", "uncertain_mutation"))
    assert all(r["fault_classification"] != "skills_runtime_failed" for r in result["scenarios"]
               if r["fault"] == "skills_deferred")
    # The diagnostic is never an arbitrary approval transport.
    assert all(r["corrective_action_plan"] != "replay_mutation" for r in result["scenarios"])
    print("LIVE_REMEDIATION_FAULT_SIMULATION_SELFTEST_PASS")


def main() -> int:
    cli = argparse.ArgumentParser()
    cli.add_argument("--evidence", type=Path, default=DEFAULT_EVIDENCE)
    cli.add_argument("--output", type=Path)
    cli.add_argument("--self-test", action="store_true")
    cli.add_argument("--enforce", action="store_true")
    cli.add_argument("--execute-fixtures", action="store_true")
    args = cli.parse_args()
    if args.self_test:
        self_test()
    doc = validated_evidence(args.evidence)
    result = build(doc)
    if args.execute_fixtures:
        result["php_fault_regressions"] = run_php_fault_probes()
        failures = [r["test"] for r in result["php_fault_regressions"] if not r["passed"]]
        result["failed_php_fault_regressions"] = failures
        result["simulation_pass"] = result["simulation_pass"] and not failures
    if args.output:
        args.output.parent.mkdir(parents=True, exist_ok=True)
        args.output.write_text(json.dumps(result, sort_keys=True, indent=2, ensure_ascii=False) + "\n", encoding="utf-8")
    print(json.dumps({k: result[k] for k in ("contract", "site_count", "fault_types",
           "fault_injections", "compound_fault_types", "simulation_pass", "release_ready", "missing_regressions",
           "policy_violations")}, sort_keys=True))
    return 0 if result["simulation_pass"] or not args.enforce else 3


if __name__ == "__main__":
    sys.exit(main())
